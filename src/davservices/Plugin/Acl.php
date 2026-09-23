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

use DavServices\Acl\GroupResolver;
use DavServices\Acl\IPrivilegeResolver;
use DavServices\Acl\Privilege;
use DavServices\Acl\PrivilegeSet;
use DavServices\Acl\Report\AclPrincipalPropSet;
use DavServices\Acl\Report\PrincipalMatch;
use DavServices\Acl\Report\PrincipalPropertySearch;
use DavServices\Acl\Report\PrincipalSearchPropertySet;
use DavServices\Acl\SearchableProperty;
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
use DavServices\Dav\Method\Report;
use DavServices\Dav\Report\ExpandProperty;
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
     * Everything the asker counts as, worked out once for the request.
     *
     * @var list<string|null>|null
     */
    private ?array $identities = null;

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
    /**
     * @param GroupResolver|null $groups What the asker counts as besides
     *                                   themselves (RFC 3744 §2 and §5.5.1).
     *                                   Null where a server keeps no
     *                                   principals — there are then no groups
     *                                   to be in, and the recursion is empty
     *                                   rather than missing
     */
    public function __construct(
        private readonly Server $server,
        private readonly IPrivilegeResolver $resolver,
        ?Privilege $privileges = null,
        private readonly bool $unreadableIsNotFound = false,
        private readonly ?GroupResolver $groups = null,
    ) {
        $this->privileges = $privileges ?? Privilege::standard();
    }

    /**
     * Switches the five properties on, the compliance class that tells a
     * client to ask for them, and the five reports that class stands for.
     *
     * **`REPORT` is asked for rather than assumed** (RFC 3744 §7.2): the
     * announcement this makes "MUST indicate that the server supports all
     * MUST level requirements and REQUIRED features specified in this
     * document", and five reports are among them. Taking the method here is
     * what makes announcing without answering impossible — the application
     * builds one `REPORT` and hands it round, so a deployment cannot switch
     * access control on and leave a promise unkept.
     */
    public function register(Report $report): void
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

        $this->answerTheReportsItOwes($report);
    }

    /**
     * The five reports a server announcing `access-control` owes.
     *
     * Four of them say so themselves — §9.2, §9.3, §9.4 and §9.5 each end
     * on "Support for this report is REQUIRED" — and §9.1 adds the fifth
     * from another specification: "A server that supports the WebDAV Access
     * Control Protocol MUST support the DAV:expand-property report (defined
     * in Section 3.8 of [RFC3253])."
     *
     * **What is registered is a default, not a decision taken from the
     * application.** A deployment with its own idea of what may be searched,
     * or its own limit on how much is reported at once, registers its own
     * afterwards and that one answers: this fills the gap rather than
     * holding the place.
     *
     * `DAV:principal-match` is given the group resolver, because §2 makes
     * matching reach through the groups somebody is in and this plugin
     * already holds what works that out. A report wired without it would
     * give a client a narrower answer than the same server gives everywhere
     * else.
     */
    private function answerTheReportsItOwes(Report $report): void
    {
        $searchable = SearchableProperty::standard();

        $report->on('{DAV:}expand-property', (new ExpandProperty($this->server))(...));
        $report->on('{DAV:}acl-principal-prop-set', (new AclPrincipalPropSet($this->server))(...));
        $report->on('{DAV:}principal-match', (new PrincipalMatch($this->server, $this->groups))(...));
        $report->on(
            '{DAV:}principal-property-search',
            (new PrincipalPropertySearch($this->server, $searchable))(...),
        );
        $report->on(
            '{DAV:}principal-search-property-set',
            (new PrincipalSearchPropertySet($this->server, $searchable))(...),
        );
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

        // A new request is a new asker. Remembering across one would go on
        // granting what somebody had just been taken out of a group for.
        $this->identities = null;

        $method = $event->request()->method();
        $needed = self::READING[$method] ?? null;

        if ($needed !== null) {
            $path = $this->server->path($event->request());

            if (!$this->heldOn($path)->has($needed)) {
                throw $this->refusalToRead($path, $needed);
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
        foreach ($this->heldOnEach($event->members()) as $path => $held) {
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
        if (!$this->heldOn($path)->has($privilege)) {
            throw new Forbidden(
                sprintf('"%s" may not be changed that way by whoever is asking.', $path),
                $this->needing($path, $privilege),
            );
        }
    }

    /**
     * **RFC 3744 §7.1.1: a refusal says what was missing, and where.**
     *
     * > If an HTTP method fails due to insufficient privileges, the response
     * > body to the "403 Forbidden" error MUST contain the `<DAV:error>`
     * > element, which in turn contains the `<DAV:need-privileges>` element,
     * > which contains one or more `<DAV:resource>` elements indicating which
     * > resource had insufficient privileges, and what the lacking privileges
     * > were.
     *
     * **The resource named is the one that lacked the privilege**, which is
     * not always the request target: a `PUT` that creates a member needs
     * `DAV:bind` on the *collection*, and telling a client the file's own URL
     * would send it to change the rights on something that does not exist
     * yet.
     *
     * The href is made where every other href in this library is made, since
     * a client is being told where to go and put something right.
     */
    private function needing(string $path, string $privilege): Element
    {
        $needed = new Element('{DAV:}need-privileges');
        $resource = new Element('{DAV:}resource');

        $tree = $this->server->tree();

        // RFC 4918 §8.3: a collection is named with a trailing slash, and a
        // client is about to compare this href with one it already holds. The
        // node is asked for only where there is one — a request for something
        // that is not there can be refused for want of a privilege before
        // anything has looked for it.
        $resource->append(self::saying(
            '{DAV:}href',
            $tree->exists($path) ? $this->server->hrefOf($path, $tree->node($path)) : $this->server->href($path),
        ));
        $resource->append(self::holding('{DAV:}privilege', $privilege));
        $needed->append($resource);

        return $needed;
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
    private function refusalToRead(string $path, string $privilege): DavException
    {
        // §7.1.1 is about the body of a `403`. Where a deployment hides an
        // unreadable resource instead, nothing is described: a server that
        // will not admit a resource exists would hardly go on to say which
        // privileges it wanted for it.
        return $this->unreadableIsNotFound
            ? new NotFound(sprintf('There is nothing at "%s".', $path))
            : new Forbidden(
                sprintf('"%s" may not be read by whoever is asking.', $path),
                $this->needing($path, $privilege),
            );
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
        $held = $this->heldOn($path);
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

    /**
     * The four principals of RFC 3744 §5.5.1 that are a name and nothing
     * else.
     *
     * > `<!ELEMENT principal (href | all | authenticated | unauthenticated |
     * > property | self)>`
     *
     * A resolver names principals by URL, and a URL never looks like
     * `{DAV:}all` — so the same string carries both without ambiguity, and
     * the seam did not have to change shape to say what §5.5 requires it to
     * be able to say.
     *
     * `DAV:property` and `DAV:invert` are **not** here and cannot be: the
     * first carries a property name inside it, the second wraps a whole
     * principal. Neither fits in a name. A deployment granting by those forms
     * cannot report them through this seam — written down rather than left
     * to be discovered, because the alternative is a server that quietly
     * reports something else.
     *
     * @var list<string>
     */
    private const PRINCIPALS_THAT_ARE_ONLY_A_NAME = [
        '{DAV:}all',
        '{DAV:}authenticated',
        '{DAV:}unauthenticated',
        '{DAV:}self',
    ];

    private static function entry(string $principal, PrivilegeSet $held): Element
    {
        $ace = new Element('{DAV:}ace');
        $who = new Element('{DAV:}principal');
        $grant = new Element('{DAV:}grant');

        // Anything else is an href, whatever it looks like: guessing would be
        // inventing an entry form §5.5.1 does not have.
        $who->append(
            in_array($principal, self::PRINCIPALS_THAT_ARE_ONLY_A_NAME, true)
                ? new Element($principal)
                : self::saying('{DAV:}href', $principal),
        );

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
     * What the asker holds on one path, through every identity they have.
     *
     * **RFC 3744 §5.5.1: the current user matches an entry naming a
     * principal "as being (or being a member of)" it**, and §2 makes that
     * membership recursive. So the question goes out once per identity and
     * the answers are merged — which is how this library guarantees §2
     * rather than leaving each application to remember it. A resolver that
     * only ever compared the principal who signed in would deny somebody a
     * group had been given the right to, and say nothing about it.
     */
    private function heldOn(string $path): PrivilegeSet
    {
        $held = PrivilegeSet::nothing();

        foreach ($this->identitiesAsking() as $identity) {
            $held = $held->merged($this->resolver->forPath($identity, $path));
        }

        return $held;
    }

    /**
     * The same for a whole listing, keeping `forPaths()` batched.
     *
     * It is one question per identity, not one per path: a collection of two
     * hundred members is still two hundred paths in one question, which is
     * what the resolver contract counts (R-PRIV-01).
     *
     * @param list<string> $paths
     *
     * @return array<string, PrivilegeSet>
     */
    private function heldOnEach(array $paths): array
    {
        $held = [];

        foreach ($this->identitiesAsking() as $identity) {
            foreach ($this->resolver->forPaths($identity, $paths) as $path => $set) {
                $held[$path] = isset($held[$path]) ? $held[$path]->merged($set) : $set;
            }
        }

        return $held;
    }

    /**
     * Everything the asker counts as, worked out once for this request.
     *
     * Nobody signed in counts as nobody — one identity, and a null one,
     * because an entry may name `DAV:unauthenticated` and that has to be
     * asked about too (§5.5.1). A server with no principal collection has no
     * groups, so the asker counts as themselves alone; that is R-ARC-02, not
     * a gap.
     *
     * @return list<string|null>
     */
    private function identitiesAsking(): array
    {
        if ($this->identities !== null) {
            return $this->identities;
        }

        $path = $this->pathAsking();

        if ($path === null) {
            return $this->identities = [null];
        }

        $paths = $this->groups === null ? [$path] : $this->groups->identitiesOf($path);

        // The resolver is given URLs, because that is what an access control
        // entry names; the tree deals in paths.
        return $this->identities = array_map($this->server->href(...), $paths);
    }

    /**
     * The path of whoever is asking, or null where nobody is signed in.
     */
    private function pathAsking(): ?string
    {
        if ($this->request === null) {
            return null;
        }

        return $this->server->events()->emit(new CurrentPrincipalRequested($this->request))->principal();
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
