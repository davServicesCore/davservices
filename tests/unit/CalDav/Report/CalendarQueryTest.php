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
use DavServices\CalDav\Filter\Filter;
use DavServices\CalDav\Limits;
use DavServices\CalDav\Report\CalendarQuery;
use DavServices\CalDav\TimeZone;
use DavServices\Dav\Event\ListingMembers;
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
use DavServices\Xml\Element;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for `CALDAV:calendar-query`. R-CAL-10, P6-06.
 *
 * RFC 4791 §7.8 and §9.5:
 *
 *     <!ELEMENT calendar-query ((DAV:allprop |
 *                                DAV:propname |
 *                                DAV:prop)?, filter, timezone?)>
 *
 * "The CALDAV:calendar-query REPORT performs a search for all calendar object
 * resources that match a specified filter. […] Support for the
 * CALDAV:calendar-query REPORT is REQUIRED."
 *
 * The filter itself is tested where it lives, under `CalDav\Filter`, and what
 * a response carries under `CalDav\Report\WhatToReturn`. What is tested here is
 * the report around them: the scope the `Depth` header sets, the zone §7.3
 * picks for what floats, the preconditions §7.8 lists, and the postcondition
 * it closes with.
 */
#[CoversClass(CalendarQuery::class)]
final class CalendarQueryTest extends TestCase
{
    private const CALENDAR = '/calendars/work';

    private const MEETING = "BEGIN:VEVENT\r\nUID:1@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
        . "DTSTART:20260105T120000Z\r\nDTEND:20260105T130000Z\r\nSUMMARY:A Meeting\r\nEND:VEVENT\r\n";

    private const REVIEW = "BEGIN:VEVENT\r\nUID:2@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
        . "DTSTART:20260210T090000Z\r\nDTEND:20260210T100000Z\r\nSUMMARY:The Review\r\nEND:VEVENT\r\n";

    /** No `Z` and no `TZID`: an instant only once somebody picks a zone. */
    private const FLOATING = "BEGIN:VEVENT\r\nUID:3@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
        . "DTSTART:20260105T120000\r\nDTEND:20260105T130000\r\nSUMMARY:Lunch\r\nEND:VEVENT\r\n";

    private const DEEP = "BEGIN:VEVENT\r\nUID:4@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
        . "DTSTART:20260105T140000Z\r\nDTEND:20260105T150000Z\r\nSUMMARY:A Meeting Below\r\nEND:VEVENT\r\n";

    /** The same floating lunch, in the plain folder a calendar may hold. */
    private const FLOATING_BELOW = "BEGIN:VEVENT\r\nUID:5@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
        . "DTSTART:20260105T120000\r\nDTEND:20260105T130000\r\nSUMMARY:Lunch Below\r\nEND:VEVENT\r\n";

    /** And once more in the calendar beside it, which keeps its own zone. */
    private const FLOATING_BESIDE = "BEGIN:VEVENT\r\nUID:6@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
        . "DTSTART:20260105T120000\r\nDTEND:20260105T130000\r\nSUMMARY:Lunch Away\r\nEND:VEVENT\r\n";

    /** Everything, which is what an empty `comp-filter` for the object is. */
    private const EVERYTHING = '<comp-filter name="VCALENDAR"/>';

    private const BY_SUMMARY = '<comp-filter name="VCALENDAR"><comp-filter name="VEVENT">'
        . '<prop-filter name="SUMMARY"><text-match>a meeting</text-match></prop-filter>'
        . '</comp-filter></comp-filter>';

    /** Five in the afternoon in UTC: noon in New York, and not noon in Berlin. */
    private const AT_FIVE = '<comp-filter name="VCALENDAR"><comp-filter name="VEVENT">'
        . '<time-range start="20260105T170000Z" end="20260105T180000Z"/>'
        . '</comp-filter></comp-filter>';

    /** Noon in UTC, which is where a floating value sits when nobody says. */
    private const AT_NOON = '<comp-filter name="VCALENDAR"><comp-filter name="VEVENT">'
        . '<time-range start="20260105T120000Z" end="20260105T130000Z"/>'
        . '</comp-filter></comp-filter>';

    public function testFindsTheObjectsTheFilterMatches(): void
    {
        $body = (string) $this->query(self::BY_SUMMARY)->body();

        self::assertStringContainsString('/calendars/work/meeting.ics', $body);
        self::assertStringNotContainsString('review.ics', $body);
    }

    /**
     * §7.8: "The response body for a successful request MUST be a
     * DAV:multistatus XML element (i.e., the response uses the same format as
     * the response for PROPFIND)."
     */
    public function testAnswersTwoOhSevenWithAMultistatus(): void
    {
        $response = $this->query(self::BY_SUMMARY);

        self::assertSame(207, $response->status());
        self::assertSame('application/xml; charset=utf-8', $response->headers()->first('Content-Type'));
        self::assertStringContainsString('<d:multistatus', (string) $response->body());
    }

    /**
     * §7.8: "In the case where there are no response elements, the returned
     * DAV:multistatus XML element is empty." So a search that finds nothing is
     * an answer rather than a `404`.
     */
    public function testAMatchlessSearchIsAnEmptyMultistatus(): void
    {
        $response = $this->query(
            '<comp-filter name="VCALENDAR"><comp-filter name="VEVENT">'
            . '<prop-filter name="SUMMARY"><text-match>nothing of the sort</text-match></prop-filter>'
            . '</comp-filter></comp-filter>',
        );

        self::assertSame(207, $response->status());
        self::assertStringNotContainsString('<d:response>', (string) $response->body());
    }

    /**
     * **§7.8: "The request MAY include a Depth header. If no Depth header is
     * included, Depth:0 is assumed."**
     *
     * The opposite of RFC 6352 §8.6, which raises the header to a MUST for its
     * own report — so where an `addressbook-query` without one is refused,
     * this one is answered at depth zero. And at depth zero a calendar
     * collection holds no calendar object resource of its own: §4.2 makes it a
     * collection "that only contains calendar object resources", which is
     * about its members. The empty answer is therefore the memo's own default
     * rather than this server's choice.
     */
    public function testWithoutADepthHeaderOnlyTheTargetItselfIsAsked(): void
    {
        $response = $this->query(self::EVERYTHING, null);

        self::assertSame(207, $response->status());
        self::assertStringNotContainsString('<d:response>', (string) $response->body());
    }

    /**
     * And the target itself is asked, which is what depth zero means: a query
     * aimed at one object resource answers about that one.
     */
    public function testAtDepthZeroAnObjectResourceAnswersForItself(): void
    {
        $body = (string) $this->query(self::EVERYTHING, null, self::CALENDAR . '/meeting.ics')->body();

        self::assertStringContainsString('/calendars/work/meeting.ics', $body);
    }

    public function testAtDepthOneTheMembersAreAsked(): void
    {
        $body = (string) $this->query(self::EVERYTHING, '1')->body();

        self::assertStringContainsString('meeting.ics', $body);
        self::assertStringContainsString('review.ics', $body);
        self::assertStringNotContainsString('deep.ics', $body);
    }

    /**
     * §4.2 lets a calendar hold "collections that are not calendar
     * collections", so there is something below depth one to find — and
     * `Depth: infinity` is what finds it.
     */
    public function testAtDepthInfinityWhatLiesDeeperIsAsked(): void
    {
        $body = (string) $this->query(self::EVERYTHING, 'infinity')->body();

        self::assertStringContainsString('meeting.ics', $body);
        self::assertStringContainsString('inner/deep.ics', $body);
    }

    /**
     * **And the header is read without regard to case.** RFC 4918 §10.2 writes
     * the value as `("0" | "1" | "infinity")`, and RFC 5234 §2.3 makes the
     * grammar's own strings case-insensitive: "ABNF strings are case
     * insensitive". A server that answered only the quiet spelling would read
     * a legal request as a depth it has no meaning for.
     */
    public function testADepthWrittenInAnotherCaseIsTheSameDepth(): void
    {
        $body = (string) $this->query(self::EVERYTHING, 'Infinity')->body();

        self::assertStringContainsString('inner/deep.ics', $body);
    }

    public function testRefusesADepthTheProtocolHasNoMeaningFor(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('0, 1 or infinity');

        $this->query(self::EVERYTHING, '2');
    }

    /**
     * **§7.8 has the response carry "a DAV:response element for each iCalendar
     * object that matched the search filter"**, and §4.1 says what one is: "a
     * calendar object resource MUST contain at least one calendar component".
     * A member that is none — a note, a file holding two objects, something
     * that is no file at all — is therefore passed over rather than failing
     * the whole search over data the client cannot see and could not correct.
     */
    public function testAMemberThatIsNoCalendarObjectIsPassedOver(): void
    {
        $body = (string) $this->query(self::EVERYTHING, 'infinity')->body();

        self::assertStringContainsString('meeting.ics', $body);
        self::assertStringNotContainsString('note.txt', $body);
        self::assertStringNotContainsString('twice.ics', $body);
        self::assertStringNotContainsString('empty.ics', $body);
        self::assertStringNotContainsString('a-principal', $body);
    }

    /**
     * **A member a client may not see is not found by searching for it**
     * either (R-ACL-06): a search that listed it would have told the client it
     * exists, which is most of what somebody wanted to know. The walk goes
     * through {@see \DavServices\Dav\VisibleMembers}, so concealment lives in
     * one place.
     */
    public function testAConcealedMemberIsNotFound(): void
    {
        $server = $this->server();

        // The paths a listener is given are the tree's own, which carry no
        // leading slash: that belongs to the href a response names.
        $server->events()->on(ListingMembers::class, static function (ListingMembers $event): void {
            $event->conceal('calendars/work/meeting.ics');
        });

        $body = (string) $this->query(self::EVERYTHING, '1', self::CALENDAR, server: $server)->body();

        self::assertStringNotContainsString('meeting.ics', $body);
        self::assertStringContainsString('review.ics', $body);
    }

    /**
     * §7.8: "Calendar data is being returned in the CALDAV:calendar-data XML
     * element inside the DAV:propstat XML element."
     */
    public function testTheCalendarDataComesBackWhenItIsAskedFor(): void
    {
        $body = (string) $this->query(
            self::BY_SUMMARY,
            properties: '<D:prop xmlns:D="DAV:"><D:getetag/><calendar-data/></D:prop>',
        )->body();

        self::assertStringContainsString('<d:getetag>', $body);
        self::assertStringContainsString('SUMMARY:A Meeting', $body);
    }

    /**
     * **§7.3 names the first of three sources for what floats**: "CalDAV
     * servers MUST rely on the value of the CALDAV:timezone XML element, if
     * specified as part of the request body, to perform the proper conversion
     * of 'floating date' and 'floating time' values to date with UTC time
     * values."
     *
     * Lunch at noon, written with neither a `Z` nor a `TZID`, is at five in
     * the afternoon UTC in New York — so the range that finds it is proof the
     * zone was used, and the same range finding nothing without one is proof
     * it was needed.
     */
    public function testTheRequestsTimeZoneIsWhatFloatingTimeIsReadIn(): void
    {
        $found = (string) $this->query(self::AT_FIVE, timezone: $this->timezone('America/New_York'))->body();

        self::assertStringContainsString('floating.ics', $found);
        self::assertStringNotContainsString('floating.ics', (string) $this->query(self::AT_FIVE)->body());
    }

    /**
     * **And a `VTIMEZONE` no catalogue names is used as it stands**, which
     * §5.2.2's own example makes necessary: it calls its zone `US-Eastern`,
     * a name the IANA database has not got. §9.8 says the server "MUST rely on
     * the specified VTIMEZONE component", so the observances are the zone.
     */
    public function testAZoneNothingNamesIsUsedAsItStands(): void
    {
        $body = (string) $this->query(self::AT_FIVE, timezone: $this->timezone('US-Eastern'))->body();

        self::assertStringContainsString('floating.ics', $body);
    }

    /**
     * §7.8's `CALDAV:valid-calendar-data` precondition: "The time zone
     * specified in the REPORT request MUST be a valid iCalendar object
     * containing a single valid VTIMEZONE component."
     */
    public function testRefusesATimeZoneThatIsNoValidObject(): void
    {
        try {
            $this->query(self::AT_FIVE, timezone: '<timezone>Europe/Berlin</timezone>');
        } catch (Forbidden $refusal) {
            self::assertSame(TimeZone::VALID, $refusal->errorElement());
            self::assertSame(403, $refusal->status());

            return;
        }

        self::fail('A time zone nothing can be resolved with was taken.');
    }

    /**
     * **§7.3's second source**: "If the CALDAV:timezone XML element is not
     * specified in the request body, CalDAV servers MUST rely on the value of
     * the CALDAV:calendar-timezone property, if defined".
     */
    public function testTheCollectionsTimeZoneIsUsedWhereTheRequestCarriesNone(): void
    {
        $body = (string) $this->query(
            self::AT_FIVE,
            server: $this->server($this->zoneCalled('America/New_York')),
        )->body();

        self::assertStringContainsString('floating.ics', $body);
    }

    /**
     * And the request's own wins over it, which is the word §9.8 uses: the
     * server relies on the element "instead of the CALDAV:calendar-timezone
     * property of the calendar collection".
     */
    public function testTheRequestsTimeZoneWinsOverTheCollections(): void
    {
        $body = (string) $this->query(
            self::AT_FIVE,
            timezone: $this->timezone('America/New_York'),
            server: $this->server($this->zoneCalled('Europe/Berlin')),
        )->body();

        self::assertStringContainsString('floating.ics', $body);
    }

    /**
     * **However the collection keeps it.** A `MKCALENDAR` hands the property
     * over as the text it carried, while a `PROPPATCH` hands over the element
     * it was written in (§5.2.2's `<!ELEMENT calendar-timezone (#PCDATA)>`) —
     * so a store may hold either, and both say the same zone.
     */
    public function testTheCollectionsTimeZoneIsReadFromAnElementToo(): void
    {
        $element = new Element('{urn:ietf:params:xml:ns:caldav}calendar-timezone');

        $element->appendText($this->zoneCalled('America/New_York'));

        $body = (string) $this->query(self::AT_FIVE, server: $this->server($element))->body();

        self::assertStringContainsString('floating.ics', $body);
    }

    /**
     * **And a value nothing can be resolved with is treated as none.** §5.2.10
     * and §5.3.1 refuse such a value on the way in, so one in a store got
     * there another way — and §7.3's last clause, a zone of the server's
     * choice, is a better answer than a report that fails over a property the
     * client did not even name.
     */
    public function testAStoredTimeZoneNothingCanBeResolvedWithIsTreatedAsNone(): void
    {
        $server = $this->server('Europe/Berlin, which is a name and no VTIMEZONE');

        self::assertStringContainsString('floating.ics', (string) $this->query(self::AT_NOON, server: $server)->body());
        self::assertStringNotContainsString('floating.ics', (string) $this->query(self::AT_FIVE, server: $server)->body());
    }

    /**
     * **An object resource named outright is read with the zone of the
     * calendar holding it**, which is the collection §9.8 names: "the
     * CALDAV:calendar-timezone property of the calendar collection, in which
     * the calendar object resource is contained". There is no walk to pick it
     * up on the way down, so it is looked up from the object's own place.
     */
    public function testAnObjectResourceNamedOutrightIsReadWithItsCalendarsZone(): void
    {
        $body = (string) $this->query(
            self::AT_FIVE,
            null,
            self::CALENDAR . '/floating.ics',
            server: $this->server($this->zoneCalled('America/New_York')),
        )->body();

        self::assertStringContainsString('floating.ics', $body);
    }

    /**
     * **Each calendar is read with its own zone**, which is what §9.8 asks of
     * a query that reaches more than one: the property belongs to "the
     * calendar collection, in which the calendar object resource is
     * contained", so a walk over a home cannot carry one calendar's zone into
     * the next.
     *
     * Both calendars hold a lunch at noon with neither a `Z` nor a `TZID`. In
     * New York that is five in the afternoon UTC; in Berlin it is eleven in
     * the morning. The range at five therefore finds one of the two, and which
     * one it finds says the zone was read where the objects are.
     */
    public function testAtDepthInfinityEachCalendarIsReadWithItsOwnZone(): void
    {
        $body = (string) $this->query(
            self::AT_FIVE,
            'infinity',
            '/calendars',
            server: $this->server(
                $this->zoneCalled('Europe/Berlin'),
                beside: $this->zoneCalled('America/New_York'),
            ),
        )->body();

        self::assertStringContainsString('holiday/away.ics', $body);
        self::assertStringNotContainsString('work/floating.ics', $body);
    }

    /**
     * **And a zone kept above a calendar is not that calendar's.** §5.2.2
     * defines `CALDAV:calendar-timezone` on a calendar collection, and §9.8
     * names the collection holding the resource — a calendar home is neither,
     * so a property somebody put there says nothing about what floats inside
     * the calendars below it.
     */
    public function testAZoneKeptAboveACalendarIsNotTheCalendarsZone(): void
    {
        $server = $this->server(above: $this->zoneCalled('America/New_York'));

        self::assertStringNotContainsString('floating.ics', (string) $this->query(self::AT_FIVE, server: $server)->body());
        self::assertStringContainsString('floating.ics', (string) $this->query(self::AT_NOON, server: $server)->body());
    }

    /**
     * **An object in a plain folder inside a calendar keeps that calendar's
     * zone.** §4.2 lets a calendar hold "collections that are not calendar
     * collections", and such a folder is no calendar collection — so there is
     * no property of its own to read, and the calendar it sits in is the
     * nearest one §9.8 can mean.
     */
    public function testAnObjectBelowAPlainFolderIsReadWithTheCalendarsZone(): void
    {
        $body = (string) $this->query(
            self::AT_FIVE,
            '1',
            self::CALENDAR . '/inner',
            server: $this->server($this->zoneCalled('America/New_York')),
        )->body();

        self::assertStringContainsString('lunch.ics', $body);
    }

    /**
     * **§7.3's third source, which is a choice rather than a rule**: "or else
     * the CalDAV servers MAY rely on the time zone of their choice." UTC is
     * the choice here, because §9.9's own tables are written against "date
     * with UTC time" values and a server that picked a zone of its own would
     * answer the same query differently from one that did not.
     */
    public function testWithNoZoneAnywhereFloatingTimeIsReadInUtc(): void
    {
        $body = (string) $this->query(self::AT_NOON)->body();

        self::assertStringContainsString('floating.ics', $body);
    }

    /**
     * §7.8's `CALDAV:min-date-time` precondition: "Any XML element specifying
     * a range of time MUST have its start or end DATE or DATE-TIME values
     * greater than or equal to the value of the CALDAV:min-date-time property
     * value (Section 5.2.6) on the calendar collections being targeted by the
     * REPORT request".
     */
    public function testRefusesARangeEarlierThanTheDatesItKeeps(): void
    {
        try {
            $this->query(
                '<comp-filter name="VCALENDAR"><comp-filter name="VEVENT">'
                . '<time-range start="18900101T000000Z" end="18900102T000000Z"/>'
                . '</comp-filter></comp-filter>',
            );
        } catch (Forbidden $refusal) {
            self::assertSame(Limits::MIN_DATE_TIME, $refusal->errorElement());

            return;
        }

        self::fail('A query about a time this server keeps nothing from was answered.');
    }

    /**
     * **Any such element, wherever it sits.** The sentence above says "any XML
     * element specifying a range of time", and §9.7.2 lets one sit inside a
     * `prop-filter` as well — so a walk that looked only at the components
     * would take a query the memo refuses.
     */
    public function testRefusesARangeInsideAPropertyFilterToo(): void
    {
        try {
            $this->query(
                '<comp-filter name="VCALENDAR"><comp-filter name="VEVENT">'
                . '<prop-filter name="DTSTART">'
                . '<time-range start="21000101T000000Z" end="21000102T000000Z"/>'
                . '</prop-filter></comp-filter></comp-filter>',
            );
        } catch (Forbidden $refusal) {
            self::assertSame(Limits::MAX_DATE_TIME, $refusal->errorElement());

            return;
        }

        self::fail('A query about a time this server keeps nothing to was answered.');
    }

    /**
     * **Every range in the body, not the first of them.** §7.8 says "any XML
     * element specifying a range of time", and §9.7.2's DTD puts a
     * `prop-filter` after the `comp-filter`'s own range — so a check that
     * stopped at the first one it found would take a query the memo refuses.
     */
    public function testARangeBesideAnotherOneIsCheckedToo(): void
    {
        try {
            $this->query(
                '<comp-filter name="VCALENDAR"><comp-filter name="VEVENT">'
                . '<time-range start="20260105T120000Z" end="20260105T130000Z"/>'
                . '<prop-filter name="DTSTART">'
                . '<time-range start="21000101T000000Z" end="21000102T000000Z"/>'
                . '</prop-filter></comp-filter></comp-filter>',
            );
        } catch (Forbidden $refusal) {
            self::assertSame(Limits::MAX_DATE_TIME, $refusal->errorElement());

            return;
        }

        self::fail('A second range outside the dates this server keeps was answered.');
    }

    /**
     * §9.5's DTD has the filter outside the optional group — `((DAV:allprop |
     * DAV:propname | DAV:prop)?, filter, timezone?)` — so there is no query
     * without one.
     */
    public function testABodyWithoutAFilterIsRefused(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('CALDAV:filter');

        $this->queryOf('<calendar-query xmlns="urn:ietf:params:xml:ns:caldav"/>', '1', self::CALENDAR);
    }

    /**
     * **A filter that cannot be answered is refused with §7.8's own
     * precondition**, which the filter raises where it is read and the report
     * lets through rather than turning into an empty answer.
     */
    public function testAFilterThatCannotBeAnsweredIsRefused(): void
    {
        try {
            $this->query('<comp-filter name="VTODO"><comp-filter name="VEVENT"/></comp-filter>');
        } catch (Forbidden $refusal) {
            self::assertSame(Filter::VALID, $refusal->errorElement());

            return;
        }

        self::fail('A filter nesting an event in a to-do was answered.');
    }

    /**
     * **§7.8's postcondition: "(DAV:number-of-matches-within-limits): The
     * number of matching calendar object resources must fall within
     * server-specific, predefined limits."**
     *
     * And the report refuses rather than truncating, because the same section
     * makes the full answer a MUST: "The response body for a successful
     * CALDAV:calendar-query REPORT request MUST contain a DAV:response element
     * for each iCalendar object that matched the search filter." A `207` that
     * left matches out would break that sentence while claiming success —
     * unlike RFC 6352 §8.6.2, which spells out a truncated answer and is why
     * `addressbook-query` may give one.
     */
    public function testTooManyMatchesIsRefusedWithThePostcondition(): void
    {
        try {
            $this->query(self::EVERYTHING, '1', atMost: 1);
        } catch (Forbidden $refusal) {
            self::assertSame('{DAV:}number-of-matches-within-limits', $refusal->errorElement());
            self::assertSame(403, $refusal->status());
            self::assertStringContainsString('1', $refusal->getMessage());

            return;
        }

        self::fail('A search wider than the server answers was answered.');
    }

    /**
     * **One more match than it will answer is one too many**, which is the
     * whole of the postcondition: a server that answered the first of two
     * where it promised one would be truncating by another name.
     */
    public function testOneMatchMoreThanItWillAnswerIsRefused(): void
    {
        // Noon finds two of the three objects: the meeting written in UTC and
        // the lunch that floats there.
        try {
            $this->query(self::AT_NOON, '1', atMost: 1);
        } catch (Forbidden $refusal) {
            self::assertSame('{DAV:}number-of-matches-within-limits', $refusal->errorElement());

            return;
        }

        self::fail('Two matches were answered where one was promised.');
    }

    /**
     * And a search inside the limit is answered, or the refusal above would be
     * every search's answer.
     */
    public function testASearchWithinTheLimitIsAnswered(): void
    {
        $response = $this->query(self::BY_SUMMARY, '1', atMost: 1);

        self::assertSame(207, $response->status());
        self::assertStringContainsString('meeting.ics', (string) $response->body());
    }

    /**
     * The whole body, as a client sends it, with the namespace prefixes the
     * memo's own examples use: the report has to read its own element as well
     * as the pieces inside it.
     */
    public function testReadsABodyWrittenTheWayTheMemosExamplesAre(): void
    {
        $body = <<<'XML'
            <C:calendar-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
              <D:prop>
                <D:getetag/>
                <C:calendar-data/>
              </D:prop>
              <C:filter>
                <C:comp-filter name="VCALENDAR">
                  <C:comp-filter name="VEVENT">
                    <C:time-range start="20260105T000000Z" end="20260106T000000Z"/>
                  </C:comp-filter>
                </C:comp-filter>
              </C:filter>
            </C:calendar-query>
            XML;

        $answer = (string) $this->queryOf($body, '1', self::CALENDAR)->body();

        self::assertStringContainsString('meeting.ics', $answer);
        self::assertStringContainsString('SUMMARY:A Meeting', $answer);
        self::assertStringNotContainsString('review.ics', $answer);
    }

    /**
     * Runs a query, given the pieces that differ from case to case.
     *
     * @param string $conditions What goes inside the `CALDAV:filter`
     * @param string|null $depth What the `Depth` header says, or null where the
     *                           request carries none
     * @param string $properties The optional property group of §9.5
     * @param string $timezone A whole `CALDAV:timezone` element, or nothing
     * @param int|null $atMost How many matches the server will answer
     */
    private function query(
        string $conditions,
        ?string $depth = '1',
        string $path = self::CALENDAR,
        string $properties = '',
        string $timezone = '',
        ?int $atMost = null,
        ?Server $server = null,
    ): Response {
        $body = sprintf(
            '<calendar-query xmlns="urn:ietf:params:xml:ns:caldav">%s<filter>%s</filter>%s</calendar-query>',
            $properties,
            $conditions,
            $timezone,
        );

        return $this->queryOf($body, $depth, $path, $atMost, $server);
    }

    private function queryOf(
        string $body,
        ?string $depth,
        string $path,
        ?int $atMost = null,
        ?Server $server = null,
    ): Response {
        $server ??= $this->server();
        $report = $atMost === null
            ? new CalendarQuery($server)
            : new CalendarQuery($server, atMost: $atMost);

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
     * A home holding one calendar, which holds three objects, a plain
     * collection with a fourth in it, and four members that are no calendar
     * objects at all.
     *
     * @param Element|string|null $zone What the calendar keeps as its
     *                                  `CALDAV:calendar-timezone`, or null for
     *                                  none
     * @param string|null $above What the home above it keeps as the same
     *                           property, which is a collection §9.8 does not
     *                           speak of
     * @param string|null $beside What the second calendar in the home keeps,
     *                            so that a walk over the home can be told to
     *                            have read each calendar's own
     */
    private function server(
        Element|string|null $zone = null,
        ?string $above = null,
        ?string $beside = null,
    ): Server {
        $store = new MemoryPropertyCollection('work');
        $inner = new MemoryCollection('inner');

        $inner->add(new MemoryFile('deep.ics', $this->objectOf(self::DEEP)));
        $inner->add(new MemoryFile('lunch.ics', $this->objectOf(self::FLOATING_BELOW)));

        $store->add(new MemoryFile('meeting.ics', $this->objectOf(self::MEETING)));
        $store->add(new MemoryFile('review.ics', $this->objectOf(self::REVIEW)));
        $store->add(new MemoryFile('floating.ics', $this->objectOf(self::FLOATING)));
        $store->add(new MemoryFile('note.txt', 'This is a note, not a calendar.'));
        $store->add(new MemoryFile('twice.ics', $this->objectOf(self::MEETING) . $this->objectOf(self::REVIEW)));
        $store->add(new MemoryFile('empty.ics'));
        $store->add(new PlainNode('a-principal'));
        $store->add($inner);

        if ($zone !== null) {
            $store->patchProperties(['{urn:ietf:params:xml:ns:caldav}calendar-timezone' => $zone]);
        }

        $away = new MemoryPropertyCollection('holiday');

        $away->add(new MemoryFile('away.ics', $this->objectOf(self::FLOATING_BESIDE)));

        if ($beside !== null) {
            $away->patchProperties(['{urn:ietf:params:xml:ns:caldav}calendar-timezone' => $beside]);
        }

        $home = new MemoryPropertyCollection('calendars');

        $home->add(Calendar::of($store));
        $home->add(Calendar::of($away));

        if ($above !== null) {
            $home->patchProperties(['{urn:ietf:params:xml:ns:caldav}calendar-timezone' => $above]);
        }

        $root = (new MemoryCollection(''))->add($home);

        // The live properties are wired up because §7.8 has the report answer
        // properties the way a `PROPFIND` does, and a server without them
        // would let these cases pass on a report that answered none.
        $events = new EventEmitter();
        $events->on(PropertiesRequested::class, (new LiveProperties())(...));

        return new Server(new Tree($root), $events);
    }

    /**
     * A `CALDAV:timezone` element carrying §5.2.2's example under another name.
     */
    private function timezone(string $tzid): string
    {
        return '<timezone>' . htmlspecialchars($this->zoneCalled($tzid), ENT_XML1) . '</timezone>';
    }

    private function zoneCalled(string $tzid): string
    {
        return "BEGIN:VCALENDAR\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\nVERSION:2.0\r\n"
            . 'BEGIN:VTIMEZONE' . "\r\nTZID:" . $tzid . "\r\nBEGIN:STANDARD\r\nDTSTART:19671029T020000\r\n"
            . "RRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=10\r\nTZOFFSETFROM:-0400\r\nTZOFFSETTO:-0500\r\n"
            . "END:STANDARD\r\nEND:VTIMEZONE\r\nEND:VCALENDAR\r\n";
    }

    private function objectOf(string $components): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\n"
            . $components . "END:VCALENDAR\r\n";
    }
}
