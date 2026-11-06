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
use DavServices\CalDav\Schedule\SchedulingObject;
use DavServices\VObject\Component;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for RFC 6638 §3.1, "Identifying Scheduling Object Resources".
 * R-CAL-32, P8-02a.
 *
 * **This is the question every other question in §3 rests on**, and it is two
 * sentences long:
 *
 * > A calendar object resource is considered to be a valid **organizer**
 * > scheduling object resource if the "ORGANIZER" iCalendar property is
 * > present and set in all the calendar components to a value that matches one
 * > of the calendar user addresses of the owner of the calendar collection.
 * >
 * > A calendar object resource is considered to be a valid **attendee**
 * > scheduling object resource if the "ORGANIZER" iCalendar property is
 * > present and set in all the calendar components to the same value and
 * > doesn't match one of the calendar user addresses of the owner of the
 * > calendar collection, and if at least one of the "ATTENDEE" iCalendar
 * > property values matches one of the calendar user addresses of the owner of
 * > the calendar collection.
 *
 * Read closely, the two are not symmetric: the organizer sentence asks each
 * component's `ORGANIZER` to match one of the owner's addresses, while the
 * attendee sentence asks for one value **the same in every component** which
 * is not the owner's. So a resource whose components disagree about who
 * organises it can be the organizer's and cannot be an attendee's.
 *
 * ## The comparison is case-sensitive, and the memo says so
 *
 * RFC 5545 §3.1: "All names of properties, property parameters, enumerated
 * property values and property parameter values are case-insensitive.
 * **However, all other property values are case-sensitive, unless otherwise
 * stated.**" An `ORGANIZER` value is none of the three case-insensitive kinds,
 * and neither RFC 5545 §3.8.4.3 nor RFC 6638 §3.1 states otherwise.
 *
 * That has a cost worth knowing: a client that writes `MAILTO:` where the
 * address set says `mailto:` gets a resource this server does not schedule,
 * and nobody is told. It is the memo's rule, and the alternative would be a
 * server deciding for itself that two different values are one.
 */
#[CoversClass(SchedulingObject::class)]
final class SchedulingObjectTest extends TestCase
{
    /**
     * Bernard, with two addresses, which is what §2.4.1's `DAV:href*` allows.
     */
    private const OWNER = ['mailto:bernard@example.com', 'urn:uuid:bernard'];

    private const SOMEBODY_ELSE = 'mailto:cyrus@example.com';

    /**
     * **The first sentence**: the owner organises it.
     */
    public function testAnObjectTheOwnerOrganisesIsTheirs(): void
    {
        self::assertSame(
            SchedulingObject::Organizers,
            $this->kindOf('ORGANIZER:mailto:bernard@example.com'),
        );
    }

    /**
     * And by any of their addresses, §2.4.1 holding `DAV:href*` — a list, so
     * matching "one of" them means any.
     */
    public function testAnyOfTheOwnersAddressesCounts(): void
    {
        self::assertSame(SchedulingObject::Organizers, $this->kindOf('ORGANIZER:urn:uuid:bernard'));
    }

    /**
     * **The second sentence**: somebody else organises it and the owner is
     * invited.
     */
    public function testAnObjectSomebodyElseOrganisesWithTheOwnerInvitedIsTheAttendees(): void
    {
        self::assertSame(
            SchedulingObject::Attendees,
            $this->kindOf(
                'ORGANIZER:' . self::SOMEBODY_ELSE . "\r\n"
                . 'ATTENDEE:mailto:bernard@example.com',
            ),
        );
    }

    /**
     * **An invitation to somebody else is nobody's here.** Both sentences need
     * the owner somewhere in the data, and this has them nowhere.
     */
    public function testAnObjectThatNamesTheOwnerNowhereIsNeither(): void
    {
        self::assertNull($this->kindOf(
            'ORGANIZER:' . self::SOMEBODY_ELSE . "\r\n"
            . 'ATTENDEE:mailto:wilfredo@example.com',
        ));
    }

    /**
     * **An object with no `ORGANIZER` is neither**, both sentences beginning
     * with the property being "present". A calendar entry somebody keeps for
     * themselves is the ordinary case of this, and it is the reason the
     * question is asked at all: the server leaves it alone.
     */
    public function testAnObjectWithNoOrganizerIsNeither(): void
    {
        self::assertNull($this->kindOf('SUMMARY:lunch'));
    }

    /**
     * **Every component has to carry it**, "present and set in **all** the
     * calendar components". A recurrence override that forgot it would
     * otherwise make the server schedule half a resource.
     */
    public function testAComponentWithoutOneLeavesTheWholeResourceNeither(): void
    {
        $object = CalendarObject::in(
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dav.services//test//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261101T100000Z\r\n"
            . "ORGANIZER:mailto:bernard@example.com\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nRECURRENCE-ID:20261102T100000Z\r\nDTSTAMP:20261101T090000Z\r\n"
            . "DTSTART:20261102T110000Z\r\nEND:VEVENT\r\n"
            . "END:VCALENDAR\r\n",
        );

        self::assertNotNull($object);
        self::assertNull(SchedulingObject::in($object, self::OWNER));
    }

    /**
     * **And for an attendee's resource it has to be the same value**, which is
     * where the two sentences part company: that one asks for `ORGANIZER`
     * "set in all the calendar components to **the same value**".
     *
     * Two components naming two different organizers is a resource nobody can
     * reply to — a `REPLY` goes to one address — so it is neither.
     */
    public function testComponentsThatDisagreeAboutTheOrganizerAreNeither(): void
    {
        $object = CalendarObject::in(
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dav.services//test//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261101T100000Z\r\n"
            . 'ORGANIZER:' . self::SOMEBODY_ELSE . "\r\nATTENDEE:mailto:bernard@example.com\r\n"
            . "END:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nRECURRENCE-ID:20261102T100000Z\r\nDTSTAMP:20261101T090000Z\r\n"
            . "DTSTART:20261102T110000Z\r\nORGANIZER:mailto:wilfredo@example.com\r\n"
            . "ATTENDEE:mailto:bernard@example.com\r\nEND:VEVENT\r\n"
            . "END:VCALENDAR\r\n",
        );

        self::assertNotNull($object);
        self::assertNull(SchedulingObject::in($object, self::OWNER));
    }

    /**
     * **But an attendee in any component counts**, §3.1 asking only that "at
     * least one of the 'ATTENDEE' iCalendar property values matches" — so a
     * sharee invited to one instance of a series is an attendee of the
     * resource.
     */
    public function testAnAttendeeInAnyComponentCounts(): void
    {
        $object = CalendarObject::in(
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dav.services//test//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261101T100000Z\r\n"
            . 'ORGANIZER:' . self::SOMEBODY_ELSE . "\r\nATTENDEE:mailto:wilfredo@example.com\r\n"
            . "END:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nRECURRENCE-ID:20261102T100000Z\r\nDTSTAMP:20261101T090000Z\r\n"
            . "DTSTART:20261102T110000Z\r\nORGANIZER:" . self::SOMEBODY_ELSE . "\r\n"
            . "ATTENDEE:urn:uuid:bernard\r\nEND:VEVENT\r\n"
            . "END:VCALENDAR\r\n",
        );

        self::assertNotNull($object);
        self::assertSame(SchedulingObject::Attendees, SchedulingObject::in($object, self::OWNER));
    }

    /**
     * **The organizer may be an attendee as well, and it is still theirs.**
     * §3.2.1: "The 'Organizer' of a calendar component can also be an
     * 'Attendee' of that calendar component. In such cases, the server MUST
     * NOT send a scheduling message to the 'Attendee' that matches the
     * 'Organizer'."
     *
     * So the first sentence is asked first: a resource that satisfies it is
     * the organizer's, whatever the attendee list says.
     */
    public function testTheOrganizerBeingAnAttendeeTooIsStillTheirs(): void
    {
        self::assertSame(
            SchedulingObject::Organizers,
            $this->kindOf(
                'ORGANIZER:mailto:bernard@example.com' . "\r\n"
                . 'ATTENDEE:mailto:bernard@example.com' . "\r\n"
                . 'ATTENDEE:' . self::SOMEBODY_ELSE,
            ),
        );
    }

    /**
     * **The time zones do not count.** §3.1 says "all the calendar
     * components", and a `VTIMEZONE` can carry no `ORGANIZER` at all — RFC
     * 5545 §3.6.5 lists what it may hold and that is not among them — so
     * reading the sentence to include one would leave nothing in the world a
     * scheduling object resource.
     *
     * The same reading {@see CalendarObject::kindsIn()} already makes of
     * §5.2.3, for a different purpose.
     */
    public function testTheTimeZonesDoNotCount(): void
    {
        $object = CalendarObject::in(
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dav.services//test//EN\r\n"
            . "BEGIN:VTIMEZONE\r\nTZID:Europe/Berlin\r\n"
            . "BEGIN:STANDARD\r\nDTSTART:19701025T030000\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\n"
            . "TZNAME:CET\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART;TZID=Europe/Berlin:20261101T100000\r\n"
            . "ORGANIZER:mailto:bernard@example.com\r\nEND:VEVENT\r\n"
            . "END:VCALENDAR\r\n",
        );

        self::assertNotNull($object);
        self::assertSame(SchedulingObject::Organizers, SchedulingObject::in($object, self::OWNER));
    }

    /**
     * **An object with nothing in it is neither**, which is not the same
     * answer as "every component carries one": there is no `ORGANIZER`, so the
     * property is not "present", and a sentence read as vacuously true would
     * make an empty calendar the owner's scheduling object.
     */
    public function testAnObjectWithNoComponentsIsNeither(): void
    {
        $object = CalendarObject::in(
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dav.services//test//EN\r\nEND:VCALENDAR\r\n",
        );

        self::assertNotNull($object);
        self::assertNull(SchedulingObject::in($object, self::OWNER));
    }

    /**
     * **And one whose only component is a time zone is neither**, for the same
     * reason read the other way round: there is nothing left to carry an
     * `ORGANIZER`.
     */
    public function testAnObjectThatIsNothingButATimeZoneIsNeither(): void
    {
        $object = CalendarObject::in(
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dav.services//test//EN\r\n"
            . "BEGIN:VTIMEZONE\r\nTZID:Europe/Berlin\r\n"
            . "BEGIN:STANDARD\r\nDTSTART:19701025T030000\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\n"
            . "TZNAME:CET\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\nEND:VCALENDAR\r\n",
        );

        self::assertNotNull($object);
        self::assertNull(SchedulingObject::in($object, self::OWNER));
    }

    /**
     * **The comparison is case-sensitive**, RFC 5545 §3.1 saying that "all
     * other property values are case-sensitive, unless otherwise stated" and
     * neither memo stating otherwise for a `CAL-ADDRESS`.
     *
     * So an address that differs only in case is a different address: this
     * resource is not the owner's, and with nobody of theirs attending it is
     * nobody's.
     */
    public function testAnAddressThatDiffersOnlyInCaseIsADifferentAddress(): void
    {
        self::assertNull($this->kindOf('ORGANIZER:MAILTO:Bernard@Example.com'));
    }

    /**
     * **A calendar user with no addresses owns nothing**, which is §2.4.1's
     * own sentence about the property this list comes from: "If not present,
     * then the associated calendar user is not enabled for scheduling on the
     * server."
     */
    public function testAnOwnerWithNoAddressesOwnsNoSchedulingObject(): void
    {
        $object = CalendarObject::in(
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dav.services//test//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261101T100000Z\r\n"
            . "ORGANIZER:mailto:bernard@example.com\r\nATTENDEE:mailto:bernard@example.com\r\n"
            . "END:VEVENT\r\nEND:VCALENDAR\r\n",
        );

        self::assertNotNull($object);
        self::assertNull(SchedulingObject::in($object, []));
    }

    /**
     * What §3.1 makes of a one-component object carrying these properties.
     */
    private function kindOf(string $properties): ?SchedulingObject
    {
        $object = CalendarObject::in(
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dav.services//test//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261101T100000Z\r\n"
            . $properties . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
        );

        self::assertInstanceOf(Component::class, $object);

        return SchedulingObject::in($object, self::OWNER);
    }
}
