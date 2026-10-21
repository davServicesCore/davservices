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

use DateTimeZone;
use DavServices\CalDav\Calendar;
use DavServices\CalDav\TimeZone;
use DavServices\Dav\INode;
use DavServices\Dav\Property\Answers;
use DavServices\Dav\PropFindForm;
use DavServices\Dav\Server;
use DavServices\Exception\Forbidden;
use DavServices\Uri\Path;
use DavServices\VObject\Recur\Zone;
use DavServices\Xml\Element;

/**
 * The zone a report reads a floating value in (RFC 4791 §7.3).
 *
 * > CalDAV servers MUST rely on the value of the CALDAV:timezone XML element,
 * > if specified as part of the request body, to perform the proper conversion
 * > of "floating date" and "floating time" values to date with UTC time
 * > values. If the CALDAV:timezone XML element is not specified in the request
 * > body, CalDAV servers MUST rely on the value of the CALDAV:calendar-timezone
 * > property, if defined, or else the CalDAV servers MAY rely on the time zone
 * > of their choice.
 *
 * Three sources in order, and the first two are a MUST. **Two reports ask the
 * same question**, which is why this is one class rather than a passage
 * written twice: `calendar-query` may carry the element, §9.5's body having a
 * `timezone?`, while `calendar-multiget` has no such element in §9.10's body
 * at all — so {@see of()} reads a body that may name one and
 * {@see ofTheCollections()} answers for a report whose body cannot.
 *
 * ## Which collection the property comes from
 *
 * §9.8 names it: "the CALDAV:calendar-timezone property of the calendar
 * collection, **in which the calendar object resource is contained**". So the
 * answer is the nearest calendar collection at or above whatever is being
 * asked about — a report may be aimed at the calendar, at one object in it, or
 * at a plain folder §4.2 lets a calendar hold, and all three are inside the
 * same calendar.
 *
 * **Only a calendar collection is asked.** §5.2.2 defines the property on one,
 * so a calendar home, or a plain folder, has no answer to give even if
 * something put the property there. A calendar is one this library was told
 * about, which is what {@see Calendar} is — the same thing
 * {@see \DavServices\Plugin\CalDav} answers the other calendar properties for.
 *
 * **The property is read the way a `PROPFIND` reads it**, through
 * {@see Answers}, because it may be kept by the collection itself or by
 * {@see \DavServices\Plugin\DeadProperties} behind it — and because a plugin
 * that refuses it to this client is then the one that answers, rather than
 * being gone around.
 *
 * **UTC is the third source.** §9.9's own tables are written against "date
 * with UTC time" values, and a server that picked a zone of its own liking
 * would answer the same query differently from one that did not.
 */
final readonly class FloatingZone
{
    private const TIMEZONE = '{urn:ietf:params:xml:ns:caldav}timezone';

    private const KEPT = '{urn:ietf:params:xml:ns:caldav}calendar-timezone';

    private const UTC = 'UTC';

    /**
     * @param DateTimeZone|Zone|null $given What the request body named, or
     *                                      null where it named none
     */
    private function __construct(
        private Server $server,
        private DateTimeZone|Zone|null $given,
    ) {
    }

    /**
     * Reads a body that may carry a `CALDAV:timezone` (§9.5, §9.11).
     *
     * @throws Forbidden If it carries one that is no iCalendar object with a
     *                   single `VTIMEZONE` this server can resolve a moment
     *                   with (§7.8's `CALDAV:valid-calendar-data`)
     */
    public static function of(Server $server, Element $asked): self
    {
        $element = $asked->child(self::TIMEZONE);

        if ($element === null) {
            return new self($server, null);
        }

        return new self($server, TimeZone::zoneIn($element->text()) ?? throw new Forbidden(
            'A CALDAV:timezone carries "an iCalendar object with exactly one VTIMEZONE" (RFC 4791 §9.8) that a moment can be resolved with: the CALDAV:valid-calendar-data precondition of §7.8.',
            TimeZone::VALID,
        ));
    }

    /**
     * For a report whose body cannot name a zone, which leaves §7.3's second
     * and third sources.
     *
     * §9.10's `calendar-multiget` is one: its body is
     * `((DAV:allprop | DAV:propname | DAV:prop)?, DAV:href+)`, with no
     * `timezone` in it — so a server that read one would be answering an
     * element the memo does not admit.
     */
    public static function ofTheCollections(Server $server): self
    {
        return new self($server, null);
    }

    /**
     * The zone whatever sits at this path is read in.
     */
    public function forTheObjectAt(string $path, INode $node): DateTimeZone|Zone
    {
        return $this->given ?? $this->keptAtOrAbove($path, $node) ?? new DateTimeZone(self::UTC);
    }

    /**
     * The zone kept by the nearest calendar collection at or above this path,
     * or null where no calendar above it keeps one.
     */
    private function keptAtOrAbove(string $path, INode $node): DateTimeZone|Zone|null
    {
        $kept = $this->keptBy($path, $node);

        // The root is where looking up stops, and no calendar collection is
        // the root: §4.2 has one hold calendar object resources, and a server
        // whose whole tree was a calendar could hold no principals.
        if ($kept !== null || $path === '') {
            return $kept;
        }

        $above = Path::split($path)[0];

        return $this->keptAtOrAbove($above, $this->server->tree()->node($above));
    }

    /**
     * The zone this calendar collection keeps, or null where it keeps none
     * this server can resolve a moment with.
     *
     * A value nothing can be resolved with is treated as none: §5.2.10 and
     * §5.3.1 refuse such a value on the way in, so one in a store got there
     * another way, and §7.3's last clause — a zone of the server's choice — is
     * a better answer than a report that fails over a property the client did
     * not even name.
     */
    private function keptBy(string $path, INode $node): DateTimeZone|Zone|null
    {
        if (!$node instanceof Calendar) {
            return null;
        }

        $answers = Answers::about(
            $this->server->events(),
            $path,
            $node,
            PropFindForm::Named,
            [self::KEPT],
        )->byStatus();

        $kept = $answers[200][self::KEPT] ?? null;

        if ($kept === null) {
            return null;
        }

        return TimeZone::zoneIn($kept instanceof Element ? $kept->text() : $kept);
    }
}
