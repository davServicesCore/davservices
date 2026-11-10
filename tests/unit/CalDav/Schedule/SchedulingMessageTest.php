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
use DavServices\VObject\Parameter;
use DavServices\VObject\Property;
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
     * The same without the parameters, for the cases where the whole message
     * is written out and a folded line would say nothing about the case.
     */
    private const PLAIN_INVITATION = "ORGANIZER:mailto:bernard@example.com\r\n"
        . 'ATTENDEE:mailto:cyrus@example.com';

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
        $kept = $this->keptOf($this->messageAbout(self::INVITATION));

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
        $kept = $this->keptOf($this->messageAbout(
            self::INVITATION,
            "BEGIN:VTIMEZONE\r\nTZID:Europe/Berlin\r\n"
            . "BEGIN:STANDARD\r\nDTSTART:19701025T030000\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\n"
            . "END:STANDARD\r\nEND:VTIMEZONE\r\n",
        ));

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
        self::assertStringNotContainsString('METHOD', $this->keptOf($message));
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
        $kept = $this->keptOf(SchedulingMessage::cancelling($this->objectWith(self::INVITATION)));

        self::assertStringNotContainsString('METHOD', $kept);
        self::assertStringContainsString("STATUS:CANCELLED\r\n", $kept);
        self::assertStringContainsString("SEQUENCE:1\r\n", $kept);
    }

    /**
     * **A cancellation carries no alarm**, which is the one row of RFC 5546
     * §3.2.5's constraints that a message built from a stored resource can
     * break on its own: `VALARM | 0`. RFC 5545 §3.6.1 lets an event hold as
     * many as it likes, so a perfectly good calendar object resource makes an
     * invalid `CANCEL` the moment it is copied whole.
     *
     * A `REQUEST` keeps them, §3.2.2's constraints having `VALARM | 0+`: a
     * reminder the organizer set is part of the invitation.
     */
    public function testACancellationCarriesNoAlarmAndARequestDoes(): void
    {
        $alarm = "BEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT15M\r\nDESCRIPTION:Soon\r\nEND:VALARM\r\n";
        $object = $this->objectWith(self::INVITATION . "\r\n" . rtrim($alarm, "\r\n"));

        self::assertStringNotContainsString('VALARM', SchedulingMessage::cancelling($object)->text());
        self::assertStringContainsString('VALARM', SchedulingMessage::requesting($object)->text());
    }

    /**
     * **And no message carries a `REQUEST-STATUS`**, which both tables of
     * constraints put at `0`: §3.2.2's for a `REQUEST` and §3.2.5's for a
     * `CANCEL`. The property is how an attendee reports on a message they
     * received (RFC 5546 §3.6), so one left lying on an organizer's resource
     * would have the server sending an attendee their own old answer back.
     */
    #[DataProvider('theMethodsAnOrganizerSends')]
    public function testNoMessageCarriesARequestStatus(string $method): void
    {
        $object = $this->objectWith(self::INVITATION . "\r\nREQUEST-STATUS:2.0;Success");

        self::assertStringNotContainsString(
            'REQUEST-STATUS',
            ($method === SchedulingMessage::REQUEST
                ? SchedulingMessage::requesting($object)
                : SchedulingMessage::cancelling($object))->text(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function theMethodsAnOrganizerSends(): iterable
    {
        yield 'a request' => [SchedulingMessage::REQUEST];
        yield 'a cancellation' => [SchedulingMessage::CANCEL];
    }

    /**
     * **§3.2.1.2's `<Removed>` column sends the other kind of `CANCEL`**, and
     * RFC 5546 §4.2.10 is the example of one — "Removing Attendees", with the
     * note that settles it:
     *
     * > Note that in the example below, the "STATUS" property is omitted. This
     * > is used when the meeting itself is cancelled and not when the intent is
     * > to remove an "Attendee" from the event.
     *
     * The message is built to that example: `METHOD:CANCEL`, and a component
     * holding what §3.2.5's constraints make REQUIRED — `UID`, `DTSTAMP`,
     * `ORGANIZER`, `SEQUENCE` — with the one attendee who is off.
     */
    public function testAnUninvitationIsTheExampleInTheMemo(): void
    {
        self::assertSame(
            "BEGIN:VCALENDAR\r\n"
            . "METHOD:CANCEL\r\n"
            . "VERSION:2.0\r\n"
            . "PRODID:-//dav.services//test//EN\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:1\r\n"
            . "DTSTAMP:20261101T090000Z\r\n"
            . "ORGANIZER:mailto:bernard@example.com\r\n"
            . "SEQUENCE:1\r\n"
            . "ATTENDEE:mailto:cyrus@example.com\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n",
            $this->uninvitationOf($this->objectWith(self::PLAIN_INVITATION), null)->text(),
        );
    }

    /**
     * **It does not say the event is cancelled**, because it is not: §3.2.5's
     * `STATUS` row is "MUST be set to CANCELLED to cancel the entire event. If
     * uninviting specific Attendees, then MUST NOT be included."
     */
    public function testAnUninvitationDoesNotSayTheEventIsCancelled(): void
    {
        self::assertStringNotContainsString(
            'STATUS',
            $this->uninvitationOf($this->objectWith(self::PLAIN_INVITATION), null)->text(),
        );
    }

    /**
     * **And it names nobody but the calendar user it is sent to.** §3.2.5 asks
     * for them — `ATTENDEE | 0+ | MUST include some or all Attendees being
     * removed from the event` — and RFC 5546 §4.2.10's example names that one
     * and no other. Whoever is still coming is nobody's business here, and
     * their names are no part of a message about somebody else.
     */
    public function testAnUninvitationNamesNobodyWhoIsStillComing(): void
    {
        $message = $this->uninvitationOf(
            $this->objectWith(self::PLAIN_INVITATION . "\r\nATTENDEE:mailto:eric@example.com"),
            null,
        )->text();

        self::assertStringContainsString("ATTENDEE:mailto:cyrus@example.com\r\n", $message);
        self::assertStringNotContainsString('eric', $message);
    }

    /**
     * **The attendee travels as they stand**, `CN` and `ROLE` and all, because
     * those are how a client shows the calendar user which invitation this
     * was about. §7.1's and §7.3's parameters are the two that may not go.
     */
    public function testTheAttendeeTravelsAsTheyStandButForTheServersOwn(): void
    {
        $message = $this->uninvitationOf(
            $this->objectWith(
                "ORGANIZER;SCHEDULE-STATUS=1.2:mailto:bernard@example.com\r\n"
                . 'ATTENDEE;CN=Cyrus;SCHEDULE-AGENT=SERVER;SCHEDULE-STATUS=1.2:mailto:cyrus@example.com',
            ),
            null,
        )->text();

        self::assertStringContainsString('CN=Cyrus', $message);
        self::assertStringNotContainsString('SCHEDULE-', $message);
    }

    /**
     * **The revision moves on here too**, RFC 5546 §2.1.4 asking it of the
     * method rather than of the reason: "The 'SEQUENCE' property value MUST be
     * incremented each time the 'Organizer' uses the 'ADD' or 'CANCEL'
     * methods."
     */
    public function testAnUninvitationMovesTheRevisionOn(): void
    {
        self::assertStringContainsString(
            "SEQUENCE:4\r\n",
            $this->uninvitationOf(
                $this->objectWith(self::PLAIN_INVITATION . "\r\nSEQUENCE:3"),
                null,
            )->text(),
        );
    }

    /**
     * **An instance is named by its own `RECURRENCE-ID`**, which §3.2.5 makes
     * the difference between the two cancellations it describes: "In order to
     * cancel an individual instance of the event, the 'RECURRENCE-ID' property
     * value for the event MUST be specified in the 'CANCEL' method."
     *
     * And the property travels whole, a `TZID` among its parameters being what
     * makes the value mean one moment (RFC 5545 §3.8.4.4).
     */
    public function testAnUninvitationFromOneInstanceNamesIt(): void
    {
        $message = $this->uninvitationOf($this->aSeries(), '20261102T100000Z')->text();

        self::assertStringContainsString("RECURRENCE-ID:20261102T100000Z\r\n", $message);
        self::assertSame(1, substr_count($message, 'BEGIN:VEVENT'));
    }

    /**
     * **And several instances are named one by one**, which is the second of
     * §3.2.5's two options: "Individual recurrence instances may be cancelled
     * by specifying multiple 'VEVENT' components each with a 'RECURRENCE-ID'
     * property corresponding to one of the instances to be cancelled."
     */
    public function testAnUninvitationFromSeveralInstancesNamesEachOfThem(): void
    {
        $message = $this->uninvitationOf($this->aSeries(), '20261102T100000Z', '20261103T100000Z')->text();

        self::assertSame(2, substr_count($message, 'BEGIN:VEVENT'));
        self::assertStringContainsString("RECURRENCE-ID:20261102T100000Z\r\n", $message);
        self::assertStringContainsString("RECURRENCE-ID:20261103T100000Z\r\n", $message);
    }

    /**
     * **The time zones travel with it**, for the reason they travel with every
     * other message: §3.2.5's constraints have `VTIMEZONE | 0+ | MUST be
     * present if any date/time refers to a timezone`, and a `RECURRENCE-ID`
     * that names a zone refers to one.
     */
    public function testAnUninvitationCarriesTheZonesItsTimesName(): void
    {
        $message = $this->uninvitationOf(
            $this->objectWith(
                self::PLAIN_INVITATION,
                "BEGIN:VTIMEZONE\r\nTZID:Europe/Berlin\r\n"
                . "BEGIN:STANDARD\r\nDTSTART:19701025T030000\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\n"
                . "END:STANDARD\r\nEND:VTIMEZONE\r\n",
            ),
            null,
        )->text();

        self::assertStringContainsString("BEGIN:VTIMEZONE\r\nTZID:Europe/Berlin\r\n", $message);
    }

    /**
     * **A component missing what §3.2.5 asks for is sent without it**, and
     * nothing is invented: RFC 5545 §3.6.1 makes `DTSTAMP` REQUIRED in a
     * `VEVENT`, so a resource without one was never a valid calendar object —
     * and a server that filled the gap would be sending a time it made up.
     */
    public function testWhatTheResourceNeverCarriedIsNotInvented(): void
    {
        $object = CalendarObject::in(
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dav.services//test//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nORGANIZER:mailto:bernard@example.com\r\n"
            . "ATTENDEE:mailto:cyrus@example.com\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
        );

        self::assertInstanceOf(Component::class, $object);
        self::assertStringNotContainsString('DTSTAMP', $this->uninvitationOf($object, null)->text());
    }

    /**
     * **The recipient keeps nothing of it**, which is the one message of the
     * three that leaves their own calendar alone.
     *
     * §4 names the two things the processing does — "creating or updating the
     * corresponding scheduling object resources" — and an uninvitation is
     * neither. There is nothing to create, and updating their copy "to reflect
     * the changes sent by the 'Organizer'" (§4.1) would put this message's own
     * shape in place of their event: a stump with one attendee and no start.
     *
     * Removing it is not among the two either, and it would throw away what
     * §3.2.2.1 lets an attendee keep there — their own `TRANSP`, their alarms.
     * So the Inbox notice is the whole message, which is what §4 puts there
     * for: "an indicator to the client that a scheduling operation has taken
     * place". Doing better wants the merge §3.2.10's `CALDAV:schedule-tag` is
     * for, and that is not here yet.
     */
    public function testTheRecipientKeepsNothingOfAnUninvitation(): void
    {
        self::assertNull(
            $this->uninvitationOf($this->objectWith(self::PLAIN_INVITATION), null)->whatTheRecipientKeeps(),
        );
    }

    /**
     * **And the object it was built from is not touched**, the same as for the
     * other two: the resource is about to be stored with §7.3's parameter on
     * it, and a message built by taking properties off in place would have
     * taken them off the client's own object.
     */
    public function testTheObjectAnUninvitationWasBuiltFromIsNotTouched(): void
    {
        $object = $this->objectWith(self::PLAIN_INVITATION . "\r\nSEQUENCE:3");
        $before = (new Serializer())->write($object);

        $this->uninvitationOf($object, null);

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
     * **The whole reply, as RFC 5546 §3.2.3 asks for it**, which is the shape
     * §4.2.2's example has: the one attendee replying with their answer, the
     * organizer it goes to, the `UID` of the request it answers, the sequence
     * number of that request, and the time stamp.
     *
     * Written out because what is *not* in it is the case: every other
     * property of the event stays behind. §3.2.3 says why in a sentence —
     * "The optional properties listed in the table below (those listed as
     * '0+' or '0 or 1') MUST NOT be changed from those of the original
     * request" — and a message that carries none of them cannot have changed
     * one.
     */
    public function testTheReplyIsTheOneAnswerAndWhatNamesTheEvent(): void
    {
        $object = $this->objectWith(self::PLAIN_INVITATION . "\r\nSEQUENCE:4\r\nSUMMARY:Lunch");

        self::assertSame(
            "BEGIN:VCALENDAR\r\nMETHOD:REPLY\r\nVERSION:2.0\r\nPRODID:-//dav.services//test//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\n"
            . "ORGANIZER:mailto:bernard@example.com\r\nSEQUENCE:4\r\n"
            . "ATTENDEE;PARTSTAT=ACCEPTED:mailto:cyrus@example.com\r\n"
            . "END:VEVENT\r\nEND:VCALENDAR\r\n",
            $this->replyAbout($object, null, 'ACCEPTED')->text(),
        );
    }

    /**
     * **One `ATTENDEE` and that is the one replying**, which §3.2.3's
     * constraints make the only possibility: `ATTENDEE | 1 | MUST be the
     * address of the Attendee replying.` Who else is coming is the
     * organizer's own list, and a reply that sent it back would be answering
     * for them.
     */
    public function testTheReplyCarriesNobodyButTheOneReplying(): void
    {
        $object = $this->objectWith(
            self::PLAIN_INVITATION . "\r\nATTENDEE;PARTSTAT=ACCEPTED:mailto:eric@example.com",
        );

        $text = $this->replyAbout($object, null, 'DECLINED')->text();

        self::assertStringContainsString("ATTENDEE;PARTSTAT=DECLINED:mailto:cyrus@example.com\r\n", $text);
        self::assertStringNotContainsString('eric@example.com', $text);
    }

    /**
     * **A reply about the series is named by nothing**, §3.2.3 putting the
     * condition on the property itself: `RECURRENCE-ID | 0 or 1 | Only if
     * referring to an instance of a recurring calendar component. Otherwise,
     * it MUST NOT be present.`
     */
    public function testAReplyAboutTheSeriesNamesNoInstance(): void
    {
        $text = $this->replyAbout($this->aSeries(), null, 'ACCEPTED')->text();

        self::assertStringNotContainsString('RECURRENCE-ID', $text);
    }

    /**
     * **And one about an instance names it**, which is the other half of the
     * same row.
     */
    public function testAReplyAboutAnInstanceNamesIt(): void
    {
        $text = $this->replyAbout($this->aSeries(), '20261102T100000Z', 'DECLINED')->text();

        self::assertStringContainsString("RECURRENCE-ID:20261102T100000Z\r\n", $text);
    }

    /**
     * **The revision is the one it answers, not the next one.** §3.2.3:
     * `SEQUENCE | 0 or 1 | If non-zero, MUST be the sequence number of the
     * original REQUEST.` Which is the opposite of what a `CANCEL` does —
     * §2.1.4 has the organizer increment it "each time […] the 'ADD' or
     * 'CANCEL' methods" — and for the same reason: an answer is about the
     * revision it answers, and a revision of the event is the organizer's to
     * make.
     */
    public function testTheReplyCarriesTheRevisionItAnswers(): void
    {
        $text = $this->replyAbout($this->aSeries(), '20261102T100000Z', 'ACCEPTED')->text();

        self::assertStringContainsString("SEQUENCE:4\r\n", $text);
    }

    /**
     * **And where there is none it carries none**, §3.2.3's row ending "MAY be
     * present if 0". RFC 5545 §3.8.7.4 has the count start at nought — "When a
     * calendar component is created, its sequence number is 0" — so a `0` the
     * server wrote itself would say what the absence says, and a number it
     * made up would be worse than either.
     */
    public function testAReplyToARequestWithoutARevisionCarriesNone(): void
    {
        $text = $this->replyAbout($this->objectWith(self::PLAIN_INVITATION), null, 'ACCEPTED')->text();

        self::assertStringNotContainsString('SEQUENCE', $text);
    }

    /**
     * **No alarm travels**, `VALARM | 0` in §3.2.3's constraints as in
     * §3.2.5's: a reminder on the attendee's own copy is theirs, and an
     * answer is no occasion to send anybody one.
     */
    public function testTheReplyCarriesNoAlarm(): void
    {
        $object = $this->objectWith(
            self::PLAIN_INVITATION
            . "
BEGIN:VALARM
ACTION:DISPLAY
TRIGGER:-PT15M
DESCRIPTION:Lunch
END:VALARM",
        );

        self::assertStringNotContainsString('VALARM', $this->replyAbout($object, null, 'ACCEPTED')->text());
    }

    /**
     * **The reply carries no `REQUEST-STATUS`**, which §3.2.3 allows at `0+`
     * and §4.2.2's example says is not wanted: "'REQUEST-STATUS' is not
     * required in successful transactions."
     *
     * It is not left out to save a line. §4.2 reads the property as what the
     * organizer's copy is to record — "set its value to that of the
     * 'REQUEST-STATUS' property in the reply, or to '2.0' if 'REQUEST-STATUS'
     * is not present" — so its absence is the server saying the delivery went
     * through, and a status copied off the attendee's resource would be an
     * answer to some older message of the organizer's.
     */
    public function testTheReplyCarriesNoRequestStatus(): void
    {
        $object = $this->objectWith(self::PLAIN_INVITATION . "\r\nREQUEST-STATUS:3.7;Invalid calendar user");

        self::assertStringNotContainsString('REQUEST-STATUS', $this->replyAbout($object, null, 'ACCEPTED')->text());
    }

    /**
     * **The organizer keeps nothing of a reply**, which is what sets it apart
     * from the two messages an organizer sends. §4.2 says what the processing
     * does with one, and it is neither of §4's "creating or updating the
     * corresponding scheduling object resources": it is an update of two
     * parameters on a resource that is already there — "update the 'PARTSTAT'
     * iCalendar property parameter value of each 'ATTENDEE' iCalendar property
     * […] to match the changes indicated in the reply".
     */
    public function testTheOrganizerKeepsNothingOfAReply(): void
    {
        $reply = $this->replyAbout($this->objectWith(self::PLAIN_INVITATION), null, 'ACCEPTED');

        self::assertNull($reply->whatTheRecipientKeeps());
    }

    /**
     * **The zone an instance is named in travels with it.** §3.2.3:
     * `VTIMEZONE | 0 or 1 | MUST be present if any date/time refers to a
     * timezone`, and RFC 5545 §3.8.4.4 makes a `RECURRENCE-ID` mean one
     * moment only together with its `TZID`.
     */
    public function testTheZoneAnInstanceIsNamedInTravels(): void
    {
        $object = $this->objectWith(
            self::PLAIN_INVITATION . "\r\nRRULE:FREQ=DAILY;COUNT=3\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261102T110000Z\r\n"
            . "RECURRENCE-ID;TZID=Europe/Berlin:20261102T100000\r\n" . self::PLAIN_INVITATION,
            "BEGIN:VTIMEZONE\r\nTZID:Europe/Berlin\r\nEND:VTIMEZONE\r\n"
            . "BEGIN:VTIMEZONE\r\nTZID:America/New_York\r\nEND:VTIMEZONE\r\n",
        );

        $text = $this->replyAbout($object, '20261102T100000', 'DECLINED')->text();

        self::assertStringContainsString("TZID:Europe/Berlin\r\n", $text);
        self::assertStringNotContainsString('America/New_York', $text);
    }

    /**
     * **And a reply that names no moment in a zone carries none.** The only
     * other time in the message is its `DTSTAMP`, which RFC 5545 §3.8.7.2
     * makes a UTC value: "The value MUST be specified in the UTC time format."
     * A zone sent with nothing referring to it would be the one thing §3.2.3
     * counts: `VTIMEZONE | 0 or 1`.
     */
    public function testAReplyThatNamesNoMomentInAZoneCarriesNoZone(): void
    {
        $object = $this->objectWith(
            self::PLAIN_INVITATION,
            "BEGIN:VTIMEZONE\r\nTZID:Europe/Berlin\r\nEND:VTIMEZONE\r\n",
        );

        self::assertStringNotContainsString('VTIMEZONE', $this->replyAbout($object, null, 'ACCEPTED')->text());
    }

    /**
     * **A zone with no name is no zone the message can be about.** RFC 5545
     * §3.6.5 makes `TZID` REQUIRED in a `VTIMEZONE` — "'tzid' is REQUIRED, but
     * MUST NOT occur more than once" — so a component without one was never
     * valid; `CALDAV:valid-calendar-data` is what refuses it, and that happens
     * in another plugin on the same request.
     *
     * Which is why it is asked rather than assumed here: a message built while
     * such a component is still in the tree may not take the server down, and
     * the zone the instance really names still has to travel.
     */
    public function testAZoneWithNoNameIsPassedOver(): void
    {
        $object = $this->objectWith(
            self::PLAIN_INVITATION . "\r\nRRULE:FREQ=DAILY;COUNT=3\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261102T110000Z\r\n"
            . "RECURRENCE-ID;TZID=Europe/Berlin:20261102T100000\r\n" . self::PLAIN_INVITATION,
            "BEGIN:VTIMEZONE\r\nEND:VTIMEZONE\r\n"
            . "BEGIN:VTIMEZONE\r\nTZID:Europe/Berlin\r\nEND:VTIMEZONE\r\n",
        );

        $text = $this->replyAbout($object, '20261102T100000', 'DECLINED')->text();

        self::assertSame(1, substr_count($text, "BEGIN:VTIMEZONE\r\n"));
        self::assertStringContainsString("TZID:Europe/Berlin\r\n", $text);
    }

    /**
     * **A zone named that the object does not hold is no zone at all.** RFC
     * 5545 §3.6.5 has a `TZID` be "a reference to the unique time zone
     * identifier" of a component in the same object, so a resource naming one
     * it does not carry was never valid — and a reply about it is still built,
     * carrying no zone rather than failing.
     */
    public function testAZoneNamedButNotHeldIsNoZone(): void
    {
        $object = $this->objectWith(
            self::PLAIN_INVITATION . "\r\nRRULE:FREQ=DAILY;COUNT=3\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261102T110000Z\r\n"
            . "RECURRENCE-ID;TZID=Europe/Berlin:20261102T100000\r\n" . self::PLAIN_INVITATION,
        );

        $text = $this->replyAbout($object, '20261102T100000', 'DECLINED')->text();

        self::assertStringNotContainsString('VTIMEZONE', $text);
        self::assertStringContainsString("RECURRENCE-ID;TZID=Europe/Berlin:20261102T100000\r\n", $text);
    }

    /**
     * **And the zone is looked for behind an instance that names none.** A
     * reply can carry the series and an instance of it at once — the series is
     * named by nothing at all, so the first record has no zone to offer and
     * the second one's still has to travel.
     */
    public function testTheZoneIsFoundBehindAnInstanceThatNamesNone(): void
    {
        $object = $this->objectWith(
            self::PLAIN_INVITATION . "\r\nRRULE:FREQ=DAILY;COUNT=3\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261102T110000Z\r\n"
            . "RECURRENCE-ID;TZID=Europe/Berlin:20261102T100000\r\n" . self::PLAIN_INVITATION,
            "BEGIN:VTIMEZONE\r\nTZID:Europe/Berlin\r\nEND:VTIMEZONE\r\n",
        );
        $instances = [];

        foreach ([null, '20261102T100000'] as $id) {
            $component = $this->theComponentNamed($object, $id);
            $instances[] = [
                $component,
                $component->property('RECURRENCE-ID'),
                new Property('ATTENDEE', 'mailto:cyrus@example.com', [new Parameter('PARTSTAT', ['ACCEPTED'])]),
            ];
        }

        self::assertStringContainsString(
            "TZID:Europe/Berlin\r\n",
            SchedulingMessage::replying($object, $instances)->text(),
        );
    }

    /**
     * **Two instances answered are two components of one message**, §3.2.3
     * having `VEVENT | 1+ | All components MUST have the same UID.`
     */
    public function testTwoInstancesAnsweredAreTwoComponentsOfOneReply(): void
    {
        $object = $this->aSeries();
        $instances = [];

        foreach (['20261102T100000Z', '20261103T100000Z'] as $id) {
            $component = $this->theComponentNamed($object, $id);
            $instances[] = [
                $component,
                $component->property('RECURRENCE-ID'),
                new Property('ATTENDEE', 'mailto:cyrus@example.com', [new Parameter('PARTSTAT', ['DECLINED'])]),
            ];
        }

        $text = SchedulingMessage::replying($object, $instances)->text();

        self::assertSame(2, substr_count($text, "BEGIN:VEVENT\r\n"));
        self::assertStringContainsString("RECURRENCE-ID:20261102T100000Z\r\n", $text);
        self::assertStringContainsString("RECURRENCE-ID:20261103T100000Z\r\n", $text);
    }

    /**
     * **And the two parameters the server keeps to itself do not travel in a
     * reply either**, §7.1 and §7.3 saying it of "any scheduling messages sent
     * as the result of a scheduling operation" without naming a direction.
     */
    public function testTheReplyCarriesNeitherOfTheServersOwnParameters(): void
    {
        $object = $this->objectWith(
            "ORGANIZER;SCHEDULE-STATUS=2.0:mailto:bernard@example.com\r\n"
            . 'ATTENDEE;SCHEDULE-AGENT=SERVER:mailto:cyrus@example.com',
        );

        $text = $this->replyAbout($object, null, 'ACCEPTED')->text();

        self::assertStringNotContainsString('SCHEDULE-STATUS', $text);
        self::assertStringNotContainsString('SCHEDULE-AGENT', $text);
    }

    /**
     * The reply this object sends about that instance, with that answer.
     *
     * The record is {@see \DavServices\CalDav\Schedule\Answer}'s: the
     * component the message is built from, the property naming the instance,
     * and the one `ATTENDEE` property that travels.
     */
    private function replyAbout(Component $object, ?string $id, string $answer): SchedulingMessage
    {
        $component = $this->theComponentNamed($object, $id);
        $attendee = new Property(
            'ATTENDEE',
            'mailto:cyrus@example.com',
            [new Parameter('PARTSTAT', [$answer])],
        );

        return SchedulingMessage::replying(
            $object,
            [[$component, $component->property('RECURRENCE-ID'), $attendee]],
        );
    }

    /**
     * The message a `PUT` of this object would send.
     */
    private function messageAbout(string $properties, string $alongside = ''): SchedulingMessage
    {
        return SchedulingMessage::requesting($this->objectWith($properties, $alongside));
    }

    /**
     * What the recipient keeps of a message that leaves them something.
     */
    private function keptOf(SchedulingMessage $message): string
    {
        return $message->whatTheRecipientKeeps() ?? self::fail('The recipient keeps nothing of it.');
    }

    /**
     * The uninvitation this object sends Cyrus, naming those instances — or
     * the complete range where the instance is named by nothing.
     */
    private function uninvitationOf(Component $object, ?string ...$ids): SchedulingMessage
    {
        $instances = [];

        foreach ($ids as $id) {
            $component = $this->theComponentNamed($object, $id);

            $instances[] = [$component, $component->property('RECURRENCE-ID')];
        }

        return SchedulingMessage::uninviting($object, $instances, 'mailto:cyrus@example.com');
    }

    /**
     * The component of that object whose `RECURRENCE-ID` says that, or the
     * master where nothing is named.
     */
    private function theComponentNamed(Component $object, ?string $id): Component
    {
        foreach ($object->components('VEVENT') as $component) {
            if ($component->property('RECURRENCE-ID')?->value() === $id) {
                return $component;
            }
        }

        return self::fail(sprintf('No component named "%s".', $id ?? 'the master'));
    }

    /**
     * A recurring event with two overridden instances, all three inviting
     * Cyrus.
     */
    private function aSeries(): Component
    {
        return $this->objectWith(
            self::PLAIN_INVITATION . "\r\nRRULE:FREQ=DAILY;COUNT=3\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261102T110000Z\r\n"
            . "RECURRENCE-ID:20261102T100000Z\r\nSEQUENCE:4\r\n"
            . self::PLAIN_INVITATION . "\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261103T110000Z\r\n"
            . "RECURRENCE-ID:20261103T100000Z\r\n"
            . self::PLAIN_INVITATION,
        );
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
