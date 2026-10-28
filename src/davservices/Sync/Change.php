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

namespace DavServices\Sync;

/**
 * What became of a member between two synchronisations (RFC 6578 §3.5).
 *
 * **Two states and no third**, which is the memo's own count: "two types of
 * member URL state changes can be returned (changed or removed)". What a
 * client is told differs in shape as well as in name — §3.2 gives a changed
 * member "at least one DAV:propstat element and MUST NOT contain any
 * DAV:status element", and a removed one "one DAV:status with a value set to
 * '404 Not Found' and MUST NOT contain any DAV:propstat element".
 */
enum Change
{
    /**
     * Newly mapped, or its entity tag moved (§3.5.1).
     *
     * **Also what a member that went and came back is**, in as many words: "In
     * the case where a mapping between a member URL and the target collection
     * was removed, then a new mapping with the same URI was created, the member
     * URL MUST be reported as changed and MUST NOT be reported as removed."
     */
    case Changed;

    /**
     * Its mapping is gone and has not come back (§3.5.2).
     *
     * **Also what a member that arrived and left is**: "If a member was added
     * (and its mapped resource possibly modified), then removed between two
     * synchronization report requests, it MUST be reported as removed." That
     * is what tells a client which created the member that it is gone again.
     */
    case Removed;
}
