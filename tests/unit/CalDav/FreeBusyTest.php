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
use DavServices\CalDav\FreeBusy;
use DavServices\CalDav\Moments;
use DavServices\CalDav\TimeRange;
use DavServices\VObject\Serializer;
use DavServices\Xml\Reader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for the busy time of a set of objects. R-CAL-12, P6-10.
 *
 * RFC 4791 §7.10 is where the whole of this is written:
 *
 * > Only VEVENT components without a TRANSP property or with the TRANSP
 * > property set to OPAQUE, and VFREEBUSY components SHOULD be considered in
 * > generating the free busy time information.
 * >
 * > In the case of VEVENT components, the free or busy time type (FBTYPE) of
 * > the FREEBUSY properties in the returned VFREEBUSY component SHOULD be
 * > derived from the value of the TRANSP and STATUS properties, as outlined in
 * > the table below:
 *
 *     +---------------------------++------------------+
 *     |          VEVENT           ||    VFREEBUSY     |
 *     +-------------+-------------++------------------+
 *     | TRANSP      | STATUS      || FBTYPE           |
 *     +=============+=============++==================+
 *     |             | CONFIRMED   || BUSY             |
 *     |             | (default)   ||                  |
 *     | OPAQUE      +-------------++------------------+
 *     | (default)   | CANCELLED   || FREE             |
 *     |             +-------------++------------------+
 *     |             | TENTATIVE   || BUSY-TENTATIVE   |
 *     |             +-------------++------------------+
 *     |             | x-name      || BUSY or          |
 *     |             |             || x-name           |
 *     +-------------+-------------++------------------+
 *     |             | CONFIRMED   ||                  |
 *     | TRANSPARENT | CANCELLED   || FREE             |
 *     |             | TENTATIVE   ||                  |
 *     |             | x-name      ||                  |
 *     +-------------+-------------++------------------+
 *
 * > Duplicate busy time periods with the same FBTYPE parameter value SHOULD
 * > NOT be specified in the returned VFREEBUSY component. Servers SHOULD
 * > coalesce consecutive or overlapping busy time periods of the same type.
 * > Busy time periods with different FBTYPE parameter values MAY overlap.
 *
 * **And the report answers about busy time only**: "This report only returns
 * busy time information. Free time information can be inferred from the
 * returned busy time information." So every row of the table that comes to
 * `FREE` is a row that comes to nothing in the answer.
 *
 * What is tested here is the computation. The report around it — the body, the
 * `Depth` header, the status — is tested with
 * {@see \DavServices\CalDav\Report\FreeBusyQuery}.
 */
#[CoversClass(FreeBusy::class)]
final class FreeBusyTest extends TestCase
{
    /** The whole of 5.1.2026 in UTC, which most cases ask about. */
    private const DAY = 'start="20260105T000000Z" end="20260106T000000Z"';

    /** What the clock says, so that a `DTSTAMP` can be asserted. */
    private const NOW = '2026-01-01T09:00:00+00:00';

    private const NOON = '20260105T120000Z';

    private const ONE = '20260105T130000Z';

    /**
     * **RFC 5545 §3.6.4's own shape.** `dtstamp` and `uid` are REQUIRED in a
     * `VFREEBUSY`; `dtstart` and `dtend` are optional and here they say what
     * the question covered — "the 'DTSTART' and 'DTEND' properties specify an
     * inclusive time window that surrounds the busy time information".
     *
     * **The order is the memo's too**: "When present in a 'VFREEBUSY' calendar
     * component, the 'DTSTART' and 'DTEND' properties SHOULD be specified
     * prior to any 'FREEBUSY' properties."
     */
    public function testTheComponentSaysWhenItWasMadeAndWhatItCovers(): void
    {
        $written = $this->busyIn(self::event('1@example.com', self::NOON, self::ONE));

        self::assertSame(
            "BEGIN:VFREEBUSY\r\n"
            . "DTSTAMP:20260101T090000Z\r\n"
            . 'UID:' . self::uidIn($written) . "\r\n"
            . "DTSTART:20260105T000000Z\r\n"
            . "DTEND:20260106T000000Z\r\n"
            . "FREEBUSY:20260105T120000Z/20260105T130000Z\r\n"
            . "END:VFREEBUSY\r\n",
            $written,
        );
    }

    /**
     * **§7.10: "If no calendar object resources are found to satisfy these
     * conditions, a VFREEBUSY component with no FREEBUSY property MUST be
     * returned."** So an empty answer is an answer.
     */
    public function testWithNothingConsideredItCarriesNoPeriod(): void
    {
        $written = $this->busyIn('');

        self::assertStringContainsString('BEGIN:VFREEBUSY', $written);
        self::assertSame([], self::periodsIn($written));
    }

    /**
     * RFC 5545 §3.8.4.7: "The 'UID' itself MUST be a globally unique
     * identifier. The generator of the identifier MUST guarantee that the
     * identifier is unique."
     *
     * Every answer is a new one, so no two carry the same name.
     */
    public function testEveryAnswerCarriesAUniqueNameOfItsOwn(): void
    {
        $names = [];

        for ($made = 0; $made < 50; $made++) {
            $names[self::uidIn($this->busyIn(''))] = true;
        }

        self::assertCount(50, $names);
    }

    /**
     * **An end §9.9 left open is a window with no edge there**, so the
     * property that would name it is not written: "If either the 'start' or
     * 'end' attribute is not specified in the CALDAV:time-range XML element,
     * assume '-infinity' and '+infinity' as their value", and RFC 5545 §3.6.4
     * makes both properties optional.
     *
     * @param string $range What the `CALDAV:time-range` says
     * @param string $written The one property it still carries
     * @param string $absent The one it cannot
     */
    #[DataProvider('rangesOpenAtOneEnd')]
    public function testAnEndLeftOpenIsNotWrittenAsAnEdgeOfTheWindow(
        string $range,
        string $written,
        string $absent,
    ): void {
        $busy = $this->busyIn(self::event('2@example.com', self::NOON, self::ONE), $range);

        self::assertStringContainsString($written, $busy);
        self::assertStringNotContainsString($absent, $busy);
        self::assertStringContainsString('FREEBUSY:20260105T120000Z/20260105T130000Z', $busy);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function rangesOpenAtOneEnd(): iterable
    {
        yield 'no end' => ['start="20260105T000000Z"', "DTSTART:20260105T000000Z\r\n", 'DTEND'];
        yield 'no start' => ['end="20260106T000000Z"', "DTEND:20260106T000000Z\r\n", 'DTSTART'];
    }

    /**
     * **§7.10's table, row by row.** The rows that come to `FREE` come to
     * nothing at all, this report answering about busy time only.
     *
     * @param string $lines What the event carries beside its times
     * @param string|null $written What the answer says about it, or null where
     *                             it says nothing
     */
    #[DataProvider('theTableOfSectionSevenTen')]
    public function testTheTypeIsDerivedFromTranspAndStatus(string $lines, ?string $written): void
    {
        $busy = $this->busyIn(self::event('3@example.com', self::NOON, self::ONE, $lines));

        if ($written === null) {
            self::assertSame([], self::periodsIn($busy));

            return;
        }

        self::assertStringContainsString($written, $busy);
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function theTableOfSectionSevenTen(): iterable
    {
        $period = '20260105T120000Z/20260105T130000Z';

        // | OPAQUE (default) | CONFIRMED (default) || BUSY | — and RFC 5545
        // §3.2.9 makes `BUSY` the default `FBTYPE`, so saying it would say
        // what silence already means. §7.10.1's own example writes it this
        // way.
        yield 'neither property' => ['', 'FREEBUSY:' . $period];
        yield 'OPAQUE and CONFIRMED' => ["TRANSP:OPAQUE\r\nSTATUS:CONFIRMED\r\n", 'FREEBUSY:' . $period];
        yield 'CONFIRMED alone' => ["STATUS:CONFIRMED\r\n", 'FREEBUSY:' . $period];
        yield 'OPAQUE alone' => ["TRANSP:OPAQUE\r\n", 'FREEBUSY:' . $period];

        // | OPAQUE | TENTATIVE || BUSY-TENTATIVE |
        yield 'TENTATIVE' => ["STATUS:TENTATIVE\r\n", 'FREEBUSY;FBTYPE=BUSY-TENTATIVE:' . $period];

        // | OPAQUE | CANCELLED || FREE | — and free time is not returned.
        yield 'CANCELLED' => ["STATUS:CANCELLED\r\n", null];

        // | TRANSPARENT | CONFIRMED, CANCELLED, TENTATIVE, x-name || FREE |
        yield 'TRANSPARENT' => ["TRANSP:TRANSPARENT\r\n", null];
        yield 'TRANSPARENT and TENTATIVE' => ["TRANSP:TRANSPARENT\r\nSTATUS:TENTATIVE\r\n", null];
        yield 'TRANSPARENT and CONFIRMED' => ["TRANSP:TRANSPARENT\r\nSTATUS:CONFIRMED\r\n", null];

        // | OPAQUE | x-name || BUSY or x-name | — the memo offers two, and
        // RFC 5545 §3.2.9 settles which is the better: "Applications MUST
        // treat x-name and iana-token values they don't recognize the same way
        // as they would the BUSY value", so writing the status out would say
        // `BUSY` to every client that reads it and nothing more.
        yield 'a status of its own' => ["STATUS:X-SOMETHING\r\n", 'FREEBUSY:' . $period];

        // A status of a to-do or a journal entry is none of §3.8.1.11's three
        // for an event, and it is not an x-name either. It falls to the row
        // the table makes the default.
        yield 'a status that is no event status' => ["STATUS:NEEDS-ACTION\r\n", 'FREEBUSY:' . $period];

        // RFC 5545 §3.1: "All names of properties, property parameters,
        // enumerated property values, and property parameter values are
        // case-insensitive", and both values are enumerated ones (§3.8.2.7,
        // §3.8.1.11).
        yield 'written quietly' => ["transp:opaque\r\nstatus:tentative\r\n", 'FBTYPE=BUSY-TENTATIVE'];

        // §3.8.2.7: `transvalue = "OPAQUE" / "TRANSPARENT"`, so a third value
        // is no iCalendar — and §7.10 considers an event "without a TRANSP
        // property or with the TRANSP property set to OPAQUE", which this is
        // neither.
        yield 'a transparency that is neither' => ["TRANSP:MAYBE\r\n", null];
    }

    /**
     * **Only events and free/busy components are considered**, and a to-do or
     * a journal entry is neither.
     *
     * @param string $component What the object holds
     */
    #[DataProvider('componentsThatSayNothingAboutBusyTime')]
    public function testWhatIsNoEventAndNoFreeBusyIsNotConsidered(string $component): void
    {
        self::assertSame([], self::periodsIn($this->busyIn($component)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function componentsThatSayNothingAboutBusyTime(): iterable
    {
        yield 'a to-do' => ["BEGIN:VTODO\r\nUID:4@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART:20260105T120000Z\r\nDUE:20260105T130000Z\r\nSUMMARY:Something\r\nEND:VTODO\r\n"];

        yield 'a journal entry' => ["BEGIN:VJOURNAL\r\nUID:5@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART:20260105T120000Z\r\nSUMMARY:A thought\r\nEND:VJOURNAL\r\n"];
    }

    /**
     * **A `VFREEBUSY` brings its own periods and its own types**, §7.10's
     * table being "in the case of VEVENT components" and nothing else. Only
     * `FBTYPE=FREE` is left out: RFC 5545 §3.2.9 gives the parameter five
     * other values and has an application "treat x-name and iana-token values
     * they don't recognize the same way as they would the BUSY value", so
     * everything but `FREE` is busy time.
     *
     * @param string $property What the published component carries
     * @param string|null $written What the answer says about it, or null where
     *                             it says nothing
     */
    #[DataProvider('publishedPeriods')]
    public function testAFreeBusyComponentBringsItsOwnPeriods(string $property, ?string $written): void
    {
        $busy = $this->busyIn(
            "BEGIN:VFREEBUSY\r\nUID:6@example.com\r\nDTSTAMP:20260101T000000Z\r\n" . $property . "END:VFREEBUSY\r\n",
        );

        if ($written === null) {
            self::assertSame([], self::periodsIn($busy));

            return;
        }

        self::assertStringContainsString($written, $busy);
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function publishedPeriods(): iterable
    {
        $period = '20260105T120000Z/20260105T130000Z';

        yield 'no type, which is BUSY' => [
            'FREEBUSY:' . $period . "\r\n",
            'FREEBUSY:' . $period,
        ];

        yield 'busy' => [
            'FREEBUSY;FBTYPE=BUSY:' . $period . "\r\n",
            'FREEBUSY:' . $period,
        ];

        yield 'unavailable' => [
            'FREEBUSY;FBTYPE=BUSY-UNAVAILABLE:' . $period . "\r\n",
            'FREEBUSY;FBTYPE=BUSY-UNAVAILABLE:' . $period,
        ];

        yield 'tentative' => [
            'FREEBUSY;FBTYPE=BUSY-TENTATIVE:' . $period . "\r\n",
            'FREEBUSY;FBTYPE=BUSY-TENTATIVE:' . $period,
        ];

        yield 'a type of its own' => [
            'FREEBUSY;FBTYPE=X-ELSEWHERE:' . $period . "\r\n",
            'FREEBUSY;FBTYPE=X-ELSEWHERE:' . $period,
        ];

        yield 'free' => ['FREEBUSY;FBTYPE=FREE:' . $period . "\r\n", null];

        // §3.1: a parameter value is read without regard to case.
        yield 'free, written quietly' => ['FREEBUSY;FBTYPE=free:' . $period . "\r\n", null];

        // §3.8.2.6: `fbvalue = period *("," period)`, and "The 'FREEBUSY'
        // property can specify more than one value, separated by the COMMA
        // character."
        yield 'two values in one property' => [
            'FREEBUSY:' . $period . ",20260105T150000Z/20260105T160000Z\r\n",
            'FREEBUSY:20260105T150000Z/20260105T160000Z',
        ];

        // "These time periods can be specified as either a start and end
        // DATE-TIME or a start DATE-TIME and DURATION" (§3.8.2.6).
        yield 'written as a start and a length' => [
            "FREEBUSY:20260105T120000Z/PT1H\r\n",
            'FREEBUSY:' . $period,
        ];

        // A period nobody can read is no period, and an answer that fell over
        // on one would say nothing about the rest of the calendar.
        yield 'something that is no period' => ["FREEBUSY:tomorrow afternoon\r\n", null];

        yield 'one of two that is no period' => [
            "FREEBUSY:tomorrow," . $period . "\r\n",
            'FREEBUSY:' . $period,
        ];
    }

    /**
     * **A period is cut to the window it is reported in.** RFC 5545 §3.6.4
     * has the `DTSTART` and `DTEND` of a published `VFREEBUSY` "specify an
     * inclusive time window that surrounds the busy time information", so a
     * period reaching past either edge would contradict the window it sits in.
     *
     * @param string $component What the object holds
     * @param string $written The period, as the answer cuts it
     */
    #[DataProvider('periodsReachingPastAnEdge')]
    public function testAPeriodIsCutToTheWindow(string $component, string $written): void
    {
        self::assertStringContainsString($written, $this->busyIn($component));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function periodsReachingPastAnEdge(): iterable
    {
        yield 'over the start' => [
            self::event('7@example.com', '20260104T230000Z', '20260105T010000Z'),
            'FREEBUSY:20260105T000000Z/20260105T010000Z',
        ];

        yield 'over the end' => [
            self::event('8@example.com', '20260105T230000Z', '20260106T010000Z'),
            'FREEBUSY:20260105T230000Z/20260106T000000Z',
        ];

        yield 'over both' => [
            self::event('9@example.com', '20260101T000000Z', '20260201T000000Z'),
            'FREEBUSY:20260105T000000Z/20260106T000000Z',
        ];

        yield 'a published period over the end' => [
            "BEGIN:VFREEBUSY\r\nUID:10@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
            . "FREEBUSY:20260105T230000Z/PT4H\r\nEND:VFREEBUSY\r\n",
            'FREEBUSY:20260105T230000Z/20260106T000000Z',
        ];
    }

    /**
     * **And a period the cut leaves with no length is dropped.** A published
     * `VFREEBUSY` is considered as soon as one of its periods intersects the
     * range (§9.9's row for a component with no `DTSTART`), so the others come
     * here to be asked about one at a time — and one that lies wholly outside
     * the window is cut to nothing, which §3.3.9 has no way of writing.
     *
     * @param string $away The period that lies outside
     */
    #[DataProvider('publishedPeriodsOutsideTheWindow')]
    public function testAPublishedPeriodOutsideTheWindowIsDropped(string $away): void
    {
        $component = "BEGIN:VFREEBUSY\r\nUID:50@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
            . 'FREEBUSY:20260105T120000Z/20260105T130000Z,' . $away . "\r\nEND:VFREEBUSY\r\n";

        self::assertSame(
            ['FREEBUSY:20260105T120000Z/20260105T130000Z'],
            self::periodsIn($this->busyIn($component)),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publishedPeriodsOutsideTheWindow(): iterable
    {
        yield 'after the end' => ['20260201T120000Z/20260201T130000Z'];
        yield 'before the start' => ['20260101T120000Z/20260101T130000Z'];

        // §9.9's end is non-inclusive — "the 'end' attribute specifies the
        // non-inclusive end of the time range" — so a period beginning exactly
        // there is outside.
        yield 'beginning where the window ends' => ['20260106T000000Z/PT1H'];

        // And the start is inclusive, so a period ending exactly there holds
        // no moment the window does.
        yield 'ending where the window begins' => ['20260104T230000Z/20260105T000000Z'];
    }

    /**
     * And what lies outside it altogether is nothing to report.
     */
    public function testWhatLiesOutsideTheWindowSaysNothing(): void
    {
        $away = self::event('11@example.com', '20260201T120000Z', '20260201T130000Z');

        self::assertSame([], self::periodsIn($this->busyIn($away)));
    }

    /**
     * **A stretch of no length is no period.** RFC 5545 §3.3.9 carries a MUST
     * for each of the two forms a `PERIOD` has — "The start MUST be before the
     * end" and "a period of time consisting of a start and **positive**
     * duration of time" — so a busy time of nothing cannot be written at all.
     *
     * @param string $component What the object holds
     */
    #[DataProvider('eventsOfNoLength')]
    public function testAnEventOfNoLengthIsNoPeriod(string $component): void
    {
        self::assertSame([], self::periodsIn($this->busyIn($component)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function eventsOfNoLength(): iterable
    {
        // §9.9: "the duration of a VEVENT component with no DTEND and
        // DURATION properties is […] 0 seconds when the DTSTART is a DATE-TIME
        // value".
        yield 'a moment and nothing more' => ["BEGIN:VEVENT\r\nUID:12@example.com\r\n"
            . "DTSTAMP:20260101T000000Z\r\nDTSTART:20260105T120000Z\r\nSUMMARY:A moment\r\nEND:VEVENT\r\n"];

        yield 'an end at the start' => [self::event('13@example.com', self::NOON, self::NOON)];

        // §9.9: "the value of the DTEND property MUST be later in time than
        // the value of the DTSTART property", so this is no iCalendar a store
        // should hold — and there is still no stretch of time in it.
        yield 'an end before the start' => [self::event('14@example.com', self::ONE, self::NOON)];

        // §3.3.6: a duration may be negative, and a negative one reaches back.
        yield 'a length that goes backwards' => [
            self::event('15@example.com', self::NOON, '', "DURATION:-PT1H\r\n"),
        ];

        yield 'a length of nothing' => [self::event('16@example.com', self::NOON, '', "DURATION:PT0S\r\n")];
    }

    /**
     * **§9.9's own rows for an event that says its length rather than its
     * end**: "if DTEND is not present in a VEVENT, but DURATION is, then the
     * test should instead operate on the 'effective' DTEND, i.e.,
     * DTSTART+DURATION".
     */
    public function testALengthSaysWhereAnEventReaches(): void
    {
        $busy = $this->busyIn(self::event('17@example.com', self::NOON, '', "DURATION:PT90M\r\n"));

        self::assertStringContainsString('FREEBUSY:20260105T120000Z/20260105T133000Z', $busy);
    }

    /**
     * A length nobody can read leaves the event with no end to reach, which
     * §9.9's last row answers: a `DATE-TIME` start alone is 0 seconds, and
     * that is no period.
     */
    public function testALengthNobodyCanReadLeavesNoPeriod(): void
    {
        $busy = $this->busyIn(self::event('18@example.com', self::NOON, '', "DURATION:an hour or so\r\n"));

        self::assertSame([], self::periodsIn($busy));
    }

    /**
     * **§9.9: the duration of an event with neither end nor length "is 1 day
     * (+P1D) when the DTSTART is a DATE value"** — and a day is a day of the
     * zone the value is read in, not twenty-four hours of UTC.
     *
     * The 5th of January in Berlin begins an hour before midnight UTC.
     */
    public function testAnAllDayEventIsBusyForTheDayOfItsZone(): void
    {
        $allDay = "BEGIN:VEVENT\r\nUID:19@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART;VALUE=DATE:20260105\r\nSUMMARY:All of it\r\nEND:VEVENT\r\n";
        $busy = $this->busyIn($allDay, 'start="20260104T000000Z" end="20260107T000000Z"', new DateTimeZone('Europe/Berlin'));

        self::assertStringContainsString('FREEBUSY:20260104T230000Z/20260105T230000Z', $busy);
    }

    /**
     * **An event with no `DTSTART` is no period**, every row of §9.9's table
     * reading one: "as specified in [RFC2445], the DTSTART property is
     * REQUIRED in the VEVENT component".
     *
     * The answer comes from the expansion rather than from the table being
     * asked again — a series is computed from a start (RFC 5545 §3.8.5.1), so
     * such an event is dropped before its type is worked out. What is asserted
     * here is the behaviour, which is the same either way.
     */
    public function testAnEventWithoutAStartIsNoPeriod(): void
    {
        $started = "BEGIN:VEVENT\r\nUID:20@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTEND:20260105T130000Z\r\nSUMMARY:From nowhere\r\nEND:VEVENT\r\n";

        self::assertSame([], self::periodsIn($this->busyIn($started)));
    }

    /**
     * **§9.9: "Time range tests MUST consider every recurrence instance when
     * testing the time range condition"**, and §7.4 says the same of every
     * report that filters by time. An event that happens three times is busy
     * three times.
     */
    public function testEveryInstanceOfASeriesIsConsidered(): void
    {
        $series = "BEGIN:VEVENT\r\nUID:21@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART:20260105T080000Z\r\nDTEND:20260105T090000Z\r\nRRULE:FREQ=HOURLY;INTERVAL=2;COUNT=3\r\n"
            . "SUMMARY:Three of them\r\nEND:VEVENT\r\n";
        $busy = $this->busyIn($series);

        self::assertStringContainsString('FREEBUSY:20260105T080000Z/20260105T090000Z', $busy);
        self::assertStringContainsString('FREEBUSY:20260105T100000Z/20260105T110000Z', $busy);
        self::assertStringContainsString('FREEBUSY:20260105T120000Z/20260105T130000Z', $busy);
    }

    /**
     * And an instance somebody moved or called off is read from the component
     * that overrides it: §4.1 has an object hold "components that represent
     * 'overridden' instances", and the table is asked of each.
     */
    public function testAnOverriddenInstanceBringsItsOwnTimeAndStatus(): void
    {
        $series = "BEGIN:VEVENT\r\nUID:22@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART:20260105T080000Z\r\nDTEND:20260105T090000Z\r\nRRULE:FREQ=HOURLY;INTERVAL=2;COUNT=3\r\n"
            . "SUMMARY:Three of them\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:22@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
            . "RECURRENCE-ID:20260105T100000Z\r\nDTSTART:20260105T160000Z\r\nDTEND:20260105T170000Z\r\n"
            . "SUMMARY:Moved\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:22@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
            . "RECURRENCE-ID:20260105T120000Z\r\nDTSTART:20260105T120000Z\r\nDTEND:20260105T130000Z\r\n"
            . "STATUS:CANCELLED\r\nSUMMARY:Called off\r\nEND:VEVENT\r\n";
        $busy = $this->busyIn($series);

        self::assertStringContainsString('FREEBUSY:20260105T080000Z/20260105T090000Z', $busy);
        self::assertStringContainsString('FREEBUSY:20260105T160000Z/20260105T170000Z', $busy);
        self::assertStringNotContainsString('20260105T100000Z', $busy);
        self::assertStringNotContainsString('20260105T120000Z', $busy);
    }

    /**
     * **A series too long to write out is considered as the component it comes
     * from.** R-RRULE-04 gives every expansion a hard limit, and an object
     * that passes it is answered by what it says of itself rather than not at
     * all — the memo's own cap on what a store may keep (§5.2.8's
     * `CALDAV:max-instances`) means a resource that got in through this
     * library cannot be one of these.
     */
    public function testASeriesTooLongToWriteOutIsConsideredAsItStands(): void
    {
        $series = "BEGIN:VEVENT\r\nUID:23@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART:20260105T080000Z\r\nDTEND:20260105T090000Z\r\nRRULE:FREQ=MINUTELY;COUNT=600\r\n"
            . "SUMMARY:Far too many\r\nEND:VEVENT\r\n";
        $busy = $this->busyIn($series, self::DAY, null, 3);

        self::assertCount(1, self::periodsIn($busy));
        self::assertStringContainsString('FREEBUSY:20260105T080000Z/20260105T090000Z', $busy);
    }

    /**
     * **§7.10: "Servers SHOULD coalesce consecutive or overlapping busy time
     * periods of the same type"**, and "Duplicate busy time periods with the
     * same FBTYPE parameter value SHOULD NOT be specified in the returned
     * VFREEBUSY component."
     *
     * @param string $components What the object holds
     * @param int $periods How many `FREEBUSY` properties the answer carries
     * @param string $written One of them, as the answer writes it
     */
    #[DataProvider('periodsThatComeTogetherOrDoNot')]
    public function testPeriodsOfTheSameTypeAreCoalesced(string $components, int $periods, string $written): void
    {
        $busy = $this->busyIn($components);

        self::assertSame($periods, count(self::periodsIn($busy)), $busy);
        self::assertStringContainsString($written, $busy);
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function periodsThatComeTogetherOrDoNot(): iterable
    {
        yield 'overlapping' => [
            self::event('24@example.com', '20260105T090000Z', '20260105T110000Z')
                . self::event('25@example.com', '20260105T100000Z', '20260105T120000Z'),
            1,
            'FREEBUSY:20260105T090000Z/20260105T120000Z',
        ];

        yield 'one inside the other' => [
            self::event('26@example.com', '20260105T090000Z', '20260105T150000Z')
                . self::event('27@example.com', '20260105T100000Z', '20260105T110000Z'),
            1,
            'FREEBUSY:20260105T090000Z/20260105T150000Z',
        ];

        // **And the same pair the other way round in the object**, because
        // the periods are brought together in the order of their starts and
        // not in the order they were read. One that encloses the open stretch
        // has to reach back to where that stretch began.
        yield 'one inside the other, the larger second' => [
            self::event('55@example.com', '20260105T100000Z', '20260105T110000Z')
                . self::event('56@example.com', '20260105T090000Z', '20260105T150000Z'),
            1,
            'FREEBUSY:20260105T090000Z/20260105T150000Z',
        ];

        yield 'consecutive' => [
            self::event('28@example.com', '20260105T090000Z', '20260105T100000Z')
                . self::event('29@example.com', '20260105T100000Z', '20260105T110000Z'),
            1,
            'FREEBUSY:20260105T090000Z/20260105T110000Z',
        ];

        yield 'the same period twice' => [
            self::event('30@example.com', '20260105T090000Z', '20260105T100000Z')
                . self::event('31@example.com', '20260105T090000Z', '20260105T100000Z'),
            1,
            'FREEBUSY:20260105T090000Z/20260105T100000Z',
        ];

        yield 'three in a chain' => [
            self::event('32@example.com', '20260105T090000Z', '20260105T100000Z')
                . self::event('33@example.com', '20260105T093000Z', '20260105T110000Z')
                . self::event('34@example.com', '20260105T104500Z', '20260105T120000Z'),
            1,
            'FREEBUSY:20260105T090000Z/20260105T120000Z',
        ];

        yield 'apart' => [
            self::event('35@example.com', '20260105T090000Z', '20260105T100000Z')
                . self::event('36@example.com', '20260105T140000Z', '20260105T150000Z'),
            2,
            'FREEBUSY:20260105T140000Z/20260105T150000Z',
        ];

        // "Busy time periods with different FBTYPE parameter values MAY
        // overlap", so they are not brought together.
        yield 'overlapping, of different types' => [
            self::event('37@example.com', '20260105T090000Z', '20260105T110000Z')
                . self::event('38@example.com', '20260105T100000Z', '20260105T120000Z', "STATUS:TENTATIVE\r\n"),
            2,
            'FREEBUSY;FBTYPE=BUSY-TENTATIVE:20260105T100000Z/20260105T120000Z',
        ];

        // And neither are two that merely touch across types.
        yield 'consecutive, of different types' => [
            self::event('39@example.com', '20260105T090000Z', '20260105T100000Z')
                . self::event('40@example.com', '20260105T100000Z', '20260105T110000Z', "STATUS:TENTATIVE\r\n"),
            2,
            'FREEBUSY;FBTYPE=BUSY-TENTATIVE:20260105T100000Z/20260105T110000Z',
        ];
    }

    /**
     * **RFC 5545 §3.8.2.6: "'FREEBUSY' properties within the 'VFREEBUSY'
     * calendar component SHOULD be sorted in ascending order, based on start
     * time and then end time, with the earliest periods first."**
     *
     * Which is also how §7.10.1's own example reads: the tentative hour at
     * three in the afternoon before the busy one at seven.
     *
     * **The case is built so that the ends cannot stand in for the starts.**
     * The unavailable stretch begins second and ends last, so an order taken
     * from the ends would put it at the back; and nothing in the object is
     * written in the order it comes out in.
     */
    public function testPeriodsAreWrittenEarliestFirst(): void
    {
        $components = self::event('41@example.com', '20260105T100000Z', '20260105T110000Z')
            . "BEGIN:VFREEBUSY\r\nUID:42@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
            . "FREEBUSY;FBTYPE=BUSY-UNAVAILABLE:20260105T090000Z/20260105T180000Z\r\nEND:VFREEBUSY\r\n"
            . self::event('43@example.com', '20260105T080000Z', '20260105T090000Z', "STATUS:TENTATIVE\r\n");

        self::assertSame(
            [
                'FREEBUSY;FBTYPE=BUSY-TENTATIVE:20260105T080000Z/20260105T090000Z',
                'FREEBUSY;FBTYPE=BUSY-UNAVAILABLE:20260105T090000Z/20260105T180000Z',
                'FREEBUSY:20260105T100000Z/20260105T110000Z',
            ],
            self::periodsIn($this->busyIn($components)),
        );
    }

    /**
     * **And where the stretch is the very same, the type breaks the tie.**
     * §3.8.2.6 orders by start and then by end, which leaves two periods of
     * one stretch unordered — and §7.10 expects exactly that to happen, "Busy
     * time periods with different FBTYPE parameter values MAY overlap". So
     * something has to decide, and the type does, in order that an answer
     * never depends on which object the calendar was read from first.
     *
     * The three are written in the object in an order that is neither the one
     * they come out in nor its reverse.
     */
    public function testTwoOfTheSameStretchAreOrderedByTheirType(): void
    {
        $components = self::event('44@example.com', '20260105T080000Z', '20260105T090000Z', "STATUS:TENTATIVE\r\n")
            . self::event('45@example.com', '20260105T080000Z', '20260105T090000Z')
            . "BEGIN:VFREEBUSY\r\nUID:46@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
            . "FREEBUSY;FBTYPE=BUSY-UNAVAILABLE:20260105T080000Z/20260105T090000Z\r\nEND:VFREEBUSY\r\n";

        self::assertSame(
            [
                'FREEBUSY:20260105T080000Z/20260105T090000Z',
                'FREEBUSY;FBTYPE=BUSY-TENTATIVE:20260105T080000Z/20260105T090000Z',
                'FREEBUSY;FBTYPE=BUSY-UNAVAILABLE:20260105T080000Z/20260105T090000Z',
            ],
            self::periodsIn($this->busyIn($components)),
        );
    }

    /**
     * **RFC 5545 §3.1: "All names of properties, property parameters,
     * enumerated property values, and property parameter values are
     * case-insensitive"** — and §3.6 writes the component names in the same
     * grammar, which RFC 5234 §2.3 makes case-insensitive too. An object
     * written quietly says exactly what a loud one says.
     *
     * @param string $component What the object holds
     * @param string $written The period the answer takes from it
     */
    #[DataProvider('componentsWrittenQuietly')]
    public function testAComponentNameIsReadWithoutRegardToCase(string $component, string $written): void
    {
        self::assertSame([$written], self::periodsIn($this->busyIn($component)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function componentsWrittenQuietly(): iterable
    {
        yield 'an event' => [
            "BEGIN:vevent\r\nUID:51@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART:20260105T120000Z\r\nDTEND:20260105T130000Z\r\nSUMMARY:Quiet\r\nEND:vevent\r\n",
            'FREEBUSY:20260105T120000Z/20260105T130000Z',
        ];

        yield 'a published set' => [
            "BEGIN:vfreebusy\r\nUID:52@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
            . "FREEBUSY:20260105T120000Z/PT1H\r\nEND:vfreebusy\r\n",
            'FREEBUSY:20260105T120000Z/20260105T130000Z',
        ];
    }

    /**
     * **A free period among busy ones is left out, and the rest are not.**
     * §3.8.2.6 lets a `VFREEBUSY` carry one `FREEBUSY` property per type, so
     * the free one may sit anywhere among them — including first, where a
     * reader that stopped instead of skipping would lose everything after it.
     */
    public function testAFreePeriodDoesNotStopTheOnesAfterIt(): void
    {
        $component = "BEGIN:VFREEBUSY\r\nUID:53@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
            . "FREEBUSY;FBTYPE=FREE:20260105T080000Z/PT1H\r\n"
            . "FREEBUSY:20260105T120000Z/PT1H\r\n"
            . "FREEBUSY;FBTYPE=FREE:20260105T140000Z/PT1H\r\n"
            . "FREEBUSY;FBTYPE=BUSY-TENTATIVE:20260105T160000Z/PT1H\r\n"
            . "END:VFREEBUSY\r\n";

        self::assertSame(
            [
                'FREEBUSY:20260105T120000Z/20260105T130000Z',
                'FREEBUSY;FBTYPE=BUSY-TENTATIVE:20260105T160000Z/20260105T170000Z',
            ],
            self::periodsIn($this->busyIn($component)),
        );
    }

    /**
     * **§9.9 reads the `DTEND` first**: "if DTEND is not present in a VEVENT,
     * but DURATION is, then the test should instead operate on the 'effective'
     * DTEND". RFC 5545 §3.6.1 forbids an event from carrying both — "either
     * 'dtend' or 'duration' MAY appear in a 'eventprop', but 'dtend' and
     * 'duration' MUST NOT occur in the same 'eventprop'" — so an object that
     * does is no iCalendar a store should hold, and the memo's own order is
     * the nearest thing to an answer it offers.
     */
    public function testAnEventCarryingBothAnEndAndALengthIsReadByItsEnd(): void
    {
        $both = self::event('54@example.com', self::NOON, self::ONE, "DURATION:PT8H\r\n");

        self::assertSame(['FREEBUSY:20260105T120000Z/20260105T130000Z'], self::periodsIn($this->busyIn($both)));
    }

    /**
     * **§7.3 settles which zone a floating value is read in**, and for this
     * report the memo names the source itself: "For the CALDAV:free-busy-query
     * REPORT, CalDAV servers MUST rely on the value of the
     * CALDAV:calendar-timezone property, if defined, to compute the proper
     * FREEBUSY time period value as date with UTC time for calendar components
     * scheduled with 'floating date' or 'floating time'."
     *
     * Which collection's property that is, and what happens where none is
     * defined, is {@see \DavServices\CalDav\Report\FloatingZone}'s question.
     * What is settled here is that the zone it answers is the one used: the
     * lunch floats at noon, and in New York that is five in the afternoon UTC.
     */
    public function testWhatFloatsIsReadInTheZoneItIsGiven(): void
    {
        $floating = "BEGIN:VEVENT\r\nUID:45@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART:20260105T120000\r\nDTEND:20260105T130000\r\nSUMMARY:Lunch\r\nEND:VEVENT\r\n";
        $busy = $this->busyIn($floating, self::DAY, new DateTimeZone('America/New_York'));

        self::assertStringContainsString('FREEBUSY:20260105T170000Z/20260105T180000Z', $busy);
    }

    /**
     * Every component of an object is considered, not the first alone — §4.1
     * has a calendar object resource hold "one or more calendar components",
     * and a recurrence set is only one of the shapes that takes.
     */
    public function testEveryComponentOfAnObjectIsConsidered(): void
    {
        $components = self::event('46@example.com', '20260105T080000Z', '20260105T090000Z')
            . "BEGIN:VFREEBUSY\r\nUID:47@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
            . "FREEBUSY:20260105T140000Z/PT1H\r\nEND:VFREEBUSY\r\n";
        $busy = $this->busyIn($components);

        self::assertStringContainsString('FREEBUSY:20260105T080000Z/20260105T090000Z', $busy);
        self::assertStringContainsString('FREEBUSY:20260105T140000Z/20260105T150000Z', $busy);
    }

    /**
     * And every object, one after another: a calendar is read a resource at a
     * time, and the busy time of a day is the busy time of all of them.
     */
    public function testWhatSeveralObjectsSayIsBroughtTogether(): void
    {
        $busy = new FreeBusy(self::range(self::DAY));

        $hours = [
            '48@example.com' => ['20260105T080000Z', '20260105T090000Z'],
            '49@example.com' => ['20260105T100000Z', '20260105T110000Z'],
        ];

        foreach ($hours as $uid => [$from, $until]) {
            $object = CalendarObject::in(self::objectOf(self::event($uid, $from, $until)))
                ?? self::fail('The case built something that is no calendar object.');

            $busy->consider($object, new Moments($object, new DateTimeZone('UTC')));
        }

        $written = (new Serializer())->write($busy->component(new DateTimeImmutable(self::NOW)));

        self::assertStringContainsString('FREEBUSY:20260105T080000Z/20260105T090000Z', $written);
        self::assertStringContainsString('FREEBUSY:20260105T100000Z/20260105T110000Z', $written);
    }

    /**
     * The busy time of one object over a range, as text.
     *
     * @param string $components What the object holds, or nothing for a
     *                           question nobody had an answer to
     * @param string $range What the `CALDAV:time-range` says
     * @param DateTimeZone|null $zone What a floating value is read in
     * @param int|null $iterations The hard limit the expansion is given
     */
    private function busyIn(
        string $components,
        string $range = self::DAY,
        ?DateTimeZone $zone = null,
        ?int $iterations = null,
    ): string {
        $busy = $iterations === null
            ? new FreeBusy(self::range($range))
            : new FreeBusy(self::range($range), $iterations);

        if ($components !== '') {
            $object = CalendarObject::in(self::objectOf($components))
                ?? self::fail('The case built something that is no calendar object.');

            $busy->consider($object, new Moments($object, $zone ?? new DateTimeZone('UTC')));
        }

        return (new Serializer())->write($busy->component(new DateTimeImmutable(self::NOW)));
    }

    /**
     * One `CALDAV:time-range`, read the way a client's body is read.
     */
    private static function range(string $written): TimeRange
    {
        return TimeRange::of((new Reader())->parse(
            sprintf('<time-range xmlns="urn:ietf:params:xml:ns:caldav" %s/>', $written),
        ));
    }

    /**
     * One event, busy for as long as the two times say.
     *
     * @param string $until What it ends at, or nothing where the lines say how
     *                      long it lasts instead
     * @param string $lines What it carries beside its times
     */
    private static function event(string $uid, string $from, string $until, string $lines = ''): string
    {
        return "BEGIN:VEVENT\r\nUID:" . $uid . "\r\nDTSTAMP:20260101T000000Z\r\n"
            . 'DTSTART:' . $from . "\r\n"
            . ($until === '' ? '' : 'DTEND:' . $until . "\r\n")
            . $lines . "SUMMARY:An hour\r\nEND:VEVENT\r\n";
    }

    private static function objectOf(string $components): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\n"
            . $components . "END:VCALENDAR\r\n";
    }

    /**
     * The name the answer gave itself.
     */
    private static function uidIn(string $written): string
    {
        if (preg_match('/^UID:([0-9a-f]{32})\r$/m', $written, $found) !== 1) {
            self::fail('The answer gave itself no name of thirty-two hexadecimal digits: ' . $written);
        }

        return $found[1];
    }

    /**
     * Every `FREEBUSY` property of an answer, in the order it writes them.
     *
     * @return list<string>
     */
    private static function periodsIn(string $written): array
    {
        preg_match_all('/^FREEBUSY.*$/m', str_replace("\r\n", "\n", $written), $found);

        return $found[0];
    }
}
