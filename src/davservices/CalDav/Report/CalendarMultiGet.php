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

namespace DavServices\CalDav\Report;

use DavServices\CalDav\CalendarObject;
use DavServices\CalDav\Limits;
use DavServices\CalDav\Moments;
use DavServices\Dav\ICollection;
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
 * Answers `CALDAV:calendar-multiget`: these objects, by name (RFC 4791 §7.9).
 *
 * **This is the other half of how a client stays in step.** It lists a
 * calendar with a `PROPFIND`, sees which etags changed, and then asks for
 * exactly those objects. Support for it is REQUIRED.
 *
 * Given to {@see \DavServices\Dav\Method\Report} like any other, which
 * {@see \DavServices\Plugin\CalDav} does for every server it is registered on:
 *
 *     $report->on(
 *         '{urn:ietf:params:xml:ns:caldav}calendar-multiget',
 *         (new CalendarMultiGet($server))(...),
 *     );
 *
 * The body is §9.10's:
 *
 *     <!ELEMENT calendar-multiget ((DAV:allprop |
 *                                   DAV:propname |
 *                                   DAV:prop)?, DAV:href+)>
 *
 * ## The hrefs are the scope, and the `Depth` header is not
 *
 * §7.9 says both halves in one breath: "If the Request-URI is a collection
 * resource, then the DAV:href elements MUST refer to calendar object resources
 * within that collection, and they MAY refer to calendar object resources at
 * any depth within the collection. **As a result, the 'Depth' header MUST be
 * ignored by the server** and SHOULD NOT be sent by the client."
 *
 * So the header is not read at all — which is deliberately unlike
 * {@see CalendarQuery}, where §7.8 has it set the scope and a missing one
 * means `Depth: 0`. The two differ because the memo does.
 *
 * **And where the Request-URI is no collection**, §7.9 narrows the body
 * instead: "then there MUST be a single DAV:href element that is equivalent to
 * the Request-URI". A second href against one object is therefore a body this
 * server cannot read as the memo asks, and it is refused whole rather than
 * answered in part.
 *
 * ## One response per href, whatever happened to it
 *
 * §7.9: the response "MUST contain a DAV:response element for each calendar
 * object resource referenced by the provided set of DAV:href elements", and
 * "In the case of an error accessing any of the provided DAV:href resources,
 * the server MUST return the appropriate error status code in the DAV:status
 * element of the corresponding DAV:response element."
 *
 * Which status is appropriate is the memo's word rather than its list, so each
 * is chosen and said:
 *
 * - **404** where nothing is there, which is what `404` means — and what
 *   §7.9.1's own example shows, one of its two hrefs naming a resource that
 *   "does not exist, resulting in an error status response";
 * - **403** where something is there but is no calendar object resource
 *   (§4.1) — `404` would be untrue, since the resource exists, and `403` says
 *   the request was understood and refused;
 * - **403** where the href lies outside the Request-URI, which is the sentence
 *   above: it is not within that collection;
 * - **400** where the href is no path this library can address at all.
 *
 * **The href a client sent is the href it gets back**, so that it can match
 * responses to requests without canonicalising anything. §7.9 speaks of the
 * resources "referenced by the provided set of DAV:href elements", and the
 * reference is the client's.
 *
 * ## The preconditions of §7.9, each where it is read
 *
 * **`CALDAV:supported-calendar-data`** is answered in
 * {@see \DavServices\CalDav\CalendarData} while the body is read, as are the
 * narrowings of §9.6 that a `CALDAV:calendar-data` may carry.
 *
 * **`CALDAV:min-date-time` and `CALDAV:max-date-time`** are asked of "any XML
 * element specifying a range of time", which
 * {@see Limits::mustTakeTheRangesIn()} reads as the elements RFC 4791 writes
 * one with. A multiget carries no filter, so the ranges it can name are the
 * three of §9.6 — an `expand` and the two limits.
 *
 * Both are raised before an object is fetched, because a precondition is a
 * reason not to run the report.
 *
 * **§7.3's zone is the collection's**, there being no `CALDAV:timezone` in
 * §9.10's body to carry one: {@see FloatingZone::ofTheCollections()}.
 *
 * ## The limit refuses rather than truncates
 *
 * §7.9 wants one response per href, so a short answer would break that MUST —
 * which is why a list longer than this server answers is refused rather than
 * cut, unlike the query's own limit. §7.9 names no condition for it
 * ("Postconditions: None"), so the refusal borrows the one WebDAV has for a
 * result set that is too large, `DAV:number-of-matches-within-limits` of
 * RFC 3744 §9.2 — the same one §7.8 names for the same kind of excess.
 */
final class CalendarMultiGet
{
    private const HREF = '{DAV:}href';

    private const WITHIN_LIMITS = '{DAV:}number-of-matches-within-limits';

    /**
     * @param Limits $limits What the targeted collection keeps (§5.2.6,
     *                       §5.2.7), which every range in the body is held to
     * @param int $atMost How many hrefs will be answered in one request. The
     *                    requirements fix 5.000 as the figure and R-CAL-08
     *                    makes it configurable
     */
    public function __construct(
        private readonly Server $server,
        private readonly Limits $limits = new Limits(),
        private readonly int $atMost = 5_000,
    ) {
    }

    /**
     * Reports on each object the body names.
     *
     * @throws BadRequest If the body names no href at all, or names more than
     *                    one against a resource that is no collection
     * @throws Forbidden If it names more hrefs than this server answers at
     *                   once, or a precondition of §7.9 is violated
     */
    public function __invoke(Request $request, Element $asked): Response
    {
        $hrefs = $this->hrefsIn($asked);

        $this->limits->mustTakeTheRangesIn($asked);

        $returning = WhatToReturn::of($this->server, $asked);
        $zone = FloatingZone::ofTheCollections($this->server);

        $path = $this->server->path($request);

        $this->mustBeOneHrefForOneObject($hrefs, $path);

        $report = new MultiStatus();

        foreach ($hrefs as $href) {
            $this->answer($report, $returning, $zone, $href, $path, $request);
        }

        return (new Response(207, body: $this->server->writer()->write($report->toElement())))
            ->withHeader('Content-Type', 'application/xml; charset=utf-8');
    }

    /**
     * The hrefs the body names.
     *
     * An element that is not an href is left where it is: the property group
     * is one of those, and a child the DTD does not name cannot change which
     * resources are returned — the hrefs decide that.
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
                'A CALDAV:calendar-multiget names what it wants: RFC 4791 §9.10 spells it DAV:href+, which is one or more.',
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
     * §7.9: "If the Request-URI refers to a non-collection resource, then
     * there MUST be a single DAV:href element that is equivalent to the
     * Request-URI."
     *
     * Whether the one href is that resource is answered with every other
     * href — it is a question about where the href points, and
     * {@see answer()} asks it of each.
     *
     * @param list<string> $hrefs
     *
     * @throws BadRequest If there are several against one object
     */
    private function mustBeOneHrefForOneObject(array $hrefs, string $path): void
    {
        if (count($hrefs) === 1 || $this->server->tree()->node($path) instanceof ICollection) {
            return;
        }

        throw new BadRequest(sprintf(
            'A multiget aimed at one calendar object resource names a single DAV:href that is equivalent to it (RFC 4791 §7.9), and this one names %d.',
            count($hrefs),
        ));
    }

    /**
     * Adds the one response this href is owed.
     */
    private function answer(
        MultiStatus $report,
        WhatToReturn $returning,
        FloatingZone $zone,
        string $href,
        string $path,
        Request $request,
    ): void {
        try {
            $where = $this->server->pathOfUrl($href, $request);
        } catch (DavException $refused) {
            // Not a URL, or on another server: §7.9's "error accessing" case,
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
                'RFC 4791 §7.9: the hrefs of a multiget refer to calendar object resources within the Request-URI.',
            );

            return;
        }

        try {
            $node = $this->server->tree()->node($where);
        } catch (DavException $missing) {
            $report->addFailure($href, $missing);

            return;
        }

        $object = CalendarObject::of($node);

        if ($object === null) {
            $report->addStatus(
                $href,
                403,
                null,
                'RFC 4791 §4.1: a calendar object resource contains calendar components, and this is not one.',
            );

            return;
        }

        $returning->describe(
            $report,
            $href,
            $where,
            $node,
            $object,
            new Moments($object, $zone->forTheObjectAt($where, $node)),
        );
    }
}
