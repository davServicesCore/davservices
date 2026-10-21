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

use Closure;
use DateTimeImmutable;
use DavServices\CalDav\CalendarObject;
use DavServices\CalDav\FreeBusy;
use DavServices\CalDav\Limits;
use DavServices\CalDav\Moments;
use DavServices\CalDav\TimeRange;
use DavServices\Dav\ICollection;
use DavServices\Dav\INode;
use DavServices\Dav\ReportDepth;
use DavServices\Dav\Server;
use DavServices\Dav\VisibleMembers;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\VObject\Component;
use DavServices\VObject\Property;
use DavServices\VObject\Serializer;
use DavServices\Xml\Element;
use Generator;

/**
 * Answers `CALDAV:free-busy-query`: when is this calendar busy (RFC 4791 §7.10).
 *
 * **This is how one person's time is seen without their calendar being read.**
 * §6.1.1 is where the memo says why: "Calendar users often wish to allow other
 * users to see their busy time information, without viewing the other details
 * of the calendar components (e.g., location, summary, attendees). This allows
 * a significant amount of privacy while still allowing other users to schedule
 * meetings at times when the user is likely to be free." Support for the
 * report is REQUIRED.
 *
 * Given to {@see \DavServices\Dav\Method\Report} like any other, which
 * {@see \DavServices\Plugin\CalDav} does for every server it is registered on:
 *
 *     $report->on(
 *         '{urn:ietf:params:xml:ns:caldav}free-busy-query',
 *         (new FreeBusyQuery($server))(...),
 *     );
 *
 * The body is §9.11's, and it is the shortest of the three:
 *
 *     <!ELEMENT free-busy-query (time-range)>
 *
 * ## It is the one report that does not answer with a multistatus
 *
 * §7.10: "The response body for a successful request MUST be an iCalendar
 * object that contains exactly one VFREEBUSY component that describes the busy
 * time intervals for the calendar object resources containing VEVENT, or
 * VFREEBUSY components that satisfy the Depth value and for which the current
 * user is at least granted the CALDAV:read-free-busy privilege."
 *
 * So there are no properties in the answer, no `DAV:response` elements, and no
 * `207` — a `200` carrying `text/calendar`, as §7.10.1's example shows. What
 * the busy time of those resources comes to is {@see FreeBusy}; this class is
 * what finds them and writes the object around the component.
 *
 * **If nothing is found, the answer is still an answer**: "If no calendar
 * object resources are found to satisfy these conditions, a VFREEBUSY
 * component with no FREEBUSY property MUST be returned." A `404` would say the
 * collection is not there, which is a different thing from a quiet day.
 *
 * ## Exactly one range, and nothing else is needed
 *
 * §9.11's definition is one child, named, and not optional, and §7.10 says it
 * again: the body "MUST contain exactly one CALDAV:time-range XML element, as
 * defined in Section 9.9". A body that names none, or two, is refused whole —
 * a report over a range nobody named is a question that was not asked.
 *
 * **A child §9.11 does not name is left where it is.** It cannot change what
 * comes back, the one range deciding that, which is the reading
 * {@see CalendarMultiGet} gives a body carrying more than §9.10's own children
 * as well.
 *
 * **The range may be open at one end**, §9.9's attributes being `#IMPLIED`:
 * "Time ranges open at one end can be specified by including only one
 * attribute". The window the answer covers is then open at that end too, and
 * {@see FreeBusy} writes no property for an edge there is none of. A series
 * that never ends cannot be written out over such a range, and what comes back
 * for it is what comes back for any series past the hard limit — the component
 * as it stands.
 *
 * ## The Request-URI is a collection, and the `Depth` header says how far
 *
 * §7.10: "The CALDAV:free-busy-query REPORT request can only be run against a
 * collection (either a regular collection or a calendar collection). An attempt
 * to run the report on a calendar object resource MUST fail and return a 403
 * (Forbidden) status value." The memo names the calendar object resource
 * because that is the mistake a client makes; the sentence before it is the
 * rule, so a member that is no calendar object is refused by it as well.
 *
 * §7.10 words the depth exactly as §7.8 does — "The request MAY include a
 * Depth header. If no Depth header is included, Depth:0 is assumed" — so it is
 * read in the one place that sentence lives, {@see ReportDepth::of()}. At
 * depth zero a collection holds no calendar object resource of its own (§4.2),
 * and the answer is the empty `VFREEBUSY` above: the memo's own default rather
 * than a server's choice, and a client that wants the calendar read sends
 * `Depth: 1`, as §7.10.1's example does.
 *
 * **The members are walked through {@see VisibleMembers}**, so an object
 * concealed from a client says nothing about its busy time either. §7.10 asks
 * for the busy time of the resources "that have the CALDAV:read-free-busy or
 * DAV:read privilege granted to the current user", and which members a client
 * may see is the question a listing is already asked — keeping the answer in
 * one place is what stops the two from drifting.
 *
 * ## What §7.10 asks for and this server does not yet do
 *
 * **A principal granted `CALDAV:read-free-busy` and not `DAV:read` cannot run
 * this report here.** §7.10 refuses with `404` only where *neither* is
 * granted; {@see \DavServices\Plugin\Acl} asks every `REPORT` for `DAV:read`
 * before a body is read, so it refuses a case the memo expects to work. That
 * is over-restrictive rather than leaky — nothing is disclosed — and it is
 * named here rather than quietly left: closing it means teaching the access
 * control guard which privilege a given report needs, which is a change to
 * that layer and not to this one. It is also why
 * {@see \DavServices\Plugin\CalDav} still does not announce `calendar-access`.
 *
 * ## The preconditions, and the postcondition that refuses rather than truncates
 *
 * §7.10 lists no preconditions of its own — "Preconditions: None" — but §7.8's
 * two date ones are about "any XML element specifying a range of time", and
 * this body is nothing but such an element. {@see Limits::mustTakeTheRange()}
 * holds it to §5.2.6 and §5.2.7, before an object is fetched, because a
 * precondition is a reason not to run the report.
 *
 * The postcondition is `DAV:number-of-matches-within-limits`: "The number of
 * matching calendar object resources must fall within server-specific,
 * predefined limits. For example, this postcondition might fail if the
 * specified CALDAV:time-range would cause an extremely large number of
 * calendar object resources to be considered in computing the response."
 *
 * **It counts what is considered, not what turns out to be busy.** That is the
 * memo's own word — "considered in computing the response" — and the cost of
 * the report is in the objects read rather than in the periods they come to.
 * And it refuses rather than answering in part: a `VFREEBUSY` that quietly
 * left a calendar's afternoon out would be read as a free afternoon, which is
 * worse than a refusal a client can see.
 */
final class FreeBusyQuery
{
    /**
     * What this library calls itself in an object it made.
     *
     * RFC 5545 §3.7.3 makes `PRODID` REQUIRED in a `VCALENDAR` and leaves the
     * value free, recommending the formal public identifier of ISO 9070. This
     * is the one place in the library that **produces** an iCalendar object
     * rather than passing one through — everywhere else, writing this name
     * over somebody else's work would be a lie in the file.
     */
    public const PRODUCT = '-//davServices//CalDAV Server//EN';

    private const WITHIN_LIMITS = '{DAV:}number-of-matches-within-limits';

    private const CALENDAR = 'VCALENDAR';

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $now;

    /**
     * @param Limits $limits What the targeted collection keeps (§5.2.6,
     *                       §5.2.7), which the range is held to
     * @param int $atMost How many calendar object resources will be considered
     *                    before the report is refused (§7.10's
     *                    postcondition). R-CAL-08 makes the figure
     *                    configurable, and it is the same order of magnitude
     *                    as the memo's own cap on generated instances
     * @param (callable(): DateTimeImmutable)|null $now The clock, handed in so
     *                                                  that a test can say
     *                                                  what time it is and so
     *                                                  that one request reads
     *                                                  it once (R-ARC-05)
     * @param string $product What the answer says made it, for a deployment
     *                        that would rather be named than name this library
     */
    public function __construct(
        private readonly Server $server,
        private readonly Limits $limits = new Limits(),
        private readonly int $atMost = 5_000,
        ?callable $now = null,
        private readonly string $product = self::PRODUCT,
    ) {
        $this->now = $now === null
            ? static fn (): DateTimeImmutable => new DateTimeImmutable()
            : $now(...);
    }

    /**
     * Reports the busy time of everything the request reaches.
     *
     * @throws BadRequest If the body does not carry exactly one
     *                    `CALDAV:time-range`, that range is not one §9.9
     *                    admits, or the `Depth` header names a depth this
     *                    report is not defined at
     * @throws Forbidden If the Request-URI is no collection, or the
     *                   postcondition of §7.10 is violated
     */
    public function __invoke(Request $request, Element $asked): Response
    {
        $depth = ReportDepth::of($request);
        $range = self::theOneRangeIn($asked);

        $this->limits->mustTakeTheRange($range);

        $path = $this->server->path($request);
        $node = $this->server->tree()->node($path);

        if (!$node instanceof ICollection) {
            throw new Forbidden(
                'A CALDAV:free-busy-query "can only be run against a collection (either a regular collection or a calendar collection)" (RFC 4791 §7.10), and this is neither.',
            );
        }

        $busy = new FreeBusy($range);
        $zone = FloatingZone::ofTheCollections($this->server);
        $considered = 0;

        foreach ($this->objectsUnder($path, $node, $depth) as $where => [$member, $object]) {
            if ($considered === $this->atMost) {
                throw new Forbidden(
                    sprintf(
                        'This server considers at most %d calendar object resources in one free-busy query, and this range reaches more.',
                        $this->atMost,
                    ),
                    self::WITHIN_LIMITS,
                );
            }

            $busy->consider($object, new Moments($object, $zone->forTheObjectAt($where, $member)));
            ++$considered;
        }

        return (new Response(200, body: (new Serializer())->write($this->objectAround($busy))))
            ->withHeader('Content-Type', Limits::MEDIA_TYPE . '; charset=utf-8');
    }

    /**
     * §9.11's one child.
     *
     * @throws BadRequest If the body carries no such element, or more than one
     */
    private static function theOneRangeIn(Element $asked): TimeRange
    {
        $found = [];

        foreach ($asked->children() as $child) {
            if ($child->name() === TimeRange::NAME) {
                $found[] = $child;
            }
        }

        if (count($found) !== 1) {
            throw new BadRequest(sprintf(
                'A CALDAV:free-busy-query body "MUST contain exactly one CALDAV:time-range XML element" (RFC 4791 §7.10), and this one carries %d.',
                count($found),
            ));
        }

        return TimeRange::of($found[0]);
    }

    /**
     * The iCalendar object the component goes in.
     *
     * RFC 5545 §3.6 makes `PRODID` and `VERSION` mandatory in a `VCALENDAR`
     * and §3.4 puts the properties before the components; §5.2.4 of RFC 4791
     * has this server keep version 2.0. The order is §7.10.1's own.
     */
    private function objectAround(FreeBusy $busy): Component
    {
        $calendar = new Component(self::CALENDAR);

        $calendar->add(new Property('VERSION', Limits::VERSION));
        $calendar->add(new Property('PRODID', $this->product));
        $calendar->add($busy->component(($this->now)()));

        return $calendar;
    }

    /**
     * The calendar object resources below this path, by path.
     *
     * The walk is lazy so that the caller can stop where it must: §7.10's
     * postcondition is about a range too wide to compute over, and a report
     * refused at the limit is one that has not read the rest of the calendar.
     *
     * Each one comes with the member it was read from, so that §7.3's zone can
     * be worked out for the collection it actually sits in.
     *
     * @return Generator<string, array{INode, Component}>
     */
    private function objectsUnder(string $path, INode $node, int $depth): Generator
    {
        if (!$node instanceof ICollection) {
            $object = CalendarObject::of($node);

            // §4.1 says what a calendar object resource is, and a member that
            // is not one — a note, something that is no file — is passed over:
            // a report that fell over on it would say nothing about the
            // calendar holding it.
            if ($object !== null) {
                yield $path => [$node, $object];
            }

            return;
        }

        if ($depth === 0) {
            return;
        }

        foreach (VisibleMembers::of($this->server->events(), $path, $node) as $member => $child) {
            yield from $this->objectsUnder($member, $child, $depth - 1);
        }
    }
}
