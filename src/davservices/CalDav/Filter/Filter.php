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
use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use DavServices\VObject\Component;
use DavServices\Xml\Element;

/**
 * The whole of a search filter (RFC 4791 §9.7).
 *
 *     <!ELEMENT filter (comp-filter)>
 *
 * > The CALDAV:filter XML element specifies the search filter used to limit
 * > the calendar components returned by a calendaring REPORT request.
 *
 * **One child, and the DTD says so plainly**: no `?`, no `*`. A filter is the
 * question a report asks, and a question with none or two of them is not one —
 * so a body carrying either is a request this server cannot read rather than
 * one it guesses at.
 *
 * That one child is where the work is ({@see CompFilter}); this is the seam
 * between the report and the filter, and it exists so that the rule about
 * there being exactly one lives somewhere.
 */
final readonly class Filter
{
    public const NAME = '{urn:ietf:params:xml:ns:caldav}filter';

    /**
     * The precondition a filter that cannot be answered violates.
     *
     * §7.8: "The CALDAV:filter XML element (see Section 9.7) specified in the
     * REPORT request MUST be valid. For instance, a CALDAV:filter cannot nest
     * a <C:comp name="VEVENT"> element in a <C:comp name="VTODO"> element, and
     * a CALDAV:filter cannot nest a <C:time-range start="..." end="...">
     * element in a <C:prop name="SUMMARY"> element."
     *
     * **It is named here and raised where each half is read** — the first
     * example in {@see CompFilter}, the second in {@see PropFilter} — because
     * a nesting is judged where it is parsed, and a second walk over the same
     * body to judge it again could come to another conclusion.
     *
     * **A `400` would be the wrong answer.** Both bodies are well-formed and
     * both fit §9.7's DTD; what is wrong is what they ask for, and §7.8 gives
     * that its own precondition so a client can tell the two apart.
     */
    public const VALID = '{urn:ietf:params:xml:ns:caldav}valid-filter';

    private function __construct(private CompFilter $comp)
    {
    }

    /**
     * @throws BadRequest If the element does not carry exactly one
     *                    `comp-filter`, or carries a child of another name
     * @throws Forbidden If what is inside it cannot be answered, which is the
     *                   precondition {@see self::VALID} is for
     */
    public static function of(Element $element): self
    {
        $children = $element->children();

        if (count($children) !== 1) {
            throw new BadRequest(
                'A CALDAV:filter carries one CALDAV:comp-filter: RFC 4791 §9.7 spells it (comp-filter).',
            );
        }

        $child = $children[0];

        if ($child->name() !== CompFilter::NAME) {
            throw new BadRequest(sprintf(
                '%s has no place in a CALDAV:filter of RFC 4791 §9.7, which carries one CALDAV:comp-filter.',
                $child->name(),
            ));
        }

        return new self(CompFilter::of($child));
    }

    /**
     * Whether this object is one the report should return.
     *
     * §9.7.1 scopes the filter inside to the calendar object, which is what
     * {@see CompFilter::holdsTheObject()} answers.
     */
    public function holds(Component $object, Moments $moments): bool
    {
        return $this->comp->holdsTheObject($object, $moments);
    }
}
