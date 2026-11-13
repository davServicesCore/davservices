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

namespace DavServices\Tests\Unit\CalDav\Schedule;

use DavServices\CalDav\Schedule\FreeBusyRequest;
use DavServices\Exception\BadRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for the body of RFC 6638 §5's busy time request. R-CAL-34, P8-05.
 *
 * §5 says what the body is in one sentence and then points at iTIP for the
 * rest:
 *
 * > To accomplish this, the request body MUST contain a "VFREEBUSY" calendar
 * > component with the "METHOD" iCalendar property set to the value "REQUEST"
 * > as specified in Section 3.3.2 of iTIP [RFC5546].
 *
 * And §5.2.1 makes the whole of that section a precondition with a name:
 *
 * > Purpose: (precondition) -- The resource submitted in the POST request
 * > MUST obey all the restrictions specified in Section 3.3.2 of iTIP
 * > [RFC5546].
 *
 * **"All the restrictions" is a table**, and the table is what this class is.
 * RFC 5546 §3.3.2 gives a presence for every property and component, and a
 * server that checked only the two §5 names in prose would take a body with a
 * `DURATION`, or with no `DTEND`, and then have to invent what it means.
 *
 * ## The whole table, as it is written
 *
 * | Component/Property | Presence | Comment |
 * |---|---|---|
 * | `METHOD` | 1 | MUST be REQUEST |
 * | `VFREEBUSY` | 1 | |
 * | `ATTENDEE` | 1+ | the calendar users being asked about |
 * | `DTEND` | 1 | DateTime values must be in UTC |
 * | `DTSTAMP` | 1 | |
 * | `DTSTART` | 1 | DateTime values must be in UTC |
 * | `ORGANIZER` | 1 | MUST be the request originator's address |
 * | `UID` | 1 | |
 * | `COMMENT` | 0+ | |
 * | `CONTACT` | 0 or 1 | |
 * | `FREEBUSY` · `DURATION` · `REQUEST-STATUS` · `URL` | 0 | |
 * | `VALARM` | 0 | |
 * | `VEVENT` · `VTODO` · `VJOURNAL` · `VTIMEZONE` | 0 | |
 * | IANA and X properties and components | 0+ | |
 *
 * **A `0` is as much a restriction as a `1`.** A `FREEBUSY` in a request is
 * the answer's property arriving in the question, and a `DURATION` would say
 * the range twice — §3.3.2 gives `DTEND | 1` instead, so there is one way to
 * say it and no second one to disagree with.
 *
 * ## Why the two times must be UTC, and what that excludes
 *
 * The table's comment on `DTSTART` and `DTEND` is "DateTime values must be in
 * UTC". Two things are therefore refused: a date without a time, RFC 5545
 * §3.6.4 allowing `DTSTART;VALUE=DATE` elsewhere but this being a *DateTime*
 * value; and a time in a named zone or a floating one, which would leave the
 * range meaning different things to the two ends of the request.
 */
#[CoversClass(FreeBusyRequest::class)]
final class FreeBusyRequestTest extends TestCase
{
    private const CYRUS = 'mailto:cyrus@example.com';

    private const WILFREDO = 'mailto:wilfredo@example.com';

    private const BERNARD = 'mailto:bernard@example.net';

    /**
     * **Appendix B.5's own request, read.** The organizer is who is asking,
     * the attendees are who is being asked about, and the two times are the
     * range to report over.
     */
    public function testItReadsWhoIsAskingAboutWhomAndOverWhatRange(): void
    {
        $asked = FreeBusyRequest::of(self::aRequest());

        self::assertSame(self::CYRUS, $asked->organizer());
        self::assertSame([self::WILFREDO, self::BERNARD], $asked->recipients());
        self::assertSame('20090602T000000Z', $asked->range()->from()?->format('Ymd\THis\Z'));
        self::assertSame('20090604T000000Z', $asked->range()->until()?->format('Ymd\THis\Z'));
    }

    /**
     * **The `UID` comes back out**, because §5's answer carries the same one:
     * Appendix B.5's reply repeats `4FD3AD926350`, and that is how a client
     * pairs the answer with the question it asked.
     */
    public function testItCarriesTheUidOfTheRequestSoTheAnswerCanBePairedWithIt(): void
    {
        self::assertSame('4FD3AD926350', FreeBusyRequest::of(self::aRequest())->uid());
    }

    /**
     * **Every `0`, `1` and `1+` of the table, each broken on its own.** The
     * precondition is `CALDAV:valid-scheduling-message` (§5.2.1) and the
     * status `400`, which §5.2.1 names as the one it is used with.
     *
     * @param string $body A request that breaks exactly one row
     */
    #[DataProvider('bodiesThatBreakOneRow')]
    public function testItRefusesABodyThatBreaksOneRowOfTheTable(string $body): void
    {
        $this->expectException(BadRequest::class);

        FreeBusyRequest::of($body);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function bodiesThatBreakOneRow(): array
    {
        return [
            'no METHOD' => [self::aRequest(method: null)],
            'a METHOD that is not REQUEST' => [self::aRequest(method: 'REPLY')],
            'two METHODs' => [self::aRequest(extra: 'METHOD:REQUEST')],
            'no VFREEBUSY' => [self::noComponent()],
            'two VFREEBUSYs' => [self::aRequest(second: true)],
            'no ATTENDEE' => [self::aRequest(attendees: [])],
            'no DTEND' => [self::without('DTEND')],
            'no DTSTAMP' => [self::without('DTSTAMP')],
            'no DTSTART' => [self::without('DTSTART')],
            'no ORGANIZER' => [self::without('ORGANIZER')],
            'no UID' => [self::without('UID')],
            'two DTENDs' => [self::aRequest(inside: 'DTEND:20090605T000000Z')],
            'two ORGANIZERs' => [self::aRequest(inside: 'ORGANIZER:' . self::BERNARD)],
            'two UIDs' => [self::aRequest(inside: 'UID:second')],
            'two CONTACTs' => [self::aRequest(inside: "CONTACT:one\r\nCONTACT:two")],
            'a FREEBUSY' => [self::aRequest(inside: 'FREEBUSY:20090602T110000Z/20090602T120000Z')],
            'a DURATION' => [self::aRequest(inside: 'DURATION:PT1H')],
            'a REQUEST-STATUS' => [self::aRequest(inside: 'REQUEST-STATUS:2.0;Success')],
            'a URL' => [self::aRequest(inside: 'URL:https://example.com/')],
            'a VALARM' => [self::aRequest(alarm: true)],
            'a VEVENT beside it' => [self::aRequest(beside: 'VEVENT')],
            'a VTODO beside it' => [self::aRequest(beside: 'VTODO')],
            'a VJOURNAL beside it' => [self::aRequest(beside: 'VJOURNAL')],
            'a VTIMEZONE beside it' => [self::aRequest(beside: 'VTIMEZONE')],
            'a DTSTART that is a date' => [self::aRequest(start: 'DTSTART;VALUE=DATE:20090602')],
            'a DTEND that is a date' => [self::aRequest(end: 'DTEND;VALUE=DATE:20090604')],
            'a DTSTART in a named zone' => [
                self::aRequest(start: 'DTSTART;TZID=Europe/Berlin:20090602T000000'),
            ],
            'a DTSTART that floats' => [self::aRequest(start: 'DTSTART:20090602T000000')],
            'a DTEND that floats' => [self::aRequest(end: 'DTEND:20090604T000000')],
            'a DTEND that is not later than the DTSTART' => [
                self::aRequest(end: 'DTEND:20090602T000000Z'),
            ],
            'nothing that can be read at all' => ['not an iCalendar object'],
            'an empty body' => [''],
        ];
    }

    /**
     * **And what the table allows is not refused.** Without this the guards
     * above could all be one that refuses everything.
     *
     * @param string $body A request that uses an allowance of the table
     */
    #[DataProvider('bodiesTheTableAllows')]
    public function testItTakesWhatTheTableAllows(string $body): void
    {
        self::assertSame(self::CYRUS, FreeBusyRequest::of($body)->organizer());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function bodiesTheTableAllows(): array
    {
        return [
            'one attendee' => [self::aRequest(attendees: [self::WILFREDO])],
            'two COMMENTs' => [self::aRequest(inside: "COMMENT:one\r\nCOMMENT:two")],
            'one CONTACT' => [self::aRequest(inside: 'CONTACT:Cyrus, +1 617 555 0145')],
            'an X-property' => [self::aRequest(inside: 'X-SOMETHING:anything')],
            'an IANA property' => [self::aRequest(inside: 'IMAGE;VALUE=URI:https://example.com/i.png')],
            'an X-component beside it' => [self::aRequest(beside: 'X-SOMETHING')],
        ];
    }

    /**
     * Appendix B.5's request, with one row of the table changed at a time.
     *
     * @param list<string> $attendees
     */
    private static function aRequest(
        ?string $method = 'REQUEST',
        array $attendees = [self::WILFREDO, self::BERNARD],
        string $start = 'DTSTART:20090602T000000Z',
        string $end = 'DTEND:20090604T000000Z',
        string $inside = '',
        string $extra = '',
        string $beside = '',
        bool $alarm = false,
        bool $second = false,
    ): string {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Example Corp.//CalDAV Client//EN'];

        if ($method !== null) {
            $lines[] = 'METHOD:' . $method;
        }

        if ($extra !== '') {
            $lines[] = $extra;
        }

        $lines[] = 'BEGIN:VFREEBUSY';
        $lines[] = 'UID:4FD3AD926350';
        $lines[] = 'DTSTAMP:20090602T190420Z';
        $lines[] = $start;
        $lines[] = $end;
        $lines[] = 'ORGANIZER;CN="Cyrus Daboo":' . self::CYRUS;

        foreach ($attendees as $attendee) {
            $lines[] = 'ATTENDEE:' . $attendee;
        }

        if ($inside !== '') {
            $lines[] = $inside;
        }

        if ($alarm) {
            $lines[] = 'BEGIN:VALARM';
            $lines[] = 'ACTION:DISPLAY';
            $lines[] = 'TRIGGER:-PT15M';
            $lines[] = 'DESCRIPTION:nothing';
            $lines[] = 'END:VALARM';
        }

        $lines[] = 'END:VFREEBUSY';

        if ($second) {
            $lines = [...$lines, 'BEGIN:VFREEBUSY', 'UID:second', 'DTSTAMP:20090602T190420Z', 'END:VFREEBUSY'];
        }

        if ($beside !== '') {
            $lines[] = 'BEGIN:' . $beside;
            $lines[] = 'END:' . $beside;
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * The same, with the one property of the `VFREEBUSY` taken out again.
     */
    private static function without(string $name): string
    {
        $kept = [];

        foreach (explode("\r\n", self::aRequest()) as $line) {
            if (!str_starts_with($line, $name . ':') && !str_starts_with($line, $name . ';')) {
                $kept[] = $line;
            }
        }

        return implode("\r\n", $kept);
    }

    /**
     * A calendar with no `VFREEBUSY` in it at all.
     */
    private static function noComponent(): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nMETHOD:REQUEST\r\nEND:VCALENDAR\r\n";
    }
}
