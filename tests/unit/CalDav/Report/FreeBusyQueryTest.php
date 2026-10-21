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

use DateTimeImmutable;
use DateTimeZone;
use DavServices\CalDav\Calendar;
use DavServices\CalDav\Limits;
use DavServices\CalDav\Report\FreeBusyQuery;
use DavServices\Dav\Event\ListingMembers;
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
 * Test list for `CALDAV:free-busy-query`. R-CAL-12, P6-10.
 *
 * RFC 4791 §9.11:
 *
 *     <!ELEMENT free-busy-query (time-range)>
 *
 * > The CALDAV:free-busy-query REPORT generates a VFREEBUSY component
 * > containing free busy information for all the calendar object resources
 * > targeted by the request and that have the CALDAV:read-free-busy or
 * > DAV:read privilege granted to the current user.
 * >
 * > Support for the CALDAV:free-busy-query REPORT is REQUIRED.
 *
 * **It is the one report that answers with iCalendar.** §7.10: "The response
 * body for a successful request MUST be an iCalendar object that contains
 * exactly one VFREEBUSY component" — no `DAV:multistatus`, no properties, and
 * §7.10.1's example shows the whole of it.
 *
 * What the busy time of an object comes to is
 * {@see \DavServices\CalDav\FreeBusy}'s question, and is tested there: the
 * table of `TRANSP` and `STATUS`, the recurrences, the coalescing. What is
 * tested here is the report around it — the body §9.11 admits, the scope the
 * `Depth` header sets, what the Request-URI may be, and the postcondition
 * §7.10 closes with.
 */
#[CoversClass(FreeBusyQuery::class)]
final class FreeBusyQueryTest extends TestCase
{
    private const CALENDAR = '/calendars/work';

    /** The whole of 5.1.2026 in UTC, which every case asks about. */
    private const DAY = 'start="20260105T000000Z" end="20260106T000000Z"';

    /** What the clock says, so that a `DTSTAMP` can be asserted. */
    private const NOW = '2026-01-01T09:00:00+00:00';

    /** The busy hour of the first object the calendar holds. */
    private const NOON = 'FREEBUSY:20260105T120000Z/20260105T130000Z';

    /** And of the one in the plain folder below it. */
    private const EVENING = 'FREEBUSY:20260105T200000Z/20260105T210000Z';

    /** What this library calls itself in an object it made. */
    private const PRODUCT = '-//davServices//CalDAV Server//EN';

    /**
     * **§7.10.1's own answer**, down to the `Content-Type`: an iCalendar
     * object, `200`, with one `VFREEBUSY` in it.
     *
     * RFC 5545 §3.6 makes `PRODID` and `VERSION` mandatory in a `VCALENDAR`,
     * and §5.2.4 of RFC 4791 has this server keep version 2.0.
     */
    public function testItAnswersWithOneFreeBusyComponentInAnICalendarObject(): void
    {
        $response = $this->query(self::DAY);
        $body = (string) $response->body();

        self::assertSame(200, $response->status());
        self::assertSame('text/calendar; charset=utf-8', $response->headers()->first('Content-Type'));
        self::assertStringStartsWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:", $body);
        self::assertStringEndsWith("END:VFREEBUSY\r\nEND:VCALENDAR\r\n", $body);
        self::assertSame(1, substr_count($body, 'BEGIN:VFREEBUSY'));
        self::assertStringContainsString("DTSTAMP:20260101T090000Z\r\n", $body);
        self::assertStringContainsString(self::NOON, $body);
    }

    /**
     * **RFC 5545 §3.7.3: the `PRODID` "specifies the identifier for the
     * product that created the iCalendar object"** — this library by default,
     * and whatever a deployment calls itself where it says so. The value is
     * free, and writing somebody else's name into an object this server made
     * would be as untrue as writing this one into theirs.
     */
    public function testItSaysWhoMadeTheAnswer(): void
    {
        self::assertStringContainsString(
            'PRODID:' . self::PRODUCT . "\r\n",
            (string) $this->query(self::DAY)->body(),
        );

        self::assertStringContainsString(
            "PRODID:-//Example Corp.//CalDAV Server//EN\r\n",
            (string) $this->query(self::DAY, product: '-//Example Corp.//CalDAV Server//EN')->body(),
        );
    }

    /**
     * **§7.10: "The request MAY include a Depth header. If no Depth header is
     * included, Depth:0 is assumed."** The same default as §7.8's, and at
     * depth zero a collection holds no calendar object resource of its own
     * (§4.2) — so the answer is the empty `VFREEBUSY` the same section makes a
     * MUST.
     *
     * @param string|null $depth What the header says, or nothing at all
     * @param list<string> $reached The periods the walk comes back with
     * @param list<string> $beyond And the ones it does not reach
     */
    #[DataProvider('depthsAndWhatTheyReach')]
    public function testTheDepthHeaderSaysHowFarItReaches(?string $depth, array $reached, array $beyond): void
    {
        $body = (string) $this->query(self::DAY, depth: $depth)->body();

        foreach ($reached as $period) {
            self::assertStringContainsString($period, $body, var_export($depth, true));
        }

        foreach ($beyond as $period) {
            self::assertStringNotContainsString($period, $body, var_export($depth, true));
        }
    }

    /**
     * @return iterable<string, array{?string, list<string>, list<string>}>
     */
    public static function depthsAndWhatTheyReach(): iterable
    {
        yield 'none, which is zero' => [null, [], [self::NOON, self::EVENING]];
        yield 'zero' => ['0', [], [self::NOON, self::EVENING]];
        yield 'one' => ['1', [self::NOON], [self::EVENING]];
        yield 'infinity' => ['infinity', [self::NOON, self::EVENING], []];
    }

    public function testRefusesADepthTheProtocolHasNoMeaningFor(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('0, 1 or infinity');

        $this->query(self::DAY, depth: '2');
    }

    /**
     * **§7.10: "The CALDAV:free-busy-query REPORT request can only be run
     * against a collection (either a regular collection or a calendar
     * collection). An attempt to run the report on a calendar object resource
     * MUST fail and return a 403 (Forbidden) status value."**
     *
     * @param string $path What the Request-URI names
     */
    #[DataProvider('thingsThatAreNoCollection')]
    public function testRunningItAgainstSomethingThatIsNoCollectionIsRefused(string $path): void
    {
        try {
            $this->query(self::DAY, path: $path);
        } catch (Forbidden $refusal) {
            self::assertSame(403, $refusal->status());
            self::assertStringContainsString('collection', $refusal->getMessage());

            return;
        }

        self::fail('A free-busy query against something that is no collection was answered.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function thingsThatAreNoCollection(): iterable
    {
        yield 'a calendar object resource' => [self::CALENDAR . '/meeting.ics'];

        // The memo names the calendar object resource because that is the
        // mistake a client makes; the sentence before it is "can only be run
        // against a collection", and a note is no collection either.
        yield 'something that is no calendar object' => [self::CALENDAR . '/note.txt'];

        yield 'a member that is no file at all' => [self::CALENDAR . '/a-principal'];
    }

    /**
     * And a regular collection is one of the two the memo allows, so the walk
     * goes through a calendar home as readily as through a calendar.
     */
    public function testARegularCollectionIsATargetToo(): void
    {
        $body = (string) $this->query(self::DAY, path: '/calendars', depth: 'infinity')->body();

        self::assertStringContainsString(self::NOON, $body);
    }

    /**
     * §9.11's DTD is `(time-range)` — one, named, and not optional — and §7.10
     * says it again: the body "MUST contain exactly one CALDAV:time-range XML
     * element".
     *
     * @param string $children What the body carries
     */
    #[DataProvider('bodiesThatNameNoOneRange')]
    public function testABodyThatNamesNoOneRangeIsRefused(string $children): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('exactly one CALDAV:time-range');

        $this->queryOf(sprintf(
            '<free-busy-query xmlns="urn:ietf:params:xml:ns:caldav">%s</free-busy-query>',
            $children,
        ));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bodiesThatNameNoOneRange(): iterable
    {
        yield 'none' => [''];
        yield 'two' => [sprintf('<time-range %s/><time-range %s/>', self::DAY, self::DAY)];
        yield 'something else instead' => ['<comp-filter name="VCALENDAR"/>'];
    }

    /**
     * **A child §9.11 does not name is left where it is.** It cannot change
     * what the report answers — the one range decides that — which is the
     * reading {@see CalendarMultiGet} gives a body carrying more than §9.10's
     * own children as well.
     */
    public function testAChildTheDefinitionDoesNotNameIsPassedOver(): void
    {
        $body = (string) $this->queryOf(sprintf(
            '<free-busy-query xmlns="urn:ietf:params:xml:ns:caldav"><time-range %s/><prop/></free-busy-query>',
            self::DAY,
        ))->body();

        self::assertStringContainsString(self::NOON, $body);
    }

    /**
     * The range itself is §9.9's, so what that section refuses is refused
     * here: "Both attributes MUST be specified as 'date with UTC time' value."
     */
    public function testARangeThatIsNotOneIsRefused(): void
    {
        $this->expectException(BadRequest::class);

        $this->query('start="20260105T000000" end="20260106T000000Z"');
    }

    /**
     * **§7.10 names no preconditions of its own** — "Preconditions: None" —
     * and the two date ones of §7.8 are nonetheless about "any XML element
     * specifying a range of time", which is the whole of this body. A question
     * about a century this server keeps nothing from is refused by the
     * property that says so.
     *
     * @param string $range What the `CALDAV:time-range` says
     * @param string $element The precondition the refusal names
     */
    #[DataProvider('rangesOutsideTheDatesItKeeps')]
    public function testARangeOutsideTheDatesItKeepsIsRefused(string $range, string $element): void
    {
        try {
            $this->query($range);
        } catch (Forbidden $refusal) {
            self::assertSame($element, $refusal->errorElement());

            return;
        }

        self::fail('A range about a time this server keeps nothing from was answered.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rangesOutsideTheDatesItKeeps(): iterable
    {
        yield 'before the earliest' => ['start="18900101T000000Z" end="18900102T000000Z"', Limits::MIN_DATE_TIME];
        yield 'after the latest' => ['start="21500101T000000Z" end="21500102T000000Z"', Limits::MAX_DATE_TIME];
    }

    /**
     * **§7.10's postcondition**: "(DAV:number-of-matches-within-limits): The
     * number of matching calendar object resources must fall within
     * server-specific, predefined limits. For example, this postcondition
     * might fail if the specified CALDAV:time-range would cause an extremely
     * large number of calendar object resources to be considered in computing
     * the response."
     *
     * **It counts what is considered, not what turns out to be busy.** That is
     * the memo's own word — "considered in computing the response" — and the
     * cost of the report is in the objects read rather than in the periods
     * they come to.
     */
    public function testMoreObjectsThanItWillConsiderIsRefusedByName(): void
    {
        try {
            $this->query(self::DAY, atMost: 1);
        } catch (Forbidden $refusal) {
            self::assertSame(403, $refusal->status());
            self::assertSame('{DAV:}number-of-matches-within-limits', $refusal->errorElement());

            return;
        }

        self::fail('A range this server will not compute over was computed.');
    }

    /**
     * And the limit is how many it **does** consider, so a calendar of exactly
     * that many is answered.
     */
    public function testExactlyAsManyAsItWillConsiderIsAnswered(): void
    {
        $body = (string) $this->query(self::DAY, atMost: 2)->body();

        self::assertStringContainsString(self::NOON, $body);
    }

    /**
     * **§7.3 names the source itself for this report**: "For the
     * CALDAV:free-busy-query REPORT, CalDAV servers MUST rely on the value of
     * the CALDAV:calendar-timezone property, if defined, to compute the proper
     * FREEBUSY time period value as date with UTC time for calendar components
     * scheduled with 'floating date' or 'floating time'."
     *
     * Which is §7.3's **second** source and not its first: §9.11's body is
     * `(time-range)`, with no `CALDAV:timezone` in it to carry one.
     *
     * The lunch floats at noon, and in New York that is five in the afternoon
     * UTC.
     */
    public function testWhatFloatsIsReadInTheCollectionsZone(): void
    {
        $body = (string) $this->query(self::DAY, zone: true)->body();

        self::assertStringContainsString('FREEBUSY:20260105T170000Z/20260105T180000Z', $body);
    }

    /**
     * A member that is no calendar object resource is passed over, the way
     * every other report passes one over (§4.1): a report that fell over on a
     * note would answer nothing about the calendar holding it.
     */
    public function testAMemberThatIsNoCalendarObjectIsPassedOver(): void
    {
        $body = (string) $this->query(self::DAY)->body();

        self::assertStringContainsString(self::NOON, $body);
        self::assertSame(2, substr_count($body, 'FREEBUSY:'));
    }

    /**
     * **A member a client may not see says nothing about its busy time**
     * (R-ACL-06). The walk goes through {@see \DavServices\Dav\VisibleMembers},
     * so concealment lives in one place — and §7.10 asks for the busy time of
     * the resources "that have the CALDAV:read-free-busy or DAV:read privilege
     * granted to the current user", which is the question a listing is already
     * asked.
     */
    public function testAConcealedMemberSaysNothingAboutItsBusyTime(): void
    {
        $server = $this->server();

        $server->events()->on(ListingMembers::class, static function (ListingMembers $event): void {
            $event->conceal('calendars/work/meeting.ics');
        });

        $body = (string) $this->queryOf($this->body(self::DAY), server: $server)->body();

        self::assertStringNotContainsString(self::NOON, $body);
    }

    /**
     * A report built without a clock reads the one on the wall. Every other
     * case here hands the time in so that it can say what time it is; this one
     * proves the server does not need to be told.
     *
     * The `DTSTAMP` is asserted as a stretch rather than a moment, because the
     * moment is whatever it was when the line ran — but RFC 5545 §3.8.7.2 has
     * it name "the date and time that the instance of the iCalendar object was
     * created", so a clock that stood still or ran away would show here.
     */
    public function testReadsTheClockWhereItWasNotToldTheTime(): void
    {
        $before = new DateTimeImmutable('-1 minute');
        $server = $this->server();
        $report = new FreeBusyQuery($server);
        $body = $this->body(self::DAY);

        $answer = (string) $report(
            new Request('REPORT', self::CALENDAR, headers: new Headers(['Depth' => '1']), body: new Body($body)),
            $server->reader()->parse($body),
        )->body();

        if (preg_match('/^DTSTAMP:(\d{8}T\d{6}Z)\r$/m', $answer, $found) !== 1) {
            self::fail('The answer carries no DTSTAMP in UTC: ' . $answer);
        }

        $stamped = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $found[1], new DateTimeZone('UTC'));

        self::assertNotFalse($stamped);
        self::assertGreaterThan($before, $stamped);
        self::assertLessThan(new DateTimeImmutable('+1 minute'), $stamped);
    }

    /**
     * The whole body, as §7.10.1's own example writes it.
     */
    public function testReadsABodyWrittenTheWayTheMemosExampleIs(): void
    {
        $body = <<<'XML'
            <C:free-busy-query xmlns:C="urn:ietf:params:xml:ns:caldav">
              <C:time-range start="20260105T000000Z"
                              end="20260106T000000Z"/>
            </C:free-busy-query>
            XML;

        self::assertStringContainsString(self::NOON, (string) $this->queryOf($body)->body());
    }

    /**
     * Runs a query, given the pieces that differ from case to case.
     *
     * @param string $range What the `CALDAV:time-range` says
     * @param string $path The Request-URI
     * @param string|null $depth What the `Depth` header says
     * @param int|null $atMost How many objects the server will consider
     * @param bool $zone Whether the calendar keeps a time zone, and an object
     *                   with nothing but floating values to read in it
     * @param string|null $product What the answer says made it
     */
    private function query(
        string $range,
        string $path = self::CALENDAR,
        ?string $depth = '1',
        ?int $atMost = null,
        bool $zone = false,
        ?string $product = null,
    ): Response {
        return $this->queryOf($this->body($range), $path, $depth, $atMost, $this->server($zone), $product);
    }

    private function queryOf(
        string $body,
        string $path = self::CALENDAR,
        ?string $depth = '1',
        ?int $atMost = null,
        ?Server $server = null,
        ?string $product = null,
    ): Response {
        $server ??= $this->server();
        $report = new FreeBusyQuery(
            $server,
            atMost: $atMost ?? 5_000,
            now: static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW),
            product: $product ?? self::PRODUCT,
        );

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

    private function body(string $range): string
    {
        return sprintf(
            '<free-busy-query xmlns="urn:ietf:params:xml:ns:caldav"><time-range %s/></free-busy-query>',
            $range,
        );
    }

    /**
     * A home with one calendar, holding two busy hours, a plain folder with a
     * third below it, and two members that are no calendar object resources.
     *
     * @param bool $zone Whether the calendar keeps a `CALDAV:calendar-timezone`
     *                   and an object with nothing but floating values
     */
    private function server(bool $zone = false): Server
    {
        $store = new MemoryPropertyCollection('work');
        $inner = new MemoryCollection('inner');

        $inner->add(new MemoryFile('deep.ics', $this->objectOf(
            self::event('4@example.com', '20260105T200000Z', '20260105T210000Z'),
        )));

        $store->add(new MemoryFile('meeting.ics', $this->objectOf(
            self::event('1@example.com', '20260105T120000Z', '20260105T130000Z'),
        )));
        $store->add(new MemoryFile('review.ics', $this->objectOf(
            self::event('2@example.com', '20260105T150000Z', '20260105T160000Z'),
        )));
        $store->add(new MemoryFile('note.txt', 'This is a note, not a calendar.'));
        $store->add(new PlainNode('a-principal'));
        $store->add($inner);

        if ($zone) {
            $store->add(new MemoryFile('lunch.ics', $this->objectOf(
                "BEGIN:VEVENT\r\nUID:3@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
                . "DTSTART:20260105T120000\r\nDTEND:20260105T130000\r\nSUMMARY:Lunch\r\nEND:VEVENT\r\n",
            )));
            $store->patchProperties([
                '{urn:ietf:params:xml:ns:caldav}calendar-timezone' => $this->zoneCalled('America/New_York'),
            ]);
        }

        $home = (new MemoryCollection('calendars'))->add(Calendar::of($store));

        return new Server(new Tree((new MemoryCollection(''))->add($home)), new EventEmitter());
    }

    /**
     * §5.2.2's own example, under whichever name a case wants.
     */
    private function zoneCalled(string $tzid): string
    {
        return "BEGIN:VCALENDAR\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\nVERSION:2.0\r\n"
            . 'BEGIN:VTIMEZONE' . "\r\nTZID:" . $tzid . "\r\nBEGIN:STANDARD\r\nDTSTART:19671029T020000\r\n"
            . "RRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=10\r\nTZOFFSETFROM:-0400\r\nTZOFFSETTO:-0500\r\n"
            . "END:STANDARD\r\nEND:VTIMEZONE\r\nEND:VCALENDAR\r\n";
    }

    private static function event(string $uid, string $from, string $until): string
    {
        return "BEGIN:VEVENT\r\nUID:" . $uid . "\r\nDTSTAMP:20260101T000000Z\r\n"
            . 'DTSTART:' . $from . "\r\nDTEND:" . $until . "\r\nSUMMARY:An hour\r\nEND:VEVENT\r\n";
    }

    private function objectOf(string $components): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\n"
            . $components . "END:VCALENDAR\r\n";
    }
}
