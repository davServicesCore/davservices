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

use DavServices\CalDav\NotificationCollection;
use DavServices\CalDav\NotificationCollectionKeepingItsOwn;
use DavServices\Dav\IProperties;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Tests\Unit\Dav\MemoryPropertyCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for the notification collection. R-CAL-21, P7-06.
 *
 * **There is no RFC for this either**, and the source is the same document
 * P7-04's tag came from one section earlier: `caldav-sharing`, "Shared and
 * Published Calendars in CalDAV", C. Daboo and E. York, Apple, 19 September
 * 2012, fetched by `docs/referenzen/download-extensions.sh`.
 *
 * §4 of it defines the mechanism, and one sentence defines this class:
 *
 * > The notification collection referenced by the CS:notification-URL property
 * > MUST have a DAV:resourcetype property with DAV:collection and
 * > CS:notification child elements.
 *
 * **And it is a generic mechanism, not a sharing one.** §4's first sentence —
 * "this specification defines a new **generic** notification mechanism for
 * CalDAV servers" — is why this comes before the sharing it was written for:
 * the collection is a thing in its own right, with its own resource type and
 * its own lifecycle, and §5.3.2's "the server MUST send a notification
 * message to any sharees whose status is changed" rests on it.
 *
 * ## It wraps rather than replaces
 *
 * The same shape as {@see \DavServices\CalDav\Subscription} and for the same
 * reason: where the notifications are kept is the application's business, and
 * a wrapper that claimed `IProperties` over a store that keeps none would take
 * dead property storage away from the collection silently.
 */
#[CoversClass(NotificationCollection::class)]
#[CoversClass(NotificationCollectionKeepingItsOwn::class)]
final class NotificationCollectionTest extends TestCase
{
    /**
     * **§4: the resource type carries `CS:notification`.** `DAV:collection`
     * is not named here because the server adds it for every collection by
     * itself — {@see \DavServices\Dav\IResourceType} says what is named is
     * "added to what the server could tell by itself".
     */
    public function testItSaysItIsANotificationCollection(): void
    {
        self::assertSame(
            [NotificationCollection::NOTIFICATION],
            NotificationCollection::of(new MemoryCollection('notifications'))->resourceTypes(),
        );
    }

    /**
     * And whatever the collection underneath already was: a resource type is a
     * list rather than a label (RFC 4918 §15.9), so replacing it would quietly
     * take the other half away.
     */
    public function testItCarriesWhatTheCollectionUnderneathAlreadyWas(): void
    {
        $inner = NotificationCollection::of(new MemoryCollection('inner'));

        self::assertSame(
            [NotificationCollection::NOTIFICATION, NotificationCollection::NOTIFICATION],
            NotificationCollection::of($inner)->resourceTypes(),
        );
    }

    /**
     * **It keeps properties only where there is a store behind it that does.**
     * {@see \DavServices\Plugin\DeadProperties} steps aside for any node
     * implementing `IProperties`, the contract being that such a node keeps
     * all of its own — so claiming the interface regardless would take dead
     * property storage away from every notification collection over a store
     * that keeps none.
     */
    public function testItKeepsPropertiesOnlyWhereTheStoreDoes(): void
    {
        self::assertNotInstanceOf(
            IProperties::class,
            NotificationCollection::of(new MemoryCollection('notifications')),
        );
        self::assertInstanceOf(
            IProperties::class,
            NotificationCollection::of(new MemoryPropertyCollection('notifications')),
        );
    }

    /**
     * What a store keeps, a notification collection over it answers: the
     * wrapper passes the names and the values straight through.
     */
    public function testWhatTheStoreKeepsItAnswers(): void
    {
        $collection = NotificationCollection::of(new MemoryPropertyCollection('notifications'));

        self::assertInstanceOf(IProperties::class, $collection);
        self::assertSame(['{DAV:}displayname'], $collection->propertyNames());
        self::assertSame(
            ['{DAV:}displayname' => 'Contacts'],
            $collection->properties(['{DAV:}displayname']),
        );
    }

    /**
     * **And a write goes to the store too**, which is the half that makes the
     * claim worth making: a wrapper that answered properties but swallowed
     * changes would leave a client's `PROPPATCH` reporting success over a
     * store that never heard of it.
     *
     * A notification collection is somewhere a client sets a
     * `DAV:displayname` — §4 has it as a collection a user's client looks in
     * every few minutes — so this is the ordinary case rather than an exotic
     * one.
     */
    public function testAWriteGoesToTheStoreAsWell(): void
    {
        $collection = NotificationCollection::of(new MemoryPropertyCollection('notifications'));

        self::assertInstanceOf(IProperties::class, $collection);
        self::assertSame(
            ['{DAV:}displayname' => 200],
            $collection->patchProperties(['{DAV:}displayname' => 'Alerts']),
        );
        self::assertSame(
            ['{DAV:}displayname' => 'Alerts'],
            $collection->properties(['{DAV:}displayname']),
        );
    }

    /**
     * The members are the store's, and so is everything else a collection is
     * asked: being a notification collection adds nothing to any of it.
     */
    public function testTheMembersAreTheStores(): void
    {
        $store = (new MemoryCollection('notifications'))->add(new MemoryFile('one.xml', '<notification/>'));
        $collection = NotificationCollection::of($store);

        self::assertSame('notifications', $collection->name());
        self::assertTrue($collection->hasChild('one.xml'));
        self::assertFalse($collection->hasChild('two.xml'));
        self::assertCount(1, $collection->children());
        self::assertSame('one.xml', $collection->child('one.xml')->name());
        self::assertSame($store, $collection->collection());
    }

    /**
     * **A notification is deleted by the client that has handled it**, §4
     * saying so: "Once a client has handled the notification in whatever way
     * is appropriate it SHOULD delete the notification resource." So a write
     * and a removal go through rather than being refused here.
     */
    public function testAMemberCanBeMadeAndTakenAway(): void
    {
        $collection = NotificationCollection::of(new MemoryCollection('notifications'));

        $collection->createFile('one.xml', '<notification/>');

        self::assertTrue($collection->hasChild('one.xml'));

        $collection->child('one.xml')->delete();

        self::assertFalse($collection->hasChild('one.xml'));
    }

    /**
     * And the collection itself goes when the store lets it: nothing in §4
     * provides for keeping it, and nothing forbids a plain collection inside
     * one either — both are the store's decision, as they are for a
     * subscription.
     */
    public function testTheCollectionAndACollectionInsideItAreTheStoresToAllow(): void
    {
        $store = new MemoryCollection('notifications');
        $collection = NotificationCollection::of($store);

        $collection->createCollection('inside');

        self::assertTrue($collection->hasChild('inside'));
        self::assertNull($collection->lastModified());

        $collection->delete();
    }
}
