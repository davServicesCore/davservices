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
use DavServices\CalDav\InviteStatus;
use DavServices\CalDav\IShareable;
use DavServices\CalDav\Sharee;
use DavServices\CalDav\SharingAccess;
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
     * **A calendar that is shared says so** — R-CAL-20, P7-05.
     *
     * No RFC defines this: the source is `caldav-sharing`, "Shared and
     * Published Calendars in CalDAV", C. Daboo and E. York, Apple, 19
     * September 2012, which `docs/referenzen/download-extensions.sh` fetches.
     * §5.2.1 of it:
     *
     * > Calendar collections that are shared have addition elements listed in
     * > their DAV:resourcetype property in addition to DAV:collection and
     * > CALDAV:calendar.
     * >
     * > o CS:shared-owner (Section 6.1): used to indicate that the calendar is
     * > owned by the current user and is being shared by them.
     *
     * **And it is worked out from the sharee list rather than stored beside
     * it**, which is what §5.3.1 asks for without having to be obeyed:
     *
     * > The server MUST update the DAV:resourcetype property on the calendar
     * > collection to ensure it contains a CS:shared-owner XML element to
     * > indicate the calendar collection is now shared.
     *
     * A server keeping the element as a second fact would have two answers to
     * one question and a way for them to drift apart. Derived, they cannot.
     *
     * **The store here says nothing about its own kind**, which is both the
     * ordinary shape and the one a mutation run on 29.10.2026 found untested:
     * such a store takes the shorter way out of `resourceTypes()`, and a
     * version of it that returned the first of the list rather than the list
     * dropped the element with nothing going red.
     */
    public function testACalendarWithAShareeSaysItIsSharedByItsOwner(): void
    {
        $calendar = Calendar::of(new MemoryShareable('work', [
            new Sharee('mailto:eric@example.com', SharingAccess::ReadWrite, InviteStatus::NoResponse),
        ]));

        self::assertSame([self::CALENDAR, Calendar::SHARED_OWNER], $calendar->resourceTypes());
    }

    /**
     * **And one that is shared with nobody says nothing of the sort**, which
     * is the other half of §5.3.1 and the half a server forgets:
     *
     * > To unshare a calendar, the sharer simply removes all sharees to the
     * > CS:invite property of the calendar collection as per Section 5.3.2.
     * > The server MUST update the DAV:resourcetype property on the calendar
     * > collection to ensure it does **not** contain a CS:shared-owner XML
     * > element to indicate the calendar collection is not shared.
     *
     * A calendar that *may* be shared is not a calendar that *is*: §5.2.3 is
     * the property that says sharing is allowed, and this one says it has
     * happened.
     */
    public function testACalendarSharedWithNobodySaysNothingOfTheSort(): void
    {
        $calendar = Calendar::of(new MemoryShareable('work', [], [IShareable::CAN_BE_SHARED]));

        self::assertSame([self::CALENDAR], $calendar->resourceTypes());
    }

    /**
     * **A sharee who declined is still a sharee**, so the calendar is still
     * shared. §5.4.2 says what a refusal does, and removing is not it:
     *
     * > When such a request is received the server MUST remove the shared
     * > calendar from the sharee's calendar home and automatically **update
     * > the sharee's status** in the sharer's calendar's CS:invite property.
     *
     * The status lives inside the entry (§6.5), so only the sharer's
     * `CS:remove` (§6.28) takes an entry away. A server that dropped the
     * resource type on a decline would tell the sharer their calendar is
     * private while a list of people who were asked is still on it.
     */
    public function testAShareeWhoDeclinedIsStillASharee(): void
    {
        $calendar = Calendar::of(new MemoryShareable('work', [
            new Sharee('mailto:eric@example.com', SharingAccess::Read, InviteStatus::Declined),
        ]));

        self::assertSame([self::CALENDAR, Calendar::SHARED_OWNER], $calendar->resourceTypes());
    }

    /**
     * And the shared type takes its place beside what the store already was,
     * rather than instead of it — §15.9's list again, with two kinds of its
     * own so that "keep the list" and "keep the first of it" cannot pass for
     * each other.
     */
    public function testTheSharedTypeComesBesideWhatTheStoreAlreadyWas(): void
    {
        $calendar = Calendar::of(new TypedShareable(
            'work',
            [new Sharee('/principals/eric/', SharingAccess::Read, InviteStatus::Accepted)],
            [],
            self::ONE_OF_OURS,
            self::ANOTHER_OF_OURS,
        ));

        self::assertSame(
            [self::CALENDAR, Calendar::SHARED_OWNER, self::ONE_OF_OURS, self::ANOTHER_OF_OURS],
            $calendar->resourceTypes(),
        );
    }

    /**
     * **A calendar somebody shared to me says that instead** — R-CAL-20,
     * P7-05c. §5.2.1 lists the two side by side and they are each other's
     * opposite:
     *
     * > o CS:shared-owner (Section 6.1): used to indicate that the calendar is
     * > owned by the current user and is being shared by them.
     * >
     * > o CS:shared (Section 6.2): used to indicate that the calendar is owned
     * > by **another** user and is being shared to the current user.
     *
     * And §6.2 says where such a calendar is: it "appears in the sharee's
     * calendar home collection". Which is why this is derived from being a
     * {@see \DavServices\CalDav\ISharedWithMe} rather than asked of a store:
     * a copy that knows where the original is, is a copy, and one that forgot
     * to say so would look to a client like a calendar of the sharee's own.
     */
    public function testACalendarSomebodySharedToMeSaysSo(): void
    {
        $calendar = Calendar::of(new MemorySharedWithMe('work'));

        self::assertSame([self::CALENDAR, Calendar::SHARED], $calendar->resourceTypes());
    }

    /**
     * **And a copy is never also said to be shared by whoever holds it**,
     * however much of the sharee list it carries. §6.1 is about a calendar
     * "owned by the current user" and §6.2 about one "owned by another user",
     * so the two are each other's opposite and a client told both would have
     * been told nothing.
     *
     * A sharee's copy does carry part of the list — §5.2.2 has `CS:invite` on
     * "the calendar collection resources of the sharees" — which is exactly
     * what would make a server work the wrong one out.
     */
    public function testACopyIsNotSaidToBeSharedByWhoeverHoldsIt(): void
    {
        $calendar = Calendar::of(new MemorySharedWithMe('work', sharees: [
            new Sharee('/principals/eric/', SharingAccess::Read, InviteStatus::Accepted),
        ]));

        self::assertSame([self::CALENDAR, Calendar::SHARED], $calendar->resourceTypes());
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
