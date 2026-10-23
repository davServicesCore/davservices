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
 * A subscription over a store that keeps properties of its own.
 *
 * **It exists because `IProperties` is a claim, not a convenience.**
 * {@see \DavServices\Plugin\DeadProperties} steps aside for any node that
 * implements it — the contract is that such a node keeps all of its properties
 * itself — so a subscription that claimed the interface over a store keeping
 * none would silently take dead property storage away.
 *
 * And for a subscription that is worse than for a calendar.
 * {@see Subscription::SOURCE} is the property that says which feed the
 * collection follows, and nothing about it can be worked out from this server's
 * state: a user typed the URL. A subscription with nowhere to keep it is a
 * collection that no longer says what it is subscribed to.
 *
 * So the interface is claimed only where it is true, which is why nobody
 * constructs either class directly: {@see Subscription::of()} chooses.
 */
final class SubscriptionKeepingItsOwn extends Subscription implements IProperties
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
