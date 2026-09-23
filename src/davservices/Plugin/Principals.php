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

use Closure;
use DavServices\Acl\Principal;
use DavServices\Dav\Event\BeforeMethod;
use DavServices\Dav\Event\CurrentPrincipalRequested;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\PropFindResult;
use DavServices\Dav\Server;
use DavServices\Http\Request;
use DavServices\Uri\Path;
use DavServices\Xml\Element;

/**
 * The three properties that say **where** things are rather than what they
 * are (RFC 3744 §4.2 and §5.8, RFC 5397).
 *
 * Switched on in one line, like everything else here:
 *
 *     (new Principals($server, 'principals'))->register();
 *
 * Each of these is answered by a plugin rather than by a node, and for the
 * same reason: a node knows its name and nothing about where it hangs.
 *
 * **`DAV:principal-URL`** is the canonical URL of a principal. A client puts
 * it in an access control entry, so it has to be the URL this server answers
 * on — the same principal mounted at `/dav/` has a different one, and a node
 * that guessed would send clients where nothing answers.
 *
 * **`DAV:principal-collection-set`** is where the principals live, answered
 * on every resource: it is how a client finds the people before it knows any
 * of them.
 *
 * **`DAV:current-user-principal`** (RFC 5397) is who you are. Who that is
 * comes from the application, because authentication is not this plugin's
 * business — and with nobody signed in the answer is `DAV:unauthenticated`,
 * which is what the RFC gives for exactly this case. **Guessing at a
 * principal would be worse than saying nothing:** a client that believed it
 * was somebody would show that person's calendars.
 */
final class Principals
{
    private const PRINCIPAL_URL = '{DAV:}principal-URL';

    private const PRINCIPAL_COLLECTION_SET = '{DAV:}principal-collection-set';

    private const CURRENT_USER = '{DAV:}current-user-principal';

    private const GROUP_MEMBERSHIP = '{DAV:}group-membership';

    private const GROUP_MEMBER_SET = '{DAV:}group-member-set';

    /** @var Closure(Request): ?string */
    private readonly Closure $whoIsThere;

    private ?Request $request = null;

    /**
     * @param string $principalsAt Where the principal collection is mounted,
     *                             as a path inside the tree; nothing in this
     *                             library assumes one, which is why it is
     *                             said here
     * @param (callable(Request): ?string)|null $whoIsThere What the
     *                                                      application knows
     *                                                      about who is
     *                                                      signed in, as the
     *                                                      path of their
     *                                                      principal. Asked
     *                                                      per request,
     *                                                      because that is
     *                                                      what changes; null
     *                                                      answers
     *                                                      `DAV:unauthenticated`
     */
    public function __construct(
        private readonly Server $server,
        private readonly string $principalsAt = 'principals',
        ?callable $whoIsThere = null,
    ) {
        $this->whoIsThere = $whoIsThere === null
            ? static fn (Request $request): ?string => null
            : $whoIsThere(...);
    }

    /**
     * Switches the three properties on.
     *
     * **It announces no compliance class.** It used to say `access-control`,
     * and that was untrue: RFC 3744 §7.2 makes the value mean that the server
     * "supports all MUST level requirements and REQUIRED features specified
     * in this document", and principal properties are §4 alone — none of the
     * access control properties of §5, none of the enforcement of §6, none of
     * §7.1.1's `DAV:need-privileges`, none of §9's reports. RFC 5397, the
     * other half of what this answers, defines no compliance token at all.
     *
     * A server that really does support access control says so through
     * {@see Acl}, which owes what the word promises.
     */
    public function register(): void
    {
        $events = $this->server->events();

        $events->on(BeforeMethod::class, $this->rememberTheRequest(...));
        $events->on(PropertiesRequested::class, $this->describe(...));

        // What the application knows about who is signed in is answered on
        // the one event everything asks, rather than kept here: more than one
        // part of this library needs it, and two ideas of who somebody is
        // would be two answers that can disagree.
        $events->on(CurrentPrincipalRequested::class, $this->nameWhoIsThere(...));
    }

    /**
     * Answers the one question about identity with whatever the application
     * was given to answer it with.
     *
     * A plugin that authenticates answers the same event instead, and then
     * this one has nothing to say (R-ARC-02).
     */
    public function nameWhoIsThere(CurrentPrincipalRequested $event): void
    {
        $path = ($this->whoIsThere)($event->request());

        if ($path !== null) {
            $event->answerWith($path);
        }
    }

    /**
     * Keeps the request being answered, because who is signed in is a
     * property of the request rather than of the tree.
     */
    public function rememberTheRequest(BeforeMethod $event): void
    {
        $this->request = $event->request();
    }

    /**
     * Answers whichever of them was asked for.
     */
    public function describe(PropertiesRequested $event): void
    {
        $result = $event->result();
        $node = $event->node();

        if ($node instanceof Principal) {
            $this->describeThePrincipal($result, $node);
        }

        if ($result->wants(self::PRINCIPAL_COLLECTION_SET)) {
            $result->set(
                self::PRINCIPAL_COLLECTION_SET,
                $this->holdingHref(self::PRINCIPAL_COLLECTION_SET, $this->principalsAt),
            );
        }

        if ($result->wants(self::CURRENT_USER)) {
            $result->set(self::CURRENT_USER, $this->currentUser());
        }
    }

    /**
     * The three properties only a principal has.
     *
     * **`DAV:group-membership` is always answered** (RFC 3744 §4.4 makes
     * support REQUIRED), and it names the groups this principal is *directly*
     * in — §4.4's own word. A client wanting the rest is told by §4.4 to ask
     * those groups in turn, which is what `DAV:expand-property` is for; a
     * server answering the whole chain here would be lying to a client doing
     * exactly what it was told, and counting some groups twice.
     *
     * **`DAV:group-member-set` is answered only where the backend says.**
     * §4.3 is the one property of §4 without "Support for this property is
     * REQUIRED", so a directory that will not hand out rosters is within its
     * rights — and a `404` is how this server passes that on, rather than an
     * empty set, which would claim the group has nobody in it.
     */
    private function describeThePrincipal(PropFindResult $result, Principal $node): void
    {
        if ($result->wants(self::PRINCIPAL_URL)) {
            $result->set(self::PRINCIPAL_URL, $this->holdingHref(self::PRINCIPAL_URL, $result->path()));
        }

        if ($result->wants(self::GROUP_MEMBERSHIP)) {
            $result->set(self::GROUP_MEMBERSHIP, $this->holdingMembers(self::GROUP_MEMBERSHIP, $node->memberOf()));
        }

        $members = $node->members();

        if ($members !== null && $result->wants(self::GROUP_MEMBER_SET)) {
            $result->set(self::GROUP_MEMBER_SET, $this->holdingMembers(self::GROUP_MEMBER_SET, $members));
        }
    }

    /**
     * One property holding an href per principal, built from the member names
     * the backend gave — the node knows those, and only the server knows
     * where the principal collection is mounted.
     *
     * @param list<string> $names
     */
    private function holdingMembers(string $name, array $names): Element
    {
        $property = new Element($name);

        foreach ($names as $member) {
            $href = new Element('{DAV:}href');

            $href->appendText($this->server->href(Path::join($this->principalsAt, $member)));
            $property->append($href);
        }

        return $property;
    }

    /**
     * RFC 5397: the principal of whoever is signed in, or
     * `DAV:unauthenticated` where nobody is.
     */
    private function currentUser(): Element
    {
        $path = $this->request === null
            ? null
            : $this->server->events()->emit(new CurrentPrincipalRequested($this->request))->principal();

        if ($path === null) {
            $answer = new Element(self::CURRENT_USER);

            $answer->append(new Element('{DAV:}unauthenticated'));

            return $answer;
        }

        return $this->holdingHref(self::CURRENT_USER, $path);
    }

    /**
     * One property holding one `DAV:href`, which is the shape RFC 3744 gives
     * all three of these — and the URL is made by the server, because only it
     * knows where it is mounted.
     */
    private function holdingHref(string $name, string $path): Element
    {
        $property = new Element($name);
        $href = new Element('{DAV:}href');

        $href->appendText($this->server->href($path));
        $property->append($href);

        return $property;
    }
}
