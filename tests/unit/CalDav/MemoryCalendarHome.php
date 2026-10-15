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

namespace DavServices\Tests\Unit\CalDav;

use DavServices\CalDav\Calendar;
use DavServices\Dav\IExtendedCollection;
use DavServices\Exception\Forbidden;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryPropertyCollection;
use DavServices\Xml\Element;

/**
 * A collection that makes calendars, as an application's store does.
 *
 * **This is the half of RFC 4791 §5.3.1 the library does not write.**
 * `MKCALENDAR` hands the whole request to the collection that is to hold the
 * new member — the kind and the properties in one call — because the method
 * demands that much: "Instructions MUST either all be executed or none
 * executed", and "If a MKCALENDAR request fails, the server state preceding
 * the request MUST be restored". What a store can make is the store's
 * business, so the library brings the method, the resource type and §4.2's
 * rules, and a collection like this one brings the storage.
 *
 * It keeps the properties it is handed, which is how a test can see that the
 * initial `DAV:set` arrived rather than taking the `201` on trust.
 *
 * It refuses any other kind, which is what a store owes: the method turns that
 * into `CALDAV:calendar-collection-location-ok`.
 */
final class MemoryCalendarHome extends MemoryCollection implements IExtendedCollection
{
    /**
     * Makes a calendar, and nothing else.
     *
     * @param list<string> $resourceTypes
     * @param array<string, Element|string|null> $properties
     *
     * @throws Forbidden If asked for a kind this store does not keep
     */
    public function createExtendedCollection(string $name, array $resourceTypes, array $properties): void
    {
        $kinds = array_values(array_diff($resourceTypes, ['{DAV:}collection']));

        if ($kinds !== [Calendar::CALENDAR]) {
            throw new Forbidden('Only calendars are made here.');
        }

        $store = new MemoryPropertyCollection($name);

        foreach ($properties as $property => $value) {
            $store->patchProperties([$property => $value]);
        }

        $this->add(Calendar::of($store));
    }
}
