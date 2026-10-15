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
use DavServices\CardDav\Filter\Filter;
use DavServices\Dav\ICollection;
use DavServices\Dav\INode;
use DavServices\Dav\Property\Answers;
use DavServices\Dav\Server;
use DavServices\Dav\VisibleMembers;
use DavServices\Exception\BadRequest;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\VObject\Component;
use DavServices\Xml\Element;
use DavServices\Xml\MultiStatus;
use Generator;

/**
 * Answers `CARDDAV:addressbook-query`: which cards are these (RFC 6352 §8.6).
 *
 * **This is how a client searches an address book.** It is what happens when
 * somebody types three letters into the recipient field of a mail client, and
 * §8.6 makes support for it REQUIRED.
 *
 * Given to {@see \DavServices\Dav\Method\Report} like any other:
 *
 *     $report->on(
 *         '{urn:ietf:params:xml:ns:carddav}addressbook-query',
 *         (new AddressBookQuery($server))(...),
 *     );
 *
 * The body is §10.3's:
 *
 *     <!ELEMENT addressbook-query ((DAV:allprop |
 *                                   DAV:propname |
 *                                   DAV:prop)?, filter, limit?)>
 *
 * ## The scope is the `Depth` header, and it has to be there
 *
 * §8.6: "The request MUST include a Depth header. The scope of the query is
 * determined by the value of the Depth header." RFC 3253 §3.6 would otherwise
 * read a missing header as `Depth: 0` — and **that default is deliberately not
 * applied here**, because at depth zero an address book holds no address
 * object of its own: a client that forgot the header would be told its search
 * found nothing. The memo raised the header to a MUST for that reason, so a
 * request without one is refused rather than answered emptily.
 *
 * ## What counts as something to search
 *
 * §5.1: "Address object resources contained in address book collections MUST
 * contain a single vCard component only." A member that is not one — text that
 * is no vCard, a file holding two of them or none at all, a calendar that
 * wandered in, something that is no file to begin with — is therefore not an
 * address object, and §8.6 has the response carry "a
 * DAV:response element for each address object that matched the search
 * filter". So it is passed over. The alternative would be to fail a client's
 * whole search over data it cannot see and could not correct.
 *
 * ## Why the properties are not fetched from a backend
 *
 * They are assembled the way a `PROPFIND` assembles them
 * ({@see Answers}), which is what §8.6 asks for: "the response should follow
 * the same behavior as PROPFIND with respect to the DAV:multistatus response
 * elements used to return specific WebDAV property results." That is also what
 * makes a property nobody has come back as `404` rather than as nothing, which
 * the same paragraph makes a MUST.
 *
 * **The members are walked through {@see VisibleMembers}**, so a card
 * concealed from a client is not found by searching for it either — a listing
 * that named the thing has already told the client it exists.
 *
 * ## The three preconditions of §8.6
 *
 * **`CARDDAV:supported-collation`** is answered where the attribute is read,
 * in {@see \DavServices\CardDav\Filter\TextMatch}, and it is answered before
 * any card is fetched — a precondition is a reason not to run the report.
 *
 * **`CARDDAV:supported-filter`** cannot be violated here. It is for a server
 * whose queries only reach the properties it has indexed: "if the
 * CARDDAV:filter element attempts to reference an unsupported vCard property or
 * parameter, this precondition is violated". This one reads the card and asks
 * it, so every property and every parameter is queryable, and there is no
 * subset to report.
 *
 * **`CARDDAV:supported-address-data`** is answered where the attributes are
 * read, in {@see \DavServices\CardDav\AddressData}, and like the collation it
 * is answered before any card is fetched.
 *
 * ## What the response carries
 *
 * Whatever the property group asked for, written by {@see WhatToReturn} —
 * which is also what answers `CARDDAV:address-data`, the element §8.6 wants
 * the card data in. The same piece serves {@see AddressBookMultiGet}, because
 * §8.7 has that report answer the same question about a card.
 *
 * ## What is not here yet
 *
 * The filter is handed down to no backend. {@see \DavServices\Dav\IFilter}
 * exists for that and {@see Filter} is already shaped for it, but narrowing a
 * collection by name and then fetching what is left would be a second way into
 * a collection — the one without {@see VisibleMembers} on it — so it waits for
 * the chunk that has a backend able to filter (R-CAL-16) and can give
 * concealment one place to live.
 */
final class AddressBookQuery
{
    /** What `Depth: infinity` becomes: deeper than any tree a backend holds. */
    private const ENDLESS = PHP_INT_MAX;

    private const LIMIT = '{urn:ietf:params:xml:ns:carddav}limit';

    private const NRESULTS = '{urn:ietf:params:xml:ns:carddav}nresults';

    private const WITHIN_LIMITS = '{DAV:}number-of-matches-within-limits';

    /**
     * @param list<array{string, string}> $supported What the server says it
     *                                               keeps (§6.2.2), which a
     *                                               `CARDDAV:address-data` is
     *                                               held to
     * @param int $atMost How many matches will be reported before the result
     *                    set is cut short (§8.6.2). An address book of ten
     *                    thousand cards answers a search for "a" with all of
     *                    them unless somebody says otherwise
     */
    public function __construct(
        private readonly Server $server,
        private readonly array $supported = AddressData::KEPT,
        private readonly int $atMost = 250,
    ) {
    }

    /**
     * Reports on the cards the filter matches.
     *
     * @throws BadRequest If the `Depth` header is missing or is not one this
     *                    report is defined at, or the body is not one §10.3
     *                    admits
     */
    public function __invoke(Request $request, Element $asked): Response
    {
        $depth = self::depthOf($request);
        $filter = Filter::of($asked->child(Filter::NAME) ?? throw new BadRequest('A CARDDAV:addressbook-query carries a CARDDAV:filter: RFC 6352 §10.3 has it outside the optional group, so there is no query without one.'));
        $returning = WhatToReturn::of($this->server, $asked, $this->supported);
        $ceiling = min($this->atMost, self::limitOf($asked) ?? PHP_INT_MAX);

        $path = $this->server->path($request);
        $node = $this->server->tree()->node($path);

        $report = new MultiStatus();
        $reported = 0;
        $truncated = false;

        foreach ($this->matchesUnder($path, $node, $depth, $filter) as $where => [$found, $card]) {
            if ($reported === $ceiling) {
                $truncated = true;

                break;
            }

            $returning->describe($report, $this->server->hrefOf($where, $found), $where, $found, $card);
            ++$reported;
        }

        if ($truncated) {
            $this->sayItWasCutShort($report, $path, $node);
        }

        return (new Response(207, body: $this->server->writer()->write($report->toElement())))
            ->withHeader('Content-Type', 'application/xml; charset=utf-8');
    }

    /**
     * **§8.6.2: a result set cut short is a `507` inside the `207`.**
     *
     * > If the result set is truncated because of such a limit, the response
     * > MUST use status code 207 (Multi-Status), return a DAV:multistatus
     * > response body, and indicate a status of 507 (Insufficient Storage) for
     * > the Request-URI. That DAV:response element SHOULD include a DAV:error
     * > element with the DAV:number-of-matches-within-limits precondition.
     *
     * So the partial results stand, and one more `DAV:response` says that
     * there were more. It is the Request-URI that carries it, not the card
     * that was left out — the client asked about the collection.
     *
     * **The `507` does not count towards the client's limit**, which the same
     * section makes a MUST: "if the client requests that only a single result
     * be returned, and multiple matches are present, then the DAV:multistatus
     * response will include one DAV:response for the matching resource and one
     * DAV:response for the 507 status on the Request-URI." Here that follows
     * from where it is added: the ceiling counts matches, and this is not one.
     *
     * Unlike `DAV:principal-property-search`, which refuses outright with the
     * same precondition, this report truncates — because RFC 3744 §9.4 has no
     * section of its own on truncation and §8.6.2 does.
     */
    private function sayItWasCutShort(MultiStatus $report, string $path, INode $node): void
    {
        $report->addStatus($this->server->hrefOf($path, $node), 507, self::WITHIN_LIMITS);
    }

    /**
     * The address objects below this path that the filter matches, by path.
     *
     * The walk is lazy so that the caller can stop at its ceiling: §8.6.2 lets
     * a server limit a response "to limit the amount of work expended in
     * processing a query", and work that is never done is the cheapest kind of
     * limit.
     *
     * Each one comes with the card that was read to decide it matched, so
     * that the response does not read the body a second time.
     *
     * @return Generator<string, array{INode, Component}>
     */
    private function matchesUnder(string $path, INode $node, int $depth, Filter $filter): Generator
    {
        if (!$node instanceof ICollection) {
            $card = AddressObject::of($node);

            if ($card !== null && $filter->holds($card)) {
                yield $path => [$node, $card];
            }

            return;
        }

        // A collection is no address object (§5.1), so at the end of the
        // depth there is nothing here to report — not even for a filter that
        // matches everything.
        if ($depth === 0) {
            return;
        }

        foreach (VisibleMembers::of($this->server->events(), $path, $node) as $member => $child) {
            yield from $this->matchesUnder($member, $child, $depth - 1, $filter);
        }
    }

    /**
     * How far down the query reaches.
     *
     * @throws BadRequest If the header is missing, or names a depth this
     *                    report is not defined at
     */
    private static function depthOf(Request $request): int
    {
        $header = $request->headers()->first('Depth');

        if ($header === null) {
            throw new BadRequest('An addressbook-query says how far it reaches: RFC 6352 §8.6 has it that "the request MUST include a Depth header".');
        }

        $depth = strtolower($header);

        if ($depth === '0' || $depth === '1') {
            return (int) $depth;
        }

        if ($depth === 'infinity') {
            return self::ENDLESS;
        }

        throw new BadRequest(sprintf('An addressbook-query goes 0, 1 or infinity deep, not "%s".', $header));
    }

    /**
     * How many results the client will take, or null where it did not say.
     *
     * §10.6: `<!ELEMENT limit (nresults)>`, and §10.6.1 makes the value "an
     * unsigned integer", with the DTD saying it again — "nresults value:
     * unsigned integer, must be digits". A sign or a decimal point is
     * therefore not a limit, and "The server MAY disregard this limit" is
     * permission to ignore one it was given rather than to invent one from
     * something it cannot read.
     *
     * Room around the digits is not part of them: a body that was written out
     * with indentation means the same number as one that was not, and it is
     * the sign and the point the DTD rules out.
     *
     * @throws BadRequest If the limit names no kind of limit, or the number is
     *                    no unsigned integer
     */
    private static function limitOf(Element $asked): ?int
    {
        $limit = $asked->child(self::LIMIT);

        if ($limit === null) {
            return null;
        }

        $results = $limit->child(self::NRESULTS) ?? throw new BadRequest(
            'A CARDDAV:limit says what it limits: RFC 6352 §10.6 spells it (nresults), which is not optional.',
        );
        $digits = trim($results->text());

        if (preg_match('/^\d+$/', $digits) !== 1) {
            throw new BadRequest(sprintf(
                'A CARDDAV:nresults is an unsigned integer and "must be digits" (RFC 6352 §10.6.1), not "%s".',
                $results->text(),
            ));
        }

        return (int) $digits;
    }
}
