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
use DavServices\CalDav\Schedule\ForceSend;
use DavServices\VObject\Component;
use DavServices\VObject\Serializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for the message a calendar user asks to have sent anyway (RFC 6638
 * §3.2.7 and §7.2). R-CAL-32, P8-02k.
 *
 * > The iCalendar property parameter "SCHEDULE-FORCE-SEND" […] can be used by
 * > a calendar user to force the server to send a scheduling message to an
 * > "Attendee" or the "Organizer" in a situation where the server would not
 * > normally send a scheduling message.
 *
 * ## Three sentences of §7.2, and each of them is tested twice
 *
 * **"Servers MUST NOT preserve this property parameter in scheduling object
 * resources"** — so every case here asks what is left of the object, and the
 * cases that read nothing off a property still ask whether the parameter went.
 *
 * **One method for each of the two properties.** §7.2 names `REQUEST` for an
 * `ATTENDEE` and `REPLY` for an `ORGANIZER`, so there are four combinations of
 * the two names and two values, and only two of them are asking for something
 * this server can send.
 *
 * **"Servers MUST set the 'SCHEDULE-STATUS' parameter […] to 2.3 […] when the
 * 'SCHEDULE-FORCE-SEND' parameter is set to an iana-token value they do not
 * recognize"** — and what counts as unrecognised is every way of writing a
 * value that is not the one method for that property: the wrong one of the
 * two, a token nobody registered, nothing at all, and a list where the
 * grammar has a single value.
 *
 * The parameter permitted only "on 'ATTENDEE' and 'ORGANIZER' properties on
 * which the 'SCHEDULE-AGENT' property parameter is set to the value 'SERVER'
 * or is not specified" gives the three cases that read nothing: a property the
 * client schedules for, a property §7.2 names neither of, and an `ATTENDEE`
 * inside a `VALARM`, which RFC 5545 §3.6.6 makes an address an alarm is
 * emailed to.
 */
#[CoversClass(ForceSend::class)]
final class ForceSendTest extends TestCase
{
    private const BERNARD = 'mailto:bernard@example.com';

    private const CYRUS = 'mailto:cyrus@example.com';

    private const ORGANISED = 'ORGANIZER:' . self::BERNARD;

    private const INVITED = 'ATTENDEE:' . self::CYRUS;

    /**
     * **A `REPLY` asked of the organizer is one this server can send.** §7.2:
     * "An 'Attendee' MAY specify this parameter on the 'ORGANIZER' with the
     * value 'REPLY' to force a 'REPLY' scheduling message to be sent to the
     * 'Organizer'."
     */
    public function testAReplyAskedOfTheOrganizerIsAsked(): void
    {
        $object = $this->anObject($this->anEvent(
            'ORGANIZER;SCHEDULE-FORCE-SEND=REPLY:' . self::BERNARD,
            self::INVITED,
        ));

        $forced = ForceSend::takenOutOf($object);

        self::assertTrue($forced->wasThere());
        self::assertTrue($forced->asksForAReplyTo(self::BERNARD));
        self::assertFalse($forced->wasIgnoredOnTheOrganizer(self::BERNARD));
        self::assertStringNotContainsString(ForceSend::NAME, self::text($object));
    }

    /**
     * **A `REQUEST` asked of an attendee is one too, and is no reply to
     * force.** §7.2: "An 'Organizer' MAY specify this parameter on an
     * 'ATTENDEE' property with the value 'REQUEST'" — which §3.2.1.2's table
     * already sends to such an attendee, so there is nothing to report and
     * nothing to put right.
     */
    public function testARequestAskedOfAnAttendeeIsNoReplyToForce(): void
    {
        $object = $this->anObject($this->anEvent(
            self::ORGANISED,
            'ATTENDEE;SCHEDULE-FORCE-SEND=REQUEST:' . self::CYRUS,
        ));

        $forced = ForceSend::takenOutOf($object);

        self::assertTrue($forced->wasThere());
        self::assertFalse($forced->asksForAReplyTo(self::BERNARD));
        self::assertFalse($forced->wasIgnoredOnTheAttendee(self::CYRUS));
        self::assertStringNotContainsString(ForceSend::NAME, self::text($object));
    }

    /**
     * **A `REPLY` asked of an attendee is a value this server cannot read.**
     * It is a registered `METHOD` and the wrong one for the property: this
     * server sends an attendee a `REQUEST` or a `CANCEL`, and §7.2's own
     * sentence pairs `REPLY` with the `ORGANIZER`.
     */
    public function testAReplyAskedOfAnAttendeeIsAValueThisServerCannotRead(): void
    {
        $object = $this->anObject($this->anEvent(
            self::ORGANISED,
            'ATTENDEE;SCHEDULE-FORCE-SEND=REPLY:' . self::CYRUS,
        ));

        $forced = ForceSend::takenOutOf($object);

        self::assertTrue($forced->wasIgnoredOnTheAttendee(self::CYRUS));
        self::assertFalse($forced->wasIgnoredOnTheOrganizer(self::CYRUS));
        self::assertStringNotContainsString(ForceSend::NAME, self::text($object));
    }

    /**
     * **And a `REQUEST` asked of the organizer is the mirror of it**, which is
     * what makes this four cases rather than two.
     */
    public function testARequestAskedOfTheOrganizerIsOneToo(): void
    {
        $object = $this->anObject($this->anEvent(
            'ORGANIZER;SCHEDULE-FORCE-SEND=REQUEST:' . self::BERNARD,
            self::INVITED,
        ));

        $forced = ForceSend::takenOutOf($object);

        self::assertFalse($forced->asksForAReplyTo(self::BERNARD));
        self::assertTrue($forced->wasIgnoredOnTheOrganizer(self::BERNARD));
        self::assertStringNotContainsString(ForceSend::NAME, self::text($object));
    }

    /**
     * **A token nobody registered is §7.2's own case.** Its grammar ends in
     * `iana-token` whose "value MUST be an IANA-registered iCalendar 'METHOD'
     * property value", and the sentence about 2.3 names exactly this.
     */
    public function testATokenNobodyRegisteredIsOneToo(): void
    {
        $object = $this->anObject($this->anEvent(
            'ORGANIZER;SCHEDULE-FORCE-SEND=X-SHOUT-LOUDER:' . self::BERNARD,
            self::INVITED,
        ));

        $forced = ForceSend::takenOutOf($object);

        self::assertFalse($forced->asksForAReplyTo(self::BERNARD));
        self::assertTrue($forced->wasIgnoredOnTheOrganizer(self::BERNARD));
    }

    /**
     * **A parameter with nothing in it is a value this server cannot read**,
     * rather than a parameter that is not there: §7.2's grammar has a value
     * after the `=`, and a client that wrote the name meant to ask for
     * something.
     */
    public function testAParameterWithoutAValueIsOneToo(): void
    {
        $object = $this->anObject($this->anEvent(
            'ORGANIZER;SCHEDULE-FORCE-SEND=:' . self::BERNARD,
            self::INVITED,
        ));

        $forced = ForceSend::takenOutOf($object);

        self::assertFalse($forced->asksForAReplyTo(self::BERNARD));
        self::assertTrue($forced->wasIgnoredOnTheOrganizer(self::BERNARD));
    }

    /**
     * **And so is a list where §7.2's grammar has one value**, which is the
     * reading {@see \DavServices\CalDav\Schedule\ScheduleAgent::on()} makes of
     * §7.1's grammar for the same reason: two answers to a question that has
     * one is no answer.
     */
    public function testAListWhereTheGrammarHasOneValueIsOneToo(): void
    {
        $object = $this->anObject($this->anEvent(
            'ORGANIZER;SCHEDULE-FORCE-SEND=REPLY,REQUEST:' . self::BERNARD,
            self::INVITED,
        ));

        $forced = ForceSend::takenOutOf($object);

        self::assertFalse($forced->asksForAReplyTo(self::BERNARD));
        self::assertTrue($forced->wasIgnoredOnTheOrganizer(self::BERNARD));
    }

    /**
     * **The name and the value are read without regard to case.** RFC 5545
     * §3.5: "Property names, parameter names, and enumerated parameter values
     * are case-insensitive", and both of §7.2's named alternatives are
     * enumerated.
     */
    public function testTheNameAndTheValueAreReadWithoutRegardToCase(): void
    {
        $object = $this->anObject($this->anEvent(
            'organizer;schedule-force-send=reply:' . self::BERNARD,
            self::INVITED,
        ));

        $forced = ForceSend::takenOutOf($object);

        self::assertTrue($forced->asksForAReplyTo(self::BERNARD));
        self::assertStringNotContainsStringIgnoringCase(ForceSend::NAME, self::text($object));
    }

    /**
     * **A property the client schedules for is read for nothing, and cleaned
     * all the same.** §7.2 permits the parameter only where the
     * `SCHEDULE-AGENT` "is set to the value 'SERVER' or is not specified", and
     * §7.1 puts such a property outside this server's scheduling — so there is
     * nothing to force and nothing to report, while the MUST NOT about keeping
     * it is written of the resource and knows no exception.
     */
    public function testAPropertyTheClientSchedulesForIsReadForNothing(): void
    {
        $object = $this->anObject($this->anEvent(
            'ORGANIZER;SCHEDULE-AGENT=CLIENT;SCHEDULE-FORCE-SEND=REPLY:' . self::BERNARD,
            self::INVITED,
        ));

        $forced = ForceSend::takenOutOf($object);

        self::assertTrue($forced->wasThere());
        self::assertFalse($forced->asksForAReplyTo(self::BERNARD));
        self::assertFalse($forced->wasIgnoredOnTheOrganizer(self::BERNARD));
        self::assertStringNotContainsString(ForceSend::NAME, self::text($object));
        self::assertStringContainsString('SCHEDULE-AGENT=CLIENT', self::text($object));
    }

    /**
     * **And so is a property §7.2 names neither of.** "This property parameter
     * MAY be specified on 'ATTENDEE' and 'ORGANIZER' properties", so a
     * `DTSTART` carrying it is asking nobody for anything.
     */
    public function testAParameterOnAnotherPropertyIsReadForNothing(): void
    {
        $object = $this->anObject($this->anEvent(
            self::ORGANISED,
            self::INVITED,
            'LOCATION;SCHEDULE-FORCE-SEND=REPLY:Bakery',
        ));

        $forced = ForceSend::takenOutOf($object);

        self::assertTrue($forced->wasThere());
        self::assertFalse($forced->asksForAReplyTo('Bakery'));
        self::assertStringNotContainsString(ForceSend::NAME, self::text($object));
        self::assertStringContainsString('LOCATION:Bakery', self::text($object));
    }

    /**
     * **An alarm's attendee is nobody to send a scheduling message to.** RFC
     * 5545 §3.6.6 makes an `ATTENDEE` inside a `VALARM` "contain the email
     * address of the recipient", so the parameter is read off nothing — and
     * taken off, the resource being the resource.
     */
    public function testAnAlarmsAttendeeIsReadForNothing(): void
    {
        $object = $this->anObject(
            $this->anEvent(self::ORGANISED, self::INVITED),
            $this->anAlarm(),
        );

        $forced = ForceSend::takenOutOf($object);

        self::assertTrue($forced->wasThere());
        self::assertFalse($forced->wasIgnoredOnTheAttendee('mailto:eric@example.com'));
        self::assertStringNotContainsString(ForceSend::NAME, self::text($object));
        self::assertStringContainsString('ATTENDEE:mailto:eric@example.com', self::text($object));
    }

    /**
     * **An object without the parameter comes back exactly as it went in**,
     * which is what keeps a resource nobody forced anything on from being
     * written back for nothing.
     */
    public function testAnObjectWithoutTheParameterIsLeftAsItWas(): void
    {
        $object = $this->anObject($this->anEvent(self::ORGANISED, self::INVITED));
        $was = self::text($object);

        $forced = ForceSend::takenOutOf($object);

        self::assertFalse($forced->wasThere());
        self::assertFalse($forced->asksForAReplyTo(self::BERNARD));
        self::assertFalse($forced->wasIgnoredOnTheOrganizer(self::BERNARD));
        self::assertFalse($forced->wasIgnoredOnTheAttendee(self::CYRUS));
        self::assertSame($was, self::text($object));
    }

    /**
     * **One asked and one unreadable in the same object**, each answered for
     * itself: §7.2's parameter sits on a property, and two properties are two
     * questions.
     */
    public function testOneAskedAndOneUnreadableAreAnsweredApart(): void
    {
        $object = $this->anObject($this->anEvent(
            'ORGANIZER;SCHEDULE-FORCE-SEND=REPLY:' . self::BERNARD,
            'ATTENDEE;SCHEDULE-FORCE-SEND=PUBLISH:' . self::CYRUS,
        ));

        $forced = ForceSend::takenOutOf($object);

        self::assertTrue($forced->asksForAReplyTo(self::BERNARD));
        self::assertFalse($forced->wasIgnoredOnTheOrganizer(self::BERNARD));
        self::assertTrue($forced->wasIgnoredOnTheAttendee(self::CYRUS));
        self::assertStringNotContainsString(ForceSend::NAME, self::text($object));
    }

    /**
     * **Every component is cleaned, and the one that asked is still read.**
     * RFC 5545 §3.6 imposes no order on the components of a calendar object,
     * so an overriding component carrying the parameter is no different from
     * the master carrying it — and §7.2's MUST NOT reaches both.
     */
    public function testEveryComponentIsCleanedAndTheOneThatAskedIsRead(): void
    {
        $object = $this->anObject(
            $this->anEvent('ORGANIZER;SCHEDULE-FORCE-SEND=PUBLISH:' . self::BERNARD, self::INVITED),
            $this->anOverride('ORGANIZER;SCHEDULE-FORCE-SEND=REPLY:' . self::BERNARD),
        );

        $forced = ForceSend::takenOutOf($object);

        self::assertTrue($forced->asksForAReplyTo(self::BERNARD));
        self::assertTrue($forced->wasIgnoredOnTheOrganizer(self::BERNARD));
        self::assertStringNotContainsString(ForceSend::NAME, self::text($object));
    }

    /**
     * A `VCALENDAR` holding those components.
     */
    private function anObject(string ...$components): Component
    {
        return CalendarObject::in(
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dav.services//test//EN\r\n"
            . implode('', $components)
            . "END:VCALENDAR\r\n",
        ) ?? self::fail('The fixture is no calendar object.');
    }

    /**
     * One event carrying those lines.
     */
    private function anEvent(string ...$properties): string
    {
        return "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261101T100000Z\r\n"
            . implode("\r\n", $properties) . "\r\nEND:VEVENT\r\n";
    }

    /**
     * One overriding component carrying that line.
     */
    private function anOverride(string $organizer): string
    {
        return "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261102T110000Z\r\n"
            . "RECURRENCE-ID:20261102T100000Z\r\n" . $organizer . "\r\n"
            . self::INVITED . "\r\nEND:VEVENT\r\n";
    }

    /**
     * An alarm that emails somebody, carrying the parameter on the address it
     * emails.
     */
    private function anAlarm(): string
    {
        return "BEGIN:VEVENT\r\nUID:2\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261103T100000Z\r\n"
            . self::ORGANISED . "\r\nBEGIN:VALARM\r\nACTION:EMAIL\r\nTRIGGER:-PT15M\r\n"
            . "DESCRIPTION:Soon\r\nSUMMARY:Soon\r\n"
            . 'ATTENDEE;SCHEDULE-FORCE-SEND=REQUEST:mailto:eric@example.com' . "\r\n"
            . "END:VALARM\r\nEND:VEVENT\r\n";
    }

    /**
     * What that object says now, with the folding taken out.
     *
     * RFC 5545 §3.1 breaks a line longer than 75 octets, and a property with
     * two parameters on it passes that — so an expectation written as one line
     * would be looking for something the text never holds.
     */
    private static function text(Component $object): string
    {
        return str_replace("\r\n ", '', (new Serializer())->write($object));
    }
}
