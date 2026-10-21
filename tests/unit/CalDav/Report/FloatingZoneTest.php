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

namespace DavServices\Tests\Unit\CalDav\Report;

use DateTimeZone;
use DavServices\CalDav\Calendar;
use DavServices\CalDav\Report\FloatingZone;
use DavServices\CalDav\TimeZone;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Exception\Forbidden;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Tests\Unit\Dav\MemoryPropertyCollection;
use DavServices\VObject\Recur\Zone;
use DavServices\VObject\TimeZone\Definition;
use DavServices\Xml\Element;
use DavServices\Xml\Reader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for the zone a report reads a floating value in. R-CAL-10,
 * R-CAL-11. P6-09.
 *
 * RFC 4791 §7.3 gives three sources, and the first two are a MUST:
 *
 * > CalDAV servers MUST rely on the value of the CALDAV:timezone XML element,
 * > if specified as part of the request body, to perform the proper conversion
 * > of "floating date" and "floating time" values to date with UTC time
 * > values. If the CALDAV:timezone XML element is not specified in the request
 * > body, CalDAV servers MUST rely on the value of the CALDAV:calendar-timezone
 * > property, if defined, or else the CalDAV servers MAY rely on the time zone
 * > of their choice.
 *
 * **Two reports ask the same question**, which is why this is one class:
 * `calendar-query` (§7.8) may carry the element — §9.5's body has a
 * `timezone?` — while `calendar-multiget` (§7.9) has no such element in
 * §9.10's body at all and reads only the property. Both then need the second
 * source per object, and §9.8 says which collection that is: "the
 * CALDAV:calendar-timezone property of the calendar collection, in which the
 * calendar object resource is contained".
 */
#[CoversClass(FloatingZone::class)]
final class FloatingZoneTest extends TestCase
{
    private const CALENDAR = 'calendars/work';

    private const MEETING = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\n"
        . "BEGIN:VEVENT\r\nUID:1@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
        . "DTSTART:20260105T120000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    /**
     * **§7.3's first source**, which §9.8 makes a MUST: "the server MUST rely
     * on the specified VTIMEZONE component instead of the
     * CALDAV:calendar-timezone property of the calendar collection".
     */
    public function testTheElementOfTheBodyIsTheFirstSource(): void
    {
        // The calendar keeps a zone of its own, and a good one: §9.8 says the
        // element stands "instead of" it, so the property has to be something
        // the answer could have been.
        $zone = $this->zoneOf(
            $this->body($this->zoneCalled('America/New_York')),
            $this->zoneCalled('Europe/Berlin'),
        );

        self::assertInstanceOf(DateTimeZone::class, $zone);
        self::assertSame('America/New_York', $zone->getName());
    }

    /**
     * **§7.3's second source**, where the body carries no element: "CalDAV
     * servers MUST rely on the value of the CALDAV:calendar-timezone property,
     * if defined".
     */
    public function testTheCollectionsPropertyIsTheSecondSource(): void
    {
        $zone = $this->zoneOf($this->body(), $this->zoneCalled('America/New_York'));

        self::assertInstanceOf(DateTimeZone::class, $zone);
        self::assertSame('America/New_York', $zone->getName());
    }

    /**
     * **§7.3's third source is a choice, and UTC is the one made here**: "or
     * else the CalDAV servers MAY rely on the time zone of their choice."
     * §9.9's tables are written against "date with UTC time" values, so a
     * server that picked a zone of its own would answer the same query
     * differently from one that did not.
     */
    public function testWithNeitherItIsUtc(): void
    {
        $zone = $this->zoneOf($this->body());

        self::assertInstanceOf(DateTimeZone::class, $zone);
        self::assertSame('UTC', $zone->getName());
    }

    /**
     * **A `VTIMEZONE` no catalogue names is used as it stands**, because §9.8
     * says the server "MUST rely on the specified VTIMEZONE component" and
     * §5.2.2's own example calls its zone `US-Eastern` — a name the IANA
     * database has not got.
     */
    public function testAZoneNothingNamesIsTheDefinitionItself(): void
    {
        self::assertInstanceOf(
            Definition::class,
            $this->zoneOf($this->body($this->zoneCalled('US-Eastern'))),
        );
    }

    /**
     * §7.8's `CALDAV:valid-calendar-data` precondition: "The time zone
     * specified in the REPORT request MUST be a valid iCalendar object
     * containing a single valid VTIMEZONE component."
     */
    public function testAnElementThatIsNoTimeZoneIsRefused(): void
    {
        try {
            $this->zoneOf($this->body('Europe/Berlin'));
        } catch (Forbidden $refusal) {
            self::assertSame(TimeZone::VALID, $refusal->errorElement());
            self::assertSame(403, $refusal->status());

            return;
        }

        self::fail('A time zone nothing can be resolved with was taken.');
    }

    /**
     * **A report whose body cannot name a zone reads only the property.**
     * §9.10's `calendar-multiget` is one: its body is `((DAV:allprop |
     * DAV:propname | DAV:prop)?, DAV:href+)`, with no `timezone` in it — so a
     * server that read one there would be answering an element the memo does
     * not admit, and this way of asking cannot.
     */
    public function testAReportThatCannotNameAZoneReadsOnlyTheProperty(): void
    {
        $server = $this->server($this->zoneCalled('America/New_York'), null);
        $zone = FloatingZone::ofTheCollections($server)
            ->forTheObjectAt(self::CALENDAR, $server->tree()->node(self::CALENDAR));

        self::assertInstanceOf(DateTimeZone::class, $zone);
        self::assertSame('America/New_York', $zone->getName());
    }

    /**
     * And with no property either it is UTC, §7.3's third source — the same
     * choice the other way of asking makes.
     */
    public function testAReportThatCannotNameAZoneFallsBackToUtc(): void
    {
        $server = $this->server(null, null);
        $zone = FloatingZone::ofTheCollections($server)
            ->forTheObjectAt(self::CALENDAR, $server->tree()->node(self::CALENDAR));

        self::assertInstanceOf(DateTimeZone::class, $zone);
        self::assertSame('UTC', $zone->getName());
    }

    /**
     * **However the collection keeps it.** A `MKCALENDAR` hands the property
     * over as the text it carried, while a `PROPPATCH` hands over the element
     * it was written in (§5.2.2's `<!ELEMENT calendar-timezone (#PCDATA)>`) —
     * so a store may hold either, and both say the same zone.
     */
    public function testThePropertyIsReadFromAnElementToo(): void
    {
        $element = new Element('{urn:ietf:params:xml:ns:caldav}calendar-timezone');

        $element->appendText($this->zoneCalled('America/New_York'));

        $zone = $this->zoneOf($this->body(), $element);

        self::assertInstanceOf(DateTimeZone::class, $zone);
        self::assertSame('America/New_York', $zone->getName());
    }

    /**
     * **The nearest calendar collection at or above the object decides**,
     * which is the collection §9.8 names. A report may be aimed at the
     * calendar, at one object in it, or at a plain folder §4.2 lets the
     * calendar hold — all three are inside the same calendar.
     *
     * @param string $path What the question is about
     */
    #[DataProvider('placesInsideACalendar')]
    public function testTheNearestCalendarAtOrAboveDecides(string $path): void
    {
        $zone = $this->zoneOf($this->body(), $this->zoneCalled('America/New_York'), $path);

        self::assertInstanceOf(DateTimeZone::class, $zone);
        self::assertSame('America/New_York', $zone->getName());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function placesInsideACalendar(): iterable
    {
        yield 'the calendar itself' => [self::CALENDAR];
        yield 'an object in it' => [self::CALENDAR . '/meeting.ics'];
        yield 'a plain folder inside it' => [self::CALENDAR . '/inner'];
        yield 'an object below that folder' => [self::CALENDAR . '/inner/deep.ics'];
    }

    /**
     * **And a property kept above a calendar is not that calendar's.** §5.2.2
     * defines `CALDAV:calendar-timezone` on a calendar collection, and §9.8
     * names the collection holding the resource — a calendar home is neither.
     */
    public function testAPropertyKeptAboveACalendarIsNotItsOwn(): void
    {
        $zone = $this->zoneOf($this->body(), null, self::CALENDAR, $this->zoneCalled('America/New_York'));

        self::assertInstanceOf(DateTimeZone::class, $zone);
        self::assertSame('UTC', $zone->getName());
    }

    /**
     * And a value nothing can be resolved with is treated as none: §5.2.10 and
     * §5.3.1 refuse such a value on the way in, so one in a store got there
     * another way — and §7.3's last clause is a better answer than a report
     * that fails over a property the client did not even name.
     */
    public function testAStoredValueNothingCanBeResolvedWithIsTreatedAsNone(): void
    {
        $zone = $this->zoneOf($this->body(), 'not a VTIMEZONE at all');

        self::assertInstanceOf(DateTimeZone::class, $zone);
        self::assertSame('UTC', $zone->getName());
    }

    /**
     * The zone a report would read a floating value in.
     *
     * @param string $body The request body, as a client writes it
     * @param Element|string|null $kept What the calendar keeps as its
     *                                  `CALDAV:calendar-timezone`, as a store
     *                                  holds it
     * @param string $path What the question is about
     * @param string|null $above What the home above the calendar keeps
     */
    private function zoneOf(
        string $body,
        Element|string|null $kept = null,
        string $path = self::CALENDAR,
        ?string $above = null,
    ): DateTimeZone|Zone {
        $server = $this->server($kept, $above);

        return FloatingZone::of($server, (new Reader())->parse($body))
            ->forTheObjectAt($path, $server->tree()->node($path));
    }

    /**
     * One request body, carrying a `CALDAV:timezone` where there is one.
     */
    private function body(?string $zone = null): string
    {
        return sprintf(
            '<calendar-query xmlns="urn:ietf:params:xml:ns:caldav">%s</calendar-query>',
            $zone === null ? '' : '<timezone>' . htmlspecialchars($zone, ENT_XML1) . '</timezone>',
        );
    }

    /**
     * A home with one calendar, which holds an object and a plain folder with
     * an object in it.
     *
     * @param Element|string|null $kept What the calendar keeps
     * @param string|null $above What the home keeps
     */
    private function server(Element|string|null $kept, ?string $above): Server
    {
        $store = new MemoryPropertyCollection('work');
        $inner = new MemoryCollection('inner');

        $inner->add(new MemoryFile('deep.ics', self::MEETING));
        $store->add(new MemoryFile('meeting.ics', self::MEETING));
        $store->add($inner);

        if ($kept !== null) {
            $store->patchProperties(['{urn:ietf:params:xml:ns:caldav}calendar-timezone' => $kept]);
        }

        $home = new MemoryPropertyCollection('calendars');

        $home->add(Calendar::of($store));

        if ($above !== null) {
            $home->patchProperties(['{urn:ietf:params:xml:ns:caldav}calendar-timezone' => $above]);
        }

        return new Server(new Tree((new MemoryCollection(''))->add($home)));
    }

    /**
     * §5.2.2's example under another name.
     */
    private function zoneCalled(string $tzid): string
    {
        return "BEGIN:VCALENDAR\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\nVERSION:2.0\r\n"
            . "BEGIN:VTIMEZONE\r\nTZID:" . $tzid . "\r\nBEGIN:STANDARD\r\nDTSTART:19671029T020000\r\n"
            . "RRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=10\r\nTZOFFSETFROM:-0400\r\nTZOFFSETTO:-0500\r\n"
            . "END:STANDARD\r\nEND:VTIMEZONE\r\nEND:VCALENDAR\r\n";
    }
}
