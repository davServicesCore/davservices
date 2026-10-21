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

use DateTimeZone;
use DavServices\CalDav\CalendarObject;
use DavServices\CalDav\Expander;
use DavServices\CalDav\Moments;
use DavServices\Exception\BadRequest;
use DavServices\VObject\Component;
use DavServices\VObject\Serializer;
use DavServices\Xml\Element;
use DavServices\Xml\Reader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for `CALDAV:expand`. R-CAL-14, P6-08.
 *
 * RFC 4791 §9.6.5:
 *
 * > The CALDAV:expand XML element specifies that for a given calendaring
 * > REPORT request, the server MUST expand the recurrence set into calendar
 * > components that define exactly one recurrence instance, and MUST return
 * > only those whose scheduled time intersect a specified time range.
 * >
 * > The server MUST use the same logic as defined for CALDAV:time-range to
 * > determine if a recurrence instance intersects the specified time range.
 * >
 * > Recurring components, other than the initial instance, MUST include a
 * > RECURRENCE-ID property indicating which instance they refer to.
 * >
 * > The returned calendar components MUST NOT use recurrence properties (i.e.,
 * > EXDATE, EXRULE, RDATE, and RRULE) and MUST NOT have reference to or
 * > include VTIMEZONE components. Date and local time with reference to time
 * > zone information MUST be converted into date with UTC time.
 *
 *     <!ELEMENT expand EMPTY>
 *     <!ATTLIST expand start CDATA #REQUIRED
 *                      end   CDATA #REQUIRED>
 *
 * **What a series comes to is not this class's work** — that is
 * `VObject\Recur\ObjectSet`, which answers which instances an object has and
 * which component describes each. What is tested here is the writing out: one
 * component per instance, with the recurrence gone, the times moved and the
 * zones resolved.
 */
#[CoversClass(Expander::class)]
final class ExpanderTest extends TestCase
{
    /** Five Mondays from 5.1.2026, noon to one, in UTC. */
    private const WEEKLY = "BEGIN:VEVENT\r\nUID:1@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
        . "DTSTART:20260105T120000Z\r\nDTEND:20260105T130000Z\r\nRRULE:FREQ=WEEKLY;COUNT=5\r\n"
        . "SUMMARY:Weekly\r\nEND:VEVENT\r\n";

    /** The whole of January, which holds four of those five. */
    private const JANUARY = 'start="20260101T000000Z" end="20260201T000000Z"';

    /** The third Monday alone, 19.1.2026. */
    private const THIRD = 'start="20260119T000000Z" end="20260120T000000Z"';

    public function testASeriesBecomesOneComponentPerInstance(): void
    {
        $written = $this->expanded(self::JANUARY, self::WEEKLY);

        self::assertSame(4, substr_count($written, 'BEGIN:VEVENT'));
        self::assertStringContainsString("DTSTART:20260105T120000Z\r\n", $written);
        self::assertStringContainsString("DTSTART:20260112T120000Z\r\n", $written);
        self::assertStringContainsString("DTSTART:20260119T120000Z\r\n", $written);
        self::assertStringContainsString("DTSTART:20260126T120000Z\r\n", $written);
    }

    /**
     * **§7.8.1's own example**: "the first calendar object returned is a
     * recurring event whose first instance lies outside the requested time
     * range, but whose third instance does overlap the time range".
     */
    public function testOnlyTheInstancesThatIntersectTheRangeComeBack(): void
    {
        $written = $this->expanded(self::THIRD, self::WEEKLY);

        self::assertSame(1, substr_count($written, 'BEGIN:VEVENT'));
        self::assertStringContainsString("DTSTART:20260119T120000Z\r\n", $written);
    }

    /**
     * **"Recurring components, other than the initial instance, MUST include a
     * RECURRENCE-ID property indicating which instance they refer to."**
     *
     * So the first instance of the set carries none — it is the one the
     * `DTSTART` already names — and every other one says which it is.
     */
    public function testEveryInstanceButTheInitialOneSaysWhichItIs(): void
    {
        $written = $this->expanded(self::JANUARY, self::WEEKLY);

        self::assertSame(3, substr_count($written, 'RECURRENCE-ID'));
        self::assertStringContainsString("RECURRENCE-ID:20260112T120000Z\r\n", $written);
        self::assertStringContainsString("RECURRENCE-ID:20260126T120000Z\r\n", $written);
    }

    public function testTheInitialInstanceCarriesNoRecurrenceId(): void
    {
        $written = $this->expanded('start="20260105T000000Z" end="20260106T000000Z"', self::WEEKLY);

        self::assertSame(1, substr_count($written, 'BEGIN:VEVENT'));
        self::assertStringNotContainsString('RECURRENCE-ID', $written);
    }

    /**
     * **"The returned calendar components MUST NOT use recurrence properties
     * (i.e., EXDATE, EXRULE, RDATE, and RRULE)"** — all four, because a
     * component that kept one would still be a series, and the client asked
     * for instances.
     *
     * @param string $property The recurrence property the case is about
     * @param string $line How the object writes it
     */
    #[DataProvider('theRecurrenceProperties')]
    public function testTheRecurrencePropertiesAreGone(string $property, string $line): void
    {
        $written = $this->expanded(
            self::JANUARY,
            str_replace("SUMMARY:Weekly\r\n", $line . "SUMMARY:Weekly\r\n", self::WEEKLY),
        );

        self::assertStringNotContainsString($property, $written);
        self::assertStringContainsString('SUMMARY:Weekly', $written);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function theRecurrenceProperties(): iterable
    {
        yield 'RRULE' => ['RRULE', ''];
        yield 'RDATE' => ['RDATE', "RDATE:20260107T120000Z\r\n"];
        yield 'EXDATE' => ['EXDATE', "EXDATE:20260112T120000Z\r\n"];

        // RFC 5545 §3.8.5.2 withdrew `EXRULE`, and §9.6.5 names it all the
        // same: an object a client wrote years ago may carry one.
        yield 'EXRULE' => ['EXRULE', "EXRULE:FREQ=WEEKLY;INTERVAL=4\r\n"];
    }

    /**
     * **The end moves with the start**, by the same wall clock: RFC 5545
     * §3.8.5.3 has it that where "the duration of the recurring component is
     * specified with the DTEND […] property, then the […] duration of each
     * instance is the same as the nominal duration of the initial instance".
     */
    public function testTheEndMovesWithTheStart(): void
    {
        $written = $this->expanded(self::THIRD, self::WEEKLY);

        self::assertStringContainsString("DTSTART:20260119T120000Z\r\n", $written);
        self::assertStringContainsString("DTEND:20260119T130000Z\r\n", $written);
    }

    /**
     * And a component that says its length as a duration keeps the duration:
     * there is nothing in it to move.
     */
    public function testADurationIsKeptAsItIs(): void
    {
        $written = $this->expanded(self::THIRD, str_replace(
            "DTEND:20260105T130000Z\r\n",
            "DURATION:PT90M\r\n",
            self::WEEKLY,
        ));

        self::assertStringContainsString("DTSTART:20260119T120000Z\r\n", $written);
        self::assertStringContainsString("DURATION:PT90M\r\n", $written);
    }

    /**
     * A to-do says its end with `DUE` (RFC 5545 §3.8.2.3), and that moves the
     * same way.
     */
    public function testAToDosDueMovesWithItsStart(): void
    {
        $task = "BEGIN:VTODO\r\nUID:2@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART:20260105T120000Z\r\nDUE:20260105T170000Z\r\nRRULE:FREQ=WEEKLY;COUNT=5\r\n"
            . "SUMMARY:Weekly task\r\nEND:VTODO\r\n";
        $written = $this->expanded(self::THIRD, $task);

        self::assertStringContainsString("DTSTART:20260119T120000Z\r\n", $written);
        self::assertStringContainsString("DUE:20260119T170000Z\r\n", $written);
    }

    /**
     * **"Date and local time with reference to time zone information MUST be
     * converted into date with UTC time."** The reference is the `TZID`
     * parameter (RFC 5545 §3.2.19), and a value that carried one comes back as
     * an instant with the parameter gone — which is what makes the next
     * sentence keepable: the answer has no `VTIMEZONE` to point at.
     */
    public function testALocalTimeWithAZoneBecomesUtcAndLosesTheParameter(): void
    {
        $written = $this->expanded('start="20260107T000000Z" end="20260108T000000Z"', self::$zoned);

        // Nine in the morning in New York in January is 14:00 UTC.
        self::assertStringContainsString("DTSTART:20260107T140000Z\r\n", $written);
        self::assertStringContainsString("DTEND:20260107T150000Z\r\n", $written);
        self::assertStringNotContainsString('TZID', $written);
    }

    /**
     * **"[The returned calendar components] MUST NOT have reference to or
     * include VTIMEZONE components."** Both halves: the references are gone
     * with the parameters above, and the definitions themselves are not
     * returned.
     */
    public function testTheTimeZoneDefinitionsAreGone(): void
    {
        $written = $this->expanded(self::JANUARY, self::$zoned);

        self::assertStringNotContainsString('VTIMEZONE', $written);
        self::assertStringNotContainsString('TZOFFSETFROM', $written);
    }

    /**
     * **An all-day value stays a date.** §9.6.5's sentence is about values
     * "with reference to time zone information", and RFC 5545 §3.2.19 forbids
     * that reference on a date: "This parameter MUST NOT be applied to DATE
     * properties". A date turned into an instant would make an all-day
     * appointment a timed one, which is not what any sentence here asks for.
     */
    public function testAnAllDayValueStaysADate(): void
    {
        $allDay = "BEGIN:VEVENT\r\nUID:3@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART;VALUE=DATE:20260105\r\nDTEND;VALUE=DATE:20260106\r\nRRULE:FREQ=WEEKLY;COUNT=5\r\n"
            . "SUMMARY:All day\r\nEND:VEVENT\r\n";
        $written = $this->expanded(self::THIRD, $allDay);

        self::assertStringContainsString("DTSTART;VALUE=DATE:20260119\r\n", $written);
        self::assertStringContainsString("DTEND;VALUE=DATE:20260120\r\n", $written);
    }

    /**
     * **And a floating value stays floating**, for the same reason read the
     * other way: it references no time zone, so there is nothing about it that
     * the second sentence asks to be converted — and a server that fixed it to
     * an instant would move an appointment somebody wrote to be the same wall
     * clock everywhere.
     */
    public function testAFloatingValueStaysFloating(): void
    {
        $floating = str_replace(
            ["DTSTART:20260105T120000Z\r\n", "DTEND:20260105T130000Z\r\n"],
            ["DTSTART:20260105T120000\r\n", "DTEND:20260105T130000\r\n"],
            self::WEEKLY,
        );
        $written = $this->expanded(self::THIRD, $floating);

        self::assertStringContainsString("DTSTART:20260119T120000\r\n", $written);
        self::assertStringContainsString("DTEND:20260119T130000\r\n", $written);
    }

    /**
     * **An instance at a local time that does not exist is not one.**
     * RFC 5545 §3.3.10: "Recurrence rules may generate recurrence instances
     * with an invalid date […] or nonexistent local time (e.g., 1:30 AM on a
     * day where the local time is moved forward by an hour at 1:00 AM). Such
     * recurrence instances MUST be ignored and MUST NOT be counted as part of
     * the recurrence set."
     *
     * The clocks in New York go forward at two on 8 March 2026, so the second
     * morning of this series never comes — while the first and the third do,
     * an hour apart in UTC because the offset changed between them.
     */
    public function testAnInstanceAtALocalTimeThatDoesNotExistIsNotReturned(): void
    {
        $daily = "BEGIN:VEVENT\r\nUID:6@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART;TZID=America/New_York:20260307T023000\r\n"
            . "DTEND;TZID=America/New_York:20260307T033000\r\n"
            . "RRULE:FREQ=DAILY;COUNT=3\r\nSUMMARY:Half past two\r\nEND:VEVENT\r\n";
        $written = $this->expanded('start="20260301T000000Z" end="20260401T000000Z"', $daily);

        self::assertSame(2, substr_count($written, 'BEGIN:VEVENT'));
        self::assertStringContainsString("DTSTART:20260307T073000Z\r\n", $written);
        self::assertStringContainsString("DTSTART:20260309T063000Z\r\n", $written);
    }

    /**
     * **What a converted property carries besides its zone is kept.** §9.6.5
     * asks for the reference to the time zone to be gone, and RFC 5545
     * §3.2.19 is what the reference is — the `TZID` parameter, and nothing
     * else about the property. `VALUE=DATE-TIME` says which of §3.3.5's forms
     * the value is (§3.2.20), and that is still true of an instant.
     */
    public function testWhatAConvertedPropertyCarriesBesidesItsZoneIsKept(): void
    {
        $written = $this->expanded(
            'start="20260107T000000Z" end="20260108T000000Z"',
            str_replace(
                'DTSTART;TZID=America/New_York:',
                'DTSTART;VALUE=DATE-TIME;TZID=America/New_York:',
                self::$zoned,
            ),
        );

        self::assertStringContainsString("DTSTART;VALUE=DATE-TIME:20260107T140000Z\r\n", $written);
        self::assertStringNotContainsString('TZID', $written);
    }

    /**
     * **An overridden instance is written as its own component says it is**
     * (RFC 4791 §4.1), with the `RECURRENCE-ID` it already carries: the
     * identifier says which instance it replaces, and §3.8.4.4 keeps it at
     * "the original value of the DTSTART property".
     */
    public function testAnOverrideIsWrittenWithItsOwnTimes(): void
    {
        $written = $this->expanded(self::THIRD, self::WEEKLY . self::$override);

        self::assertStringContainsString('SUMMARY:Moved two hours on', $written);
        self::assertStringContainsString("DTSTART:20260119T140000Z\r\n", $written);
        self::assertStringContainsString("RECURRENCE-ID:20260119T120000Z\r\n", $written);
        self::assertStringNotContainsString('SUMMARY:Weekly', $written);
    }

    /**
     * **A master and its overrides are one series, expanded once.** RFC 4791
     * §4.1 has the components of one `UID` "contained in the same calendar
     * object resource", so meeting the second of them is no reason to expand
     * the set again.
     */
    public function testASeriesIsExpandedOnceHoweverManyComponentsItHas(): void
    {
        $written = $this->expanded(self::JANUARY, self::WEEKLY . self::$override);

        self::assertSame(4, substr_count($written, 'BEGIN:VEVENT'));
        self::assertSame(1, substr_count($written, 'SUMMARY:Moved two hours on'));
    }

    /**
     * **An instance moved into the range from beyond its end is found.**
     * §9.6.5 asks for the instances "whose scheduled time intersect" the
     * range, and an override is scheduled where its own component says — so
     * the walk cannot stop at the range's end and leave it out.
     *
     * **Including the one the walk stopped at.** The instance that ends the
     * walk is the first whose identifier lies past the range, and that one may
     * be an override itself — so the components asked afterwards are the ones
     * at **or** past where it stopped.
     *
     * @param string $identifier Where the series put the instance this
     *                           override replaces
     */
    #[DataProvider('identifiersBeyondTheRange')]
    public function testAnInstanceMovedIntoTheRangeFromBeyondItIsFound(string $identifier): void
    {
        $moved = "BEGIN:VEVENT\r\nUID:1@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
            . 'RECURRENCE-ID:' . $identifier . "\r\nDTSTART:20260119T100000Z\r\nDTEND:20260119T110000Z\r\n"
            . "SUMMARY:Brought forward\r\nEND:VEVENT\r\n";
        $written = $this->expanded(self::THIRD, self::WEEKLY . $moved);

        self::assertStringContainsString('SUMMARY:Brought forward', $written);
        self::assertStringContainsString('RECURRENCE-ID:' . $identifier . "\r\n", $written);

        // Its own times, which is what an override brings: nothing of the
        // series moves it, so nothing of the series may move it either.
        self::assertStringContainsString("DTSTART:20260119T100000Z\r\n", $written);
        self::assertStringContainsString("DTEND:20260119T110000Z\r\n", $written);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function identifiersBeyondTheRange(): iterable
    {
        yield 'the first identifier past the range' => ['20260126T120000Z'];
        yield 'one well past it' => ['20260202T120000Z'];
    }

    /**
     * **And the walk past the end looks at nothing it has no business with**:
     * a component of another series, one that names no instance at all, one
     * that names an instance and no series, an override the walk has already
     * been past, and an override that stayed where it was.
     *
     * Each of those is a reason to go on to the next component rather than to
     * stop — and the one that was moved into the range comes last, so that a
     * walk which stopped at any of them would leave it out.
     */
    public function testTheWalkPastTheEndPassesOverWhatIsNoneOfItsBusiness(): void
    {
        $nameless = "BEGIN:VEVENT\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART:20260119T120000Z\r\nDTEND:20260119T130000Z\r\nSUMMARY:Nameless\r\nEND:VEVENT\r\n";
        $elsewhere = "BEGIN:VEVENT\r\nUID:9@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
            . "RECURRENCE-ID:20260126T120000Z\r\nDTSTART:20260126T120000Z\r\nDTEND:20260126T130000Z\r\n"
            . "SUMMARY:Another series\r\nEND:VEVENT\r\n";

        // An instance of nothing: §4.1 makes a `UID` mandatory, so this is
        // invalid iCalendar a store may hold, and it belongs to no series the
        // walk is about.
        $orphan = "BEGIN:VEVENT\r\nDTSTAMP:20260101T000000Z\r\n"
            . "RECURRENCE-ID:20260126T120000Z\r\nDTSTART:20260126T140000Z\r\nDTEND:20260126T150000Z\r\n"
            . "SUMMARY:Instance of nothing\r\nEND:VEVENT\r\n";

        // An override the walk itself already passed, its identifier lying
        // before where the walk stopped.
        $early = "BEGIN:VEVENT\r\nUID:1@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
            . "RECURRENCE-ID:20260112T120000Z\r\nDTSTART:20260112T140000Z\r\nDTEND:20260112T150000Z\r\n"
            . "SUMMARY:Early override\r\nEND:VEVENT\r\n";
        $moved = "BEGIN:VEVENT\r\nUID:1@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
            . "RECURRENCE-ID:20260202T120000Z\r\nDTSTART:20260119T100000Z\r\nDTEND:20260119T110000Z\r\n"
            . "SUMMARY:Brought forward\r\nEND:VEVENT\r\n";
        $written = $this->expanded(
            self::THIRD,
            $nameless . self::WEEKLY . $elsewhere . $orphan . $early . $moved,
        );

        self::assertStringContainsString('SUMMARY:Nameless', $written);
        self::assertStringContainsString("DTSTART:20260119T120000Z\r\n", $written);
        self::assertStringContainsString('SUMMARY:Brought forward', $written);
        self::assertStringNotContainsString('Another series', $written);
        self::assertStringNotContainsString('Instance of nothing', $written);
        self::assertStringNotContainsString('Early override', $written);
    }

    /**
     * **The object's own properties come back**, because they are no part of
     * any instance: §9.6.5 is about the components, and an object without its
     * `VERSION` is not iCalendar at all.
     */
    public function testTheObjectsOwnPropertiesComeBack(): void
    {
        $written = $this->expanded(self::JANUARY, self::WEEKLY);

        self::assertStringContainsString("BEGIN:VCALENDAR\r\n", $written);
        self::assertStringContainsString("VERSION:2.0\r\n", $written);
        self::assertStringContainsString('PRODID:', $written);
    }

    /**
     * An alarm belongs to the instance it is in, and comes with it.
     */
    public function testAnAlarmInsideAnInstanceComesWithIt(): void
    {
        $withAlarm = str_replace(
            "END:VEVENT\r\n",
            "BEGIN:VALARM\r\nACTION:DISPLAY\r\nDESCRIPTION:Soon\r\nTRIGGER:-PT15M\r\nEND:VALARM\r\nEND:VEVENT\r\n",
            self::WEEKLY,
        );
        $written = $this->expanded(self::THIRD, $withAlarm);

        self::assertStringContainsString("BEGIN:VALARM\r\n", $written);
        self::assertStringContainsString("TRIGGER:-PT15M\r\n", $written);
    }

    /**
     * **A series that never ends is walked only as far as the range.** §9.9
     * reads a rule without `COUNT` or `UNTIL` as one that repeats for ever
     * (RFC 5545 §3.3.10: "the recurrence set is unbounded"), and §9.6.5 asks
     * for the instances inside a range — so the walk has to leave off, and
     * what proves it did is that the expansion came back at all rather than
     * running into R-RRULE-04's limit.
     */
    public function testASeriesThatNeverEndsIsWalkedOnlyAsFarAsTheRange(): void
    {
        $written = $this->expanded(self::THIRD, str_replace(
            "RRULE:FREQ=WEEKLY;COUNT=5\r\n",
            "RRULE:FREQ=WEEKLY\r\n",
            self::WEEKLY,
        ));

        self::assertSame(1, substr_count($written, 'BEGIN:VEVENT'));
        self::assertStringContainsString("DTSTART:20260119T120000Z\r\n", $written);
        self::assertStringNotContainsString('RRULE', $written);
    }

    /**
     * **Where the walk may stop is a question about instants, not about wall
     * clocks.** A series in a zone ahead of UTC shows the difference: the
     * instance of 20.1. begins at 09:00 in Auckland, which is 20:00 UTC on
     * the **19th** — inside a range that ends with the 19th. A walk that
     * compared the clock on the wall would have stopped one instance too
     * early and left it out, and §9.6.5 asks for the instances "whose
     * scheduled time intersect" the range.
     */
    public function testWhereTheWalkStopsIsAskedOfTheInstantAndNotOfTheClock(): void
    {
        $ahead = "BEGIN:VEVENT\r\nUID:8@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART;TZID=Pacific/Auckland:20260119T090000\r\n"
            . "DTEND;TZID=Pacific/Auckland:20260119T100000\r\n"
            . "RRULE:FREQ=DAILY;COUNT=5\r\nSUMMARY:Morning in Auckland\r\nEND:VEVENT\r\n";
        $written = $this->expanded('start="20260119T000000Z" end="20260120T000000Z"', $ahead);

        // Auckland is thirteen hours ahead in January, so the 19th at 09:00
        // local is the 18th at 20:00 UTC, which the range does not hold, and
        // the 20th at 09:00 local is the 19th at 20:00 UTC, which it does.
        self::assertSame(1, substr_count($written, 'BEGIN:VEVENT'));
        self::assertStringContainsString("DTSTART:20260119T200000Z\r\n", $written);
        self::assertStringContainsString("RECURRENCE-ID:20260119T200000Z\r\n", $written);
    }

    /**
     * **A name is a name however quietly it is written.** RFC 5545 §3.1: "the
     * property name `DUE` is the same as `due` and `Due`" — so a series
     * written in lower case loses its `rrule` and moves its `dtend` like any
     * other.
     */
    public function testTheNamesAreReadWithoutRegardToCase(): void
    {
        $quiet = "BEGIN:VEVENT\r\nuid:7@example.com\r\ndtstamp:20260101T000000Z\r\n"
            . "dtstart:20260105T120000Z\r\ndtend:20260105T130000Z\r\nrrule:FREQ=WEEKLY;COUNT=5\r\n"
            . "summary:Quietly\r\nEND:VEVENT\r\n";
        $written = $this->expanded(self::THIRD, $quiet);

        self::assertStringContainsString("dtstart:20260119T120000Z\r\n", $written);
        self::assertStringContainsString("dtend:20260119T130000Z\r\n", $written);
        self::assertStringNotContainsString('rrule', $written);
    }

    /**
     * **A component that is no series is asked as it stands**, which is the
     * answer this library gives everywhere a question cannot be asked: §4.1
     * makes a `UID` mandatory, so a component without one is invalid
     * iCalendar that a store may nonetheless hold, and a report that fell over
     * on it would answer nothing about the rest of the calendar.
     *
     * It is still held to the range, because §9.6.5 returns "only those whose
     * scheduled time intersect" it.
     */
    public function testAComponentWithoutAUidIsAskedAsItStands(): void
    {
        $nameless = "BEGIN:VEVENT\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART:20260119T120000Z\r\nDTEND:20260119T130000Z\r\nSUMMARY:Nameless\r\nEND:VEVENT\r\n";

        self::assertStringContainsString('SUMMARY:Nameless', $this->expanded(self::THIRD, $nameless));
        self::assertStringNotContainsString(
            'SUMMARY:Nameless',
            $this->expanded('start="20260301T000000Z" end="20260302T000000Z"', $nameless),
        );
    }

    /**
     * **And what follows it is still looked at.** A component the expansion
     * cannot treat as a series, and a component of a series already expanded,
     * are both reasons to go on to the next one rather than to stop: an object
     * may hold any number of them in any order (RFC 5545 §3.6).
     */
    public function testWhatFollowsSomethingItPassesOverIsStillLookedAt(): void
    {
        $nameless = "BEGIN:VEVENT\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART:20260119T080000Z\r\nDTEND:20260119T090000Z\r\nSUMMARY:Nameless\r\nEND:VEVENT\r\n";
        $later = "BEGIN:VEVENT\r\nUID:9@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART:20260119T160000Z\r\nDTEND:20260119T170000Z\r\nSUMMARY:Later that day\r\nEND:VEVENT\r\n";
        $written = $this->expanded(self::THIRD, $nameless . self::WEEKLY . self::$override . $later);

        self::assertStringContainsString('SUMMARY:Nameless', $written);
        self::assertStringContainsString('SUMMARY:Moved two hours on', $written);
        self::assertStringContainsString('SUMMARY:Later that day', $written);
    }

    /**
     * **A series this library cannot expand is asked as it stands too**, and
     * that is the one case where what comes back keeps a recurrence property
     * §9.6.5 would have gone. R-RRULE-04's hard limit is what stops it, and
     * the choice is then between a component with an `RRULE` and no answer at
     * all about the object. The memo's own cap on what a store may keep
     * (§5.2.8, `CALDAV:max-instances`) means a resource that got in through
     * this library cannot be one of these.
     *
     * **The limit is passed in rather than reached**, the way
     * {@see \DavServices\Tests\Unit\VObject\Recur\IteratorTest} asks the same
     * question: three iterations of a weekly series over a month is past it,
     * and ten thousand instances would prove nothing the three do not.
     */
    public function testASeriesPastTheIterationLimitIsAskedAsItStands(): void
    {
        $written = $this->expanded(self::JANUARY, self::WEEKLY, 3);

        self::assertStringContainsString('SUMMARY:Weekly', $written);
        self::assertStringContainsString('RRULE:FREQ=WEEKLY', $written);
        self::assertSame(1, substr_count($written, 'BEGIN:VEVENT'));
    }

    /**
     * **And an end nobody can read is a series nobody can expand.** `DTSTART`
     * is validated where a set is read (RFC 5545 §3.8.5.1), a `DTEND` is not
     * — so this is the one value that reaches the writing out as nonsense, and
     * the answer is the one every unreadable series gets: the component as it
     * stands.
     */
    public function testAnEndNobodyCanReadLeavesTheComponentAsItStands(): void
    {
        // January, which asks for an instance the series has to move to: the
        // first one moves nothing, so it is the second that reads the end. And
        // the component asked as it stands is held to the range like any
        // other, which January also covers.
        $written = $this->expanded(self::JANUARY, str_replace(
            "DTEND:20260105T130000Z\r\n",
            "DTEND:whenever\r\n",
            self::WEEKLY,
        ));

        self::assertStringContainsString('RRULE:FREQ=WEEKLY', $written);
        self::assertStringContainsString("DTEND:whenever\r\n", $written);
        self::assertSame(1, substr_count($written, 'BEGIN:VEVENT'));
    }

    /**
     * §9.6.5 makes both attributes `#REQUIRED`, and the rest of the two ends
     * is §9.9's — which {@see \DavServices\CalDav\TimeRange::ofBothEnds()}
     * already answers for the two limits beside this one.
     *
     * @param string $range What the element's attributes say, if anything
     */
    #[DataProvider('expansionsThatNameNoRange')]
    public function testAnExpansionNamesBothEnds(string $range): void
    {
        $this->expectException(BadRequest::class);

        Expander::of($this->element($range));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function expansionsThatNameNoRange(): iterable
    {
        yield 'no start' => ['end="20260201T000000Z"'];
        yield 'no end' => ['start="20260101T000000Z"'];
        yield 'neither' => [''];
        yield 'a local time' => ['start="20260101T000000" end="20260201T000000Z"'];
        yield 'an end before the start' => ['start="20260201T000000Z" end="20260101T000000Z"'];
    }

    /** An event in New York, with the zone the object carries. */
    private static string $zoned = "BEGIN:VTIMEZONE\r\nTZID:America/New_York\r\nBEGIN:STANDARD\r\n"
        . "DTSTART:19671029T020000\r\nTZOFFSETFROM:-0400\r\nTZOFFSETTO:-0500\r\n"
        . "END:STANDARD\r\nEND:VTIMEZONE\r\n"
        . "BEGIN:VEVENT\r\nUID:5@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
        . "DTSTART;TZID=America/New_York:20260105T090000\r\nDTEND;TZID=America/New_York:20260105T100000\r\n"
        . "RRULE:FREQ=DAILY;COUNT=5\r\nSUMMARY:Morning\r\nEND:VEVENT\r\n";

    /** The third Monday, moved two hours on. */
    private static string $override = "BEGIN:VEVENT\r\nUID:1@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
        . "RECURRENCE-ID:20260119T120000Z\r\nDTSTART:20260119T140000Z\r\nDTEND:20260119T150000Z\r\n"
        . "SUMMARY:Moved two hours on\r\nEND:VEVENT\r\n";

    /**
     * The object as the expansion leaves it, written out.
     *
     * @param string $range What the element's two attributes say
     * @param string $components What the object holds
     * @param int|null $iterations The hard limit to give the expansion, or
     *                             null for the one a server uses
     */
    private function expanded(string $range, string $components, ?int $iterations = null): string
    {
        $text = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\n"
            . $components . "END:VCALENDAR\r\n";
        $object = CalendarObject::in($text) ?? self::fail('The case built something that is no calendar object.');
        $expander = $iterations === null
            ? Expander::of($this->element($range))
            : Expander::of($this->element($range), $iterations);
        $expanded = $expander->appliedTo($object, new Moments($object, new DateTimeZone('UTC')));

        return (new Serializer())->write($expanded);
    }

    /**
     * One `CALDAV:expand`, read the way a client's body is read.
     */
    private function element(string $range): Element
    {
        return (new Reader())->parse(
            sprintf('<expand xmlns="urn:ietf:params:xml:ns:caldav" %s/>', $range),
        );
    }
}
