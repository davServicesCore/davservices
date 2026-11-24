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
use DavServices\CalDav\Schedule\ScheduleResponse;
use DavServices\Dav\Acl\PrincipalInfo;
use DavServices\Dav\Event\CurrentPrincipalRequested;
use DavServices\Dav\Event\PrivilegeNeeded;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\Method\Post;
use DavServices\Dav\Method\Report;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Plugin\Acl;
use DavServices\Plugin\Scheduling;
use DavServices\Tests\Unit\Acl\ArrayPrivilegeResolver;
use DavServices\Tests\Unit\Backend\MemoryCalendarUserBackend;
use DavServices\Tests\Unit\Backend\MemoryPrincipalBackend;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Xml\Element;
use DavServices\Xml\Writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for RFC 6638 §6.2.4 and §6.1.4 enforced — the free-busy pair, and
 * the last of §6. R-CAL-42, P8-07's third slice.
 *
 * Two privileges govern one request, and Appendix A's two `VFREEBUSY` tables
 * are where that is written out: `CALDAV:schedule-send-freebusy` on the
 * asker's own Outbox, and `CALDAV:schedule-query-freebusy` on the Inbox of
 * each calendar user asked about. They answer differently, because they are
 * about different things.
 *
 * ## §6.2.4 refuses the request
 *
 * > The CALDAV:schedule-send-freebusy privilege controls **the use of the
 * > POST method** to submit scheduling messages that specify the scheduling
 * > method "REQUEST" with a "VFREEBUSY" calendar component.
 *
 * The method itself is what is controlled, so a client without it is
 * answered §5.1's "403 (Forbidden) - The client cannot submit a scheduling
 * message to the specified Request-URI" — and §5.2 says what the body holds,
 * listing `DAV:need-privileges` among "existing preconditions that are reused
 * for the POST method on an Outbox collection". Appendix B.6 shows that
 * shape for the sibling privilege, down to the Outbox href.
 *
 * **"To submit scheduling messages that specify … REQUEST with a VFREEBUSY"
 * is a condition, and it decides the order.** A body that is no such message
 * is not what §6.2.4 governs, so it is still answered §5.2.1's `400` rather
 * than a `403` — the privilege is asked once the body is known to be a
 * free-busy request. And it is asked **before** §5.2.2, because a client that
 * may not post at all is told nothing about whose Outbox this is.
 *
 * ## §6.1.4 answers the recipient
 *
 * > The CALDAV:schedule-query-freebusy privilege controls freebusy requests
 * > targeted at the owner of the scheduling Inbox collection.
 *
 * One recipient's rules cannot decide what is said about the others, so this
 * is reported per recipient and the rest are still answered — the same shape
 * §3.2.9 gives the delivery side. The code is RFC 5546 §3.6.21's `3.8`, whose
 * status description is "No authority", and §10.4 has the element hold "the
 * iTIP 'REQUEST-STATUS' property value for this response".
 *
 * **Whose grant it is, is Appendix A.1's own sentence**: the tables "specify
 * which scheduling privileges grant the right to **a calendar user** to
 * deliver a scheduling message to the scheduling Inbox collection of
 * **another calendar user**". So the right belongs to the asking calendar
 * user — and §5 says who that is: the POST is "targeted at the scheduling
 * Outbox collection of the calendar user requesting the information (the
 * 'Organizer')", whom §5.2.2 ties to that Outbox's owner. A delegate posting
 * on their behalf therefore asks on the owner's standing, and there is a case
 * each way round.
 *
 * ## What is left out, and why
 *
 * §10.2 lets a `CALDAV:response` carry `DAV:error?`, so the refusal this
 * server already has in hand could be put beside the `3.8`. It is not, and
 * the reason is that it would say nothing a client does not know: §7.1.1's
 * element names the resource that lacked the privilege, and here that is the
 * Inbox of the calendar user the client itself asked about. On the write side
 * it was the other way round — the Outbox was a collection the client never
 * named, which is why Appendix B.6 shows it.
 */
#[CoversClass(Scheduling::class)]
#[CoversClass(ScheduleResponse::class)]
#[CoversClass(PrivilegeNeeded::class)]
final class SchedulingFreeBusyPrivilegesTest extends TestCase
{
    private const NS = 'urn:ietf:params:xml:ns:caldav';

    private const CYRUS = 'mailto:cyrus@example.com';

    private const WILFREDO = 'mailto:wilfredo@example.com';

    private const BERNARD = 'mailto:bernard@example.net';

    private const ADDRESSES = [
        'cyrus' => [self::CYRUS],
        'wilfredo' => [self::WILFREDO],
        'bernard' => [self::BERNARD],
    ];

    /** Whoever is signed in, as a path inside the tree. */
    private const ASKING = 'principals/cyrus';

    private const DELEGATE = 'principals/delegate';

    /** The same, as an access control entry names a principal. */
    private const CYRUS_HELD = '/principals/cyrus';

    private const DELEGATE_HELD = '/principals/delegate';

    private const STAFF_HELD = '/principals/staff';

    private const OUTBOX = 'calendars/cyrus/outbox';

    private const WILFREDO_INBOX = 'calendars/wilfredo/inbox';

    private const BERNARD_INBOX = 'calendars/bernard/inbox';

    private const OWNER = '{DAV:}owner';

    private const HOME_SET = '{urn:ietf:params:xml:ns:caldav}calendar-home-set';

    private const ADDRESS_SET = '{urn:ietf:params:xml:ns:caldav}calendar-user-address-set';

    /** The one thing a request has to be allowed before anything else. */
    private const SENDING = [Privileges::SEND_FREEBUSY];

    /**
     * **§6.2.4: without it the request is refused**, and §5.2's reused
     * precondition says in what shape.
     */
    public function testAPostWithoutTheSendPrivilegeIsRefused(): void
    {
        $refused = $this->post([self::WILFREDO], sending: []);

        self::assertSame(403, $refused->status());
        self::assertStringContainsString('need-privileges', (string) $refused->body());
        self::assertStringContainsString('schedule-send-freebusy', (string) $refused->body());
    }

    /**
     * **And it names the Outbox it wanted the privilege on**, which is where
     * §6.2.4 has it granted and what Appendix B.6 shows for the sibling
     * privilege.
     */
    public function testTheRefusalNamesTheOutbox(): void
    {
        $refused = $this->post([self::WILFREDO], sending: []);

        self::assertStringContainsString('/calendars/cyrus/outbox/', (string) $refused->body());
    }

    /**
     * **And with it the request is answered** — the method is allowed, which
     * is all §6.2.4 decides. What each calendar user asked about says is
     * §6.1.4's, and is the half below.
     */
    public function testAPostWithTheSendPrivilegeGoesThrough(): void
    {
        $answered = $this->post([self::WILFREDO]);

        self::assertSame(200, $answered->status());
        self::assertStringContainsString('schedule-response', (string) $answered->body());
        self::assertStringNotContainsString('need-privileges', (string) $answered->body());
    }

    /**
     * **A body that is no free-busy request is still a `400`.** §6.2.4
     * governs the use of the method "to submit scheduling messages that
     * specify the scheduling method 'REQUEST' with a 'VFREEBUSY' calendar
     * component", and a body that is none of that is not what it governs —
     * so §5.2.1's precondition answers first, whatever the client holds.
     */
    public function testABodyThatIsNoFreeBusyRequestIsRefusedAsInvalidEvenWithoutThePrivilege(): void
    {
        $refused = $this->posting("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n", sending: []);

        self::assertSame(400, $refused->status());
        self::assertStringContainsString('valid-scheduling-message', (string) $refused->body());
    }

    /**
     * **And a client that may not post at all is told nothing about whose
     * Outbox this is.** §5.2.2 compares the `ORGANIZER` against the owner's
     * calendar user addresses; answering that first would let somebody
     * without §6.2.4's privilege learn whether a guessed address is one of
     * them.
     */
    public function testTheMissingPrivilegeIsAnsweredBeforeTheOrganizerIsChecked(): void
    {
        $refused = $this->post([self::WILFREDO], sending: [], organizer: self::BERNARD);

        self::assertSame(403, $refused->status());
        self::assertStringContainsString('need-privileges', (string) $refused->body());
        self::assertStringNotContainsString('valid-organizer', (string) $refused->body());
    }

    /**
     * **§6.1.4: a recipient the asker may not query is answered `3.8`**, RFC
     * 5546 §3.6.21's "No authority" — and nothing is said about when they are
     * busy.
     */
    public function testARecipientTheAskerMayNotQueryIsAnsweredWithNoAuthority(): void
    {
        $answered = $this->post([self::WILFREDO]);

        self::assertSame(200, $answered->status());
        self::assertSame([ScheduleResponse::NO_AUTHORITY], $this->statusesOf($answered));
        self::assertStringNotContainsString('FREEBUSY', (string) $answered->body());
    }

    /**
     * **And the others are still answered**, which is why this reports rather
     * than refuses: one calendar user's rules do not decide what is said
     * about anybody else.
     */
    public function testTheOtherRecipientsAreStillAnswered(): void
    {
        $answered = $this->post([self::WILFREDO, self::BERNARD], querying: [
            self::BERNARD_INBOX => [Privileges::QUERY_FREEBUSY],
        ]);

        self::assertSame(
            [ScheduleResponse::NO_AUTHORITY, ScheduleResponse::SUCCESS],
            $this->statusesOf($answered),
        );
    }

    /**
     * And granted, the busy time comes back.
     */
    public function testARecipientTheAskerMayQueryIsAnswered(): void
    {
        $answered = $this->post([self::WILFREDO], querying: [
            self::WILFREDO_INBOX => [Privileges::QUERY_FREEBUSY],
        ]);

        self::assertSame([ScheduleResponse::SUCCESS], $this->statusesOf($answered));
        self::assertStringContainsString('FREEBUSY', (string) $answered->body());
    }

    /**
     * **§6.1.1 and §6.3: the aggregate carries it**, which Appendix A.1's
     * first row shows — `schedule-deliver` is starred in the `VFREEBUSY`
     * table as well. A calendar user who granted the whole branch has granted
     * this.
     */
    public function testTheDeliverAggregateCarriesTheQueryRight(): void
    {
        $answered = $this->post([self::WILFREDO], querying: [
            self::WILFREDO_INBOX => [Privileges::DELIVER],
        ]);

        self::assertSame([ScheduleResponse::SUCCESS], $this->statusesOf($answered));
    }

    /**
     * **The right is the Outbox owner's, not the asker's own.** §5 has the
     * POST "targeted at the scheduling Outbox collection of the calendar user
     * requesting the information (the 'Organizer')", and §5.2.2 ties that
     * owner to the `ORGANIZER`; Appendix A.1 grants the right to "a calendar
     * user" asking about "another calendar user". So a delegate posting for
     * Cyrus asks on Cyrus' standing.
     */
    public function testTheRightIsTheOutboxOwnersAndNotTheAskersOwn(): void
    {
        $answered = $this->post(
            [self::WILFREDO],
            querying: [self::WILFREDO_INBOX => [Privileges::QUERY_FREEBUSY]],
            asking: self::DELEGATE,
        );

        self::assertSame([ScheduleResponse::SUCCESS], $this->statusesOf($answered));
    }

    /**
     * **And a right the asker holds in their own name is no help**, the same
     * sentence read the other way.
     */
    public function testTheAskersOwnRightDoesNotQueryForSomebodyElse(): void
    {
        $answered = $this->post(
            [self::WILFREDO],
            querying: [self::WILFREDO_INBOX => [Privileges::QUERY_FREEBUSY]],
            grantedTo: self::DELEGATE_HELD,
            asking: self::DELEGATE,
        );

        self::assertSame([ScheduleResponse::NO_AUTHORITY], $this->statusesOf($answered));
    }

    /**
     * **A group the asker is in may be granted it**, RFC 3744 §5.5.1 and §2
     * holding here as everywhere.
     */
    public function testAGroupTheAskerIsInMayBeGrantedIt(): void
    {
        $answered = $this->post(
            [self::WILFREDO],
            querying: [self::WILFREDO_INBOX => [Privileges::QUERY_FREEBUSY]],
            grantedTo: self::STAFF_HELD,
            groups: true,
        );

        self::assertSame([ScheduleResponse::SUCCESS], $this->statusesOf($answered));
    }

    /**
     * **A calendar user with no Inbox is not refused.** §6.1.4 wants the
     * privilege on "the scheduling Inbox collection", and §6.1 adds that its
     * privileges "are ignored if applied to a resource other than a
     * scheduling Inbox collection" — so a calendar user this server publishes
     * none for has nowhere the grant could have been made, and "no authority"
     * would describe a privilege nobody could hold.
     */
    public function testACalendarUserWithNoInboxIsNotRefused(): void
    {
        $answered = $this->post([self::WILFREDO], inboxes: false);

        self::assertSame([ScheduleResponse::SUCCESS], $this->statusesOf($answered));
    }

    /**
     * **And where nothing manages access, everything is answered.** With no
     * ACL plugin there is nobody to answer {@see PrivilegeNeeded}, for either
     * of the two privileges — R-ARC-02 again.
     */
    public function testWhereNothingManagesAccessEverythingIsAnswered(): void
    {
        $answered = $this->post([self::WILFREDO], access: false);

        self::assertSame(200, $answered->status());
        self::assertSame([ScheduleResponse::SUCCESS], $this->statusesOf($answered));
    }

    /**
     * The request Appendix B.5 makes, asking about those calendar users.
     *
     * @param list<string> $recipients
     */
    private static function aRequest(array $recipients, string $organizer): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Example Corp.//CalDAV Client//EN',
            'METHOD:REQUEST',
            'BEGIN:VFREEBUSY',
            'UID:4FD3AD926350',
            'DTSTAMP:20090602T190420Z',
            'DTSTART:20090602T000000Z',
            'DTEND:20090604T000000Z',
            'ORGANIZER:' . $organizer,
        ];

        foreach ($recipients as $recipient) {
            $lines[] = 'ATTENDEE:' . $recipient;
        }

        return implode("\r\n", [...$lines, 'END:VFREEBUSY', 'END:VCALENDAR']) . "\r\n";
    }

    /**
     * @param list<string> $recipients Who is asked about
     * @param array<string, list<string>> $querying What each recipient grants,
     *                                              by Inbox path
     * @param list<string> $sending What the asker may do on their own Outbox
     */
    private function post(
        array $recipients,
        array $querying = [],
        array $sending = self::SENDING,
        string $grantedTo = self::CYRUS_HELD,
        string $asking = self::ASKING,
        string $organizer = self::CYRUS,
        bool $access = true,
        bool $groups = false,
        bool $inboxes = true,
    ): Response {
        return $this->posting(
            self::aRequest($recipients, $organizer),
            $querying,
            $sending,
            $grantedTo,
            $asking,
            $access,
            $groups,
            $inboxes,
        );
    }

    /**
     * @param array<string, list<string>> $querying As {@see self::post()}
     * @param list<string> $sending As {@see self::post()}
     */
    private function posting(
        string $body,
        array $querying = [],
        array $sending = self::SENDING,
        string $grantedTo = self::CYRUS_HELD,
        string $asking = self::ASKING,
        bool $access = true,
        bool $groups = false,
        bool $inboxes = true,
    ): Response {
        return $this->serverFor($querying, $sending, $grantedTo, $asking, $access, $groups, $inboxes)
            ->handle(new Request(
                'POST',
                '/' . self::OUTBOX,
                headers: new Headers(['Content-Type' => 'text/calendar; charset="utf-8"']),
                body: new Body($body),
            ));
    }

    /**
     * Cyrus with an Outbox to post to, Wilfredo and Bernard each with a
     * calendar to be asked about and — unless a case says otherwise — an
     * Inbox to grant on.
     *
     * @param array<string, list<string>> $querying As {@see self::post()}
     * @param list<string> $sending As {@see self::post()}
     * @param string $grantedTo Whom the recipients grant it to, which
     *                          Appendix A.1 makes the asking calendar user
     *                          rather than whoever is signed in
     * @param string $asking Who is signed in
     * @param bool $groups Whether this server resolves the groups a principal
     *                     is in (RFC 3744 §2)
     * @param bool $inboxes Whether the calendar users asked about have an
     *                      Inbox at all
     */
    private function serverFor(
        array $querying,
        array $sending,
        string $grantedTo,
        string $asking,
        bool $access,
        bool $groups,
        bool $inboxes,
    ): Server {
        $users = new MemoryCalendarUserBackend(self::ADDRESSES, '/principals/');
        $events = new EventEmitter();

        $events->on(PropertiesRequested::class, static function (PropertiesRequested $event) use ($users): void {
            self::answer($event, $users);
        });

        $server = new Server(
            new Tree(
                (new MemoryCollection(''))
                    ->add((new MemoryCollection('calendars'))
                        ->add((new MemoryCollection('cyrus'))
                            ->add(Outbox::of(new MemoryCollection('outbox')))
                            ->add(Inbox::of(new MemoryCollection('inbox')))
                            ->add(Calendar::of(self::aCalendarCalled('work', ['20090603T140000Z', '20090603T150000Z']))))
                        ->add(self::theHomeOf('wilfredo', ['20090602T110000Z', '20090602T120000Z'], $inboxes))
                        ->add(self::theHomeOf('bernard', ['20090602T150000Z', '20090602T160000Z'], $inboxes)))
                    ->add(self::thePrincipals()),
            ),
            $events,
            new Writer(['DAV:' => 'd', self::NS => 'C']),
        );

        if ($access) {
            $events->on(
                CurrentPrincipalRequested::class,
                static fn (CurrentPrincipalRequested $event) => $event->answerWith($asking),
            );

            $rules = ['/' . $asking => [self::OUTBOX => $sending]];

            foreach ($querying as $inbox => $privileges) {
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

        $post = new Post($server);

        (new Scheduling($server, $users))->register($post);
        $server->onMethod('POST', $post(...));

        return $server;
    }

    /**
     * A calendar home with one busy hour in it, and an Inbox where a case
     * wants one.
     *
     * @param array{string, string} $hour
     */
    private static function theHomeOf(string $who, array $hour, bool $inbox): MemoryCollection
    {
        $home = (new MemoryCollection($who))->add(Calendar::of(self::aCalendarCalled('work', $hour)));

        return $inbox ? $home->add(Inbox::of(new MemoryCollection('inbox'))) : $home;
    }

    /**
     * @param array{string, string} ...$hours
     */
    private static function aCalendarCalled(string $name, array ...$hours): MemoryCollection
    {
        $calendar = new MemoryCollection($name);

        foreach ($hours as $number => [$from, $until]) {
            $calendar->add(new MemoryFile(
                $number . '.ics',
                "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\n"
                . "BEGIN:VEVENT\r\nUID:" . $name . '-' . $number . "@example.com\r\n"
                . 'DTSTAMP:20090601T000000Z' . "\r\nDTSTART:" . $from . "\r\nDTEND:" . $until
                . "\r\nSUMMARY:An hour\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
            ));
        }

        return $calendar;
    }

    /**
     * Cyrus is in `staff`, which the group case grants to.
     */
    private static function thePrincipalBackend(): MemoryPrincipalBackend
    {
        return new MemoryPrincipalBackend(
            new PrincipalInfo('cyrus', 'Cyrus', [], ['staff']),
            new PrincipalInfo('wilfredo', 'Wilfredo'),
            new PrincipalInfo('bernard', 'Bernard'),
            new PrincipalInfo('delegate', 'A delegate'),
            new PrincipalInfo('staff', 'The staff', [], [], ['cyrus']),
        );
    }

    private static function thePrincipals(): MemoryCollection
    {
        $collection = new MemoryCollection('principals');

        foreach (['cyrus', 'wilfredo', 'bernard', 'delegate', 'staff'] as $name) {
            $collection->add(new Principal(new PrincipalInfo($name, ucfirst($name))));
        }

        return $collection;
    }

    /**
     * The status reported for each calendar user, in the order they were
     * asked about.
     *
     * @return list<string>
     */
    private function statusesOf(Response $answered): array
    {
        $document = (new Server(new Tree(new MemoryCollection('')), new EventEmitter()))
            ->reader()
            ->parse((string) $answered->body());
        $statuses = [];

        foreach ($document->children() as $response) {
            $statuses[] = $response->child('{' . self::NS . '}request-status')?->text()
                ?? self::fail('A response with no status.');
        }

        return $statuses;
    }

    /**
     * The three properties this plugin reads for §5, answered the way a
     * deployment answers them.
     */
    private static function answer(PropertiesRequested $event, MemoryCalendarUserBackend $users): void
    {
        $result = $event->result();
        $segments = explode('/', trim($result->path(), '/'));

        if ($event->node() instanceof Principal) {
            if ($result->wants(self::HOME_SET)) {
                $result->set(self::HOME_SET, self::naming(self::HOME_SET, '/calendars/' . ($segments[1] ?? '') . '/'));
            }

            if ($result->wants(self::ADDRESS_SET)) {
                $result->set(
                    self::ADDRESS_SET,
                    self::naming(self::ADDRESS_SET, ...$users->addressesOf($segments[1] ?? '')),
                );
            }

            return;
        }

        if ($result->wants(self::OWNER) && $segments[0] === 'calendars' && count($segments) === 3) {
            $result->set(self::OWNER, self::naming(self::OWNER, '/principals/' . $segments[1] . '/'));
        }
    }

    private static function naming(string $property, string ...$hrefs): Element
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
