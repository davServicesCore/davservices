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

namespace DavServices\Acl\Report;

use DavServices\Acl\SearchableProperty;
use DavServices\Dav\Server;
use DavServices\Exception\BadRequest;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Xml\Element;

/**
 * Answers `DAV:principal-search-property-set`: what principals may be
 * searched by (RFC 3744 §9.5).
 *
 * **This is the report a client asks before it offers a search box.** §9.4
 * leaves the search method to the server and says that "servers do not
 * typically support searching on all properties" — and a search over a
 * property this server does not search does not fail, it matches nobody. So
 * guessing is the one thing a client must not have to do, and this is what it
 * asks instead.
 *
 * Given to {@see \DavServices\Dav\Method\Report} like any other:
 *
 *     $report->on(
 *         '{DAV:}principal-search-property-set',
 *         new PrincipalSearchPropertySet($server, SearchableProperty::standard())(...),
 *     );
 *
 * Three things about it are decisions rather than detail.
 *
 * **It answers `200`, not `207`.** Every other report in this family is a
 * multistatus; this one is not, because nothing here is said *about a
 * resource* and so there is no per-resource status to report. A client handed
 * `207` would go looking for a `DAV:response` that is not there.
 *
 * **The order is kept.** §9.5 asks that the most frequently searched come
 * first, so that a client with little room on screen shows the ones people
 * use. That makes the order part of the answer, and the list is written as it
 * was given.
 *
 * **`Depth` is read here.** Only `0` is defined (§9.5), and RFC 3253 §3.6
 * makes a missing header mean `0` — the opposite of `PROPFIND`. This is
 * exactly why the `REPORT` method does not read the header for everybody:
 * each report decides what depth means for it, and they disagree.
 *
 * What is **not** done: `Accept-Language`. §9.5 says a server SHOULD consider
 * it, which needs more than one description per property; here there is one,
 * in the language the application wrote it in. An application serving German
 * users builds the list in German. Choosing between two is a seam to open
 * when somebody brings two, not before.
 */
final class PrincipalSearchPropertySet
{
    private const SET = '{DAV:}principal-search-property-set';

    /**
     * @param list<SearchableProperty> $properties What may be searched, most
     *                                             frequently searched first
     *                                             (§9.5), because the order
     *                                             is what a client shows
     */
    public function __construct(
        private readonly Server $server,
        private readonly array $properties,
    ) {
    }

    /**
     * Lists what may be searched, each with the sentence that explains it.
     *
     * The body that was sent is not read. §9.5 has it be an empty
     * `DAV:principal-search-property-set`, and one that carried something
     * else still asked a question this can answer — RFC 3744 §10 has a server
     * ignore what it does not know rather than refuse it.
     *
     * @throws BadRequest If the request asks for a depth this report has no
     *                    meaning at
     */
    public function __invoke(Request $request): Response
    {
        self::onlyAtDepthZero($request);

        $set = new Element(self::SET);

        foreach ($this->properties as $property) {
            $set->append(self::described($property));
        }

        return (new Response(200, body: $this->server->writer()->write($set)))
            ->withHeader('Content-Type', 'application/xml; charset=utf-8');
    }

    /**
     * §9.5: "This report is only defined when the Depth header has value
     * `0`; other values result in a 400 (Bad Request) error response."
     *
     * @throws BadRequest If some other depth was asked for
     */
    private static function onlyAtDepthZero(Request $request): void
    {
        // RFC 3253 §3.6: a report without the header is at depth 0. That is
        // the other way round from PROPFIND, where a missing Depth means
        // infinity — so the default is written out here rather than shared.
        if (($request->headers()->first('Depth') ?? '0') !== '0') {
            throw new BadRequest('This report says what may be searched here, so it is only defined at Depth: 0.');
        }
    }

    /**
     * One `DAV:principal-search-property`: the property, and what it holds.
     */
    private static function described(SearchableProperty $property): Element
    {
        $element = new Element('{DAV:}principal-search-property');
        $prop = new Element('{DAV:}prop');
        $description = new Element('{DAV:}description', ['xml:lang' => $property->language()]);

        $prop->append(new Element($property->name()));
        $description->appendText($property->description());

        $element->append($prop);
        $element->append($description);

        return $element;
    }
}
