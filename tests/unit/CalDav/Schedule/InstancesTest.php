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
use DavServices\CalDav\Schedule\Instances;
use DavServices\VObject\Component;
use DavServices\VObject\Property;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for the pairing both comparisons are made of. R-CAL-32, P8-02g.
 *
 * RFC 6638 asks for the same comparison twice, once of each side:
 *
 * > When an "Organizer" modifies a scheduling object resource, the server MUST
 * > inspect each "ATTENDEE" property in both the original and modified
 * > iCalendar data **on a per-instance basis** to determine whether to send a
 * > scheduling message. (§3.2.1.2)
 *
 * > The server will inspect the changes by comparing the new scheduling object
 * > resource with the existing scheduling object resource. If the "Attendee"
 * > changes one or more "PARTSTAT" iCalendar property values **on any
 * > component**, or **adds an overridden component** with a changed "PARTSTAT"
 * > property, then the server MUST deliver an iTIP "REPLY" […] (§3.2.2.3)
 *
 * Both are the same question asked of two revisions of one resource: which
 * instances does it have, and what governed each of them before and after. The
 * answers differ — one looks at `SCHEDULE-AGENT`, the other at `PARTSTAT` — so
 * what they share is the pairing and nothing else.
 *
 * **What an instance is here**: the master component, and each component
 * carrying a `RECURRENCE-ID`. One that exists only because of an `RRULE`
 * carries whatever the master says, so the master's row speaks for it.
 */
#[CoversClass(Instances::class)]
final class InstancesTest extends TestCase
{
    private const INSTANCE = '20261102T100000Z';

    /**
     * **The master comes first, and nothing names it.**
     *
     * Which is what the two callers need of it: RFC 5546 §3.2.5 describes the
     * complete range of a recurring event as the message where "a
     * 'RECURRENCE-ID' MUST NOT be specified", and §3.2.3's constraints say the
     * same of a `REPLY` about the series — `RECURRENCE-ID | 0 or 1 | Only if
     * referring to an instance of a recurring calendar component. Otherwise, it
     * MUST NOT be present.`
     */
    public function testTheMasterIsTheFirstRowAndIsNamedByNothing(): void
    {
        $object = $this->anObject($this->anEvent('SUMMARY:Lunch'));

        $paired = Instances::between($object, $object);

        self::assertCount(1, $paired);
        self::assertSame('Lunch', $this->theRow($paired, 0)[0]?->property('SUMMARY')?->value());
        self::assertSame('Lunch', $this->theRow($paired, 0)[1]?->property('SUMMARY')?->value());
        self::assertNull($this->theRow($paired, 0)[2]);
    }

    /**
     * **A creation has nothing on the side it is compared against**, and that
     * is the one state the pairing has to be able to describe: §3.2.1.2's
     * `<Absent>` row is §3.2.1.1's whole table, and §3.2.2.2 is §3.2.2.3's
     * with nothing before it.
     */
    public function testACreationPairsTheMasterWithNothing(): void
    {
        $paired = Instances::between(null, $this->anObject($this->anEvent('SUMMARY:Lunch')));

        self::assertCount(1, $paired);
        self::assertNull($this->theRow($paired, 0)[0]);
        self::assertSame('Lunch', $this->theRow($paired, 0)[1]?->property('SUMMARY')?->value());
    }

    /**
     * **An overridden instance gets a row of its own, named by its
     * `RECURRENCE-ID`.**
     */
    public function testAnOverriddenInstanceGetsARowOfItsOwn(): void
    {
        $object = $this->anObject($this->anEvent(''), $this->anInstance('SUMMARY:Dinner'));

        $paired = Instances::between($object, $object);

        self::assertCount(2, $paired);
        self::assertNull($this->theRow($paired, 0)[2]);
        self::assertSame(self::INSTANCE, $this->theRow($paired, 1)[2]?->value());
        self::assertSame('Dinner', $this->theRow($paired, 1)[1]?->property('SUMMARY')?->value());
    }

    /**
     * **The master stands in for the side that does not name the instance**,
     * and that fallback is what makes this a comparison of instances rather
     * than of components. An override the modification adds is compared
     * against what governed that instance before, which is the master — which
     * is §3.2.2.3's "adds an overridden component with a changed 'PARTSTAT'
     * property" in so many words.
     */
    public function testAnInstanceSplitOffIsComparedAgainstTheMaster(): void
    {
        $paired = Instances::between(
            $this->anObject($this->anEvent('SUMMARY:Lunch')),
            $this->anObject($this->anEvent('SUMMARY:Lunch'), $this->anInstance('SUMMARY:Dinner')),
        );

        self::assertCount(2, $paired);
        self::assertSame('Lunch', $this->theRow($paired, 1)[0]?->property('SUMMARY')?->value());
        self::assertSame('Dinner', $this->theRow($paired, 1)[1]?->property('SUMMARY')?->value());
    }

    /**
     * **And the other way round**: an override the modification drops does not
     * take its instance with it. The `RRULE` still generates it and the master
     * governs it again.
     */
    public function testAnInstanceWhoseOverrideIsDroppedFallsBackToTheMaster(): void
    {
        $paired = Instances::between(
            $this->anObject($this->anEvent('SUMMARY:Lunch'), $this->anInstance('SUMMARY:Dinner')),
            $this->anObject($this->anEvent('SUMMARY:Lunch')),
        );

        self::assertCount(2, $paired);
        self::assertSame('Dinner', $this->theRow($paired, 1)[0]?->property('SUMMARY')?->value());
        self::assertSame('Lunch', $this->theRow($paired, 1)[1]?->property('SUMMARY')?->value());
    }

    /**
     * **The name comes from the original where both sides have one**, because
     * the messages built from this are built about the instance as it stood.
     * RFC 5545 §3.8.4.4 lets a `RECURRENCE-ID` carry a `RANGE` that widens
     * what it refers to — "THISANDFUTURE" — so which of the two properties
     * travels is a question the pairing has to answer rather than leave open.
     */
    public function testTheNameIsTheOriginalsWhereBothSidesNameIt(): void
    {
        $paired = Instances::between(
            $this->anObject(
                $this->anEvent(''),
                $this->anInstanceNamed('RECURRENCE-ID;RANGE=THISANDFUTURE:' . self::INSTANCE, ''),
            ),
            $this->anObject($this->anEvent(''), $this->anInstance('')),
        );

        self::assertCount(2, $paired);
        self::assertSame('THISANDFUTURE', $this->theRow($paired, 1)[2]?->parameter('RANGE')?->value());
    }

    /**
     * **The master is found behind an instance that comes first.** RFC 5545
     * §3.5: "This memo imposes no ordering of properties within an iCalendar
     * object", and §3.6 none of the components either, so the master is
     * whichever component carries no `RECURRENCE-ID` and not whichever comes
     * first.
     */
    public function testTheMasterIsFoundBehindAnInstanceThatComesFirst(): void
    {
        $object = $this->anObject($this->anInstance('SUMMARY:Dinner'), $this->anEvent('SUMMARY:Lunch'));

        $paired = Instances::between($object, $object);

        self::assertCount(2, $paired);
        self::assertSame('Lunch', $this->theRow($paired, 0)[1]?->property('SUMMARY')?->value());
    }

    /**
     * **A time zone is no instance.** RFC 5545 §3.6.5 lists what a `VTIMEZONE`
     * may hold, and neither `RECURRENCE-ID` nor `ATTENDEE` is among them; a
     * zone read as the master would make the event's own component an instance
     * of it.
     */
    public function testATimeZoneIsNoInstance(): void
    {
        $object = $this->anObject(
            "BEGIN:VTIMEZONE\r\nTZID:Europe/Berlin\r\nEND:VTIMEZONE\r\n",
            $this->anEvent('SUMMARY:Lunch'),
        );

        $paired = Instances::between($object, $object);

        self::assertCount(1, $paired);
        self::assertSame('Lunch', $this->theRow($paired, 0)[1]?->property('SUMMARY')?->value());
    }

    /**
     * **An object naming no master is still a list of its instances.** Nothing
     * in RFC 5545 makes the master REQUIRED in a stored resource — RFC 4791
     * §4.1 asks only that every component share one `UID` — and a client that
     * sends the overridden instances alone is describing instances all the
     * same.
     */
    public function testAnObjectOfInstancesAloneHasNoMasterRow(): void
    {
        $object = $this->anObject($this->anInstance('SUMMARY:Dinner'));

        $paired = Instances::between($object, $object);

        self::assertCount(2, $paired);
        self::assertNull($this->theRow($paired, 0)[0]);
        self::assertNull($this->theRow($paired, 0)[1]);
        self::assertSame('Dinner', $this->theRow($paired, 1)[1]?->property('SUMMARY')?->value());
    }

    /**
     * **Two instances are two rows, in the order the original names them.**
     */
    public function testEachInstanceIsItsOwnRow(): void
    {
        $object = $this->anObject(
            $this->anEvent(''),
            $this->anInstance('', '20261103T100000Z'),
            $this->anInstance('', self::INSTANCE),
        );

        $paired = Instances::between($object, $object);

        self::assertSame(
            [null, '20261103T100000Z', self::INSTANCE],
            array_map(static fn (array $row): ?string => $row[2]?->value(), $paired),
        );
    }

    /**
     * **An instance only the modification names comes after the ones the
     * original does**, the original being the side the names are taken from.
     */
    public function testAnInstanceOnlyTheModificationNamesComesLast(): void
    {
        $paired = Instances::between(
            $this->anObject($this->anEvent(''), $this->anInstance('', self::INSTANCE)),
            $this->anObject(
                $this->anEvent(''),
                $this->anInstance('', '20261103T100000Z'),
                $this->anInstance('', self::INSTANCE),
            ),
        );

        self::assertSame(
            [null, self::INSTANCE, '20261103T100000Z'],
            array_map(static fn (array $row): ?string => $row[2]?->value(), $paired),
        );
    }

    /**
     * **The components an instance can be are every one but the zones**, in
     * the order the object names them. Asked for in its own right, because
     * both comparisons need the same list for the properties they stamp and
     * read as for the pairing itself.
     */
    public function testTheComponentsOfAnObjectLeaveTheZonesOut(): void
    {
        $components = Instances::of($this->anObject(
            $this->anInstance('SUMMARY:Dinner'),
            "BEGIN:VTIMEZONE\r\nTZID:Europe/Berlin\r\nEND:VTIMEZONE\r\n",
            $this->anEvent('SUMMARY:Lunch'),
        ));

        self::assertSame(
            ['Dinner', 'Lunch'],
            array_map(static fn (Component $one): ?string => $one->property('SUMMARY')?->value(), $components),
        );
    }

    /**
     * **And none at all where there is no object**, which is the creation
     * again: the side a comparison has nothing on.
     */
    public function testTheComponentsOfNothingAreNone(): void
    {
        self::assertSame([], Instances::of(null));
    }

    /**
     * One row of the pairing, which the case expects to be there.
     *
     * @param list<array{?Component, ?Component, ?Property}> $paired
     *
     * @return array{?Component, ?Component, ?Property}
     */
    private function theRow(array $paired, int $which): array
    {
        return $paired[$which] ?? self::fail(sprintf('No row %d.', $which));
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
     * The master component, carrying those properties.
     */
    private function anEvent(string $properties): string
    {
        return "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261101T100000Z\r\n"
            . "RRULE:FREQ=DAILY;COUNT=3\r\nORGANIZER:mailto:bernard@example.com\r\n"
            . ($properties === '' ? '' : $properties . "\r\n")
            . "END:VEVENT\r\n";
    }

    /**
     * An overridden instance, carrying those properties.
     */
    private function anInstance(string $properties, string $id = self::INSTANCE): string
    {
        return $this->anInstanceNamed('RECURRENCE-ID:' . $id, $properties);
    }

    /**
     * The same, with the `RECURRENCE-ID` written out for the cases where the
     * way the instance is named is the case.
     */
    private function anInstanceNamed(string $id, string $properties): string
    {
        return "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261102T110000Z\r\n"
            . $id . "\r\nORGANIZER:mailto:bernard@example.com\r\n"
            . ($properties === '' ? '' : $properties . "\r\n")
            . "END:VEVENT\r\n";
    }
}
