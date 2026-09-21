<?php

/*
 * This file is part of davServices.
 *
 * (c) Felix Böck <https://dav.services>
 *
 * Licensed under the Apache License, Version 2.0.
 * For the full copyright and license information, see the LICENSE file.
 */

declare(strict_types=1);

namespace DavServices\Plugin;

use DavServices\Acl\IPrivilegeResolver;
use DavServices\Acl\Privilege;
use DavServices\Acl\PrivilegeSet;
use DavServices\Dav\Event\BeforeBind;
use DavServices\Dav\Event\BeforeMethod;
use DavServices\Dav\Event\BeforeMove;
use DavServices\Dav\Event\BeforeUnbind;
use DavServices\Dav\Event\BeforeWriteContent;
use DavServices\Dav\Event\CurrentPrincipalRequested;
use DavServices\Dav\Event\ListingMembers;
use DavServices\Dav\Event\OptionsRequested;
use DavServices\Dav\Event\PropertiesChanging;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\Server;
use DavServices\Exception\DavException;
use DavServices\Exception\Forbidden;
use DavServices\Exception\NotFound;
use DavServices\Http\Request;
use DavServices\Uri\Path;
use DavServices\Xml\Element;

/**
 * What a client is told about access control (RFC 3744 §5, R-ACL-01).
 *
 * Switched on with the resolver that knows an application's own rules:
 *
 *     (new Acl($server, new MemoizingPrivilegeResolver($yourResolver)))->register();
 *
 * It does two things, and they are two halves of the same idea. It **reports**
 * what a client may do, and it **refuses** what it may not (R-ACL-05).
 *
 * **Fail closed:** what was not granted is refused. A server that let a
 * request through because no rule mentioned it would be a server whose rules
 * are a suggestion. Nothing here reaches into a method class — every check
 * hangs on a seam that was already there, the same as the lock enforcement
 * before it, because a write that could not be caught would mean a missing
 * seam rather than a special case.
 *
 * **`DAV:bind` and `DAV:unbind` belong to the collection, not to the member**
 * (§3.9, §3.10). Creating a file is a change to the collection it appears in,
 * and a server that asked the member instead would be asking about something
 * that does not exist yet.
 *
 * **Hiding is two things.** Refusing to read a resource is half of it; the
 * other half is that the listing of its parent must not name it (R-ACL-06),
 * or the client has been told the thing exists. Whether the refusal itself is
 * `403` or `404` is the deployment's choice.
 *
 * Four of the five properties are easy to get wrong in a way that looks
 * right:
 *
 * **`DAV:supported-privilege-set` is nested** (§5.3). It is the tree written
 * as a tree, with the description and the language the DTD requires. A flat
 * list would tell a client that `DAV:write` and `DAV:bind` are unrelated,
 * which is the one thing the tree exists to say they are not.
 *
 * **`DAV:current-user-privilege-set` is everything the asker may do** (§5.4),
 * aggregates and all: a client greys out the buttons it must not offer from
 * this, and reporting only what was granted by name would hide half of it.
 *
 * **`DAV:acl` is the other direction** (§5.5), and reports the entries as
 * somebody wrote them rather than what they come to — an administrator
 * reading it is reading their own list back.
 *
 * **`DAV:inherited-acl-set` is empty rather than missing** (§5.7). Nothing in
 * this library inherits a list, and an empty element says so where a `404`
 * would say the server does not know the question.
 *
 * `DAV:owner` and `DAV:group` are deliberately **not** answered here. Who
 * owns a resource is the backend's to say, through
 * {@see \DavServices\Dav\IProperties}, the same way `DAV:displayname` is: a
 * plugin that invented an owner would be inventing a fact about somebody
 * else's data.
 */
final class Acl
{
    private const SUPPORTED = '{DAV:}supported-privilege-set';

    private const CURRENT_USER = '{DAV:}current-user-privilege-set';

    private const ACL = '{DAV:}acl';

    private const RESTRICTIONS = '{DAV:}acl-restrictions';

    private const INHERITED = '{DAV:}inherited-acl-set';

    /**
     * What each method has to be allowed before it runs at all.
     *
     * Only the reading ones are here: what a write needs depends on **which**
     * path it touches, and the seams it passes through know that while a
     * method name does not.
     */
    private const READING = [
        'GET' => '{DAV:}read',
        'HEAD' => '{DAV:}read',
        'PROPFIND' => '{DAV:}read',
        'REPORT' => '{DAV:}read',
        // A `COPY` reads its source — that is what its request target is —
        // and writes somewhere else; the writing half is asked for at the
        // bind seam (RFC 3744 §7.2). A `MOVE` is **not** here: it carries the
        // data without showing it to anybody, and what it needs is `unbind`
        // where it came from.
        'COPY' => '{DAV:}read',
    ];

    /**
     * And `LOCK`, which is neither: it writes nothing, so no write seam sees
     * it, and it still has to be guarded — see {@see self::guardTakingALock()}
     * for what it needs and why `UNLOCK` needs nothing.
     */
    private const TAKING_A_LOCK = 'LOCK';

    private readonly Privilege $privileges;

    private ?Request $request = null;

    /**
     * @param Privilege|null $privileges What this server can be asked to
     *                                   grant; the tree of RFC 3744 §3
     *                                   unless an extension has added to it
     * @param bool $unreadableIsNotFound R-ACL-06: whether a refusal to read
     *                                   is answered `404` rather than `403`.
     *                                   `403` says "not for you"; `404` says
     *                                   nothing at all, which is what a
     *                                   deployment wants where the existence
     *                                   of a resource is itself a secret
     */
    public function __construct(
        private readonly Server $server,
        private readonly IPrivilegeResolver $resolver,
        ?Privilege $privileges = null,
        private readonly bool $unreadableIsNotFound = false,
    ) {
        $this->privileges = $privileges ?? Privilege::standard();
    }

    /**
     * Switches the five properties on, and the compliance class that tells a
     * client to ask for them.
     */
    public function register(): void
    {
        $events = $this->server->events();

        $events->on(BeforeMethod::class, $this->guardTheRequest(...));
        $events->on(OptionsRequested::class, $this->announce(...));
        $events->on(PropertiesRequested::class, $this->describe(...));

        // R-ACL-05: the checks hang on the seams that were already there.
        // Nothing here reaches into a method class — the same as the lock
        // enforcement of P3-04, and for the same reason: a write that could
        // not be caught would mean a missing seam, not a special case.
        $events->on(BeforeWriteContent::class, $this->guardWritingContent(...));
        $events->on(BeforeBind::class, $this->guardBinding(...));
        $events->on(BeforeUnbind::class, $this->guardUnbinding(...));
        $events->on(BeforeMove::class, $this->guardMoving(...));
        $events->on(PropertiesChanging::class, $this->guardChangingProperties(...));
        $events->on(ListingMembers::class, $this->concealWhatMayNotBeRead(...));
    }

    /**
     * Keeps the request, and refuses it outright where it only reads and the
     * asker may not.
     *
     * R-ACL-05 wants the check in one place before the operation, and for a
     * reading method there is only one thing to check: the target. What a
     * write needs depends on which path it touches, and that is what the
     * seams below are for.
     *
     * @throws Forbidden If the asker may not read the target
     * @throws NotFound If they may not and the deployment hides that
     */
    public function guardTheRequest(BeforeMethod $event): void
    {
        $this->request = $event->request();

        $method = $event->request()->method();
        $needed = self::READING[$method] ?? null;

        if ($needed !== null) {
            $path = $this->server->path($event->request());

            if (!$this->resolver->forPath($this->whoIsAsking(), $path)->has($needed)) {
                throw $this->refusalToRead($path);
            }

            return;
        }

        if ($method === self::TAKING_A_LOCK) {
            $this->guardTakingALock($this->server->path($event->request()));
        }
    }

    /**
     * **A `LOCK` writes nothing, and that is exactly why it needs guarding.**
     * Without this, a client that may not change a file could still take a
     * lock on it and stop everybody who may from doing so — a denial of
     * service in three lines of curl.
     *
     * What it needs depends on what is there. A lock on an existing resource
     * is a claim on the right to change it, so it is `DAV:write-content`
     * (RFC 3744 §3.4). A lock on a path that holds nothing **creates** an
     * empty resource (RFC 4918 §9.10.4), and creating a member is
     * `DAV:bind` — which {@see self::guardBinding()} already asks for, and
     * asks before anything is created, so a refusal leaves nothing behind.
     *
     * `UNLOCK` is deliberately not guarded. RFC 3744 §3.5 gives
     * `DAV:unlock` for breaking a lock **somebody else** holds, and this
     * server has no way to break one: it only ever removes a lock whose token
     * was submitted, so the token is the proof and the privilege has nothing
     * left to guard.
     *
     * @throws Forbidden If the asker may not write what they would lock
     */
    private function guardTakingALock(string $path): void
    {
        if ($this->server->tree()->exists($path)) {
            $this->refuseUnlessAllowed($path, '{DAV:}write-content');
        }
    }

    /**
     * RFC 3744 §3.4: changing what a resource holds.
     *
     * @throws Forbidden If the asker may not
     */
    public function guardWritingContent(BeforeWriteContent $event): void
    {
        $this->refuseUnlessAllowed($event->path(), '{DAV:}write-content');
    }

    /**
     * RFC 3744 §3.9: **`DAV:bind` belongs to the collection, not to the
     * member.** Creating a file is a change to the collection it appears in,
     * and a server that asked the member instead would be asking about
     * something that does not exist yet — and granting on a rule nobody
     * could have written.
     *
     * @throws Forbidden If the asker may not
     */
    public function guardBinding(BeforeBind $event): void
    {
        $this->refuseUnlessAllowed(self::collectionOf($event->path()), '{DAV:}bind');
    }

    /**
     * RFC 3744 §3.10: and removing one is `DAV:unbind` on the collection,
     * for the same reason.
     *
     * @throws Forbidden If the asker may not
     */
    public function guardUnbinding(BeforeUnbind $event): void
    {
        $this->refuseUnlessAllowed(self::collectionOf($event->path()), '{DAV:}unbind');
    }

    /**
     * **A `MOVE` takes a member away from its collection**, and that is
     * `DAV:unbind` on the collection it came from (RFC 3744 §3.10).
     *
     * It needs a seam of its own because a move is deliberately neither a
     * removal nor a creation in this library: nothing raises `BeforeUnbind`
     * for the source, so without this a client with `DAV:bind` on the
     * destination could empty a collection it has no rights in — the file
     * would simply be somewhere else.
     *
     * The other end is already asked for: a move binds at its destination
     * like anything else.
     *
     * @throws Forbidden If the asker may not take it away from where it is
     */
    public function guardMoving(BeforeMove $event): void
    {
        $this->refuseUnlessAllowed(self::collectionOf($event->from()), '{DAV:}unbind');
    }

    /**
     * RFC 3744 §3.3: properties are their own privilege, so that a client
     * which may change the content may not thereby rename the resource.
     *
     * @throws Forbidden If the asker may not
     */
    public function guardChangingProperties(PropertiesChanging $event): void
    {
        $this->refuseUnlessAllowed($event->result()->path(), '{DAV:}write-properties');
    }

    /**
     * R-ACL-06: **a member nobody may read is not in the listing of its
     * parent.** Refusing to read it is only half of hiding it — a listing
     * that still named it would have told the client the thing exists.
     *
     * Asked in **one** question for the whole collection, which is what
     * `forPaths()` is part of the contract for (R-PRIV-01).
     */
    public function concealWhatMayNotBeRead(ListingMembers $event): void
    {
        $who = $this->whoIsAsking();

        foreach ($this->resolver->forPaths($who, $event->members()) as $path => $held) {
            if (!$held->has('{DAV:}read')) {
                $event->conceal($path);
            }
        }
    }

    /**
     * RFC 3744 §5.1: a server that keeps access control is of compliance
     * class `3` and says `access-control`.
     */
    public function announce(OptionsRequested $event): void
    {
        $event->addCompliance('3', 'access-control');
    }

    /**
     * Answers whichever of the five was asked for.
     *
     * **Nothing is worked out that nobody asked for.** Each of these costs a
     * question to the resolver, and a `PROPFIND` over two hundred members
     * would ask two hundred times for nothing.
     */
    public function describe(PropertiesRequested $event): void
    {
        $result = $event->result();

        if ($result->wants(self::SUPPORTED)) {
            $result->set(self::SUPPORTED, $this->supported());
        }

        if ($result->wants(self::CURRENT_USER)) {
            $result->set(self::CURRENT_USER, $this->whatTheAskerMayDo($result->path()));
        }

        if ($result->wants(self::ACL)) {
            $result->set(self::ACL, $this->accessControlList($result->path()));
        }

        if ($result->wants(self::RESTRICTIONS)) {
            $result->set(self::RESTRICTIONS, self::restrictions());
        }

        if ($result->wants(self::INHERITED)) {
            // §5.7: nothing here inherits a list, and an empty element says
            // that where a missing property would say we do not know.
            $result->set(self::INHERITED, new Element(self::INHERITED));
        }
    }

    /**
     * **Fail closed** (R-ACL-05): what was not granted is refused.
     *
     * @throws Forbidden If the asker does not hold that privilege here
     */
    private function refuseUnlessAllowed(string $path, string $privilege): void
    {
        if (!$this->resolver->forPath($this->whoIsAsking(), $path)->has($privilege)) {
            throw new Forbidden(sprintf('"%s" may not be changed that way by whoever is asking.', $path));
        }
    }

    /**
     * R-ACL-06: `403` says "not for you", `404` says nothing at all. Which is
     * right depends on whether the existence of the resource is itself a
     * secret, so the deployment chooses.
     *
     * **Only a refusal to read is ever hidden.** A write that was refused is
     * a `403` whatever the setting: the client plainly knows the resource is
     * there — it is writing to it — so a `404` would be a lie it could see
     * through.
     */
    private function refusalToRead(string $path): DavException
    {
        return $this->unreadableIsNotFound
            ? new NotFound(sprintf('There is nothing at "%s".', $path))
            : new Forbidden(sprintf('"%s" may not be read by whoever is asking.', $path));
    }

    /**
     * The collection a path is a member of, which is what `DAV:bind` and
     * `DAV:unbind` are privileges of.
     */
    private static function collectionOf(string $path): string
    {
        [$collection] = Path::split($path);

        return $collection;
    }

    /**
     * RFC 3744 §5.3: the tree, written as a tree.
     */
    private function supported(): Element
    {
        $supported = new Element(self::SUPPORTED);

        $supported->append(self::describedTree($this->privileges));

        return $supported;
    }

    /**
     * One `DAV:supported-privilege`, and inside it the ones it aggregates.
     */
    private static function describedTree(Privilege $privilege): Element
    {
        $element = new Element('{DAV:}supported-privilege');

        $element->append(self::holding('{DAV:}privilege', $privilege->name()));

        if ($privilege->isAbstract()) {
            $element->append(new Element('{DAV:}abstract'));
        }

        $description = new Element('{DAV:}description', ['xml:lang' => $privilege->language()]);

        $description->appendText($privilege->description());
        $element->append($description);

        foreach ($privilege->aggregates() as $child) {
            $element->append(self::describedTree($child));
        }

        return $element;
    }

    /**
     * RFC 3744 §5.4: everything the asker may do here, aggregates and all.
     */
    private function whatTheAskerMayDo(string $path): Element
    {
        $held = $this->resolver->forPath($this->whoIsAsking(), $path);
        $answer = new Element(self::CURRENT_USER);

        foreach ($held->flattened() as $name) {
            $answer->append(self::holding('{DAV:}privilege', $name));
        }

        return $answer;
    }

    /**
     * RFC 3744 §5.5: who holds something here, as the entries somebody
     * wrote.
     */
    private function accessControlList(string $path): Element
    {
        $list = new Element(self::ACL);

        foreach ($this->resolver->principalsForPath($path) as $principal => $held) {
            $list->append(self::entry($principal, $held));
        }

        return $list;
    }

    private static function entry(string $principal, PrivilegeSet $held): Element
    {
        $ace = new Element('{DAV:}ace');
        $who = new Element('{DAV:}principal');
        $grant = new Element('{DAV:}grant');

        $who->append(self::saying('{DAV:}href', $principal));
        $ace->append($who);

        foreach ($held->granted() as $name) {
            $grant->append(self::holding('{DAV:}privilege', $name));
        }

        $ace->append($grant);

        return $ace;
    }

    /**
     * RFC 3744 §5.6: what this server would refuse in a list.
     *
     * It only ever grants — there is no denial in this model — and it does
     * not invert a principal. Both are true statements about this library
     * rather than a guess at a policy.
     */
    private static function restrictions(): Element
    {
        $restrictions = new Element(self::RESTRICTIONS);

        $restrictions->append(new Element('{DAV:}grant-only'));
        $restrictions->append(new Element('{DAV:}no-invert'));

        return $restrictions;
    }

    /**
     * The principal URI of whoever is asking, or null where nobody is signed
     * in — which holds nothing (R-PRIV-03).
     *
     * The event carries a path inside the tree, because that is what the rest
     * of this library deals in; the resolver is given the URL, because that
     * is what an access control entry names.
     */
    private function whoIsAsking(): ?string
    {
        if ($this->request === null) {
            return null;
        }

        $path = $this->server->events()->emit(new CurrentPrincipalRequested($this->request))->principal();

        return $path === null ? null : $this->server->href($path);
    }

    private static function holding(string $name, string $child): Element
    {
        $element = new Element($name);

        $element->append(new Element($child));

        return $element;
    }

    private static function saying(string $name, string $text): Element
    {
        $element = new Element($name);

        $element->appendText($text);

        return $element;
    }
}
