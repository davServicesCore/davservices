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
use DavServices\Acl\Privilege;
use DavServices\CalDav\Calendar;
use DavServices\CalDav\Schedule\Inbox;
use DavServices\CalDav\Schedule\Outbox;
use DavServices\CalDav\Schedule\Privileges;
use DavServices\Dav\Acl\PrincipalInfo;
use DavServices\Dav\Event\CurrentPrincipalRequested;
use DavServices\Dav\Event\PrivilegeNeeded;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\Method\Delete;
use DavServices\Dav\Method\Put;
use DavServices\Dav\Method\Report;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Http\Body;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Plugin\Acl;
use DavServices\Plugin\Scheduling;
use DavServices\Tests\Unit\Acl\ArrayPrivilegeResolver;
use DavServices\Tests\Unit\Backend\MemoryCalendarUserBackend;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Xml\Element;
use DavServices\Xml\Writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for RFC 6638 §6.2.2 and §6.2.3 enforced — the half of §6 that is
 * about **writing**, not about delivering. R-CAL-42, P8-07.
 *
 * > Users granted the DAV:bind privilege on a calendar collection, or the
 * > DAV:write privilege on scheduling object resources, will also need the
 * > CALDAV:schedule-send-invite privilege granted on the scheduling Outbox
 * > collection of the owner of the calendar collection or scheduling object
 * > resource **in order to be allowed to create, modify, or delete**
 * > scheduling object resources in a way that will trigger the CalDAV server
 * > to deliver scheduling messages to "Attendees".
 *
 * **"In order to be allowed to create, modify, or delete" is why this refuses
 * rather than reports.** §3.2.9's `3.8` is the other half of §6 and says
 * something else — "The scheduling message **was not delivered** due to
 * insufficient privileges" — which is about a recipient's Inbox, and the note
 * that it "applies to privileges granted by both the 'Organizer' and
 * 'Attendee' calendar users" names two **recipients** rather than two checks:
 * an organizer receiving a reply grants, an attendee receiving a request
 * grants. Here nothing has been sent and nothing is to be reported; the
 * request itself is not allowed, so it gets `403` with RFC 3744 §7.1.1's
 * `DAV:need-privileges`.
 *
 * **Without this, whoever holds `DAV:bind` on somebody else's calendar can
 * make the server send invitations in that owner's name.** That is the
 * escalation §6.2 exists to stop, and §6.2.2's own first sentence says as
 * much: the privilege "controls the sending of scheduling messages by
 * 'Organizers'".
 *
 * ## "In a way that will trigger" is a condition, and it is kept
 *
 * The sentence does not ask the privilege of every write to a scheduling
 * object resource — only of one that sets a message off. So a resource whose
 * attendee list holds nobody but the owner, and an attendee's copy whose
 * organizer asked the **client** to do the sending, are both written without
 * it. Each has a case below, and they are what makes this a condition rather
 * than a second `DAV:write-content`.
 *
 * ## Where there is nothing to grant from, nothing is refused
 *
 * A deployment with no Outbox for the owner, a collection that names no
 * owner, or no access control at all has no place to put the privilege — and
 * "insufficient privileges" describes nothing there. Each has a case too.
 * That is the opposite of this server's usual rule, where what was not
 * granted is refused, and the difference is that a guard defends a resource
 * while this asks after a grant that may not exist to be made.
 *
 * ## The seam, and why there is one
 *
 * {@see PrivilegeNeeded} is how this plugin asks. §7.1.1 prescribes the
 * **shape** of the refusal — `DAV:error`, holding `DAV:need-privileges`,
 * holding a `DAV:resource` with the href and the privilege — and {@see Acl}
 * is where this library builds that shape. Building it a second time here
 * would be one question answered in two places, and making `Acl`'s own
 * builder public would hang a CalDAV plugin on an access control plugin,
 * against R-ARC-02. So the question goes out as an event, and whoever
 * manages access answers it with the refusal itself.
 */
#[CoversClass(Scheduling::class)]
#[CoversClass(Acl::class)]
#[CoversClass(PrivilegeNeeded::class)]
final class SchedulingSendPrivilegesTest extends TestCase
{
    private const BERNARD = '/principals/bernard';

    private const ADDRESSES = [
        'bernard' => ['mailto:bernard@example.com'],
        'cyrus' => ['mailto:cyrus@example.com'],
    ];

    private const EVENT = '/calendars/bernard/work/meeting.ics';

    private const NAME = 'meeting.ics';

    private const HOME_SET = '{urn:ietf:params:xml:ns:caldav}calendar-home-set';

    private const ADDRESS_SET = '{urn:ietf:params:xml:ns:caldav}calendar-user-address-set';

    private const OWNER = '{DAV:}owner';

    private const WRITING = ['{DAV:}read', '{DAV:}write', '{DAV:}write-content', '{DAV:}bind', '{DAV:}unbind'];

    /** The store behind Bernard's calendar, where the event is written. */
    private ?MemoryCollection $calendar = null;

    /** The store behind Cyrus' Inbox, where a delivered message shows up. */
    private ?MemoryCollection $inbox = null;

    /**
     * **An organizer's write is refused without §6.2.2's privilege**, and the
     * refusal names it rather than leaving a client to guess which of the
     * eight was wanted.
     */
    public function testAnOrganisersWriteIsRefusedWithoutTheInvitePrivilege(): void
    {
        $refused = $this->putting(self::anInvitation(), granting: []);

        self::assertSame(403, $refused->status());
        self::assertStringContainsString('need-privileges', (string) $refused->body());
        self::assertStringContainsString('schedule-send-invite', (string) $refused->body());
    }

    /**
     * **And the href it names is the Outbox**, which is where §6.2.2 has the
     * privilege granted — not the resource the client was writing. A refusal
     * naming the event would send an administrator to change the rights on
     * the wrong collection.
     */
    public function testTheRefusalNamesTheOutboxItWantedThePrivilegeOn(): void
    {
        $refused = $this->putting(self::anInvitation(), granting: []);

        self::assertStringContainsString('/calendars/bernard/outbox/', (string) $refused->body());
    }

    /**
     * **And nothing was delivered or stored**, which is the point of asking
     * before the first message rather than after it: a refusal that left an
     * invitation in somebody's Inbox would have told them of an event that
     * was never kept.
     */
    public function testARefusedWriteDeliversNothingAndKeepsNothing(): void
    {
        $this->putting(self::anInvitation(), granting: []);

        self::assertSame([], $this->whatCyrusWasSent());
        self::assertFalse($this->theCalendar()->hasChild(self::NAME));
    }

    /**
     * **And it goes through with it**, which is the half that says this
     * refuses nothing else.
     */
    public function testAnOrganisersWriteGoesThroughWithTheInvitePrivilege(): void
    {
        $taken = $this->putting(self::anInvitation(), granting: [Privileges::SEND_INVITE]);

        self::assertContains($taken->status(), [201, 204]);
        self::assertCount(1, $this->whatCyrusWasSent());
    }

    /**
     * **An attendee's reply wants §6.2.3's privilege instead**, the two
     * sections dividing the work by who is sending: "the sending of
     * scheduling messages by 'Organizers'" against "by 'Attendees'".
     */
    public function testAnAttendeesWriteIsRefusedWithoutTheReplyPrivilege(): void
    {
        $refused = $this->putting(self::aReply(), granting: [Privileges::SEND_INVITE]);

        self::assertSame(403, $refused->status());
        self::assertStringContainsString('schedule-send-reply', (string) $refused->body());
    }

    /**
     * And goes through with that one.
     */
    public function testAnAttendeesWriteGoesThroughWithTheReplyPrivilege(): void
    {
        $taken = $this->putting(self::aReply(), granting: [Privileges::SEND_REPLY]);

        self::assertContains($taken->status(), [201, 204]);
    }

    /**
     * **An object that is no scheduling object resource at all is not this
     * section's business.** §6.2.2 asks the privilege of a write to one of
     * them, and §3.1 wants an `ORGANIZER` for either kind.
     */
    public function testAnObjectThatIsNoSchedulingObjectIsNotHeldToEitherPrivilege(): void
    {
        $taken = $this->putting(self::aPrivateNote(), granting: []);

        self::assertContains($taken->status(), [201, 204]);
    }

    /**
     * **An event with nobody on it but the owner triggers nothing**, so it
     * wants nothing. It is a scheduling object resource — §3.1's first
     * sentence is satisfied — and §3.2.1's MUST NOT keeps the one message it
     * could have sent from going: "the server MUST NOT send a scheduling
     * message to the 'Organizer'".
     */
    public function testAnEventWithNobodyButTheOwnerOnItIsNotRefused(): void
    {
        $taken = $this->putting(self::anEventTheOwnerAttends(), granting: []);

        self::assertContains($taken->status(), [201, 204]);
    }

    /**
     * **And an attendee's copy the client sends for triggers nothing either.**
     * The table §3.2.2.3 shares with §3.2.2.2 has `SCHEDULE-AGENT=CLIENT` do
     * "no special processing of the resource", so no `REPLY` leaves this
     * server and §6.2.3 has nothing to allow.
     */
    public function testAnAttendeesCopyTheClientSendsForIsNotRefused(): void
    {
        $taken = $this->putting(
            self::anObject(
                "ORGANIZER;SCHEDULE-AGENT=CLIENT:mailto:cyrus@example.com\r\n"
                . "ATTENDEE;PARTSTAT=ACCEPTED:mailto:bernard@example.com\r\n",
            ),
            granting: [],
        );

        self::assertContains($taken->status(), [201, 204]);
    }

    /**
     * **A write that cancels wants the same privilege as one that invites.**
     * §3.2.3.1's "Scheduling object resource → Calendar object resource:
     * Remove" sends §3.2.1.3's `CANCEL` to everybody who was on the list, and
     * §6.2.2 counts that as triggering the server "to deliver scheduling
     * messages to 'Attendees'" like any other.
     */
    public function testAWriteThatCancelsIsRefusedWithoutTheInvitePrivilege(): void
    {
        $refused = $this->putting(self::aPrivateNote(), granting: [], stored: self::anInvitation());

        self::assertSame(403, $refused->status());
        self::assertStringContainsString('schedule-send-invite', (string) $refused->body());
    }

    /**
     * And goes through with it, the cancellation then reaching the attendee.
     */
    public function testAWriteThatCancelsGoesThroughWithTheInvitePrivilege(): void
    {
        $taken = $this->putting(
            self::aPrivateNote(),
            granting: [Privileges::SEND_INVITE],
            stored: self::anInvitation(),
        );

        self::assertContains($taken->status(), [201, 204]);
        self::assertCount(1, $this->whatCyrusWasSent());
    }

    /**
     * **§6.2.2 says "or delete", so a removal is asked too** — and asked
     * before the resource goes. §3.2.1.3 sends the `CANCEL` once it is gone,
     * so a refusal raised then would answer `403` to a request that had
     * already taken the event away.
     */
    public function testARemovalThatCancelsIsRefusedWithoutTheInvitePrivilege(): void
    {
        $refused = $this->deleting(granting: [], stored: self::anInvitation());

        self::assertSame(403, $refused->status());
        self::assertStringContainsString('schedule-send-invite', (string) $refused->body());
        self::assertTrue($this->theCalendar()->hasChild(self::NAME));
        self::assertSame([], $this->whatCyrusWasSent());
    }

    /**
     * And goes through with it.
     */
    public function testARemovalThatCancelsGoesThroughWithTheInvitePrivilege(): void
    {
        $taken = $this->deleting(granting: [Privileges::SEND_INVITE], stored: self::anInvitation());

        self::assertSame(204, $taken->status());
        self::assertCount(1, $this->whatCyrusWasSent());
    }

    /**
     * **A removal that cancels nothing is not refused**, which is the other
     * half of the same condition: there is nobody to tell, so nothing is
     * triggered.
     */
    public function testARemovalThatCancelsNothingIsNotRefused(): void
    {
        $taken = $this->deleting(granting: [], stored: self::anEventTheOwnerAttends());

        self::assertSame(204, $taken->status());
    }

    /**
     * **Where the owner has no Outbox, nothing is refused.** §6.2.2 wants the
     * privilege "granted on the scheduling Outbox collection of the owner",
     * and a deployment that publishes none has nowhere to grant it. Refusing
     * every write then would make this server unusable wherever scheduling is
     * wanted without an Outbox, and would say "insufficient privileges" of a
     * privilege nobody could hold.
     */
    public function testWhereTheOwnerHasNoOutboxNothingIsRefused(): void
    {
        $taken = $this->putting(self::anInvitation(), granting: [], outbox: false);

        self::assertContains($taken->status(), [201, 204]);
    }

    /**
     * **And where the collection names no owner, nothing is refused either.**
     * §6.2.2 asks the privilege of the Outbox "of the owner of the calendar
     * collection", and `DAV:owner` is what names them (RFC 3744 §5.1). A
     * collection that names nobody names no Outbox.
     */
    public function testWhereTheCalendarNamesNoOwnerNothingIsRefused(): void
    {
        $taken = $this->putting(self::anInvitation(), granting: [], owner: false);

        self::assertContains($taken->status(), [201, 204]);
    }

    /**
     * **And a removal from such a collection is not refused either**, which
     * is the same sentence read on the other event: there is nothing there
     * for §3.2.1.3 to cancel, because nothing in it was ever a scheduling
     * object resource.
     */
    public function testARemovalWhereTheCalendarNamesNoOwnerIsNotRefused(): void
    {
        $taken = $this->deleting(granting: [], stored: self::anInvitation(), owner: false);

        self::assertSame(204, $taken->status());
        self::assertSame([], $this->whatCyrusWasSent());
    }

    /**
     * **And where nothing manages access at all, nothing is refused either.**
     * With no ACL plugin there is nobody to answer {@see PrivilegeNeeded}, and
     * an unanswered question is not a no. R-ARC-02 has every plugin
     * switchable, and a scheduling plugin that refused every write without one
     * would not be.
     */
    public function testWhereNothingManagesAccessNothingIsRefused(): void
    {
        $taken = $this->putting(self::anInvitation(), granting: [], access: false);

        self::assertContains($taken->status(), [201, 204]);
    }

    /**
     * An event Bernard organises with Cyrus invited, which is what makes the
     * server send.
     */
    private static function anInvitation(): string
    {
        return self::anObject(
            "ORGANIZER:mailto:bernard@example.com\r\n"
            . "ATTENDEE:mailto:cyrus@example.com\r\n",
        );
    }

    /**
     * The same meeting seen from Cyrus' side, which Bernard's calendar holds
     * as an attendee's copy — so writing it replies to the organizer.
     */
    private static function aReply(): string
    {
        return self::anObject(
            "ORGANIZER:mailto:cyrus@example.com\r\n"
            . "ATTENDEE;PARTSTAT=ACCEPTED:mailto:bernard@example.com\r\n",
        );
    }

    /**
     * Bernard's own event, which he is the only one at.
     */
    private static function anEventTheOwnerAttends(): string
    {
        return self::anObject(
            "ORGANIZER:mailto:bernard@example.com\r\n"
            . "ATTENDEE:mailto:bernard@example.com\r\n",
        );
    }

    /**
     * An event with nobody organising it and nobody to tell.
     */
    private static function aPrivateNote(): string
    {
        return self::anObject('');
    }

    private static function anObject(string $who): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260602T100000Z\r\n"
            . "DTEND:20260602T110000Z\r\nSUMMARY:A Meeting\r\n" . $who
            . "END:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    /**
     * @param list<string> $granting What Bernard may do on his own Outbox
     */
    private function putting(
        string $body,
        array $granting,
        bool $outbox = true,
        bool $access = true,
        bool $owner = true,
        ?string $stored = null,
    ): Response {
        return $this->serverFor($granting, $outbox, $access, $owner, $stored)
            ->handle(new Request('PUT', self::EVENT, body: new Body($body)));
    }

    /**
     * @param list<string> $granting As {@see self::putting()}
     */
    private function deleting(array $granting, string $stored, bool $owner = true): Response
    {
        return $this->serverFor($granting, true, true, $owner, $stored)
            ->handle(new Request('DELETE', self::EVENT));
    }

    /**
     * The server these cases are tried against: Bernard with a calendar, an
     * Inbox and — unless a case says otherwise — an Outbox, Cyrus with an
     * Inbox to be delivered to, and whoever manages access told what Bernard
     * may do where.
     *
     * @param list<string> $granting As {@see self::putting()}
     */
    private function serverFor(
        array $granting,
        bool $outbox,
        bool $access,
        bool $owner,
        ?string $stored,
    ): Server {
        $users = new MemoryCalendarUserBackend(self::ADDRESSES, '/principals/');

        $this->calendar = new MemoryCollection('work');
        $this->inbox = new MemoryCollection('inbox');

        if ($stored !== null) {
            $this->calendar->createFile(self::NAME, $stored);
        }

        $home = (new MemoryCollection('bernard'))
            ->add(Calendar::of($this->calendar))
            ->add(Inbox::of(new MemoryCollection('inbox')));

        if ($outbox) {
            $home->add(Outbox::of(new MemoryCollection('outbox')));
        }

        $server = new Server(
            new Tree(
                (new MemoryCollection(''))
                    ->add((new MemoryCollection('calendars'))
                        ->add($home)
                        // A calendar of his own as well: §4.1 has the
                        // recipient's server put the event on one before the
                        // message appears in the Inbox at all, so a calendar
                        // user without one is never delivered to.
                        ->add((new MemoryCollection('cyrus'))
                            ->add(Calendar::of(new MemoryCollection('work')))
                            ->add(Inbox::of($this->inbox))))
                    ->add((new MemoryCollection('principals'))
                        ->add(new Principal(new PrincipalInfo('bernard', 'Bernard')))
                        ->add(new Principal(new PrincipalInfo('cyrus', 'Cyrus')))),
            ),
            new EventEmitter(),
            new Writer(['DAV:' => 'd', 'urn:ietf:params:xml:ns:caldav' => 'C']),
        );

        $events = $server->events();

        $events->on(PropertiesRequested::class, static function (PropertiesRequested $event) use ($users, $owner): void {
            self::answer($event, $users, $owner);
        });

        if ($access) {
            $events->on(
                CurrentPrincipalRequested::class,
                static fn (CurrentPrincipalRequested $event) => $event->answerWith(self::BERNARD),
            );

            (new Acl($server, new ArrayPrivilegeResolver(
                [
                    self::BERNARD => [
                        'calendars/bernard/work' => self::WRITING,
                        'calendars/bernard/work/' . self::NAME => self::WRITING,
                        'calendars/bernard/outbox' => $granting,
                    ],
                ],
                // §6.2 hangs its three on an Outbox, which is where §6.2.2
                // and §6.2.3 have the grant made.
                Privileges::anOutboxDefines(Privilege::standard()),
            )))->register(new Report($server));
        }

        $server->onMethod('PUT', (new Put($server))(...));
        $server->onMethod('DELETE', (new Delete($server))(...));

        (new Scheduling($server, $users))->register();

        return $server;
    }

    /**
     * What is in Cyrus' Inbox now, by name.
     *
     * @return list<string>
     */
    private function whatCyrusWasSent(): array
    {
        $names = [];

        foreach (($this->inbox ?? self::fail('No server.'))->children() as $child) {
            $names[] = $child->name();
        }

        return $names;
    }

    /**
     * The store behind Bernard's calendar.
     */
    private function theCalendar(): MemoryCollection
    {
        return $this->calendar ?? self::fail('No server.');
    }

    /**
     * The three properties this plugin reads, answered the way a deployment
     * answers them: the home of whoever the second path segment names, the
     * owner of a collection in that home, and the addresses the directory
     * knows them by.
     *
     * @param bool $owner Whether `DAV:owner` is answered at all, which is the
     *                    case of a store that names nobody
     */
    private static function answer(PropertiesRequested $event, MemoryCalendarUserBackend $users, bool $owner): void
    {
        $node = $event->node();
        $result = $event->result();
        $segments = explode('/', trim($result->path(), '/'));

        if ($node instanceof Principal) {
            if ($result->wants(self::HOME_SET)) {
                $result->set(self::HOME_SET, self::naming(self::HOME_SET, ['/calendars/' . $node->name() . '/']));
            }

            if ($result->wants(self::ADDRESS_SET)) {
                $result->set(self::ADDRESS_SET, self::naming(self::ADDRESS_SET, $users->addressesOf($node->name())));
            }

            return;
        }

        if (!$owner || !$result->wants(self::OWNER) || count($segments) !== 3) {
            return;
        }

        $result->set(self::OWNER, self::naming(self::OWNER, ['/principals/' . $segments[1] . '/']));
    }

    /**
     * @param list<string> $hrefs
     */
    private static function naming(string $property, array $hrefs): Element
    {
        $holder = new Element($property);

        foreach ($hrefs as $href) {
            $child = new Element('{DAV:}href');

            $child->appendText($href);
            $holder->append($child);
        }

        return $holder;
    }
}
