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

namespace DavServices\Tests\Unit\Sync;

use DateTimeImmutable;
use DavServices\Sync\Change;
use DavServices\Sync\IJournalBackend;
use PHPUnit\Framework\TestCase;

/**
 * What every change history has to do, whatever it keeps the entries in.
 * R-SYNC-05, R-BE-03, R-BE-04.
 *
 * Written once and run against each implementation, because a store that is
 * exchangeable is only exchangeable if they all behave the same.
 *
 * ## The four promises, and why each is worth a case
 *
 * - **The revision is the store's to hand out, and it only grows.** R-SYNC-02
 *   asks for synchronisation tokens that are monotonically increasing, and
 *   this number is what they are made of. A store that reused one would give
 *   two states one name, and a client holding that name could not be told
 *   which of them it had.
 * - **{@see IJournalBackend::since()} answers everything after that revision,
 *   in revision order.** The order is not decoration: RFC 6578 §3.5's rule is
 *   which of several things happened to a member *last*, and a store that
 *   answered out of order would have
 *   {@see \DavServices\Sync\Journal} report a member that came back as gone.
 * - **What is already seen is not answered again.** The revision is the state
 *   the caller holds, so an entry at or below it is history it has: a store
 *   that included it would make the same change arrive twice.
 * - **{@see IJournalBackend::forget()} raises the floor and nothing else.**
 *   What is dropped is dropped, and {@see IJournalBackend::cleanedUpTo()} is
 *   how the caller knows which tokens it can no longer answer from — §3.2's
 *   `DAV:valid-sync-token` being what it says about them.
 *
 * ## What is deliberately not asked
 *
 * **Nothing about the numbers themselves.** A store that counts 1, 2, 3 and
 * one that hands out the row ids of a table with gaps in it are both
 * conformant, so every case here asks about order and membership rather than
 * about values.
 *
 * **And nothing about narrowing by path.** The `$under` argument is a hint:
 * "a store may narrow by it and may ignore it, because the caller has to apply
 * §3.5's rules to whatever comes back". So a case asks that what lies under
 * the collection *is* answered, never that anything else is absent — a store
 * answering too much is slow, and one answering too little drops a client's
 * changes with nothing further up able to detect it.
 *
 * **The reference implementations are not for production** (R-BE-05).
 */
abstract class JournalBackendContract extends TestCase
{
    protected const COLLECTION = 'calendars/work';

    protected const MOMENT = '2026-10-27 09:00:00';

    /**
     * A history with nothing in it yet.
     */
    abstract protected function backend(): IJournalBackend;

    /**
     * **An empty history says so in all three answers**, rather than in two of
     * them and a guess in the third. A client synchronising a server nothing
     * has happened on is the ordinary first case, not an exotic one.
     */
    public function testNothingHasHappenedYet(): void
    {
        $entries = $this->backend();

        self::assertSame(0, $entries->latest());
        self::assertSame(0, $entries->cleanedUpTo());
        self::assertSame([], $entries->since(0, self::COLLECTION));
    }

    /**
     * **Two entries never share a revision.** R-SYNC-02's tokens are made of
     * this number, so one handed out twice would name two states.
     */
    public function testEachEntryGetsARevisionOfItsOwn(): void
    {
        $entries = $this->backend();

        $revisions = [
            $this->record($entries, 'meeting.ics'),
            $this->record($entries, 'review.ics'),
            $this->record($entries, 'meeting.ics'),
        ];

        self::assertSame($revisions, array_values(array_unique($revisions)));
    }

    /**
     * And it only grows, which is the half that makes a token comparable:
     * {@see \DavServices\Sync\Token::isAfter()} asks which of two states came
     * later, and a number that could fall would answer that wrongly.
     */
    public function testTheRevisionOnlyGrows(): void
    {
        $entries = $this->backend();
        $first = $this->record($entries, 'meeting.ics');
        $second = $this->record($entries, 'review.ics');

        self::assertGreaterThan($first, $second);
    }

    /**
     * **The latest is the last one handed out**, which is what makes a token
     * this store never issued recognisable as one (§3.2's precondition).
     */
    public function testTheLatestIsTheLastRevisionHandedOut(): void
    {
        $entries = $this->backend();

        $this->record($entries, 'meeting.ics');
        $last = $this->record($entries, 'review.ics');

        self::assertSame($last, $entries->latest());
    }

    /**
     * **Everything after that revision**, which is the whole of what the
     * caller asked.
     */
    public function testAnswersWhatHappenedAfterThatRevision(): void
    {
        $entries = $this->backend();
        $held = $this->record($entries, 'meeting.ics');

        $this->record($entries, 'review.ics');
        $this->record($entries, 'standup.ics');

        self::assertSame(
            [self::COLLECTION . '/review.ics', self::COLLECTION . '/standup.ics'],
            $this->membersIn($entries->since($held, self::COLLECTION)),
        );
    }

    /**
     * And nothing at or below it: the revision is the state the caller holds,
     * so an entry it covers is history the caller has already been told about.
     * A store that answered it again would report one change twice.
     */
    public function testWhatTheCallerHasAlreadySeenIsNotAnsweredAgain(): void
    {
        $entries = $this->backend();

        $this->record($entries, 'meeting.ics');

        $held = $this->record($entries, 'review.ics');

        foreach ($entries->since($held, self::COLLECTION) as [$revision]) {
            self::assertGreaterThan($held, $revision);
        }

        self::assertSame([], $entries->since($entries->latest(), self::COLLECTION));
    }

    /**
     * **In revision order**, because §3.5's rule is which thing happened
     * *last*. A store that answered the removal of a member before the change
     * that put it back would have the caller report it as gone.
     */
    public function testAnswersInRevisionOrder(): void
    {
        $entries = $this->backend();

        $entries->append(self::COLLECTION . '/meeting.ics', Change::Removed, $this->at(self::MOMENT));
        $entries->append(self::COLLECTION . '/meeting.ics', Change::Changed, $this->at(self::MOMENT));

        $answered = $entries->since(0, self::COLLECTION);
        $revisions = array_column($answered, 0);
        $sorted = $revisions;

        sort($sorted);

        self::assertSame($sorted, $revisions);
    }

    /**
     * **What became of the member comes back with it.** The store keeps the
     * two states of §3.5 apart and judges neither: which one is reported is
     * {@see \DavServices\Sync\Journal}'s to decide.
     */
    public function testWhatBecameOfAMemberIsAnsweredWithIt(): void
    {
        $entries = $this->backend();

        $entries->append(self::COLLECTION . '/meeting.ics', Change::Removed, $this->at(self::MOMENT));

        self::assertSame([Change::Removed], array_column($entries->since(0, self::COLLECTION), 2));
    }

    /**
     * **A member that moved twice has two entries.** §3.5's "last thing wins"
     * is a rule about the answer, and a store that applied it would be a
     * second place for it to be got wrong — and would have thrown away the
     * order the caller needs to apply it.
     */
    public function testAMemberThatMovedTwiceIsRememberedTwice(): void
    {
        $entries = $this->backend();

        $entries->append(self::COLLECTION . '/meeting.ics', Change::Changed, $this->at(self::MOMENT));
        $entries->append(self::COLLECTION . '/meeting.ics', Change::Removed, $this->at(self::MOMENT));

        self::assertCount(2, $entries->since(0, self::COLLECTION));
    }

    /**
     * **Forgetting raises the floor and takes the entry with it**, which is
     * R-SYNC-05's cleanup and §3.2's reason for it: a server "might only be
     * able to maintain up to 3 weeks worth of changes to a collection".
     */
    public function testForgettingWhatIsOldRaisesTheFloor(): void
    {
        $entries = $this->backend();
        $old = $this->record($entries, 'meeting.ics');

        $this->record($entries, 'review.ics', '2026-11-20 09:00:00');
        $entries->forget($this->at('2026-11-01 09:00:00'));

        self::assertSame($old, $entries->cleanedUpTo());
        self::assertSame(
            [self::COLLECTION . '/review.ics'],
            $this->membersIn($entries->since(0, self::COLLECTION)),
        );
    }

    /**
     * **The moment given is kept.** `forget()` forgets what was written
     * *before* it, so an entry written at that very instant is the oldest
     * thing still answerable — a store that dropped it would make a period of
     * three weeks mean three weeks less one entry, and which entry would
     * depend on the clock.
     */
    public function testAnEntryWrittenAtThatMomentIsKept(): void
    {
        $entries = $this->backend();

        $this->record($entries, 'meeting.ics');
        $entries->forget($this->at(self::MOMENT));

        self::assertSame(0, $entries->cleanedUpTo());
        self::assertCount(1, $entries->since(0, self::COLLECTION));
    }

    /**
     * A cleanup that finds nothing old enough leaves the floor where it was,
     * rather than moving it to whatever happens to be newest.
     */
    public function testForgettingNothingLeavesTheFloorWhereItWas(): void
    {
        $entries = $this->backend();

        $this->record($entries, 'meeting.ics');
        $entries->forget($this->at('2026-01-01 00:00:00'));

        self::assertSame(0, $entries->cleanedUpTo());
    }

    /**
     * **The floor never falls.** A cleanup run with an earlier moment than the
     * last one cannot un-forget what is gone, and a floor that fell would have
     * the caller accept a token it can no longer answer from.
     */
    public function testTheFloorNeverFalls(): void
    {
        $entries = $this->backend();
        $old = $this->record($entries, 'meeting.ics');

        $this->record($entries, 'review.ics', '2026-11-20 09:00:00');
        $entries->forget($this->at('2026-11-01 09:00:00'));
        $entries->forget($this->at('2026-01-01 00:00:00'));

        self::assertSame($old, $entries->cleanedUpTo());
    }

    /**
     * And running it twice at one moment takes nothing more: a cleanup is a
     * statement about time rather than a counter.
     */
    public function testForgettingTwiceTakesNothingMore(): void
    {
        $entries = $this->backend();

        $this->record($entries, 'meeting.ics');
        $this->record($entries, 'review.ics', '2026-11-20 09:00:00');
        $entries->forget($this->at('2026-11-01 09:00:00'));
        $entries->forget($this->at('2026-11-01 09:00:00'));

        self::assertCount(1, $entries->since(0, self::COLLECTION));
    }

    /**
     * **A history forgotten whole has its floor at the top**, and not above
     * it: the floor is a revision this store handed out, so a caller holding
     * the newest token is still holding a state it can answer — an empty
     * answer rather than §3.2's refusal.
     */
    public function testForgettingEverythingLeavesTheFloorAtTheLatest(): void
    {
        $entries = $this->backend();

        $this->record($entries, 'meeting.ics');
        $this->record($entries, 'review.ics');
        $entries->forget($this->at('2026-11-20 09:00:00'));

        self::assertSame($entries->latest(), $entries->cleanedUpTo());
        self::assertSame([], $entries->since(0, self::COLLECTION));
    }

    /**
     * Writes down a change to that member, and hands back its revision.
     *
     * @param string $name The member's name inside {@see self::COLLECTION}
     * @param string|null $moment When it happened, or null for
     *                            {@see self::MOMENT}
     */
    protected function record(IJournalBackend $entries, string $name, ?string $moment = null): int
    {
        return $entries->append(
            self::COLLECTION . '/' . $name,
            Change::Changed,
            $this->at($moment ?? self::MOMENT),
        );
    }

    protected function at(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment);
    }

    /**
     * The members in an answer, in the order it gave them.
     *
     * **Only those under the collection asked about**, because `$under` is a
     * hint: a store that narrows by nothing is conformant, so a case that
     * counted everything would be asking for a behaviour the interface does
     * not promise.
     *
     * @param list<array{int, string, Change}> $answered
     *
     * @return list<string>
     */
    protected function membersIn(array $answered): array
    {
        $members = [];

        foreach ($answered as [, $member]) {
            if (str_starts_with($member, self::COLLECTION . '/')) {
                $members[] = $member;
            }
        }

        return $members;
    }
}
