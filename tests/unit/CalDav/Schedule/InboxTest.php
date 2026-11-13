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

namespace DavServices\Tests\Unit\CalDav\Schedule;

use DavServices\Acl\Privilege;
use DavServices\CalDav\Schedule\Inbox;
use DavServices\CalDav\Schedule\InboxKeepingItsOwn;
use DavServices\CalDav\Schedule\Privileges;
use DavServices\Dav\IExtendedCollection;
use DavServices\Dav\IProperties;
use DavServices\Exception\Forbidden;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Tests\Unit\Dav\MemoryPropertyCollection;
use DavServices\Tests\Unit\Dav\TypedCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for the scheduling Inbox collection. R-CAL-30, P8-01.
 *
 * **Back to an RFC after three chunks without one.** RFC 6638, "CalDAV
 * Scheduling", June 2012, §2.2:
 *
 * > A scheduling Inbox collection contains copies of incoming scheduling
 * > messages. […]
 * >
 * > A scheduling Inbox collection MUST report the DAV:collection and
 * > CALDAV:schedule-inbox XML elements in the value of the DAV:resourcetype
 * > property.
 * >
 * > Scheduling Inbox collections MUST only contain calendar object resources
 * > that obey the restrictions specified in iTIP [RFC5546]. Consequently,
 * > scheduling Inbox collections MUST NOT contain any types of collection
 * > resources.
 *
 * ## It wraps rather than replaces, like every collection of this library
 * ## does
 *
 * Where the messages are kept is the application's business, and §2 says whose
 * business the collection itself is: "It is the server's responsibility to
 * create these collection resources, and clients have no way to create or
 * delete them." So an application makes one where it wants it and says it is
 * an Inbox; nothing here creates it, and nothing here lets a client create
 * one.
 *
 * ## One `MUST NOT` is this class's to keep
 *
 * A collection inside an Inbox is refused here rather than left to the store,
 * because §2.2 forbids it outright — "MUST NOT contain any types of collection
 * resources" — and a store that allowed one would be a store this library had
 * let break a MUST it could see coming.
 *
 * Files are a different matter: an Inbox is *for* calendar object resources,
 * so those go to the store like anywhere else.
 */
#[CoversClass(Inbox::class)]
#[CoversClass(InboxKeepingItsOwn::class)]
final class InboxTest extends TestCase
{
    private const INBOX = '{urn:ietf:params:xml:ns:caldav}schedule-inbox';

    private const ONE_OF_OURS = '{https://dav.services/test}something-else';

    private const ANOTHER_OF_OURS = '{https://dav.services/test}and-another';

    /**
     * **§2.2: the resource type carries `CALDAV:schedule-inbox`.**
     * `DAV:collection` is not named here because the server adds it for every
     * collection by itself.
     */
    public function testItSaysItIsASchedulingInbox(): void
    {
        self::assertSame([self::INBOX], Inbox::of(new MemoryCollection('inbox'))->resourceTypes());
    }

    /**
     * **RFC 6638 §6.1 defines four privileges for this kind of collection**,
     * and §6 requires them to "appear in the DAV:supported-privilege-set
     * property of scheduling Outbox and Inbox collections on which they are
     * defined". It is the collection that answers, because RFC 3744 §5.3 has
     * the property identify "the privileges defined **for the resource**",
     * and access control is a layer below CalDAV.
     */
    public function testItDefinesTheFourPrivilegesOfASchedulingInbox(): void
    {
        $defined = Inbox::of(new MemoryCollection('inbox'))->privilegesDefinedHere(Privilege::standard());

        self::assertSame(
            Privileges::anInboxDefines(Privilege::standard())->flattened(),
            $defined->flattened(),
        );
    }

    /**
     * **And not the four of an Outbox.** §6.1 closes with "The privileges
     * defined in this section are ignored if applied to a resource other than
     * a scheduling Inbox collection", and §6.2 says the same of its own — so
     * neither kind reports the other's, or §5.3's dialog box offers a right
     * this server will disregard.
     */
    public function testItDoesNotDefineWhatAnOutboxDefines(): void
    {
        $defined = Inbox::of(new MemoryCollection('inbox'))->privilegesDefinedHere(Privilege::standard());

        self::assertFalse($defined->contains(Privileges::SEND));
    }

    /**
     * And whatever the collection underneath already was: a resource type is a
     * list rather than a label (RFC 4918 §15.9).
     *
     * **Two kinds, not one**, so that "keep the list" and "keep the first of
     * it" cannot pass for each other — the gap a mutation run found in
     * `CalDav\Calendar` on 29.10.2026.
     */
    public function testItKeepsWhatTheCollectionUnderneathAlreadyWas(): void
    {
        $inbox = Inbox::of(new TypedCollection('inbox', self::ONE_OF_OURS, self::ANOTHER_OF_OURS));

        self::assertSame([self::INBOX, self::ONE_OF_OURS, self::ANOTHER_OF_OURS], $inbox->resourceTypes());
    }

    /**
     * **§2.2: no collection of any kind inside it.** Refused here rather than
     * left to the store, because the memo forbids it outright and a store that
     * allowed one would be breaking a MUST this library could see coming.
     */
    public function testNoCollectionMayBeMadeInsideIt(): void
    {
        $store = new MemoryCollection('inbox');

        $this->expectException(Forbidden::class);

        try {
            Inbox::of($store)->createCollection('somewhere');
        } finally {
            self::assertFalse($store->hasChild('somewhere'), 'and the store was never asked');
        }
    }

    /**
     * **And an extended `MKCOL` is refused by name.** Leaving
     * {@see IExtendedCollection} off is what makes
     * {@see \DavServices\Dav\Method\MkCol} answer with RFC 5689's own
     * `DAV:valid-resourcetype` instead of a bare `403` — the same reason
     * {@see \DavServices\CalDav\Calendar} leaves it off.
     */
    public function testItIsNoPlaceForAnExtendedMkCol(): void
    {
        self::assertNotInstanceOf(IExtendedCollection::class, Inbox::of(new MemoryCollection('inbox')));
    }

    /**
     * **But a scheduling message goes in**, an Inbox being *for* calendar
     * object resources: §2.2 has it "contain copies of incoming scheduling
     * messages".
     *
     * The entity tag is passed on rather than swallowed: without it every
     * write looks untagged and sends a client into a `GET` it did not need.
     */
    public function testASchedulingMessageGoesIntoTheCollectionUnderneath(): void
    {
        $store = (new MemoryCollection('inbox'))->tellsItsEtag();
        $inbox = Inbox::of($store);

        self::assertNotNull($inbox->createFile('one.ics', 'BEGIN:VCALENDAR'));
        self::assertTrue($store->hasChild('one.ics'));
    }

    /**
     * Everything else is the collection underneath.
     */
    public function testItIsTheCollectionUnderneathInEveryOtherRespect(): void
    {
        $store = (new MemoryCollection('inbox'))->add(new MemoryFile('one.ics', 'BEGIN:VCALENDAR'));
        $inbox = Inbox::of($store);

        self::assertSame('inbox', $inbox->name());
        self::assertTrue($inbox->hasChild('one.ics'));
        self::assertFalse($inbox->hasChild('two.ics'));
        self::assertCount(1, $inbox->children());
        self::assertSame('one.ics', $inbox->child('one.ics')->name());
        self::assertNull($inbox->lastModified());
        self::assertSame($store, $inbox->collection());
    }

    /**
     * And it goes when the store lets it. §2 has the collections be the
     * server's to create and clients with "no way to create or delete them" —
     * which is the client's way, and not the application's: a deployment that
     * takes a user away takes their Inbox with them.
     */
    public function testTheStoreDecidesWhetherItGoes(): void
    {
        $store = new MemoryCollection('inbox');
        $home = (new MemoryCollection('bernard'))->add($store);

        Inbox::of($store)->delete();

        self::assertFalse($home->hasChild('inbox'));
    }

    /**
     * **It keeps properties only where there is a store behind it that does.**
     * §2.2 lists eight properties of RFC 4791 that "MAY also be defined on
     * scheduling Inbox collections", and {@see \DavServices\Plugin\DeadProperties}
     * steps aside for any node implementing `IProperties` — so claiming the
     * interface regardless would take dead property storage away from every
     * Inbox over a store that keeps none.
     */
    public function testItKeepsPropertiesOnlyWhereTheStoreDoes(): void
    {
        self::assertNotInstanceOf(IProperties::class, Inbox::of(new MemoryCollection('inbox')));
        self::assertInstanceOf(IProperties::class, Inbox::of(new MemoryPropertyCollection('inbox')));
    }

    /**
     * What a store keeps, an Inbox over it answers — and a change reaches it,
     * which is the half that makes the claim worth making.
     */
    public function testWhatTheStoreKeepsItAnswersAndWhatItIsToldItPassesOn(): void
    {
        $inbox = Inbox::of(new MemoryPropertyCollection('inbox'));

        self::assertInstanceOf(IProperties::class, $inbox);

        // Asked of the collection half as well, because the variant that keeps
        // properties hands the store to its parent for everything else.
        self::assertSame('inbox', $inbox->name());
        self::assertSame([self::INBOX], $inbox->resourceTypes());
        self::assertSame(['{DAV:}displayname'], $inbox->propertyNames());
        self::assertSame(
            ['{DAV:}displayname' => 'Contacts'],
            $inbox->properties(['{DAV:}displayname']),
        );
        self::assertSame(
            ['{DAV:}displayname' => 200],
            $inbox->patchProperties(['{DAV:}displayname' => 'Inbox']),
        );
        self::assertSame(['{DAV:}displayname' => 'Inbox'], $inbox->properties(['{DAV:}displayname']));
    }
}
