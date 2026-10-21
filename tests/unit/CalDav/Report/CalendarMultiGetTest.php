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

use DavServices\CalDav\Calendar;
use DavServices\CalDav\Limits;
use DavServices\CalDav\Report\CalendarMultiGet;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\Property\LiveProperties;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Tests\Unit\Dav\MemoryPropertyCollection;
use DavServices\Tests\Unit\Dav\PlainNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for `CALDAV:calendar-multiget`. R-CAL-11, P6-09.
 *
 * RFC 4791 §7.9 and §9.10:
 *
 *     <!ELEMENT calendar-multiget ((DAV:allprop |
 *                                   DAV:propname |
 *                                   DAV:prop)?, DAV:href+)>
 *
 * > The CALDAV:calendar-multiget REPORT is used to retrieve specific calendar
 * > object resources from within a collection, if the Request-URI is a
 * > collection, or to retrieve a specific calendar object resource, if the
 * > Request-URI is a calendar object resource. This report is similar to the
 * > CALDAV:calendar-query REPORT (see Section 7.8), except that it takes a
 * > list of DAV:href elements, instead of a CALDAV:filter element, to
 * > determine which calendar object resources to return.
 * >
 * > Support for the CALDAV:calendar-multiget REPORT is REQUIRED.
 *
 * **This is the other half of how a client stays in step**: it lists a
 * calendar with a `PROPFIND`, sees which etags changed, and asks for exactly
 * those objects.
 *
 * What a response carries is tested where that lives, under
 * {@see \DavServices\CalDav\Report\WhatToReturn}; which zone a floating value
 * is read in under {@see \DavServices\CalDav\Report\FloatingZone}. What is
 * tested here is the report around them: the hrefs as the scope, one response
 * per href whatever happened to it, and the preconditions §7.9 lists.
 */
#[CoversClass(CalendarMultiGet::class)]
final class CalendarMultiGetTest extends TestCase
{
    private const CALENDAR = '/calendars/work';

    private const MEETING = "BEGIN:VEVENT\r\nUID:1@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
        . "DTSTART:20260105T120000Z\r\nDTEND:20260105T130000Z\r\nSUMMARY:A Meeting\r\nEND:VEVENT\r\n";

    private const REVIEW = "BEGIN:VEVENT\r\nUID:2@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
        . "DTSTART:20260210T090000Z\r\nDTEND:20260210T100000Z\r\nSUMMARY:The Review\r\nEND:VEVENT\r\n";

    /** Noon with neither a `Z` nor a `TZID`: an instant once somebody says. */
    private const FLOATING = "BEGIN:VEVENT\r\nUID:3@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
        . "DTSTART:20260105T120000\r\nDTEND:20260105T130000\r\nSUMMARY:Lunch\r\nEND:VEVENT\r\n";

    private const DEEP = "BEGIN:VEVENT\r\nUID:4@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
        . "DTSTART:20260105T140000Z\r\nDTEND:20260105T150000Z\r\nSUMMARY:A Meeting Below\r\nEND:VEVENT\r\n";

    /**
     * What a client asks for when it wants the objects themselves: §9.6's note
     * makes `CALDAV:calendar-data` no WebDAV property, so nothing brings it
     * along unasked.
     */
    private const ETAG_AND_DATA = '<D:prop xmlns:D="DAV:"><D:getetag/>'
        . '<calendar-data xmlns="urn:ietf:params:xml:ns:caldav"/></D:prop>';

    /**
     * **§7.9: the response "MUST contain a DAV:response element for each
     * calendar object resource referenced by the provided set of DAV:href
     * elements. Calendar data is being returned in the CALDAV:calendar-data
     * element inside the DAV:prop element."**
     */
    public function testOneResponsePerHrefWithTheDataInside(): void
    {
        $body = (string) $this->get(['meeting.ics', 'review.ics'])->body();

        self::assertSame(2, substr_count($body, '<d:response>'));
        self::assertStringContainsString('/calendars/work/meeting.ics', $body);
        self::assertStringContainsString('/calendars/work/review.ics', $body);
        self::assertStringContainsString('SUMMARY:A Meeting', $body);
        self::assertStringContainsString('SUMMARY:The Review', $body);
    }

    public function testAnswersTwoOhSevenWithAMultistatus(): void
    {
        $response = $this->get(['meeting.ics']);

        self::assertSame(207, $response->status());
        self::assertSame('application/xml; charset=utf-8', $response->headers()->first('Content-Type'));
        self::assertStringContainsString('<d:multistatus', (string) $response->body());
    }

    /**
     * **The href a client sent is the href it gets back**, so that it can
     * match responses to requests without canonicalising anything: §7.9 speaks
     * of the resources "referenced by the provided set of DAV:href elements",
     * and the reference is the client's.
     */
    public function testTheHrefComesBackAsItWasSent(): void
    {
        $body = (string) $this->get(['meeting%2Eics'])->body();

        self::assertStringContainsString('<d:href>/calendars/work/meeting%2Eics</d:href>', $body);
        self::assertStringContainsString('SUMMARY:A Meeting', $body);
    }

    /**
     * **§7.9: "In the case of an error accessing any of the provided DAV:href
     * resources, the server MUST return the appropriate error status code in
     * the DAV:status element of the corresponding DAV:response element."**
     *
     * Which status is appropriate is the memo's word rather than its list, so
     * each is chosen and said — and §7.9.1's own example is the first of them:
     * "the resource at http://cal.example.com/bernard/work/mtg1.ics does not
     * exist, resulting in an error status response".
     *
     * @param string $href What the client asks for
     * @param int $status What comes back for it
     */
    #[DataProvider('hrefsThatCannotBeAnswered')]
    public function testAnHrefThatCannotBeAnsweredGetsItsOwnStatus(string $href, int $status): void
    {
        $body = (string) $this->get([$href])->body();

        self::assertStringContainsString(sprintf('HTTP/1.1 %d ', $status), $body);
        self::assertStringNotContainsString('<d:propstat>', $body);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function hrefsThatCannotBeAnswered(): iterable
    {
        // 404 where nothing is there, which is what 404 means.
        yield 'nothing is there' => ['mtg1.ics', 404];

        // 403 where something is there and is no calendar object (§4.1): 404
        // would be untrue, the resource existing, and 403 says the request was
        // understood and refused.
        yield 'no calendar object' => ['note.txt', 403];
        yield 'two objects in one resource' => ['twice.ics', 403];
        yield 'nothing that is a file' => ['a-principal', 403];

        // 403 where the href lies outside the Request-URI, which is §7.9's
        // own sentence: the hrefs refer to resources "within that collection".
        yield 'outside the Request-URI' => ['/calendars/holiday/away.ics', 403];

        // 404 where the href names another server, which this one cannot
        // answer for.
        yield 'on another server' => ['http://elsewhere.example.com/calendars/work/meeting.ics', 404];

        // 400 where the href is no path this library can address at all: a
        // percent-encoded `..` is one, RFC 3986 §3.3 giving the dot segments
        // their meaning after decoding.
        yield 'no path at all' => ['/calendars/work/%2e%2e', 400];
    }

    /**
     * **§7.9: "they MAY refer to calendar object resources at any depth within
     * the collection."** §4.2 lets a calendar hold "collections that are not
     * calendar collections", so there is a depth to reach.
     */
    public function testAnHrefMayReachAnyDepthWithinTheCollection(): void
    {
        $body = (string) $this->get(['inner/deep.ics'])->body();

        self::assertStringContainsString('SUMMARY:A Meeting Below', $body);
    }

    /**
     * **§7.9: "As a result, the 'Depth' header MUST be ignored by the server
     * and SHOULD NOT be sent by the client."** The hrefs are the scope, so
     * whatever the header says changes nothing — and a server that refused a
     * value would refuse requests the memo tells it to ignore.
     *
     * @param string|null $depth What the header says, or nothing at all
     */
    #[DataProvider('everyDepthAndNone')]
    public function testTheDepthHeaderIsIgnored(?string $depth): void
    {
        $body = (string) $this->get(['meeting.ics'], depth: $depth)->body();

        self::assertStringContainsString('SUMMARY:A Meeting', $body, var_export($depth, true));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function everyDepthAndNone(): iterable
    {
        yield 'none' => [null];
        yield 'zero' => ['0'];
        yield 'one' => ['1'];
        yield 'infinity' => ['infinity'];
        yield 'something it has no meaning for' => ['2'];
    }

    /**
     * §9.10's DTD writes `DAV:href+`, so there is no multiget without one.
     */
    public function testABodyNamingNoHrefIsRefused(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('DAV:href');

        $this->get([]);
    }

    /**
     * **§7.9: "If the Request-URI refers to a non-collection resource, then
     * there MUST be a single DAV:href element that is equivalent to the
     * Request-URI."** So two of them against one object is a body this server
     * cannot read as the memo asks.
     */
    public function testTwoHrefsAgainstOneObjectAreRefused(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('single DAV:href');

        $this->get(
            ['/calendars/work/meeting.ics', '/calendars/work/review.ics'],
            self::CALENDAR . '/meeting.ics',
        );
    }

    /**
     * And the one that is equivalent is answered, which is the other half of
     * the same sentence.
     */
    public function testOneHrefEquivalentToTheObjectIsAnswered(): void
    {
        $body = (string) $this->get(
            ['/calendars/work/meeting.ics'],
            self::CALENDAR . '/meeting.ics',
        )->body();

        self::assertStringContainsString('SUMMARY:A Meeting', $body);
    }

    /**
     * **The limit refuses rather than truncates.** §7.9 wants one response per
     * href, so a short answer would break that MUST — and §7.9 names no
     * condition of its own ("Postconditions: None"), so the refusal borrows
     * the one WebDAV has for a result set that is too large,
     * `DAV:number-of-matches-within-limits` of RFC 3744 §9.2, which §7.8 names
     * for the same kind of excess.
     */
    public function testMoreHrefsThanItWillAnswerIsRefusedByName(): void
    {
        try {
            $this->get(['meeting.ics', 'review.ics'], atMost: 1);
        } catch (Forbidden $refusal) {
            self::assertSame('{DAV:}number-of-matches-within-limits', $refusal->errorElement());
            self::assertStringContainsString('1', $refusal->getMessage());

            return;
        }

        self::fail('A multiget larger than the limit was answered.');
    }

    /**
     * And exactly as many as it answers are answered: the limit is how many
     * this server will do, not how many it will take.
     */
    public function testAsManyHrefsAsItWillAnswerAreAnswered(): void
    {
        $body = (string) $this->get(['meeting.ics'], atMost: 1)->body();

        self::assertStringContainsString('SUMMARY:A Meeting', $body);
    }

    /**
     * §7.9's `CALDAV:supported-calendar-data` precondition, which is §7.8's:
     * "The attributes 'content-type' and 'version' of the
     * CALDAV:calendar-data XML elements (see Section 9.6) specify a media type
     * supported by the server for calendar object resources."
     *
     * Raised while the body is read, before an object is fetched.
     */
    public function testAMediaTypeItNeverAdvertisedIsRefused(): void
    {
        try {
            $this->get(['meeting.ics'], properties: '<D:prop xmlns:D="DAV:">'
                . '<calendar-data content-type="text/x-vcalendar"/></D:prop>');
        } catch (Forbidden $refusal) {
            self::assertSame(Limits::SUPPORTED_DATA, $refusal->errorElement());

            return;
        }

        self::fail('Calendar data this server does not keep was asked for and taken.');
    }

    /**
     * **§7.9 lists the two date preconditions as well**, in §7.8's words: "Any
     * XML element specifying a range of time MUST have its start or end DATE
     * or DATE-TIME values greater than or equal to the value of the
     * CALDAV:min-date-time property value". A multiget carries no filter, so
     * the ranges it can name are the three of §9.6.
     */
    public function testARangeOutsideTheDatesItKeepsIsRefused(): void
    {
        try {
            $this->get(['meeting.ics'], properties: '<D:prop xmlns:D="DAV:"><calendar-data>'
                . '<expand start="18900101T000000Z" end="18900102T000000Z"/></calendar-data></D:prop>');
        } catch (Forbidden $refusal) {
            self::assertSame(Limits::MIN_DATE_TIME, $refusal->errorElement());

            return;
        }

        self::fail('A range about a time this server keeps nothing from was answered.');
    }

    /**
     * **What floats is read in the zone §7.3 settles**, and for this report
     * that is the second of its three sources: §9.10's body has no
     * `CALDAV:timezone` element, so the `CALDAV:calendar-timezone` property of
     * the calendar holding the object is what is left.
     *
     * The lunch floats at noon. In New York that is five in the afternoon UTC,
     * so a limit around five keeps it and one around noon does not.
     */
    public function testWhatFloatsIsReadInTheCollectionsZone(): void
    {
        $limit = '<D:prop xmlns:D="DAV:"><calendar-data>'
            . '<limit-recurrence-set start="20260105T170000Z" end="20260105T180000Z"/>'
            . '</calendar-data></D:prop>';

        self::assertStringContainsString(
            'SUMMARY:Lunch',
            (string) $this->get(['floating.ics'], properties: $limit, zone: true)->body(),
        );
    }

    /**
     * The whole body, as a client sends it, with the namespace prefixes
     * §7.9.1's own example uses.
     */
    public function testReadsABodyWrittenTheWayTheMemosExampleIs(): void
    {
        $body = <<<'XML'
            <C:calendar-multiget xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
              <D:prop>
                <D:getetag/>
                <C:calendar-data/>
              </D:prop>
              <D:href>/calendars/work/meeting.ics</D:href>
              <D:href>/calendars/work/mtg1.ics</D:href>
            </C:calendar-multiget>
            XML;

        $server = $this->server();
        $answer = (string) (new CalendarMultiGet($server))(
            new Request('REPORT', self::CALENDAR, body: new Body($body)),
            $server->reader()->parse($body),
        )->body();

        self::assertStringContainsString('<d:getetag>', $answer);
        self::assertStringContainsString('SUMMARY:A Meeting', $answer);
        self::assertStringContainsString('HTTP/1.1 404 Not Found', $answer);
    }

    /**
     * Runs a multiget, given the pieces that differ from case to case.
     *
     * @param list<string> $hrefs What the body names
     * @param string $path The Request-URI
     * @param string $properties The optional property group of §9.10
     * @param string|null $depth What the `Depth` header says, or nothing
     * @param int|null $atMost How many hrefs the server will answer
     * @param bool $zone Whether the calendar keeps a `CALDAV:calendar-timezone`
     */
    private function get(
        array $hrefs,
        string $path = self::CALENDAR,
        string $properties = '',
        ?string $depth = '0',
        ?int $atMost = null,
        bool $zone = false,
    ): Response {
        $properties = $properties === '' ? self::ETAG_AND_DATA : $properties;
        $written = '';

        foreach ($hrefs as $href) {
            $written .= sprintf(
                '<D:href xmlns:D="DAV:">%s</D:href>',
                htmlspecialchars(self::under($href, $path), ENT_XML1),
            );
        }

        $body = sprintf(
            '<calendar-multiget xmlns="urn:ietf:params:xml:ns:caldav">%s%s</calendar-multiget>',
            $properties,
            $written,
        );
        $server = $this->server($zone);
        $report = $atMost === null
            ? new CalendarMultiGet($server)
            : new CalendarMultiGet($server, atMost: $atMost);

        return $report(
            new Request(
                'REPORT',
                $path,
                headers: new Headers($depth === null ? [] : ['Depth' => $depth]),
                body: new Body($body),
            ),
            $server->reader()->parse($body),
        );
    }

    /**
     * A home with two calendars; the first holds three objects, a plain folder
     * with a fourth in it, and three members that are no calendar objects.
     *
     * @param bool $zone Whether the first calendar keeps a time zone
     */
    private function server(bool $zone = false): Server
    {
        $store = new MemoryPropertyCollection('work');
        $inner = new MemoryCollection('inner');

        $inner->add(new MemoryFile('deep.ics', $this->objectOf(self::DEEP)));

        $store->add(new MemoryFile('meeting.ics', $this->objectOf(self::MEETING)));
        $store->add(new MemoryFile('review.ics', $this->objectOf(self::REVIEW)));
        $store->add(new MemoryFile('floating.ics', $this->objectOf(self::FLOATING)));
        $store->add(new MemoryFile('note.txt', 'This is a note, not a calendar.'));
        $store->add(new MemoryFile('twice.ics', $this->objectOf(self::MEETING) . $this->objectOf(self::REVIEW)));
        $store->add(new PlainNode('a-principal'));
        $store->add($inner);

        if ($zone) {
            $store->patchProperties([
                '{urn:ietf:params:xml:ns:caldav}calendar-timezone' => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
                    . "PRODID:-//Example Corp.//CalDAV Client//EN\r\nBEGIN:VTIMEZONE\r\n"
                    . "TZID:America/New_York\r\nBEGIN:STANDARD\r\nDTSTART:19671029T020000\r\n"
                    . "TZOFFSETFROM:-0400\r\nTZOFFSETTO:-0500\r\nEND:STANDARD\r\n"
                    . "END:VTIMEZONE\r\nEND:VCALENDAR\r\n",
            ]);
        }

        $away = (new MemoryCollection('holiday'))->add(new MemoryFile('away.ics', $this->objectOf(self::REVIEW)));
        $home = (new MemoryCollection('calendars'))->add(Calendar::of($store))->add(Calendar::of($away));

        // The live properties are wired up because §7.9 answers properties the
        // way a `PROPFIND` does, and a server without them would let these
        // cases pass on a report that answered none.
        $events = new EventEmitter();
        $events->on(PropertiesRequested::class, (new LiveProperties())(...));

        return new Server(new Tree((new MemoryCollection(''))->add($home)), $events);
    }

    /**
     * One href as a client writes it under the Request-URI.
     *
     * A case that names something absolute, or a URL, or a path of its own
     * means it: those are the ones about an href that cannot be answered.
     */
    private static function under(string $href, string $path): string
    {
        if (str_contains($href, '://') || str_starts_with($href, '/') || str_starts_with($href, '.')) {
            return $href;
        }

        return $path . '/' . $href;
    }

    private function objectOf(string $components): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\n"
            . $components . "END:VCALENDAR\r\n";
    }
}
