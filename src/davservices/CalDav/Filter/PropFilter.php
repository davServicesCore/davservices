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
use DavServices\VObject\Property;
use DavServices\Xml\Element;

/**
 * A condition on one property of one component (RFC 4791 §9.7.2).
 *
 *     <!ELEMENT prop-filter (is-not-defined |
 *                            ((time-range | text-match)?,
 *                             param-filter*))>
 *     <!ATTLIST prop-filter name CDATA #REQUIRED>
 *
 * §9.7.2 states when it holds:
 *
 * > A calendar property is said to match a CALDAV:prop-filter if:
 * >
 * > *  The CALDAV:prop-filter XML element is empty and a property of the type
 * >    specified by the "name" attribute exists in the enclosing calendar
 * >    component;
 * >
 * > or:
 * >
 * > *  The CALDAV:prop-filter XML element contains a CALDAV:is-not-defined XML
 * >    element and no property of the type specified by the "name" attribute
 * >    exists in the enclosing calendar component;
 * >
 * > or:
 * >
 * > *  The CALDAV:prop-filter XML element contains a CALDAV:time-range XML
 * >    element and the property value overlaps the specified time range, and
 * >    all specified CALDAV:param-filter child XML elements also match the
 * >    targeted property;
 * >
 * > or:
 * >
 * > *  The CALDAV:prop-filter XML element contains a CALDAV:text-match XML
 * >    element and the property value matches it, and all specified
 * >    CALDAV:param-filter child XML elements also match the targeted
 * >    property;
 *
 * ## "The targeted property" is one property, not the name
 *
 * The phrase decides something a paraphrase would lose. A property may appear
 * as often as it likes — an event has as many `ATTENDEE` properties as it has
 * attendees — and the parameter tests are asked of **the same occurrence**
 * whose value matched. So a filter looking for Anne among the attendees who
 * have accepted does not match an event where Anne declined and Bert
 * accepted.
 *
 * And one occurrence satisfying the whole condition satisfies it: §9.7.2 asks
 * whether "a calendar property" is said to match, not whether every property
 * of that name is.
 *
 * ## The case the bullets leave out
 *
 * The DTD makes the value test optional and the parameter tests independent of
 * it, so `param-filter` children may stand alone — and the bullets above say
 * nothing about that. RFC 6352 §10.5.1 states the same case for cards: "A
 * property of the type specified by the 'name' attribute exists, and the
 * CARDDAV:prop-filter is empty, **or it matches any CARDDAV:text-match or
 * CARDDAV:param-filter conditions specified**." So the parameter tests are
 * asked whether or not a value test stands beside them.
 */
final readonly class PropFilter
{
    public const NAME = '{urn:ietf:params:xml:ns:caldav}prop-filter';

    /**
     * @param list<ParamFilter> $params
     */
    private function __construct(
        private string $name,
        private ?TextMatch $match,
        private ?TimeRange $range,
        private array $params,
        private bool $mustBeAbsent,
    ) {
    }

    /**
     * @throws BadRequest If the `name` attribute is missing, or the children
     *                    are not what the DTD admits in the order it writes
     *                    them
     * @throws Forbidden If a range is nested in a property §9.9 defines none
     *                   for (§7.8's `CALDAV:valid-filter`)
     */
    public static function of(Element $element): self
    {
        $name = $element->attribute('name') ?? throw new BadRequest(
            'A CALDAV:prop-filter says which property it is about: RFC 4791 §9.7.2 makes "name" #REQUIRED.',
        );

        $children = $element->children();

        if (($children[0] ?? null)?->name() === ParamFilter::NOT_DEFINED) {
            if (count($children) > 1) {
                throw new BadRequest(
                    'A CALDAV:prop-filter with an is-not-defined carries nothing else: RFC 4791 §9.7.2 makes it a choice.',
                );
            }

            return new self($name, null, null, [], true);
        }

        $match = null;
        $range = null;
        $params = [];

        foreach ($children as $child) {
            if ($child->name() === ParamFilter::NAME) {
                $params[] = ParamFilter::of($child);

                continue;
            }

            if ($params !== []) {
                throw new BadRequest(sprintf(
                    'A %s comes before the param-filters of a CALDAV:prop-filter: RFC 4791 §9.7.2 writes that order.',
                    $child->name(),
                ));
            }

            if ($match !== null || $range !== null) {
                throw new BadRequest(
                    'A CALDAV:prop-filter tests a value one of two ways: RFC 4791 §9.7.2 spells it (time-range | text-match)?.',
                );
            }

            match ($child->name()) {
                TextMatch::NAME => $match = TextMatch::of($child),
                TimeRange::NAME => $range = TimeRange::of($child),
                default => throw new BadRequest(sprintf(
                    '%s has no place in a CALDAV:prop-filter of RFC 4791 §9.7.2.',
                    $child->name(),
                )),
            };
        }

        // §7.8's second example of a filter that cannot be answered: "a
        // CALDAV:filter cannot nest a <C:time-range start="..." end="...">
        // element in a <C:prop name="SUMMARY"> element". §9.9 is the reason —
        // it names seven properties and says of everything else that "the
        // semantic of CALDAV:time-range is not defined", so there is no
        // question here to answer rather than an answer of "nothing matched".
        if ($range !== null && !TimeRange::isDefinedFor($name)) {
            throw new Forbidden(
                sprintf(
                    'RFC 4791 §9.9 defines no time range for a %s, so a CALDAV:prop-filter cannot nest one: the CALDAV:valid-filter precondition of §7.8.',
                    $name,
                ),
                Filter::VALID,
            );
        }

        return new self($name, $match, $range, $params, false);
    }

    /**
     * Whether this component satisfies the condition.
     */
    public function holds(Component $component, Moments $moments): bool
    {
        $properties = $component->properties($this->name);

        if ($this->mustBeAbsent) {
            return $properties === [];
        }

        foreach ($properties as $property) {
            if ($this->isSatisfiedBy($property, $moments)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether one occurrence satisfies the value test and every parameter
     * test — the same occurrence for both, which is what §9.7.2 means by "the
     * targeted property".
     */
    private function isSatisfiedBy(Property $property, Moments $moments): bool
    {
        if ($this->match !== null && !$this->match->holds($property->value())) {
            return false;
        }

        // Which property a range may be asked of is settled where the filter
        // is read, with §7.8's precondition — so by here the property is one
        // of §9.9's seven and the question is only whether its value falls in
        // the range.
        if ($this->range !== null && !$this->range->holds($moments->of($property))) {
            return false;
        }

        foreach ($this->params as $param) {
            if (!$param->holds($property)) {
                return false;
            }
        }

        return true;
    }
}
