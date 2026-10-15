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
use DavServices\Dav\Event\BeforeCreateFile;
use DavServices\Dav\Event\BeforeMove;
use DavServices\Dav\Event\BeforeWriteContent;
use DavServices\Dav\Event\OptionsRequested;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\IFile;
use DavServices\Dav\INode;
use DavServices\Dav\Method\Copy;
use DavServices\Dav\Method\MkCol;
use DavServices\Dav\Method\Move;
use DavServices\Dav\Method\Options;
use DavServices\Dav\Method\PropFind;
use DavServices\Dav\Method\PropPatch;
use DavServices\Dav\Method\Put;
use DavServices\Dav\Method\Report;
use DavServices\Dav\PropFindForm;
use DavServices\Dav\PropFindResult;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Exception\Forbidden;
use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Plugin\CardDav;
use DavServices\Plugin\DeadProperties;
use DavServices\Tests\Unit\Backend\MemoryPropertyStorage;
use DavServices\Tests\Unit\CardDav\MemoryAddressBookHome;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Tests\Unit\Dav\StreamFile;
use DavServices\Xml\Element;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for the CardDAV plugin. R-CARD-01, R-CARD-02, P5-01.
 *
 * Switched on in one line, like every other plugin here:
 *
 *     (new CardDav($server, 'addressbooks'))->register($report);
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
        (new CardDav($server))->register(new Report($server));

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

        (new CardDav($server))->register(new Report($server));
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
     * **§8.6: "Support for the CARDDAV:addressbook-query REPORT is
     * REQUIRED."** So the plugin asks for the `REPORT` method and wires the
     * query itself, the way {@see \DavServices\Plugin\Acl} wires the four of
     * RFC 3744 — a CardDAV server that did not answer it would not be one, and
     * an application left to remember the line would sooner or later ship
     * without it.
     *
     * What the report answers is tested where it lives, under
     * `CardDav\Report`. What is proved here is that it is reachable at all:
     * without the wiring, `Report` refuses the name with `403` and
     * `DAV:supported-report`.
     */
    public function testRegisterAnswersTheQueryReportTheMemoRequires(): void
    {
        $server = new Server(new Tree($this->tree()));
        $report = new Report($server);

        (new CardDav($server))->register($report);
        $report->register();

        $response = $server->handle(new Request(
            'REPORT',
            '/addressbooks/simon/work',
            headers: new Headers(['Depth' => '1']),
            body: new Body('<C:addressbook-query xmlns:C="' . self::NS . '"><C:filter/></C:addressbook-query>'),
        ));

        self::assertSame(207, $response->status());
        self::assertStringContainsString('<d:multistatus', (string) $response->body());
    }

    /**
     * **§8.7: "Support for the addressbook-multiget REPORT is REQUIRED."** The
     * second of the two the compliance class promises, wired for the same
     * reason as the first.
     *
     * The body names one card that is there, so a `207` with its href proves
     * the whole path: the report is reachable, it read the href, and it found
     * the resource.
     */
    public function testRegisterAnswersTheMultigetReportTheMemoRequires(): void
    {
        $server = new Server(new Tree($this->tree()));
        $report = new Report($server);

        (new CardDav($server))->register($report);
        $report->register();

        $response = $server->handle(new Request(
            'REPORT',
            '/addressbooks/simon',
            headers: new Headers(['Depth' => '0']),
            body: new Body(
                '<C:addressbook-multiget xmlns:C="' . self::NS . '" xmlns:D="DAV:">'
                . '<D:href>/addressbooks/simon/loose.vcf</D:href>'
                . '</C:addressbook-multiget>',
            ),
        ));

        self::assertSame(207, $response->status());
        self::assertStringContainsString('/addressbooks/simon/loose.vcf', (string) $response->body());
    }

    /**
     * **What the plugin advertises is what the reports are held to.** §6.2.2
     * publishes the media types this server keeps and §8.6 and §8.7 make them
     * a precondition of both reports, so one list has to reach both — a plugin
     * announcing 4.0 while its reports refused it would be announcing
     * something untrue.
     */
    public function testWhatItAdvertisesIsWhatTheReportsAnswer(): void
    {
        $server = new Server(new Tree($this->tree()));
        $report = new Report($server);

        (new CardDav($server, supportedAddressData: [['text/vcard', '4.0']]))->register($report);
        $report->register();

        $response = $server->handle(new Request(
            'REPORT',
            '/addressbooks/simon/work',
            headers: new Headers(['Depth' => '1']),
            body: new Body(
                '<C:addressbook-query xmlns:C="' . self::NS . '" xmlns:D="DAV:"><D:prop>'
                . '<C:address-data version="3.0"/></D:prop><C:filter/></C:addressbook-query>',
            ),
        ));

        self::assertSame(403, $response->status());
        self::assertStringContainsString('supported-address-data', (string) $response->body());
    }
    /**
     * **§6.3.2.1 adds four preconditions to `PUT`, and a card that breaks none
     * of them is stored.** The happy case first, because everything below is a
     * refusal and a guard that refused everything would pass each of them.
     */
    public function testACardAnAddressBookWillHaveIsStored(): void
    {
        $response = $this->putting('/addressbooks/simon/cards/new.vcf', self::card('fresh'));

        self::assertSame(201, $response->status());
    }

    /**
     * **§6.3.2.2 makes this a MUST, and it is the one a validator breaks
     * first:** "Servers MUST support the use of non-standard properties and
     * parameters in address object resources stored via the PUT method."
     */
    public function testACardWithNonStandardPropertiesIsStored(): void
    {
        $card = "BEGIN:VCARD\r\nVERSION:4.0\r\nUID:x\r\nFN:Simon\r\n"
            . "X-ABC-PRIVATE:whatever\r\nTEL;X-ABC-KIND=mobile:+1\r\nX-ABC.TEL:+2\r\nEND:VCARD\r\n";

        self::assertSame(201, $this->putting('/addressbooks/simon/cards/x.vcf', $card)->status());
    }

    /**
     * §6.3.2.1: "(CARDDAV:valid-address-data): The resource submitted in the
     * PUT request […] MUST be valid data for the media type being specified
     * (i.e., MUST contain valid vCard data)."
     */
    public function testRefusesABodyThatIsNoVcard(): void
    {
        $response = $this->putting('/addressbooks/simon/cards/x.vcf', 'This is a note, not a card.');

        self::assertSame(403, $response->status());
        self::assertStringContainsString('valid-address-data', (string) $response->body());
    }

    /**
     * §5.1: "Address object resources contained in address book collections
     * MUST contain a single vCard component only." Two of them is not one of
     * them, and it is `valid-address-data` that says so — the data is not
     * valid *for an address book*, whatever each half of it would be on its
     * own.
     */
    public function testRefusesTwoCardsInOneBody(): void
    {
        $response = $this->putting('/addressbooks/simon/cards/x.vcf', self::card('one') . self::card('two'));

        self::assertSame(403, $response->status());
        self::assertStringContainsString('valid-address-data', (string) $response->body());
    }

    /**
     * **§5.1 makes a `UID` mandatory where vCard itself does not.** RFC 6350
     * §6.7.6 lists `UID` among the properties a vCard may have; RFC 6352 §5.1
     * turns that into "vCard components in an address book collection MUST
     * have a UID property value". A card without one cannot be held to the
     * uniqueness rule in the next sentence, so it is not valid data for an
     * address book.
     */
    public function testRefusesACardWithoutAUid(): void
    {
        $card = "BEGIN:VCARD\r\nVERSION:4.0\r\nFN:Nameless\r\nEND:VCARD\r\n";
        $response = $this->putting('/addressbooks/simon/cards/x.vcf', $card);

        self::assertSame(403, $response->status());
        self::assertStringContainsString('valid-address-data', (string) $response->body());
    }

    /**
     * §6.3.2.1: "(CARDDAV:supported-address-data): The resource submitted in
     * the PUT request […] MUST be a supported media type (i.e., vCard) for
     * address object resources." RFC 6352 counts the version as part of the
     * media type — §6.2.2's `address-data-type` carries `content-type` **and**
     * `version`, and §5.1.1 calls the pair a media type — so a 2.1 card is an
     * unsupported media type for a server that advertises 3.0 and 4.0.
     *
     * `415` rather than `403`, because an unsupported media type is the one
     * precondition here that HTTP already has a status for.
     */
    public function testRefusesAVersionItNeverAdvertised(): void
    {
        $card = "BEGIN:VCARD\r\nVERSION:2.1\r\nUID:old\r\nFN:Ancient\r\nEND:VCARD\r\n";
        $response = $this->putting('/addressbooks/simon/cards/x.vcf', $card);

        self::assertSame(415, $response->status());
        self::assertStringContainsString('supported-address-data', (string) $response->body());
    }

    /**
     * A deployment that says it keeps 2.1 keeps it: the list the plugin
     * publishes is the list the guard holds a card to, so the property and the
     * refusal cannot say different things.
     */
    public function testAVersionItDoesAdvertiseIsStored(): void
    {
        $card = "BEGIN:VCARD\r\nVERSION:2.1\r\nUID:old\r\nFN:Ancient\r\nEND:VCARD\r\n";
        $response = $this->putting(
            '/addressbooks/simon/cards/x.vcf',
            $card,
            supported: [['text/vcard', '2.1'], ['text/vcard', '4.0']],
        );

        self::assertSame(201, $response->status());
    }

    /**
     * §6.3.2.1: "(CARDDAV:max-resource-size): The resource submitted in the
     * PUT request […] MUST have a size in octets less than or equal to the
     * value of the CARDDAV:max-resource-size property value (Section 6.2.3) on
     * the address book collection where the resource will be stored."
     *
     * "less than or equal to", so the limit itself still fits — and `413`,
     * which is the status HTTP has for a body that is too large.
     */
    public function testRefusesACardLargerThanTheLimit(): void
    {
        $response = $this->putting('/addressbooks/simon/cards/x.vcf', self::card('big'), maxSize: 10);

        self::assertSame(413, $response->status());
        self::assertStringContainsString('max-resource-size', (string) $response->body());
    }

    public function testACardOfExactlyTheLimitIsStored(): void
    {
        $card = self::card('exact');
        $response = $this->putting('/addressbooks/simon/cards/x.vcf', $card, maxSize: strlen($card));

        self::assertSame(201, $response->status());
    }

    /**
     * **§6.3.2.1's third precondition, and the one with a shape of its own:**
     * "(CARDDAV:no-uid-conflict): The resource submitted in the PUT request
     * […] MUST NOT specify a vCard UID property value already in use in the
     * targeted address book collection […] the server SHOULD report the URL of
     * the resource that is already making use of the same UID property value
     * in the DAV:href element."
     *
     *     <!ELEMENT no-uid-conflict (DAV:href)>
     *
     * The href is what makes the refusal useful: a client that stored the card
     * under another name learns where it is instead of guessing.
     */
    public function testRefusesAUidAnotherCardInTheBookAlreadyUses(): void
    {
        $response = $this->putting('/addressbooks/simon/cards/other.vcf', self::card('already-here'));
        $body = (string) $response->body();

        self::assertSame(403, $response->status());
        self::assertStringContainsString('no-uid-conflict', $body);
        self::assertStringContainsString('<d:href>/addressbooks/simon/cards/simon.vcf</d:href>', $body);
    }

    /**
     * **Replacing a card with itself is no conflict.** §6.3.2 has a PUT "at a
     * mapped URI" update what is there, and the card already holding that UID
     * *is* what is being replaced — a guard that counted it would make every
     * card in the server unmodifiable.
     */
    public function testReplacingACardWithItsOwnUidIsNoConflict(): void
    {
        $response = $this->putting('/addressbooks/simon/cards/simon.vcf', self::card('already-here'));

        self::assertSame(204, $response->status());
    }

    /**
     * §5.1: the uniqueness is "in the scope of the address book collection in
     * which it is contained" — and §5.2 lets an address book hold "collections
     * that are not address book collections", so the scope reaches into them.
     */
    public function testTheScopeReachesIntoAFolderInsideTheBook(): void
    {
        $response = $this->putting('/addressbooks/simon/cards/other.vcf', self::card('buried'));

        self::assertSame(403, $response->status());
        self::assertStringContainsString('no-uid-conflict', (string) $response->body());
    }

    /**
     * And it reaches no further: "in the scope of the address book collection"
     * is one book, so the same UID in two books is two cards about the same
     * person in two places, which is what a client does on purpose.
     */
    public function testTheSameUidInAnotherBookIsNoConflict(): void
    {
        $response = $this->putting('/addressbooks/simon/work/mine.vcf', self::card('already-here'));

        self::assertSame(201, $response->status());
    }

    /**
     * **A file outside an address book is nobody's business here.** §6.3.2.1
     * applies its preconditions "when a PUT operation of an address object
     * resource into an address book collection occurs" — a plain WebDAV
     * collection takes whatever a client puts in it, and a CardDAV plugin that
     * validated everything would turn a WebDAV server into a card store.
     */
    public function testAFileOutsideAnAddressBookIsNotChecked(): void
    {
        $response = $this->putting('/addressbooks/simon/spare/anything.txt', 'This is a note, not a card.');

        self::assertSame(201, $response->status());
    }

    /**
     * **The body arrives as a stream, and reading it must not empty it.**
     * `Dav\Method\Put` hands the events `$request->body()->stream()`, and
     * `BeforeCreateFile` says what a listener owes: "A listener that means to
     * look at it has to put back something the write can use […] reading the
     * stream without replacing it is a way to store an empty file."
     */
    public function testTheCardIsStillThereAfterBeingRead(): void
    {
        $server = $this->serverTaking();
        $card = self::card('fresh');

        $server->handle(new Request('PUT', '/addressbooks/simon/cards/new.vcf', body: new Body($card)));

        $stored = $server->tree()->node('/addressbooks/simon/cards/new.vcf');

        self::assertInstanceOf(IFile::class, $stored);
        self::assertSame($card, $stored->get());
    }

    /**
     * §6.3.2.1 applies the same preconditions "when a COPY or MOVE operation
     * of an address object resource into an address book collection occurs" —
     * so a client cannot reach a conflicting UID into a book sideways.
     */
    public function testACopyThatWouldTakeAUidTwiceIsRefused(): void
    {
        $response = $this->transferring('COPY', '/addressbooks/simon/cards/simon.vcf', '/addressbooks/simon/work/copy.vcf');

        self::assertSame(201, $response->status(), 'into another book it is free');

        $refused = $this->transferring('COPY', '/addressbooks/simon/cards/simon.vcf', '/addressbooks/simon/cards/again.vcf');

        self::assertSame(403, $refused->status());
        self::assertStringContainsString('no-uid-conflict', (string) $refused->body());
    }

    /**
     * **A `MOVE` inside one book is not a conflict with itself.** The card at
     * the source is on its way out, so counting it would make a rename
     * impossible — and renaming a card is the one thing §6.3.2 says the URL is
     * free for: "The URL for each address object resource is entirely
     * arbitrary".
     */
    public function testAMoveInsideOneBookIsNoConflict(): void
    {
        $response = $this->transferring('MOVE', '/addressbooks/simon/cards/simon.vcf', '/addressbooks/simon/cards/renamed.vcf');

        self::assertSame(201, $response->status());
    }

    /**
     * And a `MOVE` that lands beside a card already holding the UID is a
     * conflict like any other.
     */
    public function testAMoveThatWouldTakeAUidTwiceIsRefused(): void
    {
        $response = $this->transferring('MOVE', '/addressbooks/simon/loose/stray.vcf', '/addressbooks/simon/cards/stray.vcf');

        self::assertSame(403, $response->status());
        self::assertStringContainsString('no-uid-conflict', (string) $response->body());
    }

    /**
     * A card of RFC 6352's own example shape, with the UID a case needs.
     */
    private static function card(string $uid): string
    {
        return sprintf("BEGIN:VCARD\r\nVERSION:4.0\r\nUID:%s\r\nFN:Simon Perreault\r\nEND:VCARD\r\n", $uid);
    }

    /**
     * @param list<array{string, string}>|null $supported What the server says
     *                                                    it keeps, where that
     *                                                    is the point of the
     *                                                    case
     */
    private function putting(string $path, string $body, ?int $maxSize = null, ?array $supported = null): Response
    {
        $server = $this->serverTaking($maxSize, $supported);

        return $server->handle(new Request('PUT', $path, body: new Body($body)));
    }

    private function transferring(string $method, string $from, string $to): Response
    {
        $server = $this->serverTaking();

        return $server->handle(new Request(
            $method,
            $from,
            headers: new Headers(['Destination' => $to]),
        ));
    }

    /**
     * A server with the plugin wired and the three methods §6.3.2.1 names.
     *
     * @param list<array{string, string}>|null $supported
     */
    private function serverTaking(?int $maxSize = null, ?array $supported = null): Server
    {
        $server = new Server(new Tree($this->tree()));
        $plugin = new CardDav(
            $server,
            maxResourceSize: $maxSize ?? 8_388_608,
            supportedAddressData: $supported ?? [['text/vcard', '3.0'], ['text/vcard', '4.0']],
        );

        $plugin->register(new Report($server));

        $server->onMethod('PUT', (new Put($server))(...));
        $server->onMethod('COPY', (new Copy($server))(...));
        $server->onMethod('MOVE', (new Move($server))(...));

        return $server;
    }

    /**
     * **§6.3.2: "A PUT request targeted at a mapped URI updates an existing
     * address object resource."** That raises a different event than a
     * creation does, and a guard wired to only one of the two would let a
     * client replace a good card with anything at all.
     */
    public function testReplacingACardWithSomethingThatIsNoCardIsRefused(): void
    {
        $response = $this->putting('/addressbooks/simon/cards/simon.vcf', 'This is a note, not a card.');

        self::assertSame(403, $response->status());
        self::assertStringContainsString('valid-address-data', (string) $response->body());
    }

    /**
     * **RFC 6350 §6.7.9: "This property MUST be present in the vCard
     * object."** A card without `VERSION` parses — the reader keeps what it is
     * given — but it is not valid vCard data, and `CARDDAV:valid-address-data`
     * is the precondition that says so.
     */
    public function testRefusesACardWithoutAVersion(): void
    {
        $card = "BEGIN:VCARD\r\nUID:x\r\nFN:Nameless\r\nEND:VCARD\r\n";
        $response = $this->putting('/addressbooks/simon/cards/x.vcf', $card);

        self::assertSame(403, $response->status());
        self::assertStringContainsString('valid-address-data', (string) $response->body());
    }

    /**
     * **The whole book is searched, not the first answer that turns up.** A
     * `UID` held by a card that comes *after* a subfolder in the collection's
     * own order is still in use — a walk that stopped at the first folder
     * would let a client take it.
     */
    public function testAUidHeldAfterASubfolderIsStillInUse(): void
    {
        $response = $this->putting('/addressbooks/simon/cards/new.vcf', self::card('moved-in'));

        self::assertSame(403, $response->status());
        self::assertStringContainsString('no-uid-conflict', (string) $response->body());
    }

    /**
     * **And the search carries on past the card being replaced.** Replacing a
     * card is allowed to change its `UID` (§6.3.2 makes the name arbitrary and
     * says nothing about the content), but not to a `UID` another card in the
     * book already holds — so the walk steps over the one it is replacing and
     * keeps looking.
     */
    public function testReplacingACardMayNotTakeAUidAnotherOneHolds(): void
    {
        $response = $this->putting('/addressbooks/simon/cards/simon.vcf', self::card('moved-in'));

        self::assertSame(403, $response->status());
        self::assertStringContainsString('no-uid-conflict', (string) $response->body());
    }

    /**
     * **A store that hands out a stream is read as readily as one that hands
     * out a string.** `IFile::get()` says "The content, as a stream wherever
     * the backend can manage one. A string is allowed for what is small and
     * already in memory. The caller must cope with both" — and a `COPY` has
     * no event to put a stream back into, which is the case this covers.
     */
    public function testACardWhoseStoreStreamsItIsCheckedToo(): void
    {
        $refused = $this->transferring('COPY', '/addressbooks/simon/cards/streamed.vcf', '/addressbooks/simon/cards/again.vcf');

        self::assertSame(403, $refused->status(), 'its own UID is in use where it already is');
        self::assertStringContainsString('no-uid-conflict', (string) $refused->body());

        $allowed = $this->transferring('COPY', '/addressbooks/simon/cards/streamed.vcf', '/addressbooks/simon/work/copy.vcf');

        self::assertSame(201, $allowed->status(), 'and free in another book');
    }
    /**
     * **The guard asked directly**, the way the other two are tested here.
     *
     * Each rule above is driven through a real request, which is what proves
     * the listener is wired at all; this one holds the listener itself to the
     * two answers it has — nothing to say about a file outside an address
     * book, and a named refusal for one inside.
     */
    public function testTheGuardHasNothingToSayAboutAFileOutsideABook(): void
    {
        $server = $this->server();

        (new CardDav($server))->validateTheCard(
            new BeforeCreateFile('addressbooks/simon/spare/anything.txt', 'This is a note, not a card.'),
        );

        $this->expectNotToPerformAssertions();
    }

    public function testTheGuardRefusesANonCardInsideABook(): void
    {
        $server = $this->server();

        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage('single vCard component');

        (new CardDav($server))->validateTheCard(
            new BeforeCreateFile('addressbooks/simon/cards/x.vcf', 'This is a note, not a card.'),
        );
    }

    /**
     * And the same through `BeforeWriteContent`, which is the event a `PUT`
     * at a mapped URI raises — §6.3.2: "A PUT request targeted at a mapped URI
     * updates an existing address object resource."
     */
    public function testTheGuardChecksAReplacementToo(): void
    {
        $server = $this->server();

        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage('single vCard component');

        (new CardDav($server))->validateTheCard(
            new BeforeWriteContent('addressbooks/simon/cards/simon.vcf', 'This is a note, not a card.'),
        );
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

        (new CardDav($server))->register(new Report($server));

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
     * **Moving something that is not an address book is not §5.2's
     * business.** That precondition is scoped by its own first words — "when
     * the Request-URI is an address book collection" — so a card moved into an
     * address book goes through it, which is the ordinary case.
     *
     * A valid card, because §6.3.2.1's preconditions **are** about cards and
     * apply to the same `MOVE`: the case below is the one where they bite.
     */
    public function testMovingSomethingThatIsNotAnAddressBookGoesThrough(): void
    {
        $this->transfer(new BeforeMove('addressbooks/simon/cards/simon.vcf', 'addressbooks/simon/contacts/simon.vcf'));

        $this->expectNotToPerformAssertions();
    }

    /**
     * **And §6.3.2.1 is about the card rather than the collection**, so a file
     * that is no card at all is refused on its way into an address book — the
     * section applies "when a COPY or MOVE operation of an address object
     * resource into an address book collection occurs", and the refusal says
     * which of the two rules it broke.
     */
    public function testMovingSomethingThatIsNoCardIntoABookIsRefused(): void
    {
        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage('single vCard component');

        $this->transfer(new BeforeMove('addressbooks/simon/loose.vcf', 'addressbooks/simon/contacts/loose.vcf'));
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
        $simon->add(AddressBook::of(
            (new MemoryCollection('cards'))
                ->add(new MemoryFile('simon.vcf', self::card('already-here')))
                ->add((new MemoryCollection('inner'))->add(new MemoryFile('buried.vcf', self::card('buried'))))
                ->add(new MemoryFile('stray.vcf', self::card('moved-in')))
                ->add(new MemoryFile('note.txt', 'This is a note, not a card.'))
                ->add(new MemoryFile('nouid.vcf', "BEGIN:VCARD\r\nVERSION:4.0\r\nFN:Nameless\r\nEND:VCARD\r\n"))
                ->add(new StreamFile('streamed.vcf', self::card('streamed'))),
        ));
        $simon->add((new MemoryCollection('loose'))->add(new MemoryFile('stray.vcf', self::card('already-here'))));
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
