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

use DateInterval;
use DateTimeImmutable;
use DavServices\Dav\Event\AfterBind;
use DavServices\Dav\Event\AfterCopy;
use DavServices\Dav\Event\AfterMove;
use DavServices\Dav\Event\AfterUnbind;
use DavServices\Dav\Event\AfterWriteContent;
use DavServices\Event\EventEmitter;
use DavServices\Event\IEvent;
use DavServices\Exception\Forbidden;
use DavServices\Sync\Change;
use DavServices\Sync\Journal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for the change journal. R-SYNC-03, R-SYNC-05, P7-02.
 *
 * **This is what makes a token worth handing back.** P7-01 answered the full
 * listing and refused every token, which RFC 6578 §3.2 permits for a server
 * that "might be unable to maintain all of the change data for a collection"
 * — and §3.1 then has the client synchronise the whole collection every time.
 * A journal is the record that ends that.
 *
 * ## What §3.5 asks it to remember
 *
 * §3.5.1, a member reported as **changed**:
 *
 * > A member URL MUST be reported as changed if it has been newly mapped as a
 * > member of the target collection since the request sync-token was
 * > generated […] A member URL MUST be reported as changed if its mapped
 * > resource's entity tag value […] has changed since the request sync-token
 * > was generated.
 *
 * > In the case where a mapping between a member URL and the target collection
 * > was removed, then a new mapping with the same URI was created, the member
 * > URL MUST be reported as changed and MUST NOT be reported as removed.
 *
 * §3.5.2, a member reported as **removed**:
 *
 * > A member MUST be reported as removed if its mapping under the target
 * > collection has been removed since the request sync-token was generated,
 * > and it has not been remapped since it was removed.
 *
 * > If a member was added (and its mapped resource possibly modified), then
 * > removed between two synchronization report requests, it MUST be reported
 * > as removed.
 *
 * **Those two pairs of sentences are one rule: the last thing that happened to
 * a member is what is reported.** Removed and then put back is a change; added
 * and then removed is a removal. A journal that kept both and answered both
 * would break §3.2's "A given member URL MUST appear only once in the
 * response" as well.
 *
 * ## And the one case that is not about a member at all
 *
 * > §3.5.2: For a report with the DAV:sync-level XML element value set to
 * > "infinite", where a collection is removed, the server MUST NOT report the
 * > removal of any members of the removed collection. Clients MUST assume that
 * > if a collection is reported as being removed, then all members of that
 * > collection have also been removed.
 *
 * So a removed collection silences everything below it — not only the
 * removals, because a member that changed before its collection went is gone
 * too.
 *
 * ## Why it forgets, and what that costs
 *
 * R-SYNC-05: the journal is kept for a configurable period and cleaned up
 * after. §3.2 says why a server would: it "might only be able to maintain up
 * to 3 weeks worth of changes to a collection, or only up to 10,000 total
 * changes". A token from before what is still kept cannot be answered from,
 * and §3.2's `DAV:valid-sync-token` is what says so — the one precondition
 * this chunk exists to raise honestly (R-SYNC-03).
 */
#[CoversClass(Journal::class)]
#[CoversClass(Change::class)]
final class JournalTest extends TestCase
{
    private const COLLECTION = 'calendars/work';

    private const VALID_SYNC_TOKEN = '{DAV:}valid-sync-token';

    /**
     * §3.5.1: "A member URL MUST be reported as changed if it has been newly
     * mapped as a member of the target collection since the request sync-token
     * was generated."
     */
    public function testANewMemberIsAChange(): void
    {
        $journal = $this->journal();
        $before = $journal->revisionOf(self::COLLECTION);

        $journal->recordAChange(self::COLLECTION . '/meeting.ics');

        self::assertSame(
            [self::COLLECTION . '/meeting.ics' => Change::Changed],
            $journal->changesSince($before, self::COLLECTION, 1)->members(),
        );
    }

    /**
     * §3.5.2: "A member MUST be reported as removed if its mapping under the
     * target collection has been removed since the request sync-token was
     * generated, and it has not been remapped since it was removed."
     */
    public function testAMemberThatWentIsARemoval(): void
    {
        $journal = $this->journal();
        $before = $journal->revisionOf(self::COLLECTION);

        $journal->recordARemoval(self::COLLECTION . '/meeting.ics');

        self::assertSame(
            [self::COLLECTION . '/meeting.ics' => Change::Removed],
            $journal->changesSince($before, self::COLLECTION, 1)->members(),
        );
    }

    /**
     * **The two MUSTs of §3.5 that are one rule: the last thing wins.**
     *
     * Removed and then put back is a change — "MUST be reported as changed and
     * MUST NOT be reported as removed". Added and then removed is a removal —
     * "it MUST be reported as removed", so that a client which created the
     * member hears that it is gone.
     *
     * @param list<string> $kinds What happened to the member, in order
     * @param Change $reported What the answer says about it
     */
    #[DataProvider('whatHappenedAndWhatIsReported')]
    public function testTheLastThingThatHappenedIsWhatIsReported(array $kinds, Change $reported): void
    {
        $journal = $this->journal();
        $before = $journal->revisionOf(self::COLLECTION);
        $member = self::COLLECTION . '/meeting.ics';

        foreach ($kinds as $kind) {
            $kind === 'changed' ? $journal->recordAChange($member) : $journal->recordARemoval($member);
        }

        self::assertSame(
            [$member => $reported],
            $journal->changesSince($before, self::COLLECTION, 1)->members(),
        );
    }

    /**
     * @return iterable<string, array{list<string>, Change}>
     */
    public static function whatHappenedAndWhatIsReported(): iterable
    {
        yield 'removed, then put back' => [['removed', 'changed'], Change::Changed];
        yield 'added, then removed' => [['changed', 'removed'], Change::Removed];
        yield 'changed twice' => [['changed', 'changed'], Change::Changed];
        yield 'written, then removed, then written' => [['changed', 'removed', 'changed'], Change::Changed];
        yield 'added, written, removed' => [['changed', 'changed', 'removed'], Change::Removed];
    }

    /**
     * §3.2: "A given member URL MUST appear only once in the response." Which
     * is the other half of the rule above: a member that moved three times is
     * one entry in the answer, not three.
     */
    public function testAMemberAppearsOnceHoweverOftenItMoved(): void
    {
        $journal = $this->journal();
        $before = $journal->revisionOf(self::COLLECTION);
        $member = self::COLLECTION . '/meeting.ics';

        $journal->recordAChange($member);
        $journal->recordAChange($member);
        $journal->recordARemoval($member);
        $journal->recordAChange($member);

        self::assertCount(1, $journal->changesSince($before, self::COLLECTION, 1)->members());
    }

    /**
     * **§3.5.2: a removed collection silences everything below it.** "For a
     * report with the DAV:sync-level XML element value set to 'infinite', where
     * a collection is removed, the server MUST NOT report the removal of any
     * members of the removed collection."
     *
     * Not only the removals: a member that changed before its collection went
     * is gone as well, and reporting it as changed would send a client to fetch
     * something that is not there.
     */
    public function testARemovedCollectionSilencesEverythingBelowIt(): void
    {
        $journal = $this->journal();
        $before = $journal->revisionOf('calendars');

        $journal->recordAChange('calendars/work/meeting.ics');
        $journal->recordARemoval('calendars/work/review.ics');
        $journal->recordAChange('calendars/home/lunch.ics');
        $journal->recordARemoval('calendars/work');

        self::assertSame(
            [
                'calendars/home/lunch.ics' => Change::Changed,
                'calendars/work' => Change::Removed,
            ],
            $journal->changesSince($before, 'calendars', Journal::EVERY_DEPTH)->members(),
        );
    }

    /**
     * And a collection that was removed and then made again says what §3.5.1
     * says of any member: it is a change, and what is below it is its own
     * business again.
     */
    public function testACollectionMadeAgainStopsSilencingWhatIsBelowIt(): void
    {
        $journal = $this->journal();
        $before = $journal->revisionOf('calendars');

        $journal->recordARemoval('calendars/work');
        $journal->recordAChange('calendars/work');
        $journal->recordAChange('calendars/work/meeting.ics');

        self::assertSame(
            [
                'calendars/work' => Change::Changed,
                'calendars/work/meeting.ics' => Change::Changed,
            ],
            $journal->changesSince($before, 'calendars', Journal::EVERY_DEPTH)->members(),
        );
    }

    /**
     * **§3.3's scope, asked of the journal.** At level `1` only the immediate
     * children of the collection are reported; at `infinite`, every member at
     * any depth.
     *
     * @param int $depth What the level comes to
     * @param list<string> $reported The members the answer names
     */
    #[DataProvider('depthsAndWhatTheyReach')]
    public function testTheScopeDecidesWhichMembersAreReported(int $depth, array $reported): void
    {
        $journal = $this->journal();
        $before = $journal->revisionOf(self::COLLECTION);

        $journal->recordAChange(self::COLLECTION . '/meeting.ics');
        $journal->recordAChange(self::COLLECTION . '/inner');
        $journal->recordAChange(self::COLLECTION . '/inner/deep.ics');
        $journal->recordAChange(self::COLLECTION . '/inner/deeper/bottom.ics');

        self::assertSame(
            $reported,
            array_keys($journal->changesSince($before, self::COLLECTION, $depth)->members()),
        );
    }

    /**
     * @return iterable<string, array{int, list<string>}>
     */
    public static function depthsAndWhatTheyReach(): iterable
    {
        yield 'the immediate children' => [1, [
            'calendars/work/meeting.ics',
            'calendars/work/inner',
        ]];

        yield 'every depth' => [Journal::EVERY_DEPTH, [
            'calendars/work/meeting.ics',
            'calendars/work/inner',
            'calendars/work/inner/deep.ics',
            'calendars/work/inner/deeper/bottom.ics',
        ]];
    }

    /**
     * **Only what lies under the collection asked about.** A report is about
     * the request-URI (§3.2), and another collection's changes are another
     * client's business.
     */
    public function testAnotherCollectionsChangesAreNotInTheAnswer(): void
    {
        $journal = $this->journal();
        $before = $journal->revisionOf(self::COLLECTION);

        $journal->recordAChange('calendars/home/lunch.ics');
        $journal->recordAChange(self::COLLECTION . '/meeting.ics');

        self::assertSame(
            [self::COLLECTION . '/meeting.ics'],
            array_keys($journal->changesSince($before, self::COLLECTION, Journal::EVERY_DEPTH)->members()),
        );
    }

    /**
     * **A path that merely begins the same way is a different path.**
     * `calendars/work2` does not lie inside `calendars/work`, and a journal
     * that thought otherwise would hand one client another's changes.
     */
    public function testAPathThatBeginsTheSameWayIsADifferentOne(): void
    {
        $journal = $this->journal();
        $before = $journal->revisionOf(self::COLLECTION);

        $journal->recordAChange('calendars/work2/meeting.ics');

        self::assertSame([], $journal->changesSince($before, self::COLLECTION, Journal::EVERY_DEPTH)->members());
    }

    /**
     * **The collection itself is not one of its own members.** §3.2 has the
     * response carry "one DAV:response element for each member URL", and the
     * request-URI is not a member of itself — a client that saw it would be
     * told its own collection had changed under it.
     */
    public function testTheCollectionItselfIsNotOneOfItsMembers(): void
    {
        $journal = $this->journal();
        $before = $journal->revisionOf(self::COLLECTION);

        $journal->recordAChange(self::COLLECTION);

        self::assertSame([], $journal->changesSince($before, self::COLLECTION, Journal::EVERY_DEPTH)->members());
    }

    /**
     * **The revision grows with what happens, and per collection.** R-SYNC-02
     * asks for tokens that are monotonically increasing, and §4 has the
     * property carry "the value of the synchronization token as it would be
     * returned by a DAV:sync-collection report on that resource at the same
     * point in time" — so a collection nothing happened in keeps the state it
     * had.
     */
    public function testTheRevisionGrowsWithWhatHappensUnderThatCollection(): void
    {
        $journal = $this->journal();
        $work = $journal->revisionOf(self::COLLECTION);
        $home = $journal->revisionOf('calendars/home');

        $journal->recordAChange(self::COLLECTION . '/meeting.ics');

        self::assertGreaterThan($work, $journal->revisionOf(self::COLLECTION));
        self::assertSame($home, $journal->revisionOf('calendars/home'));
        self::assertGreaterThan($work, $journal->revisionOf('calendars'));
    }

    /**
     * A collection nothing has ever happened in is at the state it started in,
     * which is a state and not an absence: {@see \DavServices\Sync\IRevisions}
     * keeps `null` for a collection this server tracks nothing for, and a
     * journal tracks everything it is asked about.
     */
    public function testACollectionNothingHasHappenedInIsStillTracked(): void
    {
        self::assertSame(0, $this->journal()->revisionOf('calendars/empty'));
    }

    /**
     * **An answer that was not cut short covers the collection's current
     * state, and that is §4 rather than convenience.** The property says "the
     * value of the synchronization token as it would be returned by a
     * DAV:sync-collection report on that resource at the same point in time",
     * so the token of a complete answer is where the collection stands — not
     * where its last *reported* change stands.
     *
     * The two differ, and this case is built on the difference: a change
     * deeper than the level asked for moves the collection on without
     * appearing in the answer. §3.3 settles which of the two the token is —
     * "DAV:sync-token values returned by the server are not specific to the
     * value of the DAV:sync-level XML element".
     */
    public function testAnAnswerThatFitsCoversTheCollectionsCurrentState(): void
    {
        $journal = $this->journal();
        $before = $journal->revisionOf(self::COLLECTION);

        $journal->recordAChange(self::COLLECTION . '/meeting.ics');
        $journal->recordAChange(self::COLLECTION . '/inner/deeper.ics');

        $answer = $journal->changesSince($before, self::COLLECTION, 1);

        self::assertSame([self::COLLECTION . '/meeting.ics'], array_keys($answer->members()));
        self::assertFalse($answer->wasCutShort());
        self::assertSame($journal->revisionOf(self::COLLECTION), $answer->upTo());
    }

    /**
     * **§3.6's own example, at the journal.** "Assume the last DAV:sync-token
     * used by the client was 'http://example.com/sync/10', and since then 15
     * additional changes to different resources have occurred. […] if the
     * server chooses to limit responses to at most 10 changes, then it would
     * return only 10 DAV:response elements and a DAV:sync-token with value
     * 'http://example.com/sync/20'."
     *
     * The numbers are the memo's because the store counts the way the memo's
     * example does — one revision per entry, each greater than the last
     * ({@see \DavServices\Sync\IJournalBackend::append()}). So ten changes
     * reach revision 10, fifteen more reach 25, and a page of ten ends at 20.
     */
    public function testAnAnswerCutShortCoversOnlyTheChangesItCarries(): void
    {
        $journal = $this->journal();

        for ($i = 1; $i <= 10; ++$i) {
            $journal->recordAChange(self::COLLECTION . '/before-' . $i . '.ics');
        }

        $held = $journal->revisionOf(self::COLLECTION);

        for ($i = 1; $i <= 15; ++$i) {
            $journal->recordAChange(self::COLLECTION . '/after-' . $i . '.ics');
        }

        $answer = $journal->changesSince($held, self::COLLECTION, 1, 10);

        self::assertSame(10, $held);
        self::assertCount(10, $answer->members());
        self::assertTrue($answer->wasCutShort());
        self::assertSame(20, $answer->upTo());
    }

    /**
     * **And the rest comes back with the token that was handed over**, which
     * is what the state it covers is for: "That allows the client to use the
     * returned DAV:sync-token to fetch the next set of changes. In this way,
     * the client can effectively 'page' through the entire set of changes in a
     * consistent manner."
     */
    public function testWhatWasCutShortIsWhatTheNextAskingBringsBack(): void
    {
        $journal = $this->journal();
        $held = $journal->revisionOf(self::COLLECTION);

        for ($i = 1; $i <= 15; ++$i) {
            $journal->recordAChange(self::COLLECTION . '/after-' . $i . '.ics');
        }

        $first = $journal->changesSince($held, self::COLLECTION, 1, 10);
        $rest = $journal->changesSince($first->upTo(), self::COLLECTION, 1, 10);

        self::assertCount(5, $rest->members());
        self::assertFalse($rest->wasCutShort());
        self::assertSame([], array_intersect(array_keys($first->members()), array_keys($rest->members())));
    }

    /**
     * **A page is the oldest changes and not any subset**, and that is forced
     * rather than chosen. RFC 5323 §2.3.1 would allow any: "the partial
     * results returned MAY be any subset of the result set that would have
     * satisfied the original query." RFC 6578 §3.6 takes that back, because
     * the token has to describe the page — "the DAV:sync-token value returned
     * in the response MUST represent the correct state for the partial set of
     * changes returned" — and no single revision describes a page with a hole
     * in it.
     *
     * So a member that changed again belongs where it changed **last**, and
     * the page that does not reach it leaves it out whole.
     */
    public function testThePageIsTheOldestChangesAndNotAnySubset(): void
    {
        $journal = $this->journal();
        $held = $journal->revisionOf(self::COLLECTION);

        $journal->recordAChange(self::COLLECTION . '/one.ics');
        $journal->recordAChange(self::COLLECTION . '/two.ics');
        $journal->recordAChange(self::COLLECTION . '/one.ics');

        $first = $journal->changesSince($held, self::COLLECTION, 1, 1);

        self::assertSame([self::COLLECTION . '/two.ics'], array_keys($first->members()));
        self::assertSame(2, $first->upTo());
        self::assertSame(
            [self::COLLECTION . '/one.ics'],
            array_keys($journal->changesSince($first->upTo(), self::COLLECTION, 1, 1)->members()),
        );
    }

    /**
     * **A page of nothing covers the state the client already held.** §5.17 of
     * RFC 5323 has `DAV:nresults` as "a requested maximum number of
     * DAV:response elements" and its value as an unsigned integer, so nothing
     * is a number a client may ask for — and §3.6's rule then answers itself:
     * the state of an empty page is where the client was.
     *
     * It makes no progress, and that is the client's own doing rather than a
     * refusal this server may make: §3.7 fails a request only where the server
     * "is unable to truncate the result at or below the requested number", and
     * nothing is at or below nothing.
     */
    public function testALimitOfNothingCoversTheStateTheClientAlreadyHeld(): void
    {
        $journal = $this->journal();
        $held = $journal->revisionOf(self::COLLECTION);

        $journal->recordAChange(self::COLLECTION . '/one.ics');

        $answer = $journal->changesSince($held, self::COLLECTION, 1, 0);

        self::assertSame([], $answer->members());
        self::assertTrue($answer->wasCutShort());
        self::assertSame($held, $answer->upTo());
    }

    /**
     * A limit beyond the changes cuts nothing, and the answer says so: §3.6's
     * `507` is for a result set that "is truncated", which this one is not.
     */
    public function testALimitBeyondTheChangesCutsNothing(): void
    {
        $journal = $this->journal();
        $held = $journal->revisionOf(self::COLLECTION);

        $journal->recordAChange(self::COLLECTION . '/one.ics');

        $answer = $journal->changesSince($held, self::COLLECTION, 1, 1000);

        self::assertCount(1, $answer->members());
        self::assertFalse($answer->wasCutShort());
    }

    /**
     * **§3.2's other duty, the one a client feels:** "a server needs to keep
     * track of changes to any member URLs and their mapped resources in a
     * collection […] This includes noting the addition of new member URLs, the
     * changes to the mapped resources of existing member URLs, and the removal
     * of member URLs."
     *
     * Four events answer that sentence, and each is named by §3.5:
     * {@see AfterBind} is §3.5.1's "newly mapped as a member of the target
     * collection", {@see AfterWriteContent} its "mapped resource's entity tag
     * value […] has changed", {@see AfterUnbind} §3.5.2's "mapping under the
     * target collection has been removed", and {@see AfterMove} the same
     * section's "members that have been unmapped as the result of a MOVE".
     *
     * **A move is one removal and one change, and this is the removal half.**
     * Where it went is {@see AfterBind}'s to record, which is why a `MOVE`
     * over something already there comes out as a change and not a removal —
     * §3.5.1 asks for exactly that.
     *
     * @param IEvent $happened What the server did
     * @param string $member The member it is about
     * @param Change $recorded What the journal is to have written down
     */
    #[DataProvider('whatTheServerDoesAndWhatIsWrittenDown')]
    public function testItWritesDownWhatTheServerDoes(IEvent $happened, string $member, Change $recorded): void
    {
        $journal = $this->journal();
        $events = new EventEmitter();
        $before = $journal->revisionOf(self::COLLECTION);

        $journal->keepTrack($events);
        $events->emit($happened);

        self::assertSame(
            [$member => $recorded],
            $journal->changesSince($before, self::COLLECTION, 1)->members(),
        );
    }

    /**
     * @return iterable<string, array{IEvent, string, Change}>
     */
    public static function whatTheServerDoesAndWhatIsWrittenDown(): iterable
    {
        $member = self::COLLECTION . '/meeting.ics';

        yield 'a member newly mapped' => [new AfterBind($member), $member, Change::Changed];

        yield 'content written over' => [new AfterWriteContent($member), $member, Change::Changed];

        yield 'a member unmapped' => [new AfterUnbind($member), $member, Change::Removed];

        yield 'where a move left from' => [
            new AfterMove($member, self::COLLECTION . '/moved.ics'),
            $member,
            Change::Removed,
        ];
    }

    /**
     * **It hears nothing it was not told to listen for.** A journal that wrote
     * an entry for every event would move a collection's token on a `PROPFIND`
     * — and §5's conditional writes rest on a token that moves when the
     * collection does and not otherwise.
     */
    public function testItWritesNothingDownForSomethingThatChangedNothing(): void
    {
        $journal = $this->journal();
        $events = new EventEmitter();
        $before = $journal->revisionOf(self::COLLECTION);

        $journal->keepTrack($events);
        $events->emit(new AfterCopy(self::COLLECTION . '/meeting.ics', 'elsewhere/meeting.ics'));

        self::assertSame($before, $journal->revisionOf(self::COLLECTION));
    }

    /**
     * **It tells the time itself where nobody hands it a clock.** The seam is
     * there so that a test can say what time it is and so that one request
     * reads it once (R-ARC-05), not so that a deployment has to supply one —
     * and a journal whose default clock did not work would keep its history
     * under a date nobody could clean up by.
     */
    public function testItTellsTheTimeItselfWhereNobodyHandsItAClock(): void
    {
        $journal = new Journal(new MemoryJournalBackend());
        $before = $journal->revisionOf(self::COLLECTION);

        $journal->recordAChange(self::COLLECTION . '/meeting.ics');
        $journal->clean();

        self::assertSame(
            [self::COLLECTION . '/meeting.ics' => Change::Changed],
            $journal->changesSince($before, self::COLLECTION, 1)->members(),
        );
    }

    /**
     * **R-SYNC-03 and §3.2's precondition.** "The DAV:sync-token element value
     * MUST be a valid token previously returned by the server for the
     * collection targeted by the request-URI", and a token from before what is
     * still kept is not one this journal can answer from.
     */
    public function testATokenFromBeforeWhatIsKeptIsRefusedByName(): void
    {
        $journal = $this->journal();

        $journal->recordAChange(self::COLLECTION . '/meeting.ics');

        $stale = $journal->revisionOf(self::COLLECTION);

        $this->at('2026-11-20 09:00:00');
        $journal->recordAChange(self::COLLECTION . '/review.ics');
        $journal->clean();

        try {
            $journal->changesSince($stale - 1, self::COLLECTION, 1);
        } catch (Forbidden $refusal) {
            self::assertSame(403, $refusal->status());
            self::assertSame(self::VALID_SYNC_TOKEN, $refusal->errorElement());

            return;
        }

        self::fail('A token older than the journal was answered from.');
    }

    /**
     * **And one from after it, which is §3.2's precondition read forwards.**
     * "The DAV:sync-token element value MUST be a valid token previously
     * returned by the server" — a state later than anything this journal has
     * recorded was never returned by it, so it is no more answerable than one
     * from before what is kept.
     *
     * A client cannot hold one honestly: {@see \DavServices\Sync\Token}
     * carries the collection it was made for, and a revision above the latest
     * was handed out for nothing. Answering it would mean taking a client's
     * word for a state this server has never been in.
     */
    public function testATokenThisJournalNeverHandedOutIsRefusedByName(): void
    {
        $journal = $this->journal();

        $journal->recordAChange(self::COLLECTION . '/meeting.ics');

        try {
            $journal->changesSince($journal->revisionOf(self::COLLECTION) + 1, self::COLLECTION, 1);
        } catch (Forbidden $refusal) {
            self::assertSame(403, $refusal->status());
            self::assertSame(self::VALID_SYNC_TOKEN, $refusal->errorElement());

            return;
        }

        self::fail('A state this journal was never in was answered from.');
    }

    /**
     * **R-SYNC-05: kept for a configurable period, then cleaned up.** What is
     * still inside the period stays answerable, so a client that synchronises
     * often enough never falls back to a full listing.
     */
    public function testWhatIsStillInsideThePeriodSurvivesACleanUp(): void
    {
        $journal = $this->journal();
        $before = $journal->revisionOf(self::COLLECTION);

        $journal->recordAChange(self::COLLECTION . '/meeting.ics');

        $this->at('2026-11-01 09:00:00');
        $journal->clean();

        self::assertSame(
            [self::COLLECTION . '/meeting.ics' => Change::Changed],
            $journal->changesSince($before, self::COLLECTION, 1)->members(),
        );
    }

    /**
     * And the period is the caller's to set: §3.2 offers "3 weeks worth of
     * changes" as an example and nothing more, so a deployment that keeps a
     * day and one that keeps a year are both conformant.
     */
    public function testThePeriodIsTheCallersToSet(): void
    {
        $journal = $this->journal(keepFor: 'P1D');
        $before = $journal->revisionOf(self::COLLECTION);

        $journal->recordAChange(self::COLLECTION . '/meeting.ics');

        $this->at('2026-10-29 09:00:00');
        $journal->recordAChange(self::COLLECTION . '/review.ics');
        $journal->clean();

        $this->expectException(Forbidden::class);

        $journal->changesSince($before, self::COLLECTION, 1);
    }

    /**
     * **Cleaning up twice is cleaning up once.** The cleanest signal that a
     * cleanup is a statement about time rather than a counter: run it again at
     * the same moment and nothing more goes.
     */
    public function testCleaningUpTwiceTakesNothingMore(): void
    {
        $journal = $this->journal();
        $before = $journal->revisionOf(self::COLLECTION);

        $journal->recordAChange(self::COLLECTION . '/meeting.ics');
        $journal->clean();
        $journal->clean();

        self::assertCount(1, $journal->changesSince($before, self::COLLECTION, 1)->members());
    }

    /**
     * **The newest state is always answerable**, however far the cleanup has
     * gone: a client that hands back the token it has just been given is owed
     * an empty answer and not a refusal.
     */
    public function testTheStateItJustHandedOutIsAnswerable(): void
    {
        $journal = $this->journal();

        $journal->recordAChange(self::COLLECTION . '/meeting.ics');

        $this->at('2026-11-20 09:00:00');
        $journal->clean();

        self::assertSame(
            [],
            $journal->changesSince($journal->revisionOf(self::COLLECTION), self::COLLECTION, 1)->members(),
        );
    }

    /**
     * What the clock says, so that a period can be measured without waiting.
     */
    private string $moment = '2026-10-27 09:00:00';

    private function at(string $moment): void
    {
        $this->moment = $moment;
    }

    /**
     * @param string $keepFor How long the record is kept, as an ISO 8601
     *                        duration
     */
    private function journal(string $keepFor = 'P3W'): Journal
    {
        return new Journal(
            new MemoryJournalBackend(),
            new DateInterval($keepFor),
            fn (): DateTimeImmutable => new DateTimeImmutable($this->moment),
        );
    }
}
