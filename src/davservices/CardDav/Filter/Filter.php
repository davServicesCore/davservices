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

use DavServices\Dav\IQueryFilter;
use DavServices\Exception\BadRequest;
use DavServices\VObject\Component;
use DavServices\Xml\Element;

/**
 * What a client is searching for (RFC 6352 §10.5).
 *
 *     <!ELEMENT filter (prop-filter*)>
 *     <!ATTLIST filter test (anyof | allof) "anyof">
 *
 * "The 'filter' element specifies the search filter used to match address
 * objects that should be returned by a report. The 'test' attribute specifies
 * whether any (logical OR) or all (logical AND) of the prop-filter tests need
 * to match in order for the overall filter to match."
 *
 * **A filter with no conditions means what the two tests mean over nothing.**
 * The DTD admits it — `(prop-filter*)` — and the sentence above settles it
 * without a rule of its own: an OR of nothing is false, an AND of nothing is
 * true. So an empty filter returns no card at all, and an empty `allof` filter
 * returns every one. Reading the first as "no restriction" would be reading
 * `anyof` as something other than the logical OR this section calls it.
 */
final class Filter implements IQueryFilter
{
    public const NAME = '{urn:ietf:params:xml:ns:carddav}filter';

    /**
     * @param list<PropFilter> $properties
     */
    private function __construct(
        private readonly Test $test,
        private readonly array $properties,
    ) {
    }

    /**
     * @throws BadRequest If the `test` attribute says something the DTD does
     *                    not admit, or a child is one it does not name
     */
    public static function of(Element $element): self
    {
        $properties = [];

        foreach ($element->children() as $child) {
            if ($child->name() !== PropFilter::NAME) {
                throw new BadRequest(sprintf(
                    '%s has no place in a CARDDAV:filter of RFC 6352 §10.5.',
                    $child->name(),
                ));
            }

            $properties[] = PropFilter::of($child);
        }

        return new self(Test::of($element->attribute('test')), $properties);
    }

    /**
     * Whether this card is one the client is looking for.
     */
    public function holds(Component $card): bool
    {
        $outcomes = [];

        foreach ($this->properties as $filter) {
            $outcomes[] = $filter->holds($card);
        }

        return $this->test->holds($outcomes);
    }
}
