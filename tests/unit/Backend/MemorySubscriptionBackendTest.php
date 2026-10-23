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
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The contract, run against the store that lives in an array.
 *
 * And the one thing the contract cannot say from outside: **what a store keeps
 * is what it was given**. Every case above goes in through the interface and
 * comes back out through it, so a store that quietly rewrote a feed would pass
 * them all by rewriting it the same way twice. Provisioning one directly and
 * reading it through the interface is what shows the two are the same record.
 */
#[CoversClass(SubscriptionInfo::class)]
final class MemorySubscriptionBackendTest extends SubscriptionBackendContract
{
    public function testWhatWasProvisionedIsWhatTheInterfaceAnswers(): void
    {
        $backend = new MemorySubscriptionBackend();
        $given = new SubscriptionInfo('fixtures', 'https://example.com/fixtures.ics', 'Fixture list');

        $backend->keep($this->principal(), $given);

        self::assertSame($given, $backend->subscription($this->principal(), 'fixtures'));
    }

    protected function backend(): ISubscriptionBackend
    {
        $backend = new MemorySubscriptionBackend();

        $backend->keep($this->principal(), new SubscriptionInfo('holidays', self::FEED));
        $backend->keep($this->secondPrincipal(), new SubscriptionInfo(
            'holidays',
            'webcal://example.com/other-holidays.ics',
        ));

        return $backend;
    }

    protected function secondPrincipal(): string
    {
        return 'cyrus';
    }
}
