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

namespace DavServices\Tests\Unit\VObject\Recur;

use DavServices\VObject\Component;
use DavServices\VObject\Parameter;
use DavServices\VObject\Property;
use DavServices\VObject\Reader;
use DavServices\VObject\Recur\Override;
use DavServices\VObject\Serializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for the component that overrides one instance of a series
 * (RFC 5545 §3.8.4.4, §3.8.5.3). R-RRULE-03, P8-02h.
 *
 * RFC 4791 §4.1 gives the two words: the **master** "defines the recurrence
 * 'set' and does not contain any RECURRENCE-ID property", and an **overridden**
 * instance "modif[ies] the behavior of a regular instance, and thus include[s]
 * a RECURRENCE-ID property". This is how the second is made out of the first,
 * for an instance nothing has overridden yet.
 *
 * ## What the instance is, before anything about it is changed
 *
 * It is the master, moved. §3.8.4.4 says where to: "The property value is the
 * original value of the 'DTSTART' property of the recurrence instance" — so
 * the identifier *is* the start of the instance it names, as long as nothing
 * has moved it, and nothing has: a component that overrode it would be the one
 * to say so, and there is none.
 *
 * And §3.8.5.3 says how long it lasts:
 *
 * > If the duration of the recurring component is specified with the "DTEND"
 * > or "DUE" property, then the computed end time or due time of each instance
 * > is the same **nominal** duration as the initial instance.
 *
 * **Nominal, so the arithmetic is wall clock** — the same frame §3.3.10 counts
 * a recurrence in. An instance an hour long is an hour long on the day the
 * clocks go forward too, and a `DURATION` has nothing to move at all.
 *
 * ## The recurrence properties stay with the series
 *
 * One instance is not a series of its own, and §3.8.4.4 has the overriding
 * component modify "the behavior of a regular instance" rather than define a
 * set. `RRULE`, `RDATE` and `EXDATE` belong to the master that generated the
 * instance — and `EXRULE` with them, withdrawn in §3.8.5.2 of RFC 5545 though
 * an object written years ago may carry one.
 *
 * ## Nothing is shared with the master
 *
 * The component that comes back is the caller's to change — that is what it is
 * for — and a {@see Property} is mutable in its parameters. So every property
 * is built again rather than handed over, or writing a `PARTSTAT` on the
 * instance would write it on the series.
 */
#[CoversClass(Override::class)]
final class OverrideTest extends TestCase
{
    private const INSTANCE = '20261103T100000Z';

    /**
     * **The instance is the master, at its own start and under its own
     * identifier.** §3.8.4.4's value is "the original value of the 'DTSTART'
     * property of the recurrence instance", so that value is where this
     * instance begins; §3.8.5.3 keeps the length; and the rule that generated
     * it stays with the series.
     */
    public function testTheMasterTravelsToTheInstance(): void
    {
        self::assertSame(
            "BEGIN:VEVENT\r\n"
            . "UID:1\r\n"
            . "DTSTAMP:20261101T090000Z\r\n"
            . "DTSTART:20261103T100000Z\r\n"
            . "DTEND:20261103T110000Z\r\n"
            . "SUMMARY:Lunch\r\n"
            . "ORGANIZER:mailto:bernard@example.com\r\n"
            . "ATTENDEE;PARTSTAT=ACCEPTED:mailto:cyrus@example.com\r\n"
            . "RECURRENCE-ID:20261103T100000Z\r\n"
            . "END:VEVENT\r\n",
            $this->overriding(
                "DTSTART:20261101T100000Z\r\nDTEND:20261101T110000Z\r\n"
                . "RRULE:FREQ=DAILY;COUNT=5\r\nSUMMARY:Lunch\r\n"
                . "ORGANIZER:mailto:bernard@example.com\r\n"
                . "ATTENDEE;PARTSTAT=ACCEPTED:mailto:cyrus@example.com\r\n",
            ),
        );
    }

    /**
     * **All four recurrence properties stay behind**, and the names are
     * compared without regard to case: RFC 5545 §3.1 makes a property name
     * case-insensitive, so a client's `rrule` is the same property as
     * §3.8.5.3's.
     */
    public function testEveryRecurrencePropertyStaysWithTheSeries(): void
    {
        self::assertSame(
            "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261103T100000Z\r\n"
            . "SUMMARY:Lunch\r\nRECURRENCE-ID:20261103T100000Z\r\nEND:VEVENT\r\n",
            $this->overriding(
                "DTSTART:20261101T100000Z\r\nrrule:FREQ=DAILY;COUNT=5\r\n"
                . "RDATE:20261110T100000Z\r\nEXDATE:20261104T100000Z\r\n"
                . "EXRULE:FREQ=WEEKLY\r\nSUMMARY:Lunch\r\n",
            ),
        );
    }

    /**
     * **An identifier the master already carried is replaced and not added
     * to.** §3.8.4.4 makes `RECURRENCE-ID` the property that says which
     * instance a component is, and a component saying it twice would be two
     * instances: what comes back is the override of the instance that was
     * asked for.
     */
    public function testAnIdentifierAlreadyThereIsReplaced(): void
    {
        self::assertSame(
            "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261103T100000Z\r\n"
            . "RECURRENCE-ID:20261103T100000Z\r\nEND:VEVENT\r\n",
            $this->overriding("DTSTART:20261101T100000Z\r\nRECURRENCE-ID:20261102T100000Z\r\n"),
        );
    }

    /**
     * **The identifier is written under the name §3.8.4.4 gives it**, whatever
     * the caller read the instance's name off. RFC 6638 §3.2.2.3 has an
     * attendee name an instance with an `EXDATE` — "adds an 'EXDATE' property
     * value to effectively remove a recurrence instance" — and RFC 5545
     * §3.8.5.1 makes those values the same starts §3.8.4.4 asks for.
     */
    public function testTheIdentifierIsWrittenUnderItsOwnName(): void
    {
        $written = Override::of(
            $this->theEvent("DTSTART:20261101T100000Z\r\n"),
            new Property('EXDATE', self::INSTANCE),
        );

        self::assertSame(
            "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261103T100000Z\r\n"
            . "RECURRENCE-ID:20261103T100000Z\r\nEND:VEVENT\r\n",
            (new Serializer())->write($written ?? self::fail('No override.')),
        );
    }

    /**
     * **And its parameters travel with it**, a `RECURRENCE-ID` naming one
     * moment only together with its `TZID` (§3.8.4.4) — and `RANGE` with them,
     * §3.2.13 making that parameter part of which instances are meant.
     */
    public function testTheIdentifiersParametersTravelWithIt(): void
    {
        $written = Override::of(
            $this->theEvent("DTSTART;TZID=Europe/Berlin:20261101T100000\r\n"),
            new Property('RECURRENCE-ID', '20261103T100000', [
                new Parameter('TZID', ['Europe/Berlin']),
                new Parameter('RANGE', ['THISANDFUTURE']),
            ]),
        );

        self::assertStringContainsString(
            "RECURRENCE-ID;TZID=Europe/Berlin;RANGE=THISANDFUTURE:20261103T100000\r\n",
            (new Serializer())->write($written ?? self::fail('No override.')),
        );
    }

    /**
     * **The start keeps the master's own parameters**, which is the zone the
     * series is counted in: §3.8.4.4 ties the identifier to "the 'DTSTART'
     * property of the recurrence instance", and §3.2.19's `TZID` is what makes
     * either value a moment. `RANGE` is the identifier's own and belongs on no
     * `DTSTART`, §3.2.13 allowing it on nothing else.
     */
    public function testTheStartKeepsTheMastersParameters(): void
    {
        $written = Override::of(
            $this->theEvent("DTSTART;TZID=Europe/Berlin:20261101T100000\r\nDTEND;TZID=Europe/Berlin:20261101T113000\r\n"),
            new Property('RECURRENCE-ID', '20261103T100000', [
                new Parameter('TZID', ['Europe/Berlin']),
                new Parameter('RANGE', ['THISANDFUTURE']),
            ]),
        );
        $text = (new Serializer())->write($written ?? self::fail('No override.'));

        self::assertStringContainsString("DTSTART;TZID=Europe/Berlin:20261103T100000\r\n", $text);
        self::assertStringContainsString("DTEND;TZID=Europe/Berlin:20261103T113000\r\n", $text);
    }

    /**
     * **A `DURATION` has nothing to move**, §3.8.5.3 asking for the same
     * nominal duration and that property being one already.
     */
    public function testADurationTravelsAsItStands(): void
    {
        self::assertStringContainsString(
            "DURATION:PT1H\r\n",
            $this->overriding("DTSTART:20261101T100000Z\r\nDURATION:PT1H\r\n"),
        );
    }

    /**
     * **A `DUE` moves as a `DTEND` does**, §3.8.5.3 naming the two together:
     * "the computed end time or due time of each instance is the same nominal
     * duration as the initial instance". And the kind of component travels,
     * §3.6.2 making `DUE` a `VTODO`'s.
     */
    public function testADueDateMovesWithTheStart(): void
    {
        self::assertSame(
            "BEGIN:VTODO\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261103T100000Z\r\n"
            . "DUE:20261104T100000Z\r\nRECURRENCE-ID:20261103T100000Z\r\nEND:VTODO\r\n",
            $this->overriding("DTSTART:20261101T100000Z\r\nDUE:20261102T100000Z\r\n", 'VTODO'),
        );
    }

    /**
     * **An all-day instance moves by whole days.** §3.8.4.4: "The value type
     * of this property MUST be the same as the 'DTSTART' property contained
     * within the recurring component", and §3.2.20's `VALUE=DATE` is what says
     * it is one — a value this library reads as a day rather than a moment.
     */
    public function testAnAllDayInstanceMovesByWholeDays(): void
    {
        $written = Override::of(
            $this->theEvent(
                "DTSTART;VALUE=DATE:20261101\r\nDTEND;VALUE=DATE:20261102\r\nRRULE:FREQ=DAILY\r\n",
            ),
            new Property('RECURRENCE-ID', '20261103', [new Parameter('VALUE', ['DATE'])]),
        );
        $text = (new Serializer())->write($written ?? self::fail('No override.'));

        self::assertStringContainsString("DTSTART;VALUE=DATE:20261103\r\n", $text);
        self::assertStringContainsString("DTEND;VALUE=DATE:20261104\r\n", $text);
    }

    /**
     * **A value of the other type is no instance of this series.** §3.8.4.4:
     * "The value type of this property MUST be the same as the 'DTSTART'
     * property contained within the recurring component." A day is not a
     * moment, and a component whose start said one while its identifier said
     * the other would name an instance of nothing.
     *
     * @param string $start What the master says about where it begins
     * @param string $value The identifier's own value
     * @param string|null $type The `VALUE` parameter on the identifier, or
     *                          null for none — which §3.8.4.4 leaves as
     *                          "DATE-TIME", the default value type
     */
    #[DataProvider('theTypesThatDisagree')]
    public function testAnIdentifierOfTheOtherTypeIsNoInstance(string $start, string $value, ?string $type): void
    {
        self::assertNull(Override::of(
            $this->theEvent($start),
            new Property('RECURRENCE-ID', $value, $type === null ? [] : [new Parameter('VALUE', [$type])]),
        ));
    }

    /**
     * Each way round, because either side can be the one that is wrong.
     *
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function theTypesThatDisagree(): iterable
    {
        yield 'a day for a series of moments' => ["DTSTART:20261101T100000Z\r\n", '20261103', 'DATE'];
        yield 'a moment for a series of days' => [
            "DTSTART;VALUE=DATE:20261101\r\n",
            '20261103T100000Z',
            null,
        ];
    }

    /**
     * **A series with no start is one nothing can be an instance of.** RFC
     * 5545 §3.8.5.3: "The recurrence set generated with a 'RRULE' property is
     * the complete set of recurrence instances starting with the 'DTSTART'
     * property value" — so where there is no such value there is no set, and
     * §3.8.4.4 has nothing to tie an identifier to.
     */
    public function testAMasterWithNoStartIsNoSeries(): void
    {
        self::assertNull(Override::of(
            $this->theEvent("SUMMARY:Lunch\r\n"),
            new Property('RECURRENCE-ID', self::INSTANCE),
        ));
    }

    /**
     * **And neither are values nobody can read**, wherever the one that cannot
     * be read stands. All three are what makes the instance: where the series
     * begins, where this instance of it does, and how long it runs.
     *
     * @param string $properties What the master says about its times
     * @param string $value The identifier's value
     */
    #[DataProvider('theValuesNobodyCanRead')]
    public function testAValueNobodyCanReadLeavesNoOverride(string $properties, string $value): void
    {
        self::assertNull(
            Override::of($this->theEvent($properties), new Property('RECURRENCE-ID', $value)),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function theValuesNobodyCanRead(): iterable
    {
        yield 'the start' => ["DTSTART:tomorrow\r\n", self::INSTANCE];
        yield 'the identifier' => ["DTSTART:20261101T100000Z\r\n", 'tomorrow'];
        yield 'the end' => ["DTSTART:20261101T100000Z\r\nDTEND:whenever\r\n", self::INSTANCE];
    }

    /**
     * **The alarms travel**, RFC 5545 §3.6.1 letting an event hold as many as
     * it likes and §3.8.4.4 having the override modify the behaviour of one
     * instance: an instance that lost the reminder the series carries would
     * have been changed in a way nobody asked for.
     */
    public function testTheAlarmsTravel(): void
    {
        self::assertStringContainsString(
            "BEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT15M\r\nEND:VALARM\r\n",
            $this->overriding(
                "DTSTART:20261101T100000Z\r\n"
                . "BEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT15M\r\nEND:VALARM\r\n",
            ),
        );
    }

    /**
     * **And they travel the whole way down.** A component may hold a component
     * that holds another: RFC 9073 §4 adds `*participantc` to a `VEVENT` —
     *
     *     eventc = "BEGIN" ":" "VEVENT" CRLF
     *              eventprop *alarmc *participantc *locationc *resourcec
     *              "END" ":" "VEVENT" CRLF
     *
     * — and §7.1 lets one of those hold the places that participant is at:
     *
     *     participantc = "BEGIN" ":" "PARTICIPANT" CRLF
     *                    partprop *locationc *resourcec
     *                    "END" ":" "PARTICIPANT" CRLF
     *
     * §7.2 says the same from the other side, a `VLOCATION` being one that
     * "can be specified multiple times in a 'VEVENT', 'VTODO', 'VJOURNAL',
     * 'VFREEBUSY', or 'PARTICIPANT' calendar component". So the depth is not a
     * guess about what a parser might hand over: it is in the grammar.
     */
    public function testSubcomponentsTravelTheWholeWayDown(): void
    {
        self::assertStringContainsString(
            "BEGIN:PARTICIPANT\r\nPARTICIPANT-TYPE:ACTIVE\r\n"
            . "BEGIN:VLOCATION\r\nUID:2\r\nNAME:Room 101\r\nEND:VLOCATION\r\n"
            . "END:PARTICIPANT\r\n",
            $this->overriding(
                "DTSTART:20261101T100000Z\r\n"
                . "BEGIN:PARTICIPANT\r\nPARTICIPANT-TYPE:ACTIVE\r\n"
                . "BEGIN:VLOCATION\r\nUID:2\r\nNAME:Room 101\r\nEND:VLOCATION\r\n"
                . "END:PARTICIPANT\r\n",
            ),
        );
    }

    /**
     * **Nothing of the master's is shared with it.** A {@see Property} is
     * mutable in its parameters, and what this is for is a `PARTSTAT` written
     * on one instance (RFC 6638 §4.2) — which would be written on the whole
     * series if the two components held one property between them.
     */
    public function testNothingIsSharedWithTheMaster(): void
    {
        $master = $this->theEvent(
            "DTSTART:20261101T100000Z\r\nATTENDEE:mailto:cyrus@example.com\r\n"
            . "BEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT15M\r\nEND:VALARM\r\n",
        );
        $written = Override::of($master, new Property('RECURRENCE-ID', self::INSTANCE))
            ?? self::fail('No override.');

        $attendee = $written->property('ATTENDEE') ?? self::fail('No attendee.');
        $attendee['PARTSTAT'] = new Parameter('PARTSTAT', ['DECLINED']);

        ($written->component('VALARM')?->property('ACTION') ?? self::fail('No action.'))->setValue('AUDIO');

        self::assertStringContainsString(
            "ATTENDEE:mailto:cyrus@example.com\r\n",
            (new Serializer())->write($master),
        );
        self::assertStringContainsString("ACTION:DISPLAY\r\n", (new Serializer())->write($master));
    }

    /**
     * **A group travels with the property that carried one.** RFC 5545's
     * content line has none — only vCard's §3.3 does — so this is a property
     * no calendar object holds, and copying one whole is cheaper than reading
     * the grammar twice.
     */
    public function testAGroupTravelsWithTheProperty(): void
    {
        $master = new Component('VEVENT');

        $master->add(new Property('DTSTART', '20261101T100000Z'));
        $master->add(new Property('SUMMARY', 'Lunch', [], 'ITEM1'));

        self::assertStringContainsString(
            "ITEM1.SUMMARY:Lunch\r\n",
            (new Serializer())->write(
                Override::of($master, new Property('RECURRENCE-ID', self::INSTANCE)) ?? self::fail('No override.'),
            ),
        );
    }

    /**
     * The component that overrides {@see self::INSTANCE} of that master, as
     * text.
     *
     * @param string $properties What the master carries, beyond the `UID` and
     *                           `DTSTAMP` RFC 5545 §3.6.1 makes REQUIRED
     * @param string $kind The component's name, `VEVENT` unless a case is
     *                     about another
     */
    private function overriding(string $properties, string $kind = 'VEVENT'): string
    {
        return (new Serializer())->write(
            Override::of(
                $this->theEvent($properties, $kind),
                new Property('RECURRENCE-ID', self::INSTANCE),
            ) ?? self::fail('No override.'),
        );
    }

    /**
     * One component carrying those properties.
     */
    private function theEvent(string $properties, string $kind = 'VEVENT'): Component
    {
        $objects = iterator_to_array(
            (new Reader(
                "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dav.services//test//EN\r\n"
                . 'BEGIN:' . $kind . "\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\n" . $properties
                . 'END:' . $kind . "\r\nEND:VCALENDAR\r\n",
            ))->objects(),
            false,
        );

        return ($objects[0] ?? self::fail('The fixture is no object.'))->component($kind)
            ?? self::fail('The fixture holds no ' . $kind . '.');
    }
}
