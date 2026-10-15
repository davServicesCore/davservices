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

namespace DavServices\CardDav;

use DavServices\VObject\Property;

/**
 * A vCard property as a client names it, group prefix and all.
 *
 * **RFC 6352 states the rule twice, word for word** — in §10.5.1 for a
 * `CARDDAV:prop-filter` and in §10.4.2 for a `CARDDAV:prop` inside
 * `CARDDAV:address-data`:
 *
 * > vCard allows a "group" prefix to appear before a property name in the vCard
 * > data. When the "name" attribute does not specify a group prefix, it MUST
 * > match properties in the vCard data without a group prefix or with any group
 * > prefix. When the "name" attribute includes a group prefix, it MUST match
 * > properties that have exactly the same group prefix and name. For example, a
 * > "name" set to "TEL" will match "TEL", "X-ABC.TEL", "X-ABC-1.TEL" vCard
 * > properties. A "name" set to "X-ABC.TEL" will match an "X-ABC.TEL" vCard
 * > property only, it will not match "TEL" or "X-ABC-1.TEL".
 *
 * Said twice in the memo and therefore kept once here: two copies would be two
 * chances for a filter and the data it returns to disagree about which
 * properties a client meant.
 *
 * **The reader has already done the hard half.** It keeps the prefix in
 * {@see Property::group()} and the bare name in {@see Property::name()}, so a
 * name without a prefix needs nothing doing and a name with one is a second
 * comparison. RFC 6350's ABNF settles the spelling of both in its own comment
 * under the `group` production: "Group and name are case-insensitive."
 *
 * **A name no property could carry matches nothing, and needs no rule of its
 * own.** A group is `1*(ALPHA / DIGIT / "-")` and so is a name, so neither may
 * be empty and neither may hold a full stop — which leaves `.TEL`, `X-ABC.` and
 * `X-ABC.TEL.X` matching nothing at all, by the ordinary comparison.
 */
final class PropertyName
{
    private function __construct(
        private readonly ?string $group,
        private readonly string $name,
    ) {
    }

    /**
     * Reads what a client wrote in a `name` attribute.
     */
    public static function of(string $name): self
    {
        $at = strpos($name, '.');

        if ($at === false) {
            return new self(null, $name);
        }

        return new self(substr($name, 0, $at), substr($name, $at + 1));
    }

    /**
     * Whether this is a property the client named.
     */
    public function matches(Property $property): bool
    {
        if (strcasecmp($property->name(), $this->name) !== 0) {
            return false;
        }

        if ($this->group === null) {
            return true;
        }

        $group = $property->group();

        return $group !== null && strcasecmp($group, $this->group) === 0;
    }
}
