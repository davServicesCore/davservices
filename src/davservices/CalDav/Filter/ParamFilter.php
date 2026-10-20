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

use DavServices\Exception\BadRequest;
use DavServices\VObject\Property;
use DavServices\Xml\Element;

/**
 * A condition on one parameter of one property (RFC 4791 §9.7.3).
 *
 *     <!ELEMENT param-filter (is-not-defined | text-match?)>
 *     <!ATTLIST param-filter name CDATA #REQUIRED>
 *
 * §9.7.3 states when it holds, and states two of the three cases its own DTD
 * admits:
 *
 * > A calendar property parameter is said to match a CALDAV:param-filter if:
 * >
 * > *  The CALDAV:param-filter XML element is empty and a parameter of the
 * >    type specified by the "name" attribute exists on the calendar property
 * >    being examined;
 * >
 * > or:
 * >
 * > *  The CALDAV:param-filter XML element contains a CALDAV:is-not-defined
 * >    XML element and no parameter of the type specified by the "name"
 * >    attribute exists on the calendar property being examined;
 *
 * ## The third case the memo leaves out
 *
 * The DTD admits a `text-match`, and those two bullets say nothing about what
 * one means. The memo for cards states the same case in as many words —
 * RFC 6352 §10.5.2: "A parameter of the type specified by the 'name' attribute
 * exists, and the CARDDAV:param-filter is empty, or it matches the
 * CARDDAV:text-match conditions if specified" — and nothing in RFC 4791 points
 * anywhere else. So a `text-match` asks about the parameter's value, and the
 * parameter still has to be there: the sentence begins with "exists".
 *
 * **It asks about one property, not about the object.** The element sits
 * inside a `prop-filter`, and that is what picks the properties this is put
 * to — so an event whose first `ATTENDEE` has accepted and whose second has
 * not answers this question twice, once per property.
 */
final readonly class ParamFilter
{
    public const NAME = '{urn:ietf:params:xml:ns:caldav}param-filter';

    public const NOT_DEFINED = '{urn:ietf:params:xml:ns:caldav}is-not-defined';

    private function __construct(
        private string $name,
        private ?TextMatch $match,
        private bool $mustBeAbsent,
    ) {
    }

    /**
     * @throws BadRequest If the `name` attribute is missing, or the element
     *                    carries more children than the DTD admits, or one the
     *                    DTD does not name
     */
    public static function of(Element $element): self
    {
        $name = $element->attribute('name') ?? throw new BadRequest(
            'A CALDAV:param-filter says which parameter it is about: RFC 4791 §9.7.3 makes "name" #REQUIRED.',
        );

        $children = $element->children();

        if (count($children) > 1) {
            throw new BadRequest(
                'A CALDAV:param-filter carries one child or none: RFC 4791 §9.7.3 spells it (is-not-defined | text-match?).',
            );
        }

        $child = $children[0] ?? null;

        if ($child === null) {
            return new self($name, null, false);
        }

        return match ($child->name()) {
            self::NOT_DEFINED => new self($name, null, true),
            TextMatch::NAME => new self($name, TextMatch::of($child), false),
            default => throw new BadRequest(sprintf(
                '%s has no place in a CALDAV:param-filter of RFC 4791 §9.7.3.',
                $child->name(),
            )),
        };
    }

    /**
     * Whether this property satisfies the condition.
     */
    public function holds(Property $property): bool
    {
        $parameter = $property->parameter($this->name);

        if ($this->mustBeAbsent) {
            return $parameter === null;
        }

        if ($parameter === null) {
            return false;
        }

        if ($this->match === null) {
            return true;
        }

        // **A parameter may carry several values** — RFC 5545 §3.2 writes
        // `param = param-name "=" param-value *("," param-value)` — and any
        // of them satisfying the match satisfies the filter. Comparing the
        // list as one string would make the answer depend on the order a
        // writer happened to use.
        foreach ($parameter->values() as $value) {
            if ($this->match->holds($value)) {
                return true;
            }
        }

        return false;
    }
}
