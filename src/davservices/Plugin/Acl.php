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
use DavServices\Dav\Event\BeforeMethod;
use DavServices\Dav\Event\CurrentPrincipalRequested;
use DavServices\Dav\Event\OptionsRequested;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\Server;
use DavServices\Http\Request;
use DavServices\Xml\Element;

/**
 * What a client is told about access control (RFC 3744 §5, R-ACL-01).
 *
 * Switched on with the resolver that knows an application's own rules:
 *
 *     (new Acl($server, new MemoizingPrivilegeResolver($yourResolver)))->register();
 *
 * **This reports; it does not refuse.** Everything here answers questions a
 * client asked — who may do what, and what this server can be asked to grant.
 * Turning a refusal into a `403` is the next piece of work and belongs on the
 * seams a write passes through, not here.
 *
 * Four of the five are easy to get wrong in a way that looks right:
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

    private readonly Privilege $privileges;

    private ?Request $request = null;

    /**
     * @param Privilege|null $privileges What this server can be asked to
     *                                   grant; the tree of RFC 3744 §3
     *                                   unless an extension has added to it
     */
    public function __construct(
        private readonly Server $server,
        private readonly IPrivilegeResolver $resolver,
        ?Privilege $privileges = null,
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

        $events->on(BeforeMethod::class, $this->rememberTheRequest(...));
        $events->on(OptionsRequested::class, $this->announce(...));
        $events->on(PropertiesRequested::class, $this->describe(...));
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
     * Keeps the request being answered, because who is asking is a property
     * of the request rather than of the tree.
     */
    public function rememberTheRequest(BeforeMethod $event): void
    {
        $this->request = $event->request();
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
