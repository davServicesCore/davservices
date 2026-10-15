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

namespace DavServices\CalDav;

use DavServices\Dav\ICollection;
use DavServices\Dav\IProperties;
use DavServices\Xml\Element;

/**
 * A calendar over a store that keeps properties of its own.
 *
 * **It exists because `IProperties` is a claim, not a convenience.**
 * {@see \DavServices\Plugin\DeadProperties} steps aside for any node that
 * implements it — the contract is that such a node keeps all of its properties
 * itself — so a calendar that claimed the interface over a store keeping none
 * would silently take dead property storage away. That matters here more than
 * almost anywhere: RFC 4791 §5.2.1 has `CALDAV:calendar-description` carry "a
 * description of the calendar collection that is suitable for presentation to
 * a user", which is a value a user writes, and in a server whose store keeps
 * nothing it is `DeadProperties` that keeps it.
 *
 * So the interface is claimed only where it is true, which is why nobody
 * constructs either class directly: {@see Calendar::of()} chooses.
 */
final class CalendarKeepingItsOwn extends Calendar implements IProperties
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
