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

use DavServices\CalDav\Calendar;
use DavServices\CalDav\Limits;
use DavServices\CalDav\Schedule\Inbox;
use DavServices\CalDav\Schedule\Outbox;
use DavServices\Dav\Method\Put;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Http\Body;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Plugin\Scheduling;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for RFC 6638 §11.1, the sentence that says what a scheduling
 * collection keeps out. R-CAL-35.
 *
 * > Servers MUST ensure that clients cannot consume excessive server
 * > resources by carrying out "large" scheduling operations. In particular,
 * > servers SHOULD enforce CALDAV:max-resource-size, CALDAV:max-instances,
 * > and CALDAV:max-attendees-per-instance preconditions as applicable for
 * > scheduling Inbox and Outbox collections.
 *
 * **Three are named, and only three.** `CALDAV:max-resource-size`,
 * `CALDAV:max-instances` and `CALDAV:max-attendees-per-instance` — the
 * preconditions RFC 4791 §5.3.2.1 defines and {@see Limits} already keeps on
 * a calendar collection. The other three questions `Limits::mustTake()` asks
 * of a calendar are **not** in this sentence: `CALDAV:min-date-time` and
 * `CALDAV:max-date-time`, and the kind of component. Each has a case here
 * saying so, because a guard that refuses more than its sentence allows is as
 * wrong as one that refuses less.
 *
 * **Which is a choice this server makes, not one the memo makes for it**, and
 * an earlier wording here claimed otherwise. §2.2 lets all five be defined on
 * an Inbox — "MAY also be defined on scheduling Inbox collections **and apply
 * to scheduling messages delivered to the collection**" — so a server that
 * published `CALDAV:min-date-time` there would hold deliveries to it and be
 * right to. This one publishes exactly the three it keeps
 * ({@see \DavServices\Plugin\Scheduling::INBOX_LIMITS}), so none of the five
 * applies here; §11.1 asks for the three and is silent on the rest.
 *
 * **"As applicable" is what decides the Outbox, and it decides it away.**
 * All three preconditions are about a calendar object resource being
 * **stored**, and nothing can be stored in an Outbox at all: §2.1 reserves
 * what such a resource would mean and
 * {@see \DavServices\CalDav\Schedule\Outbox::createFile()} refuses outright.
 * What §11.1 reaches there is §5's `POST`, already held to the two date
 * limits and a count of the objects it may walk. So the guard is on the
 * Inbox, and a case below says the Outbox's refusal is §2.1's rather than a
 * precondition of §5.3.2.1.
 *
 * **And the MUST above the SHOULD is about carrying out.** The questions are
 * therefore asked of the request as it arrives, before anything is stored,
 * which is where {@see Scheduling} already hangs its delivery.
 */
#[CoversClass(Scheduling::class)]
#[CoversClass(Limits::class)]
final class SchedulingLimitsTest extends TestCase
{
    private const INBOX = '/calendars/bernard/inbox/new.ics';

    private const OUTBOX = '/calendars/bernard/outbox/new.ics';

    private const MEETING = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
        . "PRODID:-//Example Corp.//CalDAV Client//EN\r\n"
        . "BEGIN:VEVENT\r\nUID:1\@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
        . "DTSTART:20260105T120000Z\r\nDTEND:20260105T130000Z\r\nSUMMARY:A Meeting\r\n"
        . "END:VEVENT\r\nEND:VCALENDAR\r\n";

    /**
     * **§5.2.5's precondition, on the Inbox.** The resource as it arrived is
     * what §5.2.5 measures, so this is the cheapest of the three and the one
     * that needs nothing parsed.
     */
    public function testAnObjectLargerThanTheServerKeepsIsRefusedInAnInbox(): void
    {
        $refused = $this->putting(self::INBOX, self::MEETING, new Limits(maxResourceSize: 10));

        self::assertSame(413, $refused->status());
        self::assertStringContainsString('max-resource-size', (string) $refused->body());
    }

    /**
     * **And a resource replaced is measured like one created.** §3.2.3.1's
     * table knows both and §11.1 distinguishes neither: a client that may
     * write over what stands in its Inbox can send as much that way as by
     * creating something new. That is why the guard is registered on
     * `BeforeWriteContent` beside `BeforeCreateFile`, and this case is what
     * holds it there — the mutation run found the second registration could
     * be taken away without a single case falling, because every other one
     * here writes to a name that is free.
     */
    public function testAnObjectLargerThanTheServerKeepsIsRefusedWhenItReplacesOne(): void
    {
        $server = $this->serverTaking(new Limits(maxResourceSize: 1_000));
        $made = $server->handle(new Request('PUT', self::INBOX, body: new Body(self::MEETING)));

        self::assertContains($made->status(), [201, 204]);

        $long = str_replace('SUMMARY:A Meeting', 'SUMMARY:' . str_repeat('A', 2_000), self::MEETING);
        $refused = $server->handle(new Request('PUT', self::INBOX, body: new Body($long)));

        self::assertSame(413, $refused->status());
        self::assertStringContainsString('max-resource-size', (string) $refused->body());
    }

    /**
     * **§5.2.8's precondition, on the Inbox.** A rule with no end generates
     * more than any limit, and the count stops at the first instance past it
     * rather than expanding the whole series — which is the MUST above the
     * SHOULD, about carrying out rather than about answering.
     */
    public function testASeriesOfMoreInstancesThanTheServerKeepsIsRefusedInAnInbox(): void
    {
        $series = $this->with("RRULE:FREQ=DAILY\r\n");
        $refused = $this->putting(self::INBOX, $series, new Limits(maxInstances: 1));

        self::assertSame(403, $refused->status());
        self::assertStringContainsString('max-instances', (string) $refused->body());
    }

    /**
     * **§5.2.9's precondition, on the Inbox**, which is per instance.
     */
    public function testAnInstanceOfMoreAttendeesThanTheServerKeepsIsRefusedInAnInbox(): void
    {
        $crowded = $this->with(
            "ATTENDEE:mailto:cyrus\@example.com\r\nATTENDEE:mailto:eric\@example.com\r\n",
        );
        $refused = $this->putting(self::INBOX, $crowded, new Limits(maxAttendees: 1));

        self::assertSame(403, $refused->status());
        self::assertStringContainsString('max-attendees-per-instance', (string) $refused->body());
    }

    /**
     * **The sentence names the Outbox too, and "as applicable" is what
     * settles it there: nothing applies.** Every one of the three is about a
     * calendar object resource being **stored** — RFC 4791 §5.2.5 says so in
     * as many words, "the maximum size of a resource in octets that the
     * server is willing to accept when a calendar object resource is stored
     * in a calendar collection" — and {@see Outbox::createFile()} already
     * refuses outright, §2.1 reserving what such a resource would mean. A
     * request that cannot store anything cannot be held to a limit on what is
     * stored.
     *
     * So the refusal here is §2.1's and names no precondition of §5.3.2.1,
     * and this case stands to keep a later guard from reaching where it has
     * nothing to ask. What §11.1 does reach on an Outbox is §5's `POST`,
     * which is held to `CALDAV:min-date-time`, `CALDAV:max-date-time` and a
     * count of the objects it may walk — the half of §11.1 that is already
     * kept.
     */
    public function testWhatAnOutboxRefusesIsNotALimitButSection21(): void
    {
        $refused = $this->putting(self::OUTBOX, self::MEETING, new Limits(maxResourceSize: 10));

        self::assertSame(403, $refused->status());
        self::assertStringNotContainsString('max-resource-size', (string) $refused->body());
    }

    /**
     * **An object inside all three is taken**, which is the half of the guard
     * that says it refuses nothing else.
     */
    public function testAnObjectWithinEveryLimitIsTakenInAnInbox(): void
    {
        self::assertContains($this->putting(self::INBOX, self::MEETING)->status(), [201, 204]);
    }

    /**
     * **A date outside the server's range is taken all the same.** §11.1 does
     * not name `CALDAV:min-date-time` or `CALDAV:max-date-time`, and neither
     * is about exhausting a server: an invitation to a distant year is one
     * instance like any other.
     *
     * **This server therefore does not publish them on an Inbox**, and that
     * is the whole of why they do not apply — §2.2 would let it, and then
     * they would. See {@see \DavServices\Plugin\Scheduling::INBOX_LIMITS}.
     */
    public function testADateOutsideTheServersRangeIsTakenInAnInbox(): void
    {
        $distant = str_replace('20260105T1', '18990105T1', self::MEETING);

        self::assertContains($this->putting(self::INBOX, $distant)->status(), [201, 204]);
    }

    /**
     * **And a kind the calendars do not take is taken here too.** §11.1 names
     * three preconditions and `CALDAV:supported-calendar-component-set` is
     * not among them. RFC 5546 carries `VTODO` and `VJOURNAL` as readily as
     * `VEVENT`, so what a deployment chooses to keep in its calendars says
     * nothing about what may be delivered to it.
     */
    public function testAKindTheCalendarsDoNotTakeIsTakenInAnInbox(): void
    {
        $task = str_replace('VEVENT', 'VTODO', self::MEETING);
        $only = new Limits(kinds: ['VEVENT']);

        self::assertContains($this->putting(self::INBOX, $task, $only)->status(), [201, 204]);
    }

    /**
     * **A body that is no calendar object is measured all the same.**
     * §5.3.2.1 asks the octet size of "the resource submitted in the PUT
     * request" and asks nothing of what it says, so refusing one too large to
     * keep needs nothing read. The other two have nothing to ask of it: a
     * body with no object in it has no instance to count and no attendee to
     * crowd. What may be written into an Inbox at all is §2.2's question and
     * not this one's.
     */
    public function testABodyThatIsNoCalendarObjectIsMeasuredButNotRead(): void
    {
        $refused = $this->putting(self::INBOX, 'not a calendar', new Limits(maxResourceSize: 5));

        self::assertSame(413, $refused->status());
        self::assertStringContainsString('max-resource-size', (string) $refused->body());

        self::assertContains(
            $this->putting(self::INBOX, 'not a calendar', new Limits(maxInstances: 0, maxAttendees: 0))->status(),
            [201, 204],
        );
    }

    /**
     * **Nothing else is guarded by this.** A calendar collection has its own
     * guard in {@see \DavServices\Plugin\CalDav}, and an ordinary collection
     * has none to do with scheduling — so neither is refused here, however
     * low the limits are set.
     */
    public function testNeitherACalendarNorAnOrdinaryCollectionIsGuardedHere(): void
    {
        $tiny = new Limits(maxResourceSize: 10);

        self::assertContains(
            $this->putting('/calendars/bernard/work/new.ics', self::MEETING, $tiny)->status(),
            [201, 204],
        );
        self::assertContains(
            $this->putting('/plain/new.ics', self::MEETING, $tiny)->status(),
            [201, 204],
        );
    }

    /**
     * The meeting with those lines added to its `VEVENT`.
     */
    private function with(string $lines): string
    {
        return str_replace("END:VEVENT\r\n", $lines . "END:VEVENT\r\n", self::MEETING);
    }

    private function putting(string $path, string $body, ?Limits $limits = null): Response
    {
        return $this->serverTaking($limits)->handle(new Request('PUT', $path, body: new Body($body)));
    }

    /**
     * A server with the plugin wired, a scheduling Inbox, an Outbox, a
     * calendar and a collection that is none of those.
     */
    private function serverTaking(?Limits $limits = null): Server
    {
        $tree = new Tree(
            (new MemoryCollection(''))
                ->add((new MemoryCollection('calendars'))
                    ->add((new MemoryCollection('bernard'))
                        ->add(Inbox::of(new MemoryCollection('inbox')))
                        ->add(Outbox::of(new MemoryCollection('outbox')))
                        ->add(Calendar::of(new MemoryCollection('work')))))
                ->add(new MemoryCollection('plain')),
        );

        $server = new Server($tree);
        $server->onMethod('PUT', (new Put($server))(...));

        (new Scheduling($server, null, $limits ?? new Limits()))->register();

        return $server;
    }
}
