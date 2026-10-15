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

use DavServices\CalDav\TimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for what `CALDAV:calendar-timezone` may hold. R-CAL-01, P6-01.
 *
 * Two sentences settle it. §5.2.2 defines the property:
 *
 *     <!ELEMENT calendar-timezone (#PCDATA)>
 *     PCDATA value: an iCalendar object with exactly one VTIMEZONE component.
 *
 * And §5.3.1 makes it a precondition of `MKCALENDAR`:
 *
 * > (CALDAV:valid-calendar-data): The time zone specified in the
 * > CALDAV:calendar-timezone property MUST be a valid iCalendar object
 * > containing a single valid VTIMEZONE component.
 *
 * **"Valid" is the memo's own word, and it is read as "usable for the purpose
 * the property has".** §5.2.2 says what that purpose is: "the time zone the
 * server should rely on to resolve 'date' values and 'date with local time'
 * values (i.e., floating time) to 'date with UTC time' values". A component
 * this server cannot resolve a moment with is therefore not valid here,
 * whatever else it is — which makes
 * {@see \DavServices\VObject\TimeZone\Definition} the test rather than a
 * grammar of its own.
 */
#[CoversClass(TimeZone::class)]
final class TimeZoneTest extends TestCase
{
    /** RFC 4791 §5.2.2's own example, shortened to the one observance it needs. */
    private const US_EASTERN = "BEGIN:VCALENDAR\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\nVERSION:2.0\r\n"
        . "BEGIN:VTIMEZONE\r\nTZID:US-Eastern\r\nBEGIN:STANDARD\r\nDTSTART:19671029T020000\r\n"
        . "RRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=10\r\nTZOFFSETFROM:-0400\r\nTZOFFSETTO:-0500\r\n"
        . "TZNAME:Eastern Standard Time (US & Canada)\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\nEND:VCALENDAR\r\n";

    public function testTheMemosOwnExampleIsATimeZone(): void
    {
        $zone = TimeZone::in(self::US_EASTERN);

        self::assertNotNull($zone);
        self::assertSame('VTIMEZONE', $zone->name());
        self::assertSame('US-Eastern', $zone->property('TZID')?->value());
    }

    public function testTextThatIsNoCalendarIsNoTimeZone(): void
    {
        self::assertNull(TimeZone::in('Europe/Berlin'));
    }

    /**
     * "an iCalendar object", singular — so two of them is not one of them.
     */
    public function testTwoCalendarObjectsAreNotOne(): void
    {
        self::assertNull(TimeZone::in(self::US_EASTERN . self::US_EASTERN));
    }

    public function testNoCalendarObjectAtAllIsNoTimeZone(): void
    {
        self::assertNull(TimeZone::in(''));
    }

    /**
     * **"exactly one VTIMEZONE component"**, so none and two are both refused.
     */
    public function testACalendarWithoutATimeZoneIsNoTimeZone(): void
    {
        $calendar = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//x//y//EN\r\nEND:VCALENDAR\r\n";

        self::assertNull(TimeZone::in($calendar));
    }

    public function testACalendarWithTwoTimeZonesIsNoTimeZone(): void
    {
        $two = str_replace(
            "END:VCALENDAR\r\n",
            "BEGIN:VTIMEZONE\r\nTZID:Other\r\nBEGIN:STANDARD\r\nDTSTART:19671029T020000\r\n"
            . "TZOFFSETFROM:-0400\r\nTZOFFSETTO:-0500\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\nEND:VCALENDAR\r\n",
            self::US_EASTERN,
        );

        self::assertNull(TimeZone::in($two));
    }

    /**
     * **RFC 5545 §3.6.5: "One of 'standardc' or 'daylightc' MUST occur."** A
     * `VTIMEZONE` without an observance defines nothing to resolve a moment
     * with, which is the one thing §5.2.2 wants the property for.
     */
    public function testATimeZoneWithoutAnObservanceIsNotValid(): void
    {
        $bare = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//x//y//EN\r\n"
            . "BEGIN:VTIMEZONE\r\nTZID:US-Eastern\r\nEND:VTIMEZONE\r\nEND:VCALENDAR\r\n";

        self::assertNull(TimeZone::in($bare));
    }

    /**
     * And §3.6.5 makes the three properties of an observance mandatory —
     * `DTSTART`, `TZOFFSETFROM` and `TZOFFSETTO` — so one that leaves an
     * offset out defines nothing either.
     */
    public function testAnObservanceMissingAnOffsetIsNotValid(): void
    {
        $broken = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//x//y//EN\r\n"
            . "BEGIN:VTIMEZONE\r\nTZID:US-Eastern\r\nBEGIN:STANDARD\r\nDTSTART:19671029T020000\r\n"
            . "TZOFFSETTO:-0500\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\nEND:VCALENDAR\r\n";

        self::assertNull(TimeZone::in($broken));
    }

    /**
     * **RFC 5545 §3.6.5 makes `TZID` REQUIRED**, and §5.2.2's purpose needs
     * it: a zone nobody can name is one no calendar object can point at.
     */
    public function testATimeZoneWithoutATzidIsNotValid(): void
    {
        self::assertNull(TimeZone::in(str_replace("TZID:US-Eastern\r\n", '', self::US_EASTERN)));
    }

    /**
     * **Another component beside the time zone is not refused**, because the
     * memo does not refuse it: §5.2.2 asks for "an iCalendar object with
     * exactly one VTIMEZONE component" and says nothing about what else the
     * object may carry. Being stricter than the text would refuse a client
     * that sent a `PRODID` and a stray `VEVENT` from its own exporter.
     */
    public function testAnotherComponentBesideItIsNoReasonToRefuse(): void
    {
        $withEvent = str_replace(
            "END:VCALENDAR\r\n",
            "BEGIN:VEVENT\r\nUID:x\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260101T000000Z\r\n"
            . "SUMMARY:Stray\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
            self::US_EASTERN,
        );

        self::assertNotNull(TimeZone::in($withEvent));
    }

    /**
     * An iCalendar object that is no `VCALENDAR` carries no time zone, however
     * well formed it is — RFC 5545 §3.4 makes `VCALENDAR` the only object
     * there is.
     */
    public function testAComponentOfAnotherKindIsNoTimeZone(): void
    {
        self::assertNull(TimeZone::in("BEGIN:VTIMEZONE\r\nTZID:x\r\nEND:VTIMEZONE\r\n"));
    }
}
