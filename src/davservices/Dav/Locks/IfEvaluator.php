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

use DavServices\Http\IfHeader;
use DavServices\Http\IfList;

/**
 * Does an `If` header hold? (RFC 4918 §10.4.3 and §10.4.4, R-HTTP-07.)
 *
 * The grammar was taken apart in {@see IfHeader}; this is the half that
 * decides. **The header is a list of lists: every condition inside a list has
 * to hold, and one list holding is enough for the header.** An *and* inside an
 * *or*, and either of them read the wrong way round turns a guard a client put
 * on its write into no guard at all.
 *
 * Each list is held against **its own** resource. A client writing to a
 * collection may say what it knows about a member, and a server that held
 * every condition against the request target would refuse it for no reason.
 *
 * Where a resource is, and what is held on it, are handed in: only the server
 * knows where it is mounted and only the lock storage knows what is held.
 * Knowing neither is what makes this testable on its own.
 */
final class IfEvaluator
{
    /**
     * @param callable(?string): ResourceState $stateOf What is known about
     *                                                  the resource a list is
     *                                                  tagged with; null for
     *                                                  the request target
     */
    public static function holds(IfHeader $header, callable $stateOf): bool
    {
        foreach ($header->lists() as $list) {
            // Asked only until one holds: every question here can be a round
            // trip to the lock storage, and to the backend for an entity tag.
            if (self::listHolds($list, $stateOf($list->resource()))) {
                return true;
            }
        }

        return false;
    }

    private static function listHolds(IfList $list, ResourceState $state): bool
    {
        foreach ($list->conditions() as $condition) {
            if (!$state->satisfies($condition)) {
                return false;
            }
        }

        return true;
    }
}
