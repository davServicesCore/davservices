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

namespace DavServices\CalDav\Schedule;

use DavServices\Dav\ICollection;
use DavServices\Dav\IProperties;
use DavServices\Xml\Element;

/**
 * A scheduling Outbox over a store that keeps properties of its own.
 *
 * **It exists because `IProperties` is a claim, not a convenience**, the same
 * reason {@see InboxKeepingItsOwn} does: a wrapper claiming the interface over
 * a store that keeps nothing would take dead property storage away from every
 * Outbox silently.
 *
 * RFC 6638 §2.1 lists six properties of RFC 4791 that "MAY also be defined on
 * scheduling Outbox collections", and says what they are for there: they
 * "apply to scheduling messages submitted to the scheduling Outbox collection
 * with the POST method". A deployment that narrows what may be submitted needs
 * somewhere to say so.
 *
 * Nobody constructs either class directly: {@see Outbox::of()} chooses.
 */
final class OutboxKeepingItsOwn extends Outbox implements IProperties
{
    public function __construct(private readonly ICollection&IProperties $store)
    {
        parent::__construct($store);
    }

    /**
     * The property names the store keeps.
     *
     * @return list<string>
     */
    public function propertyNames(): array
    {
        return $this->store->propertyNames();
    }

    /**
     * What the store has for these properties.
     *
     * @param list<string> $names
     *
     * @return array<string, Element|string|null>
     */
    public function properties(array $names): array
    {
        return $this->store->properties($names);
    }

    /**
     * What the store makes of these changes.
     *
     * @param array<string, Element|string|null> $mutations
     *
     * @return array<string, int>
     */
    public function patchProperties(array $mutations): array
    {
        return $this->store->patchProperties($mutations);
    }
}
