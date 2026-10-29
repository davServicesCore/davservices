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

use DateTimeImmutable;

/**
 * Where a collection's change history is kept (R-SYNC-05, R-BE-01).
 *
 * **The dumbest contract that can hold a journal.** {@see Journal} owns every
 * rule RFC 6578 §3.5 states — which of two things that happened to one member
 * is reported, what a removed collection silences, how far a level reaches —
 * and a store owns none of them. That division is deliberate: the rules are the
 * memo's and have to be the same whatever the history is written in, and a
 * store that implemented any of them would be a second place for them to be
 * got wrong.
 *
 * So an entry is a revision, a member path, what became of it and when, and the
 * only questions a store answers are "append this", "what has happened since",
 * "forget what is older than this" and "how far has forgetting got".
 *
 * ## What an implementation owes (R-BE-03)
 *
 * - **The revision is the store's to hand out, and it only grows.** R-SYNC-02
 *   asks for tokens that are monotonically increasing, and this is the number
 *   they are made of. Two entries never share one.
 * - **{@see since()} may answer too much and must never answer too little.**
 *   The same asymmetry {@see \DavServices\Backend\ICalendarBackend::objectsInRange()}
 *   has, and for the same reason: a store with an index on the path may narrow
 *   by it, one without may hand back everything since the revision, and the
 *   caller applies §3.5's rules either way. A store that guessed and answered
 *   too little would make the server drop a client's changes, and nothing
 *   further up could detect it.
 * - **In revision order**, because §3.5's rule is which thing happened *last*.
 *   A store that answered out of order would report a member that came back as
 *   gone.
 * - **{@see forget()} raises the floor and nothing else.** What is dropped is
 *   dropped; {@see cleanedUpTo()} is how the caller knows which tokens it can
 *   no longer answer from, and §3.2's `DAV:valid-sync-token` is what it says
 *   about them.
 *
 * `tests/unit/Sync/JournalBackendContract.php` is the executable form of that
 * (R-BE-04).
 *
 * **The reference implementations are not for production** (R-BE-05).
 */
interface IJournalBackend
{
    /**
     * Writes down what became of a member, and hands back its revision.
     *
     * @param string $member The member's path inside the tree
     * @param Change $change What became of it
     * @param DateTimeImmutable $at When, which is what {@see forget()} measures
     *
     * @return int The revision this entry got, greater than every revision
     *             handed out before it
     */
    public function append(string $member, Change $change, DateTimeImmutable $at): int;

    /**
     * Every entry after that revision, in revision order.
     *
     * @param int $revision The state the caller already has; entries at or
     *                      below it are history it has seen
     * @param string $under The collection the caller is asking about. **A hint
     *                      and not a filter**: a store may narrow by it and may
     *                      ignore it, because the caller has to apply §3.5's
     *                      rules to whatever comes back
     *
     * @return list<array{int, string, Change}> Revision, member, what became of
     *                                          it
     */
    public function since(int $revision, string $under): array;

    /**
     * Forgets every entry written before that moment.
     *
     * @param DateTimeImmutable $before The oldest moment still worth keeping
     */
    public function forget(DateTimeImmutable $before): void;

    /**
     * The highest revision whose entry has been forgotten, or zero where
     * nothing has.
     *
     * **This is what makes a token too old.** A client holding a revision at or
     * below it is holding a state this store can no longer say what followed,
     * and §3.2's precondition is the honest answer.
     */
    public function cleanedUpTo(): int;

    /**
     * The highest revision handed out so far, or zero where none has been.
     */
    public function latest(): int;
}
