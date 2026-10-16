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

use DavServices\CalDav\CalendarObject;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Tests\Unit\Dav\PlainNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for what a calendar object resource is. RFC 4791 §4.1, P6-02.
 *
 * **The question both of CalDAV's write-time halves ask.** The limits of §5.2
 * are about "a calendar object resource stored in a calendar collection", and
 * §4.1's own restrictions are about the same thing — so "is this one, and what
 * is in it" is asked once, the way `CardDav\AddressObject` asks it for a card.
 *
 * What §4.1 settles beyond that — one type of component only, no `METHOD`, one
 * `UID` per resource — belongs to the chunk that validates a `PUT` (P6-04).
 * This is the part the limits need: the object, and which kinds of component
 * it holds.
 */
#[CoversClass(CalendarObject::class)]
final class CalendarObjectTest extends TestCase
{
    private const MEETING = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\n"
        . "BEGIN:VEVENT\r\nUID:1@example.com\r\nDTSTAMP:20041210T183904Z\r\nDTSTART:20041207T120000Z\r\n"
        . "SUMMARY:One-off Meeting\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    public function testAFileHoldingOneCalendarIsOne(): void
    {
        $object = CalendarObject::of(new MemoryFile('meeting.ics', self::MEETING));

        self::assertNotNull($object);
        self::assertSame('VCALENDAR', $object->name());
    }

    /**
     * "an iCalendar object", singular, the same reading RFC 6352 §5.1 gets for
     * a card: two of them is not one of them.
     */
    public function testTwoCalendarsInOneFileAreNotOne(): void
    {
        self::assertNull(CalendarObject::in(self::MEETING . self::MEETING));
    }

    public function testTextThatIsNoCalendarIsNotOne(): void
    {
        self::assertNull(CalendarObject::in('This is a note, not a calendar.'));
    }

    public function testNothingAtAllIsNotOne(): void
    {
        self::assertNull(CalendarObject::in(''));
    }

    /**
     * RFC 5545 §3.4 makes `VCALENDAR` the only iCalendar object there is, so a
     * component of another kind on its own is not one.
     */
    public function testAComponentOfAnotherKindIsNotOne(): void
    {
        self::assertNull(CalendarObject::in("BEGIN:VEVENT\r\nUID:x\r\nEND:VEVENT\r\n"));
    }

    public function testACollectionIsNotOne(): void
    {
        self::assertNull(CalendarObject::of(new MemoryCollection('attachments')));
    }

    public function testSomethingThatIsNoFileIsNotOne(): void
    {
        self::assertNull(CalendarObject::of(new PlainNode('a-principal')));
    }

    /**
     * **The kinds of component it holds**, which is what
     * `CALDAV:supported-calendar-component-set` is matched against.
     */
    public function testItSaysWhichKindsOfComponentItHolds(): void
    {
        $object = CalendarObject::in(self::MEETING);

        self::assertNotNull($object);
        self::assertSame(['VEVENT'], CalendarObject::kindsIn($object));
    }

    /**
     * **`VTIMEZONE` is not one of the kinds**, and §5.2.3 says why: "The
     * empty-element tag `<C:comp name="VTIMEZONE"/>` MUST only be specified if
     * support for calendar object resources that only contain VTIMEZONE
     * components is provided or desired. Support for VTIMEZONE components in
     * calendar object resources that contain VEVENT or VTODO components is
     * always assumed."
     *
     * So an object with an event and the zone that event needs holds one kind,
     * not two — and a collection that takes `VEVENT` takes it.
     */
    public function testTheTimeZoneAnEventNeedsIsNotAKindOfItsOwn(): void
    {
        $withZone = str_replace(
            "BEGIN:VEVENT\r\n",
            "BEGIN:VTIMEZONE\r\nTZID:US-Eastern\r\nBEGIN:STANDARD\r\nDTSTART:19671029T020000\r\n"
            . "TZOFFSETFROM:-0400\r\nTZOFFSETTO:-0500\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\nBEGIN:VEVENT\r\n",
            self::MEETING,
        );
        $object = CalendarObject::in($withZone);

        self::assertNotNull($object);
        self::assertSame(['VEVENT'], CalendarObject::kindsIn($object));
    }

    /**
     * **An object of nothing but time zones holds `VTIMEZONE`**, which is the
     * other half of §5.2.3's sentence: the tag is for "calendar object
     * resources that only contain VTIMEZONE components", so such an object has
     * to be able to name its kind.
     */
    public function testAnObjectOfNothingButTimeZonesHoldsThatKind(): void
    {
        $zone = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//x//y//EN\r\n"
            . "BEGIN:VTIMEZONE\r\nTZID:US-Eastern\r\nBEGIN:STANDARD\r\nDTSTART:19671029T020000\r\n"
            . "TZOFFSETFROM:-0400\r\nTZOFFSETTO:-0500\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\nEND:VCALENDAR\r\n";
        $object = CalendarObject::in($zone);

        self::assertNotNull($object);
        self::assertSame(['VTIMEZONE'], CalendarObject::kindsIn($object));
    }

    /**
     * Two kinds are two kinds — §4.1 forbids that in a stored resource, and
     * saying so is P6-04's refusal; here it is only reported truthfully, each
     * kind once however often it appears.
     */
    public function testTwoKindsAreBothNamedAndNamedOnce(): void
    {
        $both = str_replace(
            "END:VCALENDAR\r\n",
            "BEGIN:VTODO\r\nUID:2@example.com\r\nDTSTAMP:20041210T183904Z\r\nEND:VTODO\r\n"
            . "BEGIN:VTODO\r\nUID:3@example.com\r\nDTSTAMP:20041210T183904Z\r\nEND:VTODO\r\nEND:VCALENDAR\r\n",
            self::MEETING,
        );
        $object = CalendarObject::in($both);

        self::assertNotNull($object);
        self::assertSame(['VEVENT', 'VTODO'], CalendarObject::kindsIn($object));
    }

    /**
     * **An object written in lower case is still an object.** RFC 5545 §3.4
     * gives the grammar as `"BEGIN" ":" "VCALENDAR"`, and RFC 5234 §2.3 says
     * what those quotes mean: "ABNF strings are case insensitive". §3.1 says
     * the same of the names inside — "the property name `DUE` is the same as
     * `due` and `Due`" — and {@see \DavServices\VObject\Component} already
     * looks properties up that way.
     *
     * So a reader that only answered to the shouted spelling would refuse
     * legal iCalendar with `CALDAV:valid-calendar-data`, which is the one
     * refusal a client cannot argue with.
     */
    public function testAnObjectWrittenInLowerCaseIsStillAnObject(): void
    {
        $quiet = "begin:vcalendar\r\nversion:2.0\r\nprodid:-//Example Corp.//CalDAV Client//EN\r\n"
            . "begin:vevent\r\nuid:1@example.com\r\ndtstamp:20260101T000000Z\r\n"
            . "dtstart:20260105T120000Z\r\nend:vevent\r\nend:vcalendar\r\n";

        $object = CalendarObject::in($quiet);

        self::assertNotNull($object);
        self::assertSame(['VEVENT'], CalendarObject::kindsIn($object));
    }

    /**
     * An object with no component at all holds no kind — which is a thing to
     * say rather than a thing to crash on.
     */
    public function testAnEmptyCalendarHoldsNoKind(): void
    {
        $object = CalendarObject::in("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//x//y//EN\r\nEND:VCALENDAR\r\n");

        self::assertNotNull($object);
        self::assertSame([], CalendarObject::kindsIn($object));
    }
}
