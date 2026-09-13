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

namespace DavServices\Dav;

use DavServices\Exception\Conflict;
use DavServices\Exception\Forbidden;

/**
 * A collection that can create members of a kind other than its own.
 *
 * This is what the extended `MKCOL` of RFC 5689 needs, and with it `MKCALENDAR`
 * and the creation of address books: the client says what resource types the
 * new collection is to have and what properties to set on it, in one request
 * that either succeeds whole or changes nothing.
 */
interface IExtendedCollection extends ICollection
{
    /**
     * Creates a collection of the given kind.
     *
     * @param list<string> $resourceTypes The `DAV:resourcetype` children
     *                                    as `{namespace}localname`, such
     *                                    as `{urn:ietf:params:xml:ns:caldav}calendar`
     * @param array<string, mixed> $properties The properties of the request's
     *                                         `DAV:set`, keyed the same way
     *
     * @throws Forbidden If the collection will not take one of that kind
     * @throws Conflict If a member of that name is already there
     */
    public function createExtendedCollection(string $name, array $resourceTypes, array $properties): void;
}
