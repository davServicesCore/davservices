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

namespace DavServices\Tests\Unit\Dav;

use DavServices\Dav\IProperties;
use DavServices\Xml\Element;

/**
 * A collection that keeps a property of its own.
 *
 * Two properties are why this double exists, and both are stored rather than
 * computed: `CARDDAV:addressbook-description`, of which RFC 6352 §6.2.1 says it
 * "SHOULD NOT be protected so that users can specify a description", and
 * `CALDAV:calendar-description`, which RFC 4791 §5.2.1 has carry "a description
 * of the calendar collection that is suitable for presentation to a user". A
 * wrapper around a store has to pass whatever that store keeps straight
 * through, and these are what it would take away by claiming `IProperties`
 * where there is nothing behind it.
 */
final class MemoryPropertyCollection extends MemoryCollection implements IProperties
{
    /** @var array<string, Element|string|null> */
    private array $kept = ['{DAV:}displayname' => 'Contacts'];

    public function propertyNames(): array
    {
        return array_keys($this->kept);
    }

    public function properties(array $names): array
    {
        return array_intersect_key($this->kept, array_flip($names));
    }

    public function patchProperties(array $mutations): array
    {
        $statuses = [];

        foreach ($mutations as $name => $value) {
            $this->kept[$name] = $value;
            $statuses[$name] = 200;
        }

        return $statuses;
    }
}
