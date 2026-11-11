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
use DavServices\CalDav\Schedule\Reply;
use DavServices\VObject\Component;
use DavServices\VObject\Serializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for what a reply does to the organizer's own copy (RFC 6638 §4.2).
 * R-CAL-32, P8-02h.
 *
 * §4.2 is the other direction of §4.1: a `REPLY` has reached the organizer's
 * Inbox, and this is the processing §4 asks of every message that arrives.
 *
 * > The server MUST then update the "PARTSTAT" iCalendar property parameter
 * > value of each "ATTENDEE" iCalendar property in the scheduling object
 * > resource to match the changes indicated in the reply (taking into account
 * > the fact that an "Attendee" could have created a new overridden iCalendar
 * > component to indicate different participation status on one or more
 * > instances of a recurring event).
 * >
 * > The server MUST also update or add the "SCHEDULE-STATUS" property
 * > parameter on each matching "ATTENDEE" iCalendar property and set its value
 * > to that of the "REQUEST-STATUS" property in the reply, or to "2.0" if
 * > "REQUEST-STATUS" is not present (also taking into account recurrence
 * > instances). If there are multiple "REQUEST-STATUS" properties in the
 * > reply, the "SCHEDULE-STATUS" property parameter value is set to a
 * > comma-separated list of status codes, one from each "REQUEST-STATUS"
 * > property.
 *
 * ## The parenthesis is the work
 *
 * A reply carrying a `RECURRENCE-ID` is about one instance, and the
 * organizer's copy need not have a component for it: the series may be a bare
 * `RRULE`. There is then nowhere to write one calendar user's answer that does
 * not answer for every instance — so one is made, which is what RFC 4791 §4.1
 * calls an overridden instance and {@see \DavServices\VObject\Recur\Override}
 * builds.
 *
 * ## One reply answers for one calendar user
 *
 * RFC 5546 §3.2.3's constraints have `ATTENDEE | 1 | MUST be the address of
 * the Attendee replying`, and that row is a fence rather than a formality: a
 * server that read a second one would let one calendar user answer for
 * another on the organizer's copy.
 *
 * ## What is not here
 *
 * **§4.2's last SHOULD**, "send scheduling messages to all the other
 * 'Attendees' indicating the change in participation status of the 'Attendee'
 * replying" — it is "subject to the recurrence requirements of Section 3.2.6",
 * and §3.2.6 is a MUST nothing has read yet: "Servers MUST ensure that
 * 'Attendees' only get information about recurrence instances that explicitly
 * include them as an 'Attendee'". Sending before that is built would keep a
 * SHOULD by breaking a MUST.
 *
 * **RFC 5546 §2.1.5's out-of-order replies.** It asks an organizer's server to
 * "persist the 'SEQUENCE' and 'DTSTAMP' property values associated with the
 * 'Attendee's' last response, so that any earlier responses from an 'Attendee'
 * that are received out of order […] can be correctly discarded" — and
 * iCalendar gives a server no place of its own to keep either. RFC 6638 §4.2
 * asks for neither.
 */
#[CoversClass(Reply::class)]
final class ReplyTest extends TestCase
{
    private const CYRUS = 'mailto:cyrus@example.com';

    private const ERIC = 'mailto:eric@example.com';

    private const INSTANCE = '20261103T100000Z';

    private const ASKED = 'ATTENDEE;PARTSTAT=NEEDS-ACTION:' . self::CYRUS;

    /**
     * **The answer reaches the property of the calendar user who gave it**,
     * which is §4.2's first MUST, and the status that goes with it is `2.0`:
     * "or to '2.0' if 'REQUEST-STATUS' is not present".
     *
     * Nothing else about the resource changes. §4.2 names two parameters on
     * one property, and a server that wrote anything else into an organizer's
     * event would be answering for them.
     */
    public function testTheAnswerReachesThePropertyThatGaveIt(): void
    {
        $object = $this->theEvent(self::ASKED);

        self::assertTrue(Reply::appliedTo($object, $this->theReply('ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS)));
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
            . "ATTENDEE;PARTSTAT=ACCEPTED;SCHEDULE-STATUS=2.0:mailto:cyrus@example.com\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n",
            self::text($object),
        );
    }

    /**
     * **And nobody else's answer is touched.** §4.2 has the server "update the
     * 'PARTSTAT' […] to match the changes indicated in the reply", and RFC
     * 5546 §3.2.3's `ATTENDEE | 1 | MUST be the address of the Attendee
     * replying` is the whole of what one reply indicates.
     */
    public function testNobodyElsesAnswerIsTouched(): void
    {
        $object = $this->theEvent(self::ASKED . "\r\nATTENDEE;PARTSTAT=NEEDS-ACTION:" . self::ERIC);

        Reply::appliedTo($object, $this->theReply('ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS));

        self::assertStringContainsString(
            "ATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:eric@example.com\r\n",
            self::text($object),
        );
    }

    /**
     * **A second `ATTENDEE` in the reply answers for nobody.** RFC 5546
     * §3.2.3: `ATTENDEE | 1 | MUST be the address of the Attendee replying.`
     * One calendar user does not get to say what another answered, and a
     * message that names two has broken the row that says so.
     */
    public function testASecondAttendeeInTheReplyAnswersForNobody(): void
    {
        $object = $this->theEvent(self::ASKED . "\r\nATTENDEE;PARTSTAT=NEEDS-ACTION:" . self::ERIC);

        Reply::appliedTo(
            $object,
            $this->theReply(
                'ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS . "\r\nATTENDEE;PARTSTAT=DECLINED:" . self::ERIC,
            ),
        );

        self::assertStringContainsString(
            "ATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:eric@example.com\r\n",
            self::text($object),
        );
    }

    /**
     * **Every property naming that calendar user is updated**, which is
     * §4.2's own word: "each 'ATTENDEE' iCalendar property in the scheduling
     * object resource". A component that names one person twice is data this
     * server did not write, and leaving one of the two saying something else
     * would leave the answer ambiguous.
     */
    public function testEveryPropertyNamingThatCalendarUserIsUpdated(): void
    {
        $object = $this->theEvent(self::ASKED . "\r\nATTENDEE;ROLE=CHAIR;PARTSTAT=NEEDS-ACTION:" . self::CYRUS);

        Reply::appliedTo($object, $this->theReply('ATTENDEE;PARTSTAT=DECLINED:' . self::CYRUS));

        self::assertSame(
            2,
            substr_count(self::unfolded($object), ";PARTSTAT=DECLINED;SCHEDULE-STATUS=2.0:mailto:cyrus@example.com\r\n"),
        );
    }

    /**
     * **An answer from somebody the resource does not name changes nothing.**
     * RFC 5546 §3.2.3 expects it — "the 'REPLY' method may be received from an
     * unknown CU (a 'Party Crasher')" — and says whose decision it is: "This
     * uninvited 'Attendee' may be accepted, or the 'Organizer' may cancel the
     * event for the uninvited 'Attendee'". The organizer's, which is why §4
     * leaves the message in their Inbox.
     */
    public function testAnAnswerFromSomebodyTheResourceDoesNotNameChangesNothing(): void
    {
        $object = $this->theEvent(self::ASKED);

        self::assertFalse(Reply::appliedTo($object, $this->theReply('ATTENDEE;PARTSTAT=ACCEPTED:' . self::ERIC)));
        self::assertStringContainsString("ATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:cyrus@example.com\r\n", self::text($object));
    }

    /**
     * **An address is matched as it is written.** RFC 5545 §3.1 makes a
     * property value case-sensitive, and whether two spellings name one
     * calendar user is a question only a directory can answer — the same
     * reading {@see \DavServices\CalDav\Schedule\SchedulingObject} makes.
     */
    public function testAnAddressIsMatchedAsItIsWritten(): void
    {
        $object = $this->theEvent(self::ASKED);

        self::assertFalse(
            Reply::appliedTo($object, $this->theReply('ATTENDEE;PARTSTAT=ACCEPTED:MAILTO:CYRUS@EXAMPLE.COM')),
        );
    }

    /**
     * **An answer nobody wrote is the one RFC 5545 §3.2.12 names**:
     * "NEEDS-ACTION […] is the default value". An attendee who takes their
     * answer back has changed it, and the organizer's copy says so rather
     * than keeping the one that is no longer there.
     */
    public function testAnAnswerWithNoParticipationStatusIsTheDefault(): void
    {
        $object = $this->theEvent('ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS);

        Reply::appliedTo($object, $this->theReply('ATTENDEE:' . self::CYRUS));

        self::assertStringContainsString(
            "ATTENDEE;PARTSTAT=NEEDS-ACTION;SCHEDULE-STATUS=2.0:mailto:cyrus@example.com\r\n",
            self::text($object),
        );
    }

    /**
     * **The answer replaces the one that was there and does not join it**, a
     * `PARTSTAT` being one parameter: RFC 5545 §3.2.12 gives it a single value
     * out of an enumeration.
     */
    public function testTheAnswerReplacesTheOneThatWasThere(): void
    {
        $object = $this->theEvent('ATTENDEE;PARTSTAT=TENTATIVE:' . self::CYRUS);

        Reply::appliedTo($object, $this->theReply('ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS));

        $text = self::text($object);

        self::assertStringNotContainsString('TENTATIVE', $text);
        self::assertSame(1, substr_count($text, 'PARTSTAT='));
    }

    /**
     * **And the rest of the invitation is left standing.** `CN`, `ROLE` and
     * `RSVP` are what the organizer wrote when they invited somebody, and
     * §4.2 names two parameters: a server that rebuilt the property would be
     * taking the invitation apart to record the answer to it.
     */
    public function testTheRestOfTheInvitationIsLeftStanding(): void
    {
        $object = $this->theEvent('ATTENDEE;CN=Cyrus;ROLE=REQ-PARTICIPANT;RSVP=TRUE:' . self::CYRUS);

        Reply::appliedTo($object, $this->theReply('ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS));

        self::assertStringContainsString(
            "ATTENDEE;CN=Cyrus;ROLE=REQ-PARTICIPANT;RSVP=TRUE;PARTSTAT=ACCEPTED;SCHEDULE-STATUS=2.0:"
            . "mailto:cyrus@example.com\r\n",
            self::unfolded($object),
        );
    }

    /**
     * **A reply about the series is about the component that defines it**, and
     * about no instance that overrides one: §4.2's parenthesis has each answer
     * belong to the instance it names, and RFC 5546 §3.2.3 has
     * `RECURRENCE-ID | 0 or 1 | Only if referring to an instance of a
     * recurring calendar component. Otherwise, it MUST NOT be present.`
     */
    public function testAReplyAboutTheSeriesLeavesTheInstancesAlone(): void
    {
        $object = $this->anObject($this->anEvent(self::ASKED), $this->anInstance(self::ASKED));

        Reply::appliedTo($object, $this->theReply('ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS));

        $text = self::text($object);

        self::assertSame(1, substr_count($text, 'PARTSTAT=ACCEPTED'));
        self::assertStringContainsString("RECURRENCE-ID:20261103T100000Z\r\n", $text);
        self::assertSame(1, substr_count($text, 'PARTSTAT=NEEDS-ACTION'));
    }

    /**
     * **And a reply about one instance reaches the component that overrides
     * it**, where the organizer's copy has one.
     */
    public function testAReplyAboutAnInstanceReachesTheComponentThatOverridesIt(): void
    {
        $object = $this->anObject($this->anEvent(self::ASKED), $this->anInstance(self::ASKED));

        Reply::appliedTo(
            $object,
            $this->theReply(
                'ATTENDEE;PARTSTAT=DECLINED:' . self::CYRUS,
                'RECURRENCE-ID:' . self::INSTANCE,
            ),
        );

        self::assertSame(
            "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261103T100000Z\r\n"
            . "RECURRENCE-ID:20261103T100000Z\r\nORGANIZER:mailto:bernard@example.com\r\n"
            . "ATTENDEE;PARTSTAT=DECLINED;SCHEDULE-STATUS=2.0:mailto:cyrus@example.com\r\n"
            . "END:VEVENT\r\n",
            self::text($object->components('VEVENT')[1] ?? self::fail('No instance.')),
        );
    }

    /**
     * **An instance with no component of its own gets one.** This is §4.2's
     * parenthesis: "taking into account the fact that an 'Attendee' could have
     * created a new overridden iCalendar component to indicate different
     * participation status on one or more instances of a recurring event".
     *
     * The series itself is left as it stands, which is the point of the
     * exercise: one instance declined is not the series declined.
     */
    public function testAnInstanceWithNoComponentOfItsOwnGetsOne(): void
    {
        $object = $this->theEvent(self::ASKED);

        self::assertTrue(Reply::appliedTo(
            $object,
            $this->theReply('ATTENDEE;PARTSTAT=DECLINED:' . self::CYRUS, 'RECURRENCE-ID:' . self::INSTANCE),
        ));
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
            . "ORGANIZER:mailto:bernard@example.com\r\n"
            . "ATTENDEE;PARTSTAT=DECLINED;SCHEDULE-STATUS=2.0:mailto:cyrus@example.com\r\n"
            . "RECURRENCE-ID:20261103T100000Z\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n",
            self::text($object),
        );
    }

    /**
     * **An instance answered twice gets one component**, the second answer
     * finding the one the first made. Which is what §4.2's "each 'ATTENDEE'
     * iCalendar property in the scheduling object resource" asks for: the
     * resource as it then stands, and not as it arrived.
     */
    public function testAnInstanceAnsweredTwiceGetsOneComponent(): void
    {
        $object = $this->theEvent(self::ASKED);
        $named = 'RECURRENCE-ID:' . self::INSTANCE;

        Reply::appliedTo($object, $this->anObject(
            $this->aReply('ATTENDEE;PARTSTAT=DECLINED:' . self::CYRUS, $named),
            $this->aReply('ATTENDEE;PARTSTAT=TENTATIVE:' . self::CYRUS, $named),
        ));

        $text = self::text($object);

        self::assertSame(1, substr_count($text, 'RECURRENCE-ID'));
        self::assertStringContainsString('PARTSTAT=TENTATIVE', $text);
        self::assertStringNotContainsString('DECLINED', $text);
    }

    /**
     * **An instance that names nobody gets no component either.** §3.2.6 has
     * the case: "an 'Attendee' could be excluded from one instance of a
     * recurring event", and the organizer's copy then has an overridden
     * instance whose attendee list leaves them out. A component created for an
     * answer there is nowhere to write would be a change to the series that
     * says nothing.
     */
    public function testAnInstanceThatNamesNobodyGetsNoComponent(): void
    {
        $object = $this->theEvent('ATTENDEE;PARTSTAT=ACCEPTED:' . self::ERIC);

        self::assertFalse(Reply::appliedTo(
            $object,
            $this->theReply('ATTENDEE;PARTSTAT=DECLINED:' . self::CYRUS, 'RECURRENCE-ID:' . self::INSTANCE),
        ));
        self::assertSame(1, substr_count(self::text($object), 'BEGIN:VEVENT'));
    }

    /**
     * **An instance of a series that cannot be read gets none as well.** RFC
     * 5545 §3.8.4.4 ties a `RECURRENCE-ID` to the `DTSTART` of the component
     * it overrides, and where the two cannot be read as the same kind of value
     * there is no instance to make a component for.
     */
    public function testAnInstanceOfASeriesThatCannotBeReadGetsNone(): void
    {
        $object = $this->anObject(
            "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:whenever\r\n"
            . 'ORGANIZER:mailto:bernard@example.com' . "\r\n" . self::ASKED . "\r\nEND:VEVENT\r\n",
        );

        self::assertFalse(Reply::appliedTo(
            $object,
            $this->theReply('ATTENDEE;PARTSTAT=DECLINED:' . self::CYRUS, 'RECURRENCE-ID:' . self::INSTANCE),
        ));
        self::assertSame(1, substr_count(self::text($object), 'BEGIN:VEVENT'));
    }

    /**
     * **A resource with no master is no series to answer about**, however the
     * reply names what it answers. RFC 4791 §4.1 lets a resource hold
     * overridden instances alone, and one of those is not what a reply naming
     * nothing is about.
     *
     * @param string $named The `RECURRENCE-ID` line the reply carries, or the
     *                      empty string for a reply about the series
     */
    #[DataProvider('theRepliesThatFindNothing')]
    public function testAResourceWithNoMasterIsNoSeriesToAnswerAbout(string $named): void
    {
        $object = $this->anObject($this->anInstance(self::ASKED, '20261104T100000Z'));

        self::assertFalse(
            Reply::appliedTo($object, $this->theReply('ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS, $named)),
        );
        self::assertStringContainsString("ATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:cyrus@example.com\r\n", self::text($object));
    }

    /**
     * Both ways of naming what is answered, because the master is what either
     * one needs: the series is the master itself, and an instance with no
     * component of its own is made out of it.
     *
     * @return iterable<string, array{string}>
     */
    public static function theRepliesThatFindNothing(): iterable
    {
        yield 'about the series' => [''];
        yield 'about one instance' => ['RECURRENCE-ID:' . self::INSTANCE];
    }

    /**
     * **A component of the reply that answers nothing is passed over, and the
     * next one is still read.** RFC 5546 §3.2.3's `ATTENDEE | 1` says what a
     * component without one is: a component that is no answer.
     */
    public function testAComponentThatAnswersNothingIsPassedOverAndTheNextIsStillRead(): void
    {
        $object = $this->theEvent(self::ASKED);

        self::assertTrue(Reply::appliedTo($object, $this->anObject(
            "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\n"
            . 'ORGANIZER:mailto:bernard@example.com' . "\r\nEND:VEVENT\r\n",
            $this->aReply('ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS),
        )));
        self::assertStringContainsString('PARTSTAT=ACCEPTED', self::text($object));
    }

    /**
     * **And one whose answer reaches nobody is passed over too.** The first
     * component here names an instance the resource has nobody on, and the
     * second is about the series everybody is on.
     */
    public function testAComponentWhoseAnswerReachesNobodyIsPassedOverToo(): void
    {
        $object = $this->anObject(
            $this->anEvent(self::ASKED),
            $this->anInstance('ATTENDEE;PARTSTAT=ACCEPTED:' . self::ERIC),
        );

        self::assertTrue(Reply::appliedTo($object, $this->anObject(
            $this->aReply('ATTENDEE;PARTSTAT=DECLINED:' . self::CYRUS, 'RECURRENCE-ID:' . self::INSTANCE),
            $this->aReply('ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS),
        )));

        $text = self::text($object);

        self::assertSame(1, substr_count($text, 'PARTSTAT=ACCEPTED;SCHEDULE-STATUS=2.0:mailto:cyrus@example.com'));
        self::assertStringNotContainsString('DECLINED', $text);
    }

    /**
     * **A zone is no answer.** RFC 5546 §3.2.3 has `VTIMEZONE | 0 or 1 | MUST
     * be present if any date/time refers to a timezone`, so a reply may carry
     * one — and RFC 5545 §3.6.5 lists what one may hold, where `ATTENDEE` is
     * not among them. The same reading {@see \DavServices\CalDav\Schedule\Instances}
     * makes of every object it takes apart.
     */
    public function testAZoneIsNoAnswer(): void
    {
        $object = $this->theEvent(self::ASKED);

        self::assertFalse(Reply::appliedTo($object, $this->anObject(
            "BEGIN:VTIMEZONE\r\nTZID:Europe/Berlin\r\n"
            . 'ATTENDEE;PARTSTAT=DECLINED:' . self::CYRUS . "\r\nEND:VTIMEZONE\r\n",
        )));
        self::assertStringContainsString("ATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:cyrus@example.com\r\n", self::text($object));
    }

    /**
     * **The status is the one the reply reports**, which §4.2 asks for: "set
     * its value to that of the 'REQUEST-STATUS' property in the reply". The
     * code alone, §7.3's own grammar having `statcode` where RFC 5545 §3.8.8.3
     * gives the property a code, a description and whatever data goes with it.
     */
    public function testTheStatusIsTheOneTheReplyReports(): void
    {
        $object = $this->theEvent(self::ASKED);

        Reply::appliedTo($object, $this->theReply(
            'ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS . "\r\n"
            . 'REQUEST-STATUS:2.8;Success\, repeating event ignored.;RRULE',
        ));

        self::assertStringContainsString(
            "ATTENDEE;PARTSTAT=ACCEPTED;SCHEDULE-STATUS=2.8:mailto:cyrus@example.com\r\n",
            self::text($object),
        );
    }

    /**
     * **Several of them are one comma-separated value**, which is what §4.2
     * asks for and §7.3's grammar makes one value rather than several:
     *
     *     schedulestatusparam = "SCHEDULE-STATUS" "="
     *                         ( statcode
     *                        / DQUOTE statcode *("," statcode) DQUOTE)
     *
     * So the list is written inside the quotes, and §7.3's own example has it:
     * `ATTENDEE;SCHEDULE-STATUS="2.0,2.4":mailto:cyrus@example.com`.
     */
    public function testSeveralStatusesAreOneQuotedValue(): void
    {
        $object = $this->theEvent(self::ASKED);

        Reply::appliedTo($object, $this->theReply(
            'ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS . "\r\n"
            . "REQUEST-STATUS:2.0;Success\r\nREQUEST-STATUS:2.4;Success\, no action taken.",
        ));

        self::assertStringContainsString(
            "ATTENDEE;PARTSTAT=ACCEPTED;SCHEDULE-STATUS=\"2.0,2.4\":mailto:cyrus@example.com\r\n",
            self::unfolded($object),
        );
    }

    /**
     * **And each instance carries the status its own component reported**,
     * §4.2 saying so in the same breath as the parameter: "(also taking into
     * account recurrence instances)".
     */
    public function testEachInstanceCarriesTheStatusItsOwnComponentReported(): void
    {
        $object = $this->anObject($this->anEvent(self::ASKED), $this->anInstance(self::ASKED));

        Reply::appliedTo($object, $this->anObject(
            $this->aReply('ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS . "\r\nREQUEST-STATUS:2.0;Success"),
            $this->aReply(
                'ATTENDEE;PARTSTAT=DECLINED:' . self::CYRUS . "\r\nREQUEST-STATUS:2.8;Success",
                'RECURRENCE-ID:' . self::INSTANCE,
            ),
        ));

        $text = self::text($object);

        self::assertStringContainsString('PARTSTAT=ACCEPTED;SCHEDULE-STATUS=2.0:', $text);
        self::assertStringContainsString('PARTSTAT=DECLINED;SCHEDULE-STATUS=2.8:', $text);
    }

    /**
     * **The status replaces the one the delivery left**, which is the "update
     * or add" of §4.2 and the two kinds of status §3.2.9 describes: a delivery
     * status says what came of sending the invitation, and a reply status what
     * came of the answer. §7.3 keeps one parameter for both — "the status
     * codes returned from processing of the most recent scheduling message
     * sent to the corresponding 'Attendee'" — and the reply is what came of
     * that message.
     */
    public function testTheStatusReplacesTheOneTheDeliveryLeft(): void
    {
        $object = $this->theEvent('ATTENDEE;PARTSTAT=NEEDS-ACTION;SCHEDULE-STATUS=1.2:' . self::CYRUS);

        Reply::appliedTo($object, $this->theReply('ATTENDEE;PARTSTAT=ACCEPTED:' . self::CYRUS));

        $text = self::text($object);

        self::assertStringNotContainsString('1.2', $text);
        self::assertSame(1, substr_count($text, 'SCHEDULE-STATUS='));
    }

    /**
     * **A reply about nothing changes nothing**, which is the message RFC 5546
     * §3.2.3 does not allow — `VEVENT | 1+` — and the answer a server has for
     * one anyway.
     */
    public function testAReplyAboutNothingChangesNothing(): void
    {
        $object = $this->theEvent(self::ASKED);

        self::assertFalse(Reply::appliedTo($object, $this->anObject()));
    }

    /**
     * The organizer's own copy, as one component of a daily series.
     */
    private function theEvent(string $properties): Component
    {
        return $this->anObject($this->anEvent($properties));
    }

    /**
     * A reply about that, as a message of one component.
     *
     * @param string $named The `RECURRENCE-ID` line, or the empty string for a
     *                      reply about the series
     */
    private function theReply(string $properties, string $named = ''): Component
    {
        return $this->anObject($this->aReply($properties, $named));
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
     * The master of the organizer's copy, carrying those properties.
     */
    private function anEvent(string $properties): string
    {
        return "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261101T100000Z\r\n"
            . "RRULE:FREQ=DAILY;COUNT=5\r\nORGANIZER:mailto:bernard@example.com\r\n"
            . $properties . "\r\nEND:VEVENT\r\n";
    }

    /**
     * A component of theirs that overrides one instance.
     */
    private function anInstance(string $properties, string $id = self::INSTANCE): string
    {
        return "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:" . $id . "\r\n"
            . 'RECURRENCE-ID:' . $id . "\r\nORGANIZER:mailto:bernard@example.com\r\n"
            . $properties . "\r\nEND:VEVENT\r\n";
    }

    /**
     * One component of a reply, as RFC 5546 §3.2.3's constraints have it.
     */
    private function aReply(string $properties, string $named = ''): string
    {
        return "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261105T080000Z\r\n"
            . ($named === '' ? '' : $named . "\r\n")
            . 'ORGANIZER:mailto:bernard@example.com' . "\r\n"
            . $properties . "\r\nEND:VEVENT\r\n";
    }

    /**
     * The same, with RFC 5545 §3.1's folding undone.
     *
     * A case about what one property carries reads the line whole: the
     * serialiser breaks one longer than "75 octets" where it likes, which is
     * its own to get right ({@see \DavServices\VObject\Serializer}) and
     * nothing this is about.
     */
    private static function unfolded(Component $component): string
    {
        return str_replace("\r\n ", '', self::text($component));
    }

    /**
     * What that component or object says now.
     */
    private static function text(Component $component): string
    {
        return (new Serializer())->write($component);
    }
}
