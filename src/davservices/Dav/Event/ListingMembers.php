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
 * Raised where paths are about to be named in an answer, so that a listener
 * can keep some of them out of it (RFC 3744 §8.1.1, R-ACL-06).
 *
 * **A `404` on a resource nobody may read is only half the work.** If an
 * answer still names it, the client has been told it exists — which is the
 * one thing hiding it was meant to prevent. So the question is asked first,
 * and what is concealed never reaches the answer.
 *
 * Listing a collection is the commonest case and the one this was written
 * for, which is why it carries the path being listed. It is not the only one:
 * `DAV:expand-property` asks the same question about the hrefs inside a
 * property value (RFC 3253 §3.8), because an expanded href reaches a resource
 * no collection listing covers. **A listener answers about paths and needs to
 * know nothing else** — which is what makes the second case the same question
 * rather than a second seam saying the same thing twice.
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
     * @param string $path What is being answered about: the collection being
     *                     listed, or the resource whose property holds the
     *                     hrefs
     * @param list<string> $members The paths about to be named, as they
     *                              would appear in the answer
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
