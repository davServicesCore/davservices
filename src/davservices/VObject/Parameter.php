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

namespace DavServices\VObject;

/**
 * One parameter of a property, with everything it says.
 *
 *     param = param-name "=" param-value *("," param-value)
 *
 * So a parameter is a name and a list — and the list may be empty, which is
 * what vCard 2.1's `TEL;HOME;VOICE:` leaves behind. Null and the empty string
 * are different answers there: one says the parameter said nothing at all,
 * the other that it said nothing in particular.
 *
 * **It holds no rules of its own.** What a particular parameter *means* is
 * the property's business — `TZID` names a time zone, `VALUE` names a type —
 * and which spellings are allowed at all is the validation of P4-06.
 *
 * The one rule that is here is the one RFC 5545 §3.5 states outright:
 * "Property names, parameter names, and enumerated parameter values are
 * case-insensitive." {@see self::named()} is where that lives. The spelling
 * it was written with is kept all the same: RFC 6350 §3.3 recommends upper
 * case **on output**, which is a decision for the serialiser rather than
 * something to take away from whoever reads the file.
 */
final class Parameter
{
    /** @var list<string> */
    private readonly array $values;

    /**
     * @param string $name As it was written
     * @param list<string> $values Every value it carries, in order. Empty
     *                             where the parameter was written without
     *                             any, as vCard 2.1 does
     */
    public function __construct(
        private readonly string $name,
        array $values = [],
    ) {
        $this->values = $values;
    }

    /**
     * The name, spelled as it was written.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Every value, in the order they were written.
     *
     * @return list<string>
     */
    public function values(): array
    {
        return $this->values;
    }

    /**
     * The first value, or null where the parameter has none.
     *
     * Most parameters carry exactly one — `TZID` names one time zone,
     * `VALUE` one type — and a caller asking about those should not have to
     * take a list apart to find out.
     */
    public function value(): ?string
    {
        return $this->values[0] ?? null;
    }

    /**
     * Whether this is the parameter somebody is asking for.
     *
     * RFC 5545 §3.5: "Property names, parameter names, and enumerated
     * parameter values are case-insensitive." A caller asking for `TZID`
     * means the one written `tzid`.
     */
    public function named(string $name): bool
    {
        return strcasecmp($this->name, $name) === 0;
    }
}
