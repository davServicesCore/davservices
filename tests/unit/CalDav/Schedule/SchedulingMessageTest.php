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

use DavServices\CalDav\CalendarObject;
use DavServices\CalDav\Schedule\SchedulingMessage;
use DavServices\VObject\Component;
use DavServices\VObject\Serializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for the scheduling message an organizer's `PUT` sends (RFC 6638
 * §3.2.1.1 and §4, RFC 5546 §3.2). R-CAL-32, P8-02c.
 *
 * RFC 6638 §1.1 calls it "a calendar object that describes a scheduling
 * operation such as schedule, reschedule, reply, or cancel", and §3.2.1.1's
 * table says which kind this one is: an `ATTENDEE` whose `SCHEDULE-AGENT` is
 * `SERVER` gets the iTIP method `REQUEST`.
 *
 * ## It is the stored object plus one property and minus two parameters
 *
 * RFC 5546 §3.2 asks a `REQUEST` for the `METHOD` property that names it, and
 * RFC 5545 §3.6 keeps `PRODID` and `VERSION` REQUIRED in any `VCALENDAR`, so
 * the message is built around what the client wrote rather than from nothing.
 * Every `VTIMEZONE` travels with it for the same reason: RFC 5545 §3.6.5 makes
 * a `TZID` a reference to one, and a message that left the zones behind would
 * name times nobody could resolve.
 *
 * **Two parameters may not travel**, and each is a MUST NOT of its own:
 *
 * > [§7.1] Servers MUST NOT include this parameter in any scheduling messages
 * > sent as the result of a scheduling operation.
 * >
 * > [§7.3] Servers MUST NOT include this parameter in any scheduling messages
 * > sent as the result of a scheduling operation.
 *
 * They are the two the server itself reads and writes — who delivers, and what
 * came of it — and both are about this server's side of the exchange rather
 * than about the event. Everything else a client wrote on a property stays:
 * `CN`, `ROLE`, `PARTSTAT` and `RSVP` are what the invitation *is*.
 *
 * ## And the stored resource is not touched
 *
 * The message is built from a copy, because the resource it is built from is
 * about to be stored with §7.3's `SCHEDULE-STATUS` on it — a copy taken by
 * leaving two parameters out of a new property is the one thing that cannot
 * strip them from the client's own object by accident.
 *
 * ## What it is called where it is delivered
 *
 * Nothing in RFC 6638 says what a resource in a scheduling Inbox collection is
 * called, so the name is derived from the message itself: the same message is
 * one message, and a changed one is another for the calendar user to
 * acknowledge, which is §4's model — "Scheduling messages are typically
 * removed from the scheduling Inbox collection by the client once the calendar
 * user has acknowledged the change."
 */
#[CoversClass(SchedulingMessage::class)]
final class SchedulingMessageTest extends TestCase
{
    private const INVITATION = "ORGANIZER:mailto:bernard@example.com\r\n"
        . "ATTENDEE;CN=Cyrus Daboo;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:cyrus@example.com";

    /**
     * **The whole message, as RFC 5546 §3.2 asks for it.** `METHOD:REQUEST`
     * is there, in front where a reader will see it; `PRODID` and `VERSION`
     * are the client's own, RFC 5545 §3.6 making both REQUIRED; and the event
     * is the event.
     *
     * RFC 5545 §3.5 — "This memo imposes no ordering of properties within an
     * iCalendar object" — so the position of `METHOD` is for whoever reads the
     * file rather than for a parser.
     */
    public function testTheMessageIsTheObjectWithTheMethodThatNamesIt(): void
    {
        self::assertSame(
            "BEGIN:VCALENDAR\r\n"
            . "METHOD:REQUEST\r\n"
            . "VERSION:2.0\r\n"
            . "PRODID:-//dav.services//test//EN\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:1\r\n"
            . "DTSTAMP:20261101T090000Z\r\n"
            . "DTSTART:20261101T100000Z\r\n"
            . "ORGANIZER:mailto:bernard@example.com\r\n"
            . "ATTENDEE;CN=Cyrus Daboo;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRU\r\n"
            . " E:mailto:cyrus@example.com\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n",
            $this->messageAbout(self::INVITATION)->text(),
        );
    }

    /**
     * **§7.1's and §7.3's MUST NOTs, one data set each.** The two parameters
     * the server reads and writes are left out of the message wherever a
     * client or the server itself put them — on an `ATTENDEE`, on the
     * `ORGANIZER`, and inside a sub-component, because RFC 5545 §3.6.6 lets a
     * `VALARM` carry an `ATTENDEE` of its own.
     */
    #[DataProvider('theTwoItMayNotCarry')]
    public function testTheParametersTheServerOwnsAreLeftOut(string $properties, string $gone): void
    {
        self::assertStringNotContainsString($gone, $this->messageAbout($properties)->text());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function theTwoItMayNotCarry(): iterable
    {
        yield 'SCHEDULE-AGENT on an attendee' => [
            "ORGANIZER:mailto:bernard@example.com\r\n"
            . 'ATTENDEE;SCHEDULE-AGENT=SERVER:mailto:cyrus@example.com',
            'SCHEDULE-AGENT',
        ];

        yield 'SCHEDULE-AGENT on the organizer' => [
            "ORGANIZER;SCHEDULE-AGENT=SERVER:mailto:bernard@example.com\r\n"
            . 'ATTENDEE:mailto:cyrus@example.com',
            'SCHEDULE-AGENT',
        ];

        yield 'SCHEDULE-STATUS a client wrote' => [
            "ORGANIZER:mailto:bernard@example.com\r\n"
            . 'ATTENDEE;SCHEDULE-STATUS=1.2:mailto:cyrus@example.com',
            'SCHEDULE-STATUS',
        ];

        yield 'SCHEDULE-STATUS on the organizer' => [
            "ORGANIZER;SCHEDULE-STATUS=2.0:mailto:bernard@example.com\r\n"
            . 'ATTENDEE:mailto:cyrus@example.com',
            'SCHEDULE-STATUS',
        ];

        yield 'both at once, on one property' => [
            "ORGANIZER:mailto:bernard@example.com\r\n"
            . 'ATTENDEE;SCHEDULE-AGENT=SERVER;SCHEDULE-STATUS=1.2:mailto:cyrus@example.com',
            'SCHEDULE-',
        ];

        yield 'inside an alarm' => [
            "ORGANIZER:mailto:bernard@example.com\r\n"
            . "ATTENDEE:mailto:cyrus@example.com\r\n"
            . "BEGIN:VALARM\r\nACTION:EMAIL\r\nTRIGGER:-PT30M\r\nDESCRIPTION:Soon\r\nSUMMARY:Soon\r\n"
            . "ATTENDEE;SCHEDULE-AGENT=NONE:mailto:cyrus@example.com\r\n"
            . 'END:VALARM',
            'SCHEDULE-AGENT',
        ];
    }

    /**
     * **The parameters a client wrote are the invitation**, so nothing else
     * is taken off: §7.1 and §7.3 name two parameters and no others.
     */
    #[DataProvider('theOnesThatTravel')]
    public function testEverythingElseOnThePropertyTravels(string $parameter): void
    {
        self::assertStringContainsString(
            $parameter,
            $this->messageAbout(
                "ORGANIZER:mailto:bernard@example.com\r\n"
                . 'ATTENDEE;SCHEDULE-AGENT=SERVER;' . $parameter . ':mailto:cyrus@example.com',
            )->text(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function theOnesThatTravel(): iterable
    {
        yield 'the common name' => ['CN=Cyrus Daboo'];
        yield 'the role' => ['ROLE=REQ-PARTICIPANT'];
        yield 'the participation status' => ['PARTSTAT=NEEDS-ACTION'];
        yield 'whether a reply is wanted' => ['RSVP=TRUE'];
        yield 'the kind of calendar user' => ['CUTYPE=INDIVIDUAL'];
        yield 'an x-name of a client its own' => ['X-EXAMPLE-SEAT=14A'];
    }

    /**
     * **A time zone travels with the message**, RFC 5545 §3.6.5 making a
     * `TZID` a reference to one that has to be in the same object.
     */
    public function testTheTimeZonesTravelWithIt(): void
    {
        $message = $this->messageAbout(
            self::INVITATION,
            "BEGIN:VTIMEZONE\r\nTZID:Europe/Berlin\r\n"
            . "BEGIN:STANDARD\r\nDTSTART:19701025T030000\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\n"
            . "TZNAME:CET\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\n",
        )->text();

        self::assertStringContainsString("BEGIN:VTIMEZONE\r\nTZID:Europe/Berlin\r\n", $message);
        self::assertStringContainsString("TZNAME:CET\r\n", $message);
    }

    /**
     * **One `METHOD` and no more.** RFC 4791 §4.1 forbids the property in a
     * stored calendar object resource, so an object arriving with one is
     * already refused by {@see \DavServices\CalDav\CalendarObject}; a message
     * built from one all the same says `REQUEST` once, because two `METHOD`
     * properties would make it no iTIP message at all (RFC 5546 §3.2).
     */
    public function testAnObjectThatAlreadyNamesAMethodGetsExactlyOne(): void
    {
        $message = $this->messageAbout(self::INVITATION, "METHOD:PUBLISH\r\n")->text();

        self::assertStringContainsString("METHOD:REQUEST\r\n", $message);
        self::assertStringNotContainsString('PUBLISH', $message);
        self::assertSame(1, substr_count($message, 'METHOD:'));
    }

    /**
     * **The object the message was built from is not touched**, which is what
     * the copy is for: it is about to be stored with §7.3's `SCHEDULE-STATUS`
     * written on it, and a message built in place would have taken the
     * client's own parameters off the resource.
     */
    public function testTheObjectItWasBuiltFromKeepsEverythingItHad(): void
    {
        $object = $this->objectWith(
            "ORGANIZER:mailto:bernard@example.com\r\n"
            . 'ATTENDEE;SCHEDULE-AGENT=SERVER;CN=Cyrus:mailto:cyrus@example.com',
        );

        $before = (new Serializer())->write($object);

        SchedulingMessage::requesting($object);

        self::assertSame($before, (new Serializer())->write($object));
    }

    /**
     * **The name is the message**, and nothing in RFC 6638 says otherwise: §2.2
     * and §4 describe what an Inbox holds and never what a member of one is
     * called. A digest of the text is usable as a resource name whatever the
     * `UID` holds, and it answers the one question the name has to answer —
     * whether this message is already there.
     */
    public function testTheNameIsADigestOfTheMessage(): void
    {
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}\.ics$/',
            $this->messageAbout(self::INVITATION)->name(),
        );
    }

    /**
     * **The same message is one message.** Delivered twice — two calendars of
     * one owner carrying the same event — it is the same resource, and §4's
     * Inbox is an indicator rather than a log.
     */
    public function testTheSameMessageIsCalledTheSameThing(): void
    {
        self::assertSame(
            $this->messageAbout(self::INVITATION)->name(),
            $this->messageAbout(self::INVITATION)->name(),
        );
    }

    /**
     * **And a message that differs anywhere is another one**, for the calendar
     * user to acknowledge in its own right.
     */
    #[DataProvider('theOnesThatDiffer')]
    public function testAMessageThatDiffersIsCalledSomethingElse(string $properties): void
    {
        self::assertNotSame(
            $this->messageAbout(self::INVITATION)->name(),
            $this->messageAbout($properties)->name(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function theOnesThatDiffer(): iterable
    {
        yield 'another attendee' => [
            "ORGANIZER:mailto:bernard@example.com\r\nATTENDEE:mailto:eric@example.com",
        ];

        yield 'one more attendee' => [
            self::INVITATION . "\r\nATTENDEE:mailto:eric@example.com",
        ];

        yield 'a summary the first had not' => [
            self::INVITATION . "\r\nSUMMARY:Lunch",
        ];
    }

    /**
     * The message a `PUT` of this object would send.
     */
    private function messageAbout(string $properties, string $alongside = ''): SchedulingMessage
    {
        return SchedulingMessage::requesting($this->objectWith($properties, $alongside));
    }

    /**
     * One event carrying those properties, read the way a `PUT` body is read.
     */
    private function objectWith(string $properties, string $alongside = ''): Component
    {
        $object = CalendarObject::in(
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dav.services//test//EN\r\n"
            . $alongside
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261101T100000Z\r\n"
            . $properties . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
        );

        self::assertInstanceOf(Component::class, $object);

        return $object;
    }
}
