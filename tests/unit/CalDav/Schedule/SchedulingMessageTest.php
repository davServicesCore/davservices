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
     * **The `UID` the message is about**, which is what decides whether the
     * recipient already has this event. RFC 5546 §3.2.2: "If the 'UID'
     * property value in the 'REQUEST' is not found on the recipient's
     * calendar, then the 'REQUEST' is for a new 'VEVENT' calendar component.
     * If the 'UID' property value is found on the recipient's calendar, then
     * the 'REQUEST' is for a rescheduling, an update, or a reconfirmation."
     */
    public function testItSaysWhichEventItIsAbout(): void
    {
        self::assertSame('1', $this->messageAbout(self::INVITATION)->uid());
    }

    /**
     * **And null where it names no event at all.** An object of nothing but
     * time zones carries no `UID` (RFC 5545 §3.6.5), and a message about
     * nothing matches nothing on the recipient's calendar.
     */
    public function testAMessageAboutNoEventNamesNone(): void
    {
        $object = CalendarObject::in(
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dav.services//test//EN\r\n"
            . "BEGIN:VTIMEZONE\r\nTZID:Europe/Berlin\r\n"
            . "BEGIN:STANDARD\r\nDTSTART:19701025T030000\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\n"
            . "END:STANDARD\r\nEND:VTIMEZONE\r\nEND:VCALENDAR\r\n",
        );

        self::assertInstanceOf(Component::class, $object);
        self::assertNull(SchedulingMessage::requesting($object)->uid());
    }

    /**
     * **What the recipient keeps is the message without its `METHOD`.** RFC
     * 4791 §4.1: "Calendar object resources contained in calendar collections
     * MUST NOT specify the iCalendar METHOD property." The property belongs to
     * the message in transit — RFC 5546 §1.4 — and §4.1 of RFC 6638 has the
     * server create "a new scheduling object resource" from it, which is a
     * resource in a calendar collection like any other.
     */
    public function testWhatTheRecipientKeepsCarriesNoMethod(): void
    {
        $kept = $this->messageAbout(self::INVITATION)->whatTheRecipientKeeps();

        self::assertStringNotContainsString('METHOD', $kept);
        self::assertStringStartsWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", $kept);
    }

    /**
     * **And it keeps everything else**, because that is the event: the
     * organizer, the attendees as the organizer wrote them, and every
     * `VTIMEZONE` the times need (RFC 5545 §3.6.5).
     */
    public function testWhatTheRecipientKeepsIsTheRestOfTheMessage(): void
    {
        $kept = $this->messageAbout(
            self::INVITATION,
            "BEGIN:VTIMEZONE\r\nTZID:Europe/Berlin\r\n"
            . "BEGIN:STANDARD\r\nDTSTART:19701025T030000\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\n"
            . "END:STANDARD\r\nEND:VTIMEZONE\r\n",
        )->whatTheRecipientKeeps();

        self::assertStringContainsString("UID:1\r\n", $kept);
        self::assertStringContainsString("ORGANIZER:mailto:bernard@example.com\r\n", $kept);
        self::assertStringContainsString('CN=Cyrus Daboo', $kept);
        self::assertStringContainsString("TZID:Europe/Berlin\r\n", $kept);
    }

    /**
     * **The message itself still says what it is.** The two are different
     * things — one is sent, the other is kept — and a server that stripped the
     * `METHOD` from the message would have sent no iTIP message at all (RFC
     * 5546 §3.2).
     */
    public function testTheMessageItselfKeepsItsMethod(): void
    {
        $message = $this->messageAbout(self::INVITATION);

        self::assertStringContainsString("METHOD:REQUEST\r\n", $message->text());
        self::assertStringNotContainsString('METHOD', $message->whatTheRecipientKeeps());
    }

    /**
     * **§3.2.1.3's table: a removal sends a `CANCEL`**, and RFC 5546 §3.2.5
     * says what one is: "used to send a cancellation notice of an existing
     * event request to the affected 'Attendees'".
     */
    public function testACancellationNamesItsMethod(): void
    {
        self::assertStringContainsString(
            "METHOD:CANCEL\r\n",
            SchedulingMessage::cancelling($this->objectWith(self::INVITATION))->text(),
        );
    }

    /**
     * **RFC 5546 §3.2.5: the whole event is cancelled, so it says so.** Its
     * table of constraints for a `METHOD:CANCEL` has `STATUS` at "0 or 1" with
     * the condition attached: "MUST be set to CANCELLED to cancel the entire
     * event. If uninviting specific Attendees, then MUST NOT be included."
     *
     * This message is the first of those two: the organizer's resource is
     * gone, so there is no event left for anybody. Uninviting one attendee
     * while the event stands is §3.2.1.2's `<Removed>` column, and a different
     * message.
     *
     * **It is also what makes the recipient's copy honest**: §4.1 has the
     * server update their resource "to reflect the changes sent by the
     * 'Organizer'", and an event stored without this would look like any
     * other.
     */
    public function testACancellationSaysTheEventIsCancelled(): void
    {
        self::assertStringContainsString(
            "STATUS:CANCELLED\r\n",
            SchedulingMessage::cancelling($this->objectWith(self::INVITATION))->text(),
        );
    }

    /**
     * **And a status the organizer had set is replaced**, not doubled: RFC
     * 5545 §3.8.1.11 allows one `STATUS` in a component, and a cancelled event
     * is not also confirmed.
     */
    public function testAStatusTheEventAlreadyHadIsReplaced(): void
    {
        $message = SchedulingMessage::cancelling(
            $this->objectWith(self::INVITATION . "\r\nSTATUS:CONFIRMED"),
        )->text();

        self::assertStringNotContainsString('CONFIRMED', $message);
        self::assertSame(1, substr_count($message, 'STATUS:'));
    }

    /**
     * **RFC 5546 §2.1.4: "The 'SEQUENCE' property value MUST be incremented
     * each time the 'Organizer' uses the 'ADD' or 'CANCEL' methods."** Without
     * it an attendee's client has no way to tell the cancellation from the
     * invitation it already has, and §3.2.5's constraints make the property
     * REQUIRED in a `CANCEL`.
     */
    #[DataProvider('theSequencesBefore')]
    public function testTheSequenceIsIncremented(string $properties, string $expected): void
    {
        $message = SchedulingMessage::cancelling($this->objectWith($properties))->text();

        self::assertStringContainsString($expected, $message);
        self::assertSame(1, substr_count($message, 'SEQUENCE:'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function theSequencesBefore(): iterable
    {
        yield 'none at all' => [self::INVITATION, "SEQUENCE:1\r\n"];
        yield 'the first revision' => [self::INVITATION . "\r\nSEQUENCE:1", "SEQUENCE:2\r\n"];
        yield 'the ninth' => [self::INVITATION . "\r\nSEQUENCE:9", "SEQUENCE:10\r\n"];
        yield 'one nobody can read' => [self::INVITATION . "\r\nSEQUENCE:later", "SEQUENCE:1\r\n"];
    }

    /**
     * **Every component of the resource is cancelled**, because the whole
     * resource is going: a recurring event and its overridden instance are one
     * event, and RFC 5546 §3.2.5's constraints ask for `VEVENT` at "1+" with
     * "All must have the same UID".
     */
    public function testEveryComponentOfTheResourceIsCancelled(): void
    {
        $message = SchedulingMessage::cancelling($this->objectWith(
            self::INVITATION . "\r\nRRULE:FREQ=DAILY;COUNT=3\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261102T110000Z\r\n"
            . "RECURRENCE-ID:20261102T100000Z\r\nSEQUENCE:4\r\n"
            . 'ORGANIZER:mailto:bernard@example.com',
        ))->text();

        self::assertSame(2, substr_count($message, "STATUS:CANCELLED\r\n"));
        self::assertStringContainsString("SEQUENCE:1\r\n", $message);
        self::assertStringContainsString("SEQUENCE:5\r\n", $message);
    }

    /**
     * **A `VTIMEZONE` is cancelled by nothing**, and RFC 5545 §3.6.5 is why:
     * it lists what one may hold, and neither `STATUS` nor `SEQUENCE` is among
     * them. A zone that came back carrying either would make the message
     * invalid iCalendar — and the zone still has to travel, because the times
     * refer to it.
     */
    public function testATimeZoneIsNotCancelled(): void
    {
        $message = SchedulingMessage::cancelling($this->objectWith(
            self::INVITATION,
            "BEGIN:VTIMEZONE\r\nTZID:Europe/Berlin\r\n"
            . "BEGIN:STANDARD\r\nDTSTART:19701025T030000\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\n"
            . "END:STANDARD\r\nEND:VTIMEZONE\r\n",
        ))->text();

        self::assertStringContainsString("BEGIN:VTIMEZONE\r\nTZID:Europe/Berlin\r\n", $message);
        self::assertSame(1, substr_count($message, "STATUS:CANCELLED\r\n"));
        self::assertSame(1, substr_count($message, 'SEQUENCE:'));
    }

    /**
     * **A cancellation keeps §7.1's and §7.3's parameters out too**, both
     * sections saying it of "any scheduling messages sent as the result of a
     * scheduling operation" rather than of one method.
     */
    public function testACancellationCarriesNeitherParameter(): void
    {
        $message = SchedulingMessage::cancelling($this->objectWith(
            "ORGANIZER;SCHEDULE-STATUS=1.2:mailto:bernard@example.com\r\n"
            . 'ATTENDEE;SCHEDULE-AGENT=SERVER;SCHEDULE-STATUS=1.2:mailto:cyrus@example.com',
        ))->text();

        self::assertStringNotContainsString('SCHEDULE-', $message);
    }

    /**
     * **And the object it was built from is not touched**, which matters more
     * here than anywhere: the resource is being deleted, and a server that
     * had written a cancellation into it on the way out would be handing a
     * store something nobody asked it to keep.
     */
    public function testTheObjectACancellationWasBuiltFromIsNotTouched(): void
    {
        $object = $this->objectWith(self::INVITATION . "\r\nSEQUENCE:3");
        $before = (new Serializer())->write($object);

        SchedulingMessage::cancelling($object);

        self::assertSame($before, (new Serializer())->write($object));
    }

    /**
     * **What the recipient keeps of a cancellation is the cancelled event.**
     * No `METHOD`, RFC 4791 §4.1 forbidding it in a stored resource — and the
     * `STATUS` stays, because that is the change §4.1 has the server reflect.
     */
    public function testWhatTheRecipientKeepsOfACancellationIsTheCancelledEvent(): void
    {
        $kept = SchedulingMessage::cancelling($this->objectWith(self::INVITATION))->whatTheRecipientKeeps();

        self::assertStringNotContainsString('METHOD', $kept);
        self::assertStringContainsString("STATUS:CANCELLED\r\n", $kept);
        self::assertStringContainsString("SEQUENCE:1\r\n", $kept);
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
