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
use DavServices\CalDav\Schedule\Restriction;
use DavServices\VObject\Component;
use DavServices\VObject\Serializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for what one calendar user may be told about a series (RFC 6638
 * §3.2.6). R-CAL-32, P8-02i.
 *
 * > Servers MUST ensure that "Attendees" only get information about recurrence
 * > instances that explicitly include them as an "Attendee", when delivering
 * > scheduling messages for recurring calendar components.
 *
 * The section then works both of its cases out, and the two together are this
 * whole test list.
 *
 * ## Invited to one instance: that instance, and nothing else
 *
 * > For example, if an "Attendee" is invited to only a single instance of a
 * > recurring event, the organizer scheduling object resource will contain an
 * > overridden instance in the form of a separate calendar component. That
 * > separate calendar component will include the "ATTENDEE" property
 * > referencing the "one-off" "Attendee". That "Attendee" will not be listed
 * > in any other calendar components in the scheduling object resource. Any
 * > scheduling messages delivered to the "Attendee" will only contain
 * > information about this overridden instance.
 *
 * ## Excluded from one instance: the master, with an `EXDATE`
 *
 * > As another example, an "Attendee" could be excluded from one instance of a
 * > recurring event. In that case, the organizer scheduling object resource
 * > will include an overridden instance with an "ATTENDEE" list that does not
 * > include the "Attendee" being excluded. Any scheduling messages delivered
 * > to the "Attendee" will not specify the overridden instance but rather will
 * > include an "EXDATE" property in the "master" component that defines the
 * > recurrence set.
 *
 * **The `EXDATE` is the half that cannot be left out.** Dropping the
 * overridden component alone would not keep the instance from the attendee:
 * the `RRULE` in the master generates it again, and they would be invited to
 * the very instance the organizer took them off.
 *
 * ## What this is not about
 *
 * **Who else is coming.** §3.2.6 restricts the *instances*, and RFC 5546
 * §3.2.2 has a `REQUEST` carry the attendee list of the components it carries:
 * `ATTENDEE | 1+`. An invitation that hid the other guests would be a
 * different message from the one the organizer wrote.
 */
#[CoversClass(Restriction::class)]
final class RestrictionTest extends TestCase
{
    private const CYRUS = 'mailto:cyrus@example.com';

    private const ERIC = 'mailto:eric@example.com';

    private const INSTANCE = '20261103T100000Z';

    private const ORGANISED = 'ORGANIZER:mailto:bernard@example.com';

    /**
     * **Every instance that names them travels, and the object around it.**
     * RFC 5545 §3.6 makes `PRODID` and `VERSION` REQUIRED in a `VCALENDAR`, so
     * what comes back is a calendar object and not a heap of components.
     */
    public function testEveryInstanceThatNamesThemTravels(): void
    {
        $object = $this->anObject(
            $this->aSeries('ATTENDEE;PARTSTAT=NEEDS-ACTION:' . self::CYRUS),
            $this->anInstance('ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS),
        );

        self::assertSame(
            "BEGIN:VCALENDAR\r\n"
            . "VERSION:2.0\r\n"
            . "PRODID:-//dav.services//test//EN\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:1\r\n"
            . "DTSTAMP:20261101T090000Z\r\n"
            . "DTSTART:20261101T100000Z\r\n"
            . "RRULE:FREQ=DAILY;COUNT=5\r\n"
            . "ORGANIZER:mailto:bernard@example.com\r\n"
            . "ATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:cyrus@example.com\r\n"
            . "END:VEVENT\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:1\r\n"
            . "DTSTAMP:20261101T090000Z\r\n"
            . "DTSTART:20261103T100000Z\r\n"
            . "RECURRENCE-ID:20261103T100000Z\r\n"
            . "ORGANIZER:mailto:bernard@example.com\r\n"
            . "ATTENDEE;PARTSTAT=ACCEPTED:mailto:cyrus@example.com\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n",
            self::text(Restriction::whatTheyMayBeTold($object, self::CYRUS)),
        );
    }

    /**
     * **An instance that does not name them is left out, and the master leaves
     * it out too.** §3.2.6's second example, both halves of it: the overridden
     * component does not travel, and the `EXDATE` keeps the `RRULE` from
     * putting the instance back.
     */
    public function testAnInstanceThatDoesNotNameThemIsLeftOutAndExcluded(): void
    {
        $object = $this->anObject(
            $this->aSeries('ATTENDEE;PARTSTAT=NEEDS-ACTION:' . self::CYRUS),
            $this->anInstance('ATTENDEE;PARTSTAT=ACCEPTED:' . self::ERIC),
        );
        $text = self::text(Restriction::whatTheyMayBeTold($object, self::CYRUS));

        self::assertSame(1, substr_count($text, 'BEGIN:VEVENT'));
        self::assertStringNotContainsString('RECURRENCE-ID', $text);
        self::assertStringNotContainsString(self::ERIC, $text);
        self::assertStringContainsString("EXDATE:20261103T100000Z\r\n", $text);
    }

    /**
     * **And the exclusion names the same moment the identifier did.** RFC 5545
     * §3.8.4.4 makes a `RECURRENCE-ID` mean one moment only together with its
     * `TZID`, and §3.8.5.1 asks the same of an `EXDATE`: its values are "the
     * start times of the instances" left out. So the parameters travel with the
     * value rather than the value alone.
     */
    public function testTheExclusionNamesTheSameMomentTheIdentifierDid(): void
    {
        $object = $this->anObject(
            $this->aSeries('ATTENDEE:' . self::CYRUS),
            "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART;TZID=Europe/Berlin:20261103T110000\r\n"
            . "RECURRENCE-ID;TZID=Europe/Berlin:20261103T100000\r\n"
            . self::ORGANISED . "\r\nATTENDEE:" . self::ERIC . "\r\nEND:VEVENT\r\n",
        );

        self::assertStringContainsString(
            "EXDATE;TZID=Europe/Berlin:20261103T100000\r\n",
            self::text(Restriction::whatTheyMayBeTold($object, self::CYRUS)),
        );
    }

    /**
     * **A `RANGE` stays behind, because an `EXDATE` cannot carry one.** RFC
     * 5545 §3.2.13 gives the parameter to one property only — "This parameter
     * can be specified on a property that specifies a recurrence identifier" —
     * and §3.8.5.1's `exdtval` has nowhere to put it.
     *
     * **So what is excluded is the one instance the identifier names.** An
     * override with `RANGE=THISANDFUTURE` governs the instances after it as
     * well, and keeping those from the attendee would mean expanding the
     * recurrence set to name each one: §3.8.4.4 has subsequent instances
     * "determined by their 'RECURRENCE-ID' value", and in the same breath that
     * ones "defined in separate components are not impacted". That is a reading
     * of the range rather than of the components, and §3.2.6 describes
     * components.
     */
    public function testTheExclusionLeavesTheRangeBehind(): void
    {
        $object = $this->anObject(
            $this->aSeries('ATTENDEE:' . self::CYRUS),
            "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261103T110000Z\r\n"
            . 'RECURRENCE-ID;RANGE=THISANDFUTURE:' . self::INSTANCE . "\r\n"
            . self::ORGANISED . "\r\nATTENDEE:" . self::ERIC . "\r\nEND:VEVENT\r\n",
        );
        $text = self::text(Restriction::whatTheyMayBeTold($object, self::CYRUS));

        self::assertStringContainsString("EXDATE:20261103T100000Z\r\n", $text);
        self::assertStringNotContainsString('RANGE', $text);
    }

    /**
     * **An attendee invited to one instance is told about that instance**, and
     * §3.2.6's first example is the whole of it: "Any scheduling messages
     * delivered to the 'Attendee' will only contain information about this
     * overridden instance."
     *
     * No master travels, so there is no recurrence set to exclude anything
     * from — and nothing to exclude, the instances they are not on being the
     * ones the master would have generated.
     */
    public function testAnAttendeeInvitedToOneInstanceIsToldAboutThatInstance(): void
    {
        $object = $this->anObject(
            $this->aSeries('ATTENDEE:' . self::ERIC),
            $this->anInstance('ATTENDEE:' . self::ERIC . "\r\nATTENDEE:" . self::CYRUS),
        );
        $text = self::text(Restriction::whatTheyMayBeTold($object, self::CYRUS));

        self::assertSame(1, substr_count($text, 'BEGIN:VEVENT'));
        self::assertStringContainsString("RECURRENCE-ID:20261103T100000Z\r\n", $text);
        self::assertStringNotContainsString('RRULE', $text);
        self::assertStringNotContainsString('EXDATE', $text);
    }

    /**
     * **The zones travel.** RFC 5546 §3.2.2 has `VTIMEZONE | 0+ | MUST be
     * present if any date/time refers to a timezone`, and RFC 5545 §3.6.5
     * makes a `TZID` "a reference to the unique time zone identifier" of a
     * component in the same object: a message that left them behind would name
     * times nobody could resolve.
     */
    public function testTheZonesTravel(): void
    {
        $object = $this->anObject(
            "BEGIN:VTIMEZONE\r\nTZID:Europe/Berlin\r\nEND:VTIMEZONE\r\n",
            $this->aSeries('ATTENDEE:' . self::CYRUS),
            "BEGIN:VTIMEZONE\r\nTZID:America/New_York\r\nEND:VTIMEZONE\r\n",
        );
        $text = self::text(Restriction::whatTheyMayBeTold($object, self::CYRUS));

        self::assertStringContainsString("TZID:Europe/Berlin\r\n", $text);
        self::assertStringContainsString("TZID:America/New_York\r\n", $text);
    }

    /**
     * **Nothing of the resource is shared with what comes back.** The
     * organizer's own resource is about to be stored, and the `EXDATE` this
     * writes belongs to one calendar user's message: a master handed over
     * rather than copied would have the organizer excluding an instance from
     * their own event.
     */
    public function testNothingOfTheResourceIsSharedWithIt(): void
    {
        $object = $this->anObject(
            $this->aSeries('ATTENDEE:' . self::CYRUS),
            $this->anInstance('ATTENDEE:' . self::ERIC),
        );
        $told = Restriction::whatTheyMayBeTold($object, self::CYRUS);

        $told->component('VEVENT')?->property('ATTENDEE')?->setValue('mailto:nobody@example.net');

        $text = self::text($object);

        self::assertStringNotContainsString('EXDATE', $text);
        self::assertStringNotContainsString('nobody@example.net', $text);
    }

    /**
     * **An attendee of an alarm is on no instance.** RFC 5545 §3.6.6 makes
     * those "the attendees… to be sent an email", so a reminder the organizer
     * set is nobody's invitation — the same reading
     * {@see \DavServices\CalDav\Schedule\Instances} makes wherever it takes an
     * object apart.
     */
    public function testAnAttendeeOfAnAlarmIsOnNoInstance(): void
    {
        $object = $this->anObject(
            $this->aSeries('ATTENDEE:' . self::CYRUS),
            "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261103T110000Z\r\n"
            . 'RECURRENCE-ID:' . self::INSTANCE . "\r\n" . self::ORGANISED . "\r\nATTENDEE:" . self::ERIC . "\r\n"
            . "BEGIN:VALARM\r\nACTION:EMAIL\r\nATTENDEE:" . self::CYRUS . "\r\nEND:VALARM\r\n"
            . "END:VEVENT\r\n",
        );
        $text = self::text(Restriction::whatTheyMayBeTold($object, self::CYRUS));

        self::assertStringContainsString("EXDATE:20261103T100000Z\r\n", $text);
        self::assertStringNotContainsString('VALARM', $text);
    }

    /**
     * **An address is matched as it is written.** RFC 5545 §3.1 makes a
     * property value case-sensitive, and whether two spellings name one
     * calendar user is a question only a directory can answer — the same
     * reading {@see \DavServices\CalDav\Schedule\SchedulingObject} makes.
     */
    public function testAnAddressIsMatchedAsItIsWritten(): void
    {
        $object = $this->anObject($this->aSeries('ATTENDEE:' . self::CYRUS));

        self::assertStringNotContainsString(
            'BEGIN:VEVENT',
            self::text(Restriction::whatTheyMayBeTold($object, 'MAILTO:CYRUS@EXAMPLE.COM')),
        );
    }

    /**
     * **And a calendar user the object does not name is told about no
     * instance at all**, which is the same answer read the other way: there is
     * no component that "explicitly include[s] them as an 'Attendee'".
     *
     * Nothing asks this of a calendar user who is on nothing — both callers
     * take the address off the resource itself — and what comes back says so
     * rather than guessing at an answer.
     */
    public function testACalendarUserTheObjectDoesNotNameIsToldAboutNoInstance(): void
    {
        $object = $this->anObject($this->aSeries('ATTENDEE:' . self::CYRUS));

        self::assertSame(
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dav.services//test//EN\r\nEND:VCALENDAR\r\n",
            self::text(Restriction::whatTheyMayBeTold($object, 'mailto:nobody@example.net')),
        );
    }

    /**
     * **An object with no master excludes nothing**, there being no recurrence
     * set to exclude an instance from: RFC 4791 §4.1 gives that job to the
     * component "that defines the recurrence 'set'", and a resource may hold
     * overridden instances alone.
     */
    public function testAnObjectWithNoMasterExcludesNothing(): void
    {
        $object = $this->anObject(
            $this->anInstance('ATTENDEE:' . self::CYRUS),
            $this->anInstance('ATTENDEE:' . self::ERIC, '20261104T100000Z'),
        );
        $text = self::text(Restriction::whatTheyMayBeTold($object, self::CYRUS));

        self::assertSame(1, substr_count($text, 'BEGIN:VEVENT'));
        self::assertStringContainsString("RECURRENCE-ID:20261103T100000Z\r\n", $text);
        self::assertStringNotContainsString('EXDATE', $text);
    }

    /**
     * **An exclusion joins the ones already there.** RFC 5545 §3.8.5.1 puts
     * `EXDATE` at "0+" in a component, and what the organizer excluded from
     * the series is as true for this attendee as for anybody: both
     * exclusions travel, each as the property it is.
     */
    public function testAnExclusionJoinsTheOnesAlreadyThere(): void
    {
        $object = $this->anObject(
            $this->aSeries('ATTENDEE:' . self::CYRUS . "\r\nEXDATE:20261102T100000Z"),
            $this->anInstance('ATTENDEE:' . self::ERIC),
        );
        $text = self::text(Restriction::whatTheyMayBeTold($object, self::CYRUS));

        self::assertStringContainsString("EXDATE:20261102T100000Z\r\n", $text);
        self::assertStringContainsString("EXDATE:20261103T100000Z\r\n", $text);
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
     * The master of a daily series of five, carrying those properties.
     */
    private function aSeries(string $properties): string
    {
        return "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261101T100000Z\r\n"
            . "RRULE:FREQ=DAILY;COUNT=5\r\n" . self::ORGANISED . "\r\n"
            . $properties . "\r\nEND:VEVENT\r\n";
    }

    /**
     * A component overriding one instance of it.
     */
    private function anInstance(string $properties, string $id = self::INSTANCE): string
    {
        return "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:" . $id . "\r\n"
            . 'RECURRENCE-ID:' . $id . "\r\n" . self::ORGANISED . "\r\n"
            . $properties . "\r\nEND:VEVENT\r\n";
    }

    /**
     * What that object says now.
     */
    private static function text(Component $object): string
    {
        return (new Serializer())->write($object);
    }
}
