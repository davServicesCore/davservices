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

use DavServices\CardDav\Report\WhatToReturn;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\Property\LiveProperties;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Exception\Forbidden;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\VObject\Component;
use DavServices\VObject\Reader;
use DavServices\Xml\MultiStatus;
use DavServices\Xml\Writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for what a report returns about one card. R-CARD-03, R-CARD-05.
 *
 * **The two reports of RFC 6352 §8.6 and §8.7 answer the same question about a
 * card**, and the memo says so: the multiget "is similar to the
 * CARDDAV:addressbook-query REPORT […] except that it takes a list of DAV:href
 * elements instead of a CARDDAV:filter element". Both read the same optional
 * property group, both may carry a `CARDDAV:address-data`, and both write the
 * same `DAV:response`. Written twice, the two would sooner or later disagree
 * about which of them was right.
 *
 * The piece that makes this more than a parser is §10.4's note: the
 * `CARDDAV:address-data` element "is not a WebDAV property and as such it is
 * not returned in PROPFIND responses". It arrives inside `DAV:prop` all the
 * same, so it has to be taken out before the property machinery is asked —
 * otherwise the one thing the client really wanted comes back as `404`.
 */
#[CoversClass(WhatToReturn::class)]
final class WhatToReturnTest extends TestCase
{
    private const CARD = '/cards/simon.vcf';

    private const SIMON = "BEGIN:VCARD\r\nVERSION:4.0\r\nUID:simon\r\nFN:Simon Perreault\r\nEND:VCARD\r\n";

    private const SUPPORTED = [['text/vcard', '3.0'], ['text/vcard', '4.0']];

    private const DATA = '<address-data xmlns="urn:ietf:params:xml:ns:carddav"/>';

    /**
     * **The card is answered inside `DAV:prop`, beside the WebDAV properties**,
     * which is where §8.7 puts it: "Address data is returned in the
     * CARDDAV:address-data element inside the DAV:prop element."
     */
    public function testTheCardAndThePropertiesComeBackTogether(): void
    {
        $written = $this->describing('<D:prop xmlns:D="DAV:"><D:getetag/>' . self::DATA . '</D:prop>');

        self::assertStringContainsString('<d:getetag>', $written);
        self::assertStringContainsString('FN:Simon Perreault', $written);
        self::assertStringContainsString('<d:propstat>', $written);

        // The status the card comes back under is part of the answer: a
        // propstat says what happened to the properties in it, and anything
        // but a 200 would be telling the client the card was not returned.
        self::assertStringContainsString('<d:status>HTTP/1.1 200 OK</d:status>', $written);
        self::assertSame(1, substr_count($written, '<d:propstat>'), 'one status for both');
    }

    /**
     * A `DAV:prop` naming properties and no card data is the ordinary
     * `PROPFIND`-shaped request, and it is answered as one: §8.6 asks for "the
     * same behavior as PROPFIND with respect to the DAV:multistatus response
     * elements used to return specific WebDAV property results".
     */
    public function testPropertiesWithoutTheCardAreAnsweredOnTheirOwn(): void
    {
        $written = $this->describing('<D:prop xmlns:D="DAV:"><D:getetag/></D:prop>');

        self::assertStringContainsString('<d:getetag>', $written);
        self::assertStringNotContainsString('BEGIN:VCARD', $written);
    }
    /**
     * **And it is never answered as a missing WebDAV property.** §10.4 is
     * explicit that it is not one, so asking the property machinery about it
     * would hand the client a `404` for the only thing it came for.
     */
    public function testTheCardIsNotReportedAsAPropertyNobodyHas(): void
    {
        $written = $this->describing('<D:prop xmlns:D="DAV:">' . self::DATA . '</D:prop>');

        self::assertStringContainsString('FN:Simon Perreault', $written);
        self::assertStringNotContainsString('404', $written);
    }

    /**
     * A `DAV:prop` holding nothing but the card leaves no WebDAV property to
     * ask about — and an empty `DAV:propstat` is not a shape RFC 4918 §14.24
     * admits, so there is none.
     */
    public function testAPropHoldingOnlyTheCardProducesOnePropstat(): void
    {
        $written = $this->describing('<D:prop xmlns:D="DAV:">' . self::DATA . '</D:prop>');

        self::assertSame(1, substr_count($written, '<d:propstat>'));
    }

    /**
     * RFC 4918 §14.24 gives a response two shapes, and this is the other one:
     * an href with a status. A client that named no properties gets it.
     */
    public function testWithoutAPropertyRequestTheHrefComesBackWithAStatus(): void
    {
        $written = $this->describing('');

        self::assertStringContainsString('HTTP/1.1 200 OK', $written);
        self::assertStringNotContainsString('<d:propstat>', $written);
    }

    public function testPropnameAsksForTheNamesAlone(): void
    {
        $written = $this->describing('<D:propname xmlns:D="DAV:"/>');

        self::assertStringContainsString('<d:getetag/>', $written);
        self::assertStringNotContainsString('BEGIN:VCARD', $written);
    }

    /**
     * §10.4's note again: `DAV:allprop` is about WebDAV properties, and the
     * card is not one of them.
     */
    public function testAllpropBringsThePropertiesAndNotTheCard(): void
    {
        $written = $this->describing('<D:allprop xmlns:D="DAV:"/>');

        self::assertStringContainsString('<d:getetag>', $written);
        self::assertStringNotContainsString('BEGIN:VCARD', $written);
    }

    /**
     * §8.7.2: a card the server cannot convert is answered with a status for
     * that resource rather than with properties — `415` and
     * `CARDDAV:supported-address-data-conversion`.
     */
    public function testACardItCannotConvertBecomesAStatusForThatResource(): void
    {
        $future = "BEGIN:VCARD\r\nVERSION:5.0\r\nUID:x\r\nFN:Future\r\nEND:VCARD\r\n";
        $written = $this->describing(
            '<D:prop xmlns:D="DAV:"><D:getetag/>' . self::DATA . '</D:prop>',
            $future,
        );

        self::assertStringContainsString('415', $written);
        self::assertStringContainsString('supported-address-data-conversion/>', $written);
        self::assertStringNotContainsString('<d:propstat>', $written, 'no properties beside the refusal');
    }

    /**
     * The precondition of §8.6 and §8.7 is about the request, so it is raised
     * while the body is read rather than per card.
     */
    public function testAMediaTypeTheServerNeverAdvertisedIsRefusedWhileReading(): void
    {
        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage('text/directory');

        $this->whatToReturn(
            '<D:prop xmlns:D="DAV:"><address-data xmlns="urn:ietf:params:xml:ns:carddav" '
            . 'content-type="text/directory"/></D:prop>',
        );
    }

    /**
     * The href is handed in rather than worked out, because the two reports
     * answer with different ones: a query names the resource it found, a
     * multiget echoes what the client asked for.
     */
    public function testTheHrefIsTheOneItWasGiven(): void
    {
        $written = $this->describing('', self::SIMON, '/whatever/the/caller/says');

        self::assertStringContainsString('<d:href>/whatever/the/caller/says</d:href>', $written);
    }

    private function describing(string $properties, string $card = self::SIMON, ?string $href = null): string
    {
        $server = $this->server();
        $report = new MultiStatus();

        $this->whatToReturn($properties, $server)->describe(
            $report,
            $href ?? self::CARD,
            self::CARD,
            $server->tree()->node(self::CARD),
            $this->card($card),
        );

        return (new Writer())->write($report->toElement());
    }

    private function whatToReturn(string $properties, ?Server $server = null): WhatToReturn
    {
        $server ??= $this->server();
        $body = sprintf('<report xmlns="urn:ietf:params:xml:ns:carddav">%s</report>', $properties);

        return WhatToReturn::of($server, $server->reader()->parse($body), self::SUPPORTED);
    }

    private function server(): Server
    {
        $cards = (new MemoryCollection('cards'))->add(new MemoryFile('simon.vcf', self::SIMON));
        $root = (new MemoryCollection(''))->add($cards);

        $events = new EventEmitter();
        $events->on(PropertiesRequested::class, (new LiveProperties())(...));

        return new Server(new Tree($root), $events);
    }

    private function card(string $text): Component
    {
        $objects = iterator_to_array((new Reader($text))->objects(), false);
        $first = $objects[0] ?? null;

        self::assertInstanceOf(Component::class, $first);

        return $first;
    }
}
