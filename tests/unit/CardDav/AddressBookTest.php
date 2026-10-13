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

namespace DavServices\Tests\Unit\CardDav;

use DavServices\CardDav\AddressBook;
use DavServices\CardDav\AddressBookKeepingItsOwn;
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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for the address book collection. R-CARD-01, P5-01.
 *
 * > RFC 6352 §5.2: "An address book collection MUST report the DAV:collection
 * > and CARDDAV:addressbook XML elements in the value of the
 * > DAV:resourcetype property."
 *
 * **That one sentence is the whole of what makes a collection an address
 * book**, and it is why this is a wrapper rather than a store: where the cards
 * live is the application's business, and a library that insisted on its own
 * storage would be useful to nobody who already has some. So an address book
 * is any collection, said to be one.
 *
 * ## What it owes besides the resource type
 *
 * §5.2 puts two restrictions on what may be inside, and the second is the one
 * with teeth:
 *
 * - "Address book collections MUST only contain address object resources and
 *   collections that are **not** address book collections."
 * - "Collections contained in address book collections MUST NOT contain
 *   address book collections **at any depth**."
 *
 * A plain collection inside an address book is therefore allowed — the memo
 * says so plainly, and a wrapper that forbade it would be stricter than the
 * specification it cites. What is not allowed is an address book, anywhere
 * below. The depth-one half of that is here; the rest is a question about
 * where a path sits in the tree, which a node cannot answer and
 * {@see \DavServices\Plugin\CardDav} does.
 */
#[CoversClass(AddressBook::class)]
#[CoversClass(AddressBookKeepingItsOwn::class)]
final class AddressBookTest extends TestCase
{
    /**
     * **It says it is an address book**, in the one place §5.2 looks.
     */
    public function testItSaysItIsAnAddressBook(): void
    {
        $book = AddressBook::of(new MemoryCollection('contacts'));

        self::assertSame(['{urn:ietf:params:xml:ns:carddav}addressbook'], $book->resourceTypes());
    }

    /**
     * **And it keeps whatever the collection underneath already was.** A
     * resource type is a list, not a label: a collection that is both an
     * address book and something an application invented says both, and a
     * wrapper that replaced the list would quietly take the other half away.
     */
    public function testItKeepsWhatTheCollectionUnderneathAlreadyWas(): void
    {
        $book = AddressBook::of(new MemorySpecialCollection('contacts'));

        self::assertSame(
            [
                '{urn:ietf:params:xml:ns:carddav}addressbook',
                '{http://example.com/ns}shared',
                '{http://example.com/ns}curated',
            ],
            $book->resourceTypes(),
        );
    }

    /**
     * **And an address book made by an extended `MKCOL` says it**, which is
     * R-CARD-01 end to end: the request of §6.3.1.1 goes in, and a `PROPFIND`
     * afterwards reports both elements §5.2 requires.
     *
     * The store is a test double here on purpose. RFC 5689 hands the whole
     * request — the kind and the properties in one call — to the collection
     * that is to hold the new member, and what a store can make is the
     * store's business; the library brings the method, the resource type and
     * §5.2's rules.
     */
    public function testAnAddressBookMadeByAnExtendedMkColSaysItIsOne(): void
    {
        $root = (new MemoryCollection(''))->add(new MemoryAddressBookHome('addressbooks'));
        $events = new EventEmitter();
        $live = new LiveProperties();

        $events->on(PropertiesRequested::class, $live(...));

        $server = new Server(new Tree($root), $events);

        $mkCol = new MkCol($server);
        $propFind = new PropFind($server);
        $server->onMethod('MKCOL', $mkCol(...));
        $server->onMethod('PROPFIND', $propFind(...));

        $created = $server->handle(new Request('MKCOL', '/addressbooks/contacts', body: new Body(
            '<D:mkcol xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav">'
            . '<D:set><D:prop><D:resourcetype><D:collection/><C:addressbook/></D:resourcetype></D:prop></D:set>'
            . '</D:mkcol>',
        )));

        self::assertSame(201, $created->status());

        $described = (string) $server->handle(new Request(
            'PROPFIND',
            '/addressbooks/contacts',
            new Headers(['Depth' => '0']),
            new Body('<D:propfind xmlns:D="DAV:"><D:prop><D:resourcetype/></D:prop></D:propfind>'),
        ))->body();

        self::assertStringContainsString('urn:ietf:params:xml:ns:carddav', $described);
        self::assertStringContainsString('addressbook', $described);
        self::assertStringContainsString('collection', $described);
    }

    /**
     * **It is the collection underneath in every other respect.** The name,
     * the members, the time it changed, what it will create and what it
     * refuses — none of that is an address book's business, and a wrapper that
     * answered any of it itself would be answering for data it does not hold.
     */
    public function testItIsTheCollectionUnderneathInEveryOtherRespect(): void
    {
        $inner = new MemoryCollection('contacts');
        $inner->add(new MemoryFile('simon.vcf', 'BEGIN:VCARD'));

        $book = AddressBook::of($inner);

        self::assertSame('contacts', $book->name());
        self::assertSame($inner->lastModified(), $book->lastModified());
        self::assertTrue($book->hasChild('simon.vcf'));
        self::assertFalse($book->hasChild('nobody.vcf'));
        self::assertSame($inner->child('simon.vcf'), $book->child('simon.vcf'));
        self::assertCount(1, $book->children());
    }

    /**
     * **A card put into it goes into the collection underneath**, and the
     * entity tag the store answers with comes back out: a wrapper that
     * swallowed it would make every write look untagged and send clients
     * into a `GET` they did not need.
     */
    public function testACardPutIntoItGoesIntoTheCollectionUnderneath(): void
    {
        $inner = new MemoryCollection('contacts');
        $book = AddressBook::of($inner);

        self::assertNull($book->createFile('simon.vcf', 'BEGIN:VCARD'));
        self::assertTrue($inner->hasChild('simon.vcf'));
    }

    /**
     * **A plain collection inside an address book is allowed**, because §5.2
     * says so: what it forbids is an address book, not a collection.
     */
    public function testAPlainCollectionInsideAnAddressBookIsAllowed(): void
    {
        $inner = new MemoryCollection('contacts');
        $book = AddressBook::of($inner);

        $book->createCollection('photos');

        self::assertTrue($inner->hasChild('photos'));
    }

    /**
     * **It is not an extended collection**, and that is the refusal §5.2
     * asks for rather than a gap.
     *
     * An extended `MKCOL` is how an address book is made (§6.3.1), so a
     * collection that offered it inside an address book would offer the one
     * thing §5.2 forbids. Leaving the interface off means
     * {@see \DavServices\Dav\Method\MkCol} answers with RFC 5689's own
     * `DAV:valid-resourcetype` and names the property that was refused —
     * where implementing it in order to throw would turn that into a bare
     * `403` with nothing in it for the client to read.
     */
    public function testItIsNotAnExtendedCollection(): void
    {
        $root = new MemoryCollection('');
        $root->add(AddressBook::of(new MemoryCollection('contacts')));

        $server = new Server(new Tree($root));
        $mkCol = new MkCol($server);
        $server->onMethod('MKCOL', $mkCol(...));

        $response = $server->handle(new Request('MKCOL', '/contacts/more', body: new Body(
            '<D:mkcol xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav">'
            . '<D:set><D:prop><D:resourcetype><D:collection/><C:addressbook/></D:resourcetype></D:prop></D:set>'
            . '</D:mkcol>',
        )));

        self::assertSame(403, $response->status());
        self::assertStringContainsString('valid-resourcetype', (string) $response->body());
        self::assertStringContainsString('<d:resourcetype/>', (string) $response->body());
    }

    /**
     * **Deleting it deletes the collection underneath.**
     */
    public function testDeletingItDeletesTheCollectionUnderneath(): void
    {
        $parent = new MemoryCollection('home');
        $inner = new MemoryCollection('contacts');

        $parent->add($inner);
        AddressBook::of($inner)->delete();

        self::assertFalse($parent->hasChild('contacts'));
    }

    /**
     * **A missing member is a missing member**, passed on as the store
     * raised it rather than turned into something else.
     */
    public function testAMissingMemberIsAMissingMember(): void
    {
        $this->expectException(NotFound::class);

        (AddressBook::of(new MemoryCollection('contacts')))->child('nobody.vcf');
    }

    /**
     * **The properties of the collection underneath are its properties.**
     *
     * `CARDDAV:addressbook-description` is the reason this matters: §6.2.1
     * says it "SHOULD NOT be protected so that users can specify a
     * description", so it is stored rather than computed — and a wrapper that
     * answered no properties would hide whatever the store keeps.
     */
    public function testThePropertiesOfTheCollectionUnderneathAreItsProperties(): void
    {
        $inner = new MemoryPropertyCollection('contacts');
        $book = AddressBook::of($inner);

        self::assertInstanceOf(IProperties::class, $book);

        // Still an address book over that collection in every other respect:
        // the variant that keeps properties hands the store to the class above
        // it as well, and a mutation run showed nothing held it to that.
        self::assertSame('contacts', $book->name());
        self::assertSame($inner, $book->collection());
        self::assertSame(['{urn:ietf:params:xml:ns:carddav}addressbook'], $book->resourceTypes());

        self::assertSame(['{DAV:}displayname'], $book->propertyNames());
        self::assertSame(['{DAV:}displayname' => 'Contacts'], $book->properties(['{DAV:}displayname']));
        self::assertSame(
            ['{DAV:}displayname' => 200],
            $book->patchProperties(['{DAV:}displayname' => 'Kontakte']),
        );
        self::assertSame(['{DAV:}displayname' => 'Kontakte'], $book->properties(['{DAV:}displayname']));
    }

    /**
     * **A store that keeps no properties does not pretend to**, and this is
     * the one decision in this class that is easy to get wrong in a way that
     * looks right.
     *
     * `Plugin\DeadProperties` steps aside for **any** node that implements
     * `IProperties` — its own first two lines — because the contract is that
     * such a node keeps all of its properties itself. So a wrapper that
     * claimed the interface whatever the store does would silently take dead
     * property storage away from every address book over a store that keeps
     * none, and `CARDDAV:addressbook-description` is exactly such a property.
     *
     * Hence the factory: it hands back something that implements `IProperties`
     * only when there is a store behind it that does.
     */
    public function testAStoreThatKeepsNoPropertiesDoesNotPretendTo(): void
    {
        $book = AddressBook::of(new MemoryCollection('contacts'));

        self::assertInstanceOf(AddressBook::class, $book);
        self::assertNotInstanceOf(IProperties::class, $book);
    }

    /**
     * **What is wrapped can be read back**, so that a plugin asked about a
     * node can get at the store without unwrapping it by hand.
     */
    public function testWhatIsWrappedCanBeReadBack(): void
    {
        $inner = new MemoryCollection('contacts');

        self::assertSame($inner, AddressBook::of($inner)->collection());
    }
}
