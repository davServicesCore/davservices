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

namespace DavServices\Tests\Unit\CalDav;

use DateTimeImmutable;
use DateTimeZone;
use DavServices\CalDav\CalendarObject;
use DavServices\CalDav\Moments;
use DavServices\CalDav\TimeRange;
use DavServices\Exception\BadRequest;
use DavServices\VObject\Component;
use DavServices\Xml\Element;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * RFC 4791 §9.9, table by table (R-CAL-15).
 *
 * The memo gives a table per kind of component, and the cases below are those
 * tables: one row, one case, with the row's own condition in the DocBlock. A
 * paraphrase would be a second implementation of the thing being tested.
 *
 * **The range is 5.1.2026, noon to one.** Every case says where the component
 * sits relative to that, so a reader can check a row without arithmetic.
 */
#[CoversClass(TimeRange::class)]
final class TimeRangeTest extends TestCase
{
    private const START = '20260105T120000Z';

    private const END = '20260105T130000Z';

    /**
     * **§9.9: "The 'start' attribute specifies the inclusive start of the time
     * range, and the 'end' attribute specifies the non-inclusive end of the
     * time range."**
     */
    public function testItKeepsWhatItWasGiven(): void
    {
        $range = $this->range(self::START, self::END);

        self::assertSame('2026-01-05T12:00:00+00:00', $range->from()?->format('c'));
        self::assertSame('2026-01-05T13:00:00+00:00', $range->until()?->format('c'));
    }

    /**
     * **"Time ranges open at one end can be specified by including only one
     * attribute."** And §9.9 says what the missing one means: "assume
     * '-infinity' and '+infinity' as their value, respectively".
     */
    public function testAnEndThatIsMissingIsOpen(): void
    {
        self::assertNull($this->range(self::START, null)->until());
        self::assertNull($this->range(null, self::END)->from());
    }

    /**
     * **"At least one attribute MUST always be present in the
     * CALDAV:time-range element."**
     */
    public function testARangeWithNeitherEndIsRefused(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('at least one');

        $this->range(null, null);
    }

    /**
     * **"Both attributes MUST be specified as 'date with UTC time' value."**
     * So a floating time, a date, and a local time in a named zone are each
     * refused — the attribute has one form, and a server that guessed at the
     * others would answer a question the client did not ask.
     *
     * @param string $written What the attribute says
     */
    #[DataProvider('valuesThatAreNoUtcTime')]
    public function testAnEndThatIsNoUtcTimeIsRefused(string $written): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('date with UTC time');

        $this->range($written, null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function valuesThatAreNoUtcTime(): iterable
    {
        yield 'a floating time' => ['20260105T120000'];

        yield 'a date' => ['20260105'];

        yield 'nothing at all' => [''];

        yield 'not a time' => ['the fifth of January'];
    }

    /**
     * **"If both 'start' and 'end' are present, the value of the 'end'
     * attribute MUST be greater than the value of the 'start' attribute."**
     *
     * @param string $until What the end says
     */
    #[DataProvider('endsThatAreNotGreater')]
    public function testAnEndThatIsNotGreaterThanTheStartIsRefused(string $until): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('greater');

        $this->range(self::START, $until);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function endsThatAreNotGreater(): iterable
    {
        yield 'the same moment' => [self::START];

        yield 'earlier' => ['20260105T110000Z'];
    }

    /**
     * **§9.9's last table**, for the seven properties it names: "The calendar
     * properties COMPLETED, CREATED, DTEND, DTSTAMP, DTSTART, DUE, and
     * LAST-MODIFIED overlap a given time range if the following condition
     * holds: (start <= date-time) AND (end > date-time)".
     *
     * So the start is inclusive and the end is not, which is the one place the
     * two ends of a range differ and the easiest thing to get wrong.
     *
     * @param string $moment What the property says
     * @param bool $held Whether the range holds it
     */
    #[DataProvider('momentsAgainstTheRange')]
    public function testAPropertyIsHeldOnTheMomentItNames(string $moment, bool $held): void
    {
        $range = $this->range(self::START, self::END);

        self::assertSame($held, $range->holds(new DateTimeImmutable($moment)));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function momentsAgainstTheRange(): iterable
    {
        yield 'before it' => ['2026-01-05T11:59:59Z', false];

        yield 'exactly at the start' => ['2026-01-05T12:00:00Z', true];

        yield 'inside it' => ['2026-01-05T12:30:00Z', true];

        yield 'exactly at the end' => ['2026-01-05T13:00:00Z', false];

        yield 'after it' => ['2026-01-05T13:00:01Z', false];
    }

    /**
     * And nothing is held where there is no moment: every row of every table
     * asks whether a property is there at all.
     */
    public function testNoMomentIsHeldByNoRange(): void
    {
        self::assertFalse($this->range(self::START, self::END)->holds(null));
    }

    /**
     * **§9.9's `VEVENT` table, row by row.**
     *
     * | DTEND | DURATION | > 0 | DTSTART is DATE-TIME | Condition |
     * |---|---|---|---|---|
     * | Y | N | N | * | `(start <  DTEND AND end > DTSTART)` |
     * | N | Y | Y | * | `(start <  DTSTART+DURATION AND end > DTSTART)` |
     * | N | Y | N | * | `(start <= DTSTART AND end > DTSTART)` |
     * | N | N | N | Y | `(start <= DTSTART AND end > DTSTART)` |
     * | N | N | N | N | `(start <  DTSTART+P1D AND end > DTSTART)` |
     *
     * @param string $lines The event's own properties
     * @param bool $overlaps Whether it overlaps noon to one on 5.1.2026
     */
    #[DataProvider('events')]
    public function testAnEventOverlapsAsItsRowSays(string $lines, bool $overlaps): void
    {
        self::assertSame($overlaps, $this->overlaps('VEVENT', $lines));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function events(): iterable
    {
        // Row 1: a DTEND. `start < DTEND` is strict, so an event that ends
        // exactly when the range begins does not overlap.
        yield 'ending inside the range' => ["DTSTART:20260105T113000Z\r\nDTEND:20260105T123000Z\r\n", true];

        yield 'ending exactly at the start' => ["DTSTART:20260105T110000Z\r\nDTEND:20260105T120000Z\r\n", false];

        yield 'starting exactly at the end' => ["DTSTART:20260105T130000Z\r\nDTEND:20260105T140000Z\r\n", false];

        yield 'around the range' => ["DTSTART:20260105T110000Z\r\nDTEND:20260105T140000Z\r\n", true];

        // Row 2: a DURATION greater than nothing behaves like a DTEND.
        yield 'a duration reaching in' => ["DTSTART:20260105T113000Z\r\nDURATION:PT1H\r\n", true];

        yield 'a duration stopping short' => ["DTSTART:20260105T100000Z\r\nDURATION:PT1H\r\n", false];

        // Row 3: a duration of nothing is a moment, and `start <= DTSTART`
        // makes the start inclusive where row 1 made it exclusive.
        yield 'a duration of nothing at the start' => ["DTSTART:20260105T120000Z\r\nDURATION:PT0S\r\n", true];

        yield 'a duration of nothing before it' => ["DTSTART:20260105T110000Z\r\nDURATION:PT0S\r\n", false];

        // Row 4: neither, and a DATE-TIME: "0 seconds".
        yield 'neither, at the start' => ["DTSTART:20260105T120000Z\r\n", true];

        yield 'neither, before it' => ["DTSTART:20260105T110000Z\r\n", false];

        yield 'neither, at the end' => ["DTSTART:20260105T130000Z\r\n", false];

        // Row 5: neither, and a DATE: "1 day (+P1D)". The whole day of the
        // fifth covers noon, and the fourth covers it too because a day on
        // from midnight on the fourth is midnight on the fifth — which is
        // before the range, so `start < DTSTART+P1D` fails.
        yield 'all day on the day itself' => ["DTSTART;VALUE=DATE:20260105\r\n", true];

        yield 'all day on the day before' => ["DTSTART;VALUE=DATE:20260104\r\n", false];

        yield 'all day on the day after' => ["DTSTART;VALUE=DATE:20260106\r\n", false];

        // "as specified in [RFC2445], the DTSTART property is REQUIRED in the
        // VEVENT component", so every row of the table reads one — and an
        // event without one is invalid iCalendar the table says nothing
        // about.
        yield 'no start at all' => ["SUMMARY:An event with no date\r\n", false];

        yield 'a start it cannot read' => ["DTSTART:not-a-date\r\n", false];

        // A `DURATION` this library cannot read is no duration, so the row
        // for an event with neither applies.
        yield 'a duration it cannot read, at the start' => [
            "DTSTART:20260105T120000Z\r\nDURATION:not-a-duration\r\n",
            true,
        ];
    }

    /**
     * **§9.9's `VTODO` table, row by row.** Eight rows, and the conditions
     * depend on "the presence of the DTSTART, DURATION, DUE, COMPLETED, and
     * CREATED properties".
     *
     * @param string $lines The to-do's own properties
     * @param bool $overlaps Whether it overlaps noon to one on 5.1.2026
     */
    #[DataProvider('toDos')]
    public function testAToDoOverlapsAsItsRowSays(string $lines, bool $overlaps): void
    {
        self::assertSame($overlaps, $this->overlaps('VTODO', $lines));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function toDos(): iterable
    {
        // Y Y N: (start <= DTSTART+DURATION) AND ((end > DTSTART) OR (end >= DTSTART+DURATION))
        yield 'a start and a duration reaching in' => ["DTSTART:20260105T113000Z\r\nDURATION:PT1H\r\n", true];

        yield 'a start and a duration stopping short' => ["DTSTART:20260105T090000Z\r\nDURATION:PT1H\r\n", false];

        // Y N Y: ((start < DUE) OR (start <= DTSTART)) AND ((end > DTSTART) OR (end >= DUE))
        yield 'a start and a due date around it' => ["DTSTART:20260105T110000Z\r\nDUE:20260105T140000Z\r\n", true];

        yield 'a start and a due date before it' => ["DTSTART:20260105T090000Z\r\nDUE:20260105T100000Z\r\n", false];

        // Y N N: (start <= DTSTART) AND (end > DTSTART)
        yield 'a start alone, inside' => ["DTSTART:20260105T123000Z\r\n", true];

        yield 'a start alone, outside' => ["DTSTART:20260105T110000Z\r\n", false];

        // N N Y: (start < DUE) AND (end >= DUE)
        yield 'a due date inside' => ["DUE:20260105T123000Z\r\n", true];

        yield 'a due date exactly at the start' => ["DUE:20260105T120000Z\r\n", false];

        yield 'a due date exactly at the end' => ["DUE:20260105T130000Z\r\n", true];

        // N N N Y Y: ((start <= CREATED) OR (start <= COMPLETED)) AND
        //            ((end >= CREATED) OR (end >= COMPLETED))
        yield 'created and completed inside' => [
            "CREATED:20260105T121000Z\r\nCOMPLETED:20260105T122000Z\r\n",
            true,
        ];

        yield 'created and completed long before' => [
            "CREATED:20250101T000000Z\r\nCOMPLETED:20250102T000000Z\r\n",
            false,
        ];

        // N N N Y N: (start <= COMPLETED) AND (end >= COMPLETED)
        yield 'completed inside' => ["COMPLETED:20260105T123000Z\r\n", true];

        yield 'completed after' => ["COMPLETED:20260105T140000Z\r\n", false];

        // N N N N Y: (end > CREATED)
        yield 'created before the range ends' => ["CREATED:20260105T100000Z\r\n", true];

        yield 'created after the range ends' => ["CREATED:20260105T140000Z\r\n", false];

        // N N N N N: TRUE
        yield 'none of them at all' => ["SUMMARY:Something to do\r\n", true];

        // Row 1's second arm: `end >= DTSTART+DURATION` carries the row where
        // `end > DTSTART` cannot, which is a to-do that began before the range
        // and reaches exactly to its end.
        yield 'a duration reaching exactly to the end' => [
            "DTSTART:20260105T110000Z\r\nDURATION:PT2H\r\n",
            true,
        ];

        // Row 2's first arm the other way round: `start < DUE` is false for a
        // to-do due before the range, and `start <= DTSTART` is false too, so
        // the row fails on its first half however the second reads.
        yield 'a start and a due date both before it' => [
            "DTSTART:20260105T080000Z\r\nDUE:20260105T090000Z\r\n",
            false,
        ];

        // Row 2's second arm: `end >= DUE` carries it where `end > DTSTART`
        // cannot.
        yield 'a start before it and a due date at its end' => [
            "DTSTART:20260105T100000Z\r\nDUE:20260105T130000Z\r\n",
            true,
        ];

        // Row 1's first arm alone: the range ends inside the to-do, so
        // `end > DTSTART` carries the row and `end >= DTSTART+DURATION`
        // cannot.
        yield 'a duration reaching past the end' => [
            "DTSTART:20260105T123000Z\r\nDURATION:PT2H\r\n",
            true,
        ];

        // Row 5's first arm alone: created inside, completed after. The range
        // ends between them, so `end >= CREATED` carries it.
        yield 'created inside but completed after' => [
            "CREATED:20260105T121000Z\r\nCOMPLETED:20260105T140000Z\r\n",
            true,
        ];

        // Row 5's second arms: created long before, completed inside.
        yield 'created long before but completed inside' => [
            "CREATED:20250101T000000Z\r\nCOMPLETED:20260105T123000Z\r\n",
            true,
        ];

        // **The `VTODO` table reaches past the end where the `VEVENT` table
        // does not**, and this is the row that shows it. A to-do that takes no
        // time, beginning exactly at the non-inclusive end of the range:
        // `(start <= DTSTART+DURATION)` is `12:00 <= 13:00`, and of
        // `((end > DTSTART) OR (end >= DTSTART+DURATION))` the first half is
        // false while the second — `13:00 >= 13:00` — is true. So the memo
        // says it overlaps, where §9.9's `VEVENT` row for a duration of
        // nothing says `(start <= DTSTART) AND (end > DTSTART)` and refuses
        // the same moment.
        //
        // The difference is the memo's, not this library's, which is why it is
        // written down rather than smoothed over.
        yield 'a to-do of no length beginning at the end of the range' => [
            "DTSTART:20260105T130000Z\r\nDURATION:PT0S\r\n",
            true,
        ];
    }

    /**
     * **A store may hold what the memos forbid, and the filter still answers.**
     *
     * §9.9 notes of a to-do that "the DUE value MUST be a DATE-TIME value
     * equal to or after the DTSTART value if specified", and RFC 5545 §3.8.7.1
     * has `CREATED` mark when an object came into being — so a `DUE` before
     * its `DTSTART`, and a `COMPLETED` before its `CREATED`, are objects
     * nobody should write. They can be in a calendar all the same, written
     * before anything validated them, and a filter that fell over on one would
     * answer nothing about the rest.
     *
     * Each of these also reaches a half of §9.9's own `OR`s that a valid
     * object cannot: where `DUE` is at or after `DTSTART`, the second arm of
     * `((start < DUE) OR (start <= DTSTART))` can never decide anything.
     *
     * @param string $lines The to-do's own properties
     * @param bool $overlaps Whether it overlaps noon to one on 5.1.2026
     */
    #[DataProvider('toDosThatBreakTheirOwnRules')]
    public function testAToDoThatBreaksItsOwnRulesIsStillAnswered(string $lines, bool $overlaps): void
    {
        self::assertSame($overlaps, $this->overlaps('VTODO', $lines));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function toDosThatBreakTheirOwnRules(): iterable
    {
        // `start < DUE` is false and `start <= DTSTART` carries the row.
        yield 'a due date before its start, starting inside' => [
            "DTSTART:20260105T123000Z\r\nDUE:20260105T090000Z\r\n",
            true,
        ];

        // `end > DTSTART` is false and `end >= DUE` carries the row.
        yield 'a due date at the end, starting after it' => [
            "DTSTART:20260105T140000Z\r\nDUE:20260105T130000Z\r\n",
            true,
        ];

        // `end >= CREATED` is false and `end >= COMPLETED` carries the row.
        yield 'completed before it was created' => [
            "CREATED:20260105T140000Z\r\nCOMPLETED:20260105T123000Z\r\n",
            true,
        ];
    }

    /**
     * **§9.9's `VJOURNAL` table.** "The effective 'duration' of a VJOURNAL
     * component is 1 day (+P1D) when the DTSTART is a DATE value, and 0
     * seconds when the DTSTART is a DATE-TIME value."
     *
     * @param string $lines The entry's own properties
     * @param bool $overlaps Whether it overlaps noon to one on 5.1.2026
     */
    #[DataProvider('journalEntries')]
    public function testAJournalEntryOverlapsAsItsRowSays(string $lines, bool $overlaps): void
    {
        self::assertSame($overlaps, $this->overlaps('VJOURNAL', $lines));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function journalEntries(): iterable
    {
        // Y Y: (start <= DTSTART) AND (end > DTSTART)
        yield 'at the start' => ["DTSTART:20260105T120000Z\r\n", true];

        yield 'before it' => ["DTSTART:20260105T110000Z\r\n", false];

        // Y N: (start < DTSTART+P1D) AND (end > DTSTART)
        yield 'all day on the day itself' => ["DTSTART;VALUE=DATE:20260105\r\n", true];

        yield 'all day on the day before' => ["DTSTART;VALUE=DATE:20260104\r\n", false];

        // N *: FALSE
        yield 'no start at all' => ["SUMMARY:A thought\r\n", false];
    }

    /**
     * **§9.9's `VFREEBUSY` table.** "Any DURATION property is ignored, as it
     * has a special meaning when used in a VFREEBUSY component."
     *
     * @param string $lines The component's own properties
     * @param bool $overlaps Whether it overlaps noon to one on 5.1.2026
     */
    #[DataProvider('freeBusy')]
    public function testFreeBusyOverlapsAsItsRowSays(string $lines, bool $overlaps): void
    {
        self::assertSame($overlaps, $this->overlaps('VFREEBUSY', $lines));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function freeBusy(): iterable
    {
        // Y *: (start <= DTEND) AND (end > DTSTART)
        yield 'a span around the range' => ["DTSTART:20260105T110000Z\r\nDTEND:20260105T140000Z\r\n", true];

        yield 'a span ending exactly at the start' => [
            "DTSTART:20260105T100000Z\r\nDTEND:20260105T120000Z\r\n",
            true,
        ];

        yield 'a span ending before it' => ["DTSTART:20260105T100000Z\r\nDTEND:20260105T110000Z\r\n", false];

        // N Y: (start < freebusy-period-end) AND (end > freebusy-period-start)
        yield 'a busy period reaching in' => ["FREEBUSY:20260105T113000Z/20260105T123000Z\r\n", true];

        yield 'a busy period before it' => ["FREEBUSY:20260105T100000Z/20260105T110000Z\r\n", false];

        yield 'one of several periods reaching in' => [
            "FREEBUSY:20260105T100000Z/20260105T110000Z,20260105T123000Z/20260105T124000Z\r\n",
            true,
        ];

        yield 'a period written as a duration' => ["FREEBUSY:20260105T113000Z/PT1H\r\n", true];

        // "irrespective of the type of free busy information"
        yield 'a tentative period' => ["FREEBUSY;FBTYPE=BUSY-TENTATIVE:20260105T123000Z/PT10M\r\n", true];

        // N N: FALSE
        yield 'neither a span nor a period' => ["ORGANIZER:mailto:a@example.com\r\n", false];

        // A period this library cannot read falls out of the list rather than
        // throwing the filter over: §3.8.2.6's own form is `period`, and
        // something else is something else.
        yield 'a period it cannot read' => ["FREEBUSY:not-a-period\r\n", false];

        yield 'a readable period beside one it cannot read' => [
            "FREEBUSY:not-a-period,20260105T123000Z/PT10M\r\n",
            true,
        ];

        // And a period after one written as a duration: the list goes on past
        // either form, because §3.8.2.6 lets both stand in one value.
        yield 'a period after one written as a duration' => [
            "FREEBUSY:20260105T100000Z/PT1H,20260105T123000Z/20260105T124000Z\r\n",
            true,
        ];
    }

    /**
     * **§9.9 on `VALARM`:** "A VALARM component is said to overlap a given
     * time range if the following condition holds: (start <= trigger-time) AND
     * (end > trigger-time)".
     *
     * @param string $alarm The alarm's own properties
     * @param bool $overlaps Whether it overlaps noon to one on 5.1.2026
     */
    #[DataProvider('alarms')]
    public function testAnAlarmOverlapsWhenItTriggersInTheRange(string $alarm, bool $overlaps): void
    {
        // The event runs from 14:00 to 15:00, two hours after the range, so
        // every alarm below has to reach back into it.
        $event = "BEGIN:VEVENT\r\nUID:1@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART:20260105T140000Z\r\nDTEND:20260105T150000Z\r\n"
            . 'BEGIN:VALARM' . "\r\nACTION:DISPLAY\r\nDESCRIPTION:Reminder\r\n" . $alarm
            . "END:VALARM\r\nEND:VEVENT\r\n";
        $object = $this->objectOf($event);
        $within = $object->component('VEVENT');

        self::assertNotNull($within);

        $alarmComponent = $within->component('VALARM');

        self::assertNotNull($alarmComponent);
        self::assertSame($overlaps, $this->range(self::START, self::END)->overlaps(
            $alarmComponent,
            new Moments($object, new DateTimeZone('UTC')),
            $within,
        ));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function alarms(): iterable
    {
        // An absolute trigger needs nothing but itself (RFC 5545 §3.8.6.3).
        yield 'an absolute trigger inside' => ["TRIGGER;VALUE=DATE-TIME:20260105T123000Z\r\n", true];

        yield 'an absolute trigger outside' => ["TRIGGER;VALUE=DATE-TIME:20260105T100000Z\r\n", false];

        // A relative trigger is a duration from the start by default:
        // "the 'RELATED' parameter … the default is START".
        yield 'two hours before the start' => ["TRIGGER:-PT2H\r\n", true];

        yield 'one hour before the start' => ["TRIGGER:-PT1H\r\n", false];

        // RELATED=END counts from the event's end.
        yield 'three hours before the end' => ["TRIGGER;RELATED=END:-PT3H\r\n", true];

        yield 'at the end' => ["TRIGGER;RELATED=END:PT0S\r\n", false];

        // "A VALARM component can be defined such that it triggers
        // repeatedly. Such a VALARM component is said to overlap a given time
        // range if at least one of its triggers overlaps the time range."
        yield 'a repeat reaching in' => ["TRIGGER:-PT4H\r\nREPEAT:3\r\nDURATION:PT1H\r\n", true];

        yield 'a repeat stopping short' => ["TRIGGER:-PT6H\r\nREPEAT:1\r\nDURATION:PT1H\r\n", false];

        // A trigger this library cannot read triggers nowhere.
        yield 'a trigger it cannot read' => ["TRIGGER:not-a-duration\r\n", false];

        yield 'no trigger at all' => ["ATTACH:ftp://example.com/bell.aud\r\n", false];

        // A `REPEAT` without a `DURATION` repeats nothing: §3.8.6.2 says the
        // two belong together — "This property MUST be specified along with
        // the 'duration' property."
        yield 'a repeat with no duration' => ["TRIGGER:-PT6H\r\nREPEAT:3\r\n", false];

        // **`REPEAT` counts what follows the first trigger**, which §3.8.6.2
        // says in as many words: "This property defines the number of times
        // the alarm should be repeated, **after** the initial trigger." So
        // two repeats from 10:00 at an hour apart are 10:00, 11:00 and 12:00 —
        // and only the last of the three is in the range, which is what pins
        // the count.
        yield 'the last of two repeats reaching in' => ["TRIGGER:-PT4H\r\nREPEAT:2\r\nDURATION:PT1H\r\n", true];

        // A `REPEAT` that is no number repeats nothing. §3.8.6.2 gives it the
        // INTEGER value type, so this is invalid iCalendar — and a store may
        // hold it, which makes reading it as nothing rather than as something
        // the difference between an answer and a loop with no end.
        yield 'a repeat that is no number' => ["TRIGGER:-PT4H\r\nREPEAT:many\r\nDURATION:PT1H\r\n", false];

        // **And it counts no further than it says.** One repeat from 10:00 at
        // an hour apart is 10:00 and 11:00, and the range begins at noon — so
        // a count that reached one step beyond would put an alarm in a range
        // the object never reaches.
        yield 'one repeat stopping just short' => ["TRIGGER:-PT4H\r\nREPEAT:1\r\nDURATION:PT1H\r\n", false];

        // A trigger's own `VALUE` and `RELATED` are enumerated parameter
        // values, which §3.1 makes case-insensitive.
        yield 'an absolute trigger written quietly' => ["TRIGGER;value=date-time:20260105T123000Z\r\n", true];

        yield 'a trigger related to the end, written quietly' => ["TRIGGER;related=end:-PT3H\r\n", true];
    }

    /**
     * **`RELATED=END` counts from whatever says where the component ends**,
     * and §9.9's own note says which: "if DTEND is not present in a VEVENT,
     * but DURATION is, then the test should instead operate on the 'effective'
     * DTEND, i.e., DTSTART+DURATION. Similarly, if DUE is not present in a
     * VTODO, but DTSTART and DURATION are, then the test should instead
     * operate on the 'effective' DUE".
     *
     * @param string $kind The kind of component the alarm sits in
     * @param string $lines What that component says about when it ends
     * @param bool $overlaps Whether an alarm three hours before its end
     *                       falls in the range
     */
    #[DataProvider('componentsAnAlarmCountsBackFrom')]
    public function testAnAlarmCountsBackFromWhateverSaysWhereItEnds(
        string $kind,
        string $lines,
        bool $overlaps,
    ): void {
        $text = 'BEGIN:' . $kind . "\r\nUID:1@example.com\r\nDTSTAMP:20260101T000000Z\r\n" . $lines
            . "BEGIN:VALARM\r\nACTION:DISPLAY\r\nDESCRIPTION:Reminder\r\n"
            . "TRIGGER;RELATED=END:-PT3H\r\nEND:VALARM\r\n" . 'END:' . $kind . "\r\n";
        $object = $this->objectOf($text);
        $within = $object->component($kind);

        self::assertNotNull($within);

        $alarm = $within->component('VALARM');

        self::assertNotNull($alarm);
        self::assertSame($overlaps, $this->range(self::START, self::END)->overlaps(
            $alarm,
            new Moments($object, new DateTimeZone('UTC')),
            $within,
        ));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function componentsAnAlarmCountsBackFrom(): iterable
    {
        // An event's own DTEND: 15:00 less three hours is noon.
        yield 'an event with a DTEND' => [
            'VEVENT',
            "DTSTART:20260105T140000Z\r\nDTEND:20260105T150000Z\r\n",
            true,
        ];

        // A to-do's DUE stands where an event's DTEND does.
        yield 'a to-do with a DUE' => ['VTODO', "DUE:20260105T150000Z\r\n", true];

        // Neither, but a DURATION: the effective end is DTSTART+DURATION.
        yield 'an event with a DURATION' => [
            'VEVENT',
            "DTSTART:20260105T140000Z\r\nDURATION:PT1H\r\n",
            true,
        ];

        // Nothing at all says where it ends, so there is nothing to count
        // back from — and inventing one would put an alarm in a range at
        // random.
        yield 'an event with no end at all' => ['VEVENT', "DTSTART:20260105T140000Z\r\n", false];

        yield 'a journal entry with nothing to end' => ['VJOURNAL', "SUMMARY:A thought\r\n", false];

        // **`DTEND` is asked before `DUE`**, because §9.9's own note names one
        // for each kind — "the 'effective' DTEND" of a VEVENT, "the
        // 'effective' DUE" of a VTODO. A component carrying both is invalid
        // iCalendar whichever kind it is, and a store may hold one: 15:00 less
        // three hours is noon, while 20:00 less three is not.
        yield 'an event carrying both, where they disagree' => [
            'VEVENT',
            "DTSTART:20260105T140000Z\r\nDTEND:20260105T150000Z\r\nDUE:20260105T200000Z\r\n",
            true,
        ];
    }

    /**
     * **A kind is read whatever its case**, because RFC 5545 §3.1 makes the
     * names case-insensitive: "the property name `DUE` is the same as `due`
     * and `Due`". A table chosen by a shouted spelling alone would answer
     * nothing about half the objects in the wild.
     */
    public function testAKindIsReadWhateverItsCase(): void
    {
        $object = $this->objectOf(
            "begin:vevent\r\nuid:1@example.com\r\ndtstamp:20260101T000000Z\r\n"
            . "dtstart:20260105T123000Z\r\nend:vevent\r\n",
        );
        $component = $object->component('VEVENT');

        self::assertNotNull($component);
        self::assertTrue($this->range(self::START, self::END)->overlaps(
            $component,
            new Moments($object, new DateTimeZone('UTC')),
        ));
    }

    /**
     * **A journal entry whose `DTSTART` cannot be read overlaps nothing.** The
     * table asks whether the property is there; a value nobody can read is
     * there and says nothing, which is not the same thing — so both are
     * refused, and neither is mistaken for the other.
     */
    public function testAJournalEntryWithAnUnreadableStartOverlapsNothing(): void
    {
        self::assertFalse($this->overlaps('VJOURNAL', "DTSTART:not-a-date\r\n"));
    }

    /**
     * **§9.9 asks whether a `VFREEBUSY` has *both*** — "VFREEBUSY has both the
     * DTSTART and DTEND properties?" — so one of the two is not an answer to
     * that row, and the periods decide instead.
     */
    public function testFreeBusyWithOnlyOneOfTheTwoFallsToItsPeriods(): void
    {
        self::assertTrue($this->overlaps(
            'VFREEBUSY',
            "DTSTART:20260105T100000Z\r\nFREEBUSY:20260105T123000Z/PT10M\r\n",
        ));
        self::assertFalse($this->overlaps('VFREEBUSY', "DTEND:20260105T140000Z\r\n"));
    }

    /**
     * **"The semantic of CALDAV:time-range is not defined for any other
     * calendar components and properties."**
     *
     * So a kind the memo says nothing about overlaps nothing. Answering
     * otherwise would return resources a client cannot predict, and the
     * report that asked is the layer that may refuse the filter outright.
     */
    public function testAKindTheMemoSaysNothingAboutOverlapsNothing(): void
    {
        self::assertFalse($this->overlaps('VTIMEZONE', "TZID:US-Eastern\r\n"));
    }

    /**
     * **An alarm outside a component triggers nowhere**, because a relative
     * trigger is a duration from something: RFC 5545 §3.8.6.3 counts it from
     * "the start of the calendar component" or its end, and there is neither.
     */
    public function testAnAlarmWithNothingAroundItTriggersNowhere(): void
    {
        self::assertFalse($this->overlaps('VALARM', "TRIGGER:-PT2H\r\n"));
    }

    /**
     * One range, from what the attributes say; null leaves an attribute out.
     */
    private function range(?string $from, ?string $until): TimeRange
    {
        $attributes = [];

        if ($from !== null) {
            $attributes['start'] = $from;
        }

        if ($until !== null) {
            $attributes['end'] = $until;
        }

        return TimeRange::of(new Element(TimeRange::NAME, $attributes));
    }

    /**
     * Whether a component of that kind, with those properties, overlaps the
     * range.
     */
    private function overlaps(string $kind, string $lines): bool
    {
        $object = $this->objectOf(
            'BEGIN:' . $kind . "\r\nUID:1@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
            . $lines . 'END:' . $kind . "\r\n",
        );
        $component = $object->component($kind);

        self::assertNotNull($component);

        return $this->range(self::START, self::END)->overlaps(
            $component,
            new Moments($object, new DateTimeZone('UTC')),
        );
    }

    private function objectOf(string $components): Component
    {
        $text = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\n"
            . $components . "END:VCALENDAR\r\n";

        return CalendarObject::in($text) ?? self::fail('The case built something that is no calendar object.');
    }
}
