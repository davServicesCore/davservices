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

use DavServices\CalDav\CalendarData;
use DavServices\CalDav\CalendarObject;
use DavServices\CalDav\Limits;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use DavServices\Exception\NotImplemented;
use DavServices\VObject\Component;
use DavServices\Xml\Element;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for `CALDAV:calendar-data` as a report reads and writes it
 * (RFC 4791 §9.6). R-CAL-10, P6-06.
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
 */
#[CoversClass(CalendarData::class)]
final class CalendarDataTest extends TestCase
{
    private const MEETING = "BEGIN:VEVENT\r\nUID:1@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
        . "DTSTART:20260105T120000Z\r\nSUMMARY:A Meeting\r\nEND:VEVENT\r\n";

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
        $element = CalendarData::elementFor($this->object());

        self::assertSame(CalendarData::NAME, $element->name());
        self::assertStringContainsString("BEGIN:VCALENDAR\r\n", $element->text());
        self::assertStringContainsString("UID:1@example.com\r\n", $element->text());
        self::assertStringContainsString("SUMMARY:A Meeting\r\n", $element->text());
        self::assertStringContainsString("END:VCALENDAR\r\n", $element->text());
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
            CalendarData::mustBeOneItAnswers(new Element(CalendarData::NAME, $attributes));
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
        CalendarData::mustBeOneItAnswers(new Element(CalendarData::NAME, $attributes));

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
     * **Asking for parts of an object is refused rather than answered with all
     * of it.** §9.6 lets a client narrow what comes back — `CALDAV:comp`,
     * `CALDAV:expand`, `CALDAV:limit-recurrence-set`,
     * `CALDAV:limit-freebusy-set` — and R-CAL-13 and R-CAL-14 are where this
     * library does that.
     *
     * Until then the honest answer is `501`: §9.6 returns an object "in their
     * entirety" only for a request that asked for nothing narrower, so a
     * server that sent everything to a client asking for `VERSION` alone would
     * be answering a different question quietly. RFC 9110 §15.6.2 is the
     * status for it — "the server does not support the functionality required
     * to fulfill the request".
     *
     * @param string $child The element the client narrowed with
     */
    #[DataProvider('waysOfNarrowingTheAnswer')]
    public function testItRefusesANarrowingItCannotYetDo(string $child): void
    {
        $element = new Element(CalendarData::NAME);

        $element->append(new Element('{urn:ietf:params:xml:ns:caldav}' . $child));

        $this->expectException(NotImplemented::class);
        $this->expectExceptionMessage($child);

        CalendarData::mustBeOneItAnswers($element);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function waysOfNarrowingTheAnswer(): iterable
    {
        yield 'comp' => ['comp'];
        yield 'expand' => ['expand'];
        yield 'limit-recurrence-set' => ['limit-recurrence-set'];
        yield 'limit-freebusy-set' => ['limit-freebusy-set'];
    }

    /**
     * A child §9.6's DTD does not name is a `400`, and for the other reason: a
     * body that does not fit the grammar is the client's mistake, where the
     * four above are this server's limit.
     */
    public function testAChildTheDtdDoesNotNameIsRefused(): void
    {
        $element = new Element(CalendarData::NAME);

        $element->append(new Element('{DAV:}prop'));

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('{DAV:}prop');

        CalendarData::mustBeOneItAnswers($element);
    }

    private function object(): Component
    {
        $text = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\n"
            . self::MEETING . "END:VCALENDAR\r\n";

        return CalendarObject::in($text) ?? self::fail('The case built something that is no calendar object.');
    }
}
