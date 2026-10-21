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

use DavServices\CalDav\Calendar;
use DavServices\CalDav\CalendarObject;
use DavServices\CalDav\Filter\Filter;
use DavServices\CalDav\Limits;
use DavServices\CalDav\Moments;
use DavServices\CalDav\TimeZone;
use DavServices\Dav\ICollection;
use DavServices\Dav\INode;
use DavServices\Dav\Property\Answers;
use DavServices\Dav\Server;
use DavServices\Dav\VisibleMembers;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Uri\Path;
use DavServices\VObject\Component;
use DavServices\VObject\Recur\Zone;
use DavServices\Xml\Element;
use DavServices\Xml\MultiStatus;
use Generator;

/**
 * Answers `CALDAV:calendar-query`: which objects are these (RFC 4791 §7.8).
 *
 * **This is how a client reads a calendar.** Every month a user pages to is
 * one of these, and §7.8 makes support for it REQUIRED.
 *
 * Given to {@see \DavServices\Dav\Method\Report} like any other, which
 * {@see \DavServices\Plugin\CalDav} does for every server it is registered on:
 *
 *     $report->on(
 *         '{urn:ietf:params:xml:ns:caldav}calendar-query',
 *         (new CalendarQuery($server))(...),
 *     );
 *
 * The body is §9.5's:
 *
 *     <!ELEMENT calendar-query ((DAV:allprop |
 *                                DAV:propname |
 *                                DAV:prop)?, filter, timezone?)>
 *
 * ## The scope is the `Depth` header, and it may be left out
 *
 * §7.8: "The request MAY include a Depth header. If no Depth header is
 * included, Depth:0 is assumed." **The opposite of RFC 6352 §8.6**, which
 * raises the header to a MUST for its own report — so where an
 * `addressbook-query` without one is refused, this one is answered at depth
 * zero, and at depth zero a calendar collection reports nothing: a collection
 * is no calendar object resource. The empty answer is the memo's own default
 * rather than a server's choice, and a client that wants the calendar read
 * sends `Depth: 1`, as §7.8.1's example does.
 *
 * ## What counts as something to search
 *
 * §4.1: "Calendar object resources contained in calendar collections MUST NOT
 * specify the iCalendar METHOD property" and "MUST contain at least one
 * calendar component". A member that is not one — text that is no iCalendar, a
 * file holding two objects, something that is no file to begin with — is
 * therefore not a calendar object, and §7.8 has the response carry "a
 * DAV:response element for each iCalendar object that matched the search
 * filter". So it is passed over. The alternative would be to fail a client's
 * whole search over data it cannot see and could not correct.
 *
 * **The members are walked through {@see VisibleMembers}**, so an object
 * concealed from a client is not found by searching for it either — a listing
 * that named the thing has already told the client it exists.
 *
 * ## Which zone what floats is read in
 *
 * §7.3 gives three sources in order, and the order is a MUST for the first two:
 *
 * > CalDAV servers MUST rely on the value of the CALDAV:timezone XML element,
 * > if specified as part of the request body, to perform the proper conversion
 * > of "floating date" and "floating time" values to date with UTC time
 * > values. If the CALDAV:timezone XML element is not specified in the request
 * > body, CalDAV servers MUST rely on the value of the CALDAV:calendar-timezone
 * > property, if defined, or else the CalDAV servers MAY rely on the time zone
 * > of their choice.
 *
 * So the element wins where there is one; otherwise each calendar collection's
 * own property is read **as the walk enters it**, which is what §9.8 names —
 * "the CALDAV:calendar-timezone property of the calendar collection, in which
 * the calendar object resource is contained" — so a `Depth: infinity` query
 * over a home reads each calendar's own rather than one for all of them.
 *
 * **UTC is the third source.** §9.9's own tables are written against "date
 * with UTC time" values, and a server that picked a zone of its own liking
 * would answer the same query differently from one that did not.
 *
 * ## Why the properties are not fetched from a backend
 *
 * They are assembled the way a `PROPFIND` assembles them ({@see Answers}),
 * which is what §7.8 asks for: "the response should follow the same behavior
 * as PROPFIND with respect to the DAV:multistatus response elements used to
 * return specific property results." That is also what makes a property
 * nobody has come back as `404` rather than as nothing, which the same
 * paragraph makes a MUST.
 *
 * ## The preconditions of §7.8, each where it is read
 *
 * **`CALDAV:supported-calendar-data`** is answered in
 * {@see \DavServices\CalDav\CalendarData}, while the body is read — as is the
 * one thing in §9.6 this server cannot yet do, `CALDAV:expand` (R-CAL-14).
 *
 * **`CALDAV:valid-filter`** is raised where a filter is parsed — a component
 * nested where iCalendar has none ({@see \DavServices\CalDav\Filter\CompFilter})
 * and a range on a property §9.9 defines none for
 * ({@see \DavServices\CalDav\Filter\PropFilter}).
 *
 * **`CALDAV:supported-filter`** cannot be violated here. It is for a server
 * whose queries only reach what it has indexed: "if the CALDAV:filter element
 * attempts to reference an unsupported component, property, or parameter, this
 * precondition is violated". This one reads the object and asks it, so every
 * component, property and parameter is queryable, and there is no subset to
 * report.
 *
 * **`CALDAV:valid-calendar-data`** is the `CALDAV:timezone` element, read
 * through {@see TimeZone} — the same reading §5.3.1 gets, where "valid" means
 * a zone this server can resolve a moment with.
 *
 * **`CALDAV:min-date-time` and `CALDAV:max-date-time`** are asked of "any XML
 * element specifying a range of time", which {@see Limits::mustTakeTheRangesIn()}
 * reads as the four elements RFC 4791 writes one with — §9.9's own, wherever it
 * sits, and the three of §9.6 that say what of an object comes back.
 *
 * **`CALDAV:supported-collation`** is answered where the attribute is read, in
 * {@see \DavServices\CalDav\Filter\TextMatch}.
 *
 * Every one of them is raised before an object is fetched, because a
 * precondition is a reason not to run the report.
 *
 * ## The postcondition refuses rather than truncates
 *
 * §7.8 closes with "(DAV:number-of-matches-within-limits): The number of
 * matching calendar object resources must fall within server-specific,
 * predefined limits", and names no way of answering in part. The same section
 * makes the whole answer a MUST — "The response body for a successful
 * CALDAV:calendar-query REPORT request MUST contain a DAV:response element for
 * each iCalendar object that matched the search filter" — so a `207` that left
 * matches out would break that sentence while claiming success. RFC 6352
 * §8.6.2 spells out a truncated answer for `addressbook-query`, which is why
 * that report may give one and this one may not.
 *
 * ## What is not here yet
 *
 * The filter is handed down to no backend. {@see \DavServices\Dav\IQueryFilter}
 * exists for that and {@see Filter} is already shaped for it, but narrowing a
 * collection and then fetching what is left would be a second way into one —
 * the one without {@see VisibleMembers} on it — so it waits for the chunk that
 * has a backend able to filter (R-CAL-16) and can give concealment one place
 * to live.
 */
final class CalendarQuery
{
    /** What `Depth: infinity` becomes: deeper than any tree a backend holds. */
    private const ENDLESS = PHP_INT_MAX;

    private const WITHIN_LIMITS = '{DAV:}number-of-matches-within-limits';

    /**
     * @param Limits $limits What the targeted collection keeps (§5.2.6,
     *                       §5.2.7), which every range in the body is held to.
     *                       R-CAL-08 makes the values configurable, and a
     *                       server that narrowed them has to narrow its
     *                       queries by the same amount
     * @param int $atMost How many matches will be answered before the search
     *                    is refused (§7.8's postcondition). A calendar of a
     *                    hundred thousand objects answers a filter that
     *                    matches everything with all of them unless somebody
     *                    says otherwise, and R-CAL-08's own cap on generated
     *                    instances is the same order of magnitude
     */
    public function __construct(
        private readonly Server $server,
        private readonly Limits $limits = new Limits(),
        private readonly int $atMost = 5_000,
    ) {
    }

    /**
     * Reports on the objects the filter matches.
     *
     * @throws BadRequest If the body carries no `CALDAV:filter`, the `Depth`
     *                    header names a depth this report is not defined at,
     *                    or a piece of the body is not one §9.5 admits
     * @throws Forbidden If a precondition or the postcondition of §7.8 is
     *                   violated
     */
    public function __invoke(Request $request, Element $asked): Response
    {
        $depth = self::depthOf($request);
        $filter = Filter::of($asked->child(Filter::NAME) ?? throw new BadRequest(
            'A CALDAV:calendar-query carries a CALDAV:filter: RFC 4791 §9.5 has it outside the optional group, so there is no query without one.',
        ));

        $this->limits->mustTakeTheRangesIn($asked);

        $returning = WhatToReturn::of($this->server, $asked);
        $zone = FloatingZone::of($this->server, $asked);

        $path = $this->server->path($request);
        $node = $this->server->tree()->node($path);

        $report = new MultiStatus();
        $reported = 0;
        $found = $this->matchesUnder($path, $node, $depth, $filter, $zone);

        foreach ($found as $where => [$member, $object, $moments]) {
            if ($reported === $this->atMost) {
                throw new Forbidden(
                    sprintf(
                        'This server answers at most %d matching calendar object resources in one report, and this filter matches more.',
                        $this->atMost,
                    ),
                    self::WITHIN_LIMITS,
                );
            }

            $returning->describe(
                $report,
                $this->server->hrefOf($where, $member),
                $where,
                $member,
                $object,
                $moments,
            );
            ++$reported;
        }

        return (new Response(207, body: $this->server->writer()->write($report->toElement())))
            ->withHeader('Content-Type', 'application/xml; charset=utf-8');
    }

    /**
     * The calendar objects below this path that the filter matches, by path.
     *
     * The walk is lazy so that the caller can stop where it must: §7.8's
     * postcondition is about a search too wide to answer, and a search refused
     * at the limit is one that has not read the rest of the calendar.
     *
     * Each one comes with the object that was read to decide it matched, and
     * with the moments that object's properties name, so that the response
     * neither reads the body a second time nor works out the zone again.
     *
     * @param FloatingZone $zone What a floating value is read in, which §7.3
     *                           settles per object
     *
     * @return Generator<string, array{INode, Component, Moments}>
     */
    private function matchesUnder(
        string $path,
        INode $node,
        int $depth,
        Filter $filter,
        FloatingZone $zone,
    ): Generator {
        if (!$node instanceof ICollection) {
            $object = CalendarObject::of($node);

            if ($object === null) {
                return;
            }

            $moments = new Moments($object, $zone->forTheObjectAt($path, $node));

            if ($filter->holds($object, $moments)) {
                yield $path => [$node, $object, $moments];
            }

            return;
        }

        // A collection is no calendar object resource (§4.2), so at the end of
        // the depth there is nothing here to report — not even for a filter
        // that matches everything.
        if ($depth === 0) {
            return;
        }

        foreach (VisibleMembers::of($this->server->events(), $path, $node) as $member => $child) {
            yield from $this->matchesUnder($member, $child, $depth - 1, $filter, $zone);
        }
    }






    /**
     * How far down the query reaches.
     *
     * §7.8: "The request MAY include a Depth header. If no Depth header is
     * included, Depth:0 is assumed." The default is the memo's own, and
     * RFC 4918 §10.2 is where the three values come from.
     *
     * @throws BadRequest If the header names a depth this report is not
     *                    defined at
     */
    private static function depthOf(Request $request): int
    {
        $header = $request->headers()->first('Depth');

        if ($header === null) {
            return 0;
        }

        // Without regard to case: RFC 4918 §10.2 writes the value as `("0" |
        // "1" | "infinity")`, and RFC 5234 §2.3 makes the grammar's own
        // strings case-insensitive.
        $depth = strtolower($header);

        if ($depth === '0' || $depth === '1') {
            return (int) $depth;
        }

        if ($depth === 'infinity') {
            return self::ENDLESS;
        }

        throw new BadRequest(sprintf(
            'A calendar-query goes 0, 1 or infinity deep, not "%s".',
            $header,
        ));
    }
}
