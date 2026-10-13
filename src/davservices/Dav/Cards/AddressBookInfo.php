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

namespace DavServices\Dav\Cards;

/**
 * What is known about one address book collection (RFC 6352 §5.2).
 *
 * Three things, and each of them answers a question the protocol asks by name.
 *
 * **The name** is the member name inside the principal's address book home,
 * which is the last segment of the collection's URL — and §6.3.1 says a client
 * may find that segment "opaque and not represent any meaningful
 * human-readable text", which is why the next one exists.
 *
 * **The display name** is `DAV:displayname`, and §6.3.1 asks clients to prefer
 * it: "When displaying address book collections to users, clients SHOULD check
 * the DAV:displayname property and use that value as the name of the address
 * book."
 *
 * **The description** is `CARDDAV:addressbook-description` (§6.2.1), the cue a
 * user is given about what an address book is for — the memo is explicit that
 * it does not otherwise define what extra address books mean. It is here
 * because §6.2.1 says the property "SHOULD NOT be protected so that users can
 * specify a description", so somebody has to keep it, and a card store is the
 * obvious somebody.
 *
 * Both of the last two are nullable, and null means **not set** rather than
 * empty: a `PROPFIND` for a property nobody has set is a `404`, and a server
 * answering an empty string instead would be claiming the user named their
 * address book nothing at all.
 */
final class AddressBookInfo
{
    /**
     * @param string $name The member name inside the address book home
     * @param string|null $displayName `DAV:displayname`, or null where none
     *                                 is set
     * @param string|null $description `CARDDAV:addressbook-description`
     *                                 (§6.2.1), or null where none is set
     */
    public function __construct(
        private readonly string $name,
        private readonly ?string $displayName = null,
        private readonly ?string $description = null,
    ) {
    }

    /**
     * The member name inside the address book home.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * `DAV:displayname`, or null where none is set.
     */
    public function displayName(): ?string
    {
        return $this->displayName;
    }

    /**
     * `CARDDAV:addressbook-description`, or null where none is set.
     */
    public function description(): ?string
    {
        return $this->description;
    }
}
