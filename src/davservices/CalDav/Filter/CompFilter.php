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

namespace DavServices\CalDav\Filter;

use DavServices\CalDav\Moments;
use DavServices\CalDav\TimeRange;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use DavServices\VObject\Component;
use DavServices\VObject\ParseError;
use DavServices\VObject\Recur\Instance;
use DavServices\VObject\Recur\Iterator;
use DavServices\VObject\Recur\ObjectSet;
use DavServices\VObject\Recur\TooManyIterations;
use DavServices\Xml\Element;

/**
 * A condition on a component, and on everything inside it (RFC 4791 §9.7.1).
 *
 *     <!ELEMENT comp-filter (is-not-defined | (time-range?,
 *                            prop-filter*, comp-filter*))>
 *     <!ATTLIST comp-filter name CDATA #REQUIRED>
 *
 * §9.7.1 states when it holds:
 *
 * > A CALDAV:comp-filter is said to match if:
 * >
 * > *  The CALDAV:comp-filter XML element is empty and the calendar object or
 * >    calendar component type specified by the "name" attribute exists in the
 * >    current scope;
 * >
 * > or:
 * >
 * > *  The CALDAV:comp-filter XML element contains a CALDAV:is-not-defined XML
 * >    element and the calendar object or calendar component type specified by
 * >    the "name" attribute does not exist in the current scope;
 * >
 * > or:
 * >
 * > *  The CALDAV:comp-filter XML element contains a CALDAV:time-range XML
 * >    element and at least one recurrence instance in the targeted calendar
 * >    component is scheduled to overlap the specified time range, and all
 * >    specified CALDAV:prop-filter and CALDAV:comp-filter child XML elements
 * >    also match the targeted calendar component;
 * >
 * > or:
 * >
 * > *  The CALDAV:comp-filter XML element only contains CALDAV:prop-filter and
 * >    CALDAV:comp-filter child XML elements that all match the targeted
 * >    calendar component.
 *
 * ## The scope is what makes it recursive
 *
 * > The scope of the CALDAV:comp-filter XML element is the calendar object
 * > when used as a child of the CALDAV:filter XML element. The scope of the
 * > CALDAV:comp-filter XML element is the enclosing calendar component when
 * > used as a child of another CALDAV:comp-filter XML element.
 *
 * Two sentences, two ways in: {@see holdsTheObject()} asks the name of the
 * object itself, and the filters inside it ask it of the components of
 * whatever matched. So a client sends `VCALENDAR` at the top and reaches an
 * alarm in three steps, as deep as it likes (R-CAL-10).
 *
 * ## "The targeted calendar component" is one component
 *
 * One component has to satisfy the whole condition. An object holding an event
 * with the right summary and another event in the right week does not match a
 * filter asking for both — the bullets ask about "the targeted calendar
 * component", and two components are two answers rather than half of one
 * each.
 *
 * **And all of the children, not any of them.** RFC 4791 gives a
 * `comp-filter` no `test` attribute — that is RFC 6352 §10.5's addition for
 * cards — so there is no asking for any of several.
 *
 * ## "At least one recurrence instance" means the series, not the master
 *
 * A weekly meeting written in January is in a range in March, because one of
 * its instances is. A filter that asked only the component as written would
 * tell a client its recurring appointment does not exist.
 *
 * **The range is moved rather than the instance rebuilt.** Asking whether a
 * range overlaps an instance that begins Δ after its component is the same as
 * asking whether the range moved back by Δ overlaps that component — so
 * §9.9's tables are used exactly as they stand, and writing out an instance is
 * left to the chunk that is about writing them (`CALDAV:expand`, §9.6.5,
 * R-CAL-14). An override brings its own component and its own start, so its Δ
 * is nothing and the range is asked as it is.
 *
 * **A series this library cannot expand is asked as it stands.** An `RRULE`
 * nobody can read is invalid iCalendar, which `CALDAV:valid-calendar-data`
 * refuses on the way in — and a filter that fell over on one such object would
 * answer nothing about the rest of the calendar. The same goes for a series
 * that runs past R-RRULE-04's hard limit.
 *
 * ## A nesting iCalendar has not got is refused
 *
 * §7.8 gives the case as its first example of an invalid filter: "a
 * CALDAV:filter cannot nest a <C:comp name='VEVENT'> element in a <C:comp
 * name='VTODO'> element." Such a filter asks about a component that cannot
 * exist, and "nothing matched" would let a client's typo look like an empty
 * calendar — so it is refused with `CALDAV:valid-filter` where it is read, by
 * {@see nestedIn()} against RFC 5545 §3.6's own content models.
 *
 * **The outermost filter's own name is not judged that way.** §9.7.1 scopes it
 * to the calendar object, so a client that sent `VEVENT` there has asked
 * whether the object is one — a question with an answer, which is no, rather
 * than a nesting the grammar rules out. The memo gives two examples of an
 * invalid filter and this is neither.
 */
final readonly class CompFilter
{
    public const NAME = '{urn:ietf:params:xml:ns:caldav}comp-filter';

    /**
     * What RFC 5545 §3.6 writes inside a component, by component.
     *
     * **A filter that nests a component where the grammar puts none describes
     * something that cannot exist**, which is §7.8's first example of an
     * invalid filter: "a CALDAV:filter cannot nest a <C:comp name='VEVENT'>
     * element in a <C:comp name='VTODO'> element". §3.4's `icalbody` holds the
     * five a calendar object is made of, `eventc` and `todoc` hold `alarmc`,
     * and `timezonec` holds `standardc` and `daylightc`. Nothing else in §3.6
     * holds a component at all, and the empty lists say so rather than leaving
     * a reader to infer it from an absence.
     *
     * **A name this table has not got is not judged.** §3.4 makes `iana-comp`
     * and `x-comp` part of a calendar object and says nothing about what
     * either holds, so refusing a filter about one would be this library
     * guessing at somebody else's extension — and §7.8 is about a filter that
     * cannot be, not one nobody here knows.
     *
     * @var array<string, list<string>>
     */
    private const HOLDS = [
        'VCALENDAR' => ['VEVENT', 'VTODO', 'VJOURNAL', 'VFREEBUSY', 'VTIMEZONE'],
        'VEVENT' => ['VALARM'],
        'VTODO' => ['VALARM'],
        'VJOURNAL' => [],
        'VFREEBUSY' => [],
        'VTIMEZONE' => ['STANDARD', 'DAYLIGHT'],
        'VALARM' => [],
        'STANDARD' => [],
        'DAYLIGHT' => [],
    ];

    /**
     * @param list<PropFilter> $props
     * @param list<self> $comps
     */
    private function __construct(
        private string $name,
        private ?TimeRange $range,
        private array $props,
        private array $comps,
        private bool $mustBeAbsent,
    ) {
    }

    /**
     * @throws BadRequest If the `name` attribute is missing, or the children
     *                    are not what the DTD admits in the order it writes
     *                    them
     * @throws Forbidden If a nested filter is about a component RFC 5545 puts
     *                   nowhere inside this one (§7.8's `CALDAV:valid-filter`)
     */
    public static function of(Element $element): self
    {
        $name = $element->attribute('name') ?? throw new BadRequest(
            'A CALDAV:comp-filter says which component it is about: RFC 4791 §9.7.1 makes "name" #REQUIRED.',
        );

        $children = $element->children();

        if (($children[0] ?? null)?->name() === ParamFilter::NOT_DEFINED) {
            if (count($children) > 1) {
                throw new BadRequest(
                    'A CALDAV:comp-filter with an is-not-defined carries nothing else: RFC 4791 §9.7.1 makes it a choice.',
                );
            }

            return new self($name, null, [], [], true);
        }

        $range = null;
        $props = [];
        $comps = [];

        foreach ($children as $child) {
            match ($child->name()) {
                TimeRange::NAME => $range = self::theOnlyRange($range, $child, $props, $comps),
                PropFilter::NAME => $props[] = self::aPropFilter($child, $comps),
                self::NAME => $comps[] = self::nestedIn($name, $child),
                default => throw new BadRequest(sprintf(
                    '%s has no place in a CALDAV:comp-filter of RFC 4791 §9.7.1.',
                    $child->name(),
                )),
            };
        }

        return new self($name, $range, $props, $comps, false);
    }

    /**
     * One nested filter, where a component of that kind can be there at all.
     *
     * The names are compared without regard to case, as RFC 5545 §3.1 and
     * RFC 5234 §2.3 ask, and the refusal quotes what the client wrote.
     *
     * @param string $enclosing The component this one is nested in
     *
     * @throws BadRequest If the nested filter is not one §9.7.1's DTD admits
     * @throws Forbidden If RFC 5545 puts no such component inside that one
     */
    private static function nestedIn(string $enclosing, Element $element): self
    {
        $inner = $element->attribute('name') ?? '';
        $held = self::HOLDS[strtoupper($enclosing)] ?? null;

        // A name of neither kind is a question for the DTD, which `of()`
        // answers: a nameless filter is refused there with a `400`.
        if ($held !== null && isset(self::HOLDS[strtoupper($inner)]) && !in_array(strtoupper($inner), $held, true)) {
            throw new Forbidden(
                sprintf(
                    'RFC 5545 §3.6 writes no %s inside a %s, so a CALDAV:filter cannot nest one either: the CALDAV:valid-filter precondition of RFC 4791 §7.8.',
                    $inner,
                    $enclosing,
                ),
                Filter::VALID,
            );
        }

        return self::of($element);
    }

    /**
     * Whether the object satisfies this filter, which is the outermost one.
     *
     * §9.7.1's first sentence about scope: a filter that is a child of
     * `CALDAV:filter` is about the calendar object, so the name is asked of
     * the object itself.
     */
    public function holdsTheObject(Component $object, Moments $moments): bool
    {
        if (strcasecmp($object->name(), $this->name) !== 0) {
            return $this->mustBeAbsent;
        }

        return !$this->mustBeAbsent && $this->isSatisfiedBy($object, $object, $moments);
    }

    /**
     * Whether this scope holds a component that satisfies the filter.
     *
     * §9.7.1's second sentence: a filter inside another is about the
     * components of whatever that one matched.
     */
    private function holdsIn(Component $scope, Component $object, Moments $moments): bool
    {
        $found = $scope->components($this->name);

        if ($this->mustBeAbsent) {
            return $found === [];
        }

        foreach ($found as $component) {
            if ($this->isSatisfiedBy($component, $object, $moments)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether one component satisfies the range, every property test and every
     * nested filter — the same component for all three.
     */
    private function isSatisfiedBy(Component $component, Component $object, Moments $moments): bool
    {
        foreach ($this->props as $prop) {
            if (!$prop->holds($component, $moments)) {
                return false;
            }
        }

        foreach ($this->comps as $comp) {
            if (!$comp->holdsIn($component, $object, $moments)) {
                return false;
            }
        }

        return $this->range === null || self::anInstanceOverlaps($this->range, $component, $object, $moments);
    }

    /**
     * Whether at least one instance of this component overlaps the range.
     *
     * The reasoning for moving the range rather than writing out the instance
     * is on the class above.
     */
    private static function anInstanceOverlaps(
        TimeRange $range,
        Component $component,
        Component $object,
        Moments $moments,
    ): bool {
        $uid = $component->property('UID')?->value();

        // Without a `UID` there is no series to ask for — §4.1 and RFC 5545
        // §3.6.1 both make one mandatory, so such a component is invalid
        // iCalendar and is asked as it stands.
        if ($uid === null || $uid === '') {
            return $range->overlaps($component, $moments);
        }

        try {
            foreach (ObjectSet::of($object, $uid, Iterator::ITERATIONS)->instances() as $instance) {
                if (self::reaches($range, $instance, $moments)) {
                    return true;
                }
            }
        } catch (ParseError | TooManyIterations) {
            return $range->overlaps($component, $moments);
        }

        return false;
    }

    /**
     * Whether the range reaches one instance of a series.
     *
     * The instance's own component is asked — the master for an ordinary
     * instance, the overriding component for an overridden one — with the
     * range moved by the gap between where that component begins and where
     * this instance does. For an override the two are the same value, so the
     * gap is nothing.
     *
     * **The instance's start is read as the property it would be written as**,
     * which {@see Moments::ofTheInstance()} does: a value carries no zone of
     * its own, and the one that applies is the one the component's own
     * `DTSTART` named.
     *
     * **Where either of the two is no moment, the component is asked as it
     * stands.** That happens where a start falls in an hour the clocks skip —
     * RFC 5545 §3.3.10's "nonexistent local time" — and the answer is the same
     * one a moved range would give: §9.9's tables read the component's own
     * `DTSTART`, so a component whose start is no moment overlaps nothing
     * however the range is moved. Finding such a series would mean writing its
     * instances out with their own resolved starts, which is what
     * `CALDAV:expand` does (§9.6.5, R-CAL-14).
     */
    private static function reaches(TimeRange $range, Instance $instance, Moments $moments): bool
    {
        $component = $instance->component();
        $begins = $moments->of($component->property('DTSTART'));
        $at = $moments->ofTheInstance($component, $instance->start());

        if ($begins === null || $at === null) {
            return $range->overlaps($component, $moments);
        }

        return $range->moved($begins->getTimestamp() - $at->getTimestamp())->overlaps($component, $moments);
    }

    /**
     * @param list<PropFilter> $props
     * @param list<self> $comps
     *
     * @throws BadRequest If a second range arrives, or one arrives late
     */
    private static function theOnlyRange(?TimeRange $range, Element $child, array $props, array $comps): TimeRange
    {
        if ($range !== null) {
            throw new BadRequest(
                'A CALDAV:comp-filter carries one time-range at most: RFC 4791 §9.7.1 spells it time-range?.',
            );
        }

        if ($props !== [] || $comps !== []) {
            throw new BadRequest(
                'A time-range comes before the filters of a CALDAV:comp-filter: RFC 4791 §9.7.1 writes that order.',
            );
        }

        return TimeRange::of($child);
    }

    /**
     * @param list<self> $comps
     *
     * @throws BadRequest If it arrives after a nested filter
     */
    private static function aPropFilter(Element $child, array $comps): PropFilter
    {
        if ($comps !== []) {
            throw new BadRequest(
                'A prop-filter comes before the comp-filters of a CALDAV:comp-filter: RFC 4791 §9.7.1 writes that order.',
            );
        }

        return PropFilter::of($child);
    }
}
