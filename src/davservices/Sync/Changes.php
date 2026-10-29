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
 * What became of a collection's members, and which state that answer covers.
 *
 * **The state is in here rather than asked for separately**, and RFC 6578 §4
 * is why: the token a report hands back has to be "the value of the
 * synchronization token as it would be returned by a DAV:sync-collection
 * report on that resource at the same point in time". Two calls — these
 * changes, and then which state they stand for — are two chances to answer
 * about two different moments, and a client handed a token that runs ahead of
 * the changes it came with **never hears about the difference again**.
 *
 * So one call answers both, and the answer carries them together.
 *
 * ## What `upTo()` is, and what it is not
 *
 * It is the state **this answer** covers, which §3.6 asks for in as many
 * words: "the DAV:sync-token value returned in the response MUST represent the
 * correct state for the partial set of changes returned."
 *
 * - For an answer that was **not** cut short, that is where the collection
 *   stands now, which can be further on than the newest change in the answer
 *   — a change deeper than the level asked for moves the collection without
 *   being reported (§3.3: "DAV:sync-token values returned by the server are
 *   not specific to the value of the DAV:sync-level XML element").
 * - For one that **was**, it is where the page ends, so that asking again with
 *   it brings back exactly what was left out.
 *
 * A page that carries nothing covers the state the caller already held: the
 * state of no changes is where it was.
 */
final class Changes
{
    /**
     * @param array<string, Change> $members What became of each member, by
     *                                       path, in the order the changes
     *                                       happened — oldest first, so that
     *                                       a page is a stretch of history
     *                                       and not a handful out of it
     * @param int $upTo The state this answer covers
     * @param bool $wasCutShort Whether anything was left out for want of room
     */
    public function __construct(
        private readonly array $members,
        private readonly int $upTo,
        private readonly bool $wasCutShort,
    ) {
    }

    /**
     * What became of each member, by path, oldest change first.
     *
     * @return array<string, Change>
     */
    public function members(): array
    {
        return $this->members;
    }

    /**
     * The state this answer covers, which is what a token for it names.
     */
    public function upTo(): int
    {
        return $this->upTo;
    }

    /**
     * Whether there is more than this answer carries (RFC 6578 §3.6).
     *
     * **Not derivable from the numbers**, which is why it is said outright: an
     * answer at a level of `1` leaves out everything deeper, so a state beyond
     * {@see upTo()} is the ordinary case rather than a sign that anything was
     * cut.
     */
    public function wasCutShort(): bool
    {
        return $this->wasCutShort;
    }
}
