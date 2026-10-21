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

namespace DavServices\CalDav;

use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use DavServices\VObject\Component;
use DavServices\VObject\Property;
use DavServices\VObject\Serializer;
use DavServices\Xml\Element;

/**
 * The calendar data a report asks for, and the data it answers with
 * (RFC 4791 §9.6).
 *
 * The element has a shape per direction:
 *
 *     <!ELEMENT calendar-data (comp?,
 *                              (expand | limit-recurrence-set)?,
 *                              limit-freebusy-set?)>   in a request
 *     <!ELEMENT calendar-data (#PCDATA)>               in a response
 *     <!ATTLIST calendar-data content-type CDATA "text/calendar"
 *                             version CDATA "2.0">
 *
 * and a third, `EMPTY`, inside `CALDAV:supported-calendar-data`, where it
 * names a media type rather than data. That one is written by
 * {@see \DavServices\Plugin\CalDav} and read by nobody.
 *
 * **It is not a WebDAV property**, which §9.6 says in as many words: it "is
 * specified in requests and responses inside the DAV:prop XML element as if it
 * were a WebDAV property. However, the CALDAV:calendar-data XML element is not
 * a WebDAV property and, as such, is not returned in PROPFIND responses, nor
 * used in PROPPATCH requests." So a report answers it itself rather than
 * letting the property machinery try — a client that asked for it and got a
 * `404` would have been told the one thing it came for does not exist — and a
 * `DAV:allprop` never brings it along.
 *
 * ## What the attributes are held to
 *
 * §7.8 makes them a precondition of the report: "(CALDAV:supported-calendar-
 * data): The attributes 'content-type' and 'version' of the
 * CALDAV:calendar-data XML element (see Section 9.6) specify a media type
 * supported by the server for calendar object resources."
 *
 * What this server supports is §5.2.4's answer in the absence of a property
 * saying otherwise — "the server MUST only accept data with the media type
 * 'text/calendar' and iCalendar version 2.0" — and it is read from
 * {@see Limits} rather than written out again, so the property that publishes
 * the list and the report held to it cannot come apart.
 *
 * ## Three ways to ask for less, and one still to come
 *
 * **Which parts of which components** is {@see CalendarDataFilter} (§9.6.1 to
 * §9.6.4).
 *
 * **Which overridden components** is §9.6.6's `limit-recurrence-set`, and
 * **which free/busy periods** is §9.6.7's `limit-freebusy-set`. Both are
 * answered here, with §9.9's own logic, which both sections ask for in the
 * same words: "The server MUST use the same logic as defined for
 * CALDAV:time-range".
 *
 * **Which instances, written out one component each**, is §9.6.5's
 * `CALDAV:expand` and {@see Expander} (R-CAL-14). It stands in the same choice
 * as the recurrence limit, because the two are the same question answered two
 * ways: one says which overrides matter to a range, the other writes out what
 * the range holds.
 *
 * ## The limits and the expansion come before the narrowing
 *
 * §9.6's DTD writes the narrowing first, but that is the order of a document
 * rather than of the work: a narrowing can take away the very property the
 * others are computed from — `RECURRENCE-ID` says which instance an override
 * replaces, and `RRULE` is the series itself — so a client that asked only for
 * summaries would otherwise leave the server unable to tell a master from an
 * override, or to expand anything at all.
 */
final readonly class CalendarData
{
    public const NAME = '{urn:ietf:params:xml:ns:caldav}calendar-data';

    /** §9.6.5: recurrences written out as instances. */
    private const EXPAND = Expander::NAME;

    /** §9.6.6: the instances inside a range, and the overrides outside it. */
    private const LIMIT_RECURRENCE = '{urn:ietf:params:xml:ns:caldav}limit-recurrence-set';

    /** §9.6.7: the free/busy periods inside a range. */
    private const LIMIT_FREEBUSY = '{urn:ietf:params:xml:ns:caldav}limit-freebusy-set';

    private const RECURRENCE_ID = 'RECURRENCE-ID';

    private const FREEBUSY = 'FREEBUSY';

    private const VFREEBUSY = 'VFREEBUSY';

    /**
     * @param CalendarDataFilter|null $parts Which parts of which components
     *                                       come back, or null for all of them
     * @param Expander|null $expands The range §9.6.5 writes the instances of,
     *                               or null where the client asked for none
     * @param TimeRange|null $recurrences The range §9.6.6 limits the
     *                                    overridden components to
     * @param TimeRange|null $freeBusy The range §9.6.7 limits the `FREEBUSY`
     *                                 values to
     */
    private function __construct(
        private ?CalendarDataFilter $parts,
        private ?Expander $expands,
        private ?TimeRange $recurrences,
        private ?TimeRange $freeBusy,
    ) {
    }

    /**
     * Reads the element as a request.
     *
     * Asked before any object is fetched, because a precondition is a reason
     * not to run the report at all.
     *
     * @throws Forbidden If the attributes name calendar data this server does
     *                   not keep (§7.8's `CALDAV:supported-calendar-data`)
     * @throws BadRequest If the children are not what §9.6's DTD admits in the
     *                    order it writes them
     */
    public static function of(Element $element): self
    {
        self::mustBeTheDataItKeeps($element);

        $parts = null;
        $recurrences = null;
        $freeBusy = null;
        $expands = null;

        foreach ($element->children() as $child) {
            match ($child->name()) {
                CalendarDataFilter::NAME => $parts = self::theOnlyRestriction($parts, $child, $recurrences, $freeBusy),
                self::EXPAND => $expands = self::theOnlyExpansion($expands, $child, $recurrences, $freeBusy),
                self::LIMIT_RECURRENCE => $recurrences = self::theOnlyRecurrenceLimit(
                    $recurrences,
                    $child,
                    $freeBusy,
                    $expands,
                ),
                self::LIMIT_FREEBUSY => $freeBusy = self::theOnlyFreeBusyLimit($freeBusy, $child),
                default => throw new BadRequest(sprintf(
                    '%s has no place in a CALDAV:calendar-data of RFC 4791 §9.6.',
                    $child->name(),
                )),
            };
        }

        return new self($parts, $expands, $recurrences, $freeBusy);
    }

    /**
     * The element as a response carries it, which is §9.6's second shape:
     * `<!ELEMENT calendar-data (#PCDATA)>`.
     *
     * The writer escapes the text, which is what §9.6 asks for — "The
     * iCalendar data embedded within the CALDAV:calendar-data XML element MUST
     * follow the standard XML character data encoding rules, including use of
     * &lt;, &gt;, &amp; etc. entity encoding or the use of a <![CDATA[ ... ]]>
     * construct." Entity encoding is the half chosen here, so the warning that
     * follows it — that a CDATA section cannot carry the sequence that ends one
     * — does not apply.
     *
     * **The object is written out rather than handed on as it arrived.** §9.6
     * licenses the difference that makes: "Given that XML parsers normalize the
     * two-character sequence CRLF […] to a single LF character […], the CR
     * character […] MAY be omitted in calendar object resources specified in
     * the CALDAV:calendar-data XML element."
     *
     * @param Moments $moments What moment each of the object's properties
     *                         names, which is what the two limits are measured
     *                         with (§7.3 settles the zone)
     */
    public function elementFor(Component $object, Moments $moments): Element
    {
        $element = new Element(self::NAME);

        $element->appendText((new Serializer())->write($this->asked($object, $moments)));

        return $element;
    }

    /**
     * The object as the client asked for it.
     */
    private function asked(Component $object, Moments $moments): Component
    {
        if ($this->expands !== null) {
            $object = $this->expands->appliedTo($object, $moments);
        }

        if ($this->recurrences !== null) {
            $object = $this->withinTheRecurrences($object, $this->recurrences, $moments);
        }

        if ($this->freeBusy !== null) {
            $object = self::withinTheFreeBusy($object, $this->freeBusy);
        }

        if ($this->parts === null) {
            return $object;
        }

        // A restriction about another kind of component names no part of this
        // object (§9.6.1), and then what comes back is the object's own
        // beginning and end with nothing between — which §9.6 licenses, having
        // said the answer "MAY be invalid per their media type specification".
        return $this->parts->appliedTo($object) ?? new Component($object->name());
    }

    /**
     * The object with only the overridden components that impact the range.
     *
     * §9.6.6: "the server MUST return, in addition to the 'master component',
     * only the 'overridden components' that impact a specified time range. An
     * overridden component impacts a time range if its current start and end
     * times overlap the time range, or if the original start and end times --
     * the ones that would have been used if the instance were not overridden
     * -- overlap the time range."
     *
     * **An overridden component is one that carries a `RECURRENCE-ID`**
     * (RFC 5545 §3.8.4.4), and everything else — the master, a `VTIMEZONE`, an
     * event of another series — is not what that sentence is about.
     */
    private function withinTheRecurrences(Component $object, TimeRange $range, Moments $moments): Component
    {
        $kept = new Component($object->name());

        foreach ($object->properties() as $property) {
            $kept->add($property);
        }

        foreach ($object->components() as $component) {
            $identifier = $component->property(self::RECURRENCE_ID);

            if ($identifier === null || self::impacts($range, $component, $identifier, $object, $moments)) {
                $kept->add($component);
            }
        }

        return $kept;
    }

    /**
     * Whether one overridden component impacts the range.
     */
    private static function impacts(
        TimeRange $range,
        Component $override,
        Property $identifier,
        Component $object,
        Moments $moments,
    ): bool {
        // "if its current start and end times overlap the time range"
        if ($range->overlaps($override, $moments)) {
            return true;
        }

        // "Overridden components that have a RANGE parameter on their
        // RECURRENCE-ID property may specify one or more instances in the
        // recurrence set, and some of those instances may fall within the
        // specified time range […] If that is the case, the overridden
        // component MUST be included in the results."
        if (self::speaksForWhatFollows($identifier) && self::reaches($range, $identifier, $moments)) {
            return true;
        }

        // "or if the original start and end times -- the ones that would have
        // been used if the instance were not overridden -- overlap the time
        // range"
        return self::originallyOverlapped($range, $override, $identifier, $object, $moments);
    }

    /**
     * Whether the instance the series would have made overlaps the range.
     *
     * **The original start is the `RECURRENCE-ID` itself** — RFC 5545 §3.8.4.4:
     * "The property value is the original value of the 'DTSTART' property of
     * the recurrence instance" — and the original end is that start plus the
     * master's own length. So the question is the master's own table, asked
     * with the range moved by the gap between where the master begins and
     * where that instance would have: the same arithmetic
     * {@see Filter\CompFilter} uses to ask about an instance without writing
     * it out.
     *
     * **An override without a master has no original time at all.** RFC 4791
     * §4.1 lets an object "just contain components that represent 'overridden'
     * instances", so this is a resource a store may hold, and what is left is
     * the override's own times.
     */
    private static function originallyOverlapped(
        TimeRange $range,
        Component $override,
        Property $identifier,
        Component $object,
        Moments $moments,
    ): bool {
        $master = self::theMasterOf($override, $object);

        if ($master === null) {
            return false;
        }

        $begins = $moments->of($master->property('DTSTART'));
        $at = $moments->of($identifier);

        if ($begins === null || $at === null) {
            return false;
        }

        return $range->moved($begins->getTimestamp() - $at->getTimestamp())->overlaps($master, $moments);
    }

    /**
     * The component of the same series that defines it, or null where the
     * object carries none.
     */
    private static function theMasterOf(Component $override, Component $object): ?Component
    {
        $uid = $override->property('UID')?->value();

        foreach ($object->components() as $candidate) {
            if ($candidate->property(self::RECURRENCE_ID) !== null) {
                continue;
            }

            if ($candidate->property('UID')?->value() === $uid) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Whether this `RECURRENCE-ID` speaks for the instances after it as well.
     *
     * RFC 5545 §3.2.13: `rangeparam = "RANGE" "=" "THISANDFUTURE"`, and §3.1
     * makes "property parameter values" case-insensitive.
     */
    private static function speaksForWhatFollows(Property $identifier): bool
    {
        $range = $identifier->parameter('RANGE')?->value();

        return $range !== null && strcasecmp($range, 'THISANDFUTURE') === 0;
    }

    /**
     * Whether the range reaches the instances such an override speaks for.
     *
     * Those are the ones at or after its own identifier, so a range that ends
     * before it holds none of them. **The memo's own test is "may fall"**, not
     * "does fall": deciding it exactly would mean expanding the series to see
     * whether it still runs that far, which §9.6.6 does not ask for — and the
     * sentence exists to keep such an override in rather than out.
     */
    private static function reaches(TimeRange $range, Property $identifier, Moments $moments): bool
    {
        $at = $moments->of($identifier);

        return $at !== null && $range->reachesPast($at);
    }

    /**
     * The object with only the `FREEBUSY` values that intersect the range.
     *
     * §9.6.7: "the server MUST only return the FREEBUSY property values of a
     * VFREEBUSY component that intersects a specified time range."
     *
     * **The values, not the properties.** A value is a list — RFC 5545
     * §3.8.2.6: `fbvalue = period *("," period)` — so what is left of a
     * property is written back as one, and a property with nothing left is not
     * returned at all.
     */
    private static function withinTheFreeBusy(Component $object, TimeRange $range): Component
    {
        $kept = new Component($object->name());

        foreach ($object->properties() as $property) {
            $kept->add($property);
        }

        foreach ($object->components() as $component) {
            $kept->add(
                strcasecmp($component->name(), self::VFREEBUSY) === 0
                    ? self::withoutTheOtherPeriods($component, $range)
                    : $component,
            );
        }

        return $kept;
    }

    /**
     * One `VFREEBUSY` with only the periods that intersect the range.
     */
    private static function withoutTheOtherPeriods(Component $component, TimeRange $range): Component
    {
        $kept = new Component($component->name());

        foreach ($component->properties() as $property) {
            if (strcasecmp($property->name(), self::FREEBUSY) !== 0) {
                $kept->add($property);

                continue;
            }

            $periods = [];

            foreach (explode(',', $property->value()) as $written) {
                if ($range->intersectsThePeriod($written)) {
                    $periods[] = $written;
                }
            }

            if ($periods !== []) {
                $kept->add(new Property($property->name(), implode(',', $periods), $property->parameters()));
            }
        }

        foreach ($component->components() as $inner) {
            $kept->add($inner);
        }

        return $kept;
    }

    /**
     * @throws BadRequest If a second `CALDAV:comp` arrives, or one arrives
     *                    after a limit
     */
    private static function theOnlyRestriction(
        ?CalendarDataFilter $parts,
        Element $element,
        ?TimeRange $recurrences,
        ?TimeRange $freeBusy,
    ): CalendarDataFilter {
        if ($parts !== null) {
            throw new BadRequest(
                'A CALDAV:calendar-data narrows the parts once: RFC 4791 §9.6 spells it comp?.',
            );
        }

        if ($recurrences !== null || $freeBusy !== null) {
            throw new BadRequest(
                'A CALDAV:comp comes before the limits of a CALDAV:calendar-data: RFC 4791 §9.6 writes that order.',
            );
        }

        return CalendarDataFilter::of($element);
    }

    /**
     * @throws BadRequest If a second one arrives, one arrives beside a
     *                    `CALDAV:limit-recurrence-set`, one arrives after the
     *                    free/busy limit, or it names no range §9.6.5 admits
     */
    private static function theOnlyExpansion(
        ?Expander $expands,
        Element $element,
        ?TimeRange $recurrences,
        ?TimeRange $freeBusy,
    ): Expander {
        self::mustBeTheFirstOfTheChoice($expands, $recurrences);

        if ($freeBusy !== null) {
            throw new BadRequest(
                'A CALDAV:expand comes before a CALDAV:limit-freebusy-set: RFC 4791 §9.6 writes that order.',
            );
        }

        return Expander::of($element);
    }

    /**
     * @throws BadRequest If a second one arrives, one arrives beside a
     *                    `CALDAV:expand`, or one arrives after the free/busy
     *                    limit
     */
    private static function theOnlyRecurrenceLimit(
        ?TimeRange $recurrences,
        Element $element,
        ?TimeRange $freeBusy,
        ?Expander $expands,
    ): TimeRange {
        self::mustBeTheFirstOfTheChoice($expands, $recurrences);

        if ($freeBusy !== null) {
            throw new BadRequest(
                'A CALDAV:limit-recurrence-set comes before a CALDAV:limit-freebusy-set: RFC 4791 §9.6 writes that order.',
            );
        }

        return TimeRange::ofBothEnds($element);
    }

    /**
     * @throws BadRequest If a second one arrives
     */
    private static function theOnlyFreeBusyLimit(?TimeRange $freeBusy, Element $element): TimeRange
    {
        if ($freeBusy !== null) {
            throw new BadRequest(
                'A CALDAV:calendar-data limits the free/busy periods once: RFC 4791 §9.6 spells it limit-freebusy-set?.',
            );
        }

        return TimeRange::ofBothEnds($element);
    }

    /**
     * §9.6: `(expand | limit-recurrence-set)?` — one of the two, once.
     *
     * @throws BadRequest If the choice has already been made
     */
    private static function mustBeTheFirstOfTheChoice(?Expander $expands, ?TimeRange $recurrences): void
    {
        if ($expands !== null || $recurrences !== null) {
            throw new BadRequest(
                'A CALDAV:calendar-data expands or limits the recurrences, once: RFC 4791 §9.6 spells it (expand | limit-recurrence-set)?.',
            );
        }
    }

    /**
     * @throws Forbidden If this is not the media type and version §5.2.4 names
     */
    private static function mustBeTheDataItKeeps(Element $element): void
    {
        // RFC 9110 §8.3.1 makes a media type's "type, subtype, and parameter
        // name tokens" case-insensitive. A version is a string the memo prints
        // as it is, and is compared that way.
        $contentType = strtolower($element->attribute('content-type') ?? Limits::MEDIA_TYPE);
        $version = $element->attribute('version') ?? Limits::VERSION;

        if ($contentType === Limits::MEDIA_TYPE && $version === Limits::VERSION) {
            return;
        }

        throw new Forbidden(
            sprintf(
                'This server keeps calendar data as "%s" version %s, not as "%s" version %s: the CALDAV:supported-calendar-data precondition of RFC 4791 §7.8.',
                Limits::MEDIA_TYPE,
                Limits::VERSION,
                $contentType,
                $version,
            ),
            Limits::SUPPORTED_DATA,
        );
    }
}
