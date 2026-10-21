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
use DavServices\CalDav\CalendarData;
use DavServices\CalDav\CalendarObject;
use DavServices\CalDav\Limits;
use DavServices\CalDav\Moments;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use DavServices\VObject\Component;
use DavServices\Xml\Element;
use DavServices\Xml\Reader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for `CALDAV:calendar-data` as a report reads and writes it
 * (RFC 4791 §9.6). R-CAL-10, R-CAL-13. P6-06, P6-07.
 *
 * The element has a shape per direction, and two of the three are here:
 *
 *     <!ELEMENT calendar-data (comp?,
 *                              (expand | limit-recurrence-set)?,
 *                              limit-freebusy-set?)>   in a request
 *     <!ELEMENT calendar-data (#PCDATA)>               in a response
 *     <!ATTLIST calendar-data content-type CDATA "text/calendar"
 *                             version CDATA "2.0">
 *
 * The third is the empty one inside `CALDAV:supported-calendar-data`, which
 * {@see \DavServices\Plugin\CalDav} writes and nobody reads.
 *
 * Which parts of a component come back is tested where that lives, under
 * {@see \DavServices\CalDav\CalendarDataFilter}. What is tested here is the
 * element around it: the attributes, the shape, and the two limits of §9.6.6
 * and §9.6.7.
 */
#[CoversClass(CalendarData::class)]
final class CalendarDataTest extends TestCase
{
    private const MEETING = "BEGIN:VEVENT\r\nUID:1@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
        . "DTSTART:20260105T120000Z\r\nDTEND:20260105T130000Z\r\nSUMMARY:A Meeting\r\nEND:VEVENT\r\n";

    /** A weekly series, 5.1.2026 to 2.2.2026, noon to one. */
    private const WEEKLY = "BEGIN:VEVENT\r\nUID:7@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
        . "DTSTART:20260105T120000Z\r\nDTEND:20260105T130000Z\r\nRRULE:FREQ=WEEKLY;COUNT=5\r\n"
        . "SUMMARY:Weekly\r\nEND:VEVENT\r\n";

    /** The instance of 12.1. moved to June: its original time is January. */
    private const MOVED = "BEGIN:VEVENT\r\nUID:7@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
        . "RECURRENCE-ID:20260112T120000Z\r\nDTSTART:20260601T120000Z\r\nDTEND:20260601T130000Z\r\n"
        . "SUMMARY:Moved to June\r\nEND:VEVENT\r\n";

    /** The instance of 26.1., moved two hours on the same day. */
    private const SHIFTED = "BEGIN:VEVENT\r\nUID:7@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
        . "RECURRENCE-ID:20260126T120000Z\r\nDTSTART:20260126T140000Z\r\nDTEND:20260126T150000Z\r\n"
        . "SUMMARY:Later that day\r\nEND:VEVENT\r\n";

    /** An override that speaks for every instance from 19.1. onwards. */
    private const ONWARDS = "BEGIN:VEVENT\r\nUID:7@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
        . "RECURRENCE-ID;RANGE=THISANDFUTURE:20260119T120000Z\r\n"
        . "DTSTART:20260119T160000Z\r\nDTEND:20260119T170000Z\r\nSUMMARY:From now on\r\nEND:VEVENT\r\n";

    /** A time zone: no `UID`, no `RECURRENCE-ID`, and no part of any series. */
    private const ZONE = "BEGIN:VTIMEZONE\r\nTZID:America/New_York\r\nBEGIN:STANDARD\r\n"
        . "DTSTART:19671029T020000\r\nTZOFFSETFROM:-0400\r\nTZOFFSETTO:-0500\r\n"
        . "END:STANDARD\r\nEND:VTIMEZONE\r\n";

    private const FREE_BUSY = "BEGIN:VFREEBUSY\r\nUID:8@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
        . "FREEBUSY:20260105T100000Z/20260105T110000Z,20260105T121500Z/20260105T124500Z\r\n"
        . "FREEBUSY;FBTYPE=BUSY-TENTATIVE:20260105T140000Z/20260105T150000Z\r\n"
        . "END:VFREEBUSY\r\n";

    /** Noon to one on 5.1.2026, as the two limits write a range. */
    private const NOON = 'start="20260105T120000Z" end="20260105T130000Z"';

    /**
     * **§9.6, the third shape: the element carries "the content of a calendar
     * object resource".**
     *
     * And in its entirety, which is the same section's rule for a request that
     * named no part of it: "If the CALDAV:calendar-data XML element doesn't
     * contain any CALDAV:comp element, calendar object resources will be
     * returned in their entirety."
     */
    public function testItWritesTheWholeObjectIntoTheElement(): void
    {
        $element = $this->data()->elementFor(...$this->object(self::MEETING));

        self::assertSame(CalendarData::NAME, $element->name());
        self::assertStringContainsString("BEGIN:VCALENDAR\r\n", $element->text());
        self::assertStringContainsString("UID:1@example.com\r\n", $element->text());
        self::assertStringContainsString("SUMMARY:A Meeting\r\n", $element->text());
        self::assertStringContainsString("END:VCALENDAR\r\n", $element->text());
    }

    /**
     * **And only the parts a `CALDAV:comp` names**, which is the other half of
     * the sentence above. What those parts are is
     * {@see \DavServices\CalDav\CalendarDataFilter}'s business; that it is
     * asked at all is this one's.
     */
    public function testACompNarrowsWhatComesBack(): void
    {
        $written = $this->applied(
            '<comp name="VCALENDAR"><prop name="VERSION"/>'
            . '<comp name="VEVENT"><prop name="SUMMARY"/></comp></comp>',
            self::MEETING,
        );

        self::assertStringContainsString("VERSION:2.0\r\n", $written);
        self::assertStringContainsString("SUMMARY:A Meeting\r\n", $written);
        self::assertStringNotContainsString('PRODID', $written);
        self::assertStringNotContainsString('DTSTART', $written);
    }

    /**
     * **§9.6.6: "the server MUST return, in addition to the 'master
     * component', only the 'overridden components' that impact a specified
     * time range."**
     *
     * The series is weekly from 5.1.2026; one instance is moved to June and
     * one is moved two hours on its own day. The limit therefore has three
     * questions to answer, and the master is not one of them: it is returned
     * whatever the range.
     *
     * @param string $range What the limit's two attributes say
     * @param list<string> $kept The summaries that come back
     * @param list<string> $dropped The summaries that do not
     */
    #[DataProvider('rangesAndTheOverridesTheyKeep')]
    public function testTheRecurrenceLimitKeepsOnlyTheOverridesThatImpactTheRange(
        string $range,
        array $kept,
        array $dropped,
    ): void {
        $written = $this->applied(
            sprintf('<limit-recurrence-set %s/>', $range),
            self::WEEKLY . self::MOVED . self::SHIFTED,
        );

        self::assertStringContainsString('SUMMARY:Weekly', $written, 'the master is always returned');

        foreach ($kept as $summary) {
            self::assertStringContainsString($summary, $written);
        }

        foreach ($dropped as $summary) {
            self::assertStringNotContainsString($summary, $written);
        }
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function rangesAndTheOverridesTheyKeep(): iterable
    {
        // "if its current start and end times overlap the time range"
        yield 'where the moved instance now is' => [
            'start="20260601T000000Z" end="20260602T000000Z"',
            ['Moved to June'],
            ['Later that day'],
        ];

        // "or if the original start and end times -- the ones that would have
        // been used if the instance were not overridden -- overlap the time
        // range"
        yield 'where the moved instance would have been' => [
            'start="20260112T120000Z" end="20260112T130000Z"',
            ['Moved to June'],
            ['Later that day'],
        ];

        yield 'where the shifted instance now is' => [
            'start="20260126T140000Z" end="20260126T150000Z"',
            ['Later that day'],
            ['Moved to June'],
        ];

        yield 'where the shifted instance would have been' => [
            'start="20260126T120000Z" end="20260126T130000Z"',
            ['Later that day'],
            ['Moved to June'],
        ];

        yield 'where neither of them is' => [
            'start="20260301T000000Z" end="20260302T000000Z"',
            [],
            ['Moved to June', 'Later that day'],
        ];
    }

    /**
     * **§9.6.6 on the `RANGE` parameter:** "Overridden components that have a
     * RANGE parameter on their RECURRENCE-ID property may specify one or more
     * instances in the recurrence set, and some of those instances may fall
     * within the specified time range or may have originally fallen within the
     * specified time range prior to being overridden. If that is the case, the
     * overridden component MUST be included in the results, as it has a direct
     * impact on the interpretation of instances within the specified time
     * range."
     *
     * Such an override speaks for every instance from its own identifier
     * onwards (RFC 5545 §3.2.13), so a range that reaches that far may hold
     * one of them — and the memo's own test is "may fall", which is why the
     * reach is what is asked rather than the expansion.
     *
     * @param string $range What the limit's two attributes say
     * @param bool $kept Whether the override comes back
     */
    #[DataProvider('rangesAroundAnOverrideThatSpeaksForWhatFollows')]
    public function testAnOverrideThatSpeaksForWhatFollowsIsKeptWhereTheRangeReachesIt(
        string $range,
        bool $kept,
    ): void {
        $written = $this->applied(
            sprintf('<limit-recurrence-set %s/>', $range),
            self::WEEKLY . self::ONWARDS,
        );

        self::assertSame($kept, str_contains($written, 'SUMMARY:From now on'));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function rangesAroundAnOverrideThatSpeaksForWhatFollows(): iterable
    {
        yield 'a range before it' => ['start="20260105T000000Z" end="20260106T000000Z"', false];

        // §9.9's end is non-inclusive — "the 'end' attribute specifies the
        // non-inclusive end of the time range" — so a range that stops exactly
        // where the override begins holds no instance of it.
        yield 'a range ending exactly where it begins' => [
            'start="20260105T120000Z" end="20260119T120000Z"',
            false,
        ];
        yield 'a range on it' => ['start="20260119T120000Z" end="20260119T130000Z"', true];
        yield 'a range long after it' => ['start="20270101T000000Z" end="20270102T000000Z"', true];
    }

    /**
     * **Everything that is no overridden component comes back.** §9.6.6 limits
     * "the 'overridden components'" and says the master is returned "in
     * addition" — so a time zone, and an event of another series, are not
     * what the sentence is about.
     */
    public function testWhatIsNoOverrideIsNotLimited(): void
    {
        // The time zone comes first, so that the walk for the master of the
        // override meets a component with no `UID` at all on the way.
        $written = $this->applied(
            '<limit-recurrence-set start="20260301T000000Z" end="20260302T000000Z"/>',
            self::ZONE . self::WEEKLY . self::MOVED . self::MEETING,
        );

        self::assertStringContainsString('SUMMARY:Weekly', $written);
        self::assertStringContainsString('SUMMARY:A Meeting', $written);
        self::assertStringContainsString("BEGIN:VTIMEZONE\r\n", $written);
        self::assertStringNotContainsString('Moved to June', $written);
    }

    /**
     * **And neither are the object's own properties.** §9.6.6 limits "the
     * 'overridden components'", and §9.6.7 "the FREEBUSY property values of a
     * VFREEBUSY component" — so what the `VCALENDAR` itself carries is no part
     * of either sentence, and a limit that took it away would answer with
     * calendar data no client could read as one.
     *
     * @param string $limit The limit element, as a client writes it
     * @param string $components What the object holds
     */
    #[DataProvider('bothLimits')]
    public function testTheObjectsOwnPropertiesSurviveALimit(string $limit, string $components): void
    {
        $written = $this->applied($limit, $components);

        self::assertStringContainsString("VERSION:2.0\r\n", $written);
        self::assertStringContainsString('PRODID:', $written);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bothLimits(): iterable
    {
        yield 'on the recurrences' => [
            '<limit-recurrence-set start="20260301T000000Z" end="20260302T000000Z"/>',
            self::WEEKLY . self::MOVED,
        ];

        yield 'on the free/busy' => [
            sprintf('<limit-freebusy-set %s/>', self::NOON),
            self::FREE_BUSY,
        ];
    }

    /**
     * **The master is found wherever it stands.** RFC 5545 §3.6 fixes no order
     * among the components of an object, so a client may write the override
     * before the series it overrides — and a walk that gave up at the first
     * override it met would then find no master and judge the override by half
     * of §9.6.6's sentence.
     */
    public function testTheMasterIsFoundWhereverItStands(): void
    {
        $written = $this->applied(
            '<limit-recurrence-set start="20260112T120000Z" end="20260112T130000Z"/>',
            self::MOVED . self::WEEKLY,
        );

        self::assertStringContainsString('Moved to June', $written);
    }

    /**
     * **An override without a `UID` belongs to no series**, so it has no
     * original time either. §4.1 makes the property mandatory — "calendar
     * object resources MUST contain […] a UID property" — so this is invalid
     * iCalendar that a store may nonetheless hold, and the answer is the one
     * §9.6.6's first half gives: its own times.
     */
    public function testAnOverrideWithoutAUidHasNoMaster(): void
    {
        $nameless = self::WEEKLY . str_replace("UID:7@example.com\r\n", '', self::MOVED);

        self::assertStringContainsString(
            'Moved to June',
            $this->applied('<limit-recurrence-set start="20260601T000000Z" end="20260602T000000Z"/>', $nameless),
        );
        self::assertStringNotContainsString(
            'Moved to June',
            $this->applied('<limit-recurrence-set start="20260112T120000Z" end="20260112T130000Z"/>', $nameless),
        );
    }

    /**
     * **An override without a master has no original time**, and nothing to
     * measure one against: RFC 4791 §4.1 lets an object "just contain
     * components that represent 'overridden' instances", so this is a resource
     * a store may hold. What is left is the override's own times, which is the
     * first half of §9.6.6's sentence.
     */
    public function testAnOverrideWithoutAMasterIsJudgedByItsOwnTimes(): void
    {
        $orphan = self::MOVED;

        self::assertStringContainsString(
            'Moved to June',
            $this->applied('<limit-recurrence-set start="20260601T000000Z" end="20260602T000000Z"/>', $orphan),
        );
        self::assertStringNotContainsString(
            'Moved to June',
            $this->applied('<limit-recurrence-set start="20260112T120000Z" end="20260112T130000Z"/>', $orphan),
        );
    }

    /**
     * **The master of another series is passed over.** RFC 4791 §4.1 knows one
     * master "per resource" for a given `UID`, and an object may hold more
     * than one series — so the component that defines *this* override's
     * instances is the one that shares its `UID`, and a master found first is
     * not it.
     */
    public function testTheMasterOfAnotherSeriesIsPassedOver(): void
    {
        $written = $this->applied(
            '<limit-recurrence-set start="20260112T120000Z" end="20260112T130000Z"/>',
            self::MEETING . self::WEEKLY . self::MOVED,
        );

        self::assertStringContainsString('Moved to June', $written);
    }

    /**
     * **Where either time is no moment, the override is judged by its own.**
     * Two ways to get there, and both are data a store may hold: a
     * `RECURRENCE-ID` nobody can read, and a master whose own start falls in
     * an hour the clocks skip (RFC 5545 §3.3.10's "nonexistent local time").
     *
     * In both cases §9.6.6's second half — "the original start and end times
     * -- the ones that would have been used if the instance were not
     * overridden" — has nothing to measure, and the first half is what is
     * left.
     *
     * @param string $components What the object holds
     */
    #[DataProvider('seriesWithNoOriginalTimeToMeasure')]
    public function testAnOverrideWhoseOriginalTimeIsNoMomentIsJudgedByItsOwn(string $components): void
    {
        // June, where the override itself now is.
        self::assertStringContainsString(
            'Moved to June',
            $this->applied('<limit-recurrence-set start="20260601T000000Z" end="20260602T000000Z"/>', $components),
        );

        // January, where it would have been, had anybody been able to say so.
        self::assertStringNotContainsString(
            'Moved to June',
            $this->applied('<limit-recurrence-set start="20260112T120000Z" end="20260112T130000Z"/>', $components),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function seriesWithNoOriginalTimeToMeasure(): iterable
    {
        yield 'an identifier nobody can read' => [
            self::WEEKLY . str_replace(
                "RECURRENCE-ID:20260112T120000Z\r\n",
                "RECURRENCE-ID:whenever\r\n",
                self::MOVED,
            ),
        ];

        // Half past two on 8 March 2026 in New York: the clocks go forward at
        // two, so the master's own start is a wall clock that never comes.
        yield 'a master whose own start is no moment' => [
            str_replace(
                "DTSTART:20260105T120000Z\r\nDTEND:20260105T130000Z\r\n",
                "DTSTART;TZID=America/New_York:20260308T023000\r\n"
                    . "DTEND;TZID=America/New_York:20260308T033000\r\n",
                self::WEEKLY,
            ) . self::MOVED,
        ];
    }

    /**
     * **§9.6.7: "the server MUST only return the FREEBUSY property values of a
     * VFREEBUSY component that intersects a specified time range."**
     *
     * The values, not the properties: a `FREEBUSY` value is a list
     * (RFC 5545 §3.8.2.6: `fbvalue = period *("," period)`), so what is left
     * of a property is written back as one, and a property with nothing left
     * is not returned at all.
     */
    public function testTheFreeBusyLimitKeepsOnlyThePeriodsThatIntersect(): void
    {
        $written = $this->applied(sprintf('<limit-freebusy-set %s/>', self::NOON), self::FREE_BUSY);

        self::assertStringContainsString("FREEBUSY:20260105T121500Z/20260105T124500Z\r\n", $written);
        self::assertStringNotContainsString('20260105T100000Z', $written);
        self::assertStringNotContainsString('BUSY-TENTATIVE', $written);
        self::assertStringContainsString("BEGIN:VFREEBUSY\r\n", $written);
        self::assertStringContainsString("UID:8@example.com\r\n", $written);
    }

    /**
     * And it limits nothing else: §9.6.7 names the `FREEBUSY` property values
     * of a `VFREEBUSY` component, so an event in the same object keeps every
     * date it has.
     */
    public function testTheFreeBusyLimitLeavesEverythingElseAlone(): void
    {
        $written = $this->applied(sprintf('<limit-freebusy-set %s/>', self::NOON), self::FREE_BUSY . self::MEETING);

        self::assertStringContainsString("DTSTART:20260105T120000Z\r\n", $written);
        self::assertStringContainsString('SUMMARY:A Meeting', $written);
    }

    /**
     * **And what is nested inside a `VFREEBUSY` is passed through.** RFC 5545
     * §3.6.4 writes no component inside one — `freebusyc = "BEGIN" ":"
     * "VFREEBUSY" CRLF fbprop "END" ":" "VFREEBUSY" CRLF` — so a store that
     * holds one holds something the grammar does not admit, and §9.6.7
     * licenses the server to leave out `FREEBUSY` values and nothing else.
     */
    public function testWhatIsNestedInsideAFreeBusyComponentIsPassedThrough(): void
    {
        $odd = str_replace(
            "END:VFREEBUSY\r\n",
            "BEGIN:X-SOMETHING\r\nX-WHAT:a component no grammar puts here\r\nEND:X-SOMETHING\r\nEND:VFREEBUSY\r\n",
            self::FREE_BUSY,
        );
        $written = $this->applied(sprintf('<limit-freebusy-set %s/>', self::NOON), $odd);

        self::assertStringContainsString("BEGIN:X-SOMETHING\r\n", $written);
        self::assertStringContainsString('a component no grammar puts here', $written);
        self::assertStringNotContainsString('20260105T100000Z', $written);
    }

    /**
     * **The limits are applied before the parts are narrowed**, because a
     * narrowing can take away the very property a limit is judged by:
     * `RECURRENCE-ID` says which instance an override replaces, and a client
     * that asked only for summaries would otherwise leave the server unable to
     * tell master from override.
     */
    public function testTheLimitsAreAppliedBeforeThePartsAreNarrowed(): void
    {
        $written = $this->applied(
            '<comp name="VCALENDAR"><comp name="VEVENT"><prop name="SUMMARY"/></comp></comp>'
            . '<limit-recurrence-set start="20260601T000000Z" end="20260602T000000Z"/>',
            self::WEEKLY . self::MOVED . self::SHIFTED,
        );

        self::assertStringContainsString('SUMMARY:Moved to June', $written);
        self::assertStringNotContainsString('Later that day', $written);
        self::assertStringNotContainsString('RECURRENCE-ID', $written);
    }

    /**
     * **A restriction about another kind of component leaves nothing between
     * the object's own beginning and end.** §9.6.1 defines "which component
     * types to return", and a type that is not this object's names no part of
     * it — while §9.6 licenses the answer: it "MAY be invalid per their media
     * type specification".
     */
    public function testARestrictionAboutAnotherKindLeavesTheObjectEmpty(): void
    {
        $written = $this->applied('<comp name="VTODO"/>', self::MEETING);

        self::assertSame("BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n", $written);
    }

    /**
     * **§7.8's `CALDAV:supported-calendar-data` precondition**: "The
     * attributes 'content-type' and 'version' of the CALDAV:calendar-data XML
     * element (see Section 9.6) specify a media type supported by the server
     * for calendar object resources."
     *
     * What this server supports is what §5.2.4 fixes in the absence of a
     * property saying otherwise — "the server MUST only accept data with the
     * media type 'text/calendar' and iCalendar version 2.0" — and it is read
     * from {@see Limits} rather than written out again, so the property that
     * publishes it and the report held to it cannot disagree.
     *
     * @param array<string, string> $attributes What the element carries
     */
    #[DataProvider('mediaTypesItDoesNotKeep')]
    public function testItRefusesAMediaTypeItNeverAdvertised(array $attributes): void
    {
        try {
            CalendarData::of(new Element(CalendarData::NAME, $attributes));
        } catch (Forbidden $refusal) {
            self::assertSame(Limits::SUPPORTED_DATA, $refusal->errorElement());
            self::assertSame(403, $refusal->status());

            return;
        }

        self::fail('Calendar data this server does not keep was asked for and taken.');
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function mediaTypesItDoesNotKeep(): iterable
    {
        yield 'another media type' => [['content-type' => 'text/x-vcalendar']];
        yield 'another version' => [['version' => '1.0']];
        yield 'jCal, which this server does not write' => [['content-type' => 'application/calendar+json']];
        yield 'both of them another' => [['content-type' => 'application/calendar+xml', 'version' => '2.0']];
    }

    /**
     * And the one it does keep is taken, however it is written: RFC 9110
     * §8.3.1 makes a media type's "type, subtype, and parameter name tokens"
     * case-insensitive, while a version is a string the memo prints as it is
     * and is compared that way.
     *
     * @param array<string, string> $attributes What the element carries
     */
    #[DataProvider('waysOfNamingWhatItKeeps')]
    public function testItTakesTheMediaTypeItAdvertises(array $attributes): void
    {
        CalendarData::of(new Element(CalendarData::NAME, $attributes));

        $this->expectNotToPerformAssertions();
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function waysOfNamingWhatItKeeps(): iterable
    {
        // §9.6's `<!ATTLIST>` gives both attributes a default, so an element
        // carrying neither names the one this server keeps.
        yield 'neither attribute' => [[]];
        yield 'both, as the memo prints them' => [['content-type' => 'text/calendar', 'version' => '2.0']];
        yield 'the media type in another case' => [['content-type' => 'TEXT/Calendar']];
        yield 'the version alone' => [['version' => '2.0']];
    }

    /**
     * **§9.6.5: an expansion answers with the instances.** „the server MUST
     * expand the recurrence set into calendar components that define exactly
     * one recurrence instance, and MUST return only those whose scheduled time
     * intersect a specified time range." What those components look like is
     * {@see \DavServices\CalDav\Expander}'s business; that it is asked at all
     * is this one's.
     */
    public function testAnExpansionAnswersWithTheInstances(): void
    {
        $written = $this->applied(
            '<expand start="20260112T000000Z" end="20260113T000000Z"/>',
            self::WEEKLY,
        );

        self::assertStringContainsString("DTSTART:20260112T120000Z\r\n", $written);
        self::assertStringContainsString("RECURRENCE-ID:20260112T120000Z\r\n", $written);
        self::assertStringNotContainsString('RRULE', $written);
        self::assertSame(1, substr_count($written, 'BEGIN:VEVENT'));
    }

    /**
     * **And an expansion runs before the parts are narrowed**, for the reason
     * the limits do: what the expansion is computed from — `RRULE`,
     * `RECURRENCE-ID` — is what a narrowing may take away.
     */
    public function testAnExpansionRunsBeforeThePartsAreNarrowed(): void
    {
        $written = $this->applied(
            '<comp name="VCALENDAR"><comp name="VEVENT"><prop name="SUMMARY"/></comp></comp>'
            . '<expand start="20260112T000000Z" end="20260113T000000Z"/>',
            self::WEEKLY,
        );

        self::assertSame("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nSUMMARY:Weekly\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n", $written);
    }

    /**
     * **§9.6's DTD, element by element**: `(comp?, (expand |
     * limit-recurrence-set)?, limit-freebusy-set?)`. One of each at most, a
     * choice in the middle, and the order it writes is the order it means.
     *
     * @param string $children What the `CALDAV:calendar-data` carries
     * @param string $says What the refusal names
     */
    #[DataProvider('bodiesTheDtdDoesNotAdmit')]
    public function testWhatTheDtdDoesNotAdmitIsRefused(string $children, string $says): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage($says);

        $this->data($children);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bodiesTheDtdDoesNotAdmit(): iterable
    {
        yield 'two comps' => ['<comp name="VCALENDAR"/><comp name="VEVENT"/>', 'comp?'];
        yield 'two limits on the recurrences' => [
            sprintf('<limit-recurrence-set %s/><limit-recurrence-set %s/>', self::NOON, self::NOON),
            'expand | limit-recurrence-set',
        ];
        yield 'an expansion beside a limit' => [
            sprintf('<expand %s/><limit-recurrence-set %s/>', self::NOON, self::NOON),
            'expand | limit-recurrence-set',
        ];
        yield 'a limit beside an expansion' => [
            sprintf('<limit-recurrence-set %s/><expand %s/>', self::NOON, self::NOON),
            'expand | limit-recurrence-set',
        ];
        yield 'two expansions' => [
            sprintf('<expand %s/><expand %s/>', self::NOON, self::NOON),
            'expand | limit-recurrence-set',
        ];
        yield 'an expansion after a limit on the free/busy' => [
            sprintf('<limit-freebusy-set %s/><expand %s/>', self::NOON, self::NOON),
            'before',
        ];
        yield 'two limits on the free/busy' => [
            sprintf('<limit-freebusy-set %s/><limit-freebusy-set %s/>', self::NOON, self::NOON),
            'limit-freebusy-set?',
        ];
        yield 'a comp after a limit' => [
            sprintf('<limit-freebusy-set %s/><comp name="VCALENDAR"/>', self::NOON),
            'before',
        ];
        yield 'a limit on the recurrences after one on the free/busy' => [
            sprintf('<limit-freebusy-set %s/><limit-recurrence-set %s/>', self::NOON, self::NOON),
            'before',
        ];
        yield 'a child the DTD does not name' => ['<prop name="VERSION"/>', 'prop'];

        // The two limits want both ends, which §9.6.6 and §9.6.7 make
        // `#REQUIRED` — unlike §9.9's own element.
        yield 'a limit open at one end' => ['<limit-recurrence-set start="20260105T120000Z"/>', '#REQUIRED'];
        yield 'a free/busy limit open at one end' => ['<limit-freebusy-set end="20260105T130000Z"/>', '#REQUIRED'];

        // §9.6.5 makes the two attributes of an expansion `#REQUIRED` as well,
        // and now that the expansion is answered they are read.
        yield 'an expansion open at one end' => ['<expand start="20260105T120000Z"/>', '#REQUIRED'];
    }

    /**
     * The object as the element leaves it, written out.
     *
     * @param string $children What the `CALDAV:calendar-data` carries
     * @param string $components What the object holds
     */
    private function applied(string $children, string $components): string
    {
        return $this->data($children)->elementFor(...$this->object($components))->text();
    }

    /**
     * One `CALDAV:calendar-data` as a request carries it.
     */
    private function data(string $children = ''): CalendarData
    {
        $body = sprintf(
            '<calendar-data xmlns="urn:ietf:params:xml:ns:caldav">%s</calendar-data>',
            $children,
        );

        return CalendarData::of((new Reader())->parse($body));
    }

    /**
     * The two arguments `elementFor()` takes, for one object.
     *
     * @return array{Component, Moments}
     */
    private function object(string $components): array
    {
        $text = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\n"
            . $components . "END:VCALENDAR\r\n";
        $object = CalendarObject::in($text) ?? self::fail('The case built something that is no calendar object.');

        return [$object, new Moments($object, new DateTimeZone('UTC'))];
    }
}
