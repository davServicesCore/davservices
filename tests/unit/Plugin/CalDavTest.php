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
use DavServices\CalDav\Method\MkCalendar;
use DavServices\Dav\Event\BeforeCopy;
use DavServices\Dav\Event\BeforeMove;
use DavServices\Dav\Method\Copy;
use DavServices\Dav\Method\Move;
use DavServices\Dav\Method\Options;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Exception\Forbidden;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Plugin\CalDav;
use DavServices\Tests\Unit\CalDav\MemoryCalendarHome;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for the CalDAV plugin. R-CAL-06, P6-01.
 *
 * **What this plugin answers is the question no node can:** §4.2(b) forbids a
 * calendar collection below a calendar collection "at any depth", and that is a
 * question about *where* a path lies. A node knows its name and nothing about
 * where it hangs — so `CalDav\Calendar` does the half it can see, by not being
 * an extended collection, and the other half is here.
 *
 * ## And one thing it deliberately does not do yet
 *
 * **It does not announce `calendar-access`.** §5.1 makes that value a promise
 * with teeth: "A value of 'calendar-access' in the DAV response header MUST
 * indicate that the server supports all MUST level requirements specified in
 * this document" — and §7.8 makes `CALDAV:calendar-query` one of them. A server
 * announcing the class before the reports exist would send every client
 * straight into a `403`, which is worse than a server a client does not try.
 * The same reasoning kept `CARDDAV:supported-collation-set` out of P5-01 until
 * the collations existed.
 */
#[CoversClass(CalDav::class)]
final class CalDavTest extends TestCase
{
    private const LOCATION_OK = 'calendar-collection-location-ok';

    /**
     * §4.2(b): "Collections contained in calendar collections MUST NOT contain
     * calendar collections at any depth."
     */
    public function testACalendarMayNotBeMadeInsideACalendar(): void
    {
        $server = $this->server();
        $response = $server->handle(new Request('MKCALENDAR', '/calendars/work/nested'));

        self::assertSame(403, $response->status());
        self::assertStringContainsString(self::LOCATION_OK, (string) $response->body());
    }

    /**
     * **And not at any depth below one**, which is the half of §4.2(b) that is
     * easy to get wrong: §4.2(a) allows "collections that are not calendar
     * collections" inside a calendar, so there is a legal plain folder in
     * there — and a calendar may not go into it either.
     */
    public function testACalendarMayNotBeMadeInAFolderInsideACalendar(): void
    {
        $server = $this->server();
        $response = $server->handle(new Request('MKCALENDAR', '/calendars/work/attachments/nested'));

        self::assertSame(403, $response->status());
        self::assertStringContainsString(self::LOCATION_OK, (string) $response->body());
    }

    /**
     * A calendar somewhere ordinary is the whole point of the method, so the
     * guard has to let it through.
     */
    public function testACalendarSomewhereOrdinaryIsMade(): void
    {
        self::assertSame(201, $this->server()->handle(new Request('MKCALENDAR', '/calendars/soccer'))->status());
    }

    /**
     * §4.2: "Multiple calendar collections MAY be children of the same
     * collection." So a sibling is no reason to refuse.
     */
    public function testASecondCalendarBesideTheFirstIsMade(): void
    {
        $server = $this->server();

        self::assertSame(201, $server->handle(new Request('MKCALENDAR', '/calendars/soccer'))->status());
        self::assertSame(201, $server->handle(new Request('MKCALENDAR', '/calendars/hockey'))->status());
    }

    /**
     * **A plain collection inside a calendar is allowed** — §4.2(a) says so in
     * as many words — so the guard looks at what is being made, not only at
     * where.
     */
    public function testAPlainCollectionInsideACalendarIsNotRefused(): void
    {
        $plugin = new CalDav($this->server());

        $plugin->refuseNesting(new \DavServices\Dav\Event\BeforeCreateCollection(
            'calendars/work/attachments',
            ['{DAV:}collection'],
        ));

        $this->expectNotToPerformAssertions();
    }

    /**
     * **§4.2(b) would be a formality without this half:** a client would make
     * the calendar beside and move it in. The same holds for a `COPY`.
     */
    public function testACalendarMayNotBeMovedIntoACalendar(): void
    {
        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage('§4.2');

        $this->transfer(new BeforeMove('calendars/holiday', 'calendars/work/holiday'));
    }

    public function testACalendarMayNotBeCopiedIntoACalendar(): void
    {
        $this->expectException(Forbidden::class);

        $this->transfer(new BeforeCopy('calendars/holiday', 'calendars/work/holiday'));
    }

    /**
     * **And not by moving something that merely holds one**, which is what "at
     * any depth" means: a plain folder with a calendar inside it may not come
     * to rest in a calendar either.
     */
    public function testAFolderHoldingACalendarMayNotBeMovedIntoACalendar(): void
    {
        $this->expectException(Forbidden::class);

        $this->transfer(new BeforeMove('spare', 'calendars/work/spare'));
    }

    /**
     * **And a folder that holds no calendar may be moved in**, which is the
     * other half of "at any depth": the walk has to come back empty-handed as
     * readily as it finds something, or §4.2(a)'s own permission — "collections
     * that are not calendar collections" — would be worth nothing.
     */
    public function testAFolderHoldingNoCalendarMayBeMovedIntoOne(): void
    {
        $this->transfer(new BeforeMove('drafts', 'calendars/work/drafts'));

        $this->expectNotToPerformAssertions();
    }
    public function testSomethingThatIsNoCalendarMayBeMovedIntoOne(): void
    {
        $this->transfer(new BeforeMove('calendars/loose.ics', 'calendars/work/loose.ics'));

        $this->expectNotToPerformAssertions();
    }

    /**
     * And a calendar may be moved somewhere ordinary, or the rule would stop a
     * client tidying its own account.
     */
    public function testACalendarMayBeMovedSomewhereOrdinary(): void
    {
        $this->transfer(new BeforeMove('calendars/holiday', 'spare/holiday'));

        $this->expectNotToPerformAssertions();
    }

    /**
     * A source that is not there is a `404` the method raises; a plugin that
     * fell over looking for it would turn that into a `500`.
     */
    public function testASourceThatIsNotThereIsNotThisPluginsBusiness(): void
    {
        $this->transfer(new BeforeMove('calendars/nothing', 'calendars/work/nothing'));

        $this->expectNotToPerformAssertions();
    }

    /**
     * **The `DAV` header says nothing about CalDAV yet**, and that is the
     * decision this test records: §5.1 makes `calendar-access` a promise that
     * every MUST-level requirement of RFC 4791 is met, and §7.8's
     * `calendar-query` is one of them.
     */
    public function testItDoesNotYetAnnounceCalendarAccess(): void
    {
        $server = $this->server();
        $server->onMethod('OPTIONS', (new Options($server))(...));

        (new CalDav($server))->register();

        $response = $server->handle(new Request('OPTIONS', '/calendars'));

        self::assertStringNotContainsString('calendar-access', $response->headers()->first('DAV') ?? '');
    }

    /**
     * **`register()` wires the guards, and this is what proves it.** Each rule
     * above is driven through the listener or a real request; without the
     * wiring, every one of them would pass and the server would keep none of
     * them.
     */
    public function testRegisterWiresTheGuards(): void
    {
        $server = new Server(new Tree($this->tree()));

        $server->onMethod('MKCALENDAR', (new MkCalendar($server))(...));
        $server->onMethod('MOVE', (new Move($server))(...));

        // **The request is aimed where a store would gladly make a calendar**,
        // which is the only shape that tells the plugin apart from the node
        // half: aimed straight at a calendar it is refused either way, because
        // a calendar is deliberately no extended collection.
        self::assertSame(
            201,
            $server->handle(new Request('MKCALENDAR', '/calendars/work/attachments/nested'))->status(),
            'unwired, the store makes it',
        );

        $guarded = new Server(new Tree($this->tree()));

        (new CalDav($guarded))->register();
        $guarded->onMethod('MKCALENDAR', (new MkCalendar($guarded))(...));
        $guarded->onMethod('MOVE', (new Move($guarded))(...));

        self::assertSame(
            403,
            $guarded->handle(new Request('MKCALENDAR', '/calendars/work/attachments/nested'))->status(),
        );
        self::assertSame(
            403,
            $guarded->handle(new Request(
                'MOVE',
                '/calendars/holiday',
                headers: new Headers(['Destination' => '/calendars/work/holiday']),
            ))->status(),
        );

        // All three seams, because each is its own `on()` call and each was
        // separately deletable until this looked.
        $guarded->onMethod('COPY', (new Copy($guarded))(...));

        self::assertSame(
            403,
            $guarded->handle(new Request(
                'COPY',
                '/calendars/holiday',
                headers: new Headers(['Destination' => '/calendars/work/copy']),
            ))->status(),
        );
    }

    private function transfer(BeforeCopy|BeforeMove $event): void
    {
        (new CalDav($this->server()))->refuseABadDestination($event);
    }

    private function server(): Server
    {
        $server = new Server(new Tree($this->tree()));

        (new CalDav($server))->register();

        $server->onMethod('MKCALENDAR', (new MkCalendar($server))(...));
        $server->onMethod('COPY', (new Copy($server))(...));
        $server->onMethod('MOVE', (new Move($server))(...));

        return $server;
    }

    /**
     * A home with one calendar that holds a plain folder, a calendar beside
     * it, something that is no calendar, and a folder with a calendar in it.
     */
    private function tree(): MemoryCollection
    {
        // §4.2(a) allows "collections that are not calendar collections"
        // inside a calendar, and a store able to make calendars is one of
        // those — which makes it the only place where this plugin is the
        // *only* thing standing between a client and §4.2(b). Aimed straight
        // at a calendar, a request is refused whether the plugin is there or
        // not, because a calendar is deliberately no extended collection.
        $work = (new MemoryCollection('work'))->add(new MemoryCalendarHome('attachments'));

        $calendars = new MemoryCalendarHome('calendars');

        $calendars->add(Calendar::of($work));
        $calendars->add(Calendar::of(new MemoryCollection('holiday')));
        $calendars->add(new MemoryFile('loose.ics', 'BEGIN:VCALENDAR'));

        return (new MemoryCollection(''))
            ->add($calendars)
            ->add((new MemoryCollection('spare'))->add(Calendar::of(new MemoryCollection('buried'))))
            ->add((new MemoryCollection('drafts'))->add(new MemoryCollection('notes')));
    }
}
