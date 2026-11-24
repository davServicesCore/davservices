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
use DavServices\CalDav\Report\CalendarQuery;
use DavServices\CalDav\Report\FreeBusyQuery;
use DavServices\CalDav\Schedule\Inbox;
use DavServices\CalDav\Schedule\Outbox;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Exception\Forbidden;
use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Xml\Writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for RFC 6638 §2.3, `calendar-query` on a scheduling Inbox
 * collection. R-CAL-43, R-CODE-12.
 *
 * > This specification extends the CALDAV:calendar-query and
 * > CALDAV:calendar-multiget REPORTs to return results for calendar object
 * > resources in scheduling Inbox collections.
 * >
 * > When a CALDAV:calendar-query REPORT includes a time-range query and
 * > targets a scheduling Inbox collection, if any calendar object resources
 * > contain "VEVENT" calendar components that do not include a "DTSTART"
 * > iCalendar property (as allowed by iTIP [RFC5546]) then such components
 * > **MUST always match** the time-range query test.
 *
 * ## Why an iTIP message may carry no `DTSTART`
 *
 * RFC 5545 §3.6.1 makes `DTSTART` mandatory in a `VEVENT` — but only "if the
 * iCalendar object does not specify a 'METHOD' property". An Inbox holds
 * nothing but iTIP messages, and every one of them specifies a `METHOD`, so
 * RFC 5546's own tables decide instead: a `REPLY` has `DTSTART` as optional
 * (§3.2.3), because an attendee answering says who they are and what they
 * answer, not when the meeting is.
 *
 * **So a message with no `DTSTART` is ordinary in an Inbox and nowhere else**,
 * and that is what the MUST is for: §9.9's `VEVENT` table reads `DTSTART`
 * first, so without one nothing can overlap anything and a client asking its
 * Inbox for "this week" would be answered with silence about every reply it
 * had received.
 *
 * ## It is the time-range test that always matches, not the filter
 *
 * The sentence is about "the time-range query test". A `prop-filter` beside it
 * is asked as it always is — the case below says so, because a rule read as
 * "the whole filter matches" would hand a client resources it had excluded by
 * name.
 *
 * ## And only in an Inbox
 *
 * §2.3's rule is tied to the collection: "targets a scheduling Inbox
 * collection". In a calendar collection a `VEVENT` without `DTSTART` is
 * invalid iCalendar (RFC 4791 §5.2.3 wants every resource to obey RFC 5545,
 * and a calendar collection's resources specify no `METHOD`), and it goes on
 * matching nothing.
 */
#[CoversClass(CalendarQuery::class)]
#[CoversClass(FreeBusyQuery::class)]
final class CalendarQueryInboxTest extends TestCase
{
    private const INBOX = '/calendars/bernard/inbox';

    private const CALENDAR = '/calendars/bernard/work';

    private const NS = 'urn:ietf:params:xml:ns:caldav';

    /** A whole year, which an undated component cannot be inside. */
    private const A_YEAR = '<time-range start="20260101T000000Z" end="20270101T000000Z"/>';

    /**
     * **The MUST: an undated `VEVENT` in an Inbox matches whatever the range
     * is.**
     */
    public function testAnUndatedEventInAnInboxAlwaysMatchesTheRange(): void
    {
        $answered = $this->queryOn(self::INBOX, self::A_YEAR);

        self::assertStringContainsString('/calendars/bernard/inbox/reply.ics', $answered);
    }

    /**
     * **And a range it could not possibly be inside changes nothing**, which
     * is what "always" means: the rule is not a wider window but no window at
     * all.
     */
    public function testEvenARangeNowhereNearItMatches(): void
    {
        $answered = $this->queryOn(
            self::INBOX,
            '<time-range start="19990101T000000Z" end="19990102T000000Z"/>',
        );

        self::assertStringContainsString('/calendars/bernard/inbox/reply.ics', $answered);
    }

    /**
     * **A dated message in the Inbox is still asked as it stands**, so the
     * rule reaches only the components the sentence describes.
     */
    public function testADatedMessageInAnInboxIsStillAsked(): void
    {
        $inside = $this->queryOn(self::INBOX, self::A_YEAR);
        $outside = $this->queryOn(
            self::INBOX,
            '<time-range start="20300101T000000Z" end="20310101T000000Z"/>',
        );

        self::assertStringContainsString('/calendars/bernard/inbox/request.ics', $inside);
        self::assertStringNotContainsString('/calendars/bernard/inbox/request.ics', $outside);
    }

    /**
     * **In a calendar collection nothing changes.** §2.3 ties its rule to the
     * Inbox, and a `VEVENT` with no `DTSTART` is invalid in a calendar
     * collection to begin with.
     */
    public function testAnUndatedEventInACalendarStillMatchesNothing(): void
    {
        $answered = $this->queryOn(self::CALENDAR, self::A_YEAR);

        self::assertStringNotContainsString('/calendars/bernard/work/undated.ics', $answered);
        self::assertStringContainsString('/calendars/bernard/work/dated.ics', $answered);
    }

    /**
     * **It is the time-range test that always matches, not the filter.** A
     * `prop-filter` beside the range is asked as it always is, so a client
     * that excluded something by name still has it excluded.
     */
    public function testAPropFilterBesideTheRangeIsStillAsked(): void
    {
        $answered = $this->queryOn(
            self::INBOX,
            self::A_YEAR . '<prop-filter name="SUMMARY"><text-match>Nothing here</text-match></prop-filter>',
        );

        self::assertStringNotContainsString('/calendars/bernard/inbox/reply.ics', $answered);
    }

    /**
     * **And a query with no range at all is untouched**, which says the rule
     * hangs on the range rather than on the collection alone.
     */
    public function testAQueryWithoutARangeIsUntouched(): void
    {
        $answered = $this->queryOn(self::INBOX, '');

        self::assertStringContainsString('/calendars/bernard/inbox/reply.ics', $answered);
        self::assertStringContainsString('/calendars/bernard/inbox/request.ics', $answered);
    }

    /**
     * **An Outbox is not an Inbox**, which is the other half of "targets a
     * scheduling Inbox collection".
     */
    public function testAnUndatedEventInAnOutboxMatchesNothing(): void
    {
        $answered = $this->queryOn('/calendars/bernard/outbox', self::A_YEAR);

        self::assertStringNotContainsString('outbox/undated.ics', $answered);
    }

    /**
     * **§2.3 names `VEVENT`, and a `VTODO` is judged by §9.9 as ever.** RFC
     * 4791 §9.9's `VTODO` table has a row for one with no `DTSTART`, no
     * `DURATION`, no `DUE` and no `COMPLETED` — `(end > CREATED)` — and this
     * one was created after the range ends, so it matches nothing. The rule
     * would make it match, and the rule is not about it.
     *
     * **This is the case the names in §2.3 and §9.9 differ over**, and
     * without it nothing would notice a server that applied the rule to every
     * undated component.
     */
    public function testAnUndatedTaskInAnInboxIsStillJudgedByItsOwnTable(): void
    {
        $body = sprintf(
            '<calendar-query xmlns="%s"><filter><comp-filter name="VCALENDAR">'
            . '<comp-filter name="VTODO">%s</comp-filter></comp-filter></filter></calendar-query>',
            self::NS,
            self::A_YEAR,
        );

        self::assertStringNotContainsString(
            '/calendars/bernard/inbox/task.ics',
            (string) $this->reportOf($body, self::INBOX)->body(),
        );
    }

    /**
     * **§2.3's last sentence: "Note that the CALDAV:free-busy-query REPORT is
     * not supported on scheduling Inbox collections."**
     *
     * It is a note, and it still says what the protocol is: the report has no
     * meaning there. §2.2 has an Inbox hold iTIP messages — somebody else's
     * invitations and replies — and busy time worked out from those would say
     * the owner is busy at every hour anybody ever proposed to them. So it is
     * refused the way RFC 3253 §3.1.5 refuses a report a resource does not
     * support: `403` with `DAV:supported-report`.
     */
    public function testAFreeBusyQueryOnAnInboxIsNotSupported(): void
    {
        try {
            $this->freeBusyOn(self::INBOX);
        } catch (Forbidden $refusal) {
            self::assertSame('{DAV:}supported-report', $refusal->errorElement());
            self::assertSame(403, $refusal->status());

            return;
        }

        self::fail('A free-busy query on a scheduling Inbox collection was answered.');
    }

    /**
     * **And on a calendar collection it is answered as ever**, which is what
     * says the refusal is about the Inbox and not about the report.
     */
    public function testAFreeBusyQueryOnACalendarIsStillAnswered(): void
    {
        $answered = $this->freeBusyOn(self::CALENDAR);

        self::assertSame(200, $answered->status());
        self::assertStringContainsString('VFREEBUSY', (string) $answered->body());
    }

    /**
     * A `free-busy-query` over a year against that collection.
     */
    private function freeBusyOn(string $path): Response
    {
        $body = sprintf(
            '<free-busy-query xmlns="%s">%s</free-busy-query>',
            self::NS,
            self::A_YEAR,
        );
        $server = $this->server();
        $report = new FreeBusyQuery($server);

        return $report(
            new Request('REPORT', $path, headers: new Headers(['Depth' => '1']), body: new Body($body)),
            $server->reader()->parse($body),
        );
    }

    /**
     * The `calendar-query` that asks that collection for `VEVENT`s under
     * those conditions.
     */
    private function queryOn(string $path, string $conditions): string
    {
        $body = sprintf(
            '<calendar-query xmlns="%s"><filter><comp-filter name="VCALENDAR">'
            . '<comp-filter name="VEVENT">%s</comp-filter></comp-filter></filter></calendar-query>',
            self::NS,
            $conditions,
        );

        return (string) $this->reportOf($body, $path)->body();
    }

    private function reportOf(string $body, string $path): Response
    {
        $server = $this->server();
        $report = new CalendarQuery($server);

        return $report(
            new Request('REPORT', $path, headers: new Headers(['Depth' => '1']), body: new Body($body)),
            $server->reader()->parse($body),
        );
    }

    /**
     * Bernard's home: a calendar with a dated and an undated object, an Inbox
     * with a `REPLY` that carries no `DTSTART` and a `REQUEST` that does, and
     * an Outbox with an undated object of its own.
     */
    private function server(): Server
    {
        $inbox = new MemoryCollection('inbox');

        $inbox->add(new MemoryFile('reply.ics', self::aMessage('REPLY', '')));
        $inbox->add(new MemoryFile('request.ics', self::aMessage(
            'REQUEST',
            "DTSTART:20260602T100000Z\r\nDTEND:20260602T110000Z\r\n",
        )));

        // A task with nothing dated but a `CREATED` after the range ends,
        // which RFC 4791 §9.9's own `VTODO` row answers with
        // `(end > CREATED)` — and §2.3's rule is about `VEVENT` components,
        // not about this one.
        $inbox->add(new MemoryFile('task.ics', self::aTask()));

        $calendar = new MemoryCollection('work');

        $calendar->add(new MemoryFile('undated.ics', self::anEvent('')));
        $calendar->add(new MemoryFile('dated.ics', self::anEvent(
            "DTSTART:20260602T100000Z\r\nDTEND:20260602T110000Z\r\n",
        )));

        $outbox = new MemoryCollection('outbox');

        $outbox->add(new MemoryFile('undated.ics', self::aMessage('REPLY', '')));

        return new Server(
            new Tree(
                (new MemoryCollection(''))
                    ->add((new MemoryCollection('calendars'))
                        ->add((new MemoryCollection('bernard'))
                            ->add(Calendar::of($calendar))
                            ->add(Inbox::of($inbox))
                            ->add(Outbox::of($outbox)))),
            ),
            writer: new Writer(['DAV:' => 'd', self::NS => 'C']),
        );
    }

    /**
     * An iTIP message of that method, which is what an Inbox holds.
     */
    private static function aMessage(string $method, string $when): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\n"
            . 'METHOD:' . $method . "\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20260101T000000Z\r\n" . $when
            . "SUMMARY:A Meeting\r\nORGANIZER:mailto:cyrus@example.com\r\n"
            . "ATTENDEE;PARTSTAT=ACCEPTED:mailto:bernard@example.com\r\n"
            . "END:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    /**
     * An iTIP `REQUEST` for a task with nothing dated but a `CREATED`,
     * which is the one row of RFC 4791 §9.9's `VTODO` table that answers
     * no to a range before it.
     */
    private static function aTask(): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\n"
            . "METHOD:REQUEST\r\nBEGIN:VTODO\r\nUID:3\r\nDTSTAMP:20260101T000000Z\r\n"
            . "CREATED:20280101T000000Z\r\nSUMMARY:Someday\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";
    }

    /**
     * A calendar object resource, which carries no `METHOD`.
     */
    private static function anEvent(string $when): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:2\r\nDTSTAMP:20260101T000000Z\r\n" . $when
            . "SUMMARY:A Meeting\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }
}
