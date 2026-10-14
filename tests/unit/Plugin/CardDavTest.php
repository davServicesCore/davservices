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

use DavServices\Acl\Principal;
use DavServices\CardDav\AddressBook;
use DavServices\Dav\Acl\PrincipalInfo;
use DavServices\Dav\Collation\Collations;
use DavServices\Dav\Collation\UnknownCollation;
use DavServices\Dav\Event\BeforeCopy;
use DavServices\Dav\Event\BeforeCreateCollection;
use DavServices\Dav\Event\BeforeMove;
use DavServices\Dav\Event\OptionsRequested;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\INode;
use DavServices\Dav\Method\Copy;
use DavServices\Dav\Method\MkCol;
use DavServices\Dav\Method\Move;
use DavServices\Dav\Method\Options;
use DavServices\Dav\Method\PropFind;
use DavServices\Dav\Method\PropPatch;
use DavServices\Dav\PropFindForm;
use DavServices\Dav\PropFindResult;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Exception\Forbidden;
use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Plugin\CardDav;
use DavServices\Plugin\DeadProperties;
use DavServices\Tests\Unit\Backend\MemoryPropertyStorage;
use DavServices\Tests\Unit\CardDav\MemoryAddressBookHome;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Xml\Element;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for the CardDAV plugin. R-CARD-01, R-CARD-02, P5-01.
 *
 * Switched on in one line, like every other plugin here:
 *
 *     (new CardDav($server, 'addressbooks'))->register();
 *
 * **What it answers are the questions a node cannot.** Two of them are about
 * limits the server sets rather than data it holds, and two are about *where*
 * something is — and a node knows its name and nothing about where it hangs,
 * which is the same reason {@see \DavServices\Plugin\Principals} exists.
 *
 * ## What it does not answer, and why that is deliberate
 *
 * **`CARDDAV:addressbook-description` is not here.** §6.2.1: "SHOULD NOT be
 * protected so that users can specify a description." A plugin that answered
 * it would make it unwritable, which is the opposite of what the memo asks.
 * It is stored — by the backend through `IProperties`, or by
 * `Plugin\DeadProperties`, which also preserves it across `COPY` and `MOVE`
 * as §6.2.1 asks.
 *
 * **`CARDDAV:supported-collation-set` is answered since P5-05**, and the
 * reason it was not before is worth keeping: §8.3 makes the property a promise
 * with teeth — "If the client chooses a collation not supported by the server,
 * the server MUST respond with a CARDDAV:supported-collation precondition error
 * response" — so advertising a collation the library could not apply would owe
 * a client a match it could not give. Now that `Dav\Collation` has all three,
 * the list is true, and it is read from there rather than written out twice.
 */
#[CoversClass(CardDav::class)]
#[CoversClass(Collations::class)]
final class CardDavTest extends TestCase
{
    private const NS = 'urn:ietf:params:xml:ns:carddav';

    /**
     * **It says the server speaks CardDAV.** §6.1: a server "MUST include
     * 'addressbook' as a field in the DAV response header from an OPTIONS
     * request on any resource that supports any address book properties,
     * reports, or methods."
     */
    public function testItSaysTheServerSpeaksCardDav(): void
    {
        $server = $this->server();
        (new CardDav($server))->register();

        $options = new Options($server);
        $server->onMethod('OPTIONS', $options(...));

        $classes = explode(', ', $server->handle(new Request('OPTIONS', '/'))->headers()->first('DAV') ?? '');

        self::assertContains('addressbook', $classes);

        // Asked directly as well as through the server: a listener registered
        // as a first-class callable is a compiled variant of its own as far as
        // the coverage driver is concerned, and a claim exercised only one way
        // leaves the other half unmeasured.
        $event = new OptionsRequested(new Request('OPTIONS', '/'));

        (new CardDav($this->server()))->announce($event);

        self::assertSame(['addressbook'], $event->compliance());
    }

    /**
     * **An address book says what it will take.** §6.2.2: "In the absence of
     * this property, the server MUST only accept data with the media type
     * 'text/vcard' and vCard version 3.0" — so a server that reads 4.0 as well
     * has to say so, or a client will never send it.
     */
    public function testAnAddressBookSaysWhatItWillTake(): void
    {
        $property = $this->answerFor('{' . self::NS . '}supported-address-data');

        self::assertInstanceOf(Element::class, $property);

        $kinds = [];

        foreach ($property->children() as $child) {
            self::assertSame('{' . self::NS . '}address-data-type', $child->name());

            $kinds[] = $child->attribute('content-type') . ' ' . $child->attribute('version');
        }

        self::assertSame(['text/vcard 3.0', 'text/vcard 4.0'], $kinds);
    }

    /**
     * **And what it will take can be said by the application**, because what
     * a store accepts is the store's business. A server that will only keep
     * 3.0 says only that, and §6.2.2's precondition then means something.
     */
    public function testWhatItWillTakeCanBeSaidByTheApplication(): void
    {
        $plugin = new CardDav($this->server(), supportedAddressData: [['text/vcard', '3.0']]);
        $property = $this->answerFor('{' . self::NS . '}supported-address-data', $plugin);

        self::assertInstanceOf(Element::class, $property);
        self::assertCount(1, $property->children());
    }

    /**
     * **An address book says how large a card may be**, and the figure is the
     * one the requirements fix: 8 MB (8 388 608 octets).
     */
    public function testAnAddressBookSaysHowLargeACardMayBe(): void
    {
        self::assertSame('8388608', $this->answerFor('{' . self::NS . '}max-resource-size'));
    }

    /**
     * **And the limit is configurable**, which R-CAL-08 asks of every one of
     * them: "Die Werte MÜSSEN konfigurierbar sein, die genannten sind
     * Vorgaben."
     */
    public function testTheLimitIsConfigurable(): void
    {
        $plugin = new CardDav($this->server(), maxResourceSize: 1_048_576);

        self::assertSame('1048576', $this->answerFor('{' . self::NS . '}max-resource-size', $plugin));
    }

    /**
     * **Neither property is answered on anything that is not an address
     * book.** §6.2.2 and §6.2.3 define both on an address book collection, and
     * a plain folder that claimed a maximum card size would be telling a
     * client it stores cards.
     */
    public function testNeitherPropertyIsAnsweredOnAPlainCollection(): void
    {
        self::assertSame([], $this->ask(new MemoryCollection('files'), [
            '{' . self::NS . '}supported-address-data',
            '{' . self::NS . '}max-resource-size',
        ]));
    }

    /**
     * **The description is left to whoever stores it.** §6.2.1 says it
     * "SHOULD NOT be protected so that users can specify a description", and
     * a plugin answering it would make it unwritable.
     */
    public function testTheDescriptionIsLeftToWhoeverStoresIt(): void
    {
        self::assertNull($this->answerFor('{' . self::NS . '}addressbook-description'));
    }

    /**
     * **And a description a client sets comes back**, which is what §6.2.1
     * asks for and what the plugin keeping quiet makes possible: a `PROPPATCH`
     * stores it, a `PROPFIND` returns it, and §6.2.1's "SHOULD NOT be
     * protected so that users can specify a description" is met by the server
     * as a whole rather than by this class alone.
     *
     * It is `Plugin\DeadProperties` doing the keeping here, which also carries
     * the value across `COPY` and `MOVE` as §6.2.1 asks. A backend with
     * somewhere better to put it answers through `IProperties` instead; either
     * way the plugin that owns the protected properties must not claim this
     * one, or there would be nothing to store.
     */
    public function testADescriptionAClientSetsComesBack(): void
    {
        $description = '{' . self::NS . '}addressbook-description';
        $events = new EventEmitter();
        $server = new Server(new Tree($this->tree()), $events);

        (new CardDav($server))->register();
        (new DeadProperties(new MemoryPropertyStorage()))->registerOn($events);

        $propPatch = new PropPatch($server);
        $propFind = new PropFind($server);
        $server->onMethod('PROPPATCH', $propPatch(...));
        $server->onMethod('PROPFIND', $propFind(...));

        $patched = $server->handle(new Request(
            'PROPPATCH',
            '/addressbooks/simon/contacts',
            null,
            new Body(
                '<D:propertyupdate xmlns:D="DAV:" xmlns:C="' . self::NS . '"><D:set><D:prop>'
                . '<C:addressbook-description xml:lang="en">My primary address book.</C:addressbook-description>'
                . '</D:prop></D:set></D:propertyupdate>',
            ),
        ));

        self::assertSame(207, $patched->status());
        self::assertStringContainsString('200 OK', (string) $patched->body());

        $described = (string) $server->handle(new Request(
            'PROPFIND',
            '/addressbooks/simon/contacts',
            new Headers(['Depth' => '0']),
            new Body(
                '<D:propfind xmlns:D="DAV:" xmlns:C="' . self::NS . '">'
                . '<D:prop><C:addressbook-description/></D:prop></D:propfind>',
            ),
        ))->body();

        self::assertStringContainsString('My primary address book.', $described);
        self::assertStringNotContainsString('404', $described);

        // And a principal asked through the same server, because the home set
        // reaching a real multistatus is the claim §7.1.1 actually makes — and
        // because a listener reached through the emitter is a compiled variant
        // of its own, so a branch exercised only by a direct call leaves the
        // other half unmeasured.
        $home = (string) $server->handle(new Request(
            'PROPFIND',
            '/principals/simon',
            new Headers(['Depth' => '0']),
            new Body(
                '<D:propfind xmlns:D="DAV:" xmlns:C="' . self::NS . '">'
                . '<D:prop><C:addressbook-home-set/></D:prop></D:propfind>',
            ),
        ))->body();

        self::assertStringContainsString('/addressbooks/simon/', $home);
    }

    /**
     * **And so is the collation set**, until a report needs one. §8.3 owes it
     * "on any resource that supports reports that use collations", and this
     * chunk brings no such report.
     */
    public function testTheCollationSetIsNowAnswered(): void
    {
        $property = $this->answerFor('{' . self::NS . '}supported-collation-set');

        self::assertInstanceOf(Element::class, $property);

        $names = [];

        foreach ($property->children() as $child) {
            self::assertSame('{' . self::NS . '}supported-collation', $child->name());

            $names[] = $child->text();
        }

        // R-CARD-09 names all three, and §8.3.1's DTD comment says two of them
        // outright: "Both 'i;ascii-casemap' and 'i;unicode-casemap' will be
        // present."
        self::assertSame(['i;ascii-casemap', 'i;octet', 'i;unicode-casemap'], $names);
    }

    /**
     * **And it is the same list the server will actually accept.**
     *
     * §8.3 makes the property a promise with teeth: "If the client chooses a
     * collation not supported by the server, the server MUST respond with a
     * CARDDAV:supported-collation precondition error response." A property that
     * advertised a fourth name would owe a client a match it cannot give, so
     * the list comes from `Dav\Collation\Collations` rather than from a
     * literal written twice.
     */
    public function testTheCollationSetIsTheListTheServerCanActuallyUse(): void
    {
        $property = $this->answerFor('{' . self::NS . '}supported-collation-set');

        self::assertInstanceOf(Element::class, $property);

        // Every advertised name must resolve to a working collation, which is
        // the other side of §8.3's precondition: a client choosing one it was
        // offered is owed a match rather than an error.
        foreach ($property->children() as $child) {
            self::assertSame($child->text(), Collations::named($child->text())->identifier());
        }
    }

    /**
     * **And a name it did not advertise is refused**, which is the other half
     * of the same promise. §8.3: "If the client chooses a collation not
     * supported by the server, the server MUST respond with a
     * CARDDAV:supported-collation precondition error response."
     *
     * The refusal is raised by `Dav\Collation` and carries the name, so that
     * the report which catches it can write that precondition — the element is
     * CardDAV's and the registry is a layer below. Asserting both halves here,
     * beside the property, is what keeps the advertised list and the accepted
     * list one list.
     */
    public function testANameItDidNotAdvertiseIsRefused(): void
    {
        $property = $this->answerFor('{' . self::NS . '}supported-collation-set');

        self::assertInstanceOf(Element::class, $property);

        $advertised = array_map(
            static fn (Element $child): string => $child->text(),
            $property->children(),
        );

        self::assertNotContains('i;basic', $advertised);

        $refusal = null;

        try {
            Collations::named('i;basic');
        } catch (UnknownCollation $thrown) {
            $refusal = $thrown;
        }

        self::assertNotNull($refusal);
        self::assertSame('i;basic', $refusal->identifier());
    }

    /**
     * **§8.3.1 wants at least two**, and says so in its definition: "The
     * CARDDAV:supported-collation-set property contains two or more
     * CARDDAV:supported-collation elements."
     */
    public function testTheCollationSetHasAtLeastTwoEntries(): void
    {
        $property = $this->answerFor('{' . self::NS . '}supported-collation-set');

        self::assertInstanceOf(Element::class, $property);
        self::assertGreaterThanOrEqual(2, count($property->children()));
    }

    /**
     * **A principal says where its address books are.** §7.1.1, and the
     * element holds `(DAV:href*)` — hrefs, not a path, because a client puts
     * one straight into a request.
     */
    public function testAPrincipalSaysWhereItsAddressBooksAre(): void
    {
        $property = $this->answerFor('{' . self::NS . '}addressbook-home-set', node: $this->principal('simon'));

        self::assertInstanceOf(Element::class, $property);
        self::assertSame(['/addressbooks/simon/'], $this->hrefsIn($property));
    }

    /**
     * **Where they are is the application's to say**, because nothing in this
     * library decides where an address book home is mounted.
     */
    public function testWhereTheyAreIsTheApplicationsToSay(): void
    {
        $plugin = new CardDav($this->server(), 'contacts/books');
        $property = $this->answerFor(
            '{' . self::NS . '}addressbook-home-set',
            $plugin,
            $this->principal('simon'),
        );

        self::assertInstanceOf(Element::class, $property);
        self::assertSame(['/contacts/books/simon/'], $this->hrefsIn($property));
    }

    /**
     * **A principal's own card is answered only where somebody knows it.**
     * §7.1.2 gives `principal-address` exactly one `DAV:href`, so there is no
     * truthful empty answer: a server that invented one would send clients to
     * a card that is not there, and `404` says it does not know the question.
     */
    public function testAPrincipalsOwnCardIsAnsweredOnlyWhereSomebodyKnowsIt(): void
    {
        self::assertNull($this->answerFor(
            '{' . self::NS . '}principal-address',
            node: $this->principal('simon'),
        ));

        $plugin = new CardDav(
            $this->server(),
            principalAddress: static fn (string $principal): string => 'cards/' . $principal . '.vcf',
        );
        $property = $this->answerFor('{' . self::NS . '}principal-address', $plugin, $this->principal('simon'));

        self::assertInstanceOf(Element::class, $property);
        self::assertSame(['/cards/simon.vcf'], $this->hrefsIn($property));
    }

    /**
     * **Neither principal property is answered on anything but a principal.**
     * §7.1 calls them "Additional Principal Properties", and an address book
     * that reported a home set would be claiming to be a person.
     */
    public function testNeitherPrincipalPropertyIsAnsweredOnAnAddressBook(): void
    {
        self::assertSame([], $this->ask(AddressBook::of(new MemoryCollection('contacts')), [
            '{' . self::NS . '}addressbook-home-set',
            '{' . self::NS . '}principal-address',
        ]));
    }

    /**
     * **`register()` wires every guard, and this is what proves it.**
     *
     * Each rule below has a test of its own that calls the listener directly,
     * and a mutation run showed what that leaves open: removing any one of the
     * three `on()` calls from `register()` killed nothing. A guard nobody
     * registers is a rule the server does not keep, so the whole of it is
     * driven here through real requests — the way an application gets it.
     */
    public function testRegisterWiresEveryGuard(): void
    {
        $server = new Server(new Tree($this->tree()));

        (new CardDav($server))->register();

        $mkCol = new MkCol($server);
        $move = new Move($server);
        $copy = new Copy($server);
        $server->onMethod('MKCOL', $mkCol(...));
        $server->onMethod('MOVE', $move(...));
        $server->onMethod('COPY', $copy(...));

        // **The folder asked is one that would gladly make an address book.**
        // A mutation run showed why that matters: aimed straight at an address
        // book, the request is refused whether the guard is registered or not,
        // because an address book is deliberately not an extended collection
        // and `MkCol` answers `DAV:valid-resourcetype` by itself. The case the
        // guard is for is a plain folder *below* an address book, which §5.2
        // forbids at any depth and which a store has no way of knowing.
        $created = $server->handle(new Request(
            'MKCOL',
            '/addressbooks/simon/contacts/photos/more',
            body: new Body(
                '<D:mkcol xmlns:D="DAV:" xmlns:C="' . self::NS . '">'
                . '<D:set><D:prop><D:resourcetype><D:collection/><C:addressbook/></D:resourcetype></D:prop></D:set>'
                . '</D:mkcol>',
            ),
        ));

        self::assertSame(403, $created->status());

        $moved = $server->handle(new Request(
            'MOVE',
            '/addressbooks/simon/work',
            new Headers(['Destination' => '/addressbooks/simon/contacts/work']),
        ));

        self::assertSame(403, $moved->status());
        self::assertStringContainsString('addressbook-collection-location-ok', (string) $moved->body());

        $copied = $server->handle(new Request(
            'COPY',
            '/addressbooks/simon/work',
            new Headers(['Destination' => '/addressbooks/simon/contacts/copy']),
        ));

        self::assertSame(403, $copied->status());
    }

    /**
     * **An address book inside an address book is refused.** §5.2: "Collections
     * contained in address book collections MUST NOT contain address book
     * collections at any depth."
     */
    public function testAnAddressBookInsideAnAddressBookIsRefused(): void
    {
        $this->expectException(Forbidden::class);
        $this->expectExceptionMessageMatches('/address book/');

        $this->createAt('addressbooks/simon/contacts/more');
    }

    /**
     * **At any depth** — the words are the memo's own, and a plain collection
     * in between does not make it allowed.
     */
    public function testAtAnyDepthMeansAtAnyDepth(): void
    {
        $this->expectException(Forbidden::class);

        $this->createAt('addressbooks/simon/contacts/photos/deeper/more');
    }

    /**
     * **Beside one is allowed**, and §5.2 says so: "Multiple address book
     * collections MAY be children of the same collection."
     */
    public function testBesideOneIsAllowed(): void
    {
        $this->createAt('addressbooks/simon/work');

        $this->expectNotToPerformAssertions();
    }

    /**
     * **A plain collection below an address book is allowed**, because §5.2
     * forbids address books there and nothing else.
     */
    public function testAPlainCollectionBelowAnAddressBookIsAllowed(): void
    {
        $this->createAt('addressbooks/simon/contacts/photos', resourceTypes: []);

        $this->expectNotToPerformAssertions();
    }

    /**
     * **Moving an address book under another is refused**, and it is refused
     * with the precondition §6.3.2.1 defines for exactly this:
     *
     * > (CARDDAV:addressbook-collection-location-ok): In a COPY or MOVE
     * > request, when the Request-URI is an address book collection, the URI
     * > targeted by the Destination HTTP Request header MUST identify a
     * > location where an address book collection can be created.
     *
     * Without this, the nesting rule would hold for `MKCOL` and be a
     * formality: a client would make the address book beside and move it in.
     */
    public function testMovingAnAddressBookUnderAnotherIsRefused(): void
    {
        $refusal = null;

        try {
            $this->transfer(new BeforeMove('addressbooks/simon/work', 'addressbooks/simon/contacts/work'));
        } catch (Forbidden $thrown) {
            $refusal = $thrown;
        }

        self::assertNotNull($refusal);
        self::assertSame('{' . self::NS . '}addressbook-collection-location-ok', $refusal->errorElement());
    }

    /**
     * **And so is copying one**, which §6.3.2.1 names in the same breath.
     */
    public function testCopyingAnAddressBookUnderAnotherIsRefused(): void
    {
        $this->expectException(Forbidden::class);

        $this->transfer(new BeforeCopy('addressbooks/simon/work', 'addressbooks/simon/contacts/work'));
    }

    /**
     * **A collection that merely *holds* an address book is refused too**,
     * and §5.2(b) is why:
     *
     * > Collections contained in address book collections MUST NOT contain
     * > address book collections **at any depth**.
     *
     * Checking only whether the thing being moved is itself an address book
     * would leave the rule open by one step: a client would put the address
     * book in a folder and move the folder. The walk is paid for only when the
     * destination is below an address book, which is the one case it can
     * matter in.
     */
    public function testACollectionThatMerelyHoldsAnAddressBookIsRefusedToo(): void
    {
        $this->expectException(Forbidden::class);

        $this->transfer(new BeforeMove('addressbooks/simon/folder', 'addressbooks/simon/contacts/folder'));
    }

    /**
     * **A folder with no address book in it may be moved into one**, which is
     * §5.2(a) in as many words: an address book "MUST only contain address
     * object resources and collections that are **not** address book
     * collections". A rule that refused every folder would be stricter than
     * the specification it cites.
     */
    public function testAFolderWithNoAddressBookInItMayBeMovedIntoOne(): void
    {
        $this->transfer(new BeforeMove('addressbooks/simon/spare', 'addressbooks/simon/contacts/spare'));

        $this->expectNotToPerformAssertions();
    }

    /**
     * **And one that holds an address book may still be moved somewhere
     * ordinary**, because §5.2(b) is about what ends up inside an address
     * book, not about folders in general.
     */
    public function testOneThatHoldsAnAddressBookMayStillGoSomewhereOrdinary(): void
    {
        $this->transfer(new BeforeMove('addressbooks/simon/folder', 'addressbooks/simon/elsewhere'));

        $this->expectNotToPerformAssertions();
    }

    /**
     * **Moving something that is not an address book is not this plugin's
     * business.** The precondition is scoped by its own first words — "when
     * the Request-URI is an address book collection" — so a card moved inside
     * an address book goes through, which is the ordinary case.
     */
    public function testMovingSomethingThatIsNotAnAddressBookGoesThrough(): void
    {
        $this->transfer(new BeforeMove('addressbooks/simon/loose.vcf', 'addressbooks/simon/contacts/loose.vcf'));

        $this->expectNotToPerformAssertions();
    }

    /**
     * **And moving an address book somewhere ordinary goes through too**, or
     * the rule would stop a client tidying its own account.
     */
    public function testMovingAnAddressBookSomewhereOrdinaryGoesThrough(): void
    {
        $this->transfer(new BeforeMove('addressbooks/simon/work', 'addressbooks/simon/elsewhere'));

        $this->expectNotToPerformAssertions();
    }

    /**
     * **A path that is not in the tree is nobody's business here.** A `MOVE`
     * from a source that does not exist is a `404` the method raises, and a
     * plugin that fell over looking for it would turn that into a `500`.
     */
    public function testAPathThatIsNotInTheTreeIsNobodysBusinessHere(): void
    {
        $this->transfer(new BeforeMove('nowhere/at/all', 'addressbooks/simon/contacts/work'));

        $this->expectNotToPerformAssertions();
    }

    /**
     * The tree these tests work in: an address book home with one address
     * book in it, a plain collection inside that address book, and a card
     * sitting loose beside them.
     */
    private function tree(): MemoryCollection
    {
        $root = new MemoryCollection('');
        $home = new MemoryCollection('addressbooks');
        $simon = new MemoryCollection('simon');
        $contacts = new MemoryCollection('contacts');

        $contacts->add(new MemoryAddressBookHome('photos'));
        $simon->add(AddressBook::of($contacts));
        $simon->add(AddressBook::of(new MemoryCollection('work')));
        $simon->createFile('loose.vcf', 'BEGIN:VCARD');
        $simon->add((new MemoryCollection('folder'))->add(AddressBook::of(new MemoryCollection('buried'))));
        $simon->add((new MemoryCollection('spare'))->add(new MemoryCollection('inner')));
        $home->add($simon);
        $root->add($home);
        $root->add((new MemoryCollection('principals'))->add($this->principal('simon')));

        return $root;
    }

    private function server(): Server
    {
        return new Server(new Tree($this->tree()));
    }

    private function principal(string $name): Principal
    {
        return new Principal(new PrincipalInfo($name, $name));
    }

    /**
     * What the plugin answers for one property on one node.
     */
    private function answerFor(string $property, ?CardDav $plugin = null, ?INode $node = null): Element|string|null
    {
        $node ??= AddressBook::of(new MemoryCollection('contacts'));

        return $this->ask($node, [$property], $plugin)[$property] ?? null;
    }

    /**
     * @param list<string> $properties
     *
     * @return array<string, Element|string|null>
     */
    private function ask(INode $node, array $properties, ?CardDav $plugin = null): array
    {
        $plugin ??= new CardDav($this->server());
        $result = new PropFindResult('addressbooks/simon/contacts', PropFindForm::Named, $properties);

        $plugin->describe(new PropertiesRequested($result, $node));

        return $result->byStatus()[200] ?? [];
    }

    /**
     * @param list<string> $resourceTypes
     */
    private function createAt(string $path, array $resourceTypes = [AddressBook::ADDRESSBOOK]): void
    {
        $server = $this->server();

        (new CardDav($server))->refuseNesting(new BeforeCreateCollection($path, $resourceTypes));
    }

    private function transfer(BeforeCopy|BeforeMove $event): void
    {
        $server = $this->server();

        (new CardDav($server))->refuseABadDestination($event);
    }

    /**
     * @return list<string>
     */
    private function hrefsIn(Element $property): array
    {
        $hrefs = [];

        foreach ($property->children() as $child) {
            self::assertSame('{DAV:}href', $child->name());

            $hrefs[] = $child->text();
        }

        return $hrefs;
    }
}
