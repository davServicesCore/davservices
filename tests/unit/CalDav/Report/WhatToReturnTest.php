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
use DavServices\CalDav\CalendarObject;
use DavServices\CalDav\Moments;
use DavServices\CalDav\Report\WhatToReturn;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\Property\LiveProperties;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\VObject\Component;
use DavServices\Xml\MultiStatus;
use DavServices\Xml\Writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for what a report returns about one calendar object. R-CAL-10,
 * R-CAL-11. P6-06.
 *
 * **The three reports of RFC 4791 answer the same question about an object.**
 * §7.9 says so of the multiget — it "is similar to the CALDAV:calendar-query
 * REPORT (see Section 7.8), except that it takes a list of DAV:href elements,
 * instead of a CALDAV:filter element, to determine which calendar object
 * resources to return" — and §7.8 and §7.9 both read the same optional
 * property group and both answer calendar data "in the CALDAV:calendar-data
 * XML element inside the DAV:propstat XML element". Written twice, the two
 * would sooner or later disagree about which of them was right.
 *
 * The piece that makes this more than a parser is §9.6's note: the
 * `CALDAV:calendar-data` element "is not a WebDAV property and, as such, is
 * not returned in PROPFIND responses". It arrives inside `DAV:prop` all the
 * same, so it has to be taken out before the property machinery is asked —
 * otherwise the one thing the client came for comes back as `404`.
 */
#[CoversClass(WhatToReturn::class)]
final class WhatToReturnTest extends TestCase
{
    private const OBJECT = '/calendars/work/meeting.ics';

    private const MEETING = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\n"
        . "BEGIN:VEVENT\r\nUID:1@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
        . "DTSTART:20260105T120000Z\r\nSUMMARY:A Meeting\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    private const DATA = '<calendar-data xmlns="urn:ietf:params:xml:ns:caldav"/>';

    /**
     * **§7.8: "Calendar data is being returned in the CALDAV:calendar-data XML
     * element inside the DAV:propstat XML element."** So it comes back beside
     * the WebDAV properties and under their status.
     */
    public function testTheObjectAndThePropertiesComeBackTogether(): void
    {
        $written = $this->describing('<D:prop xmlns:D="DAV:"><D:getetag/>' . self::DATA . '</D:prop>');

        self::assertStringContainsString('<d:getetag>', $written);
        self::assertStringContainsString('SUMMARY:A Meeting', $written);
        self::assertStringContainsString('<d:status>HTTP/1.1 200 OK</d:status>', $written);
        self::assertSame(1, substr_count($written, '<d:propstat>'), 'one status for both');
    }

    /**
     * A `DAV:prop` naming properties and no calendar data is the ordinary
     * `PROPFIND`-shaped request, and §7.8 asks for it to be answered as one:
     * "the response should follow the same behavior as PROPFIND with respect
     * to the DAV:multistatus response elements used to return specific
     * property results".
     */
    public function testPropertiesWithoutTheObjectAreAnsweredOnTheirOwn(): void
    {
        $written = $this->describing('<D:prop xmlns:D="DAV:"><D:getetag/></D:prop>');

        self::assertStringContainsString('<d:getetag>', $written);
        self::assertStringNotContainsString('BEGIN:VCALENDAR', $written);
    }

    /**
     * **And a property nobody has is a `404`**, which §7.8 makes a MUST: "a
     * request to retrieve the value of a property that does not exist is an
     * error and MUST be noted with a response XML element that contains a 404
     * (Not Found) status value."
     */
    public function testAPropertyNobodyHasIsReportedAsMissing(): void
    {
        $written = $this->describing('<D:prop xmlns:D="DAV:"><D:displayname/></D:prop>');

        self::assertStringContainsString('<d:status>HTTP/1.1 404 Not Found</d:status>', $written);
        self::assertStringContainsString('<d:displayname/>', $written);
    }

    /**
     * **The calendar data is never one of those.** §9.6 is explicit that it is
     * no WebDAV property, so asking the property machinery about it would hand
     * the client a `404` for the only thing it came for.
     */
    public function testTheObjectIsNotReportedAsAPropertyNobodyHas(): void
    {
        $written = $this->describing('<D:prop xmlns:D="DAV:">' . self::DATA . '</D:prop>');

        self::assertStringContainsString('BEGIN:VCALENDAR', $written);
        self::assertStringNotContainsString('404', $written);
    }

    /**
     * A `DAV:prop` holding nothing but the calendar data leaves no WebDAV
     * property to ask about — and an empty `DAV:propstat` is not a shape
     * RFC 4918 §14.24 admits, so there is one propstat and it holds the data.
     */
    public function testAPropHoldingOnlyTheDataProducesOnePropstat(): void
    {
        $written = $this->describing('<D:prop xmlns:D="DAV:">' . self::DATA . '</D:prop>');

        self::assertSame(1, substr_count($written, '<d:propstat>'));
    }

    /**
     * RFC 4918 §14.24 gives a `DAV:response` two shapes, and this is the other
     * one: an href with a status. §9.5 makes the property group optional —
     * `((DAV:allprop | DAV:propname | DAV:prop)?, filter, timezone?)` — so a
     * client that named nothing wanted nothing, and answering `allprop` would
     * send calendar data nobody asked for.
     */
    public function testWithoutAPropertyRequestTheHrefComesBackWithAStatus(): void
    {
        $written = $this->describing('');

        self::assertStringContainsString('HTTP/1.1 200 OK', $written);
        self::assertStringNotContainsString('<d:propstat>', $written);
        self::assertStringNotContainsString('BEGIN:VCALENDAR', $written);
    }

    public function testPropnameAsksForTheNamesAlone(): void
    {
        $written = $this->describing('<D:propname xmlns:D="DAV:"/>');

        self::assertStringContainsString('<d:getetag/>', $written);
        self::assertStringNotContainsString('BEGIN:VCALENDAR', $written);
    }

    /**
     * §9.6's note again: `DAV:allprop` is about WebDAV properties, and
     * calendar data is not one of them.
     */
    public function testAllpropBringsThePropertiesAndNotTheObject(): void
    {
        $written = $this->describing('<D:allprop xmlns:D="DAV:"/>');

        self::assertStringContainsString('<d:getetag>', $written);
        self::assertStringNotContainsString('BEGIN:VCALENDAR', $written);
    }

    /**
     * §7.8's `CALDAV:supported-calendar-data` precondition is about the
     * request, so it is raised while the body is read rather than once per
     * object: a precondition is a reason not to run the report at all.
     */
    public function testAMediaTypeTheServerNeverAdvertisedIsRefusedWhileReading(): void
    {
        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage('text/x-vcalendar');

        $this->whatToReturn(
            '<D:prop xmlns:D="DAV:"><calendar-data xmlns="urn:ietf:params:xml:ns:caldav" '
            . 'content-type="text/x-vcalendar"/></D:prop>',
        );
    }

    /**
     * And so is an expansion that names no range it can be read as: §9.6.5
     * makes both attributes `#REQUIRED`, and the whole `CALDAV:calendar-data`
     * is read once, before an object is fetched, rather than per object.
     */
    public function testAnExpansionThatNamesNoRangeIsRefusedWhileReading(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('#REQUIRED');

        $this->whatToReturn(
            '<D:prop xmlns:D="DAV:"><calendar-data xmlns="urn:ietf:params:xml:ns:caldav">'
            . '<expand xmlns="urn:ietf:params:xml:ns:caldav" start="20260101T000000Z"/>'
            . '</calendar-data></D:prop>',
        );
    }

    /**
     * **And an expansion that does name one reaches the answer** (R-CAL-14):
     * §9.6.5 has the server "expand the recurrence set into calendar
     * components that define exactly one recurrence instance, and […] return
     * only those whose scheduled time intersect a specified time range".
     */
    public function testAnExpansionReachesTheAnswer(): void
    {
        $written = $this->describing(
            '<D:prop xmlns:D="DAV:"><calendar-data xmlns="urn:ietf:params:xml:ns:caldav">'
            . '<expand xmlns="urn:ietf:params:xml:ns:caldav" start="20260105T000000Z" end="20260106T000000Z"/>'
            . '</calendar-data></D:prop>',
        );

        self::assertStringContainsString('SUMMARY:A Meeting', $written);
        self::assertStringNotContainsString(
            'SUMMARY:A Meeting',
            $this->describing(
                '<D:prop xmlns:D="DAV:"><calendar-data xmlns="urn:ietf:params:xml:ns:caldav">'
                . '<expand xmlns="urn:ietf:params:xml:ns:caldav" start="20260301T000000Z" end="20260302T000000Z"/>'
                . '</calendar-data></D:prop>',
            ),
            'an instance outside the range',
        );
    }

    /**
     * **And a client that asked for less is answered with less**, which is
     * §9.6's own sentence: "one can explicitly specify the calendar components
     * and properties that should be returned in the calendar object resource
     * data". Which parts those are is
     * {@see \DavServices\CalDav\CalendarData}'s business; that it is asked at
     * all is this one's.
     */
    public function testTheDataComesBackAsNarrowlyAsItWasAskedFor(): void
    {
        $written = $this->describing(
            '<D:prop xmlns:D="DAV:"><calendar-data xmlns="urn:ietf:params:xml:ns:caldav">'
            . '<comp xmlns="urn:ietf:params:xml:ns:caldav" name="VCALENDAR">'
            . '<prop xmlns="urn:ietf:params:xml:ns:caldav" name="VERSION"/>'
            . '</comp></calendar-data></D:prop>',
        );

        self::assertStringContainsString('VERSION:2.0', $written);
        self::assertStringNotContainsString('PRODID', $written);
        self::assertStringNotContainsString('SUMMARY', $written);
    }

    /**
     * The href is handed in rather than worked out, because the reports answer
     * with different ones: a query names the resource it found, a multiget
     * echoes what the client asked for (§7.9).
     */
    public function testTheHrefIsTheOneItWasGiven(): void
    {
        $written = $this->describing('', self::MEETING, '/whatever/the/caller/says');

        self::assertStringContainsString('<d:href>/whatever/the/caller/says</d:href>', $written);
    }

    private function describing(string $properties, string $object = self::MEETING, ?string $href = null): string
    {
        $server = $this->server();
        $report = new MultiStatus();

        $read = $this->object($object);

        $this->whatToReturn($properties, $server)->describe(
            $report,
            $href ?? self::OBJECT,
            self::OBJECT,
            $server->tree()->node(self::OBJECT),
            $read,
            new Moments($read, new DateTimeZone('UTC')),
        );

        return (new Writer())->write($report->toElement());
    }

    private function whatToReturn(string $properties, ?Server $server = null): WhatToReturn
    {
        $server ??= $this->server();
        $body = sprintf('<report xmlns="urn:ietf:params:xml:ns:caldav">%s</report>', $properties);

        return WhatToReturn::of($server, $server->reader()->parse($body));
    }

    private function server(): Server
    {
        $calendar = (new MemoryCollection('work'))->add(new MemoryFile('meeting.ics', self::MEETING));
        $home = (new MemoryCollection('calendars'))->add($calendar);
        $root = (new MemoryCollection(''))->add($home);

        // The live properties are wired up because §7.8 has the report answer
        // properties the way a `PROPFIND` does, and a server without them
        // would let these cases pass on a report that answered none.
        $events = new EventEmitter();
        $events->on(PropertiesRequested::class, (new LiveProperties())(...));

        return new Server(new Tree($root), $events);
    }

    private function object(string $text): Component
    {
        return CalendarObject::in($text) ?? self::fail('The case built something that is no calendar object.');
    }
}
