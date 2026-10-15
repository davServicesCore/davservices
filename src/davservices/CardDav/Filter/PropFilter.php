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

use DavServices\CardDav\PropertyName;
use DavServices\Exception\BadRequest;
use DavServices\VObject\Component;
use DavServices\VObject\Property;
use DavServices\VObject\Value\Text;
use DavServices\Xml\Element;

/**
 * A condition on one property of a card (RFC 6352 §10.5.1).
 *
 *     <!ELEMENT prop-filter (is-not-defined |
 *                            (text-match*, param-filter*))>
 *     <!ATTLIST prop-filter name CDATA #REQUIRED
 *                           test (anyof | allof) "anyof">
 *
 * §10.5.1 states when a card matches, in two bullets:
 *
 * > *  A vCard property of the type specified by the "name" attribute exists,
 * >    and the CARDDAV:prop-filter is empty, or it matches any specified
 * >    CARDDAV:text-match or CARDDAV:param-filter conditions.
 * >
 * > or:
 * >
 * > *  A vCard property of the type specified by the "name" attribute does not
 * >    exist, and the CARDDAV:is-not-defined element is specified.
 *
 * **The conditions are put to one property at a time.** The memo says "a vCard
 * property […] exists, and […] *it* matches", so a card satisfies the filter
 * when any single one of its properties of that name satisfies every condition
 * the `test` attribute asks for — not when the card as a whole does between
 * several of them. The difference shows under `allof`: a card whose home
 * number is not its work number does not match a filter wanting the value of
 * one and the parameter of the other.
 */
final class PropFilter
{
    public const NAME = '{urn:ietf:params:xml:ns:carddav}prop-filter';

    private const NOT_DEFINED = '{urn:ietf:params:xml:ns:carddav}is-not-defined';

    /**
     * @param list<TextMatch> $matches
     * @param list<ParamFilter> $parameters
     */
    private function __construct(
        private readonly PropertyName $named,
        private readonly Test $test,
        private readonly array $matches,
        private readonly array $parameters,
        private readonly bool $mustBeAbsent,
    ) {
    }

    /**
     * @throws BadRequest If the `name` attribute is missing, the `test`
     *                    attribute says something the DTD does not admit, a
     *                    child is one the DTD does not name, or
     *                    `is-not-defined` arrives beside anything else
     */
    public static function of(Element $element): self
    {
        $name = $element->attribute('name') ?? throw new BadRequest(
            'A CARDDAV:prop-filter says which property it is about: RFC 6352 §10.5.1 makes "name" #REQUIRED.',
        );

        $matches = [];
        $parameters = [];
        $mustBeAbsent = false;

        foreach ($element->children() as $child) {
            if ($child->name() === self::NOT_DEFINED) {
                $mustBeAbsent = true;

                continue;
            }

            if ($child->name() === TextMatch::NAME) {
                $matches[] = TextMatch::of($child);

                continue;
            }

            if ($child->name() === ParamFilter::NAME) {
                $parameters[] = ParamFilter::of($child);

                continue;
            }

            throw new BadRequest(sprintf(
                '%s has no place in a CARDDAV:prop-filter of RFC 6352 §10.5.1.',
                $child->name(),
            ));
        }

        if ($mustBeAbsent && count($element->children()) > 1) {
            throw new BadRequest('A CARDDAV:prop-filter asking that a property be absent asks nothing else: RFC 6352 §10.5.1 spells is-not-defined as a choice against the conditions, not beside them.');
        }

        return new self(
            PropertyName::of($name),
            Test::of($element->attribute('test')),
            $matches,
            $parameters,
            $mustBeAbsent,
        );
    }

    /**
     * Whether this card satisfies the condition.
     */
    public function holds(Component $card): bool
    {
        $properties = $this->propertiesOf($card);

        if ($this->mustBeAbsent) {
            return $properties === [];
        }

        if ($properties === []) {
            return false;
        }

        // "the CARDDAV:prop-filter is empty": the property being there is the
        // whole question, and `test` has nothing to combine. It is asked here
        // rather than left to Test::holds() because the two tests answer an
        // empty list differently, and the memo wants this case matched.
        if ($this->matches === [] && $this->parameters === []) {
            return true;
        }

        foreach ($properties as $property) {
            if ($this->test->holds($this->outcomesFor($property))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The properties of the card this filter is about.
     *
     * Which those are is §10.5.1's group-prefix rule, and it lives in
     * {@see PropertyName} because §10.4.2 states the same rule again for the
     * data a report returns — two copies would be two chances to disagree
     * about which properties a client meant.
     *
     * @return list<Property>
     */
    private function propertiesOf(Component $card): array
    {
        $found = [];

        foreach ($card->properties() as $property) {
            if ($this->named->matches($property)) {
                $found[] = $property;
            }
        }

        return $found;
    }

    /**
     * How each condition answers for one property of the card.
     *
     * **The value is handed over as the card means it rather than as it is
     * written.** RFC 6350 §3.4 makes the backslash a way of putting a comma, a
     * semicolon or a line break inside a text value, not part of the value, so
     * a client searching for a name with a comma in it would otherwise have to
     * know how this server had escaped it.
     *
     * Undoing it whatever the property is costs nothing, because of what RFC
     * 6350 says about every other value type — "No additional content value
     * encoding […] is defined for this value type" stands under `BINARY`,
     * `BOOLEAN`, `FLOAT`, `INTEGER` and `URI` alike — and because none of
     * their grammars admits a backslash. In a legal value of any of them there
     * is nothing for the undoing to act on.
     *
     * @return list<bool>
     */
    private function outcomesFor(Property $property): array
    {
        $value = Text::decode($property->value());
        $outcomes = [];

        foreach ($this->matches as $match) {
            $outcomes[] = $match->holds($value);
        }

        foreach ($this->parameters as $filter) {
            $outcomes[] = $filter->holds($property);
        }

        return $outcomes;
    }
}
