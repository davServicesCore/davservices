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
 * How much of a hold a lock is (RFC 4918 §6.1, R-LOCK-01).
 *
 * The difference is what a second client is told. An exclusive lock is the
 * usual one: while it is held, nobody else may write, and a second request for
 * one is refused. A shared lock says only "I am working here" — others may
 * take one too, and the point of it is that a client can see it is not alone
 * before it starts.
 */
enum LockScope
{
    /** Nobody else may write, and nobody else may take one (`DAV:exclusive`). */
    case Exclusive;

    /** Others may take one as well (`DAV:shared`). */
    case Shared;
}
