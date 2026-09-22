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

namespace DavServices\Acl;

use DavServices\Backend\IPrincipalBackend;
use DavServices\Uri\Path;

/**
 * Everything the person asking counts as (RFC 3744 §2 and §5.5.1).
 *
 * **An access control entry naming a group applies to everybody in it.**
 * §5.5.1 says so: "the current user matches DAV:href only if that user is
 * authenticated as being (**or being a member of**) the principal identified
 * by the URL contained by that DAV:href". And §2 settles how far that
 * reaches: "Membership in a group is recursive, so if a principal is a member
 * of group GRPA, and GRPA is a member of group GRPB, then the principal is
 * also a member of GRPB."
 *
 * So a check that asked only about the principal who signed in would grant
 * far less than whoever wrote the entry intended — and would do it silently.
 *
 * **This is the other half of the pair.** `DAV:group-membership` reports the
 * direct edge and nothing more, because §4.4 says "directly" in as many
 * words; the matching walks the whole chain, because §2 says so. Both are
 * true at the same time, and keeping them apart is what makes the pair
 * honest.
 *
 *     $groups = new GroupResolver($backend, 'principals');
 *     $groups->identitiesOf('principals/alice');
 *     // ['principals/alice', 'principals/staff', 'principals/everyone']
 *
 * ## The cycle guard is this server's, not the specification's
 *
 * RFC 3744 mentions cycles nowhere. It says membership "is recursive" and
 * assumes a graph that ends — which an administrator breaks in a minute by
 * putting two groups inside each other. "Recursive" over a loop is undefined,
 * so this decides: every principal is visited once. That terminates, and it
 * yields the closure, which is what "recursive" plainly means wherever it is
 * defined at all. **Refusing the request instead would lock people out over
 * somebody else's mistake.**
 *
 * Unlike `DAV:expand-property`, where the nesting of the request body bounds
 * the recursion, nothing outside bounds this one. The guard is not optional.
 */
final class GroupResolver
{
    /**
     * @param string $principalsAt Where the principal collection is mounted,
     *                             because a backend knows member names and
     *                             the application chose the path
     */
    public function __construct(
        private readonly IPrincipalBackend $backend,
        private readonly string $principalsAt,
    ) {
    }

    /**
     * The paths this one counts as: itself, then the groups outward.
     *
     * Itself is in the answer because §5.5.1 matches a principal "as being"
     * the one an entry names, not only as a member of it. A path that is no
     * principal here — something else an application put in an entry — counts
     * as itself and nothing more, since inventing groups for it is not this
     * resolver's business.
     *
     * The order is fixed rather than left to chance: it is the order
     * privileges are merged in, and an answer that came out differently
     * between requests would be a server nobody can reason about.
     *
     * @return list<string>
     */
    public function identitiesOf(string $principalPath): array
    {
        $identities = [$principalPath];

        // Walked breadth first, so the groups come out in the order they were
        // reached: the ones somebody is in, then the ones those are in. The
        // answer is its own record of what has been visited — a group already
        // in it is not followed again, which is the whole cycle guard and
        // needs no second structure to hold a value nobody reads.
        for ($at = 0; $at < count($identities); ++$at) {
            foreach ($this->groupsOf($identities[$at]) as $group) {
                if (!in_array($group, $identities, true)) {
                    $identities[] = $group;
                }
            }
        }

        return $identities;
    }

    /**
     * The groups one path is **directly** in, as paths (RFC 3744 §4.4).
     *
     * @return list<string>
     */
    private function groupsOf(string $path): array
    {
        [$collection, $name] = Path::split($path);

        if ($collection !== $this->principalsAt) {
            return [];
        }

        $principal = $this->backend->principal($name);

        if ($principal === null) {
            return [];
        }

        return array_map(
            fn (string $group): string => Path::join($this->principalsAt, $group),
            $principal->memberOf(),
        );
    }
}
