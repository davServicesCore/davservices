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

namespace DavServices\Dav;

use DavServices\Dav\Event\ListingMembers;
use DavServices\Event\EventEmitter;
use DavServices\Uri\Path;

/**
 * The members of a collection that are to appear at all.
 *
 * **Refusing to read a resource is only half of hiding it** (R-ACL-06). A
 * listing that still named the thing has told the client it exists, which is
 * usually most of what somebody wanted to know. So every walk over a
 * collection comes through here, and what appears is decided in one place.
 *
 * It was private inside `PROPFIND` until `DAV:principal-property-search`
 * needed the same walk — and a search that listed members for itself would
 * have been a second way into a collection, the one without the access
 * control on it.
 *
 * **The whole listing is offered at once** (R-PRIV-01), because deciding this
 * is a question to whatever knows the rules, and a collection of two hundred
 * members asked one at a time is two hundred questions.
 */
final class VisibleMembers
{
    /**
     * What may be seen in this collection, keyed by path.
     *
     * A listener conceals members; it does not invent them. So the answer is
     * built from what the collection held, keeping the order it gave —
     * whatever a listener names that was never a member is simply not here,
     * rather than a path with the wrong node behind it.
     *
     * @param string $path The path of the collection itself
     *
     * @return array<string, INode>
     */
    public static function of(EventEmitter $events, string $path, ICollection $node): array
    {
        $found = [];

        foreach ($node->children() as $child) {
            $found[Path::join($path, $child->name())] = $child;
        }

        $listing = $events->emit(new ListingMembers($path, array_keys($found)));
        $shown = array_flip($listing->visible());
        $visible = [];

        foreach ($found as $member => $child) {
            if (isset($shown[$member])) {
                $visible[$member] = $child;
            }
        }

        return $visible;
    }
}
