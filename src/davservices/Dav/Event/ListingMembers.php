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

namespace DavServices\Dav\Event;

use DavServices\Event\Event;

/**
 * Raised while the members of a collection are being listed, so that a
 * listener can keep some of them out of the answer (RFC 3744 §8.1.1,
 * R-ACL-06).
 *
 * **A `404` on a member nobody may read is only half the work.** If the
 * listing of its parent still names it, the client has been told it exists —
 * which is the one thing hiding it was meant to prevent. So the listing is
 * asked first, and what is concealed never reaches the report.
 *
 * **Every member is offered at once**, not one at a time. Deciding this is a
 * question to whatever knows the rules, and a collection of two hundred
 * members would otherwise be two hundred questions — the N+1 that
 * {@see \DavServices\Acl\IPrivilegeResolver::forPaths()} exists to prevent
 * (R-PRIV-01).
 *
 * Nobody listening means nothing is concealed, which is what a server without
 * access control does: it has no reason to hide anything (R-ARC-02).
 */
final class ListingMembers extends Event
{
    /** @var list<string> */
    private array $concealed = [];

    /**
     * @param string $path The collection being listed
     * @param list<string> $members The paths of its members, as they would
     *                              appear in the answer
     */
    public function __construct(
        private readonly string $path,
        private readonly array $members,
    ) {
    }

    /**
     * The collection being listed.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Every member that was found, before anything was concealed.
     *
     * @return list<string>
     */
    public function members(): array
    {
        return $this->members;
    }

    /**
     * Keeps these members out of the answer.
     *
     * A path that is no member of this listing is simply not in it, and
     * saying so twice is saying it once: a listener may conceal what it
     * likes without having to know what another has already hidden.
     */
    public function conceal(string ...$paths): void
    {
        foreach ($paths as $path) {
            if (!in_array($path, $this->concealed, true)) {
                $this->concealed[] = $path;
            }
        }
    }

    /**
     * The members that are to appear, in the order they were found.
     *
     * @return list<string>
     */
    public function visible(): array
    {
        return array_values(array_filter(
            $this->members,
            fn (string $member): bool => !in_array($member, $this->concealed, true),
        ));
    }
}
