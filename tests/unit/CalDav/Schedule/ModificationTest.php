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
use DavServices\CalDav\Schedule\Modification;
use DavServices\VObject\Component;
use DavServices\VObject\Property;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for RFC 6638 §3.2.1.2's table, "Modify". R-CAL-32, P8-02f.
 *
 * > When an "Organizer" modifies a scheduling object resource, the server MUST
 * > inspect each "ATTENDEE" property in both the original and modified
 * > iCalendar data **on a per-instance basis** to determine whether to send a
 * > scheduling message.
 * >
 * >  +---------------+-----------------------------------------------+
 * >  |               |                   Modified                    |
 * >  |               +-----------+-----------+-----------+-----------+
 * >  |               | <Removed> | SERVER    | CLIENT    | NONE      |
 * >  |               |           | (default) |           |           |
 * >  +===+===========+===========+===========+===========+===========+
 * >  |   | <Absent>  |  --       | REQUEST / | --        | --        |
 * >  | O |           |           | ADD       |           |           |
 * >  | r +-----------+-----------+-----------+-----------+-----------+
 * >  | i | SERVER    |  CANCEL   | REQUEST   | CANCEL    | CANCEL    |
 * >  | g | (default) |           |           |           |           |
 * >  | i +-----------+-----------+-----------+-----------+-----------+
 * >  | n | CLIENT    |  --       | REQUEST / | --        | --        |
 * >  | a |           |           | ADD       |           |           |
 * >  | l +-----------+-----------+-----------+-----------+-----------+
 * >  |   | NONE      |  --       | REQUEST / | --        | --        |
 * >  |   |           |           | ADD       |           |           |
 * >  +---+-----------+-----------+-----------+-----------+-----------+
 *
 * Sixteen cells, and every one of them is a case below.
 *
 * ## `REQUEST`, never `ADD`
 *
 * Four cells offer the server a choice, and it takes the same one every time.
 * RFC 5546 §3.2.4 says what an `ADD` saves and what it costs in the same
 * breath: it "allows the 'Organizer' to add one or more new instances to an
 * existing 'VEVENT' using a single iTIP message without having to send the
 * entire 'VEVENT' with all the existing instance data, as it would have to do
 * if the 'REQUEST' method were used" — and then, two paragraphs on, "If an
 * 'Attendee' implementation does not support the 'ADD' method, it should
 * respond with a 'REQUEST-STATUS' value of 3.14 and ask for a 'REFRESH'."
 *
 * So the `ADD` is the saving and the `REQUEST` is the one that always arrives.
 * Its constraints say the rest: an `ADD` carries exactly one component and
 * `RECURRENCE-ID | 0`, which makes it useless for the one thing a per-instance
 * comparison turns up most — a change to an overridden instance.
 *
 * ## What "per-instance" is taken to mean
 *
 * The instances the **data** names: the master component, and each component
 * that carries a `RECURRENCE-ID`. An instance that exists only because of an
 * `RRULE` and has no override of its own carries the master's attendee list,
 * so the master's row speaks for it.
 *
 * That has one consequence worth stating. Where the modification drops an
 * override, the instance it governed does not vanish — it falls back to the
 * master, and the master's attendee list is what the comparison reads for it.
 * An attendee the dropped override had left out is therefore invited again,
 * which is what the data says.
 *
 * And where an `EXDATE` or a shortened `RRULE` takes an instance away, no
 * `ATTENDEE` property changes anywhere, so the table says `REQUEST` and the
 * modified data travels — which tells the attendee exactly that the instance
 * is gone. The literal reading is also the right behaviour.
 */
#[CoversClass(Modification::class)]
final class ModificationTest extends TestCase
{
    private const CYRUS = 'mailto:cyrus@example.com';

    private const SERVER = 'ATTENDEE:mailto:cyrus@example.com';

    private const CLIENT = 'ATTENDEE;SCHEDULE-AGENT=CLIENT:mailto:cyrus@example.com';

    private const NONE = 'ATTENDEE;SCHEDULE-AGENT=NONE:mailto:cyrus@example.com';

    private const INSTANCE = '20261102T100000Z';

    /**
     * All sixteen cells, in the order the table prints them.
     *
     * @return array<string, array{?string, ?string, list<string>}>
     */
    public static function theTable(): array
    {
        return [
            '<Absent> → <Removed>' => [null, null, []],
            '<Absent> → SERVER' => [null, self::SERVER, ['REQUEST']],
            '<Absent> → CLIENT' => [null, self::CLIENT, []],
            '<Absent> → NONE' => [null, self::NONE, []],
            'SERVER → <Removed>' => [self::SERVER, null, ['CANCEL']],
            'SERVER → SERVER' => [self::SERVER, self::SERVER, ['REQUEST']],
            'SERVER → CLIENT' => [self::SERVER, self::CLIENT, ['CANCEL']],
            'SERVER → NONE' => [self::SERVER, self::NONE, ['CANCEL']],
            'CLIENT → <Removed>' => [self::CLIENT, null, []],
            'CLIENT → SERVER' => [self::CLIENT, self::SERVER, ['REQUEST']],
            'CLIENT → CLIENT' => [self::CLIENT, self::CLIENT, []],
            'CLIENT → NONE' => [self::CLIENT, self::NONE, []],
            'NONE → <Removed>' => [self::NONE, null, []],
            'NONE → SERVER' => [self::NONE, self::SERVER, ['REQUEST']],
            'NONE → CLIENT' => [self::NONE, self::CLIENT, []],
            'NONE → NONE' => [self::NONE, self::NONE, []],
        ];
    }

    /**
     * **§3.2.1.2's table, cell by cell.**
     *
     * @param list<string> $expected
     */
    #[DataProvider('theTable')]
    public function testTheTableSaysWhichMethodGoes(?string $was, ?string $is, array $expected): void
    {
        self::assertSame($expected, $this->whatCyrusIsTold(
            $this->anObject($this->anEvent($was ?? '')),
            $this->anObject($this->anEvent($is ?? '')),
        ));
    }

    /**
     * **And §3.2.1.1's table is the `<Absent>` row of it.**
     *
     * A creation has no original to compare against, so every attendee is
     * `<Absent>` — and the row reads `--`, `REQUEST`, `--`, `--`, which is
     * §3.2.1.1's own table word for word:
     *
     * >                  +------------------+-------------+
     * >                  | SCHEDULE-AGENT   | iTIP METHOD |
     * >                  +------------------+-------------+
     * >                  | SERVER (default) | REQUEST     |
     * >                  | CLIENT           | --          |
     * >                  | NONE             | --          |
     * >                  +------------------+-------------+
     *
     * @param list<string> $expected
     */
    #[DataProvider('theAbsentRow')]
    public function testACreationIsTheAbsentRowOfTheSameTable(?string $is, array $expected): void
    {
        self::assertSame($expected, $this->whatCyrusIsTold(null, $this->anObject($this->anEvent($is ?? ''))));
    }

    /**
     * @return array<string, array{?string, list<string>}>
     */
    public static function theAbsentRow(): array
    {
        return [
            'nothing → <Removed>' => [null, []],
            'nothing → SERVER' => [self::SERVER, ['REQUEST']],
            'nothing → CLIENT' => [self::CLIENT, []],
            'nothing → NONE' => [self::NONE, []],
        ];
    }

    /**
     * **The `<Removed>` cell of one instance cancels that instance.**
     *
     * RFC 5546 §3.2.5: "In order to cancel an individual instance of the
     * event, the 'RECURRENCE-ID' property value for the event MUST be
     * specified in the 'CANCEL' method."
     *
     * The attendee stays on the master, so the master's cell is `REQUEST` and
     * both messages go.
     */
    public function testAnAttendeeOffOneInstanceIsCancelledForThatInstanceOnly(): void
    {
        $told = $this->theOneTold(
            $this->anObject($this->anEvent(self::SERVER), $this->anInstance(self::SERVER)),
            $this->anObject($this->anEvent(self::SERVER), $this->anInstance('')),
        );

        self::assertTrue($told[1]);
        self::assertSame([self::INSTANCE], $this->instancesIn($told[2]));
    }

    /**
     * **An override the modification adds is an instance all the same.**
     *
     * The original named one event; the modification names it and an
     * overridden instance that leaves the attendee out. Nothing in the
     * original carries that `RECURRENCE-ID`, so the instance is read off the
     * side that does name it — and the master is what governed the attendee
     * there before.
     */
    public function testAnOverrideTheModificationAddsCancelsTheInstanceItNames(): void
    {
        $told = $this->theOneTold(
            $this->anObject($this->anEvent(self::SERVER)),
            $this->anObject($this->anEvent(self::SERVER), $this->anInstance('')),
        );

        self::assertSame([self::INSTANCE], $this->instancesIn($told[2]));
    }

    /**
     * **And the instance is named as the data names it**, parameters and all.
     *
     * A `RECURRENCE-ID` means one moment only together with its `TZID` (RFC
     * 5545 §3.8.4.4), so the property travels rather than its value.
     */
    public function testTheInstanceIsNamedWithWhateverSaysWhichMomentItIs(): void
    {
        $told = $this->theOneTold(
            $this->anObject($this->anEvent(self::SERVER)),
            $this->anObject(
                $this->anEvent(self::SERVER),
                "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261102T110000Z\r\n"
                . "RECURRENCE-ID;TZID=Europe/Berlin:20261102T100000\r\n"
                . "ORGANIZER:mailto:bernard@example.com\r\nEND:VEVENT\r\n",
            ),
        );

        $named = $told[2][0][1] ?? self::fail('Nothing cancelled.');

        self::assertInstanceOf(Property::class, $named);
        self::assertSame('20261102T100000', $named->value());
        self::assertSame('Europe/Berlin', $named->parameter('TZID')?->value());
    }

    /**
     * **An override the modification drops leaves the master speaking for the
     * instance.**
     *
     * The instance does not go away with the component that overrode it: the
     * `RRULE` still generates it, and what it carries is the master's attendee
     * list. So an attendee the dropped override left out is invited again, and
     * nothing is cancelled.
     */
    public function testAnOverrideTheModificationDropsFallsBackToTheMaster(): void
    {
        $told = $this->theOneTold(
            $this->anObject($this->anEvent(self::SERVER), $this->anInstance('')),
            $this->anObject($this->anEvent(self::SERVER)),
        );

        self::assertTrue($told[1]);
        self::assertSame([], $told[2]);
    }

    /**
     * **Off everything is a cancellation of the complete range.**
     *
     * RFC 5546 §3.2.5: "To cancel the complete range of a recurring event, the
     * 'UID' property value for the event MUST be specified and a
     * 'RECURRENCE-ID' MUST NOT be specified in the 'CANCEL' method." So the
     * instance is named by nothing at all.
     */
    public function testAnAttendeeOffEverythingIsCancelledForTheCompleteRange(): void
    {
        $told = $this->theOneTold(
            $this->anObject($this->anEvent(self::SERVER)),
            $this->anObject($this->anEvent('')),
        );

        self::assertFalse($told[1]);
        self::assertSame([null], $this->instancesIn($told[2]));
    }

    /**
     * **And the complete range covers the exceptions with it**, which is what
     * RFC 5546 §4.4.4 shows one of: "the 'Organizer' wishes to cancel the
     * entire recurring event and any exceptions", in one component with no
     * `RECURRENCE-ID`. Naming the overridden instances as well would be the
     * same cancellation twice.
     */
    public function testACompleteRangeCancellationSaysNothingOfTheInstances(): void
    {
        $told = $this->theOneTold(
            $this->anObject($this->anEvent(self::SERVER), $this->anInstance(self::SERVER)),
            $this->anObject($this->anEvent(''), $this->anInstance('')),
        );

        self::assertSame([null], $this->instancesIn($told[2]));
    }

    /**
     * **Off the master but on one instance is no cancellation at all.**
     *
     * §3.2.5 offers two forms and no third: the complete range, or the
     * instances named one by one. "The complete range" would say the attendee
     * is off an instance they are still invited to, and naming the instances
     * they keep is not what the form is for. So the removal from the master
     * travels in the `REQUEST` — RFC 5546 §3.2.2 has that message express
     * "the entire series", instance-specific information and all, and the
     * modified data no longer lists them on the master.
     */
    public function testOffTheMasterButOnAnInstanceTravelsInTheRequest(): void
    {
        $told = $this->theOneTold(
            $this->anObject($this->anEvent(self::SERVER), $this->anInstance(self::SERVER)),
            $this->anObject($this->anEvent(''), $this->anInstance(self::SERVER)),
        );

        self::assertTrue($told[1]);
        self::assertSame([], $told[2]);
    }

    /**
     * **And an instance lost on the way keeps its own cancellation**, because
     * that one can be named. The attendee is off the master and off one
     * overridden instance, and still on another.
     */
    public function testAnInstanceLostBesideOneKeptIsStillCancelled(): void
    {
        $told = $this->theOneTold(
            $this->anObject(
                $this->anEvent(self::SERVER),
                $this->anInstance(self::SERVER),
                $this->anInstance(self::SERVER, '20261103T100000Z'),
            ),
            $this->anObject(
                $this->anEvent(''),
                $this->anInstance(self::SERVER),
                $this->anInstance('', '20261103T100000Z'),
            ),
        );

        self::assertTrue($told[1]);
        self::assertSame(['20261103T100000Z'], $this->instancesIn($told[2]));
    }

    /**
     * **The cancellation is built from the original**, which is the only side
     * that still has the attendee on it — and RFC 5546 §3.2.5 asks for them:
     * `ATTENDEE | 0+ | MUST include some or all Attendees being removed from
     * the event.`
     */
    public function testTheCancellationIsTakenFromTheSideTheAttendeeIsOn(): void
    {
        $told = $this->theOneTold(
            $this->anObject($this->anEvent(self::SERVER)),
            $this->anObject($this->anEvent('')),
        );

        $component = $told[2][0][0] ?? self::fail('Nothing cancelled.');

        self::assertInstanceOf(Component::class, $component);
        self::assertSame(self::CYRUS, $component->property('ATTENDEE')?->value());
    }

    /**
     * **An attendee added in one instance only is still sent a `REQUEST`**,
     * and the whole modified resource is what goes: that is the `<Absent>`
     * row's `REQUEST / ADD`, taken as a `REQUEST`.
     */
    public function testAnAttendeeAddedInOneInstanceOnlyIsStillSentARequest(): void
    {
        $told = $this->theOneTold(
            $this->anObject($this->anEvent(''), $this->anInstance('')),
            $this->anObject($this->anEvent(''), $this->anInstance(self::SERVER)),
        );

        self::assertTrue($told[1]);
        self::assertSame([], $told[2]);
    }

    /**
     * **The properties to stamp are the ones on the modified resource**, which
     * is where §3.2.1.2 has them written: "'SCHEDULE-STATUS' iCalendar
     * property parameters are added or changed on 'ATTENDEE' iCalendar
     * properties in the scheduling object resource being modified".
     *
     * One calendar user, every property that names them — §7.3 asks for the
     * parameter "on any 'ATTENDEE' properties corresponding to calendar users
     * who were sent a scheduling message".
     */
    public function testEveryPropertyOfTheModifiedResourceIsThereToStamp(): void
    {
        $told = $this->theOneTold(
            $this->anObject($this->anEvent(self::SERVER)),
            $this->anObject($this->anEvent(self::SERVER), $this->anInstance(self::SERVER)),
        );

        self::assertCount(2, $told[3]);
    }

    /**
     * **And an attendee who is gone has nothing to stamp**, which is §3.2.1.1's
     * MUST NOT read from the other end: "Servers MUST NOT set the
     * 'SCHEDULE-STATUS' property parameter on the 'ATTENDEE' property of
     * 'Attendees' for which it did not attempt to deliver a scheduling
     * message." There is no property of theirs left on the resource at all.
     */
    public function testAnAttendeeTheModificationRemovesHasNothingToStamp(): void
    {
        $told = $this->theOneTold(
            $this->anObject($this->anEvent(self::SERVER)),
            $this->anObject($this->anEvent('')),
        );

        self::assertSame([], $told[3]);
    }

    /**
     * **A `VTIMEZONE` is no instance.** RFC 5545 §3.6.5 lists what one may
     * hold, and neither `RECURRENCE-ID` nor `ATTENDEE` is among them.
     */
    public function testATimeZoneIsNoInstanceAndNobodysAttendeeList(): void
    {
        $zone = "BEGIN:VTIMEZONE\r\nTZID:Europe/Berlin\r\nBEGIN:STANDARD\r\n"
            . "DTSTART:19701025T030000\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\n"
            . "END:STANDARD\r\nEND:VTIMEZONE\r\n";

        self::assertSame(['REQUEST'], $this->whatCyrusIsTold(
            $this->anObject($zone, $this->anEvent(self::SERVER)),
            $this->anObject($zone, $this->anEvent(self::SERVER)),
        ));
    }

    /**
     * **An alarm's attendee is nobody's invitation.** RFC 5545 §3.6.6 makes
     * them "the attendees… to be sent an email", and a reminder the organizer
     * set is not an invitation for whoever it names.
     */
    public function testAnAlarmsAttendeeIsNotOnTheAttendeeList(): void
    {
        $alarm = "BEGIN:VALARM\r\nACTION:EMAIL\r\nTRIGGER:-PT15M\r\n"
            . "DESCRIPTION:x\r\nSUMMARY:x\r\nATTENDEE:mailto:cyrus@example.com\r\nEND:VALARM\r\n";

        self::assertSame([], $this->whatCyrusIsTold(
            $this->anObject($this->anEvent('')),
            $this->anObject($this->anEventWith('', $alarm)),
        ));
    }

    /**
     * **Everybody either side names is looked at**, not just the ones the
     * modification keeps.
     */
    public function testEachCalendarUserOnEitherSideIsAnswerredFor(): void
    {
        $told = Modification::whatEachCalendarUserIsTold(
            $this->anObject($this->anEvent(self::SERVER)),
            $this->anObject($this->anEvent('ATTENDEE:mailto:eric@example.com')),
        );

        self::assertSame(
            [self::CYRUS, 'mailto:eric@example.com'],
            array_map(static fn (array $one): string => $one[0], array_values($told)),
        );
    }

    /**
     * **Nobody is told about a change that moves nobody**, and the record is
     * not there to be skipped: an attendee whose cell is `--` on every
     * instance is left out altogether.
     */
    public function testAChangeThatTellsNobodyAnythingSaysNothing(): void
    {
        self::assertSame([], Modification::whatEachCalendarUserIsTold(
            $this->anObject($this->anEvent(self::CLIENT)),
            $this->anObject($this->anEvent(self::CLIENT)),
        ));
    }

    /**
     * **§3.2.1's `PARTSTAT` SHOULD, and what makes it an attempt to set.**
     *
     * > The server SHOULD reject any attempt to **set** the "PARTSTAT"
     * > iCalendar property parameter value of the "ATTENDEE" iCalendar
     * > property of other users in the calendar object resource to a value
     * > other than "NEEDS-ACTION" if the "SCHEDULE-AGENT" property parameter
     * > value is not present or set to the value "SERVER".
     *
     * On a creation every answer but `NEEDS-ACTION` is an attempt to set one.
     */
    public function testAnAnswerWrittenWhereThereWasNoResourceIsSet(): void
    {
        self::assertSame([self::CYRUS], Modification::whoseAnswerItSets(
            null,
            $this->anObject($this->anEvent('ATTENDEE;PARTSTAT=ACCEPTED:mailto:cyrus@example.com')),
        ));
    }

    /**
     * **An answer the resource already carried is nobody's attempt to set
     * one**, and this is the sentence a modification turns on: §3.2.1.2 refuses
     * what "the 'Organizer' attempts to **change**… in a manner that is
     * forbidden".
     *
     * §4.2 writes the attendees' answers onto the organizer's own copy, so a
     * client that reads the resource, moves the meeting and writes it back
     * sends every one of those answers back with it. A server that read that
     * as an organizer answering for somebody else would refuse every second
     * modification ever made.
     */
    public function testAnAnswerTheResourceAlreadyCarriedIsNotSet(): void
    {
        self::assertSame([], Modification::whoseAnswerItSets(
            $this->anObject($this->anEvent('ATTENDEE;PARTSTAT=ACCEPTED:mailto:cyrus@example.com')),
            $this->anObject($this->anEvent('ATTENDEE;PARTSTAT=ACCEPTED:mailto:cyrus@example.com')),
        ));
    }

    /**
     * **And a different answer is.**
     */
    public function testAnAnswerTheModificationChangesIsSet(): void
    {
        self::assertSame([self::CYRUS], Modification::whoseAnswerItSets(
            $this->anObject($this->anEvent('ATTENDEE;PARTSTAT=DECLINED:mailto:cyrus@example.com')),
            $this->anObject($this->anEvent('ATTENDEE;PARTSTAT=ACCEPTED:mailto:cyrus@example.com')),
        ));
    }

    /**
     * **The same answer spelt differently is the same answer.** RFC 5545 §3.5
     * makes an enumerated property parameter value case-insensitive, and
     * `PARTSTAT` is one.
     */
    public function testTheSpellingOfAnUnchangedAnswerDoesNotMakeItNew(): void
    {
        self::assertSame([], Modification::whoseAnswerItSets(
            $this->anObject($this->anEvent('ATTENDEE;PARTSTAT=ACCEPTED:mailto:cyrus@example.com')),
            $this->anObject($this->anEvent('ATTENDEE;PARTSTAT=accepted:mailto:cyrus@example.com')),
        ));
    }

    /**
     * **`NEEDS-ACTION` is never an answer set**, whatever stood there before:
     * the sentence refuses "a value other than 'NEEDS-ACTION'", and taking an
     * answer away is how an organizer asks again.
     */
    public function testNeedsActionIsNoAnswerAtAll(): void
    {
        self::assertSame([], Modification::whoseAnswerItSets(
            $this->anObject($this->anEvent('ATTENDEE;PARTSTAT=ACCEPTED:mailto:cyrus@example.com')),
            $this->anObject($this->anEvent('ATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:cyrus@example.com')),
        ));
    }

    /**
     * **An attendee with no `PARTSTAT` at all has no answer to set either**,
     * which is the same thing: RFC 5545 §3.2.12 makes `NEEDS-ACTION` the
     * default.
     */
    public function testAnAttendeeWhoHasNotAnsweredIsNoObjection(): void
    {
        self::assertSame([], Modification::whoseAnswerItSets(
            null,
            $this->anObject($this->anEvent(self::SERVER)),
        ));
    }

    /**
     * **Per instance here too**, because an attendee answers per instance:
     * §4.2 says as much — "an 'Attendee' could have created a new overridden
     * iCalendar component to indicate different participation status on one or
     * more instances of a recurring event".
     */
    public function testAnAnswerChangedInOneInstanceOnlyIsStillSet(): void
    {
        self::assertSame([self::CYRUS], Modification::whoseAnswerItSets(
            $this->anObject(
                $this->anEvent('ATTENDEE;PARTSTAT=ACCEPTED:mailto:cyrus@example.com'),
                $this->anInstance('ATTENDEE;PARTSTAT=DECLINED:mailto:cyrus@example.com'),
            ),
            $this->anObject(
                $this->anEvent('ATTENDEE;PARTSTAT=ACCEPTED:mailto:cyrus@example.com'),
                $this->anInstance('ATTENDEE;PARTSTAT=ACCEPTED:mailto:cyrus@example.com'),
            ),
        ));
    }

    /**
     * **An attendee the client schedules for is not this server's business**,
     * which is the condition the sentence states: "if the 'SCHEDULE-AGENT'
     * property parameter value is not present or set to the value 'SERVER'".
     * §7.3 says why in its own words — "the client MAY add, change, or remove
     * this parameter to indicate the last scheduling message status it
     * received".
     */
    public function testAnAnswerOnAnAttendeeTheClientHandlesStands(): void
    {
        self::assertSame([], Modification::whoseAnswerItSets(
            null,
            $this->anObject($this->anEvent('ATTENDEE;SCHEDULE-AGENT=CLIENT;PARTSTAT=ACCEPTED:mailto:cyrus@example.com')),
        ));
    }

    /**
     * **And one the modification invites with an answer already on it is
     * set**: there was nothing there to carry it over from.
     */
    public function testAnAnswerForAnAttendeeTheModificationAddsIsSet(): void
    {
        self::assertSame([self::CYRUS], Modification::whoseAnswerItSets(
            $this->anObject($this->anEvent('')),
            $this->anObject($this->anEvent('ATTENDEE;PARTSTAT=ACCEPTED:mailto:cyrus@example.com')),
        ));
    }

    /**
     * **One calendar user is named once**, however many properties of theirs
     * the resource carries.
     */
    public function testACalendarUserWhoseAnswerIsSetTwiceIsNamedOnce(): void
    {
        self::assertSame([self::CYRUS], Modification::whoseAnswerItSets(
            null,
            $this->anObject(
                $this->anEvent('ATTENDEE;PARTSTAT=ACCEPTED:mailto:cyrus@example.com'),
                $this->anInstance('ATTENDEE;PARTSTAT=TENTATIVE:mailto:cyrus@example.com'),
            ),
        ));
    }

    /**
     * **An answer where the resource carried none is set too.** RFC 5545
     * §3.2.12 makes `NEEDS-ACTION` the default, so an attendee without the
     * parameter had answered nothing, and an answer put there is one the
     * organizer wrote.
     */
    public function testAnAnswerWhereTheResourceCarriedNoneIsSet(): void
    {
        self::assertSame([self::CYRUS], Modification::whoseAnswerItSets(
            $this->anObject($this->anEvent(self::SERVER)),
            $this->anObject($this->anEvent('ATTENDEE;PARTSTAT=ACCEPTED:mailto:cyrus@example.com')),
        ));
    }

    /**
     * **And the answer compared is that calendar user's own**, with whoever
     * stands in front of them on the list passed over.
     */
    public function testTheAnswerComparedIsTheSameCalendarUsers(): void
    {
        self::assertSame([], Modification::whoseAnswerItSets(
            $this->anObject($this->anEvent(
                "ATTENDEE;PARTSTAT=DECLINED:mailto:eric@example.com\r\n"
                . 'ATTENDEE;PARTSTAT=ACCEPTED:mailto:cyrus@example.com',
            )),
            $this->anObject($this->anEvent(
                "ATTENDEE;PARTSTAT=DECLINED:mailto:eric@example.com\r\n"
                . 'ATTENDEE;PARTSTAT=ACCEPTED:mailto:cyrus@example.com',
            )),
        ));
    }

    /**
     * **Dropping an overridden instance that held another answer sets one**,
     * and this case is the price of reading "per-instance" the way the table
     * does.
     *
     * The attendee had declined that one instance; with the override gone the
     * master speaks for it, and the master says they accepted. The resource
     * now asserts an answer for that instance which the attendee never gave,
     * so §3.2.1's sentence has it refused.
     *
     * The alternative reading — comparing component to component as the client
     * wrote them — would let this through and refuse the far commoner case
     * instead: a client that splits one instance off a series copies the
     * attendee list into the new component, answers and all, and there is no
     * component in the original to carry them over from. Of the two, this one
     * is on the side of the answer the attendee actually gave.
     */
    public function testDroppingAnOverrideThatHeldAnotherAnswerIsSettingOne(): void
    {
        self::assertSame([self::CYRUS], Modification::whoseAnswerItSets(
            $this->anObject(
                $this->anEvent('ATTENDEE;PARTSTAT=ACCEPTED:mailto:cyrus@example.com'),
                $this->anInstance('ATTENDEE;PARTSTAT=DECLINED:mailto:cyrus@example.com'),
            ),
            $this->anObject($this->anEvent('ATTENDEE;PARTSTAT=ACCEPTED:mailto:cyrus@example.com')),
        ));
    }

    /**
     * **And splitting an instance off a series carries the answers with it**,
     * which is the case the reading above is chosen for: the new component is
     * compared against the master that governed that instance before it.
     */
    public function testSplittingAnInstanceOffCarriesTheAnswersWithIt(): void
    {
        self::assertSame([], Modification::whoseAnswerItSets(
            $this->anObject($this->anEvent('ATTENDEE;PARTSTAT=ACCEPTED:mailto:cyrus@example.com')),
            $this->anObject(
                $this->anEvent('ATTENDEE;PARTSTAT=ACCEPTED:mailto:cyrus@example.com'),
                $this->anInstance('ATTENDEE;PARTSTAT=ACCEPTED:mailto:cyrus@example.com'),
            ),
        ));
    }

    /**
     * **An alarm's attendee has no answer to set.** The same reading
     * {@see self::testAnAlarmsAttendeeIsNotOnTheAttendeeList()} makes: RFC 5545
     * §3.6.6's attendees are there to be sent an email.
     */
    public function testAnAlarmsAttendeeHasNoAnswerToSet(): void
    {
        $alarm = "BEGIN:VALARM\r\nACTION:EMAIL\r\nTRIGGER:-PT15M\r\nDESCRIPTION:x\r\nSUMMARY:x\r\n"
            . "ATTENDEE;PARTSTAT=ACCEPTED:mailto:cyrus@example.com\r\nEND:VALARM\r\n";

        self::assertSame([], Modification::whoseAnswerItSets(
            null,
            $this->anObject($this->anEventWith('', $alarm)),
        ));
    }

    /**
     * What the comparison tells Cyrus, as the methods in the order they go.
     *
     * @return list<string>
     */
    private function whatCyrusIsTold(?Component $original, Component $modified): array
    {
        $told = [];

        foreach (Modification::whatEachCalendarUserIsTold($original, $modified) as $one) {
            if ($one[0] !== self::CYRUS) {
                continue;
            }

            if ($one[2] !== []) {
                $told[] = 'CANCEL';
            }

            if ($one[1]) {
                $told[] = 'REQUEST';
            }
        }

        return $told;
    }

    /**
     * The one record the comparison yields, which every case below has.
     *
     * @return array{string, bool, list<array{Component, ?Property}>, list<Property>}
     */
    private function theOneTold(?Component $original, Component $modified): array
    {
        $told = array_values(Modification::whatEachCalendarUserIsTold($original, $modified));

        self::assertCount(1, $told);

        return $told[0] ?? self::fail('Nothing told.');
    }

    /**
     * The instances a cancellation names, by value, with null for the complete
     * range.
     *
     * @param list<array{Component, ?Property}> $cancelled
     *
     * @return list<string|null>
     */
    private function instancesIn(array $cancelled): array
    {
        $named = [];

        foreach ($cancelled as [, $property]) {
            $named[] = $property?->value();
        }

        return $named;
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
        return $this->anEventWith($properties, '');
    }

    /**
     * The same with something inside it.
     */
    private function anEventWith(string $properties, string $inside): string
    {
        return "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261101T100000Z\r\n"
            . "RRULE:FREQ=DAILY;COUNT=3\r\nORGANIZER:mailto:bernard@example.com\r\n"
            . ($properties === '' ? '' : $properties . "\r\n")
            . $inside
            . "END:VEVENT\r\n";
    }

    /**
     * An overridden instance, carrying those properties.
     */
    private function anInstance(string $properties, string $id = self::INSTANCE): string
    {
        return "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261102T110000Z\r\n"
            . 'RECURRENCE-ID:' . $id . "\r\nORGANIZER:mailto:bernard@example.com\r\n"
            . ($properties === '' ? '' : $properties . "\r\n")
            . "END:VEVENT\r\n";
    }
}
