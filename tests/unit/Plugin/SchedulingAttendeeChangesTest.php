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

namespace DavServices\Tests\Unit\Plugin;

use DavServices\Acl\Principal;
use DavServices\CalDav\Calendar;
use DavServices\CalDav\Schedule\Inbox;
use DavServices\Dav\Acl\PrincipalInfo;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\IFile;
use DavServices\Dav\Method\Put;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Http\Body;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Plugin\Scheduling;
use DavServices\Tests\Unit\Backend\MemoryCalendarUserBackend;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Xml\Element;
use DavServices\Xml\Writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for RFC 6638 §3.2.2.1, the eleven changes an attendee must be
 * allowed to make. R-CAL-42, R-CODE-12.
 *
 * > Servers **MUST allow** "Attendees" to make the following iCalendar data
 * > changes, subject to other restrictions, such as access privileges and
 * > preconditions […]
 * >
 * > The server MUST return an error with the
 * > CALDAV:allowed-attendee-scheduling-object-change precondition code
 * > (Section 3.2.4.4) when the "Attendee" attempts to change the iCalendar
 * > data **in a manner forbidden by the server**.
 *
 * ## It is a floor on permissiveness, not a list of what is permitted
 *
 * **An earlier reading of this section had it backwards**, and the matrix said
 * so: it recorded §3.2.2.1 and §3.2.4.4 as open, as though an attendee's copy
 * were being written without a guard the memo asks for. The memo asks for no
 * such guard. The MUST is "MUST **allow**", and §3.2.4.4 says what the
 * precondition is for:
 *
 * > **Servers MAY impose restrictions** on modifications allowed by an
 * > "Attendee", **subject to the allowed changes specified in Section
 * > 3.2.2.1**.
 *
 * A MAY, and the eleven are its ceiling: whatever a server chooses to forbid,
 * it may not forbid these. A server that forbids nothing has nothing to report
 * the precondition for — the same shape §6.2.4's privilege had before §5's
 * `POST` existed, and the same shape `CALDAV:schedule-send-freebusy` still has
 * where no deployment grants it.
 *
 * **So what this library owes is that each of the eleven goes through**, and
 * that is what is asked here, once per item. It owes it twice over: a change
 * that is accepted and then written over is not a change that was allowed, so
 * every case reads the stored resource rather than the status alone.
 *
 * ## What the server does write, and why that is not a refusal
 *
 * An attendee's write sends a `REPLY` (§3.2.2.3), and §7.3 has the server put
 * `SCHEDULE-STATUS` on the `ORGANIZER` of the stored resource. So the bytes
 * that come back are not the bytes that went in, and no case compares them
 * whole: each asks after the one property it is about.
 *
 * `SCHEDULE-AGENT=CLIENT` on the organizer keeps even that away, which is what
 * the cases about the top-level properties use — there is then nothing the
 * server writes at all, and a change that did not survive has nobody else to
 * blame.
 */
#[CoversClass(Scheduling::class)]
final class SchedulingAttendeeChangesTest extends TestCase
{
    private const ADDRESSES = [
        'bernard' => ['mailto:bernard@example.com'],
        'cyrus' => ['mailto:cyrus@example.com'],
    ];

    private const EVENT = '/calendars/bernard/work/meeting.ics';

    private const NAME = 'meeting.ics';

    private const HOME_SET = '{urn:ietf:params:xml:ns:caldav}calendar-home-set';

    private const ADDRESS_SET = '{urn:ietf:params:xml:ns:caldav}calendar-user-address-set';

    private const OWNER = '{DAV:}owner';

    /** The store behind Bernard's calendar. */
    private ?MemoryCollection $calendar = null;

    /**
     * **(1) Their own `PARTSTAT`.** The one change the whole of §3.2.2 is
     * about, and the only one of the eleven that sets a scheduling message
     * off.
     */
    public function testAnAttendeeMayChangeTheirOwnPartstat(): void
    {
        $stored = $this->writing(
            "ORGANIZER:mailto:cyrus@example.com\r\n"
            . "ATTENDEE;PARTSTAT=ACCEPTED:mailto:bernard@example.com\r\n",
        );

        self::assertStringContainsString('PARTSTAT=ACCEPTED', $stored);
    }

    /**
     * **(2) Any `TRANSP` property**, which is theirs to set: whether their own
     * copy counts towards their busy time is nobody else's business.
     */
    public function testAnAttendeeMayAddTransparency(): void
    {
        self::assertStringContainsString("TRANSP:TRANSPARENT\r\n", $this->writing(
            self::theClientSchedules() . "TRANSP:TRANSPARENT\r\n",
        ));
    }

    /**
     * And remove it again.
     */
    public function testAnAttendeeMayRemoveTransparency(): void
    {
        $this->alreadyThere(self::anObject(self::theClientSchedules() . "TRANSP:OPAQUE\r\n"));

        self::assertStringNotContainsString('TRANSP', $this->writing(self::theClientSchedules()));
    }

    /**
     * **(3) Any `PERCENT-COMPLETE`** — a `VTODO` property (RFC 5545 §3.8.1.8),
     * so the resource this asks of is a task rather than an event.
     */
    public function testAnAttendeeMayRecordHowFarAlongTheyAre(): void
    {
        self::assertStringContainsString("PERCENT-COMPLETE:40\r\n", $this->writing(
            self::theClientSchedules() . "PERCENT-COMPLETE:40\r\n",
            'VTODO',
        ));
    }

    /**
     * **(4) Any `COMPLETED`**, the other of the two task properties
     * (RFC 5545 §3.8.2.1).
     */
    public function testAnAttendeeMaySayTheyAreDone(): void
    {
        self::assertStringContainsString("COMPLETED:20260602T120000Z\r\n", $this->writing(
            self::theClientSchedules() . "COMPLETED:20260602T120000Z\r\n",
            'VTODO',
        ));
    }

    /**
     * **(5) Any `VALARM` component.** When somebody is reminded of a meeting
     * is theirs alone, and an organizer has no business in it.
     */
    public function testAnAttendeeMayAddAnAlarm(): void
    {
        $stored = $this->writing(self::theClientSchedules(), '', self::anAlarm());

        self::assertStringContainsString("BEGIN:VALARM\r\n", $stored);
        self::assertStringContainsString("TRIGGER:-PT15M\r\n", $stored);
    }

    /**
     * And take it away again.
     */
    public function testAnAttendeeMayRemoveAnAlarm(): void
    {
        $this->alreadyThere(self::anObject(self::theClientSchedules(), 'VEVENT', self::anAlarm()));

        self::assertStringNotContainsString('VALARM', $this->writing(self::theClientSchedules()));
    }

    /**
     * **(6) `CALSCALE` on the top-level `VCALENDAR`** (RFC 5545 §3.7.1).
     */
    public function testAnAttendeeMayNameTheCalendarScale(): void
    {
        self::assertStringContainsString("CALSCALE:GREGORIAN\r\n", $this->writing(
            self::theClientSchedules(),
            '',
            '',
            "CALSCALE:GREGORIAN\r\n",
        ));
    }

    /**
     * **(7) `PRODID` on the top-level `VCALENDAR`** (RFC 5545 §3.7.3), which
     * names the client that wrote the data and changes whenever the client
     * does.
     */
    public function testAnAttendeeMayNameTheirOwnProduct(): void
    {
        self::assertStringContainsString('-//Another Client//EN', $this->writing(
            self::theClientSchedules(),
            '',
            '',
            '',
            '-//Another Client//EN',
        ));
    }

    /**
     * **(8) `EXDATE`, and the overridden instance it leaves behind.** An
     * attendee declining one date of a series writes it out of their own copy.
     */
    public function testAnAttendeeMayExcludeADateOfTheirOwnCopy(): void
    {
        $stored = $this->writing(
            self::theClientSchedules()
            . "RRULE:FREQ=DAILY;COUNT=5\r\n"
            . "EXDATE:20260603T100000Z\r\n",
        );

        self::assertStringContainsString("EXDATE:20260603T100000Z\r\n", $stored);
    }

    /**
     * **(9) `CREATED`, `DTSTAMP` and `LAST-MODIFIED`.** §3.2.5 has the server
     * set `DTSTAMP` on a scheduling **message**; what the resource carries is
     * the client's, and all three come back as written.
     */
    public function testAnAttendeeMayWriteTheThreeTimestamps(): void
    {
        $stored = $this->writing(
            self::theClientSchedules()
            . "CREATED:20260101T090000Z\r\n"
            . "LAST-MODIFIED:20260501T080000Z\r\n",
        );

        self::assertStringContainsString("CREATED:20260101T090000Z\r\n", $stored);
        self::assertStringContainsString("LAST-MODIFIED:20260501T080000Z\r\n", $stored);
        self::assertStringContainsString("DTSTAMP:20260101T000000Z\r\n", $stored);
    }

    /**
     * **(10) `SCHEDULE-STATUS` where the client does the scheduling.** §7.3
     * draws the line: "Servers MUST **only** add or change this property
     * parameter on any 'ATTENDEE' properties corresponding to calendar users
     * who were sent a scheduling message" — and where the client handles the
     * scheduling it "MAY add, change, or remove this parameter to indicate the
     * last scheduling message status it received". So the parameter the client
     * wrote on a `SCHEDULE-AGENT=CLIENT` attendee is the client's, and it
     * survives untouched.
     */
    public function testAnAttendeeMayKeepTheStatusTheirOwnClientRecorded(): void
    {
        $stored = $this->writing(
            "ORGANIZER;SCHEDULE-AGENT=CLIENT:mailto:cyrus@example.com\r\n"
            . "ATTENDEE;PARTSTAT=ACCEPTED:mailto:bernard@example.com\r\n"
            . "ATTENDEE;SCHEDULE-AGENT=CLIENT;SCHEDULE-STATUS=1.1:mailto:wilfredo@example.net\r\n",
        );

        self::assertStringContainsString('SCHEDULE-STATUS=1.1', $stored);
    }

    /**
     * **(11) A new component for an overridden instance**, "provided the only
     * changes to the recurrence instance follow the rules above" — here a
     * `PARTSTAT` of their own on one date of a series.
     */
    public function testAnAttendeeMayOverrideOneInstanceOfTheirOwnCopy(): void
    {
        $stored = $this->writing(
            self::theClientSchedules() . "RRULE:FREQ=DAILY;COUNT=5\r\n",
            'VEVENT',
            '',
            '',
            '',
            "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20260101T000000Z\r\n"
            . "RECURRENCE-ID:20260603T100000Z\r\nDTSTART:20260603T100000Z\r\n"
            . "ORGANIZER;SCHEDULE-AGENT=CLIENT:mailto:cyrus@example.com\r\n"
            . "ATTENDEE;PARTSTAT=DECLINED:mailto:bernard@example.com\r\n"
            . "END:VEVENT\r\n",
        );

        self::assertStringContainsString("RECURRENCE-ID:20260603T100000Z\r\n", $stored);
        self::assertStringContainsString('PARTSTAT=DECLINED', $stored);
    }

    /**
     * **And nothing is refused**, which is the sentence behind all eleven: a
     * write that was allowed is a `201` or a `204`, never §3.2.4.4's `403`.
     */
    public function testNoneOfThemIsRefused(): void
    {
        $answered = $this->putting(self::anObject(
            self::theClientSchedules()
            . "TRANSP:TRANSPARENT\r\nCREATED:20260101T090000Z\r\nEXDATE:20260603T100000Z\r\n"
            . "RRULE:FREQ=DAILY;COUNT=5\r\n",
            'VEVENT',
            self::anAlarm(),
            "CALSCALE:GREGORIAN\r\n",
        ));

        self::assertContains($answered->status(), [201, 204]);
        self::assertStringNotContainsString(
            'allowed-attendee-scheduling-object-change',
            (string) $answered->body(),
        );
    }

    /**
     * The organizer line of a copy whose scheduling the client handles, so
     * that the server writes nothing on the resource at all.
     */
    private static function theClientSchedules(): string
    {
        return "ORGANIZER;SCHEDULE-AGENT=CLIENT:mailto:cyrus@example.com\r\n"
            . "ATTENDEE;PARTSTAT=ACCEPTED:mailto:bernard@example.com\r\n";
    }

    private static function anAlarm(): string
    {
        return "BEGIN:VALARM\r\nACTION:DISPLAY\r\nDESCRIPTION:Soon\r\nTRIGGER:-PT15M\r\nEND:VALARM\r\n";
    }

    /**
     * An attendee's copy, as a calendar object resource.
     *
     * @param string $kind `VEVENT` or `VTODO`
     * @param string $inside What the component holds besides its own
     *                       properties, which is where a `VALARM` goes
     * @param string $atTheTop What the `VCALENDAR` holds before the component
     * @param string $product The `PRODID` value
     * @param string $alongside A second component, for an overridden instance
     */
    private static function anObject(
        string $properties,
        string $kind = 'VEVENT',
        string $inside = '',
        string $atTheTop = '',
        string $product = '-//Example Corp.//CalDAV Client//EN',
        string $alongside = '',
    ): string {
        $kind = $kind === '' ? 'VEVENT' : $kind;
        $when = $kind === 'VTODO'
            ? "DUE:20260602T110000Z\r\n"
            : "DTEND:20260602T110000Z\r\n";

        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:" . $product . "\r\n" . $atTheTop
            . 'BEGIN:' . $kind . "\r\nUID:1\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260602T100000Z\r\n"
            . $when . "SUMMARY:A Meeting\r\n" . $properties . $inside
            . 'END:' . $kind . "\r\n" . $alongside . "END:VCALENDAR\r\n";
    }

    /**
     * Writes that object as the attendee and answers what was stored.
     *
     * @param string $kind As {@see self::anObject()}
     */
    private function writing(
        string $properties,
        string $kind = 'VEVENT',
        string $inside = '',
        string $atTheTop = '',
        string $product = '-//Example Corp.//CalDAV Client//EN',
        string $alongside = '',
    ): string {
        $answered = $this->putting(
            self::anObject($properties, $kind, $inside, $atTheTop, $product, $alongside),
        );

        self::assertContains($answered->status(), [201, 204], (string) $answered->body());

        return $this->stored();
    }

    /**
     * What Bernard's calendar already holds, for the cases about removing
     * something.
     */
    private function alreadyThere(string $object): void
    {
        $this->theCalendar()->createFile(self::NAME, $object);
    }

    private function putting(string $body): Response
    {
        return $this->server()->handle(new Request('PUT', self::EVENT, body: new Body($body)));
    }

    /**
     * Bernard with a calendar and an Inbox, Cyrus with an Inbox to receive a
     * reply, and no plugin that manages access — what is asked here is what
     * §3.2.2.1 allows, not what an access control list does.
     */
    private function server(): Server
    {
        $users = new MemoryCalendarUserBackend(self::ADDRESSES, '/principals/');
        $server = new Server(
            new Tree(
                (new MemoryCollection(''))
                    ->add((new MemoryCollection('calendars'))
                        ->add((new MemoryCollection('bernard'))
                            ->add(Calendar::of($this->theCalendar()))
                            ->add(Inbox::of(new MemoryCollection('inbox'))))
                        ->add((new MemoryCollection('cyrus'))
                            ->add(Calendar::of(new MemoryCollection('work')))
                            ->add(Inbox::of(new MemoryCollection('inbox')))))
                    ->add((new MemoryCollection('principals'))
                        ->add(new Principal(new PrincipalInfo('bernard', 'Bernard')))
                        ->add(new Principal(new PrincipalInfo('cyrus', 'Cyrus')))),
            ),
            new EventEmitter(),
            new Writer(['DAV:' => 'd', 'urn:ietf:params:xml:ns:caldav' => 'C']),
        );

        $server->events()->on(
            PropertiesRequested::class,
            static function (PropertiesRequested $event) use ($users): void {
                self::answer($event, $users);
            },
        );

        $server->onMethod('PUT', (new Put($server))(...));

        (new Scheduling($server, $users))->register();

        return $server;
    }

    /**
     * The store behind Bernard's calendar, made once so that a case can fill
     * it before the write.
     */
    private function theCalendar(): MemoryCollection
    {
        return $this->calendar ??= new MemoryCollection('work');
    }

    /**
     * The resource as it was stored.
     */
    private function stored(): string
    {
        $file = $this->theCalendar()->child(self::NAME);
        $content = $file instanceof IFile ? $file->get() : null;

        return is_string($content) ? $content : self::fail('Nothing was stored.');
    }

    /**
     * The three properties this plugin reads, answered the way a deployment
     * answers them.
     */
    private static function answer(PropertiesRequested $event, MemoryCalendarUserBackend $users): void
    {
        $node = $event->node();
        $result = $event->result();
        $segments = explode('/', trim($result->path(), '/'));

        // A principal is asked by name rather than by path: it knows its own,
        // and a path read apart would be a second idea of who this is.
        if ($node instanceof Principal) {
            if ($result->wants(self::HOME_SET)) {
                $result->set(self::HOME_SET, self::naming(self::HOME_SET, '/calendars/' . $node->name() . '/'));
            }

            if ($result->wants(self::ADDRESS_SET)) {
                $result->set(
                    self::ADDRESS_SET,
                    self::naming(self::ADDRESS_SET, ...$users->addressesOf($node->name())),
                );
            }

            return;
        }

        if ($result->wants(self::OWNER) && count($segments) === 3) {
            $result->set(self::OWNER, self::naming(self::OWNER, '/principals/' . $segments[1] . '/'));
        }
    }

    private static function naming(string $property, string ...$hrefs): Element
    {
        $holder = new Element($property);

        foreach ($hrefs as $href) {
            $child = new Element('{DAV:}href');

            $child->appendText($href);
            $holder->append($child);
        }

        return $holder;
    }
}
