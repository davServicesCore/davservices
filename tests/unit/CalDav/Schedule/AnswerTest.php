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
use DavServices\CalDav\Schedule\Answer;
use DavServices\VObject\Component;
use DavServices\VObject\Property;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for RFC 6638 §3.2.2.3, "Modify" on the attendee's side.
 * R-CAL-32, P8-02g.
 *
 * > The server will inspect the changes by comparing the new scheduling object
 * > resource with the existing scheduling object resource.
 * >
 * > If the "Attendee" changes one or more "PARTSTAT" iCalendar property values
 * > on any component, or adds an overridden component with a changed
 * > "PARTSTAT" property, then the server MUST deliver an iTIP "REPLY"
 * > scheduling message to the "Organizer" to indicate the new participation
 * > status of the "Attendee".
 * >
 * > If the "Attendee" adds an "EXDATE" property value to effectively remove a
 * > recurrence instance, the server MUST deliver an iTIP "REPLY" scheduling
 * > message to the "Organizer" to indicate that the "Attendee" has declined
 * > the instance.
 *
 * ## Two sentences, one comparison
 *
 * Both are the same question of each instance — what does this calendar user
 * now say about it, and did they say that before — and the second is the
 * first with the answer read off the absence of the instance rather than off a
 * parameter. An `EXDATE` added is the attendee declining, which §3.2.2.3 says
 * in as many words, so it arrives in the same message with `PARTSTAT=DECLINED`
 * against the instance it names.
 *
 * ## A creation is the comparison with nothing to compare against
 *
 * §3.2.2.2 names no message of its own. What it says is that the server
 * "will attempt to process changes to the resource using the normal rules for
 * attendee scheduling object resources", and then that "'SCHEDULE-STATUS'
 * iCalendar property parameters are added or changed on 'ORGANIZER' iCalendar
 * properties in the scheduling object resource being created as described in
 * Section 7.3, with the value set as described in Section 3.2.9" — a delivery
 * status, which is only ever written where something was delivered.
 *
 * So a creation is read here the way §3.2.1.2's `<Absent>` row reads a
 * creation on the organizer's side: the same rules, with nothing on the side
 * they compare against. The answer an attendee stores an invitation with is
 * their answer, and RFC 5545 §3.2.12 says what an absent one is — "NEEDS-ACTION
 * […] is the default value" — so storing one with no answer in it answers
 * nothing and sends nothing.
 *
 * ## Only this calendar user's own answer
 *
 * §3.2.2.1's first allowance is "change their own 'PARTSTAT' iCalendar property
 * parameter value", and a `REPLY` is `ATTENDEE | 1 | MUST be the address of the
 * Attendee replying` (RFC 5546 §3.2.3). Somebody else's answer on an
 * attendee's copy is the organizer's own doing — §4.2 writes it there — so it
 * is no part of what this calendar user is telling anybody.
 */
#[CoversClass(Answer::class)]
final class AnswerTest extends TestCase
{
    private const CYRUS = 'mailto:cyrus@example.com';

    private const INSTANCE = '20261102T100000Z';

    /**
     * **An answer changed is a reply**, which is §3.2.2.3's first sentence.
     */
    public function testAnAnswerChangedIsAReply(): void
    {
        $told = $this->whatTheOrganizerIsTold(
            $this->anObject($this->anEvent('ATTENDEE;PARTSTAT=NEEDS-ACTION:' . self::CYRUS)),
            $this->anObject($this->anEvent('ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS)),
        );

        self::assertSame(['' => 'ACCEPTED'], $told);
    }

    /**
     * **An answer unchanged is nothing to tell anybody.** The sentence asks
     * about an attendee who "changes one or more 'PARTSTAT' iCalendar property
     * values", and a resource written back as it stood changes none.
     */
    public function testAnAnswerUnchangedIsNoReply(): void
    {
        $object = $this->anObject($this->anEvent('ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS));

        self::assertSame([], $this->whatTheOrganizerIsTold($object, $object));
    }

    /**
     * **An answer withdrawn is a reply too.** `NEEDS-ACTION` is a
     * participation status like any other once it replaces one, and the
     * sentence asks for "the new participation status of the 'Attendee'"
     * rather than for an acceptance.
     */
    public function testAnAnswerWithdrawnIsAReply(): void
    {
        $told = $this->whatTheOrganizerIsTold(
            $this->anObject($this->anEvent('ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS)),
            $this->anObject($this->anEvent('ATTENDEE;PARTSTAT=NEEDS-ACTION:' . self::CYRUS)),
        );

        self::assertSame(['' => 'NEEDS-ACTION'], $told);
    }

    /**
     * **A creation carrying an answer is a reply**, §3.2.2.2 being this
     * comparison with nothing on the other side.
     */
    public function testACreationCarryingAnAnswerIsAReply(): void
    {
        $told = $this->whatTheOrganizerIsTold(
            null,
            $this->anObject($this->anEvent('ATTENDEE;PARTSTAT=DECLINED:' . self::CYRUS)),
        );

        self::assertSame(['' => 'DECLINED'], $told);
    }

    /**
     * **And a creation carrying none is not**, RFC 5545 §3.2.12 making the
     * absent answer `NEEDS-ACTION` rather than nothing: "NEEDS-ACTION […] is
     * the default value".
     *
     * Which is the same state the stored one is in, so nothing changed and
     * nobody is told. A server that replied here would be telling the
     * organizer that an attendee has not answered yet, over and over, every
     * time one stored a message that reached them by another road.
     */
    #[DataProvider('theAnswersThatAreNone')]
    public function testACreationCarryingNoAnswerIsNoReply(string $attendee): void
    {
        self::assertSame([], $this->whatTheOrganizerIsTold(null, $this->anObject($this->anEvent($attendee))));
    }

    /**
     * The two ways of saying what §3.2.12 says.
     *
     * @return iterable<string, array{string}>
     */
    public static function theAnswersThatAreNone(): iterable
    {
        yield 'no parameter at all' => ['ATTENDEE:' . self::CYRUS];
        yield 'the default written out' => ['ATTENDEE;PARTSTAT=NEEDS-ACTION:' . self::CYRUS];
    }

    /**
     * **The same default on the other side**: a resource that carried no
     * answer and now carries the default in writing says the same thing twice.
     */
    public function testWritingTheDefaultOutChangesNothing(): void
    {
        $told = $this->whatTheOrganizerIsTold(
            $this->anObject($this->anEvent('ATTENDEE:' . self::CYRUS)),
            $this->anObject($this->anEvent('ATTENDEE;PARTSTAT=NEEDS-ACTION:' . self::CYRUS)),
        );

        self::assertSame([], $told);
    }

    /**
     * **An answer is read without regard to case**, RFC 5545 §3.5 making the
     * enumerated values of a parameter case-insensitive: "Property parameter
     * values that are not in quoted-strings are case-insensitive."
     */
    public function testAnAnswerIsReadWithoutRegardToCase(): void
    {
        $told = $this->whatTheOrganizerIsTold(
            $this->anObject($this->anEvent('ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS)),
            $this->anObject($this->anEvent('ATTENDEE;PARTSTAT=accepted:' . self::CYRUS)),
        );

        self::assertSame([], $told);
    }

    /**
     * **Somebody else's answer is not this calendar user's reply.** §4.2 has
     * the organizer's server write the other attendees' answers onto the
     * copies it sends, so an attendee's own resource is full of answers that
     * are not theirs, and a `REPLY` carries exactly one `ATTENDEE`.
     */
    public function testSomebodyElsesAnswerIsNoReply(): void
    {
        $told = $this->whatTheOrganizerIsTold(
            $this->anObject($this->anEvent(
                'ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS . "\r\nATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:eric@example.com",
            )),
            $this->anObject($this->anEvent(
                'ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS . "\r\nATTENDEE;PARTSTAT=DECLINED:mailto:eric@example.com",
            )),
        );

        self::assertSame([], $told);
    }

    /**
     * **An instance answered is named**, which RFC 5546 §3.2.3's constraints
     * ask of a `REPLY` about one: `RECURRENCE-ID | 0 or 1 | Only if referring
     * to an instance of a recurring calendar component.`
     */
    public function testAnInstanceAnsweredIsNamed(): void
    {
        $told = $this->whatTheOrganizerIsTold(
            $this->anObject(
                $this->anEvent('ATTENDEE:' . self::CYRUS),
                $this->anInstance('ATTENDEE;PARTSTAT=NEEDS-ACTION:' . self::CYRUS),
            ),
            $this->anObject(
                $this->anEvent('ATTENDEE:' . self::CYRUS),
                $this->anInstance('ATTENDEE;PARTSTAT=TENTATIVE:' . self::CYRUS),
            ),
        );

        self::assertSame([self::INSTANCE => 'TENTATIVE'], $told);
    }

    /**
     * **An override added with a different answer is a reply about that
     * instance**, which is §3.2.2.3's "or adds an overridden component with a
     * changed 'PARTSTAT' property" — and what it is changed *from* is the
     * master that governed the instance until then.
     */
    public function testAnOverrideAddedWithADifferentAnswerIsAReply(): void
    {
        $told = $this->whatTheOrganizerIsTold(
            $this->anObject($this->anEvent('ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS)),
            $this->anObject(
                $this->anEvent('ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS),
                $this->anInstance('ATTENDEE;PARTSTAT=DECLINED:' . self::CYRUS),
            ),
        );

        self::assertSame([self::INSTANCE => 'DECLINED'], $told);
    }

    /**
     * **And one added that says what the master already said is no reply**,
     * which is the other half of the same sentence: the component is added,
     * the `PARTSTAT` is not changed. A client that splits an instance off a
     * series copies the attendee list into the new component, answers and all.
     */
    public function testAnOverrideAddedWithTheSameAnswerIsNoReply(): void
    {
        $told = $this->whatTheOrganizerIsTold(
            $this->anObject($this->anEvent('ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS)),
            $this->anObject(
                $this->anEvent('ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS),
                $this->anInstance('ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS),
            ),
        );

        self::assertSame([], $told);
    }

    /**
     * **An override dropped is a reply where the master says something else.**
     * The `RRULE` still generates the instance and the master governs it
     * again, so the participation status of that instance has changed to the
     * master's — which is the comparison read the other way round, and §3.2.2.1
     * allows the change: "possibly remove components for overridden recurrence
     * instances".
     */
    public function testAnOverrideDroppedIsAReplyWhereTheMasterSaysSomethingElse(): void
    {
        $told = $this->whatTheOrganizerIsTold(
            $this->anObject(
                $this->anEvent('ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS),
                $this->anInstance('ATTENDEE;PARTSTAT=DECLINED:' . self::CYRUS),
            ),
            $this->anObject($this->anEvent('ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS)),
        );

        self::assertSame([self::INSTANCE => 'ACCEPTED'], $told);
    }

    /**
     * **An `EXDATE` added declines the instance it names**, which is
     * §3.2.2.3's second sentence, and the answer it carries is the one that
     * sentence names: "to indicate that the 'Attendee' has declined the
     * instance".
     */
    public function testAnExdateAddedDeclinesThatInstance(): void
    {
        $told = $this->whatTheOrganizerIsTold(
            $this->anObject($this->anEvent('ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS)),
            $this->anObject($this->anEvent(
                'ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS . "\r\nEXDATE:" . self::INSTANCE,
            )),
        );

        self::assertSame([self::INSTANCE => 'DECLINED'], $told);
    }

    /**
     * **An `EXDATE` that was there already declines nothing.** The sentence is
     * about one "added", and an instance the attendee declined last week is
     * not news the organizer needs twice.
     */
    public function testAnExdateAlreadyThereDeclinesNothing(): void
    {
        $object = $this->anObject($this->anEvent(
            'ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS . "\r\nEXDATE:" . self::INSTANCE,
        ));

        self::assertSame([], $this->whatTheOrganizerIsTold($object, $object));
    }

    /**
     * **And one taken away says nothing either.** §3.2.2.3 names the addition
     * and no other change to the property, and there is no answer for "I am
     * coming after all" that a `REPLY` about an instance nobody excluded could
     * carry: the instance is back under whatever the master says, which is
     * what the organizer's copy already holds.
     */
    public function testAnExdateTakenAwaySaysNothing(): void
    {
        $told = $this->whatTheOrganizerIsTold(
            $this->anObject($this->anEvent(
                'ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS . "\r\nEXDATE:" . self::INSTANCE,
            )),
            $this->anObject($this->anEvent('ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS)),
        );

        self::assertSame([], $told);
    }

    /**
     * **Several values in one property are several instances.** RFC 5545
     * §3.8.5.1 gives `EXDATE` a comma-separated list of values — "exdtval
     * *("," exdtval)" — and each of them removes an instance of its own.
     */
    public function testSeveralValuesInOneExdateAreSeveralInstances(): void
    {
        $told = $this->whatTheOrganizerIsTold(
            $this->anObject($this->anEvent('ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS)),
            $this->anObject($this->anEvent(
                'ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS
                . "\r\nEXDATE:" . self::INSTANCE . ',20261103T100000Z',
            )),
        );

        self::assertSame([self::INSTANCE => 'DECLINED', '20261103T100000Z' => 'DECLINED'], $told);
    }

    /**
     * **And several properties are too**, §3.8.5.1 letting a component carry
     * more than one.
     */
    public function testSeveralExdatePropertiesAreSeveralInstances(): void
    {
        $told = $this->whatTheOrganizerIsTold(
            $this->anObject($this->anEvent('ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS)),
            $this->anObject($this->anEvent(
                'ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS
                . "\r\nEXDATE:" . self::INSTANCE . "\r\nEXDATE:20261103T100000Z",
            )),
        );

        self::assertSame([self::INSTANCE => 'DECLINED', '20261103T100000Z' => 'DECLINED'], $told);
    }

    /**
     * **An instance excluded is declined and not also answered.** §3.2.2.1
     * allows both halves of the change in one breath — "add 'EXDATE'
     * iCalendar properties **and possibly remove components for overridden
     * recurrence instances**" — so an attendee who declines an instance they
     * had an override for leaves two changes behind: the exclusion, and an
     * override gone whose answer differed from the master's.
     *
     * The exclusion is the one that is true of the instance afterwards. Two
     * components naming one instance would have the organizer's server, which
     * reads them in whatever order they arrive (§4.2), decide which of two
     * contradictory answers to keep.
     */
    public function testAnInstanceExcludedIsDeclinedAndNotAlsoAnswered(): void
    {
        $told = $this->whatTheOrganizerIsTold(
            $this->anObject(
                $this->anEvent('ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS),
                $this->anInstance('ATTENDEE;PARTSTAT=TENTATIVE:' . self::CYRUS),
            ),
            $this->anObject($this->anEvent(
                'ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS . "\r\nEXDATE:" . self::INSTANCE,
            )),
        );

        self::assertSame([self::INSTANCE => 'DECLINED'], $told);
    }

    /**
     * **Two instances answered are two components of one message**, RFC 5546
     * §3.2.3 having `VEVENT | 1+ | All components MUST have the same UID`.
     */
    public function testTwoInstancesAnsweredAreTwoComponents(): void
    {
        $told = $this->whatTheOrganizerIsTold(
            $this->anObject(
                $this->anEvent('ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS),
                $this->anInstance('ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS),
            ),
            $this->anObject(
                $this->anEvent('ATTENDEE;PARTSTAT=TENTATIVE:' . self::CYRUS),
                $this->anInstance('ATTENDEE;PARTSTAT=DECLINED:' . self::CYRUS),
            ),
        );

        self::assertSame(['' => 'TENTATIVE', self::INSTANCE => 'DECLINED'], $told);
    }

    /**
     * **An instance this calendar user is not on says nothing about them.**
     * The comparison asks what they now say about the instance, and a
     * component that does not name them has them saying nothing — which is
     * not a participation status to report.
     */
    public function testAnInstanceWithoutThisCalendarUserIsNoReply(): void
    {
        $told = $this->whatTheOrganizerIsTold(
            $this->anObject($this->anEvent('ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS)),
            $this->anObject(
                $this->anEvent('ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS),
                $this->anInstance('ATTENDEE:mailto:eric@example.com'),
            ),
        );

        self::assertSame([], $told);
    }

    /**
     * **This calendar user is found behind one that was passed over.** RFC
     * 5545 §3.6.1 lets a component carry as many `ATTENDEE` properties as the
     * event has people, and §3.5 imposes no order on them, so the one this
     * comparison is about can stand anywhere in the list.
     */
    public function testTheOwnerIsFoundBehindAnAttendeeThatComesFirst(): void
    {
        $told = $this->whatTheOrganizerIsTold(
            $this->anObject($this->anEvent(
                'ATTENDEE;PARTSTAT=ACCEPTED:mailto:eric@example.com'
                . "\r\nATTENDEE;PARTSTAT=NEEDS-ACTION:" . self::CYRUS,
            )),
            $this->anObject($this->anEvent(
                'ATTENDEE;PARTSTAT=ACCEPTED:mailto:eric@example.com'
                . "\r\nATTENDEE;PARTSTAT=TENTATIVE:" . self::CYRUS,
            )),
        );

        self::assertSame(['' => 'TENTATIVE'], $told);
    }

    /**
     * **And an instance excluded behind one that was passed over is declined
     * all the same**, the two readings of the attendee list being the same
     * reading.
     */
    public function testAnInstanceExcludedIsDeclinedBehindAnAttendeeThatComesFirst(): void
    {
        $told = $this->whatTheOrganizerIsTold(
            $this->anObject($this->anEvent('ATTENDEE:mailto:eric@example.com' . "\r\nATTENDEE:" . self::CYRUS)),
            $this->anObject($this->anEvent(
                'ATTENDEE:mailto:eric@example.com' . "\r\nATTENDEE:" . self::CYRUS
                . "\r\nEXDATE:" . self::INSTANCE,
            )),
        );

        self::assertSame([self::INSTANCE => 'DECLINED'], $told);
    }

    /**
     * **Any of the owner's own addresses is theirs.** §2.4.1 makes
     * `CALDAV:calendar-user-address-set` a set of them — "the URI(s) …
     * associated with the calendar user" — so which one the organizer wrote
     * into the `ATTENDEE` property is the organizer's choice and not a
     * different person.
     */
    public function testAnyOfTheOwnersAddressesIsTheirs(): void
    {
        $told = Answer::whatTheOrganizerIsTold(
            $this->anObject($this->anEvent('ATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:c.daboo@example.com')),
            $this->anObject($this->anEvent('ATTENDEE;PARTSTAT=ACCEPTED:mailto:c.daboo@example.com')),
            [self::CYRUS, 'mailto:c.daboo@example.com'],
        );

        self::assertCount(1, $told);
    }

    /**
     * **The reply carries the property the attendee wrote.** What else is on
     * it is the invitation as the organizer composed it — `CN`, `ROLE`, `RSVP`
     * — and a server that built a bare `ATTENDEE` from the address alone would
     * be answering in words of its own.
     */
    public function testTheReplyCarriesThePropertyTheAttendeeWrote(): void
    {
        $told = $this->theOneTold(
            $this->anObject($this->anEvent('ATTENDEE;CN=Cyrus;PARTSTAT=NEEDS-ACTION:' . self::CYRUS)),
            $this->anObject($this->anEvent('ATTENDEE;CN=Cyrus;PARTSTAT=ACCEPTED:' . self::CYRUS)),
        );

        self::assertSame('Cyrus', $told[2]->parameter('CN')?->value());
        self::assertSame(self::CYRUS, $told[2]->value());
    }

    /**
     * **An instance declined is answered in words of the server's**, which is
     * the one place it has to be: the component the answer was on is gone, so
     * there is no property to carry. It is built from the one that governs the
     * instance now, so that whatever the organizer wrote about this calendar
     * user travels back with the answer.
     */
    public function testAnInstanceDeclinedKeepsWhatTheOrganizerWroteAboutThem(): void
    {
        $told = $this->theOneTold(
            $this->anObject($this->anEvent('ATTENDEE;CN=Cyrus;PARTSTAT=ACCEPTED:' . self::CYRUS)),
            $this->anObject($this->anEvent(
                'ATTENDEE;CN=Cyrus;PARTSTAT=ACCEPTED:' . self::CYRUS . "\r\nEXDATE:" . self::INSTANCE,
            )),
        );

        self::assertSame('Cyrus', $told[2]->parameter('CN')?->value());
        self::assertSame('DECLINED', $told[2]->parameter('PARTSTAT')?->value());
    }

    /**
     * **The instance an `EXDATE` excluded is named as it was named.** RFC 5545
     * §3.8.5.1 has the values of an `EXDATE` be the start times of the
     * instances left out, and §3.8.4.4 asks the same of a `RECURRENCE-ID`:
     * both mean one moment only together with their `TZID`, so the parameters
     * travel with the value.
     */
    public function testTheInstanceAnExdateExcludedIsNamedAsItWasNamed(): void
    {
        $told = $this->theOneTold(
            $this->anObject($this->anEvent('ATTENDEE:' . self::CYRUS)),
            $this->anObject($this->anEvent(
                'ATTENDEE:' . self::CYRUS . "\r\nEXDATE;TZID=Europe/Berlin:20261102T110000",
            )),
        );

        self::assertSame('RECURRENCE-ID', $told[1]?->name());
        self::assertSame('20261102T110000', $told[1]->value());
        self::assertSame('Europe/Berlin', $told[1]->parameter('TZID')?->value());
    }

    /**
     * **A whole day excluded is named as a whole day.** §3.8.5.1 lets an
     * `EXDATE` carry `VALUE=DATE`, and §3.8.4.4 has it that a `RECURRENCE-ID`
     * "MUST be the same as the value type of the 'DTSTART' property" — so the
     * parameter that says which type this is cannot be left behind.
     */
    public function testAWholeDayExcludedIsNamedAsAWholeDay(): void
    {
        $told = $this->theOneTold(
            $this->anObject($this->anEvent('ATTENDEE:' . self::CYRUS)),
            $this->anObject($this->anEvent(
                'ATTENDEE:' . self::CYRUS . "\r\nEXDATE;VALUE=DATE:20261102",
            )),
        );

        self::assertSame('DATE', $told[1]?->parameter('VALUE')?->value());
    }

    /**
     * **The component the reply is built from is the one that governs the
     * instance**, which is what carries the `UID`, the `ORGANIZER` and the
     * `SEQUENCE` RFC 5546 §3.2.3 asks a `REPLY` for.
     */
    public function testTheComponentIsTheOneGoverningTheInstance(): void
    {
        $told = $this->theOneTold(
            $this->anObject($this->anEvent('ATTENDEE:' . self::CYRUS)),
            $this->anObject(
                $this->anEvent('ATTENDEE:' . self::CYRUS),
                $this->anInstance('ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS . "\r\nSUMMARY:Dinner"),
            ),
        );

        self::assertSame('Dinner', $told[0]->property('SUMMARY')?->value());
    }

    /**
     * **And for an instance excluded it is the one that governed it**, the
     * override being gone: the `EXDATE` sits on the master, and the master is
     * what the instance belonged to.
     */
    public function testTheComponentForAnInstanceExcludedIsTheOneCarryingTheExdate(): void
    {
        $told = $this->theOneTold(
            $this->anObject($this->anEvent('ATTENDEE:' . self::CYRUS)),
            $this->anObject($this->anEvent(
                'ATTENDEE:' . self::CYRUS . "\r\nSUMMARY:Lunch\r\nEXDATE:" . self::INSTANCE,
            )),
        );

        self::assertSame('Lunch', $told[0]->property('SUMMARY')?->value());
    }

    /**
     * **An object with nothing in it tells nobody anything**, which is the
     * same answer {@see \DavServices\CalDav\Schedule\SchedulingObject::in()}
     * gives it: no component is no component carrying an `ATTENDEE`.
     */
    public function testAnObjectWithNothingInItIsNoReply(): void
    {
        $object = CalendarObject::in("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//x//EN\r\nEND:VCALENDAR\r\n")
            ?? self::fail('The fixture is no calendar object.');

        self::assertSame([], $this->whatTheOrganizerIsTold(null, $object));
    }

    /**
     * What the organizer is told, as the answer against the instance it is
     * about, with the empty string for the series itself.
     *
     * @return array<string, string>
     */
    private function whatTheOrganizerIsTold(?Component $original, Component $modified): array
    {
        $told = [];

        foreach (Answer::whatTheOrganizerIsTold($original, $modified, [self::CYRUS]) as [, $named, $attendee]) {
            $told[$named?->value() ?? ''] = (string) $attendee->parameter('PARTSTAT')?->value();
        }

        return $told;
    }

    /**
     * The one record the case expects, for the cases about what is in one.
     *
     * @return array{Component, ?Property, Property}
     */
    private function theOneTold(?Component $original, Component $modified): array
    {
        $told = Answer::whatTheOrganizerIsTold($original, $modified, [self::CYRUS]);

        self::assertCount(1, $told);

        return $told[0] ?? self::fail('Nothing told.');
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
        return "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261102T110000Z\r\n"
            . 'RECURRENCE-ID:' . $id . "\r\nORGANIZER:mailto:bernard@example.com\r\n"
            . ($properties === '' ? '' : $properties . "\r\n")
            . "END:VEVENT\r\n";
    }
}
