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
use DateTimeImmutable;
use DavServices\Backend\ILockBackend;
use DavServices\Dav\Event\AfterBind;
use DavServices\Dav\Event\AfterCreateFile;
use DavServices\Dav\Event\BeforeBind;
use DavServices\Dav\Event\OptionsRequested;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\Locks\LockDiscovery;
use DavServices\Dav\Locks\LockInfo;
use DavServices\Dav\Locks\LockRequest;
use DavServices\Dav\Locks\LockScope;
use DavServices\Dav\Locks\LockToken;
use DavServices\Dav\Server;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Conflict;
use DavServices\Exception\Locked;
use DavServices\Exception\PreconditionFailed;
use DavServices\Http\IfHeader;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Uri\Path;
use DavServices\Xml\Element;
use InvalidArgumentException;

/**
 * `LOCK` and `UNLOCK`, and everything a client is told about them (R-LOCK-01,
 * R-LOCK-03).
 *
 * Switched on in one place, because a server without it is a plain WebDAV
 * server rather than a broken one (R-LOCK-06):
 *
 *     $locks = new Locks($server, new LockBackend('/var/lib/davservices/locks'));
 *     $locks->register();
 *
 * Four things are decided here, and each can be got wrong in a way nobody
 * notices until a client loses work.
 *
 * **Who may take a lock.** Two locks conflict when at least one of them is
 * exclusive; two shared ones never do (RFC 4918 §6.1). A deep lock has to
 * clear everything below it as well, because a collection cannot be held whole
 * while somebody holds a piece of it.
 *
 * **How long it lasts.** The client asks, this decides, and the maximum
 * belongs to the server (R-LOCK-03). `Infinite` is answered with the maximum
 * rather than with forever: a lock that never ends is one nobody can clear
 * after a client has crashed.
 *
 * **What a refresh is.** RFC 4918 §9.10.2: a `LOCK` with no body, naming the
 * lock in the `If` header. It is the same lock held until later — handing out
 * a new one would leave the old standing, held by nobody, until it ran out.
 *
 * **Where a lock is released.** `UNLOCK` names a token, and it has to be one
 * held at that path, or a client could release somebody else's resource by
 * accident.
 *
 * What this does **not** yet do is refuse the writes that a lock stands in the
 * way of. That is R-LOCK-04 and comes with the `If` evaluation; until then a
 * lock is a promise this server keeps a record of and tells the truth about.
 */
final class Locks
{
    private const LOCK_DISCOVERY = '{DAV:}lockdiscovery';

    private const SUPPORTED_LOCK = '{DAV:}supportedlock';

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $now;

    /**
     * @param int $maximumSeconds The longest a lock is handed out for,
     *                            whatever the client asks (R-LOCK-03)
     * @param (callable(): DateTimeImmutable)|null $now The clock, handed in so
     *                                                  that a test can say
     *                                                  what time it is and so
     *                                                  that one request reads
     *                                                  it once (R-ARC-05)
     *
     * @throws InvalidArgumentException If the maximum is no stretch of time
     */
    public function __construct(
        private readonly Server $server,
        private readonly ILockBackend $locks,
        private readonly int $maximumSeconds = 3600,
        ?callable $now = null,
    ) {
        if ($maximumSeconds < 1) {
            throw new InvalidArgumentException('A lock has to last at least a second to be worth taking.');
        }

        $this->now = $now === null
            ? static fn (): DateTimeImmutable => new DateTimeImmutable()
            : $now(...);
    }

    /**
     * Switches locking on: the two methods, the compliance class `OPTIONS`
     * answers with, and the two properties a `PROPFIND` may ask for.
     */
    public function register(): void
    {
        $this->server->onMethod('LOCK', $this->lock(...));
        $this->server->onMethod('UNLOCK', $this->unlock(...));

        $this->server->events()->on(OptionsRequested::class, $this->announce(...));
        $this->server->events()->on(PropertiesRequested::class, $this->describe(...));
    }

    /**
     * Takes a lock, or holds an existing one for longer.
     *
     * @throws Conflict If the collection a new resource would go in is missing
     * @throws Locked If somebody else holds what was asked for
     * @throws PreconditionFailed If a refresh names no lock held at the path
     * @throws BadRequest If the request cannot be read
     */
    public function lock(Request $request): Response
    {
        $path = $this->server->path($request);
        $now = ($this->now)();

        // RFC 4918 §9.10.2: no body at all is a refresh of a lock the `If`
        // header names, not a request for a new one.
        if ($request->body()->isEmpty()) {
            return $this->answer($this->refreshed($path, $request, $now), 200, $now);
        }

        $asked = LockRequest::read($this->server->reader()->parse($request->body()->contents()), $request->headers());
        $created = $this->createWhereNothingIsYet($path);

        $this->refuseWhereItWouldConflict($path, $asked->scope(), $asked->isDeep(), $now);

        $lock = new LockInfo(
            $path,
            LockToken::fresh(),
            $asked->scope(),
            $asked->isDeep(),
            $asked->owner(),
            $this->endOf($now, $asked->seconds()),
        );

        $this->locks->set($lock);

        return $this->answer($lock, $created ? 201 : 200, $now)
            ->withHeader('Lock-Token', sprintf('<%s>', $lock->token()));
    }

    /**
     * Releases the lock the `Lock-Token` header names.
     *
     * @throws BadRequest If the request names no token
     * @throws Conflict If the token names no lock held at this path
     */
    public function unlock(Request $request): Response
    {
        $token = $request->headers()->first('Lock-Token');

        if ($token === null) {
            throw new BadRequest('An UNLOCK says which lock it releases in a Lock-Token header.');
        }

        $path = $this->server->path($request);
        $wanted = trim($token, '<> ');

        foreach ($this->locks->locksOn($path, ($this->now)()) as $lock) {
            // The root, not merely something the lock holds: a deep lock is
            // released where it was taken, because that is the resource the
            // client was told it holds.
            if ($lock->token() === $wanted && $lock->root() === $path) {
                $this->locks->remove($lock);

                return new Response(204);
            }
        }

        throw new Conflict(sprintf('No lock of "%s" is held here.', $wanted), '{DAV:}lock-token-matches-request-uri');
    }

    /**
     * RFC 4918 §18.2: a server that takes write locks is of compliance class
     * 2, and `OPTIONS` is where a client finds that out.
     */
    public function announce(OptionsRequested $event): void
    {
        $event->addCompliance('2');
    }

    /**
     * Answers the two properties RFC 4918 §15.8 and §15.10 ask of a locking
     * server: what holds this resource, and what kinds of lock may be asked
     * for at all.
     */
    public function describe(PropertiesRequested $event): void
    {
        $result = $event->result();

        if ($result->wants(self::LOCK_DISCOVERY)) {
            $result->set(self::LOCK_DISCOVERY, $this->discoveryOf($result->path()));
        }

        if ($result->wants(self::SUPPORTED_LOCK)) {
            $result->set(self::SUPPORTED_LOCK, self::supported());
        }
    }

    /**
     * RFC 4918 §9.10.2: the lock named in the `If` header, held until later.
     *
     * @throws PreconditionFailed If no lock held here is named
     */
    private function refreshed(string $path, Request $request, DateTimeImmutable $now): LockInfo
    {
        $tokens = self::tokensIn($request->headers()->first('If'));

        foreach ($this->locks->locksOn($path, $now) as $held) {
            if (in_array($held->token(), $tokens, true)) {
                $refreshed = $held->until($this->endOf($now, LockRequest::secondsAsked($request->headers())));

                $this->locks->set($refreshed);

                return $refreshed;
            }
        }

        throw new PreconditionFailed('A refresh names the lock it refreshes, and no lock of that name is held here.');
    }

    /**
     * RFC 4918 §9.10.4: a `LOCK` on a path that holds nothing creates an
     * empty resource, which is how a client reserves a name before it
     * uploads to it.
     *
     * @throws Conflict If the collection it would go in is not there
     *
     * @return bool Whether anything was created
     */
    private function createWhereNothingIsYet(string $path): bool
    {
        if ($this->server->tree()->exists($path)) {
            return false;
        }

        [$parentPath, $name] = Path::split($path);
        $parent = $this->server->tree()->collectionAt($parentPath);

        $this->server->events()->emit(new BeforeBind($path));

        $parent->createFile($name);

        $this->server->tree()->forget($parentPath);
        $this->server->events()->emit(new AfterCreateFile($path));
        $this->server->events()->emit(new AfterBind($path));

        return true;
    }

    /**
     * **Two locks conflict when at least one of them is exclusive** (RFC 4918
     * §6.1), and a deep lock has to clear what lies below it as well.
     *
     * @throws Locked If one of them stands in the way
     */
    private function refuseWhereItWouldConflict(string $path, LockScope $scope, bool $deep, DateTimeImmutable $now): void
    {
        $held = $this->locks->locksOn($path, $now);

        if ($deep) {
            $held = [...$held, ...$this->locks->locksBelow($path, $now)];
        }

        foreach ($held as $lock) {
            if ($scope === LockScope::Exclusive || $lock->scope() === LockScope::Exclusive) {
                throw new Locked(
                    sprintf('"%s" is held already.', $lock->root()),
                    '{DAV:}no-conflicting-lock',
                );
            }
        }
    }

    /**
     * When the lock ends: R-LOCK-03's maximum, or what the client asked for
     * where that is less. A client that named nothing gets the maximum.
     *
     * Counted in seconds rather than said in words, because a moment worked
     * out by parsing a sentence is a moment that can fail to be one.
     */
    private function endOf(DateTimeImmutable $now, ?int $asked): DateTimeImmutable
    {
        $seconds = $asked === null ? $this->maximumSeconds : min($asked, $this->maximumSeconds);

        return $now->setTimestamp($now->getTimestamp() + $seconds);
    }

    private function answer(LockInfo $lock, int $status, DateTimeImmutable $now): Response
    {
        $body = LockDiscovery::asProperty([$lock], $this->server->href(...), $now);

        return (new Response($status, body: $this->server->writer()->write($body)))
            ->withHeader('Content-Type', 'application/xml; charset=utf-8');
    }

    private function discoveryOf(string $path): Element
    {
        $now = ($this->now)();

        return LockDiscovery::element($this->locks->locksOn($path, $now), $this->server->href(...), $now);
    }

    /**
     * RFC 4918 §15.10: the kinds of lock that may be asked for, which are the
     * two this server takes.
     */
    private static function supported(): Element
    {
        $supported = new Element(self::SUPPORTED_LOCK);

        foreach (['{DAV:}exclusive', '{DAV:}shared'] as $scope) {
            $entry = new Element('{DAV:}lockentry');

            $entry->append(self::holding('{DAV:}lockscope', $scope));
            $entry->append(self::holding('{DAV:}locktype', '{DAV:}write'));
            $supported->append($entry);
        }

        return $supported;
    }

    private static function holding(string $name, string $child): Element
    {
        $element = new Element($name);

        $element->append(new Element($child));

        return $element;
    }

    /**
     * The state tokens of an `If` header, of which a refresh needs one.
     *
     * Whether the conditions of that header *hold* is R-LOCK-04's question,
     * and this is not it: a refresh asks only which lock is meant.
     *
     * @return list<string>
     */
    private static function tokensIn(?string $header): array
    {
        if ($header === null) {
            return [];
        }

        $tokens = [];

        foreach (IfHeader::parse($header)->lists() as $list) {
            foreach ($list->conditions() as $condition) {
                $token = $condition->stateToken();

                if ($token !== null) {
                    $tokens[] = $token;
                }
            }
        }

        return $tokens;
    }
}
