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
use DavServices\Tests\Unit\Dav\TypedNode;
use DavServices\Uri\Path;
use DavServices\Xml\Element;
use DavServices\Xml\Writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for what an organizer's `PUT` sends (RFC 6638 §3.2.1.1, the Create
 * row of §3.2.3.1, §4, §7.1, §7.3 and §3.2.9). R-CAL-32, P8-02c.
 *
 * §3 says what hangs on all of this in its first paragraph: "If it does, the
 * server will automatically attempt to deliver a scheduling message to the
 * appropriate calendar users." §3.2.3.1's table says which request starts it —
 * "None → Scheduling object resource: Create" — and §3.2.1.1 says what Create
 * means:
 *
 * > When an "Organizer" creates a scheduling object resource, the server MUST
 * > inspect each "ATTENDEE" property to determine whether to send a scheduling
 * > message. The table below indicates the appropriate iTIP method used by the
 * > server, taking into account any "SCHEDULE-AGENT" property parameter (see
 * > Section 7.1) specified on each "ATTENDEE" property.
 * >
 * >                  +------------------+-------------+
 * >                  | SCHEDULE-AGENT   | iTIP METHOD |
 * >                  +------------------+-------------+
 * >                  | SERVER (default) | REQUEST     |
 * >                  | CLIENT           | --          |
 * >                  | NONE             | --          |
 * >                  +------------------+-------------+
 *
 * ## Three MUSTs and a MUST NOT, and they fence each other in
 *
 * > The server MUST add a "SCHEDULE-STATUS" iCalendar property parameter (see
 * > Section 7.3) to the "ATTENDEE" iCalendar property in the scheduling object
 * > resource being created, and set its value as described in Section 3.2.9.
 * > This will result in the created calendar object resource differing from
 * > the calendar data sent in the HTTP request.
 * >
 * > Servers MUST NOT set the "SCHEDULE-STATUS" property parameter on the
 * > "ATTENDEE" property of "Attendees" for which it did not attempt to deliver
 * > a scheduling message.
 *
 * So the stamp is not decoration: it is on exactly the attendees somebody was
 * told about, and its value says what came of telling them (§3.2.9). And
 * because the *stored* resource is the one that differs from what was sent,
 * none of this can happen after the write — which is why it hangs off
 * {@see \DavServices\Dav\Event\BeforeCreateFile}.
 *
 * **The organizer is not invited to their own event**, however the attendee
 * list reads (§3.2.1):
 *
 * > The "Organizer" of a calendar component can also be an "Attendee" of that
 * > calendar component. In such cases, the server MUST NOT send a scheduling
 * > message to the "Attendee" that matches the "Organizer".
 *
 * §1.2 settles what "matches" means there — «"Organizer" refers to a role of a
 * calendar user» — so it is the calendar user that matches, not the text of a
 * property: an owner with three addresses is one person, and none of the three
 * is invited.
 *
 * ## Where the message goes
 *
 * §4: "the scheduling message is stored in the scheduling Inbox collection as
 * an indicator to the client that a scheduling operation has taken place". The
 * Inbox is the one {@see Scheduling} already finds for §2.2.1's property, and
 * the way to it is the attendee's address: §2.4.1 is "needed to map calendar
 * user addresses in iCalendar data to principal resources and their associated
 * scheduling Inbox and Outbox collections", which is
 * {@see ICalendarUserBackend::principalFor()}.
 *
 * **Everything that way can fail, and §3.2.9 has a code for each**: an address
 * nobody is reachable at is `3.7`, a calendar user §2.2.1 calls "not enabled
 * for reception of scheduling messages" is `5.2`, a store that refused the
 * write is `5.1`, and a message that arrived is `1.2`.
 *
 * ## What this test deliberately does without
 *
 * **{@see \DavServices\Plugin\CalDav} is not registered here.** What this
 * plugin reads is a property and a resource type rather than that plugin, and
 * a body that is no calendar object resource is that plugin's to refuse —
 * these tests show this one leaving it alone.
 *
 * And the directory answers both directions from one table, which is what
 * {@see ICalendarUserBackend} is for: the owner's addresses come from the
 * property §2.4.1 defines, and the attendee's principal from the same table
 * read backwards.
 */
#[CoversClass(Scheduling::class)]
final class SchedulingDeliveryTest extends TestCase
{
    private const NS = 'urn:ietf:params:xml:ns:caldav';

    private const HOME_SET = '{' . self::NS . '}calendar-home-set';

    private const ADDRESS_SET = '{' . self::NS . '}calendar-user-address-set';

    private const OWNER = '{DAV:}owner';

    private const HREF = '{DAV:}href';

    /** Bernard's calendar, where the organizer's resources are created. */
    private const EVENT = 'calendars/bernard/work/event.ics';

    /**
     * Who is reachable at what, as §2.4.1 holds it. Bernard has two addresses
     * because one calendar user with several is the case §3.2.1's MUST NOT
     * turns on.
     */
    private const ADDRESSES = [
        'bernard' => ['mailto:bernard@example.com', 'mailto:b.desruisseaux@example.com'],
        'cyrus' => ['mailto:cyrus@example.com'],
        'eric' => ['mailto:eric@example.com'],
        'wilfredo' => ['mailto:wilfredo@example.com'],
        'quentin' => [],
    ];

    private ?Tree $tree = null;

    private ?Server $server = null;

    /** What this server is mounted at, which a request has to carry. */
    private string $base = '';

    /**
     * **§3.2.1.1's table, first row: the attendee is sent a `REQUEST`.** The
     * whole message, because §4 stores "the scheduling message" rather than a
     * summary of it — and `METHOD:REQUEST` is what makes it one (RFC 5546
     * §3.2).
     */
    public function testTheAttendeeIsSentARequest(): void
    {
        $this->given();
        $this->put('ATTENDEE:mailto:cyrus@example.com');

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
            . "ATTENDEE:mailto:cyrus@example.com\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n",
            $this->theOneMessageIn('cyrus'),
        );
    }

    /**
     * **§3.2.1.1: the stored resource carries the status of the delivery**,
     * and §3.2.9.3 says what `1.2` means: "The iTIP message has been sent and
     * delivered." A message written into the calendar user's own Inbox on this
     * server has been, with nothing left to find out.
     */
    public function testTheStoredResourceCarriesWhatCameOfIt(): void
    {
        $this->given();
        $this->put('ATTENDEE:mailto:cyrus@example.com');

        self::assertStringContainsString(
            'ATTENDEE;SCHEDULE-STATUS=1.2:mailto:cyrus@example.com',
            $this->theStoredObject(),
        );
    }

    /**
     * **§7.3's MUST NOT, read against the thing that writes it**: "Servers
     * MUST NOT include this parameter in any scheduling messages sent as the
     * result of a scheduling operation."
     *
     * The stored resource carries the stamp and the message does not, which is
     * only true if the message was built before the stamp was written — an
     * ordering nothing in the sections states and everything depends on.
     */
    public function testTheMessageDoesNotCarryTheStatusTheResourceDoes(): void
    {
        $this->given();
        $this->put('ATTENDEE:mailto:cyrus@example.com');

        self::assertStringContainsString('SCHEDULE-STATUS', $this->theStoredObject());
        self::assertStringNotContainsString('SCHEDULE-STATUS', $this->theOneMessageIn('cyrus'));
    }

    /**
     * **§3.2.1's MUST NOT: the organizer is not invited to their own event.**
     * Bernard owns the calendar, organises the event and is on the attendee
     * list; his own Inbox stays empty and his `ATTENDEE` property is left as he
     * wrote it, because §3.2.1.1's other MUST NOT forbids a status where
     * nothing was attempted.
     */
    public function testTheOrganizerIsNotSentTheirOwnInvitation(): void
    {
        $this->given();
        $this->put(
            "ATTENDEE:mailto:bernard@example.com\r\n"
            . 'ATTENDEE:mailto:cyrus@example.com',
        );

        self::assertSame([], $this->namesIn('calendars/bernard/inbox'));
        self::assertStringContainsString("ATTENDEE:mailto:bernard@example.com\r\n", $this->theStoredObject());

        // And the attendee after him is still told: the organizer is skipped,
        // not the rest of the list.
        self::assertCount(1, $this->namesIn('calendars/cyrus/inbox'));
    }

    /**
     * **An attendee after one this server leaves alone is still told.** §7.1's
     * `CLIENT` says nothing about anybody else on the list, and a server that
     * stopped at the first attendee it had nothing to do for would drop every
     * invitation behind it — silently, which is the worst way to be wrong
     * here.
     */
    public function testAnAttendeeAfterOneTheClientHandlesIsStillTold(): void
    {
        $this->given();
        $this->put(
            "ATTENDEE;SCHEDULE-AGENT=CLIENT:mailto:eric@example.com\r\n"
            . 'ATTENDEE:mailto:cyrus@example.com',
        );

        self::assertCount(1, $this->namesIn('calendars/cyrus/inbox'));
        self::assertStringContainsString(
            'ATTENDEE;SCHEDULE-STATUS=1.2:mailto:cyrus@example.com',
            $this->theStoredObject(),
        );
    }

    /**
     * **Where nothing was sent, nothing is rewritten.** §3.2.1.1 makes the
     * stored resource differ from what was sent *because* a status was added
     * to it; where no status was added there is nothing to differ about, and a
     * server that wrote the object out again would be handing back its own
     * spelling of somebody else's data.
     *
     * The body here is written with bare line feeds, which RFC 5545 §3.1 does
     * not allow and this library reads all the same. A rewrite would fold it
     * into the `CRLF` the serialiser writes, which is what makes the
     * difference visible at all.
     */
    public function testWhereNothingIsSentNothingIsRewritten(): void
    {
        $this->given();
        $text = str_replace("\r\n", "\n", $this->organisedByBernard(
            'ATTENDEE;SCHEDULE-AGENT=CLIENT:mailto:cyrus@example.com',
        ));

        $this->putWhole($text);

        self::assertSame($text, $this->theStoredObject());
    }

    /**
     * **And neither is the organizer under another of their addresses.** §1.2:
     * «"Organizer" refers to a role of a calendar user», so what §3.2.1
     * matches is the calendar user — and §2.4.1 lets one have as many
     * addresses as a deployment likes. Bernard organises as
     * `bernard@example.com` and is invited as `b.desruisseaux@example.com`,
     * which is one person twice.
     */
    public function testTheOrganizerIsOnePersonWhateverAddressTheyAreInvitedUnder(): void
    {
        $this->given();
        $this->put('ATTENDEE:mailto:b.desruisseaux@example.com');

        self::assertSame([], $this->namesIn('calendars/bernard/inbox'));
        self::assertStringContainsString(
            "ATTENDEE:mailto:b.desruisseaux@example.com\r\n",
            $this->theStoredObject(),
        );
    }

    /**
     * **§3.2.1.1's table, second row: `SCHEDULE-AGENT=CLIENT` is nobody
     * told.** §7.1: "When the value 'CLIENT' is specified, that indicates that
     * the client is handling scheduling messages with the calendar user
     * itself."
     *
     * No message, and no status: §3.2.1.1 forbids one where the server did not
     * attempt to deliver.
     */
    public function testAnAttendeeTheClientHandlesIsLeftToTheClient(): void
    {
        $this->given();
        $this->put('ATTENDEE;SCHEDULE-AGENT=CLIENT:mailto:cyrus@example.com');

        self::assertSame([], $this->namesIn('calendars/cyrus/inbox'));
        self::assertStringNotContainsString('SCHEDULE-STATUS', $this->theStoredObject());
    }

    /**
     * **The third row: `NONE` is nobody told either**, and §7.1 means it
     * plainly — "When 'NONE' is specified, no scheduling messages are being
     * sent to the calendar user."
     */
    public function testAnAttendeeWithNoSchedulingAtAllIsNotTold(): void
    {
        $this->given();
        $this->put('ATTENDEE;SCHEDULE-AGENT=NONE:mailto:cyrus@example.com');

        self::assertSame([], $this->namesIn('calendars/cyrus/inbox'));
        self::assertStringNotContainsString('SCHEDULE-STATUS', $this->theStoredObject());
    }

    /**
     * **And a value §7.1 does not know is read as `NONE`**: "Servers and
     * clients MUST treat x-name and iana-token values they do not recognize
     * the same way as they would the 'NONE' value." Nobody's invitation goes
     * out on the strength of a parameter nobody understood.
     */
    public function testAnAgentItDoesNotRecogniseTellsNobody(): void
    {
        $this->given();
        $this->put('ATTENDEE;SCHEDULE-AGENT=X-SOMETHING:mailto:cyrus@example.com');

        self::assertSame([], $this->namesIn('calendars/cyrus/inbox'));
        self::assertStringNotContainsString('SCHEDULE-STATUS', $this->theStoredObject());
    }

    /**
     * **A status the client keeps for itself is left alone**, which is §7.3's
     * own arrangement: "In the case where the client is handling the
     * scheduling, the client MAY add, change, or remove this parameter to
     * indicate the last scheduling message status it received." The server
     * "MUST only add or change this property parameter on any 'ATTENDEE'
     * properties corresponding to calendar users who were sent a scheduling
     * message", and this one was not.
     */
    public function testAStatusTheClientWroteOnItsOwnAttendeeStands(): void
    {
        $this->given();
        $this->put('ATTENDEE;SCHEDULE-AGENT=CLIENT;SCHEDULE-STATUS=2.0:mailto:cyrus@example.com');

        self::assertStringContainsString('SCHEDULE-STATUS=2.0', $this->theStoredObject());
    }

    /**
     * **§3.2.1's SHOULD: an organizer does not answer for their attendees.**
     *
     * > The server SHOULD reject any attempt to set the "PARTSTAT" iCalendar
     * > property parameter value of the "ATTENDEE" iCalendar property of other
     * > users in the calendar object resource to a value other than
     * > "NEEDS-ACTION" if the "SCHEDULE-AGENT" property parameter value is not
     * > present or set to the value "SERVER".
     *
     * §3.2.4.3 is the code it is refused with, and names this very case as
     * what it is for: "For instance, servers MAY prevent the 'Organizer' from
     * setting the 'PARTSTAT' property parameter to a value other than
     * 'NEEDS-ACTION'".
     *
     * **Refused before anything is sent**, because an invitation delivered and
     * then refused would be a message about a resource that was never stored.
     */
    public function testAnOrganizerAnsweringForSomebodyElseIsRefused(): void
    {
        $this->given();

        self::assertSame(403, $this->put('ATTENDEE;PARTSTAT=ACCEPTED:mailto:cyrus@example.com'));
        self::assertSame([], $this->namesIn('calendars/cyrus/inbox'));
        self::assertNotContains('event.ics', $this->namesIn('calendars/bernard/work'));
    }

    /**
     * **And the refusal names itself**, which is what §3.2.4.3 is for: a
     * client that reads the precondition knows which parameter to put back,
     * where a bare `403` leaves it guessing.
     */
    public function testTheRefusalNamesThePrecondition(): void
    {
        $this->given();
        $body = (string) ($this->server ?? self::fail('No server.'))
            ->handle(new Request(
                'PUT',
                '/' . self::EVENT,
                body: new Body($this->organisedByBernard('ATTENDEE;PARTSTAT=ACCEPTED:mailto:cyrus@example.com')),
            ))
            ->body();

        self::assertStringContainsString('allowed-organizer-scheduling-object-change', $body);
    }

    /**
     * **Every property of that calendar user is asked, not the first one.**
     * §3.2.1 is about "the 'ATTENDEE' iCalendar property of other users" in
     * the resource, and a recurring event with an overridden instance names
     * one person twice — a guard that looked only at the first would let an
     * answer through in the override, which is exactly where a client would
     * put one.
     */
    public function testAnAnswerInAnOverriddenInstanceIsRefusedAsWell(): void
    {
        $this->given();

        self::assertSame(403, $this->putWhole(
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dav.services//test//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261101T100000Z\r\n"
            . "RRULE:FREQ=DAILY;COUNT=3\r\n"
            . "ORGANIZER:mailto:bernard@example.com\r\n"
            . "ATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:cyrus@example.com\r\n"
            . "END:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261102T110000Z\r\n"
            . "RECURRENCE-ID:20261102T100000Z\r\n"
            . "ORGANIZER:mailto:bernard@example.com\r\n"
            . "ATTENDEE;PARTSTAT=ACCEPTED:mailto:cyrus@example.com\r\n"
            . "END:VEVENT\r\nEND:VCALENDAR\r\n",
        ));
        self::assertSame([], $this->namesIn('calendars/cyrus/inbox'));
    }

    /**
     * **And an answer behind an attendee the rule skips is refused too.** The
     * exceptions §3.2.1 states are about one `ATTENDEE` property each; a guard
     * that stopped at the first of them would let every answer after it
     * through, which is where a client that sets one would put it.
     */
    public function testAnAnswerBehindAnAttendeeItSkipsIsRefusedAsWell(): void
    {
        $this->given();

        self::assertSame(403, $this->put(
            "ATTENDEE;SCHEDULE-AGENT=CLIENT;PARTSTAT=ACCEPTED:mailto:eric@example.com\r\n"
            . 'ATTENDEE;PARTSTAT=ACCEPTED:mailto:cyrus@example.com',
        ));
        self::assertSame([], $this->namesIn('calendars/cyrus/inbox'));
    }

    /**
     * **RFC 5545 §3.5 again: `PARTSTAT` is an enumerated parameter value**,
     * so it is case-insensitive — and a guard that answered only to the
     * shouted spelling would be no guard at all.
     */
    public function testTheSpellingOfTheAnswerDoesNotSaveIt(): void
    {
        $this->given();

        self::assertSame(403, $this->put('ATTENDEE;PARTSTAT=accepted:mailto:cyrus@example.com'));
    }

    /**
     * **`NEEDS-ACTION` is what §3.2.1 allows**, because it says nothing about
     * anybody's answer: it is the state an invitation starts in (RFC 5545
     * §3.2.12).
     */
    public function testAnAttendeeWhoHasNotAnsweredYetIsNoObjection(): void
    {
        $this->given();
        $this->put('ATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:cyrus@example.com');

        self::assertCount(1, $this->namesIn('calendars/cyrus/inbox'));
    }

    /**
     * **The organizer's own answer is their own**, and §3.2.1 says so in the
     * same sentence: it is "the 'ATTENDEE' iCalendar property of **other
     * users**" that is not theirs to set. An organizer going to their own
     * event is the ordinary case.
     */
    public function testTheOrganizerMayAnswerForThemselves(): void
    {
        $this->given();
        $this->put(
            "ATTENDEE;PARTSTAT=ACCEPTED:mailto:bernard@example.com\r\n"
            . 'ATTENDEE:mailto:cyrus@example.com',
        );

        self::assertCount(1, $this->namesIn('calendars/cyrus/inbox'));
        self::assertStringContainsString('PARTSTAT=ACCEPTED', $this->theStoredObject());
    }

    /**
     * **And neither is it an objection where the client is doing the
     * scheduling**, which is the condition §3.2.1 states: the rule holds only
     * "if the 'SCHEDULE-AGENT' property parameter value is not present or set
     * to the value 'SERVER'". A client handling its own invitations knows
     * answers this server never saw.
     */
    public function testAClientThatSchedulesForItselfMayCarryAnAnswer(): void
    {
        $this->given();
        $this->put('ATTENDEE;SCHEDULE-AGENT=CLIENT;PARTSTAT=ACCEPTED:mailto:cyrus@example.com');

        self::assertSame([], $this->namesIn('calendars/cyrus/inbox'));
        self::assertStringContainsString('PARTSTAT=ACCEPTED', $this->theStoredObject());
    }

    /**
     * **§3.2.9's `3.7`: "the server did not recognize the calendar user
     * address as a valid calendar user".** Which is what a directory answering
     * nothing says, and §5.3.2 of `caldav-sharing` says the same of such an
     * address in its own words: how anything reaches that person "is out of
     * scope for this specification".
     */
    public function testAnAddressNobodyIsReachableAtIsSaidSo(): void
    {
        $this->given();
        $this->put('ATTENDEE:mailto:stranger@example.net');

        self::assertStringContainsString(
            'ATTENDEE;SCHEDULE-STATUS=3.7:mailto:stranger@example.net',
            $this->theStoredObject(),
        );
    }

    /**
     * **§3.2.9's `5.2`: "the server was not able to find a way to deliver the
     * message".** Eric is a calendar user with addresses and no Inbox, which
     * is §2.2.1's own sentence: "If not present, then the associated calendar
     * user is not enabled for reception of scheduling messages on the server."
     */
    public function testACalendarUserWithNoInboxIsSaidSo(): void
    {
        $this->given();
        $this->put('ATTENDEE:mailto:eric@example.com');

        self::assertStringContainsString(
            'ATTENDEE;SCHEDULE-STATUS=5.2:mailto:eric@example.com',
            $this->theStoredObject(),
        );
    }

    /**
     * **And so is something that says it is an Inbox and is no collection.**
     * §2.2 calls it a collection throughout, so a store that publishes the
     * resource type on anything else has nowhere for a message to go — and a
     * server that trusted the resource type would be calling `createFile()` on
     * whatever it found.
     */
    public function testSomethingThatIsNoCollectionIsNoInbox(): void
    {
        $this->given();
        $this->put('ATTENDEE:mailto:wilfredo@example.com');

        self::assertStringContainsString(
            'ATTENDEE;SCHEDULE-STATUS=5.2:mailto:wilfredo@example.com',
            $this->theStoredObject(),
        );
    }

    /**
     * **And so is an address that resolves to a path this server cannot
     * address at all.** A directory is an application's, and what it answers
     * with arrives here as text: one that climbs above the root of the server
     * names nobody, and §3.2.9's `5.2` is the answer — "the server was not
     * able to find a way to deliver the message. This is likely a permanent
     * failure."
     */
    public function testAnAddressLeadingNowhereThisServerServesIsSaidSo(): void
    {
        $this->given(principalsAt: '/../principals/');
        $this->put('ATTENDEE:mailto:cyrus@example.com');

        self::assertStringContainsString(
            'ATTENDEE;SCHEDULE-STATUS=5.2:mailto:cyrus@example.com',
            $this->theStoredObject(),
        );
    }

    /**
     * **And one that resolves to a principal this server does not hold.** A
     * directory can say anything; what it says is read and then looked for,
     * and §3.2.9's `5.2` is what not finding it comes to.
     */
    public function testAnAddressNamingAPrincipalThatIsNotThereIsSaidSo(): void
    {
        $this->given(principalsAt: '/nowhere/');
        $this->put('ATTENDEE:mailto:cyrus@example.com');

        self::assertStringContainsString(
            'ATTENDEE;SCHEDULE-STATUS=5.2:mailto:cyrus@example.com',
            $this->theStoredObject(),
        );
    }

    /**
     * **And one that resolves to something that is no principal.** §2.4.1's
     * map is "needed to map calendar user addresses in iCalendar data to
     * principal resources", so an answer naming anything else has not answered
     * it — and a server that read a calendar's `CALDAV:schedule-inbox-URL`
     * would be delivering by whatever a store happened to say.
     */
    public function testAnAddressThatNamesNoPrincipalIsSaidSo(): void
    {
        $this->given(principalsAt: '/calendars/');
        $this->put('ATTENDEE:mailto:cyrus@example.com');

        self::assertStringContainsString(
            'ATTENDEE;SCHEDULE-STATUS=5.2:mailto:cyrus@example.com',
            $this->theStoredObject(),
        );
    }

    /**
     * **And one outside what this server serves at all.** A server mounted at
     * a path — `/dav` here — serves nothing above it, and a directory that
     * answers with a URL from the host's root has named a principal of
     * somebody else's: {@see Server::pathOf()} refuses it, and `5.2` is what
     * that comes to.
     */
    public function testAnAddressOutsideWhatThisServerServesIsSaidSo(): void
    {
        $this->given(base: '/dav', principalsAt: '/principals/');
        $this->put('ATTENDEE:mailto:cyrus@example.com');

        self::assertStringContainsString(
            'ATTENDEE;SCHEDULE-STATUS=5.2:mailto:cyrus@example.com',
            $this->theStoredObject(),
        );
    }

    /**
     * **§3.2.9's `5.1`: "the server could not complete delivery of the
     * message. This is likely a temporary failure, and the originator can try
     * to send the message again at a later time."**
     *
     * A store that refuses the write is that failure, and the organizer's own
     * `PUT` is not what pays for it: a `201` with a status of `5.1` on one
     * attendee is the whole of §3.2.9's purpose, where a refusal passed on
     * would have lost the organizer's event over somebody else's Inbox.
     */
    public function testAnInboxThatRefusesTheWriteIsSaidSo(): void
    {
        $this->given(refusing: true);

        self::assertSame(201, $this->put('ATTENDEE:mailto:cyrus@example.com'));
        self::assertStringContainsString(
            'ATTENDEE;SCHEDULE-STATUS=5.1:mailto:cyrus@example.com',
            $this->theStoredObject(),
        );
    }

    /**
     * **Two attendees, two answers**, each on its own property: §3.2.1.1 asks
     * the server to "inspect each 'ATTENDEE' property", and a server that
     * stamped one answer across the list would be reporting a delivery that
     * never happened.
     */
    public function testEachAttendeeIsToldWhatCameOfTheirOwnMessage(): void
    {
        $this->given();
        $this->put(
            "ATTENDEE:mailto:cyrus@example.com\r\n"
            . 'ATTENDEE:mailto:stranger@example.net',
        );

        $stored = $this->theStoredObject();

        self::assertStringContainsString('ATTENDEE;SCHEDULE-STATUS=1.2:mailto:cyrus@example.com', $stored);
        self::assertStringContainsString('ATTENDEE;SCHEDULE-STATUS=3.7:mailto:stranger@example.net', $stored);
    }

    /**
     * **One calendar user is told once, and every property of theirs is
     * stamped.** A recurring event with an overridden instance names the same
     * attendee twice, and §7.3 asks for the parameter "on any 'ATTENDEE'
     * properties corresponding to calendar users who were sent a scheduling
     * message" — properties, plural. The message itself is the whole resource,
     * so there is one of it.
     */
    public function testAnAttendeeNamedTwiceIsToldOnceAndStampedTwice(): void
    {
        $this->given();
        $this->putWhole(
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dav.services//test//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261101T100000Z\r\n"
            . "RRULE:FREQ=DAILY;COUNT=3\r\n"
            . "ORGANIZER:mailto:bernard@example.com\r\nATTENDEE:mailto:cyrus@example.com\r\n"
            . "END:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261102T110000Z\r\n"
            . "RECURRENCE-ID:20261102T100000Z\r\n"
            . "ORGANIZER:mailto:bernard@example.com\r\nATTENDEE:mailto:cyrus@example.com\r\n"
            . "END:VEVENT\r\nEND:VCALENDAR\r\n",
        );

        self::assertCount(1, $this->namesIn('calendars/cyrus/inbox'));
        self::assertSame(
            2,
            substr_count($this->theStoredObject(), 'ATTENDEE;SCHEDULE-STATUS=1.2:mailto:cyrus@example.com'),
        );
    }

    /**
     * **The same message twice is one message.** §4 makes the Inbox an
     * indicator — "Scheduling messages are typically removed from the
     * scheduling Inbox collection by the client once the calendar user has
     * acknowledged the change" — so two calendars of one owner holding one
     * event leave the attendee one thing to acknowledge rather than two.
     *
     * **And the second one is delivered**, which is the whole of it: §3.2.9.3
     * asks whether "the iTIP message has been sent and delivered", and a
     * message already in that Inbox has been. A server that wrote it again
     * instead would be reporting a failure about a message the calendar user
     * is looking at.
     */
    public function testTheSameMessageDeliveredTwiceLeavesOneAndIsStillDelivered(): void
    {
        $this->given();
        $this->put('ATTENDEE:mailto:cyrus@example.com');
        $this->put('ATTENDEE:mailto:cyrus@example.com', 'calendars/bernard/work/again.ics');

        self::assertCount(1, $this->namesIn('calendars/cyrus/inbox'));
        self::assertStringContainsString(
            'ATTENDEE;SCHEDULE-STATUS=1.2:mailto:cyrus@example.com',
            $this->theStoredObject('calendars/bernard/work/again.ics'),
        );
    }

    /**
     * **An attendee's copy is nobody's to send from here.** §3.1's second
     * sentence makes this an attendee scheduling object resource — Cyrus
     * organises, Bernard owns the calendar — and §3.2.2 is what such a
     * resource does, which is a reply rather than a request. Until then the
     * resource is stored and nothing is sent.
     */
    public function testAnAttendeesOwnCopySendsNothing(): void
    {
        $this->given();
        $this->putWhole($this->anObject(
            "ORGANIZER:mailto:cyrus@example.com\r\n"
            . 'ATTENDEE:mailto:bernard@example.com',
        ));

        self::assertSame([], $this->namesIn('calendars/cyrus/inbox'));
        self::assertStringNotContainsString('SCHEDULE-STATUS', $this->theStoredObject());
    }

    /**
     * **A calendar object resource that is no scheduling object resource is
     * stored as it came.** §3.1 asks for an `ORGANIZER` "present and set in
     * all the calendar components", and an appointment somebody made for
     * themselves has none.
     */
    public function testAnEventWithNoOrganizerIsStoredAsItCame(): void
    {
        $this->given();
        $text = $this->anObject('SUMMARY:Lunch');

        $this->putWhole($text);

        self::assertSame($text, $this->theStoredObject());
    }

    /**
     * **A body that is no calendar object resource is left alone**, and left
     * to {@see \DavServices\Plugin\CalDav}: refusing it is that plugin's
     * `CALDAV:valid-calendar-data` precondition, and a second plugin refusing
     * it in its own words would answer a request twice.
     */
    public function testABodyThatIsNoCalendarObjectIsLeftAlone(): void
    {
        $this->given();
        $this->putWhole('this is not iCalendar at all');

        self::assertSame('this is not iCalendar at all', $this->theStoredObject());
    }

    /**
     * **§3.1 asks about "a calendar object resource contained in a calendar
     * collection"**, so a file written anywhere else is nothing to do with
     * scheduling, whatever it holds.
     */
    public function testAFileOutsideACalendarIsNothingToDoWithScheduling(): void
    {
        $this->given();
        $text = $this->organisedByBernard('ATTENDEE:mailto:cyrus@example.com');

        $this->putWhole($text, 'plain/event.ics');

        self::assertSame([], $this->namesIn('calendars/cyrus/inbox'));
        self::assertSame($text, $this->theStoredObject('plain/event.ics'));
    }

    /**
     * **A calendar whose `DAV:owner` nobody answers schedules nothing.** §3.1
     * asks every comparison against "the calendar user addresses of the owner
     * of the calendar collection", and a collection this server names no owner
     * for has no such addresses to compare with — the same reading
     * {@see \DavServices\Plugin\Sharing} makes of §5.5.5.
     */
    public function testACalendarWithNoOwnerSchedulesNothing(): void
    {
        $this->given();
        $this->put('ATTENDEE:mailto:cyrus@example.com', 'calendars/nobodys/event.ics');

        self::assertSame([], $this->namesIn('calendars/cyrus/inbox'));
        self::assertStringNotContainsString('SCHEDULE-STATUS', $this->theStoredObject('calendars/nobodys/event.ics'));
    }

    /**
     * **And neither does one whose owner is not there.** A `DAV:owner` naming
     * a principal this server does not hold answers no
     * `CALDAV:calendar-user-address-set` either, which §2.4.1 reads as a
     * calendar user "not enabled for scheduling on the server".
     */
    public function testAnOwnerThisServerDoesNotHoldSchedulesNothing(): void
    {
        $this->given();
        $this->put('ATTENDEE:mailto:cyrus@example.com', 'calendars/orphan/work/event.ics');

        self::assertSame([], $this->namesIn('calendars/cyrus/inbox'));
        self::assertStringNotContainsString(
            'SCHEDULE-STATUS',
            $this->theStoredObject('calendars/orphan/work/event.ics'),
        );
    }

    /**
     * **And neither does one whose owner this server cannot address.** What
     * `DAV:owner` holds is a store's or another plugin's answer, so it is read
     * rather than trusted: an href climbing above the root of the server names
     * nobody, and §3.1's comparison has nothing to compare with.
     */
    public function testAnOwnerThisServerCannotAddressSchedulesNothing(): void
    {
        $this->given();
        $this->put('ATTENDEE:mailto:cyrus@example.com', 'calendars/astray/work/event.ics');

        self::assertSame([], $this->namesIn('calendars/cyrus/inbox'));
        self::assertStringNotContainsString(
            'SCHEDULE-STATUS',
            $this->theStoredObject('calendars/astray/work/event.ics'),
        );
    }

    /**
     * **And neither does one whose owner is outside what this server
     * serves.** The same mount, and `DAV:owner` naming a principal above it:
     * what a store answers is read and then refused, rather than resolved
     * against a tree it does not belong to.
     */
    public function testAnOwnerOutsideWhatThisServerServesSchedulesNothing(): void
    {
        $this->given(base: '/dav');
        $this->put('ATTENDEE:mailto:cyrus@example.com', 'calendars/offsite/work/event.ics');

        self::assertSame([], $this->namesIn('calendars/cyrus/inbox'));
        self::assertStringNotContainsString(
            'SCHEDULE-STATUS',
            $this->theStoredObject('calendars/offsite/work/event.ics'),
        );
    }

    /**
     * **A resource in a folder inside a calendar is in that calendar**, which
     * RFC 4791 §4.2 allows — "calendar collections MUST NOT contain other
     * calendar collections, but they MAY contain collections that are not
     * calendar collections" — and §3.1 asks only that the resource be
     * "contained in a calendar collection". The owner whose addresses decide
     * everything is the calendar's, not the folder's.
     */
    public function testAResourceInAFolderInsideACalendarIsStillTheOwnersToSend(): void
    {
        $this->given();
        $this->put('ATTENDEE:mailto:cyrus@example.com', 'calendars/bernard/work/notes/event.ics');

        self::assertCount(1, $this->namesIn('calendars/cyrus/inbox'));
        self::assertStringContainsString(
            'ATTENDEE;SCHEDULE-STATUS=1.2:mailto:cyrus@example.com',
            $this->theStoredObject('calendars/bernard/work/notes/event.ics'),
        );
    }

    /**
     * **§2.4.1: "If not present, then the associated calendar user is not
     * enabled for scheduling on the server."** Quentin is in the directory
     * with no addresses at all, so nothing he organises can be matched to him
     * — and §3.1's first sentence is the comparison that fails.
     */
    public function testAnOwnerWithNoAddressesSchedulesNothing(): void
    {
        $this->given();
        $this->put('ATTENDEE:mailto:cyrus@example.com', 'calendars/quentin/work/event.ics');

        self::assertSame([], $this->namesIn('calendars/cyrus/inbox'));
        self::assertStringNotContainsString(
            'SCHEDULE-STATUS',
            $this->theStoredObject('calendars/quentin/work/event.ics'),
        );
    }

    /**
     * **With no directory wired, nobody is reachable at any address.** §2.4.1
     * is the map "needed to map calendar user addresses in iCalendar data to
     * principal resources and their associated scheduling Inbox and Outbox
     * collections", and a deployment that publishes addresses without one has
     * nothing to resolve them with — which §3.2.9's `3.7` says exactly.
     */
    public function testWithNoDirectoryNobodyCanBeReached(): void
    {
        $this->given(directory: false);
        $this->put('ATTENDEE:mailto:cyrus@example.com');

        self::assertSame([], $this->namesIn('calendars/cyrus/inbox'));
        self::assertStringContainsString(
            'ATTENDEE;SCHEDULE-STATUS=3.7:mailto:cyrus@example.com',
            $this->theStoredObject(),
        );
    }

    /**
     * The `PUT` of an event carrying those properties, and the status it was
     * answered with.
     */
    private function put(string $properties, string $path = self::EVENT): int
    {
        return $this->putWhole($this->organisedByBernard($properties), $path);
    }

    /**
     * The same with the whole body written out, for the cases the shape of the
     * object is the case.
     */
    private function putWhole(string $body, string $path = self::EVENT): int
    {
        return ($this->server ?? self::fail('No server.'))
            ->handle(new Request('PUT', $this->base . '/' . $path, body: new Body($body)))
            ->status();
    }

    /**
     * One event carrying those properties, and nothing added to them.
     */
    private function anObject(string $properties): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dav.services//test//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20261101T090000Z\r\nDTSTART:20261101T100000Z\r\n"
            . $properties . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    /**
     * The same, organised by Bernard — who owns the calendar the tests write
     * into, which is what makes it his to send (§3.1).
     */
    private function organisedByBernard(string $properties): string
    {
        return $this->anObject("ORGANIZER:mailto:bernard@example.com\r\n" . $properties);
    }

    /**
     * What is stored at that path now.
     */
    private function theStoredObject(string $path = self::EVENT): string
    {
        $node = ($this->tree ?? self::fail('No tree.'))->node($path);

        self::assertInstanceOf(IFile::class, $node);

        $content = $node->get();

        return is_string($content) ? $content : self::fail('The double keeps its content as a string.');
    }

    /**
     * The one message in that calendar user's Inbox.
     */
    private function theOneMessageIn(string $who): string
    {
        $path = 'calendars/' . $who . '/inbox';
        $names = $this->namesIn($path);
        $name = $names[0] ?? self::fail(sprintf('Nothing was delivered to "%s".', $who));

        self::assertCount(1, $names);

        return $this->theStoredObject(Path::join($path, $name));
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
     * Bernard's calendar and Inbox, the attendees of the tests, and the one
     * directory both directions are read from.
     *
     * - **Cyrus** has an Inbox, and is who an invitation reaches.
     * - **Eric** has none: §2.2.1's "not enabled for reception of scheduling
     *   messages on the server".
     * - **Wilfredo's** says it is an Inbox and is no collection.
     * - **Quentin** is a calendar user with no addresses (§2.4.1).
     * - **`calendars/nobodys`** is a calendar this server names no owner for,
     *   **`calendars/orphan/work`** one whose owner it does not hold, and
     *   **`calendars/offsite/work`** one whose owner is named from the host's
     *   root rather than from where this server is mounted.
     *
     * @param bool $refusing Whether Cyrus's Inbox refuses what it is handed
     * @param bool $directory Whether a calendar user directory is wired at all
     * @param string $principalsAt Where the directory says the principals are,
     *                             so that an answer leading nowhere can be
     *                             tried
     * @param string $base What this server is mounted at, so that an href
     *                     above the mount can be tried
     */
    private function given(
        bool $refusing = false,
        bool $directory = true,
        string $principalsAt = '/principals/',
        string $base = '',
    ): void {
        $users = new MemoryCalendarUserBackend(self::ADDRESSES, $principalsAt);
        $cyrus = new MemoryCollection('inbox');

        if ($refusing) {
            $cyrus->refuseCreation();
        }

        $events = new EventEmitter();

        $events->on(
            PropertiesRequested::class,
            static function (PropertiesRequested $event) use ($users, $base): void {
                self::answer($event, $users, $base);
            },
        );

        $tree = new Tree(
            (new MemoryCollection(''))
                ->add((new MemoryCollection('calendars'))
                    ->add((new MemoryCollection('bernard'))
                        ->add(Calendar::of((new MemoryCollection('work'))
                            ->add(new MemoryCollection('notes'))))
                        ->add(Inbox::of(new MemoryCollection('inbox'))))
                    ->add((new MemoryCollection('cyrus'))
                        ->add(Inbox::of($cyrus)))
                    ->add((new MemoryCollection('eric'))
                        ->add(Calendar::of(new MemoryCollection('work'))))
                    ->add((new MemoryCollection('wilfredo'))
                        ->add(new TypedNode('inbox', Inbox::INBOX)))
                    ->add((new MemoryCollection('quentin'))
                        ->add(Calendar::of(new MemoryCollection('work'))))
                    ->add((new MemoryCollection('orphan'))
                        ->add(Calendar::of(new MemoryCollection('work'))))
                    ->add((new MemoryCollection('astray'))
                        ->add(Calendar::of(new MemoryCollection('work'))))
                    ->add((new MemoryCollection('offsite'))
                        ->add(Calendar::of(new MemoryCollection('work'))))
                    ->add(Calendar::of(new MemoryCollection('nobodys'))))
                ->add(new MemoryCollection('plain'))
                ->add((new MemoryCollection('principals'))
                    ->add(new Principal(new PrincipalInfo('bernard', 'Bernard')))
                    ->add(new Principal(new PrincipalInfo('cyrus', 'Cyrus')))
                    ->add(new Principal(new PrincipalInfo('eric', 'Eric')))
                    ->add(new Principal(new PrincipalInfo('wilfredo', 'Wilfredo')))
                    ->add(new Principal(new PrincipalInfo('quentin', 'Quentin')))),
        );

        $server = new Server(
            $tree,
            $events,
            new Writer(['DAV:' => 'd', self::NS => 'C']),
            baseUri: $base === '' ? '/' : $base,
        );

        $server->onMethod('PUT', (new Put($server))(...));

        (new Scheduling($server, $directory ? $users : null))->register();

        $this->tree = $tree;
        $this->server = $server;
        $this->base = $base;
    }

    /**
     * The three properties this plugin reads, answered the way a deployment
     * answers them.
     *
     * **The addresses come out of the same table the directory answers
     * backwards from**, which is the point of
     * {@see ICalendarUserBackend}: a fixture keeping two lists could disagree
     * with itself about who is reachable where, and so could a deployment.
     *
     * `DAV:owner` is answered for a calendar in somebody's home — the second
     * segment of the path names them, as this fixture's layout has it — and
     * for nothing else, because a store that names no owner is the case
     * {@see self::testACalendarWithNoOwnerSchedulesNothing()} is about. Two
     * homes are answered with an href this server cannot resolve, both of
     * which a store or another plugin is free to hand over: `astray` with one
     * that climbs above the root, and `offsite` with one from the host's root
     * where this server is mounted somewhere else.
     */
    private static function answer(PropertiesRequested $event, ICalendarUserBackend $users, string $base): void
    {
        $node = $event->node();
        $result = $event->result();

        if ($node instanceof Principal) {
            if ($result->wants(self::HOME_SET)) {
                $result->set(
                    self::HOME_SET,
                    self::naming(self::HOME_SET, [$base . '/calendars/' . $node->name() . '/']),
                );
            }

            if ($result->wants(self::ADDRESS_SET)) {
                $result->set(self::ADDRESS_SET, self::naming(self::ADDRESS_SET, $users->addressesOf($node->name())));
            }

            return;
        }

        $segments = Path::segments($result->path());

        if (!$node instanceof Calendar || !$result->wants(self::OWNER) || count($segments) !== 3) {
            return;
        }

        $result->set(self::OWNER, self::naming(self::OWNER, [match ($segments[1]) {
            'astray' => '/../principals/astray/',
            'offsite' => '/principals/offsite/',
            default => $base . '/principals/' . $segments[1] . '/',
        }]));
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
