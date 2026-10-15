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

use DavServices\CardDav\AddressData;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use DavServices\Exception\UnsupportedMediaType;
use DavServices\VObject\Component;
use DavServices\VObject\Reader;
use DavServices\Xml\Element;
use DavServices\Xml\Reader as XmlReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for `CARDDAV:address-data`. R-CARD-05, P5-04.
 *
 * RFC 6352 §10.4 gives the element two shapes, one per direction:
 *
 *     <!ELEMENT address-data (allprop | prop*)>   in a request
 *     <!ELEMENT address-data (#PCDATA)>           in a response
 *     <!ATTLIST address-data content-type CDATA "text/vcard"
 *                           version CDATA "3.0">
 *
 * "If the CARDDAV:address-data XML element doesn't contain any CARDDAV:prop
 * elements, address object resources will be returned in their entirety.
 * Additionally, a media type and version can be specified to request that the
 * server return the data in that format if possible."
 *
 * **It is not a WebDAV property**, which the same section says twice: it is
 * written "inside the DAV:prop XML element as if it were a WebDAV property.
 * However, the CARDDAV:address-data XML element is not a WebDAV property and as
 * such it is not returned in PROPFIND responses nor used in PROPPATCH
 * requests."
 */
#[CoversClass(AddressData::class)]
final class AddressDataTest extends TestCase
{
    private const CARDDAV = 'xmlns="urn:ietf:params:xml:ns:carddav"';

    /** What this server says it keeps, as `Plugin\CardDav` advertises by default. */
    private const SUPPORTED = [['text/vcard', '3.0'], ['text/vcard', '4.0']];

    private const SIMON = "BEGIN:VCARD\r\nVERSION:4.0\r\nUID:simon\r\nFN:Simon Perreault\r\n"
        . "NICKNAME:Si\r\nTEL;TYPE=work:+1 418 656 9254\r\nX-ABC.TEL:+1 555 0100\r\nEND:VCARD\r\n";

    /**
     * **Without a `prop` the whole card comes back**, and §10.4 says so: "If
     * the CARDDAV:address-data XML element doesn't contain any CARDDAV:prop
     * elements, address object resources will be returned in their entirety."
     */
    public function testWithoutAPropTheWholeCardComesBack(): void
    {
        $text = $this->dataOf()->textOf($this->card(self::SIMON));

        self::assertStringContainsString("FN:Simon Perreault\r\n", $text);
        self::assertStringContainsString("NICKNAME:Si\r\n", $text);
        self::assertStringContainsString("UID:simon\r\n", $text);
    }

    /**
     * **§10.4's second shape**, which is what a response carries:
     * `<!ELEMENT address-data (#PCDATA)>`. The card goes in as character data,
     * and the writer escapes it — "The address data embedded within the
     * CARDDAV:address-data XML element MUST follow the standard XML character
     * data encoding rules".
     */
    public function testTheResponseElementCarriesTheCardAsText(): void
    {
        $element = $this->dataOf('<prop name="FN"/>')->elementFor($this->card(self::SIMON));

        self::assertSame('{urn:ietf:params:xml:ns:carddav}address-data', $element->name());
        self::assertStringContainsString("FN:Simon Perreault\r\n", $element->text());
        self::assertSame([], $element->children(), 'the request shape has children, the response shape has text');
    }
    /**
     * §10.4.1 gives CardDAV its own `allprop`, in its own namespace, for a
     * client that would rather say so than leave the element empty.
     */
    public function testAllpropIsTheSameAsSayingNothing(): void
    {
        $whole = $this->dataOf()->textOf($this->card(self::SIMON));
        $said = $this->dataOf('<allprop/>')->textOf($this->card(self::SIMON));

        self::assertSame($whole, $said);
    }

    /**
     * **The default version is `3.0`**, which the DTD fixes and which is easy
     * to overlook now that 4.0 is the current one: a client that says nothing
     * gets 3.0, whatever the card was stored as.
     */
    public function testTheDefaultVersionIsThreeZero(): void
    {
        $text = $this->dataOf()->textOf($this->card(self::SIMON));

        self::assertStringContainsString("VERSION:3.0\r\n", $text);
    }

    public function testTheVersionAskedForIsTheOneReturned(): void
    {
        $text = $this->dataOf('', 'version="4.0"')->textOf($this->card(self::SIMON));

        self::assertStringContainsString("VERSION:4.0\r\n", $text);
    }

    /**
     * RFC 9110 §8.3.1 makes a media type's "type, subtype, and parameter name
     * tokens" case-insensitive, so a client shouting it means the same thing.
     */
    public function testTheMediaTypeIsReadWhateverItsCase(): void
    {
        $text = $this->dataOf('', 'content-type="TEXT/VCARD" version="4.0"')->textOf($this->card(self::SIMON));

        self::assertStringContainsString("VERSION:4.0\r\n", $text);
    }

    /**
     * **Both sides of that comparison, because both are media types.** A
     * deployment that wrote its `CARDDAV:supported-address-data` in capitals
     * means what a client asking in lower case means — RFC 9110 §8.3.1 makes
     * the tokens case-insensitive wherever they are written, not only where a
     * client writes them.
     */
    public function testWhatTheServerAdvertisedIsReadWhateverItsCase(): void
    {
        $data = $this->dataOf('', 'content-type="text/vcard" version="4.0"', [['TEXT/VCARD', '4.0']]);

        self::assertStringContainsString("VERSION:4.0\r\n", $data->textOf($this->card(self::SIMON)));
    }

    /**
     * **§8.6 and §8.7 both make this a precondition:** "(CARDDAV:supported-address-data):
     * The attributes 'content-type' and 'version' of the CARDDAV:address-data
     * XML element […] specify a media type supported by the server for address
     * object resources." A client asking for something the server never
     * advertised is told which rule it broke, so that
     * `CARDDAV:supported-address-data` can tell it what to ask for instead.
     */
    public function testRefusesAMediaTypeTheServerNeverAdvertised(): void
    {
        try {
            $this->dataOf('', 'content-type="application/vcard+json" version="4.0"');
        } catch (Forbidden $refusal) {
            self::assertSame('{urn:ietf:params:xml:ns:carddav}supported-address-data', $refusal->errorElement());
            self::assertStringContainsString('application/vcard+json', $refusal->getMessage());

            return;
        }

        self::fail('A media type this server never advertised was accepted.');
    }

    public function testRefusesAVersionTheServerNeverAdvertised(): void
    {
        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage('2.1');

        $this->dataOf('', 'version="2.1"');
    }

    /**
     * **A server may keep more than two versions, and §5.1.1 says it then has
     * to hand them over:** "Servers might support more than one primary media
     * type for address object resources, for example, vCard v3.0 and vCard
     * v4.0. In such cases, servers have to accept all media types that they
     * advertise via the CARDDAV:supported-address-data WebDAV property." vCard
     * 2.1 is one this library can write, so a deployment that advertises it is
     * answered rather than refused.
     */
    public function testAVersionTheServerAdvertisesIsOneItWrites(): void
    {
        $data = $this->dataOf('', 'version="2.1"', [['text/vcard', '2.1'], ['text/vcard', '4.0']]);

        self::assertStringContainsString("VERSION:2.1\r\n", $data->textOf($this->card(self::SIMON)));
    }

    /**
     * **And a server may advertise something this library cannot write**, at
     * which point §5.1.1's other half applies: "In some cases, it might not be
     * possible for a server to convert from one media type to another. When
     * that happens, the server MUST return the
     * CARDDAV:supported-address-data-conversion precondition […] in the
     * DAV:response XML element in the response for the targeted address object
     * resource when one of the REPORTs defined below is used."
     *
     * The request passes the precondition — the server did advertise it — and
     * the card is what cannot be had, which is why this refusal is the per-card
     * one and not the per-request one.
     */
    public function testAVersionItCannotWriteIsRefusedPerCard(): void
    {
        $data = $this->dataOf('', 'version="5.0"', [['text/vcard', '5.0']]);

        $this->expectException(UnsupportedMediaType::class);
        $this->expectExceptionMessage('5.0');

        $data->textOf($this->card(self::SIMON));
    }

    public function testAMediaTypeItCannotWriteIsRefusedPerCard(): void
    {
        $data = $this->dataOf('', 'content-type="application/vcard+json"', [['application/vcard+json', '3.0']]);

        $this->expectException(UnsupportedMediaType::class);
        $this->expectExceptionMessage('application/vcard+json');

        $data->textOf($this->card(self::SIMON));
    }
    /**
     * §9.1 is why the restriction exists at all: "Since some property data can
     * be large (e.g., PHOTO or SOUND with in-line content) clients can choose
     * to ignore those by only requesting the specific items it knows it will
     * use."
     */
    public function testOnlyTheNamedPropertiesComeBack(): void
    {
        $text = $this->dataOf('<prop name="FN"/><prop name="UID"/>')->textOf($this->card(self::SIMON));

        self::assertStringContainsString("FN:Simon Perreault\r\n", $text);
        self::assertStringContainsString("UID:simon\r\n", $text);
        self::assertStringNotContainsString('NICKNAME', $text);
        self::assertStringNotContainsString('TEL', $text);
    }

    /**
     * **The version comes back even where nobody asked for it.** §10.4 allows
     * the answer to be invalid for its media type when the request left
     * required properties out — "address object resources specified in the
     * CARDDAV:address-data XML element MAY be invalid per their media type
     * specification" — so either choice is conformant. This one keeps
     * `VERSION`, because the `version` attribute is a promise about the data
     * and a card without it cannot be read back as a vCard at all.
     */
    public function testTheVersionStaysEvenWhenItWasNotAskedFor(): void
    {
        $text = $this->dataOf('<prop name="FN"/>')->textOf($this->card(self::SIMON));

        self::assertStringContainsString("VERSION:3.0\r\n", $text);
        self::assertSame(1, substr_count($text, 'VERSION'), 'and only once');
    }

    public function testAskingForTheVersionDoesNotDoubleIt(): void
    {
        $text = $this->dataOf('<prop name="VERSION"/><prop name="FN"/>')->textOf($this->card(self::SIMON));

        self::assertSame(1, substr_count($text, 'VERSION'));
    }

    /**
     * A property the card has not got is simply absent — the client asked what
     * it would use, not what has to be there.
     */
    public function testAPropertyTheCardHasNotGotIsSimplyAbsent(): void
    {
        $text = $this->dataOf('<prop name="PHOTO"/>')->textOf($this->card(self::SIMON));

        self::assertStringNotContainsString('PHOTO', $text);
        self::assertStringContainsString("BEGIN:VCARD\r\n", $text);
        self::assertStringContainsString("END:VCARD\r\n", $text);
    }

    /**
     * §10.4.2: "The 'novalue' attribute can be used by clients to request that
     * the actual value of the property not be returned (if the 'novalue'
     * attribute is set to 'yes'). In that case, the server will return just the
     * vCard property name and any vCard parameters and a trailing ':' without
     * the subsequent value data."
     *
     * A client uses it to learn that a card *has* a photograph without
     * fetching it.
     */
    public function testNovalueReturnsTheNameAndTheParametersAndNothingElse(): void
    {
        $text = $this->dataOf('<prop name="TEL" novalue="yes"/>')->textOf($this->card(self::SIMON));

        self::assertStringContainsString("TEL;TYPE=work:\r\n", $text);
        self::assertStringNotContainsString('656 9254', $text);
    }

    public function testSayingNoToNovalueIsTheSameAsSayingNothing(): void
    {
        $text = $this->dataOf('<prop name="FN" novalue="no"/>')->textOf($this->card(self::SIMON));

        self::assertStringContainsString("FN:Simon Perreault\r\n", $text);
    }

    public function testRefusesAThirdAnswerToNovalue(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('maybe');

        $this->dataOf('<prop name="FN" novalue="maybe"/>');
    }

    public function testRefusesAPropWithoutAName(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('name');

        $this->dataOf('<prop/>');
    }

    /**
     * **§10.4.2 repeats §10.5.1's paragraph word for word**, so the rule is the
     * same in both places: "a 'name' set to 'TEL' will match 'TEL',
     * 'X-ABC.TEL', and 'X-ABC-1.TEL' vCard properties. A 'name' set to
     * 'X-ABC.TEL' will match an 'X-ABC.TEL' vCard property only; it will not
     * match 'TEL' or 'X-ABC-1.TEL'."
     */
    public function testANameWithoutAPrefixTakesEveryPrefix(): void
    {
        $text = $this->dataOf('<prop name="TEL"/>')->textOf($this->card(self::SIMON));

        self::assertStringContainsString("TEL;TYPE=work:+1 418 656 9254\r\n", $text);
        self::assertStringContainsString("X-ABC.TEL:+1 555 0100\r\n", $text);
    }

    public function testANameWithAPrefixTakesThatPrefixOnly(): void
    {
        $text = $this->dataOf('<prop name="X-ABC.TEL"/>')->textOf($this->card(self::SIMON));

        self::assertStringContainsString("X-ABC.TEL:+1 555 0100\r\n", $text);
        self::assertStringNotContainsString('656 9254', $text);
    }

    /**
     * `(allprop | prop*)` is a choice: a request asking for everything *and*
     * for three named properties says two things at once.
     */
    public function testRefusesAllpropBesideAProp(): void
    {
        $this->expectException(BadRequest::class);

        $this->dataOf('<allprop/><prop name="FN"/>');
    }

    public function testRefusesAChildTheMemoDoesNotName(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('filter');

        $this->dataOf(sprintf('<filter %s/>', self::CARDDAV));
    }

    /**
     * **§8.7.2's own example, as a test.** A card "exists but in a media type
     * format that the server is unable to convert, resulting in an error status
     * response" — there, `415` with
     * `CARDDAV:supported-address-data-conversion`. §5.1.1 is the sentence
     * behind it: "In some cases, it might not be possible for a server to
     * convert from one media type to another."
     *
     * A stored card whose version this library cannot read is exactly that
     * case, and the refusal is per card rather than per request: the other
     * hrefs of a multiget are answered as usual.
     */
    public function testACardItCannotConvertIsRefusedByName(): void
    {
        $future = "BEGIN:VCARD\r\nVERSION:5.0\r\nUID:future\r\nFN:Future\r\nEND:VCARD\r\n";

        try {
            $this->dataOf()->textOf($this->card($future));
        } catch (UnsupportedMediaType $refusal) {
            self::assertSame(
                '{urn:ietf:params:xml:ns:carddav}supported-address-data-conversion',
                $refusal->errorElement(),
            );
            self::assertStringContainsString('3.0', $refusal->getMessage());

            return;
        }

        self::fail('A card this server cannot convert was converted anyway.');
    }

    /**
     * @param list<array{string, string}>|null $supported What the server says
     *                                                    it keeps, where that
     *                                                    is the point of the
     *                                                    case
     */
    private function dataOf(string $children = '', string $attributes = '', ?array $supported = null): AddressData
    {
        return AddressData::of($this->element(sprintf(
            '<address-data %s %s>%s</address-data>',
            self::CARDDAV,
            $attributes,
            $children,
        )), $supported ?? self::SUPPORTED);
    }

    private function element(string $fragment): Element
    {
        return (new XmlReader())->parse($fragment);
    }

    private function card(string $text): Component
    {
        $objects = iterator_to_array((new Reader($text))->objects(), false);
        $first = $objects[0] ?? null;

        self::assertInstanceOf(Component::class, $first);

        return $first;
    }
}
