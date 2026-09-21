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
use DavServices\Dav\Event\OptionsRequested;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\Server;
use DavServices\Http\Request;
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
     * Switches the three properties on, and the compliance class that tells a
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
     * class `3` and says `access-control`. A client reads that before it asks
     * for any of this.
     */
    public function announce(OptionsRequested $event): void
    {
        $event->addCompliance('3', 'access-control');
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
     * Answers whichever of the three was asked for.
     */
    public function describe(PropertiesRequested $event): void
    {
        $result = $event->result();

        if ($result->wants(self::PRINCIPAL_URL) && $event->node() instanceof Principal) {
            $result->set(self::PRINCIPAL_URL, $this->holdingHref(self::PRINCIPAL_URL, $result->path()));
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
     * RFC 5397: the principal of whoever is signed in, or
     * `DAV:unauthenticated` where nobody is.
     */
    private function currentUser(): Element
    {
        $path = $this->request === null ? null : ($this->whoIsThere)($this->request);

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
