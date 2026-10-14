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

namespace DavServices\CardDav\Filter;

use DavServices\Exception\BadRequest;
use DavServices\VObject\Parameter;
use DavServices\VObject\Property;
use DavServices\Xml\Element;

/**
 * A condition on one parameter of one property (RFC 6352 §10.5.2).
 *
 *     <!ELEMENT param-filter (is-not-defined | text-match)?>
 *     <!ATTLIST param-filter name CDATA #REQUIRED>
 *
 * §10.5.2 states when it holds, and the two halves are worth keeping apart:
 *
 * > A vCard property is said to match a CARDDAV:param-filter if:
 * >
 * > *  A parameter of the type specified by the "name" attribute exists, and
 * >    the CARDDAV:param-filter is empty, or it matches the CARDDAV:text-match
 * >    conditions if specified.
 * >
 * > or:
 * >
 * > *  A parameter of the type specified by the "name" attribute does not
 * >    exist, and the CARDDAV:is-not-defined element is specified.
 *
 * **It asks about one property, not about the card.** The element sits inside
 * a `prop-filter`, and that is what picks the properties this is put to — so a
 * card whose first `TEL` is a work number and whose second is a home one
 * answers this question twice, once per property.
 */
final class ParamFilter
{
    public const NAME = '{urn:ietf:params:xml:ns:carddav}param-filter';

    private const NOT_DEFINED = '{urn:ietf:params:xml:ns:carddav}is-not-defined';

    private function __construct(
        private readonly string $name,
        private readonly ?TextMatch $match,
        private readonly bool $mustBeAbsent,
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
            'A CARDDAV:param-filter says which parameter it is about: RFC 6352 §10.5.2 makes "name" #REQUIRED.',
        );

        $children = $element->children();

        if (count($children) > 1) {
            throw new BadRequest(
                'A CARDDAV:param-filter carries one child or none: RFC 6352 §10.5.2 spells it (is-not-defined | text-match)?.',
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
                '%s has no place in a CARDDAV:param-filter of RFC 6352 §10.5.2.',
                $child->name(),
            )),
        };
    }

    /**
     * Whether this property satisfies the condition.
     */
    public function holds(Property $property): bool
    {
        $parameters = $this->parametersOf($property);

        if ($this->mustBeAbsent) {
            return $parameters === [];
        }

        if ($parameters === []) {
            return false;
        }

        return $this->match === null || self::matchedBy($this->match, $parameters);
    }

    /**
     * Every parameter of the name this filter asks about.
     *
     * The reader folds a name written twice into one parameter carrying both
     * values, but `Property` admits two of one name, so this takes all of them
     * rather than the first: otherwise the same filter would answer differently
     * depending on whether the property had been read or built.
     *
     * @return list<Parameter>
     */
    private function parametersOf(Property $property): array
    {
        $found = [];

        foreach ($property->parameters() as $parameter) {
            if ($parameter->named($this->name)) {
                $found[] = $parameter;
            }
        }

        return $found;
    }

    /**
     * Whether the text match holds for any one value.
     *
     * **The values are asked one at a time**, because that is what they are: a
     * comma in `TYPE=work,voice` separates two values rather than belonging to
     * either, so joining them back together would let a search for `k,v` find
     * a card that never said it.
     *
     * A parameter written without an equals sign carries no value at all — a
     * form RFC 6350's ABNF does not admit, which reaches this library from
     * vCard 2.1 — and then there is nothing for the match to hold on, not even
     * an empty search.
     *
     * @param list<Parameter> $parameters
     */
    private static function matchedBy(TextMatch $match, array $parameters): bool
    {
        foreach ($parameters as $parameter) {
            foreach ($parameter->values() as $value) {
                if ($match->holds($value)) {
                    return true;
                }
            }
        }

        return false;
    }
}
