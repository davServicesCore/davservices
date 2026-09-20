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

namespace DavServices\Backend;

use DateTimeImmutable;
use DavServices\Dav\Locks\LockInfo;

/**
 * Where the write locks of RFC 4918 §6 are kept (R-LOCK-05).
 *
 * Keyed by path, like the properties and for the same reason: a lock outlives
 * the objects of one request, and it goes on holding a path after what was
 * there has been deleted — which is what keeps a second client from putting
 * something else in its place.
 *
 * **It has to outlive the process, too.** Two requests to one server are two
 * processes as often as not, and a lock only one of them can see is not a
 * lock. That is why there is no in-memory implementation of this interface in
 * the library: it would look like it worked and would hold nothing.
 *
 * Two questions are asked of it, and they are not the same question. What
 * holds *this* path — the locks on it and the deep ones above it — is what a
 * write has to get past. What lies *below* this path is what a new deep lock
 * has to get past, because a collection cannot be locked whole while somebody
 * holds a piece of it.
 *
 * A lock that has run out is not a lock (R-LOCK-03). Neither question hands
 * one back, and an implementation is free to drop it while it is there.
 */
interface ILockBackend
{
    /**
     * The locks that hold a path: the ones taken on it, and the deep ones
     * taken on a collection above it.
     *
     * @param DateTimeImmutable $now What the request takes the time to be, so
     *                               that two parts of it cannot disagree
     *
     * @return list<LockInfo>
     */
    public function locksOn(string $path, DateTimeImmutable $now): array;

    /**
     * The locks taken strictly below a path.
     *
     * What a `LOCK` with `Depth: infinity` has to get past: a collection is
     * not free to be locked whole while somebody holds a piece of it.
     *
     * @return list<LockInfo>
     */
    public function locksBelow(string $path, DateTimeImmutable $now): array;

    /**
     * Keeps a lock, or replaces the one of the same token.
     *
     * Replacing is what a refresh is (RFC 4918 §9.10.2): the same lock, held
     * until later.
     */
    public function set(LockInfo $lock): void;

    /**
     * Drops a lock. One that is not there is no error — something else has
     * done what the client asked for.
     */
    public function remove(LockInfo $lock): void;
}
