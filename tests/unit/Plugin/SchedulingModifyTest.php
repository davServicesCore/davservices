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

namespace DavServices\Tests\Unit\Plugin;

use DavServices\Acl\Principal;
use DavServices\Backend\ICalendarUserBackend;
use DavServices\CalDav\Calendar;
use DavServices\CalDav\Schedule\Inbox;
use DavServices\Dav\Acl\PrincipalInfo;
use DavServices\Dav\Event\BeforeWriteContent;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\ICollection;
use DavServices\Dav\IFile;
use DavServices\Dav\Method\Put;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Http\Body;
use DavServices\Http\Request;
use DavServices\Plugin\Scheduling;
use DavServices\Tests\Unit\Backend\MemoryCalendarUserBackend;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Uri\Path;
use DavServices\Xml\Element;
use DavServices\Xml\Writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for what an organizer's change sends (RFC 6638 §3.2.1.2 and the
 * three rows of §3.2.3.1's table that need the old content). R-CAL-32, P8-02f.
 *
 * ## §3.2.3.1 decides which operation a `PUT` is, and only now can it
 *
 * > +------------------------+--------------------------+---------------+
 * > | Existing Destination   | Resulting Destination    | Server        |
 * > | Resource               | Resource                 | Operation     |
 * > +------------------------+--------------------------+---------------+
 * > | None                   | Calendar object resource | None          |
 * > | None                   | Scheduling object        | Create        |
 * > | Calendar object        | Calendar object resource | None          |
 * > | resource               |                          |               |
 * > | Calendar object        | Scheduling object        | Create        |
 * > | resource               | resource                 |               |
 * > | Scheduling object      | Calendar object resource | Remove        |
 * > | resource               |                          |               |
 * > | Scheduling object      | Scheduling object        | Modify        |
 * > | resource               | resource                 |               |
 * > +------------------------+--------------------------+---------------+
 *
 * The first two rows are a creation and were already here. The four below them
 * all turn on what was there before, which is why they arrive together: a
 * `PUT` over something is read twice, once as it stands and once as it is
 * about to be.
 *
 * **"Scheduling object resource" is either kind** (§3.1), so whose it is picks
 * the section: §3.2.1's operations are the organizer's, §3.2.2's the
 * attendee's. An attendee's own copy being changed is §3.2.2.3 and is not here
 * yet, and so is a resource whose `ORGANIZER` changes from the owner to
 * somebody else — §3.2.3.1 calls that a Modify, and RFC 5546 §3.2.2.4 calls it
 * "Changing the Organizer".
 *
 * ## Two messages, in one order
 *
 * A modification can send one calendar user both: a `CANCEL` for an instance
 * they are off and a `REQUEST` for the series they are still on. The
 * cancellation goes first, because §4 has each message update their own copy
 * as it arrives and the modified data is what they should be left with.
 *
 * That also settles which status is written on the organizer's resource. §7.3
 * asks for "the status codes returned from processing of the most recent
 * scheduling message sent to the corresponding 'Attendee'", and the most
 * recent is the second one sent.
 */
#[CoversClass(Scheduling::class)]
final class SchedulingModifyTest extends TestCase
{
    private const NS = 'urn:ietf:params:xml:ns:caldav';

    private const HOME_SET = '{' . self::NS . '}calendar-home-set';

    private const ADDRESS_SET = '{' . self::NS . '}calendar-user-address-set';

    private const OWNER = '{DAV:}owner';

    private const HREF = '{DAV:}href';

    private const EVENT = 'calendars/bernard/work/event.ics';

    private const ORGANISED = 'ORGANIZER:mailto:bernard@example.com';

    private const CYRUS = 'ATTENDEE:mailto:cyrus@example.com';

    private const ERIC = 'ATTENDEE:mailto:eric@example.com';

    private const ACCEPTED = 'ATTENDEE;PARTSTAT=ACCEPTED:mailto:cyrus@example.com';

    private const RESET = 'ATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:cyrus@example.com';

    private const ADDRESSES = [
        'bernard' => ['mailto:bernard@example.com'],
        'cyrus' => ['mailto:cyrus@example.com'],
        'eric' => ['mailto:eric@example.com'],
    ];

    private ?Tree $tree = null;

    private ?Server $server = null;

    /** @var array<string, MemoryCollection> */
    private array $stores = [];

    /**
     * **The Modify row: the attendee is told again.** §3.2.1.2's `SERVER →
     * SERVER` cell reads `REQUEST`, and RFC 5546 §3.2.2 names that use of one
     * among the first: "Update the details of an existing event, without
     * rescheduling it."
     */
    public function testAModificationTellsTheAttendeeAgain(): void
    {
        $this->given($this->anEvent(self::ORGANISED, self::CYRUS));
        $this->put($this->anEvent(self::ORGANISED, self::CYRUS, 'SUMMARY:Lunch'));

        $message = $this->theOneResourceIn('calendars/cyrus/inbox');

        self::assertStringContainsString("METHOD:REQUEST\r\n", $message);
        self::assertStringContainsString("SUMMARY:Lunch\r\n", $message);
    }

    /**
     * **And the resource says what came of it.** §3.2.1.2: "'SCHEDULE-STATUS'
     * iCalendar property parameters are added or changed on 'ATTENDEE'
     * iCalendar properties in the scheduling object resource being modified as
     * described in Section 7.3, with the value set as described in Section
     * 3.2.9."
     */
    public function testTheModifiedResourceCarriesWhatCameOfIt(): void
    {
        $this->given($this->anEvent(self::ORGANISED, self::CYRUS));
        $this->put($this->anEvent(self::ORGANISED, self::CYRUS, 'SUMMARY:Lunch'));

        self::assertStringContainsString(
            'ATTENDEE;SCHEDULE-STATUS=1.2:mailto:cyrus@example.com',
            $this->theStoredObject(self::EVENT),
        );
    }

    /**
     * **A forced request asks for what happens anyway.** §3.2.1.2's table has
     * one cell for an attendee this server delivers for — `SERVER` in both
     * columns — and that cell is `REQUEST` with no condition on it. So §3.2.7's
     * "situation where the server would not normally send a scheduling message"
     * does not arise on this side, the parameter changes nothing about what is
     * sent, and nothing is reported: `REQUEST` on an `ATTENDEE` is the one
     * value §7.2 pairs with that property.
     *
     * What it does change is the resource, and the message built out of it:
     * §7.2 keeps the parameter out of both.
     */
    public function testAForcedRequestAsksForWhatHappensAnyway(): void
    {
        $this->given($this->anEvent(self::ORGANISED, self::ACCEPTED));

        $this->put($this->anEvent(
            self::ORGANISED,
            'ATTENDEE;PARTSTAT=ACCEPTED;SCHEDULE-FORCE-SEND=REQUEST:mailto:cyrus@example.com',
        ));

        $stored = self::unfolded($this->theStoredObject(self::EVENT));

        self::assertStringContainsString(
            'ATTENDEE;PARTSTAT=ACCEPTED;SCHEDULE-STATUS=1.2:mailto:cyrus@example.com',
            $stored,
        );
        self::assertStringNotContainsString('SCHEDULE-FORCE-SEND', $stored);
        self::assertStringNotContainsString(
            'SCHEDULE-FORCE-SEND',
            self::unfolded($this->theOneResourceIn('calendars/cyrus/inbox')),
        );
    }

    /**
     * **And a value this server cannot read is reported beside what came of
     * the delivery.** §7.2's last MUST, on the side where the message goes out
     * regardless — so the one value §7.3 writes holds both of §3.2.9's kinds
     * of status, which is what its grammar's comma-separated list is for.
     */
    public function testAValueTheServerCannotReadIsReportedBesideTheDelivery(): void
    {
        $this->given($this->anEvent(self::ORGANISED, self::ACCEPTED));

        $this->put($this->anEvent(
            self::ORGANISED,
            'ATTENDEE;PARTSTAT=ACCEPTED;SCHEDULE-FORCE-SEND=PUBLISH:mailto:cyrus@example.com',
        ));

        self::assertStringContainsString(
            'ATTENDEE;PARTSTAT=ACCEPTED;SCHEDULE-STATUS="2.3,1.2":mailto:cyrus@example.com',
            self::unfolded($this->theStoredObject(self::EVENT)),
        );
    }

    /**
     * **The organizer's own property forces nothing and is stamped with
     * nothing.** §3.2.1: "The 'Organizer' of a calendar component can also be
     * an 'Attendee' of that calendar component. In such cases, the server MUST
     * NOT send a scheduling message to the 'Attendee' that matches the
     * 'Organizer'" — and §7.2's MAY cannot overrule that MUST NOT. §7.3 says
     * the rest: the parameter it would be reported in may only be written on
     * calendar users "who were sent a scheduling message".
     */
    public function testTheOrganizersOwnPropertyForcesNothingAndIsNotStamped(): void
    {
        $this->given($this->anEvent(self::ORGANISED, self::ACCEPTED));

        $this->put($this->anEvent(
            self::ORGANISED,
            'ATTENDEE;SCHEDULE-FORCE-SEND=PUBLISH:mailto:bernard@example.com',
            self::ACCEPTED,
        ));

        $stored = self::unfolded($this->theStoredObject(self::EVENT));

        self::assertStringContainsString('ATTENDEE:mailto:bernard@example.com', $stored);
        self::assertStringNotContainsString('SCHEDULE-FORCE-SEND', $stored);
    }

    /**
     * **An attendee the client schedules for is neither told nor reported
     * to.** §7.2 permits the parameter only where the `SCHEDULE-AGENT` "is set
     * to the value 'SERVER' or is not specified", and §3.2.1.2's table has
     * nothing sent to such an attendee — so a 2.3 on them would be the server
     * reporting on a message it was never going to send. The parameter goes
     * all the same.
     */
    public function testAnAttendeeTheClientSchedulesForIsNeitherToldNorReportedTo(): void
    {
        $this->given($this->anEvent(self::ORGANISED, self::ACCEPTED));

        $this->put($this->anEvent(
            self::ORGANISED,
            self::ACCEPTED,
            'ATTENDEE;SCHEDULE-AGENT=CLIENT;SCHEDULE-FORCE-SEND=PUBLISH:mailto:eric@example.com',
        ));

        $stored = self::unfolded($this->theStoredObject(self::EVENT));

        self::assertStringContainsString(
            'ATTENDEE;SCHEDULE-AGENT=CLIENT:mailto:eric@example.com',
            $stored,
        );
        self::assertStringNotContainsString('SCHEDULE-FORCE-SEND', $stored);
        self::assertSame([], $this->namesIn('calendars/eric/inbox'));
    }

    /**
     * **A calendar object that is nobody's scheduling object keeps what
     * arrived.** §7.2's MUST NOT is written of "scheduling object resources",
     * and §3.1 makes a resource one by the `ORGANIZER` and `ATTENDEE`
     * properties it carries — so a body with neither is the other row of
     * §3.2.3.1's table, and tidying it would be the server editing a resource
     * it has no part in.
     */
    public function testACalendarObjectThatIsNobodysSchedulingObjectKeepsWhatArrived(): void
    {
        $this->given($this->anEvent('SUMMARY:Lunch'));

        $this->put($this->anEvent('SUMMARY:Lunch', 'LOCATION;SCHEDULE-FORCE-SEND=REQUEST:Bakery'));

        self::assertStringContainsString(
            'LOCATION;SCHEDULE-FORCE-SEND=REQUEST:Bakery',
            self::unfolded($this->theStoredObject(self::EVENT)),
        );
    }

    /**
     * **A meeting that moves takes the answers with it.** RFC 6638 §3.2.8:
     * "Servers MUST reset the 'PARTSTAT' property parameter value of all
     * 'ATTENDEE' properties, except the one that corresponds to the
     * 'Organizer', to 'NEEDS-ACTION' for each calendar component change that
     * causes any instance to be rescheduled." The table's first row has it of
     * a `DTSTART`: "Any change to these properties".
     */
    public function testAMeetingThatMovesTakesTheAnswersWithIt(): void
    {
        $this->given($this->anEvent(self::ORGANISED, self::ACCEPTED));
        $this->put($this->anEventAt('20261101T140000Z', self::ORGANISED, self::ACCEPTED));

        self::assertStringContainsString(
            'ATTENDEE;PARTSTAT=NEEDS-ACTION;SCHEDULE-STATUS=1.2:mailto:cyrus@example.com',
            self::unfolded($this->theStoredObject(self::EVENT)),
        );
    }

    /**
     * **And the invitation says so, because the answer is taken back before it
     * is built.** An ordering with a reason: the message §3.2.1.2 sends is made
     * out of the resource (§3.2.6), so an attendee told afterwards would be
     * looking at an invitation that still carried the answer §3.2.8 has just
     * decided no longer stands.
     */
    public function testTheInvitationCarriesTheAnswerAlreadyTakenBack(): void
    {
        $this->given($this->anEvent(self::ORGANISED, self::ACCEPTED));
        $this->put($this->anEventAt('20261101T140000Z', self::ORGANISED, self::ACCEPTED));

        $message = self::unfolded($this->theOneResourceIn('calendars/cyrus/inbox'));

        self::assertStringContainsString("METHOD:REQUEST\r\n", $message);
        self::assertStringContainsString('PARTSTAT=NEEDS-ACTION', $message);
        self::assertStringNotContainsString('PARTSTAT=ACCEPTED', $message);
    }

    /**
     * **A modification that moves nothing leaves the answer where it is.**
     * §3.2.8 defines a reschedule as a change to one of seven properties, and
     * a summary is none of them — an organizer who wants the answers back for
     * another reason has that section's own last sentence, which is a MAY and
     * is the client's to use.
     */
    public function testAModificationThatMovesNothingLeavesTheAnswerStanding(): void
    {
        $this->given($this->anEvent(self::ORGANISED, self::ACCEPTED));
        $this->put($this->anEvent(self::ORGANISED, self::ACCEPTED, 'SUMMARY:Lunch'));

        self::assertStringContainsString(
            'ATTENDEE;PARTSTAT=ACCEPTED;SCHEDULE-STATUS=1.2:mailto:cyrus@example.com',
            self::unfolded($this->theStoredObject(self::EVENT)),
        );
    }

    /**
     * **An answer taken back is stored although no message went out.**
     *
     * This asks the one case where the two questions come apart, and the
     * fixture that reaches it is **invalid iCalendar on purpose**: §7.1 has
     * "The parameter value MUST be the same on each 'ATTENDEE' property whose
     * values match in a scheduling object resource", and here cyrus is named
     * twice, once with `SCHEDULE-AGENT=CLIENT` and once without.
     *
     * {@see Modification::whatEachCalendarUserIsTold()} reads such a resource
     * by the sentence it broke — the first property naming a calendar user
     * answers for all of them — so nothing is sent and nothing is stamped. Nor
     * is anything refused, both properties carrying the answer the resource
     * already had. §3.2.8 is written of the property rather than of the
     * calendar user: "when the 'SCHEDULE-AGENT' property parameter **on the
     * corresponding 'ATTENDEE' property** is set to the value 'SERVER' or is
     * not present". So the second property is reset, the first is left where it
     * stands, and the resource has to be stored as that left it — a stamp is
     * not what the write back is for.
     */
    public function testAnAnswerTakenBackIsStoredAlthoughNothingWasSent(): void
    {
        $theirs = 'ATTENDEE;SCHEDULE-AGENT=CLIENT;PARTSTAT=ACCEPTED:mailto:cyrus@example.com';
        $both = $theirs . "\r\n" . self::ACCEPTED;
        $this->given($this->anEvent(self::ORGANISED, $both));
        $this->put($this->anEventAt('20261101T140000Z', self::ORGANISED, $both));
        $stored = self::unfolded($this->theStoredObject(self::EVENT));

        self::assertSame([], $this->namesIn('calendars/cyrus/inbox'));
        self::assertStringContainsString("DTSTART:20261101T140000Z\r\n", $stored);
        self::assertStringContainsString(self::RESET, $stored);
        self::assertStringContainsString($theirs, $stored);
    }

    /**
     * **The `<Removed>` cell: an attendee taken off the list is uninvited**,
     * and the message is RFC 5546 §4.2.10's rather than §3.2.1.3's — no
     * `STATUS`, because the meeting itself is not cancelled.
     */
    public function testAnAttendeeTakenOffTheListIsUninvited(): void
    {
        $this->given($this->anEvent(self::ORGANISED, self::CYRUS, self::ERIC));
        $this->put($this->anEvent(self::ORGANISED, self::ERIC));

        $message = $this->theOneResourceIn('calendars/cyrus/inbox');

        self::assertStringContainsString("METHOD:CANCEL\r\n", $message);
        self::assertStringContainsString("ATTENDEE:mailto:cyrus@example.com\r\n", $message);
        self::assertStringNotContainsString('STATUS', $message);
    }

    /**
     * **And whoever is still coming is told the new state of it**, which is
     * what RFC 5546 §4.2.10 has the organizer do next: "'A' optionally sends
     * the updated event to the remaining 'Attendees'." Here it is not optional
     * — §3.2.1.2's `SERVER → SERVER` cell reads `REQUEST` whatever else
     * changed.
     */
    public function testWhoeverIsStillComingIsToldTheNewState(): void
    {
        $this->given($this->anEvent(self::ORGANISED, self::CYRUS, self::ERIC));
        $this->put($this->anEvent(self::ORGANISED, self::ERIC));

        $message = $this->theOneResourceIn('calendars/eric/inbox');

        self::assertStringContainsString("METHOD:REQUEST\r\n", $message);
        self::assertStringNotContainsString('cyrus', $message);
    }

    /**
     * **Nothing is stamped for an attendee who is gone**, because there is no
     * property of theirs left to stamp — which is §3.2.1.1's MUST NOT met by
     * the resource itself: "Servers MUST NOT set the 'SCHEDULE-STATUS' property
     * parameter on the 'ATTENDEE' property of 'Attendees' for which it did not
     * attempt to deliver a scheduling message."
     */
    public function testTheResourceSaysNothingOfAnAttendeeItNoLongerHas(): void
    {
        $this->given($this->anEvent(self::ORGANISED, self::CYRUS, self::ERIC));
        $this->put($this->anEvent(self::ORGANISED, self::ERIC));

        $stored = $this->theStoredObject(self::EVENT);

        self::assertStringNotContainsString('cyrus', $stored);
        self::assertStringContainsString('ATTENDEE;SCHEDULE-STATUS=1.2:mailto:eric@example.com', $stored);
    }

    /**
     * **An uninvitation leaves their own copy where it is.** §4 processes a
     * message by "creating or updating the corresponding scheduling object
     * resources", and neither is what an uninvitation asks for: there is
     * nothing to create, and putting this message's shape in place of their
     * event would leave them a stump. The notice in their Inbox is what §4 puts
     * one there for.
     */
    public function testAnUninvitedAttendeeKeepsTheirOwnCopyUntouched(): void
    {
        $theirs = $this->anEvent(self::ORGANISED, self::CYRUS);

        $this->given($this->anEvent(self::ORGANISED, self::CYRUS));
        $this->inTheCalendar('calendars/cyrus/work', 'theirs.ics', $theirs);
        $this->put($this->anEvent(self::ORGANISED));

        self::assertSame($theirs, $this->theStoredObject('calendars/cyrus/work/theirs.ics'));
    }

    /**
     * **One instance lost and the series kept sends both messages, in that
     * order.** The cancellation names the instance (§3.2.5's second form) and
     * the request carries the modified resource as far as it concerns them
     * (§3.2.6), so what the attendee is left with is what the organizer's
     * resource says about them.
     */
    public function testAnInstanceLostAndASeriesKeptSendsBothInThatOrder(): void
    {
        $this->given($this->aSeries(self::CYRUS, self::CYRUS));
        $this->put($this->aSeries(self::CYRUS, ''));

        $messages = $this->theResourcesIn('calendars/cyrus/inbox');

        self::assertCount(2, $messages);
        self::assertStringContainsString("METHOD:CANCEL\r\n", $messages[0] ?? '');
        self::assertStringContainsString("RECURRENCE-ID:20261102T100000Z\r\n", $messages[0] ?? '');
        self::assertStringContainsString("METHOD:REQUEST\r\n", $messages[1] ?? '');
    }

    /**
     * **And their own copy is left as the modification left the resource, as
     * far as it concerns them**, which is the whole reason the cancellation
     * goes first: the request is what §4.1 has update their resource "to
     * reflect the changes sent by the 'Organizer'", and it is the second of the
     * two to arrive.
     *
     * **One component and not two**, which is §3.2.6: the instance they were
     * taken off is not theirs to be told about, and the `EXDATE` in the master
     * is what keeps the `RRULE` from putting it back in front of them.
     */
    public function testTheirOwnCopyEndsAsTheModifiedResourceConcernsThem(): void
    {
        $this->given($this->aSeries(self::CYRUS, self::CYRUS));
        $this->inTheCalendar('calendars/cyrus/work', 'theirs.ics', $this->aSeries(self::CYRUS, self::CYRUS));
        $this->put($this->aSeries(self::CYRUS, ''));

        $theirs = $this->theStoredObject('calendars/cyrus/work/theirs.ics');

        self::assertSame(1, substr_count($theirs, 'BEGIN:VEVENT'));
        self::assertSame(1, substr_count($theirs, 'cyrus'));
        self::assertStringContainsString("EXDATE:20261102T100000Z\r\n", $theirs);
    }

    /**
     * **The status written is the last message's**, which is §7.3's own word:
     * "the status codes returned from processing of the **most recent**
     * scheduling message sent to the corresponding 'Attendee'".
     *
     * Eric has an Inbox and no calendar, which §4.3 answers with `5.3` — "If
     * there is no valid calendar collection, then the server MUST reject the
     * attempt to deliver the scheduling message to the 'Attendee'." The
     * uninvitation needs no calendar of his and arrives; the request needs one
     * and is rejected, so it never reaches his Inbox either — §4.1: "the
     * scheduling message MUST only appear in the 'Attendee's' scheduling Inbox
     * collection once all automatic processing has been done."
     *
     * So the two statuses differ, and the one written is the request's although
     * the request is the one that got nowhere.
     */
    public function testTheStatusWrittenIsTheLastMessageSent(): void
    {
        $this->given($this->aSeries(self::ERIC, self::ERIC), calendarForEric: false);
        $this->put($this->aSeries(self::ERIC, ''));

        self::assertCount(1, $this->namesIn('calendars/eric/inbox'));
        self::assertStringContainsString(
            'ATTENDEE;SCHEDULE-STATUS=5.3:mailto:eric@example.com',
            $this->theStoredObject(self::EVENT),
        );
    }

    /**
     * **The Remove row: a resource that stops being the organizer's cancels
     * the event.** §3.2.3.1 reads "Scheduling object resource → Calendar object
     * resource: Remove", and §3.2.1.3's Remove is the whole-event `CANCEL` with
     * `STATUS:CANCELLED` — the attendees have nothing left either way.
     */
    public function testAResourceThatStopsBeingTheOrganizersCancelsTheEvent(): void
    {
        $this->given($this->anEvent(self::ORGANISED, self::CYRUS));
        $this->put($this->anEvent('', self::CYRUS));

        $message = $this->theOneResourceIn('calendars/cyrus/inbox');

        self::assertStringContainsString("METHOD:CANCEL\r\n", $message);
        self::assertStringContainsString("STATUS:CANCELLED\r\n", $message);
    }

    /**
     * **And nothing is written on it**, which is the one way §3.2.1.3 differs
     * from the other two operations: it asks for no `SCHEDULE-STATUS`, and what
     * the resource has become is no scheduling object resource to carry one.
     */
    public function testARemovalByOverwritingWritesNothingOnTheResource(): void
    {
        $body = $this->anEvent('', self::CYRUS);

        $this->given($this->anEvent(self::ORGANISED, self::CYRUS));
        $this->put($body);

        self::assertSame($body, $this->theStoredObject(self::EVENT));
    }

    /**
     * **The second Create row: a resource that becomes the organizer's invites
     * everybody on it.** §3.2.3.1 reads "Calendar object resource → Scheduling
     * object resource: Create", and §3.2.1.1's table is §3.2.1.2's `<Absent>`
     * row: there was no organizer's data to compare against, so every attendee
     * is new.
     */
    public function testAResourceThatBecomesTheOrganizersInvitesEverybodyOnIt(): void
    {
        $this->given($this->anEvent('', self::CYRUS));
        $this->put($this->anEvent(self::ORGANISED, self::CYRUS));

        self::assertStringContainsString("METHOD:REQUEST\r\n", $this->theOneResourceIn('calendars/cyrus/inbox'));
    }

    /**
     * **And one that is nobody's before and after sends nothing**, which is
     * §3.2.3.1's "Calendar object resource → Calendar object resource: None".
     */
    public function testAResourceThatIsNobodysBeforeAndAfterSendsNothing(): void
    {
        $body = $this->anEvent('', self::CYRUS, 'SUMMARY:Lunch');

        $this->given($this->anEvent('', self::CYRUS));
        $this->put($body);

        self::assertSame([], $this->namesIn('calendars/cyrus/inbox'));
        self::assertSame($body, $this->theStoredObject(self::EVENT));
    }

    /**
     * **An attendee's own copy being changed is §3.2.2.3**, which is not here
     * yet: §3.2.3.1 calls it a Modify all the same, and the section that says
     * what one does is the attendee's. Until then the resource is stored and
     * nothing is sent.
     */
    public function testAnAttendeesOwnCopyBeingChangedSendsNothing(): void
    {
        $theirs = "ORGANIZER:mailto:cyrus@example.com\r\nATTENDEE:mailto:bernard@example.com";

        $this->given($this->anEvent($theirs));
        $this->put($this->anEvent($theirs, 'SUMMARY:Lunch'));

        self::assertSame([], $this->namesIn('calendars/cyrus/inbox'));
        self::assertStringNotContainsString('SCHEDULE-STATUS', $this->theStoredObject(self::EVENT));
    }

    /**
     * **And handing the organizer's role over sends nothing yet either.** Both
     * sides are scheduling object resources, so §3.2.3.1 says Modify — and the
     * owner is the organizer of one of them and the attendee of the other, so
     * neither §3.2.1.2 nor §3.2.2.3 is describing this on its own. RFC 5546
     * §3.2.2.4 is what it is, "Changing the Organizer", and it travels with
     * §3.2.2.
     */
    public function testHandingTheOrganizersRoleOverSendsNothingYet(): void
    {
        $this->given($this->anEvent(self::ORGANISED, self::CYRUS));
        $this->put($this->anEvent(
            "ORGANIZER:mailto:cyrus@example.com\r\nATTENDEE:mailto:bernard@example.com",
        ));

        self::assertSame([], $this->namesIn('calendars/cyrus/inbox'));
    }

    /**
     * **And an attendee off a resource that was not the owner's is not
     * uninvited by them.** Bernard turns a copy Cyrus organised into one of his
     * own and leaves Eric off it. Nothing was ever Bernard's to cancel: the
     * original is no original of §3.2.1.2's, so the write is the `<Absent>` row
     * — and a cancellation from somebody who never invited him would be the
     * first Eric ever heard of either of them.
     */
    public function testAnAttendeeOffAResourceThatWasNotTheOwnersIsNotUninvited(): void
    {
        $this->given($this->anEvent(
            'ORGANIZER:mailto:cyrus@example.com',
            'ATTENDEE:mailto:bernard@example.com',
            self::ERIC,
        ));
        $this->put($this->anEvent(self::ORGANISED, 'ATTENDEE:mailto:bernard@example.com'));

        self::assertSame([], $this->namesIn('calendars/eric/inbox'));
    }

    /**
     * **An attendee the client schedules for is left to the client** here too,
     * both `CLIENT` cells of the table reading `--`.
     */
    public function testAnAttendeeTheClientHandlesIsLeftToTheClient(): void
    {
        $client = 'ATTENDEE;SCHEDULE-AGENT=CLIENT:mailto:cyrus@example.com';

        $this->given($this->anEvent(self::ORGANISED, $client));
        $this->put($this->anEvent(self::ORGANISED, $client, 'SUMMARY:Lunch'));

        self::assertSame([], $this->namesIn('calendars/cyrus/inbox'));
        self::assertStringNotContainsString('SCHEDULE-STATUS', $this->theStoredObject(self::EVENT));
    }

    /**
     * **An answer the resource already carried is no attempt to set one.**
     * §4.2 writes the attendees' answers onto the organizer's own copy, so a
     * client that reads the resource, changes it and writes it back sends every
     * one of them back with it. A server refusing that would refuse every
     * second modification ever made.
     */
    public function testAnAnswerTheResourceAlreadyCarriedIsNotRefused(): void
    {
        $answered = 'ATTENDEE;PARTSTAT=ACCEPTED:mailto:cyrus@example.com';

        $this->given($this->anEvent(self::ORGANISED, $answered));

        self::assertSame(204, $this->put($this->anEvent(self::ORGANISED, $answered, 'SUMMARY:Lunch')));
    }

    /**
     * **And one the organizer writes is refused**, which is §3.2.1's SHOULD and
     * §3.2.4.3's precondition: §3.2.1.2 asks for it of what "the 'Organizer'
     * attempts to change… in a manner that is forbidden".
     */
    public function testAnAnswerTheOrganizerWritesIsRefused(): void
    {
        $this->given($this->anEvent(self::ORGANISED, self::CYRUS));

        self::assertSame(403, $this->put($this->anEvent(
            self::ORGANISED,
            'ATTENDEE;PARTSTAT=ACCEPTED:mailto:cyrus@example.com',
        )));
        self::assertSame([], $this->namesIn('calendars/cyrus/inbox'));
    }

    /**
     * **The organizer may still answer for themselves**, the sentence being
     * about "the 'ATTENDEE' iCalendar property of other users".
     */
    public function testTheOrganizerMayStillAnswerForThemselves(): void
    {
        $this->given($this->anEvent(self::ORGANISED, self::CYRUS));

        self::assertSame(204, $this->put($this->anEvent(
            self::ORGANISED,
            self::CYRUS,
            'ATTENDEE;PARTSTAT=ACCEPTED:mailto:bernard@example.com',
        )));
    }

    /**
     * **And an answer behind their own is still refused.** The organizer
     * answers for themselves, which the sentence allows, and for Cyrus, which
     * it does not — in that order on the list. A server that stopped at the
     * one it was allowed to pass over would store the other.
     */
    public function testAnAnswerBehindTheOrganizersOwnIsStillRefused(): void
    {
        $this->given($this->anEvent(self::ORGANISED, self::CYRUS));

        self::assertSame(403, $this->put($this->anEvent(
            self::ORGANISED,
            'ATTENDEE;PARTSTAT=ACCEPTED:mailto:bernard@example.com',
            'ATTENDEE;PARTSTAT=ACCEPTED:mailto:cyrus@example.com',
        )));
    }

    /**
     * **What is underneath and cannot be read as one calendar object is no
     * original**, so the write is a creation: §3.2.3.1's rows turn on what the
     * existing resource *is*, and something no server can read as a calendar
     * object resource is neither of the two kinds it names.
     */
    public function testSomethingUnreadableUnderneathIsNoOriginal(): void
    {
        $this->given('this is not a calendar object');
        $this->put($this->anEvent(self::ORGANISED, self::CYRUS));

        self::assertStringContainsString("METHOD:REQUEST\r\n", $this->theOneResourceIn('calendars/cyrus/inbox'));
    }

    /**
     * **A body that is no calendar object is left alone**, and left to
     * {@see \DavServices\Plugin\CalDav}: refusing it is that plugin's
     * `CALDAV:valid-calendar-data` precondition, and two plugins refusing one
     * request in their own words would answer it twice.
     */
    public function testABodyThatIsNoCalendarObjectIsStoredAsItCame(): void
    {
        $this->given($this->anEvent(self::ORGANISED, self::CYRUS));
        $this->put('this is not a calendar object either');

        self::assertSame('this is not a calendar object either', $this->theStoredObject(self::EVENT));
        self::assertSame([], $this->namesIn('calendars/cyrus/inbox'));
    }

    /**
     * **A body that arrives as a string is read as one.**
     * {@see BeforeWriteContent} declares its content `resource|string`, and
     * {@see Put} hands over a stream — but a listener that only worked for one
     * of the two shapes would break for any method that handed over the other,
     * and the event is what says which shapes there are.
     *
     * The event is raised here rather than through a request for that reason:
     * what is being held to its word is the listener's half of the event's
     * contract.
     */
    public function testABodyThatArrivesAsAStringIsReadAsOne(): void
    {
        $this->given($this->anEvent(self::ORGANISED, self::CYRUS));

        $raised = ($this->server ?? self::fail('No server.'))->events()->emit(new BeforeWriteContent(
            self::EVENT,
            $this->anEvent(self::ORGANISED, self::CYRUS, 'SUMMARY:Lunch'),
        ));
        $content = $raised->content();

        self::assertStringContainsString("METHOD:REQUEST\r\n", $this->theOneResourceIn('calendars/cyrus/inbox'));
        self::assertIsString($content);
        self::assertStringContainsString('SCHEDULE-STATUS=1.2', $content);
    }

    /**
     * **And a file outside a calendar collection is nothing to do with
     * scheduling**, §3.1 asking about "a calendar object resource… created,
     * modified, or removed from a calendar collection".
     */
    public function testAFileOutsideACalendarIsNothingToDoWithScheduling(): void
    {
        $this->given($this->anEvent(self::ORGANISED, self::CYRUS));
        $this->inTheCalendar('plain', 'event.ics', $this->anEvent(self::ORGANISED, self::CYRUS));
        $this->put($this->anEvent(self::ORGANISED, self::CYRUS, 'SUMMARY:Lunch'), 'plain/event.ics');

        self::assertSame([], $this->namesIn('calendars/cyrus/inbox'));
    }

    /**
     * Writes that body over the resource.
     */
    private function put(string $body, string $path = self::EVENT): int
    {
        return ($this->server ?? self::fail('No server.'))
            ->handle(new Request('PUT', '/' . $path, body: new Body($body)))
            ->status();
    }

    /**
     * One event carrying those properties.
     */
    private function anEvent(string ...$properties): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dav.services//test//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261101T100000Z\r\n"
            . self::lines($properties)
            . "END:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    /**
     * One event carrying those properties, starting at that moment.
     */
    private function anEventAt(string $start, string ...$properties): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dav.services//test//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:" . $start . "\r\n"
            . self::lines($properties)
            . "END:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    /**
     * That text with the folding taken out, RFC 5545 §3.1 breaking a line
     * longer than 75 octets — which an attendee with two parameters passes.
     */
    private static function unfolded(string $text): string
    {
        return str_replace("\r\n ", '', $text);
    }

    /**
     * A recurring event and one overridden instance, each with those
     * attendees besides the organizer.
     */
    private function aSeries(string $master, string $instance): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dav.services//test//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261101T100000Z\r\n"
            . "RRULE:FREQ=DAILY;COUNT=3\r\n"
            . self::lines([self::ORGANISED, $master])
            . "END:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261102T110000Z\r\n"
            . "RECURRENCE-ID:20261102T100000Z\r\n"
            . self::lines([self::ORGANISED, $instance])
            . "END:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    /**
     * Those of these properties that are there, one to a line.
     *
     * @param array<array-key, string> $properties
     */
    private static function lines(array $properties): string
    {
        $written = '';

        foreach ($properties as $property) {
            if ($property !== '') {
                $written .= $property . "\r\n";
            }
        }

        return $written;
    }

    /**
     * Puts something into one of the collections.
     */
    private function inTheCalendar(string $path, string $name, string $content): void
    {
        $this->theCollection($path)->createFile($name, $content);
    }

    /**
     * The store behind the collection at that path.
     */
    private function theCollection(string $path): MemoryCollection
    {
        return $this->stores[$path] ?? self::fail('No such collection: ' . $path);
    }

    /**
     * What is stored at that path now.
     */
    private function theStoredObject(string $path): string
    {
        $node = ($this->tree ?? self::fail('No tree.'))->node($path);

        self::assertInstanceOf(IFile::class, $node);

        $content = $node->get();

        return is_string($content) ? $content : self::fail('The double keeps its content as a string.');
    }

    /**
     * The one resource in that collection.
     */
    private function theOneResourceIn(string $path): string
    {
        $resources = $this->theResourcesIn($path);

        self::assertCount(1, $resources);

        return $resources[0] ?? self::fail(sprintf('Nothing in "%s".', $path));
    }

    /**
     * What is in that collection, in the order it arrived.
     *
     * @return list<string>
     */
    private function theResourcesIn(string $path): array
    {
        $resources = [];

        foreach ($this->namesIn($path) as $name) {
            $resources[] = $this->theStoredObject(Path::join($path, $name));
        }

        return $resources;
    }

    /**
     * The names of what is in that collection.
     *
     * @return list<string>
     */
    private function namesIn(string $path): array
    {
        $collection = ($this->tree ?? self::fail('No tree.'))->node($path);

        self::assertInstanceOf(ICollection::class, $collection);

        $names = [];

        foreach ($collection->children() as $child) {
            $names[] = $child->name();
        }

        return $names;
    }

    /**
     * Bernard organises from a calendar of his own; Cyrus and Eric each have
     * an Inbox and, unless the case is about doing without one, a calendar for
     * their copy.
     *
     * @param string $original What is already at {@see self::EVENT}, which is
     *                         the half of every case here that §3.2.3.1 needs
     * @param bool $calendarForEric Whether Eric has a calendar at all, which
     *                              is §4.3's `5.3`
     */
    private function given(string $original, bool $calendarForEric = true): void
    {
        $users = new MemoryCalendarUserBackend(self::ADDRESSES);
        $events = new EventEmitter();

        $events->on(PropertiesRequested::class, static function (PropertiesRequested $event) use ($users): void {
            self::answer($event, $users);
        });

        $this->stores = [
            'calendars/bernard/work' => new MemoryCollection('work'),
            'calendars/bernard/inbox' => new MemoryCollection('inbox'),
            'calendars/cyrus/work' => new MemoryCollection('work'),
            'calendars/cyrus/inbox' => new MemoryCollection('inbox'),
            'calendars/eric/work' => new MemoryCollection('work'),
            'calendars/eric/inbox' => new MemoryCollection('inbox'),
            'plain' => new MemoryCollection('plain'),
        ];

        $this->stores['calendars/bernard/work']->createFile('event.ics', $original);

        $eric = (new MemoryCollection('eric'))->add(Inbox::of($this->stores['calendars/eric/inbox']));

        if ($calendarForEric) {
            $eric->add(Calendar::of($this->stores['calendars/eric/work']));
        }

        $tree = new Tree(
            (new MemoryCollection(''))
                ->add((new MemoryCollection('calendars'))
                    ->add((new MemoryCollection('bernard'))
                        ->add(Calendar::of($this->stores['calendars/bernard/work']))
                        ->add(Inbox::of($this->stores['calendars/bernard/inbox'])))
                    ->add((new MemoryCollection('cyrus'))
                        ->add(Calendar::of($this->stores['calendars/cyrus/work']))
                        ->add(Inbox::of($this->stores['calendars/cyrus/inbox'])))
                    ->add($eric))
                ->add($this->stores['plain'])
                ->add((new MemoryCollection('principals'))
                    ->add(new Principal(new PrincipalInfo('bernard', 'Bernard')))
                    ->add(new Principal(new PrincipalInfo('cyrus', 'Cyrus')))
                    ->add(new Principal(new PrincipalInfo('eric', 'Eric')))),
        );

        $server = new Server($tree, $events, new Writer(['DAV:' => 'd', self::NS => 'C']));

        $server->onMethod('PUT', (new Put($server))(...));

        (new Scheduling($server, $users))->register();

        $this->tree = $tree;
        $this->server = $server;
    }

    /**
     * The three properties this plugin reads, answered the way a deployment
     * answers them.
     */
    private static function answer(PropertiesRequested $event, ICalendarUserBackend $users): void
    {
        $node = $event->node();
        $result = $event->result();

        if ($node instanceof Principal) {
            if ($result->wants(self::HOME_SET)) {
                $result->set(self::HOME_SET, self::naming(self::HOME_SET, ['/calendars/' . $node->name() . '/']));
            }

            if ($result->wants(self::ADDRESS_SET)) {
                $result->set(self::ADDRESS_SET, self::naming(self::ADDRESS_SET, $users->addressesOf($node->name())));
            }

            return;
        }

        $segments = Path::segments($result->path());

        if (!$result->wants(self::OWNER) || count($segments) !== 3) {
            return;
        }

        $result->set(self::OWNER, self::naming(self::OWNER, ['/principals/' . $segments[1] . '/']));
    }

    /**
     * A property holding a `DAV:href` for each of those.
     *
     * @param list<string> $hrefs
     */
    private static function naming(string $property, array $hrefs): Element
    {
        $element = new Element($property);

        foreach ($hrefs as $href) {
            $child = new Element(self::HREF);

            $child->appendText($href);
            $element->append($child);
        }

        return $element;
    }
}
