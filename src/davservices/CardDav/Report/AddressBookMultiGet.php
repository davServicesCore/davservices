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
use DavServices\CardDav\AddressObject;
use DavServices\Dav\Server;
use DavServices\Exception\BadRequest;
use DavServices\Exception\DavException;
use DavServices\Exception\Forbidden;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Uri\MalformedPath;
use DavServices\Uri\Path;
use DavServices\Xml\Element;
use DavServices\Xml\MultiStatus;

/**
 * Answers `CARDDAV:addressbook-multiget`: these cards, by name (RFC 6352 §8.7).
 *
 * **This is the other half of how a client stays in step.** It lists an address
 * book with a `PROPFIND`, sees which etags changed, and then asks for exactly
 * those cards — §9.2 and §9.3 describe the dance. Support for it is REQUIRED.
 *
 * Given to {@see \DavServices\Dav\Method\Report} like any other:
 *
 *     $report->on(
 *         '{urn:ietf:params:xml:ns:carddav}addressbook-multiget',
 *         (new AddressBookMultiGet($server))(...),
 *     );
 *
 * The body is §10.7's:
 *
 *     <!ELEMENT addressbook-multiget ((DAV:allprop |
 *                                      DAV:propname |
 *                                      DAV:prop)?,
 *                                      DAV:href+)>
 *
 * ## The hrefs are the scope, and the `Depth` header is not
 *
 * §8.7: "If DAV:href elements are present, the scope of the request is the set
 * of resources identified by these elements, which all need to be members (not
 * necessarily internal members) of the resource identified by the Request-URI."
 * And then: "The request MUST include a Depth: 0 header; however, the actual
 * scope of the REPORT is determined as described above."
 *
 * **So the header is not read.** Three readings of that sentence agree on it:
 * the same sentence says the value decides nothing; RFC 3253 §3.6 already makes
 * a REPORT without the header a `Depth: 0` request, so a missing header *is*
 * what §8.7 asks for; and §8.7.2's own example sends `Depth: 1`, so a server
 * that refused anything else would reject the memo's own example.
 *
 * That is deliberately unlike {@see AddressBookQuery}, where §8.6 has the
 * header determine the scope — and where a missing one is therefore refused.
 * The two differ because the memo does.
 *
 * ## One response per href, whatever happened to it
 *
 * §8.7: the response "MUST contain a DAV:response element for each address
 * object resource referenced by the provided set of DAV:href elements", and "In
 * the case of an error accessing any of the provided DAV:href resources, the
 * server MUST return the appropriate error status code in the DAV:status
 * element of the corresponding DAV:response element."
 *
 * Which status is appropriate is the memo's word rather than its list, so each
 * is chosen and said:
 *
 * - **404** where nothing is there, which is what `404` means;
 * - **403** where something is there but is no address object (§5.1) — `404`
 *   would be untrue, since the resource exists, and `403` says the request was
 *   understood and refused;
 * - **403** where the href lies outside the Request-URI, which is the sentence
 *   above: it is not a member of it;
 * - **400** where the href is no path this library can address at all;
 * - **415** where the card cannot be turned into what was asked for, which is
 *   §8.7.2's own answer, written by {@see WhatToReturn}.
 *
 * **The href a client sent is the href it gets back**, so that it can match
 * responses to requests without canonicalising anything. §8.7 speaks of the
 * resources "referenced by the provided set of DAV:href elements", and the
 * reference is the client's.
 *
 * ## The limit refuses rather than truncates
 *
 * §8.7 wants one response per href, so a short answer would break that MUST —
 * which is why this report refuses a list longer than it will answer instead of
 * cutting it, unlike the query's §8.6.2 truncation. §8.7 names no condition for
 * it ("Postconditions: None"), so the refusal borrows the one WebDAV has for a
 * result set that is too large, `DAV:number-of-matches-within-limits` of
 * RFC 3744 §9.2 — the same one §8.6 names for the same kind of excess.
 */
final class AddressBookMultiGet
{
    private const HREF = '{DAV:}href';

    private const WITHIN_LIMITS = '{DAV:}number-of-matches-within-limits';

    /**
     * @param list<array{string, string}> $supported What the server says it
     *                                               keeps (§6.2.2), which a
     *                                               `CARDDAV:address-data` is
     *                                               held to
     * @param int $atMost How many hrefs will be answered in one request. The
     *                    requirements fix 5.000 as the figure and R-CAL-08
     *                    makes it configurable
     */
    public function __construct(
        private readonly Server $server,
        private readonly array $supported = AddressData::KEPT,
        private readonly int $atMost = 5_000,
    ) {
    }

    /**
     * Reports on each card the body names.
     *
     * @throws BadRequest If the body names no href at all
     * @throws Forbidden If it names more than this server answers at once, or
     *                   asks for a media type it never advertised
     */
    public function __invoke(Request $request, Element $asked): Response
    {
        $hrefs = $this->hrefsIn($asked);
        $returning = WhatToReturn::of($this->server, $asked, $this->supported);

        $path = $this->server->path($request);
        $report = new MultiStatus();

        foreach ($hrefs as $href) {
            $this->answer($report, $returning, $href, $path, $request);
        }

        return (new Response(207, body: $this->server->writer()->write($report->toElement())))
            ->withHeader('Content-Type', 'application/xml; charset=utf-8');
    }

    /**
     * The hrefs the body names.
     *
     * An element that is not an href is left where it is: the property group
     * is one of those, and a child the DTD does not name cannot change which
     * resources are returned — the hrefs decide that. The same reading as
     * {@see AddressBookQuery}'s, for the same reason.
     *
     *
     * @throws BadRequest If there is none
     * @throws Forbidden If there are more than this server answers at once
     *
     * @return list<string>
     */
    private function hrefsIn(Element $asked): array
    {
        $hrefs = [];

        foreach ($asked->children() as $child) {
            if ($child->name() === self::HREF) {
                $hrefs[] = $child->text();
            }
        }

        if ($hrefs === []) {
            throw new BadRequest(
                'A CARDDAV:addressbook-multiget names what it wants: RFC 6352 §8.7 has the body "MUST contain at least one DAV:href XML element".',
            );
        }

        if (count($hrefs) > $this->atMost) {
            throw new Forbidden(
                sprintf(
                    'This server answers at most %d hrefs in one multiget, and this request names %d.',
                    $this->atMost,
                    count($hrefs),
                ),
                self::WITHIN_LIMITS,
            );
        }

        return $hrefs;
    }

    /**
     * Adds the one response this href is owed.
     */
    private function answer(
        MultiStatus $report,
        WhatToReturn $returning,
        string $href,
        string $path,
        Request $request,
    ): void {
        try {
            $where = $this->server->pathOfUrl($href, $request);
        } catch (DavException $refused) {
            // Not a URL, or on another server: §8.7's "error accessing" case,
            // with the status the refusal already carries.
            $report->addFailure($href, $refused);

            return;
        } catch (MalformedPath $malformed) {
            $report->addStatus($href, 400, null, $malformed->getMessage());

            return;
        }

        if (!Path::isBelow($where, $path)) {
            $report->addStatus(
                $href,
                403,
                null,
                'RFC 6352 §8.7: the hrefs of a multiget are members of the Request-URI.',
            );

            return;
        }

        try {
            $node = $this->server->tree()->node($where);
        } catch (DavException $missing) {
            $report->addFailure($href, $missing);

            return;
        }

        $card = AddressObject::of($node);

        if ($card === null) {
            $report->addStatus(
                $href,
                403,
                null,
                'RFC 6352 §5.1: an address object holds a single vCard component only, and this is not one.',
            );

            return;
        }

        $returning->describe($report, $href, $where, $node, $card);
    }
}
