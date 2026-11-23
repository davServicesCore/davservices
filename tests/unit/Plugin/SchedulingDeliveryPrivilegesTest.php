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

use DavServices\Acl\GroupResolver;
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
use DavServices\Dav\IFile;
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
use DavServices\Tests\Unit\Backend\MemoryPrincipalBackend;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Xml\Element;
use DavServices\Xml\Writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for RFC 6638 §6.1 enforced, and for §3.2.9's `3.8`. R-CAL-42,
 * P8-07's second slice.
 *
 * §6.1 says in its own words whose privilege this is and what it decides:
 *
 * > These privileges determine whether delivery of scheduling messages **from
 * > a calendar user** is allowed by the calendar user who "owns" the
 * > scheduling Inbox collection. This allows calendar users to choose **which
 * > other calendar users can schedule with them**.
 *
 * **So the principal asked about is the sender, not whoever is writing.** That
 * is the whole point of the sentence: a calendar user grants
 * `CALDAV:schedule-deliver-invite` to the people they are willing to hear
 * from. A server that asked after the signed-in user instead would let
 * anybody schedule with anybody as soon as they could write somewhere, and
 * would refuse a delegate acting for somebody the recipient *had* granted.
 * There is a case for each half of that.
 *
 * ## This reports where §6.2 refuses
 *
 * §6.2's privileges are a condition on the write — "in order to be allowed to
 * create, modify, or delete" — and are enforced with a `403`. §3.2.9's `3.8`
 * is the other kind:
 *
 * > | 3.8 | The scheduling message **was not delivered** due to insufficient
 * > privileges. Note that this code applies to privileges granted by both the
 * > "Organizer" and "Attendee" calendar users.
 *
 * A delivery that was not allowed is **reported on the resource** and the
 * request carries on, which is what §3.2.9 exists for: an organizer's event
 * is not lost, and is not withheld from the other attendees, because one
 * recipient will not hear from them. The "both" names two **recipients** —
 * an organizer receiving a reply grants on their own Inbox, an attendee
 * receiving a request grants on theirs — which is why one code serves both
 * directions.
 *
 * ## Two privileges, because the memo gives two
 *
 * §6.1.2 "controls the processing and delivery of scheduling messages coming
 * from an 'Organizer'" and §6.1.3 the ones "coming from an 'Attendee'". The
 * `METHOD` is what says which a message is (RFC 5546 §1.4), so a `REQUEST` or
 * a `CANCEL` wants the first and a `REPLY` the second. Granting one does not
 * grant the other, and there is a case each way round.
 *
 * ## §6.1's second paragraph, which is about somewhere else entirely
 *
 * > Note that when a scheduling message is delivered to a calendar user, in
 * > addition to a scheduling object resource being created in the calendar
 * > user's scheduling Inbox collection, a new scheduling object resource
 * > might be created or an existing one updated in a calendar belonging to
 * > the calendar user. In that case, **the ability to create or update the
 * > scheduling object resource in the calendar is controlled by the
 * > privileges assigned to the scheduling Inbox collection.**
 *
 * So the automatic processing of §4.1 writes into the recipient's calendar
 * **without** that calendar's own `DAV:bind` being asked for. This library
 * does that already — the delivery works on the nodes rather than through the
 * request seams — and the last case below says so rather than leaving it to
 * look unexamined.
 */
#[CoversClass(Scheduling::class)]
#[CoversClass(Acl::class)]
#[CoversClass(PrivilegeNeeded::class)]
final class SchedulingDeliveryPrivilegesTest extends TestCase
{
    /** Whoever is signed in, as a path inside the tree. */
    private const BERNARD = 'principals/bernard';

    private const DELEGATE = 'principals/delegate';

    /** The same, as an access control entry names a principal. */
    private const BERNARD_HELD = '/principals/bernard';

    private const DELEGATE_HELD = '/principals/delegate';

    private const STAFF_HELD = '/principals/staff';

    private const CYRUS_INBOX = 'calendars/cyrus/inbox';

    private const WILFREDO_INBOX = 'calendars/wilfredo/inbox';

    private const ADDRESSES = [
        'bernard' => ['mailto:bernard@example.com'],
        'cyrus' => ['mailto:cyrus@example.com'],
        'wilfredo' => ['mailto:wilfredo@example.com'],
    ];

    private const EVENT = '/calendars/bernard/work/meeting.ics';

    private const NAME = 'meeting.ics';

    private const HOME_SET = '{urn:ietf:params:xml:ns:caldav}calendar-home-set';

    private const ADDRESS_SET = '{urn:ietf:params:xml:ns:caldav}calendar-user-address-set';

    private const OWNER = '{DAV:}owner';

    private const WRITING = ['{DAV:}read', '{DAV:}write', '{DAV:}write-content', '{DAV:}bind', '{DAV:}unbind'];

    private const SENDING = [Privileges::SEND_INVITE, Privileges::SEND_REPLY];

    /**
     * The stores behind the collections, by path.
     *
     * @var array<string, MemoryCollection>
     */
    private array $stores = [];

    /**
     * **An invitation the sender may not deliver is reported, not refused.**
     * §3.2.9's `3.8` goes on the `ATTENDEE` property it is about, and the
     * write itself goes through: §6.1 is about delivery, and an organizer's
     * event is not lost because one recipient will not hear from them.
     */
    public function testAnInvitationTheSenderMayNotDeliverIsReportedAsInsufficient(): void
    {
        $taken = $this->putting(self::anInvitation());

        self::assertContains($taken->status(), [201, 204]);
        self::assertStringContainsString(
            'ATTENDEE;SCHEDULE-STATUS=3.8:mailto:cyrus@example.com',
            $this->storedEvent(),
        );
        self::assertSame([], $this->namesIn(self::CYRUS_INBOX));
    }

    /**
     * **And the one who granted it is still told**, which is the half that
     * makes `3.8` a report rather than a refusal: §3.2.9 is there so that one
     * recipient's rules do not decide what the others hear.
     */
    public function testTheOtherAttendeesAreStillTold(): void
    {
        $this->putting(self::aMeetingWithBoth(), delivering: [
            self::CYRUS_INBOX => [Privileges::DELIVER_INVITE],
        ]);

        $stored = $this->storedEvent();

        self::assertStringContainsString('ATTENDEE;SCHEDULE-STATUS=1.2:mailto:cyrus@example.com', $stored);
        self::assertStringContainsString('ATTENDEE;SCHEDULE-STATUS=3.8:mailto:wilfredo@example.com', $stored);
        self::assertCount(1, $this->namesIn(self::CYRUS_INBOX));
        self::assertSame([], $this->namesIn(self::WILFREDO_INBOX));
    }

    /**
     * **Granted, it is delivered**, and the status says so.
     */
    public function testAnInvitationTheSenderMayDeliverIsDelivered(): void
    {
        $this->putting(self::anInvitation(), delivering: [
            self::CYRUS_INBOX => [Privileges::DELIVER_INVITE],
        ]);

        self::assertStringContainsString(
            'ATTENDEE;SCHEDULE-STATUS=1.2:mailto:cyrus@example.com',
            $this->storedEvent(),
        );
        self::assertCount(1, $this->namesIn(self::CYRUS_INBOX));
    }

    /**
     * **A reply wants §6.1.3's privilege**, which is the other of the two:
     * "scheduling messages coming from an 'Attendee'".
     */
    public function testAReplyTheSenderMayNotDeliverIsReportedAsInsufficient(): void
    {
        $this->putting(self::aReply());

        self::assertStringContainsString(
            'ORGANIZER;SCHEDULE-STATUS=3.8:mailto:cyrus@example.com',
            $this->storedEvent(),
        );
        self::assertSame([], $this->namesIn(self::CYRUS_INBOX));
    }

    /**
     * And goes through with it.
     */
    public function testAReplyTheSenderMayDeliverIsDelivered(): void
    {
        $this->putting(self::aReply(), delivering: [
            self::CYRUS_INBOX => [Privileges::DELIVER_REPLY],
        ]);

        self::assertStringContainsString(
            'ORGANIZER;SCHEDULE-STATUS=1.2:mailto:cyrus@example.com',
            $this->storedEvent(),
        );
        self::assertCount(1, $this->namesIn(self::CYRUS_INBOX));
    }

    /**
     * **The right to hear from an organizer is not the right to hear from an
     * attendee**, §6.1.2 and §6.1.3 being two privileges and not one. A
     * recipient who grants only the first is sent no reply.
     */
    public function testTheRightToAnInviteDoesNotCarryTheRightToAReply(): void
    {
        $this->putting(self::aReply(), delivering: [
            self::CYRUS_INBOX => [Privileges::DELIVER_INVITE],
        ]);

        self::assertStringContainsString('ORGANIZER;SCHEDULE-STATUS=3.8', $this->storedEvent());
    }

    /**
     * And the other way round.
     */
    public function testTheRightToAReplyDoesNotCarryTheRightToAnInvite(): void
    {
        $this->putting(self::anInvitation(), delivering: [
            self::CYRUS_INBOX => [Privileges::DELIVER_REPLY],
        ]);

        self::assertStringContainsString('ATTENDEE;SCHEDULE-STATUS=3.8', $this->storedEvent());
    }

    /**
     * **The privilege is the sender's, not the writer's.** §6.1 lets a
     * calendar user "choose which other calendar users can schedule with
     * them", and the calendar user scheduling here is the owner of the
     * collection the resource is in — §3.1 decides whose scheduling object
     * resource it is by that owner's addresses, and §6.2.2 has the sending
     * done in their name. So a delegate writing Bernard's event delivers on
     * Bernard's standing, and Cyrus hears from Bernard as he agreed to.
     */
    public function testTheRightIsTheSendersAndNotTheWritersOwn(): void
    {
        $this->putting(
            self::anInvitation(),
            delivering: [self::CYRUS_INBOX => [Privileges::DELIVER_INVITE]],
            asking: self::DELEGATE,
        );

        self::assertStringContainsString('SCHEDULE-STATUS=1.2', $this->storedEvent());
        self::assertCount(1, $this->namesIn(self::CYRUS_INBOX));
    }

    /**
     * **And a right the writer holds in their own name is no help**, which is
     * the same sentence read the other way: a delegate Cyrus is willing to
     * hear from cannot thereby schedule as Bernard.
     */
    public function testTheWritersOwnRightDoesNotDeliverForSomebodyElse(): void
    {
        $this->putting(
            self::anInvitation(),
            delivering: [self::CYRUS_INBOX => [Privileges::DELIVER_INVITE]],
            grantedTo: self::DELEGATE_HELD,
            asking: self::DELEGATE,
        );

        self::assertStringContainsString('SCHEDULE-STATUS=3.8', $this->storedEvent());
        self::assertSame([], $this->namesIn(self::CYRUS_INBOX));
    }

    /**
     * **A group the sender is in may be granted it**, RFC 3744 §5.5.1
     * matching a principal "as being (or being a member of)" the one an entry
     * names, and §2 making that recursive. §6.1's "which other calendar
     * users" is therefore answered the way every other access control
     * question on this server is.
     */
    public function testAGroupTheSenderIsInMayBeGrantedIt(): void
    {
        $this->putting(
            self::anInvitation(),
            delivering: [self::CYRUS_INBOX => [Privileges::DELIVER_INVITE]],
            grantedTo: self::STAFF_HELD,
            groups: true,
        );

        self::assertStringContainsString('SCHEDULE-STATUS=1.2', $this->storedEvent());
    }

    /**
     * **And without a group resolver the same entry says nothing**, which is
     * what proves the case above is about the groups rather than about the
     * entry.
     */
    public function testWithoutAGroupResolverTheGroupsEntrySaysNothing(): void
    {
        $this->putting(
            self::anInvitation(),
            delivering: [self::CYRUS_INBOX => [Privileges::DELIVER_INVITE]],
            grantedTo: self::STAFF_HELD,
        );

        self::assertStringContainsString('SCHEDULE-STATUS=3.8', $this->storedEvent());
    }

    /**
     * **Where nothing manages access, everything is delivered.** With no ACL
     * plugin there is nobody to answer {@see PrivilegeNeeded}, and an
     * unanswered question is not a no — R-ARC-02 has every plugin switchable,
     * and "insufficient privileges" describes nothing where there are no
     * privileges.
     */
    public function testWhereNothingManagesAccessEverythingIsDelivered(): void
    {
        $this->putting(self::anInvitation(), access: false);

        self::assertStringContainsString('SCHEDULE-STATUS=1.2', $this->storedEvent());
        self::assertCount(1, $this->namesIn(self::CYRUS_INBOX));
    }

    /**
     * **A cancellation the sender may not deliver leaves the Inbox empty and
     * the removal standing.** §3.2.1.3 asks for no `SCHEDULE-STATUS` — there
     * is no resource left to record one in — so the only thing to see is that
     * nothing arrived, and that the `DELETE` was not turned into a refusal:
     * §6.1 reports, and §6.2 is what refuses a removal.
     */
    public function testACancellationTheSenderMayNotDeliverIsNotDelivered(): void
    {
        $taken = $this->deleting(stored: self::anInvitation());

        self::assertSame(204, $taken->status());
        self::assertSame([], $this->namesIn(self::CYRUS_INBOX));
        self::assertFalse($this->store('calendars/bernard/work')->hasChild(self::NAME));
    }

    /**
     * And granted, the cancellation arrives.
     */
    public function testACancellationTheSenderMayDeliverIsDelivered(): void
    {
        $this->deleting(
            stored: self::anInvitation(),
            delivering: [self::CYRUS_INBOX => [Privileges::DELIVER_INVITE]],
        );

        self::assertCount(1, $this->namesIn(self::CYRUS_INBOX));
    }

    /**
     * **§6.1's second paragraph: the recipient's own calendar is not asked
     * separately.** "The ability to create or update the scheduling object
     * resource in the calendar is controlled by the privileges assigned to
     * the scheduling Inbox collection" — so with the Inbox granted and
     * nothing at all granted on Cyrus' calendar, §4.1's copy still lands
     * there.
     */
    public function testTheRecipientsOwnCalendarIsNotAskedSeparately(): void
    {
        $this->putting(self::anInvitation(), delivering: [
            self::CYRUS_INBOX => [Privileges::DELIVER_INVITE],
        ]);

        self::assertCount(1, $this->namesIn('calendars/cyrus/work'));
    }

    /**
     * An event Bernard organises with Cyrus invited.
     */
    private static function anInvitation(): string
    {
        return self::anObject(
            "ORGANIZER:mailto:bernard@example.com\r\n"
            . "ATTENDEE:mailto:cyrus@example.com\r\n",
        );
    }

    /**
     * The same with Wilfredo on it too, so that one recipient's rules can be
     * told from the other's.
     */
    private static function aMeetingWithBoth(): string
    {
        return self::anObject(
            "ORGANIZER:mailto:bernard@example.com\r\n"
            . "ATTENDEE:mailto:cyrus@example.com\r\n"
            . "ATTENDEE:mailto:wilfredo@example.com\r\n",
        );
    }

    /**
     * The same meeting seen from Cyrus' side, which Bernard's calendar holds
     * as an attendee's copy — so writing it replies to Cyrus.
     */
    private static function aReply(): string
    {
        return self::anObject(
            "ORGANIZER:mailto:cyrus@example.com\r\n"
            . "ATTENDEE;PARTSTAT=ACCEPTED:mailto:bernard@example.com\r\n",
        );
    }

    private static function anObject(string $who): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260602T100000Z\r\n"
            . "DTEND:20260602T110000Z\r\nSUMMARY:A Meeting\r\n" . $who
            . "END:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    /**
     * @param array<string, list<string>> $delivering What the recipient grants,
     *                                                by Inbox path
     */
    private function putting(
        string $body,
        array $delivering = [],
        string $grantedTo = self::BERNARD_HELD,
        string $asking = self::BERNARD,
        bool $access = true,
        bool $groups = false,
    ): Response {
        return $this->serverFor($delivering, $grantedTo, $asking, $access, $groups, null)
            ->handle(new Request('PUT', self::EVENT, body: new Body($body)));
    }

    /**
     * @param array<string, list<string>> $delivering As {@see self::putting()}
     */
    private function deleting(string $stored, array $delivering = []): Response
    {
        return $this->serverFor($delivering, self::BERNARD_HELD, self::BERNARD, true, false, $stored)
            ->handle(new Request('DELETE', self::EVENT));
    }

    /**
     * The server these cases are tried against: Bernard with a calendar, an
     * Inbox and an Outbox, Cyrus and Wilfredo each with a calendar and an
     * Inbox to be delivered to, and whoever manages access told what the
     * writer may do and what each recipient grants.
     *
     * **What the writer may do is never in question here**, §6.2 being the
     * other slice: they always hold what it takes to write and to send, so
     * that the only thing a case can turn on is what the recipient granted.
     *
     * @param array<string, list<string>> $delivering As {@see self::putting()}
     * @param string $grantedTo Whom the recipients grant it to, which §6.1
     *                          makes the sending calendar user rather than
     *                          whoever is writing
     * @param string $asking Who is signed in
     * @param bool $groups Whether this server resolves the groups a principal
     *                     is in (RFC 3744 §2)
     * @param string|null $stored What Bernard's calendar already holds
     */
    private function serverFor(
        array $delivering,
        string $grantedTo,
        string $asking,
        bool $access,
        bool $groups,
        ?string $stored,
    ): Server {
        $users = new MemoryCalendarUserBackend(self::ADDRESSES, '/principals/');
        $this->stores = [];

        foreach ([
            'calendars/bernard/work', 'calendars/bernard/inbox', 'calendars/bernard/outbox',
            'calendars/cyrus/work', self::CYRUS_INBOX,
            'calendars/wilfredo/work', self::WILFREDO_INBOX,
        ] as $path) {
            $this->stores[$path] = new MemoryCollection(basename($path));
        }

        if ($stored !== null) {
            $this->store('calendars/bernard/work')->createFile(self::NAME, $stored);
        }

        $server = new Server(
            new Tree(
                (new MemoryCollection(''))
                    ->add((new MemoryCollection('calendars'))
                        ->add((new MemoryCollection('bernard'))
                            ->add(Calendar::of($this->store('calendars/bernard/work')))
                            ->add(Inbox::of($this->store('calendars/bernard/inbox')))
                            ->add(Outbox::of($this->store('calendars/bernard/outbox'))))
                        ->add($this->homeOf('cyrus'))
                        ->add($this->homeOf('wilfredo')))
                    ->add(self::thePrincipals()),
            ),
            new EventEmitter(),
            new Writer(['DAV:' => 'd', 'urn:ietf:params:xml:ns:caldav' => 'C']),
        );

        $events = $server->events();

        $events->on(PropertiesRequested::class, static function (PropertiesRequested $event) use ($users): void {
            self::answer($event, $users);
        });

        if ($access) {
            $events->on(
                CurrentPrincipalRequested::class,
                static fn (CurrentPrincipalRequested $event) => $event->answerWith($asking),
            );

            $rules = [
                '/' . $asking => [
                    'calendars/bernard/work' => self::WRITING,
                    'calendars/bernard/work/' . self::NAME => self::WRITING,
                    'calendars/bernard/outbox' => self::SENDING,
                ],
            ];

            foreach ($delivering as $inbox => $privileges) {
                $rules[$grantedTo][$inbox] = $privileges;
            }

            (new Acl(
                $server,
                new ArrayPrivilegeResolver(
                    $rules,
                    Privileges::anInboxDefines(Privileges::anOutboxDefines(Privilege::standard())),
                ),
                null,
                false,
                $groups ? new GroupResolver(self::thePrincipalBackend(), 'principals') : null,
            ))->register(new Report($server));
        }

        $server->onMethod('PUT', (new Put($server))(...));
        $server->onMethod('DELETE', (new Delete($server))(...));

        (new Scheduling($server, $users))->register();

        return $server;
    }

    /**
     * A calendar home with a calendar and an Inbox, which is what §4.1 needs
     * of a recipient: the message appears in the Inbox only "once all
     * automatic processing has been done", and that processing puts the event
     * on a calendar of theirs.
     */
    private function homeOf(string $who): MemoryCollection
    {
        return (new MemoryCollection($who))
            ->add(Calendar::of($this->store('calendars/' . $who . '/work')))
            ->add(Inbox::of($this->store('calendars/' . $who . '/inbox')));
    }

    /**
     * Bernard is in `staff`, which is what the group case grants to.
     */
    private static function thePrincipalBackend(): MemoryPrincipalBackend
    {
        return new MemoryPrincipalBackend(
            new PrincipalInfo('bernard', 'Bernard', [], ['staff']),
            new PrincipalInfo('cyrus', 'Cyrus'),
            new PrincipalInfo('wilfredo', 'Wilfredo'),
            new PrincipalInfo('delegate', 'A delegate'),
            new PrincipalInfo('staff', 'The staff', [], [], ['bernard']),
        );
    }

    private static function thePrincipals(): MemoryCollection
    {
        $collection = new MemoryCollection('principals');

        foreach (['bernard' => 'Bernard', 'cyrus' => 'Cyrus', 'wilfredo' => 'Wilfredo', 'delegate' => 'A delegate', 'staff' => 'The staff'] as $name => $display) {
            $collection->add(new Principal(new PrincipalInfo($name, $display)));
        }

        return $collection;
    }

    /**
     * The store behind that collection.
     */
    private function store(string $path): MemoryCollection
    {
        return $this->stores[$path] ?? self::fail('No such collection: ' . $path);
    }

    /**
     * What is in that collection now, by name.
     *
     * @return list<string>
     */
    private function namesIn(string $path): array
    {
        $names = [];

        foreach ($this->store($path)->children() as $child) {
            $names[] = $child->name();
        }

        return $names;
    }

    /**
     * Bernard's resource as it was stored, which is where a `SCHEDULE-STATUS`
     * is written.
     */
    private function storedEvent(): string
    {
        $file = $this->store('calendars/bernard/work')->child(self::NAME);
        $content = $file instanceof IFile ? $file->get() : null;

        return is_string($content) ? $content : self::fail('Nothing was stored.');
    }

    /**
     * The three properties this plugin reads, answered the way a deployment
     * answers them.
     */
    private static function answer(PropertiesRequested $event, MemoryCalendarUserBackend $users): void
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

        if (!$result->wants(self::OWNER) || count($segments) !== 3) {
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
