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

namespace DavServices\CardDav\Report;

use DavServices\CardDav\AddressData;
use DavServices\Dav\INode;
use DavServices\Dav\Property\Answers;
use DavServices\Dav\PropFindForm;
use DavServices\Dav\Server;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use DavServices\Exception\UnsupportedMediaType;
use DavServices\VObject\Component;
use DavServices\Xml\Element;
use DavServices\Xml\MultiStatus;

/**
 * What a report is to return about one card, and the `DAV:response` it becomes.
 *
 * **The two reports of RFC 6352 answer the same question about a card.** §8.7
 * says so itself: the multiget "is similar to the CARDDAV:addressbook-query
 * REPORT (see Section 8.6), except that it takes a list of DAV:href elements
 * instead of a CARDDAV:filter element to determine which address object
 * resources to return." Both read the same optional property group, both may
 * carry a `CARDDAV:address-data`, and both write the same response — so it is
 * read and written in one place rather than in two that could drift apart.
 *
 * ## The one thing that makes this more than a parser
 *
 * §10.4: the `CARDDAV:address-data` element "is specified in requests and
 * responses inside the DAV:prop XML element **as if it were a WebDAV
 * property**. However, the CARDDAV:address-data XML element is not a WebDAV
 * property and as such it is not returned in PROPFIND responses nor used in
 * PROPPATCH requests."
 *
 * So it arrives among the property names and has to be taken out before the
 * property machinery is asked — a server that passed it along would answer the
 * one thing the client came for with `404`. For the same reason a
 * `DAV:allprop` brings no card data with it: it asks for WebDAV properties,
 * and this is not one.
 *
 * ## The response takes the shape the answer has
 *
 * RFC 4918 §14.24 gives a `DAV:response` two shapes — an href with propstats,
 * or an href with a status — and all three cases here are one of them:
 *
 * - properties, card data, or both: one `DAV:propstat` per status;
 * - a client that named neither: the href and a `200`, because an empty
 *   propstat is not a shape the grammar admits;
 * - a card that cannot be converted: the href, `415`, and
 *   `CARDDAV:supported-address-data-conversion`, which is §8.7.2's own answer
 *   and replaces the properties rather than standing beside them.
 */
final class WhatToReturn
{
    /**
     * @param list<string> $names The WebDAV properties named, with
     *                            `CARDDAV:address-data` already taken out
     */
    private function __construct(
        private readonly Server $server,
        private readonly ?PropFindForm $form,
        private readonly array $names,
        private readonly ?AddressData $data,
    ) {
    }

    /**
     * Reads the property group of either report's body.
     *
     * @param list<array{string, string}> $supported What the server says it
     *                                               keeps (§6.2.2), which
     *                                               `CARDDAV:address-data` is
     *                                               held to
     *
     * @throws BadRequest If a `CARDDAV:address-data` is not one §10.4 admits
     * @throws Forbidden If it asks for a media type this server never
     *                   advertised (§8.6, §8.7)
     */
    public static function of(Server $server, Element $asked, array $supported): self
    {
        $named = $asked->child('{DAV:}prop');

        if ($named !== null) {
            return self::named($server, $named, $supported);
        }

        if ($asked->child('{DAV:}propname') !== null) {
            return new self($server, PropFindForm::NamesOnly, [], null);
        }

        if ($asked->child('{DAV:}allprop') !== null) {
            return new self($server, PropFindForm::Everything, [], null);
        }

        // §10.3 and §10.7 make the group optional, and §8.6 has it that a
        // request "can include" the properties — so one that named none wanted
        // none, and answering `allprop` would send card data nobody asked for.
        return new self($server, null, [], null);
    }

    /**
     * Adds this card to the report.
     */
    public function describe(
        MultiStatus $report,
        string $href,
        string $path,
        INode $node,
        Component $card,
    ): void {
        $byStatus = $this->form === null
            ? []
            : Answers::about($this->server->events(), $path, $node, $this->form, $this->names)->byStatus();

        if ($this->data !== null) {
            try {
                $byStatus[200][AddressData::NAME] = $this->data->elementFor($card);
            } catch (UnsupportedMediaType $cannot) {
                $report->addFailure($href, $cannot);

                return;
            }
        }

        if ($byStatus === []) {
            $report->addStatus($href, 200);

            return;
        }

        $report->addProperties($href, $byStatus);
    }

    /**
     * A `DAV:prop`, with the card data told apart from the properties.
     *
     * **A `DAV:prop` holding nothing but the card leaves nothing to ask the
     * property machinery**, and that is why the form goes back to null rather
     * than staying `Named` over an empty list: `Answers` would hand back an
     * empty `DAV:propstat`, which RFC 4918 §14.24 does not admit.
     *
     * @param list<array{string, string}> $supported
     */
    private static function named(Server $server, Element $prop, array $supported): self
    {
        $data = $prop->child(AddressData::NAME);
        $names = [];

        foreach ($prop->childNames() as $name) {
            if ($name !== AddressData::NAME) {
                $names[] = $name;
            }
        }

        return new self(
            $server,
            $names === [] ? null : PropFindForm::Named,
            $names,
            $data === null ? null : AddressData::of($data, $supported),
        );
    }
}
