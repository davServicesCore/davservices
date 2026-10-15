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

namespace DavServices\Tests\Unit\CalDav;

use DavServices\CalDav\Calendar;
use DavServices\CalDav\CalendarKeepingItsOwn;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\IExtendedCollection;
use DavServices\Dav\IProperties;
use DavServices\Dav\Method\MkCol;
use DavServices\Dav\Method\PropFind;
use DavServices\Dav\Property\LiveProperties;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Exception\NotFound;
use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Tests\Unit\Dav\MemoryPropertyCollection;
use DavServices\Tests\Unit\Dav\TypedCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for the calendar collection. R-CAL-01, R-CAL-06, P6-01.
 *
 * > RFC 4791 §4.2: "A calendar collection MUST report the DAV:collection and
 * > CALDAV:calendar XML elements in the value of the DAV:resourcetype
 * > property."
 *
 * **That sentence is the whole of what makes a collection a calendar**, which
 * is why this wraps a collection rather than being one: where the events are
 * kept is the application's business, and a library that insisted on storage of
 * its own would be useful only to applications that had none.
 *
 * §4.2 then puts two restrictions on the contents, and the second is the one
 * with teeth:
 *
 * - "Calendar collections MUST only contain calendar object resources and
 *   collections that are **not** calendar collections";
 * - "Collections contained in calendar collections MUST NOT contain calendar
 *   collections **at any depth**".
 *
 * So a plain collection inside a calendar is allowed — the memo says so in as
 * many words — and what is not allowed is a calendar, anywhere below one.
 */
#[CoversClass(Calendar::class)]
#[CoversClass(CalendarKeepingItsOwn::class)]
final class CalendarTest extends TestCase
{
    private const CALENDAR = '{urn:ietf:params:xml:ns:caldav}calendar';

    private const ONE_OF_OURS = '{https://dav.services/test}something-else';

    private const ANOTHER_OF_OURS = '{https://dav.services/test}and-another';

    public function testItSaysItIsACalendar(): void
    {
        self::assertSame([self::CALENDAR], Calendar::of(new MemoryCollection('work'))->resourceTypes());
    }

    /**
     * A resource type is a list rather than a label (RFC 4918 §15.9), so a
     * collection that is both a calendar and something an application invented
     * says both. Replacing the list would quietly take the other half away.
     */
    public function testItKeepsWhatTheCollectionUnderneathAlreadyWas(): void
    {
        // **Two kinds, not one.** A store with a single kind of its own makes
        // "keep the list" and "keep the first of it" look alike, which a
        // mutation run on 15.10.2026 showed for exactly this line.
        $calendar = Calendar::of(new TypedCollection('work', self::ONE_OF_OURS, self::ANOTHER_OF_OURS));

        self::assertSame(
            [self::CALENDAR, self::ONE_OF_OURS, self::ANOTHER_OF_OURS],
            $calendar->resourceTypes(),
        );
    }

    /**
     * And `DAV:collection` is not in that list because the server adds it for
     * every collection by itself — which is what the property has to come back
     * with, so this is the half that matters.
     */
    public function testThePropertyComesBackWithBothElements(): void
    {
        $root = (new MemoryCollection(''))->add(Calendar::of(new MemoryCollection('work')));
        $events = new EventEmitter();

        $events->on(PropertiesRequested::class, (new LiveProperties())(...));

        $server = new Server(new Tree($root), $events);
        $server->onMethod('PROPFIND', (new PropFind($server))(...));

        $body = '<D:propfind xmlns:D="DAV:"><D:prop><D:resourcetype/></D:prop></D:propfind>';
        $answer = (string) $server->handle(new Request('PROPFIND', '/work', headers: new Headers(['Depth' => '0']), body: new Body($body)))->body();

        self::assertStringContainsString('<d:collection/>', $answer);
        self::assertStringContainsString('calendar/>', $answer);
    }

    public function testItIsTheCollectionUnderneathInEveryOtherRespect(): void
    {
        $inner = (new MemoryCollection('work'))->add(new MemoryFile('meeting.ics', 'BEGIN:VCALENDAR'));
        $calendar = Calendar::of($inner);

        self::assertSame('work', $calendar->name());
        self::assertSame($inner->lastModified(), $calendar->lastModified());
        self::assertTrue($calendar->hasChild('meeting.ics'));
        self::assertCount(1, $calendar->children());
        self::assertSame('meeting.ics', $calendar->child('meeting.ics')->name());
    }

    public function testAnEventPutIntoItGoesIntoTheCollectionUnderneath(): void
    {
        $inner = new MemoryCollection('work');

        Calendar::of($inner)->createFile('meeting.ics', 'BEGIN:VCALENDAR');

        self::assertTrue($inner->hasChild('meeting.ics'));
    }

    /**
     * §4.2(a): "Calendar collections MUST only contain calendar object
     * resources and collections that are not calendar collections" — so a
     * plain one is allowed, and refusing it would be stricter than the
     * specification this cites.
     */
    public function testAPlainCollectionInsideACalendarIsAllowed(): void
    {
        $inner = new MemoryCollection('work');

        Calendar::of($inner)->createCollection('attachments');

        self::assertTrue($inner->hasChild('attachments'));
    }

    /**
     * **§4.2(b) is why this is not an {@see IExtendedCollection}.** An
     * extended `MKCOL` is one of the two ways a calendar comes into being, so
     * a collection that offered it inside a calendar would offer exactly what
     * the memo forbids — and leaving the interface off makes
     * {@see \DavServices\Dav\Method\MkCol} answer with RFC 5689's own
     * `DAV:valid-resourcetype` rather than a bare refusal.
     */
    public function testAnExtendedMkColAimedAtItIsRefusedByName(): void
    {
        $root = (new MemoryCollection(''))->add(Calendar::of(new MemoryCollection('work')));
        $server = new Server(new Tree($root));

        $server->onMethod('MKCOL', (new MkCol($server))(...));

        $body = '<D:mkcol xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav"><D:set><D:prop>'
            . '<D:resourcetype><D:collection/><C:calendar/></D:resourcetype>'
            . '</D:prop></D:set></D:mkcol>';
        $response = $server->handle(new Request('MKCOL', '/work/inner', body: new Body($body)));

        self::assertSame(403, $response->status());
        self::assertStringContainsString('valid-resourcetype', (string) $response->body());
    }

    public function testDeletingItDeletesTheCollectionUnderneath(): void
    {
        $inner = new MemoryCollection('work');
        $root = (new MemoryCollection(''))->add($inner);

        Calendar::of($inner)->delete();

        self::assertFalse($root->hasChild('work'));
    }

    /**
     * A member that is not there is the store's own `NotFound` rather than
     * something made up here.
     */
    public function testAMissingMemberIsAMissingMember(): void
    {
        $this->expectException(NotFound::class);

        Calendar::of(new MemoryCollection('work'))->child('nothing.ics');
    }

    /**
     * **A store that keeps properties keeps them through the wrapper.**
     * `Plugin\DeadProperties` steps aside for *any* node implementing
     * `IProperties` — its own first two lines — so a wrapper that claimed the
     * interface regardless would take dead property storage away from every
     * calendar over a store that keeps none. §5.2.1 makes
     * `CALDAV:calendar-description` one somebody has to be able to keep.
     */
    public function testThePropertiesOfTheCollectionUnderneathAreItsProperties(): void
    {
        $inner = new MemoryPropertyCollection('work');
        $calendar = Calendar::of($inner);

        self::assertInstanceOf(CalendarKeepingItsOwn::class, $calendar);
        self::assertInstanceOf(IProperties::class, $calendar);

        $calendar->patchProperties(['{urn:ietf:params:xml:ns:caldav}calendar-description' => 'Team events']);

        self::assertSame(
            ['{urn:ietf:params:xml:ns:caldav}calendar-description' => 'Team events'],
            $calendar->properties(['{urn:ietf:params:xml:ns:caldav}calendar-description']),
        );
        self::assertContains('{urn:ietf:params:xml:ns:caldav}calendar-description', $calendar->propertyNames());

        // **And it is still the collection underneath.** The variant extends
        // the plain one, so a constructor that forgot to hand the store up
        // would leave every delegating method with nothing to delegate to.
        $inner->createFile('meeting.ics', 'BEGIN:VCALENDAR');

        self::assertSame('work', $calendar->name());
        self::assertCount(1, $calendar->children());
    }

    public function testAStoreThatKeepsNoPropertiesDoesNotPretendTo(): void
    {
        self::assertNotInstanceOf(IProperties::class, Calendar::of(new MemoryCollection('work')));
    }

    /**
     * Read by a plugin that was handed a node and needs the store behind it,
     * so that it does not have to unwrap one by hand.
     */
    public function testWhatIsWrappedCanBeReadBack(): void
    {
        $inner = new MemoryCollection('work');

        self::assertSame($inner, Calendar::of($inner)->collection());
    }
}
