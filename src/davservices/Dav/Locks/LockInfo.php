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

namespace DavServices\Dav\Locks;

use DateTimeImmutable;
use DavServices\Uri\Path;
use DavServices\Xml\Element;

/**
 * One write lock (R-LOCK-01, RFC 4918 §6).
 *
 * A lock is held against a path rather than against a node: it outlives the
 * objects of one request, and it goes on holding a path after what was there
 * has been deleted — which is what keeps a second client from putting
 * something else in its place.
 *
 * Two questions everything else asks it.
 *
 * **What does it reach?** One taken at `Depth: 0` holds its own resource; one
 * taken at `infinity` holds everything below its root as well, which is how a
 * client stops a whole calendar from moving under it. The slash matters here
 * as everywhere: `alice2` does not lie below `alice`.
 *
 * **Is it still there?** A lock ends (R-LOCK-03), and one that has ended is
 * not a lock. The moment is handed in rather than read from the clock, so that
 * two parts of one request cannot disagree about what time it is — and so that
 * the question has an answer a test can check.
 */
final class LockInfo
{
    /**
     * @param string $root The path it was taken on, which is its
     *                     `DAV:lockroot`
     * @param string $token The name it is held by; see {@see LockToken}
     * @param bool $deep Whether it reaches below its root, which is the
     *                   `Depth: infinity` of RFC 4918 §9.10.3
     * @param Element|string|null $owner Whatever the client wrote about
     *                                   itself, kept as it arrived
     * @param DateTimeImmutable|null $expiresAt When it ends; null for one
     *                                          taken with `Timeout: Infinite`
     */
    public function __construct(
        private readonly string $root,
        private readonly string $token,
        private readonly LockScope $scope,
        private readonly bool $deep,
        private readonly Element|string|null $owner,
        private readonly ?DateTimeImmutable $expiresAt,
    ) {
    }

    /**
     * The path it was taken on.
     */
    public function root(): string
    {
        return $this->root;
    }

    /**
     * The name it is held by.
     */
    public function token(): string
    {
        return $this->token;
    }

    /**
     * Whether anybody else may hold one here at the same time.
     */
    public function scope(): LockScope
    {
        return $this->scope;
    }

    /**
     * Whether it reaches below its root.
     */
    public function isDeep(): bool
    {
        return $this->deep;
    }

    /**
     * Whatever the client wrote about itself, as it wrote it.
     */
    public function owner(): Element|string|null
    {
        return $this->owner;
    }

    /**
     * When it ends, or null for one that does not.
     */
    public function expiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * The same lock, held until later (RFC 4918 §9.10.2).
     *
     * What a refresh is: the same token, the same reach, the same owner. A
     * server that handed out a **new** lock would leave the old one standing,
     * held by nobody, and the client would find its own resource locked
     * against it until that one ran out.
     */
    public function until(?DateTimeImmutable $expiresAt): self
    {
        return new self($this->root, $this->token, $this->scope, $this->deep, $this->owner, $expiresAt);
    }

    /**
     * Does this lock hold that path?
     *
     * The slash is the whole of it: `alice2` does not lie below `alice`, and a
     * lock that thought otherwise would refuse writes to somebody else's
     * account. The root of the tree is spelt as nothing at all, and a deep
     * lock there reaches everything.
     */
    public function covers(string $path): bool
    {
        if ($path === $this->root) {
            return true;
        }

        return $this->deep && Path::isBelow($path, $this->root);
    }

    /**
     * Has it run out by then?
     *
     * The moment it ends is the first moment it is gone: a lock good for ten
     * seconds is not still good at the tenth.
     */
    public function hasExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $now >= $this->expiresAt;
    }
}
