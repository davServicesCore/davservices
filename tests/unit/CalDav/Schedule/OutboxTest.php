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
use DavServices\CalDav\Schedule\Outbox;
use DavServices\CalDav\Schedule\OutboxKeepingItsOwn;
use DavServices\CalDav\Schedule\Privileges;
use DavServices\Dav\IExtendedCollection;
use DavServices\Dav\IProperties;
use DavServices\Exception\Forbidden;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryPropertyCollection;
use DavServices\Tests\Unit\Dav\TypedCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for the scheduling Outbox collection. R-CAL-31, P8-01.
 *
 * RFC 6638 §2.1:
 *
 * > A scheduling Outbox collection is used as the target for busy time
 * > information requests, and to manage privileges that apply to outgoing
 * > scheduling requests.
 * >
 * > A scheduling Outbox collection MUST report the DAV:collection and
 * > CALDAV:schedule-outbox XML elements in the value of the DAV:resourcetype
 * > property.
 * >
 * > The use of child resources in a scheduling Outbox collection is reserved
 * > for future revisions or extensions of this specification.
 *
 * ## That last sentence is why nothing may be put in one
 *
 * It is not a `MUST NOT`, and it says something stronger than a prohibition
 * would: what a child resource of an Outbox **means** is not defined, and is
 * reserved for somebody else to define. A server that let a client make one
 * would be letting it create a resource with no agreed meaning, and would then
 * have to keep whatever the next revision decides those mean. So both a file
 * and a collection are refused here, where an Inbox refuses only collections
 * (§2.2 names the one and allows the other).
 */
#[CoversClass(Outbox::class)]
#[CoversClass(OutboxKeepingItsOwn::class)]
final class OutboxTest extends TestCase
{
    private const OUTBOX = '{urn:ietf:params:xml:ns:caldav}schedule-outbox';

    private const ONE_OF_OURS = '{https://dav.services/test}something-else';

    private const ANOTHER_OF_OURS = '{https://dav.services/test}and-another';

    /**
     * **§2.1: the resource type carries `CALDAV:schedule-outbox`.**
     */
    public function testItSaysItIsASchedulingOutbox(): void
    {
        self::assertSame([self::OUTBOX], Outbox::of(new MemoryCollection('outbox'))->resourceTypes());
    }

    /**
     * **RFC 6638 §6.2 defines four privileges for this kind of collection**,
     * and §6 requires them to appear in `DAV:supported-privilege-set` "on
     * which they are defined" — which RFC 3744 §5.3 makes the resource's own
     * answer rather than the server's one tree.
     */
    public function testItDefinesTheFourPrivilegesOfASchedulingOutbox(): void
    {
        $defined = Outbox::of(new MemoryCollection('outbox'))->privilegesDefinedHere(Privilege::standard());

        self::assertSame(
            Privileges::anOutboxDefines(Privilege::standard())->flattened(),
            $defined->flattened(),
        );
    }

    /**
     * **And not the four of an Inbox**, for the sentence §6.2 closes with:
     * "The privileges defined in this section are ignored if applied to a
     * resource other than a scheduling Outbox collection."
     */
    public function testItDoesNotDefineWhatAnInboxDefines(): void
    {
        $defined = Outbox::of(new MemoryCollection('outbox'))->privilegesDefinedHere(Privilege::standard());

        self::assertFalse($defined->contains(Privileges::DELIVER));
    }

    /**
     * And whatever the collection underneath already was, with two kinds so
     * that keeping the list and keeping its first entry cannot pass for each
     * other.
     */
    public function testItKeepsWhatTheCollectionUnderneathAlreadyWas(): void
    {
        $outbox = Outbox::of(new TypedCollection('outbox', self::ONE_OF_OURS, self::ANOTHER_OF_OURS));

        self::assertSame([self::OUTBOX, self::ONE_OF_OURS, self::ANOTHER_OF_OURS], $outbox->resourceTypes());
    }

    /**
     * **Nothing may be put inside it**, §2.1 reserving what a child resource
     * of an Outbox means. A busy-time request arrives as a `POST` on the
     * collection itself (§5) and leaves nothing behind.
     */
    #[DataProvider('waysToPutSomethingIn')]
    public function testNothingMayBePutInsideIt(string $how): void
    {
        $store = new MemoryCollection('outbox');
        $outbox = Outbox::of($store);

        $this->expectException(Forbidden::class);

        try {
            $how === 'file' ? $outbox->createFile('one.ics', 'BEGIN:VCALENDAR') : $outbox->createCollection('one');
        } finally {
            self::assertSame([], $store->children(), 'and the store was never asked');
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function waysToPutSomethingIn(): iterable
    {
        yield 'a calendar object resource' => ['file'];
        yield 'a collection' => ['collection'];
    }

    /**
     * And an extended `MKCOL` is refused by name rather than with a bare
     * `403`, which is what leaving {@see IExtendedCollection} off achieves.
     */
    public function testItIsNoPlaceForAnExtendedMkCol(): void
    {
        self::assertNotInstanceOf(IExtendedCollection::class, Outbox::of(new MemoryCollection('outbox')));
    }

    /**
     * Everything else is the collection underneath.
     */
    public function testItIsTheCollectionUnderneathInEveryOtherRespect(): void
    {
        $store = new MemoryCollection('outbox');
        $outbox = Outbox::of($store);

        self::assertSame('outbox', $outbox->name());
        self::assertFalse($outbox->hasChild('one.ics'));
        self::assertSame([], $outbox->children());
        self::assertNull($outbox->lastModified());
        self::assertSame($store, $outbox->collection());
    }

    /**
     * A member that is not there is the store's own `NotFound`.
     */
    public function testAMissingMemberIsAMissingMember(): void
    {
        $this->expectException(\DavServices\Exception\NotFound::class);

        Outbox::of(new MemoryCollection('outbox'))->child('one.ics');
    }

    /**
     * And it goes when the store lets it.
     */
    public function testTheStoreDecidesWhetherItGoes(): void
    {
        $store = new MemoryCollection('outbox');
        $home = (new MemoryCollection('bernard'))->add($store);

        Outbox::of($store)->delete();

        self::assertFalse($home->hasChild('outbox'));
    }

    /**
     * **It keeps properties only where there is a store behind it that does**,
     * §2.1 listing six properties of RFC 4791 that "MAY also be defined on
     * scheduling Outbox collections".
     */
    public function testItKeepsPropertiesOnlyWhereTheStoreDoes(): void
    {
        self::assertNotInstanceOf(IProperties::class, Outbox::of(new MemoryCollection('outbox')));
        self::assertInstanceOf(IProperties::class, Outbox::of(new MemoryPropertyCollection('outbox')));
    }

    /**
     * What a store keeps, an Outbox over it answers — and a change reaches it.
     */
    public function testWhatTheStoreKeepsItAnswersAndWhatItIsToldItPassesOn(): void
    {
        $outbox = Outbox::of(new MemoryPropertyCollection('outbox'));

        self::assertInstanceOf(IProperties::class, $outbox);

        self::assertSame('outbox', $outbox->name());
        self::assertSame([self::OUTBOX], $outbox->resourceTypes());
        self::assertSame(['{DAV:}displayname'], $outbox->propertyNames());
        self::assertSame(
            ['{DAV:}displayname' => 'Contacts'],
            $outbox->properties(['{DAV:}displayname']),
        );
        self::assertSame(
            ['{DAV:}displayname' => 200],
            $outbox->patchProperties(['{DAV:}displayname' => 'Outbox']),
        );
        self::assertSame(['{DAV:}displayname' => 'Outbox'], $outbox->properties(['{DAV:}displayname']));
    }
}
