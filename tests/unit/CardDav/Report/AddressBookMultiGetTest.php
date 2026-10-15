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

namespace DavServices\Tests\Unit\CardDav\Report;

use DavServices\CardDav\AddressBook;
use DavServices\CardDav\Report\AddressBookMultiGet;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\Property\LiveProperties;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for `CARDDAV:addressbook-multiget`. R-CARD-04, R-CARD-05, P5-04.
 *
 * RFC 6352 §8.7 and §10.7:
 *
 *     <!ELEMENT addressbook-multiget ((DAV:allprop |
 *                                      DAV:propname |
 *                                      DAV:prop)?,
 *                                      DAV:href+)>
 *
 * "This report is similar to the CARDDAV:addressbook-query REPORT […] except
 * that it takes a list of DAV:href elements instead of a CARDDAV:filter element
 * to determine which address object resources to return." Support for it is
 * REQUIRED.
 */
#[CoversClass(AddressBookMultiGet::class)]
final class AddressBookMultiGetTest extends TestCase
{
    private const BOOK = '/addressbooks/default';

    private const SIMON = "BEGIN:VCARD\r\nVERSION:4.0\r\nUID:simon\r\nFN:Simon Perreault\r\n"
        . "TEL;TYPE=work:+1 418 656 9254\r\nEND:VCARD\r\n";

    private const TIM = "BEGIN:VCARD\r\nVERSION:4.0\r\nUID:tim\r\nFN:Tim Howes\r\nEND:VCARD\r\n";

    private const ETAG_AND_DATA = '<D:prop xmlns:D="DAV:"><D:getetag/>'
        . '<address-data xmlns="urn:ietf:params:xml:ns:carddav"/></D:prop>';

    public function testAnswersTwoOhSevenWithAMultistatus(): void
    {
        $response = $this->get(['simon.vcf']);

        self::assertSame(207, $response->status());
        self::assertSame('application/xml; charset=utf-8', $response->headers()->first('Content-Type'));
        self::assertStringContainsString('<d:multistatus', (string) $response->body());
    }

    /**
     * §8.7: "The response body […] MUST contain a DAV:response element for
     * each address object resource referenced by the provided set of DAV:href
     * elements."
     */
    public function testOneResponsePerHref(): void
    {
        $body = (string) $this->get(['simon.vcf', 'tim.vcf'])->body();

        self::assertSame(2, substr_count($body, '<d:response>'));
        self::assertStringContainsString('/addressbooks/default/simon.vcf', $body);
        self::assertStringContainsString('/addressbooks/default/tim.vcf', $body);
    }

    /**
     * The order a client wrote is the order it gets back. The memo does not
     * ask for it, and keeping it costs nothing — a client that sent its hrefs
     * in a sensible order has one less thing to sort out.
     */
    public function testTheOrderTheClientAskedInIsKept(): void
    {
        $body = (string) $this->get(['tim.vcf', 'simon.vcf'])->body();

        self::assertLessThan(strpos($body, 'simon.vcf'), strpos($body, 'tim.vcf'));
    }

    /**
     * §8.7: "Address data is returned in the CARDDAV:address-data element
     * inside the DAV:prop element."
     */
    public function testTheCardComesBackInTheAddressDataElement(): void
    {
        $body = (string) $this->get(['simon.vcf'], self::ETAG_AND_DATA)->body();

        self::assertStringContainsString('<d:getetag>', $body);
        self::assertStringContainsString('BEGIN:VCARD', $body);
        self::assertStringContainsString('FN:Simon Perreault', $body);
    }

    /**
     * **§10.4's note, as a test:** the element "is not a WebDAV property and as
     * such it is not returned in PROPFIND responses nor used in PROPPATCH
     * requests". So `DAV:allprop` asks for the WebDAV properties and brings no
     * card data with it — a client that wants the card says so.
     */
    public function testAllpropDoesNotBringTheCardAlong(): void
    {
        $body = (string) $this->get(['simon.vcf'], '<D:allprop xmlns:D="DAV:"/>')->body();

        self::assertStringContainsString('<d:getetag>', $body);
        self::assertStringNotContainsString('BEGIN:VCARD', $body);
    }

    public function testAPropertyNobodyHasComesBackAsFourOhFour(): void
    {
        $body = (string) $this->get(['simon.vcf'], '<D:prop xmlns:D="DAV:"><D:displayname/></D:prop>')->body();

        self::assertStringContainsString('404', $body);
    }

    /**
     * The same reading as the query's: §10.7 makes the property group
     * optional, so a client that named none wanted none, and what is left is
     * RFC 4918 §14.24's other shape — an href with a status.
     */
    public function testWithoutAPropertyRequestTheHrefComesBackWithAStatus(): void
    {
        $body = (string) $this->get(['simon.vcf'])->body();

        self::assertStringContainsString('HTTP/1.1 200 OK', $body);
        self::assertStringNotContainsString('<d:propstat>', $body);
    }

    /**
     * §8.7 makes the list the point of the report: the body "MUST contain at
     * least one DAV:href XML element", and §10.7 spells it `DAV:href+`. A
     * multiget that names nothing is not a request for everything.
     */
    public function testRefusesABodyWithoutAnHref(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('href');

        $this->get([]);
    }

    /**
     * §8.7: "In the case of an error accessing any of the provided DAV:href
     * resources, the server MUST return the appropriate error status code in
     * the DAV:status element of the corresponding DAV:response element." So
     * one href that is not there does not spoil the rest.
     */
    public function testAnHrefThatIsNotThereIsFourOhFourAndTheRestIsAnswered(): void
    {
        $body = (string) $this->get(['nobody.vcf', 'simon.vcf'])->body();

        self::assertSame(2, substr_count($body, '<d:response>'));
        self::assertStringContainsString('404', $body);
        self::assertStringContainsString('HTTP/1.1 200 OK', $body);
    }

    /**
     * **Something that is there but is no address object is refused rather
     * than reported missing.** §5.1 makes an address object "a single vCard
     * component only", and this report retrieves address objects — so `403`,
     * which says the request was understood and refused, with a description
     * saying why. `404` would be untrue: the resource is there.
     */
    public function testSomethingThatIsNoAddressObjectIsRefused(): void
    {
        $body = (string) $this->get(['photos', 'broken.vcf'])->body();

        self::assertSame(2, substr_count($body, '<d:response>'));
        self::assertSame(2, substr_count($body, '403'));
    }

    /**
     * §8.7: the hrefs "all need to be members (not necessarily internal
     * members) of the resource identified by the Request-URI". One that is not
     * is outside what this request may reach, which is a refusal rather than a
     * silent omission — §8.7 wants a response per href either way.
     */
    public function testAnHrefOutsideTheRequestUriIsRefused(): void
    {
        $body = (string) $this->get(['/addressbooks/other/sideways.vcf'])->body();

        self::assertSame(1, substr_count($body, '<d:response>'));
        self::assertStringContainsString('403', $body);
    }

    /**
     * "not necessarily internal members" is the phrase that allows this: an
     * href further down than the collection's own members is still in scope.
     */
    public function testAnHrefFurtherDownIsStillInScope(): void
    {
        $body = (string) $this->get([self::BOOK . '/photos/buried.vcf'], self::ETAG_AND_DATA, '/addressbooks')->body();

        self::assertStringContainsString('FN:Tim Howes', $body);
    }

    /**
     * §8.7's first sentence: the report retrieves "a specific address object
     * resource, if the Request-URI is an address object resource".
     */
    public function testTheRequestUriMayBeTheCardItself(): void
    {
        $body = (string) $this->get(
            ['/addressbooks/default/simon.vcf'],
            self::ETAG_AND_DATA,
            '/addressbooks/default/simon.vcf',
        )->body();

        self::assertStringContainsString('FN:Simon Perreault', $body);
    }

    /**
     * **An href that is no path this library can address is `400` for that
     * href**, not a refusal of the whole request: §8.7 wants a response per
     * href, and one bad href is the client's mistake about one resource.
     *
     * `%2e%2e` is the shape that matters — a percent-escape that would become
     * a dot segment if it were decoded before the path was cut into segments.
     * {@see \DavServices\Uri\Path} cuts first and decodes once, so it arrives
     * here as an ordinary name that may not be addressed.
     */
    public function testAnHrefThatIsNoAddressablePathIsFourHundred(): void
    {
        $body = (string) $this->get([self::BOOK . '/%2e%2e'])->body();

        self::assertSame(1, substr_count($body, '<d:response>'));
        self::assertStringContainsString('400', $body);
    }

    /**
     * An href on another server is not this server's to answer. `pathOfUrl`
     * says so with a `404`, and §8.7's "error accessing" case carries it.
     */
    public function testAnHrefOnAnotherServerIsNotAnswered(): void
    {
        $body = (string) $this->get(['http://elsewhere.example.com/cards/x.vcf'])->body();

        self::assertSame(1, substr_count($body, '<d:response>'));
        self::assertStringContainsString('404', $body);
    }
    /**
     * **The `Depth` header is not read, and that is a decision at the
     * wording.** §8.7 says "The request MUST include a Depth: 0 header;
     * however, the actual scope of the REPORT is determined as described
     * above" — the hrefs determine it. RFC 3253 §3.6 already makes a REPORT
     * without the header a `Depth: 0` request, so a missing header *is* what
     * §8.7 asks for; and §8.7.2's own example sends `Depth: 1`, so refusing
     * that would reject the memo's own example.
     *
     * This is deliberately unlike the query, where §8.6 has the header
     * determine the scope and a missing one is therefore refused.
     */
    public function testTheDepthHeaderChangesNothing(): void
    {
        foreach ([null, '0', '1', 'infinity'] as $depth) {
            $body = (string) $this->get(['simon.vcf'], self::ETAG_AND_DATA, self::BOOK, $depth)->body();

            self::assertStringContainsString('FN:Simon Perreault', $body, var_export($depth, true));
        }
    }

    /**
     * **The limit refuses the request rather than truncating it**, and §8.7 is
     * why: the response "MUST contain a DAV:response element for each address
     * object resource referenced by the provided set of DAV:href elements", so
     * a short answer would break a MUST. §8.7 names no condition of its own —
     * "Postconditions: None" — so the refusal borrows the one WebDAV has for
     * a result set that is too large, `DAV:number-of-matches-within-limits`
     * (RFC 3744 §9.2), which §8.6 names for the same kind of excess.
     *
     * R-CAL-09 asks for exactly this shape: a limit reached is a refusal with
     * the matching precondition, never an abort.
     */
    public function testMoreHrefsThanItWillAnswerIsRefusedByName(): void
    {
        try {
            $this->get(['simon.vcf', 'tim.vcf'], '', self::BOOK, '0', 1);
        } catch (Forbidden $refusal) {
            self::assertSame('{DAV:}number-of-matches-within-limits', $refusal->errorElement());
            self::assertStringContainsString('1', $refusal->getMessage());

            return;
        }

        self::fail('A multiget larger than the limit was answered.');
    }

    public function testExactlyTheLimitIsStillAnswered(): void
    {
        $body = (string) $this->get(['simon.vcf', 'tim.vcf'], '', self::BOOK, '0', 2)->body();

        self::assertSame(2, substr_count($body, '<d:response>'));
    }

    /**
     * **§8.7.2's example, as a test.** A card that "exists but in a media type
     * format that the server is unable to convert" is answered with `415` and
     * `CARDDAV:supported-address-data-conversion` in its own `DAV:response`,
     * while the other hrefs are answered as usual.
     */
    public function testACardItCannotConvertIsFourOneFiveAndTheRestIsAnswered(): void
    {
        $body = (string) $this->get(['future.vcf', 'simon.vcf'], self::ETAG_AND_DATA)->body();

        self::assertSame(2, substr_count($body, '<d:response>'));
        self::assertStringContainsString('415', $body);
        self::assertStringContainsString('supported-address-data-conversion/>', $body);
        self::assertStringContainsString('FN:Simon Perreault', $body, 'the card that could be had');
    }

    /**
     * §8.7's one precondition: "(CARDDAV:supported-address-data): The
     * attributes 'content-type' and 'version' […] specify a media type
     * supported by the server for address object resources." A precondition is
     * a reason not to run the report, so it is answered before any href is
     * looked at.
     */
    public function testAMediaTypeTheServerNeverAdvertisedRefusesTheWholeRequest(): void
    {
        $asked = '<D:prop xmlns:D="DAV:"><address-data xmlns="urn:ietf:params:xml:ns:carddav" '
            . 'content-type="application/vcard+json"/></D:prop>';

        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage('application/vcard+json');

        $this->get(['simon.vcf'], $asked);
    }

    /**
     * @param list<string> $hrefs Each one a path, taken as it stands where it
     *                            starts with a slash and under the Request-URI
     *                            otherwise
     */
    private function get(
        array $hrefs,
        string $properties = '',
        string $path = self::BOOK,
        ?string $depth = '0',
        ?int $atMost = null,
    ): Response {
        $written = '';

        foreach ($hrefs as $href) {
            $written .= sprintf(
                '<D:href xmlns:D="DAV:">%s</D:href>',
                self::written($href, $path),
            );
        }

        $body = sprintf(
            '<addressbook-multiget xmlns="urn:ietf:params:xml:ns:carddav">%s%s</addressbook-multiget>',
            $properties,
            $written,
        );

        $server = $this->server();
        $report = $atMost === null
            ? new AddressBookMultiGet($server)
            : new AddressBookMultiGet($server, atMost: $atMost);

        $request = new Request(
            'REPORT',
            $path,
            headers: new Headers($depth === null ? [] : ['Depth' => $depth]),
            body: new Body($body),
        );

        return $report($request, $server->reader()->parse($body));
    }

    /**
     * An href as the client would write it: a whole URL or a path taken as it
     * stands, and anything else as a member of the Request-URI.
     *
     * The URL case is its own: `//` in the middle of a path is not what a
     * client means by `http://elsewhere.example.com/`, and reading it as one
     * put a case meant for another server down an entirely different road.
     */
    private static function written(string $href, string $path): string
    {
        if (str_contains($href, '://') || str_starts_with($href, '/')) {
            return $href;
        }

        return $path . '/' . $href;
    }
    /**
     * A home with two address books, one of which holds a card, something that
     * is no card, and a folder with a card below it.
     */
    private function server(): Server
    {
        $root = new MemoryCollection('');
        $home = new MemoryCollection('addressbooks');
        $book = new MemoryCollection('default');
        $other = new MemoryCollection('other');

        $book->add(new MemoryFile('simon.vcf', self::SIMON));
        $book->add(new MemoryFile('tim.vcf', self::TIM));
        $book->add(new MemoryFile('broken.vcf', 'This is a note, not a card.'));
        $book->add(new MemoryFile('future.vcf', "BEGIN:VCARD\r\nVERSION:5.0\r\nUID:x\r\nFN:Future\r\nEND:VCARD\r\n"));
        $book->add((new MemoryCollection('photos'))->add(new MemoryFile('buried.vcf', self::TIM)));
        $other->add(new MemoryFile('sideways.vcf', self::TIM));

        $home->add(AddressBook::of($book));
        $home->add(AddressBook::of($other));
        $root->add($home);

        $events = new EventEmitter();
        $events->on(PropertiesRequested::class, (new LiveProperties())(...));

        return new Server(new Tree($root), $events);
    }
}
