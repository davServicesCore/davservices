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

namespace DavServices\Tests\Unit\CardDav;

use DavServices\CardDav\AddressBook;
use DavServices\Dav\IExtendedCollection;
use DavServices\Exception\Forbidden;
use DavServices\Tests\Unit\Dav\MemoryCollection;

/**
 * A collection that makes address books, as an application's store does.
 *
 * **This is the half of RFC 6352 §6.3.1 the library does not write.** The
 * extended `MKCOL` of RFC 5689 hands the whole request to the collection that
 * is to hold the new member — the kind and the properties in one call — and
 * what a store can make is the store's business. So the library brings the
 * method, the resource type and §5.2's rules, and a collection like this one
 * brings the storage.
 *
 * It refuses any other kind, which is what a store owes: `Dav\Method\MkCol`
 * turns that into RFC 5689's `DAV:valid-resourcetype`.
 */
final class MemoryAddressBookHome extends MemoryCollection implements IExtendedCollection
{
    /**
     * Makes an address book, and nothing else.
     *
     * @param list<string> $resourceTypes
     * @param array<string, mixed> $properties
     *
     * @throws Forbidden If asked for a kind this store does not keep
     */
    public function createExtendedCollection(string $name, array $resourceTypes, array $properties): void
    {
        $kinds = array_values(array_diff($resourceTypes, ['{DAV:}collection']));

        if ($kinds !== [AddressBook::ADDRESSBOOK]) {
            throw new Forbidden('Only address books are made here.');
        }

        $this->add(AddressBook::of(new MemoryCollection($name)));
    }
}
