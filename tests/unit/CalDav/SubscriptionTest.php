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
use DavServices\CalDav\Subscription;
use DavServices\CalDav\SubscriptionKeepingItsOwn;
use DavServices\Dav\Event\PropertiesRequested;
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
 * Test list for the subscribed collection. R-CAL-04, P6-11.
 *
 * > R-CAL-04: Kalenderabonnements (`CS:subscribed` mit `CS:source`) SOLLEN als
 * > eigener Kollektionstyp unterstützt werden.
 *
 * ## There is no specification, and that was checked rather than assumed
 *
 * **No RFC defines this.** Nor does any of Apple's own extension documents:
 * `apple/ccs-calendarserver/doc/Extensions/` holds eleven — `caldav-ctag`,
 * `caldav-notifications`, `caldav-privatecomments`, `caldav-privateevents`,
 * `caldav-proxy`, `caldav-pubsubdiscovery`, `caldav-recursplit`,
 * `caldav-schedulingchanges`, `caldav-sharing`, `calendarserver-bulk-change`
 * and `icalendar-maskuids` — and **not one of them is about subscriptions**,
 * although the namespace these elements live in is theirs.
 *
 * **The one attempt at a specification lapsed.**
 * `draft-ietf-calext-serverside-subscriptions-02` (7 March 2022, intended
 * Standards Track) expired on 8 September 2022 without becoming an RFC, and it
 * names the elements differently: `DAV:subscription` in the resource type and
 * `DAV:subscription-href` for the URL. Nothing speaks it — no client sends
 * those elements and no server this library has to interoperate with answers
 * them — so following it would be conforming to a document that expired in
 * favour of a convention that did not.
 *
 * **So what decides here is what the clients send and the servers answer**, and
 * every decision below names the observation it rests on rather than a section.
 * Two observations carry all of it:
 *
 * - OS X creates a subscription with an extended `MKCOL` (10.7, 10.8) or a
 *   `MKCALENDAR` (10.9.2 onwards) whose `DAV:resourcetype` holds
 *   `DAV:collection` **and** `{http://calendarserver.org/ns/}subscribed`, with
 *   `{http://calendarserver.org/ns/}source` carrying a `DAV:href` to the feed.
 * - sabre/dav maps its own subscriptions to that same resource type, and reads
 *   `…}source` as an href.
 *
 * ## What "its own collection type" settles
 *
 * **A subscription is not a calendar collection**, and the resource type says
 * so by not holding `CALDAV:calendar`. That is the point of the requirement:
 * RFC 4791 §7.8 has `calendar-query` search "all calendar object resources"
 * of a calendar collection, and a client that saw both elements would aim the
 * three reports of §7.8 to §7.10 at a collection whose contents are somebody
 * else's and live somewhere else.
 *
 * Everything but the resource type is the collection underneath, exactly as
 * with {@see Calendar}: where a subscription's cached objects are kept — if
 * they are kept at all — is the application's business.
 */
#[CoversClass(Subscription::class)]
#[CoversClass(SubscriptionKeepingItsOwn::class)]
final class SubscriptionTest extends TestCase
{
    private const SUBSCRIBED = '{http://calendarserver.org/ns/}subscribed';

    private const SOURCE = '{http://calendarserver.org/ns/}source';

    private const ONE_OF_OURS = '{https://dav.services/test}something-else';

    private const ANOTHER_OF_OURS = '{https://dav.services/test}and-another';

    private const FEED = 'webcal://example.com/holidays.ics';

    /**
     * The one element that makes a collection a subscription, which is what
     * both observations agree on.
     */
    public function testItSaysItIsASubscription(): void
    {
        $subscription = Subscription::of(new MemoryCollection('holidays'));

        self::assertSame([self::SUBSCRIBED], $subscription->resourceTypes());
    }

    /**
     * **And it is deliberately not a calendar collection.** RFC 4791 §4.2 makes
     * `CALDAV:calendar` the element that says a collection holds calendar
     * object resources of its own, and a subscription's do not exist here —
     * they are at the far end of the `…}source` href.
     */
    public function testItIsNoCalendarCollection(): void
    {
        $subscription = Subscription::of(new MemoryCollection('holidays'));

        self::assertNotContains(Calendar::CALENDAR, $subscription->resourceTypes());
    }

    /**
     * A resource type is a list rather than a label (RFC 4918 §15.9), so a
     * collection that was already something an application invented stays it.
     * Replacing the list would quietly take the other half away.
     *
     * **Two of them, not one**: a wrapper that kept only the first would look
     * right to a case with a single element, and the half it dropped would be
     * the half nobody here wrote.
     */
    public function testItKeepsWhatTheCollectionUnderneathAlreadyWas(): void
    {
        $store = new TypedCollection('holidays', self::ONE_OF_OURS, self::ANOTHER_OF_OURS);

        self::assertSame(
            [self::SUBSCRIBED, self::ONE_OF_OURS, self::ANOTHER_OF_OURS],
            Subscription::of($store)->resourceTypes(),
        );
    }

    /**
     * And through a real `PROPFIND`, where the server adds `DAV:collection` for
     * every collection by itself: a client sees both elements, which is what it
     * matches the subscription on.
     */
    public function testThePropertyComesBackWithBothElements(): void
    {
        $server = $this->server();

        $server->onMethod('PROPFIND', (new PropFind($server))(...));

        $body = '<propfind xmlns="DAV:"><prop><resourcetype/></prop></propfind>';
        $answer = (string) $server->handle(new Request(
            'PROPFIND',
            '/home/holidays',
            headers: new Headers(['Depth' => '0']),
            body: new Body($body),
        ))->body();

        self::assertStringContainsString('<d:collection/>', $answer);
        self::assertStringContainsString('subscribed/>', $answer);
        self::assertStringNotContainsString('calendar/>', $answer);
    }

    /**
     * **The `…}source` is a property the store keeps**, not one this library
     * computes. Nothing about the feed's URL can be worked out from the
     * server's own state — a user typed it — which is the same reason
     * `CALDAV:calendar-description` is kept rather than answered (§5.2.1
     * describing it as a value "suitable for presentation to a user").
     *
     * The value is an href, as both observations have it, and it comes back as
     * it was written.
     */
    public function testTheFeedItPointsAtIsKeptLikeAnyOtherProperty(): void
    {
        $store = new MemoryPropertyCollection('holidays');

        $store->patchProperties([self::SOURCE => '<href xmlns="DAV:">' . self::FEED . '</href>']);

        $subscription = Subscription::of($store);

        self::assertInstanceOf(IProperties::class, $subscription);
        self::assertSame(
            ['<href xmlns="DAV:">' . self::FEED . '</href>'],
            array_values($subscription->properties([self::SOURCE])),
        );
    }

    /**
     * **And the feed can be changed through it**, which is what a `PROPPATCH`
     * of `…}source` comes to: a published calendar moves, and a client that had
     * to delete and re-create the subscription would lose everything else set
     * on it.
     */
    public function testTheFeedCanBeChangedThroughIt(): void
    {
        $store = new MemoryPropertyCollection('holidays');
        $subscription = Subscription::of($store);
        $moved = '<href xmlns="DAV:">https://example.com/holidays-2027.ics</href>';

        self::assertInstanceOf(IProperties::class, $subscription);
        self::assertSame([self::SOURCE => 200], $subscription->patchProperties([self::SOURCE => $moved]));
        self::assertSame([$moved], array_values($store->properties([self::SOURCE])));
    }

    /**
     * And the names it keeps are the store's, which is what a `PROPFIND` with
     * `DAV:propname` asks for — so a subscription answers that it has a feed
     * without being asked for the feed itself.
     */
    public function testThePropertyNamesAreTheStoresOwn(): void
    {
        $store = new MemoryPropertyCollection('holidays');

        $store->patchProperties([self::SOURCE => '<href xmlns="DAV:">' . self::FEED . '</href>']);

        $subscription = Subscription::of($store);

        self::assertInstanceOf(IProperties::class, $subscription);
        self::assertSame($store->propertyNames(), $subscription->propertyNames());
        self::assertContains(self::SOURCE, $subscription->propertyNames());
    }

    /**
     * It keeps properties only where there is a store behind it that does.
     * {@see \DavServices\Plugin\DeadProperties} steps aside for any node
     * implementing `IProperties` — the contract being that such a node keeps
     * all of its own — so a wrapper that claimed the interface regardless would
     * silently take dead property storage away from every subscription over a
     * store that keeps none. And for a subscription that is worse than for a
     * calendar: without somewhere to keep `…}source`, the collection no longer
     * says what it is subscribed to.
     */
    public function testAStoreThatKeepsNoPropertiesDoesNotPretendTo(): void
    {
        self::assertNotInstanceOf(IProperties::class, Subscription::of(new MemoryCollection('holidays')));
        self::assertInstanceOf(
            SubscriptionKeepingItsOwn::class,
            Subscription::of(new MemoryPropertyCollection('holidays')),
        );
    }

    /**
     * In every other respect it is the collection underneath. Answering any of
     * it here would be answering for data this does not hold.
     */
    public function testItIsTheCollectionUnderneathInEveryOtherRespect(): void
    {
        $store = new MemoryCollection('holidays');

        $store->add(new MemoryFile('new-year.ics', 'BEGIN:VCALENDAR'));

        $subscription = Subscription::of($store);

        self::assertSame('holidays', $subscription->name());
        self::assertSame($store->lastModified(), $subscription->lastModified());
        self::assertTrue($subscription->hasChild('new-year.ics'));
        self::assertCount(1, $subscription->children());
        self::assertSame('new-year.ics', $subscription->child('new-year.ics')->name());
    }

    /**
     * **A write goes through to the collection underneath rather than being
     * refused here.** It is tempting to refuse one — the objects of a
     * subscription belong to whoever publishes the feed — but a server that
     * caches a feed has to write the fetched objects somewhere, and that
     * somewhere is this collection. A wrapper that refused every write would
     * make a cached subscription impossible to build, which is the only kind
     * a client can read offline.
     *
     * Whether *a client* may write is a question about access rather than about
     * what the collection is, and `Plugin\Acl` answers it where every other
     * write is answered.
     */
    public function testWhatIsPutIntoItGoesIntoTheCollectionUnderneath(): void
    {
        $store = new MemoryCollection('holidays');
        $subscription = Subscription::of($store);

        $subscription->createFile('new-year.ics', 'BEGIN:VCALENDAR');

        self::assertTrue($store->hasChild('new-year.ics'));
    }

    /**
     * **A plain collection inside it is the store's decision.** Nothing in the
     * convention provides for one and nothing in it forbids one either, so a
     * wrapper that refused would be stricter than anything it could cite —
     * which is the same answer {@see Calendar} gives, where RFC 4791 §4.2 does
     * allow one in as many words.
     */
    public function testAPlainCollectionInsideItIsTheStoresDecision(): void
    {
        $store = new MemoryCollection('holidays');

        Subscription::of($store)->createCollection('attachments');

        self::assertTrue($store->hasChild('attachments'));
    }

    /**
     * **An extended `MKCOL` aimed at a subscription is refused by name.** A
     * subscription inside a subscription is nothing either observation
     * provides for, and the feed at the far end has no say in what a client
     * creates here.
     *
     * The refusal comes from not implementing
     * {@see \DavServices\Dav\IExtendedCollection}, which makes
     * {@see MkCol} answer with RFC 5689's own `DAV:valid-resourcetype` and name
     * the property it refused — where implementing the interface in order to
     * throw would turn that into a bare `403` with nothing in it a client can
     * read.
     */
    public function testAnExtendedMkColAimedAtItIsRefusedByName(): void
    {
        $server = $this->server();

        $server->onMethod('MKCOL', (new MkCol($server))(...));

        $response = $server->handle(new Request(
            'MKCOL',
            '/home/holidays/deeper',
            headers: new Headers(['Content-Type' => 'application/xml']),
            body: new Body(
                '<mkcol xmlns="DAV:"><set><prop><resourcetype><collection/>'
                . '<subscribed xmlns="http://calendarserver.org/ns/"/></resourcetype></prop></set></mkcol>',
            ),
        ));

        self::assertSame(403, $response->status());
        self::assertStringContainsString('valid-resourcetype', (string) $response->body());
    }

    /**
     * Removing it removes the collection underneath; being a subscription adds
     * no reason to keep it. What is at the far end of the feed is untouched —
     * this server never owned it.
     */
    public function testDeletingItDeletesTheCollectionUnderneath(): void
    {
        $home = new MemoryCollection('home');
        $store = new MemoryCollection('holidays');

        $home->add($store);

        Subscription::of($store)->delete();

        self::assertFalse($home->hasChild('holidays'));
    }

    /**
     * A member that is not there is the store's own `NotFound` rather than
     * something made up here.
     */
    public function testAMissingMemberIsAMissingMember(): void
    {
        $this->expectException(NotFound::class);

        Subscription::of(new MemoryCollection('holidays'))->child('nothing.ics');
    }

    /**
     * And the collection underneath can be read back, so that a plugin handed
     * a node need not unwrap one by hand.
     */
    public function testWhatIsWrappedCanBeReadBack(): void
    {
        $store = new MemoryCollection('holidays');

        self::assertSame($store, Subscription::of($store)->collection());
    }

    /**
     * A home with one subscription in it.
     */
    private function server(): Server
    {
        $store = new MemoryPropertyCollection('holidays');

        $store->patchProperties([self::SOURCE => '<href xmlns="DAV:">' . self::FEED . '</href>']);

        $home = (new MemoryCollection('home'))->add(Subscription::of($store));
        $events = new EventEmitter();

        $events->on(PropertiesRequested::class, (new LiveProperties())(...));

        return new Server(new Tree((new MemoryCollection(''))->add($home)), $events);
    }
}
