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

namespace DavServices\Tests\Unit\Backend;

use DavServices\Backend\ISubscriptionBackend;
use DavServices\Dav\Calendars\SubscriptionInfo;
use DavServices\Exception\Conflict;
use DavServices\Exception\Forbidden;
use DavServices\Exception\NotFound;

/**
 * A subscription store that lives in an array, for the tests of the layers
 * above.
 *
 * **It keeps what it is given and nothing more**, which is the point: a
 * subscription is a record rather than a container, so a store of them is an
 * array keyed by principal and name, and anything more elaborate would be
 * hiding how little the cut asks for.
 *
 * It fetches nothing, as no store in this library does — what is at the far end
 * of a feed is an application's business
 * ({@see \DavServices\Backend\ISubscriptionBackend}).
 */
final class MemorySubscriptionBackend implements ISubscriptionBackend
{
    /** @var array<string, array<string, SubscriptionInfo>> */
    private array $subscriptions = [];

    /**
     * Puts one there, as provisioning would, without the checks
     * {@see create()} makes of a client's request.
     */
    public function keep(string $principal, SubscriptionInfo $subscription): void
    {
        $this->subscriptions[$principal][$subscription->name()] = $subscription;
    }

    /**
     * @return list<SubscriptionInfo>
     */
    public function subscriptions(string $principal): array
    {
        return array_values($this->subscriptions[$principal] ?? []);
    }

    public function subscription(string $principal, string $name): ?SubscriptionInfo
    {
        return $this->subscriptions[$principal][$name] ?? null;
    }

    public function create(string $principal, SubscriptionInfo $subscription): void
    {
        $name = $subscription->name();

        self::mustBeAName($name);

        if (isset($this->subscriptions[$principal][$name])) {
            throw new Conflict(sprintf('This principal already follows something called "%s".', $name));
        }

        $this->subscriptions[$principal][$name] = $subscription;
    }

    public function update(string $principal, SubscriptionInfo $subscription): void
    {
        $name = $subscription->name();

        self::mustBeAName($name);
        $this->mustBeThere($principal, $name);

        $this->subscriptions[$principal][$name] = $subscription;
    }

    public function remove(string $principal, string $name): void
    {
        self::mustBeAName($name);
        $this->mustBeThere($principal, $name);

        unset($this->subscriptions[$principal][$name]);
    }

    /**
     * @throws NotFound If the principal follows nothing of that name
     */
    private function mustBeThere(string $principal, string $name): void
    {
        if (!isset($this->subscriptions[$principal][$name])) {
            throw new NotFound(sprintf('This principal follows nothing called "%s".', $name));
        }
    }

    /**
     * @throws Forbidden If it is not a single path segment
     */
    private static function mustBeAName(string $name): void
    {
        if ($name === '' || $name === '.' || $name === '..' || strpbrk($name, "/\\\0") !== false) {
            throw new Forbidden(sprintf('"%s" is not a name this store takes.', $name));
        }
    }
}
