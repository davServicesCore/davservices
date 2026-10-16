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

namespace DavServices\Tests\Unit\Backend;

use DateTimeImmutable;
use DavServices\Backend\ICalendarBackend;
use DavServices\Dav\Calendars\CalendarInfo;
use DavServices\Dav\Calendars\CalendarObjectInfo;
use DavServices\Exception\Forbidden;
use DavServices\Exception\NotFound;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What every calendar store has to do, whatever it keeps its objects in.
 * R-BE-04.
 *
 * Written once and run against each implementation, because a backend that is
 * exchangeable is only exchangeable if they all behave the same. An
 * application that swapped one for another and found its appointments gone
 * would have been better off with no choice at all.
 *
 * ## The two promises R-BE-02 is about
 *
 * > R-BE-02: Die Schnitte MÜSSEN N+1-Abfragen vermeidbar machen: Batch-Methoden
 * > für Multiget und für gemeinsame Metadaten bei `PROPFIND Depth: 1`.
 *
 * - **`objects()` answers for the whole calendar in one call**, with no bodies
 *   in the answer. A `PROPFIND Depth: 1` over a year of events asks for entity
 *   tags and sizes; a store that made that cost a read per event would make
 *   the cheap question as dear as the expensive one.
 * - **`bodies()` answers for many names in one call**, which is what
 *   `CALDAV:calendar-multiget` (§7.9) is. A name that is not there is left out
 *   rather than refused, because one event deleted mid-request must not sink a
 *   report about forty others.
 *
 * ## And the promise R-CAL-16 is about
 *
 * > R-CAL-16: `time-range` MUSS im Backend vorgefiltert werden. Vollständige
 * > In-Memory-Expansion DARF NICHT der Regelfall sein.
 *
 * **`objectsInRange()` may answer too much and must never answer too little.**
 * §9.9 requires every recurrence instance to be considered, which a store
 * cannot do from an index, so the cases below pin the half that can be pinned:
 * what overlaps is in the answer, and nothing from another calendar ever is.
 * A store with no index passes by answering everything — slow rather than
 * wrong, which is the right way round.
 *
 * ## And the ones that are easiest to get wrong
 *
 * **An entity tag changes when the object does, and not otherwise.** §5.3.4
 * makes `DAV:getetag` a MUST on every calendar object resource and says what
 * it is for: a client compares the tag a `PUT` answered with the one a
 * `PROPFIND` reports "and know that if they are equal, then the calendar
 * object resource on the server has not been changed". A tag that stayed the
 * same across a write would tell a client exactly the opposite of the truth.
 *
 * **A `UID` and a kind are answered without reading the object**, or answered
 * as null. Both are allowed; what is not allowed is guessing. §5.3.2.1 needs
 * the UIDs of a whole calendar to answer `CALDAV:no-uid-conflict`, and a store
 * that had to read every body for that would make one `PUT` cost the
 * collection.
 *
 * **A path that merely begins the same way is a different one.** `work2` is
 * not inside `work`, and a store that thinks otherwise hands out somebody
 * else's appointments.
 */
abstract class CalendarBackendContract extends TestCase
{
    /**
     * A store with one calendar, `work`, holding one object, `meeting.ics`.
     */
    abstract protected function backend(): ICalendarBackend;

    /**
     * A second principal with a calendar of the same name.
     *
     * **Abstract rather than optional.** Serving two people who both called a
     * calendar `work` is a promise of the interface, not a feature a store may
     * decline — so a store that cannot be set up to show it cannot claim
     * conformance either.
     */
    abstract protected function secondPrincipal(): string;

    /**
     * The principal whose calendars the store was set up with.
     */
    protected function principal(): string
    {
        return 'bernard';
    }

    public function testListsTheCalendarsOfAPrincipal(): void
    {
        $names = array_map(
            static fn (CalendarInfo $calendar): string => $calendar->name(),
            $this->backend()->calendars($this->principal()),
        );

        // No order is promised — a store may answer in whatever order its
        // index has — so the test asks what is there rather than where.
        self::assertContains('work', $names);
    }

    /**
     * A principal the store knows nothing about has no calendars, and that is
     * not an error. §5.3.1 makes `MKCALENDAR` support only RECOMMENDED because
     * "some calendar stores only support one calendar per user (or principal),
     * and those are typically pre-created for each account" — so an account
     * whose calendars are not there yet is an ordinary state.
     */
    public function testAPrincipalWithNoCalendarsHasNone(): void
    {
        self::assertSame([], $this->backend()->calendars('nobody'));
    }

    public function testFindsOneObjectByName(): void
    {
        $object = $this->backend()->object($this->principal(), 'work', 'meeting.ics');

        self::assertNotNull($object);
        self::assertSame('meeting.ics', $object->name());
        self::assertNotSame('', $object->etag());
        self::assertGreaterThan(0, $object->size());
    }

    public function testKnowsNothingAboutAnObjectThatIsNotThere(): void
    {
        self::assertNull($this->backend()->object($this->principal(), 'work', 'nothing.ics'));
    }

    /**
     * **The `PROPFIND Depth: 1` half of R-BE-02.** One call, every object's
     * metadata, no bodies.
     */
    public function testAnswersTheMetadataOfAWholeCalendarAtOnce(): void
    {
        $objects = $this->backend()->objects($this->principal(), 'work');

        self::assertCount(1, $objects);

        $only = $objects[0] ?? self::fail('The calendar answered no object.');

        self::assertSame('meeting.ics', $only->name());
        self::assertNotSame('', $only->etag());
    }

    /**
     * A calendar with nothing in it is a perfectly good calendar, and a store
     * that threw would make "nothing here yet" indistinguishable from
     * "something is broken".
     */
    public function testACalendarWithNoObjectsIsEmptyRatherThanAnError(): void
    {
        $backend = $this->backend();

        $backend->remove($this->principal(), 'work', 'meeting.ics');

        self::assertSame([], $backend->objects($this->principal(), 'work'));
    }

    /**
     * A calendar nobody made is a `404`, because the server must not report a
     * collection that is not there.
     */
    public function testACalendarThatIsNotThereIsRefused(): void
    {
        $this->expectException(NotFound::class);

        $this->backend()->objects($this->principal(), 'nothing');
    }

    /**
     * **The multiget half of R-BE-02** (§7.9).
     */
    public function testAnswersManyBodiesAtOnce(): void
    {
        $backend = $this->backend();

        $backend->put($this->principal(), 'work', 'review.ics', self::object('review'));

        $bodies = $backend->bodies($this->principal(), 'work', ['meeting.ics', 'review.ics']);

        self::assertSame(['meeting.ics', 'review.ics'], array_keys($bodies));
        self::assertStringContainsString(
            'UID:meeting@example.com',
            $bodies['meeting.ics'] ?? self::fail('The store left the meeting out.'),
        );
        self::assertStringContainsString(
            'UID:review@example.com',
            $bodies['review.ics'] ?? self::fail('The store left the review out.'),
        );
    }

    /**
     * **A name that is not there is left out rather than refused**, because
     * §7.9 has the report answer each href on its own account: one object
     * deleted mid-request is a `propstat` and not a failure of the request.
     */
    public function testANameThatIsNotThereIsLeftOutOfTheBodies(): void
    {
        $bodies = $this->backend()->bodies($this->principal(), 'work', ['meeting.ics', 'gone.ics']);

        self::assertSame(['meeting.ics'], array_keys($bodies));
    }

    /**
     * Asking for none is a question with an answer, not a mistake: a
     * `calendar-multiget` whose hrefs all turned out to be elsewhere is still
     * a request the server answers.
     */
    public function testAskingForNoBodiesAnswersNothing(): void
    {
        self::assertSame([], $this->backend()->bodies($this->principal(), 'work', []));
    }

    /**
     * And the calendar is still checked, because the answer is about a
     * collection: a report on one that is not there is a `404` whether it asks
     * for anything or not.
     */
    public function testACalendarThatIsNotThereIsRefusedEvenForNoNames(): void
    {
        $this->expectException(NotFound::class);

        $this->backend()->bodies($this->principal(), 'nothing', []);
    }

    public function testFindsOneBodyByName(): void
    {
        $body = $this->backend()->body($this->principal(), 'work', 'meeting.ics');

        self::assertNotNull($body);
        self::assertStringContainsString('BEGIN:VCALENDAR', $body);
    }

    public function testKnowsNothingAboutABodyThatIsNotThere(): void
    {
        self::assertNull($this->backend()->body($this->principal(), 'work', 'nothing.ics'));
    }

    /**
     * **What overlaps the range is in the answer** (R-CAL-16). That is the
     * half of `objectsInRange()` a contract can pin: a store may answer more
     * than this, never less.
     *
     * The fixture's object runs from 12:00 to 13:00 on 5.1.2026, and §9.9's
     * own condition for an event with a `DTEND` is
     * `(start < DTEND AND end > DTSTART)`.
     *
     * @param string $from The inclusive start, or an empty string for
     *                     "-infinity"
     * @param string $until The non-inclusive end, or an empty string for
     *                      "+infinity"
     */
    #[DataProvider('rangesThatOverlap')]
    public function testARangeThatOverlapsAnswersTheObject(string $from, string $until): void
    {
        $found = $this->backend()->objectsInRange(
            $this->principal(),
            'work',
            self::moment($from),
            self::moment($until),
        );

        self::assertContains('meeting.ics', array_map(
            static fn (CalendarObjectInfo $object): string => $object->name(),
            $found,
        ));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rangesThatOverlap(): iterable
    {
        yield 'around it' => ['20260101T000000Z', '20260201T000000Z'];

        yield 'starting inside it' => ['20260105T123000Z', '20260201T000000Z'];

        yield 'ending inside it' => ['20260101T000000Z', '20260105T123000Z'];

        yield 'inside it' => ['20260105T121000Z', '20260105T122000Z'];

        // §9.9: "If either the 'start' or 'end' attribute is not specified …
        // assume '-infinity' and '+infinity' as their value, respectively."
        yield 'open at the start' => ['', '20260201T000000Z'];

        yield 'open at the end' => ['20260101T000000Z', ''];

        yield 'open at both ends' => ['', ''];
    }

    /**
     * **And nothing from anywhere else ever is.** A store may answer too much
     * within the calendar it was asked about — §9.9 leaves the final test to
     * the caller — but "too much" never reaches past the collection.
     */
    public function testARangeNeverAnswersSomethingFromAnotherCalendar(): void
    {
        $backend = $this->backend();
        $other = $this->secondPrincipal();

        $backend->put($other, 'work', 'theirs.ics', self::object('theirs'));

        $found = $backend->objectsInRange(
            $this->principal(),
            'work',
            self::moment('20260101T000000Z'),
            self::moment('20260201T000000Z'),
        );

        self::assertNotContains('theirs.ics', array_map(
            static fn (CalendarObjectInfo $object): string => $object->name(),
            $found,
        ));
    }

    /**
     * And whatever a store narrows by, the answer is a listing of that
     * calendar: every name in it is a name the calendar holds.
     */
    public function testEverythingARangeAnswersIsInTheCalendar(): void
    {
        $backend = $this->backend();
        $held = array_map(
            static fn (CalendarObjectInfo $object): string => $object->name(),
            $backend->objects($this->principal(), 'work'),
        );

        foreach ($backend->objectsInRange($this->principal(), 'work', null, null) as $object) {
            self::assertContains($object->name(), $held);
        }
    }

    public function testACalendarThatIsNotThereIsRefusedForARangeToo(): void
    {
        $this->expectException(NotFound::class);

        $this->backend()->objectsInRange($this->principal(), 'nothing', null, null);
    }

    public function testKeepsAnObjectAndHandsItBack(): void
    {
        $backend = $this->backend();

        $backend->put($this->principal(), 'work', 'review.ics', self::object('review'));

        self::assertSame(self::object('review'), $backend->body($this->principal(), 'work', 'review.ics'));
    }

    /**
     * **An entity tag changes when the object does.** §5.3.4 opens with the
     * MUST — "The DAV:getetag property MUST be defined and set to a strong
     * entity tag on all calendar object resources" — and a tag that stayed the
     * same across a write would let a client overwrite something it had never
     * seen.
     */
    public function testTheEntityTagChangesWhenTheObjectDoes(): void
    {
        $backend = $this->backend();
        $before = $backend->object($this->principal(), 'work', 'meeting.ics')?->etag();

        $backend->put($this->principal(), 'work', 'meeting.ics', self::object('meeting', 'Moved'));

        $after = $backend->object($this->principal(), 'work', 'meeting.ics')?->etag();

        self::assertNotNull($before);
        self::assertNotNull($after);
        self::assertNotSame($before, $after);
    }

    /**
     * And it is the same tag whoever asks, because a client that got one from
     * a listing and another from the resource itself could never make an
     * `If-Match` work.
     */
    public function testTheEntityTagIsTheSameWhoeverAsks(): void
    {
        $backend = $this->backend();
        $listed = $backend->objects($this->principal(), 'work');
        $one = $backend->object($this->principal(), 'work', 'meeting.ics');

        $first = $listed[0] ?? self::fail('The calendar answered no object.');

        self::assertNotNull($one);
        self::assertSame($first->etag(), $one->etag());
    }

    /**
     * A `put()` says what the store now has, or says nothing.
     *
     * Both are conformant, and §5.3.4 says when each is: a strong tag "when
     * the stored calendar object resource is equivalent by octet equality to
     * the calendar object resource submitted", and where it is not, "a strong
     * entity tag MUST NOT be returned in the response". What is not conformant
     * either way is answering a tag that is not the one a later read gives.
     */
    public function testWhatAPutAnswersIsWhatALaterReadFinds(): void
    {
        $backend = $this->backend();
        $answered = $backend->put($this->principal(), 'work', 'review.ics', self::object('review'));
        $found = $backend->object($this->principal(), 'work', 'review.ics')?->etag();

        if ($answered !== null) {
            self::assertSame($answered, $found);
        }

        self::assertNotNull($found);
    }

    /**
     * **A `UID` is either known or honestly unknown**, never guessed. The same
     * goes for the kind: §4.1 has an object hold one type of component, so a
     * store that keeps the kind beside the object can answer
     * `CALDAV:comp-filter` from its index, and one that cannot says so.
     */
    public function testTheUidAndTheKindAreEitherKnownOrHonestlyUnknown(): void
    {
        $object = $this->backend()->object($this->principal(), 'work', 'meeting.ics');

        self::assertNotNull($object);

        if ($object->uid() !== null) {
            self::assertSame('meeting@example.com', $object->uid());
        }

        if ($object->kind() !== null) {
            self::assertSame('VEVENT', $object->kind());
        }
    }

    public function testRemovesAnObject(): void
    {
        $backend = $this->backend();

        $backend->remove($this->principal(), 'work', 'meeting.ics');

        self::assertNull($backend->object($this->principal(), 'work', 'meeting.ics'));
    }

    /**
     * An object that is not there is `NotFound` rather than nothing done: a
     * `DELETE` on a resource that has gone is a `404` (RFC 4918 §9.6), and a
     * store that shrugged would have the server answer `204` for an
     * appointment it never had.
     */
    public function testRemovingAnObjectThatIsNotThereIsRefused(): void
    {
        $this->expectException(NotFound::class);

        $this->backend()->remove($this->principal(), 'work', 'nothing.ics');
    }

    /**
     * A member name is a path segment, and a store that let one carry a
     * separator would let a client reach outside the calendar it was writing
     * to.
     *
     * `Forbidden` rather than `Conflict`: `409` is about a parent that is not
     * there, `403` about a name the server refuses.
     */
    public function testAMemberNameMayNotReachOutOfItsCalendar(): void
    {
        $this->expectException(Forbidden::class);

        $this->backend()->put($this->principal(), 'work', '../escaped.ics', self::object('escaped'));
    }

    /**
     * **An empty name is no name**, and neither are the two that mean a
     * directory. A store that took one would write beside its calendar rather
     * than inside it.
     *
     * @param string $name The name the case is about
     */
    #[DataProvider('namesThatAreNotNames')]
    public function testANameThatIsNoNameIsRefused(string $name): void
    {
        $this->expectException(Forbidden::class);

        $this->backend()->put($this->principal(), 'work', $name, self::object('x'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namesThatAreNotNames(): iterable
    {
        yield 'nothing at all' => [''];

        yield 'this directory' => ['.'];

        yield 'the one above' => ['..'];
    }

    /**
     * **A calendar that merely begins the same way is a different one.**
     * `work2` does not lie inside `work`, and a store that thought otherwise
     * would hand out somebody else's appointments.
     */
    public function testACalendarThatBeginsTheSameWayIsADifferentOne(): void
    {
        $this->expectException(NotFound::class);

        $this->backend()->objects($this->principal(), 'work2');
    }

    /**
     * **What a calendar says about itself.** The name is the URL's last
     * segment; the rest is what §5.2 lets a calendar carry, and null means not
     * set rather than empty.
     */
    public function testACalendarSaysWhatItIsCalledAndWhatItIsFor(): void
    {
        $found = null;

        foreach ($this->backend()->calendars($this->principal()) as $calendar) {
            if ($calendar->name() === 'work') {
                $found = $calendar;
            }
        }

        self::assertNotNull($found);

        if ($found->displayName() !== null) {
            self::assertNotSame('', $found->displayName());
        }

        if ($found->description() !== null) {
            self::assertNotSame('', $found->description());
        }

        // §5.2.3: "In the absence of this property, the server MUST accept all
        // component types", which is what null says here. A store that keeps
        // the property answers the kinds a client narrowed it to.
        if ($found->kinds() !== null) {
            self::assertNotSame([], $found->kinds());
        }

        // §5.2.2's own definition: "an iCalendar object with exactly one
        // VTIMEZONE component".
        if ($found->timeZone() !== null) {
            self::assertStringContainsString('VTIMEZONE', $found->timeZone());
        }
    }

    /**
     * An object says when it last changed, or says it does not keep that.
     */
    public function testAnObjectSaysWhenItLastChangedOrSaysItDoesNotKnow(): void
    {
        $object = $this->backend()->object($this->principal(), 'work', 'meeting.ics');

        self::assertNotNull($object);

        $changed = $object->lastModified();

        if ($changed !== null) {
            self::assertGreaterThan(0, $changed->getTimestamp());
        }
    }

    /**
     * **Two principals may both call a calendar `work`.**
     *
     * This is the ordinary case rather than the exotic one, and a store keyed
     * on the name alone would serve one of them the other's appointments —
     * which is why every method here is told whose calendar it is.
     */
    public function testTwoPrincipalsMayBothCallACalendarTheSame(): void
    {
        $backend = $this->backend();
        $other = $this->secondPrincipal();

        $backend->put($other, 'work', 'theirs.ics', self::object('theirs'));

        $mine = array_map(
            static fn (CalendarObjectInfo $object): string => $object->name(),
            $backend->objects($this->principal(), 'work'),
        );
        $theirs = array_map(
            static fn (CalendarObjectInfo $object): string => $object->name(),
            $backend->objects($other, 'work'),
        );

        self::assertSame(['meeting.ics'], $mine);
        self::assertSame(['theirs.ics'], $theirs);
    }

    /**
     * One moment in UTC, or null for an open end.
     */
    protected static function moment(string $written): ?DateTimeImmutable
    {
        if ($written === '') {
            return null;
        }

        return new DateTimeImmutable($written);
    }

    /**
     * One `VEVENT` on 5.1.2026 from 12:00 to 13:00 UTC, with the `UID` the
     * contract above expects.
     */
    protected static function object(string $uid, string $summary = 'Meeting'): string
    {
        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Example Corp.//CalDAV Client//EN',
            'BEGIN:VEVENT',
            'UID:' . $uid . '@example.com',
            'DTSTAMP:20260101T000000Z',
            'DTSTART:20260105T120000Z',
            'DTEND:20260105T130000Z',
            'SUMMARY:' . $summary,
            'END:VEVENT',
            'END:VCALENDAR',
        ]) . "\r\n";
    }
}
