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

/**
 * The name a lock is held by (R-LOCK-02, RFC 4918 §6.5).
 *
 * A lock token is the only thing between one client's half-written file and
 * another client's write: whoever holds it may change what is locked. Whoever
 * can **guess** it may do the same, and that is the whole reason this is made
 * of `random_bytes()` and `random_int()` — the two sources PHP guarantees are
 * fit for it — rather than of a counter, a timestamp or `uniqid()`, all of
 * which are guessable by anyone who knows roughly when the lock was taken.
 *
 * The form is a version 4 UUID (RFC 9562 §5.4) behind the `opaquelocktoken:`
 * scheme RFC 4918 recommends where a server has nothing better to say. It is
 * opaque on purpose: a client is to hand it back and read nothing into it.
 */
final class LockToken
{
    /** Where RFC 9562 §5.4 puts the digit that says which kind of UUID it is. */
    private const VERSION = 12;

    /** And RFC 9562 §4.1 the one that says which layout the rest follows. */
    private const VARIANT = 16;

    /**
     * A token no one has held before.
     */
    public static function fresh(): string
    {
        $uuid = bin2hex(random_bytes(16));

        // Two of the thirty-two digits are spoken for; the rest stays as it
        // came. Writing them into the finished digits rather than into the
        // bytes behind them keeps the specification's own positions visible.
        $uuid[self::VERSION] = '4';
        $uuid[self::VARIANT] = '89ab'[random_int(0, 3)];

        return sprintf(
            'opaquelocktoken:%s-%s-%s-%s-%s',
            substr($uuid, 0, 8),
            substr($uuid, 8, 4),
            substr($uuid, 12, 4),
            substr($uuid, 16, 4),
            substr($uuid, 20, 12),
        );
    }
}
