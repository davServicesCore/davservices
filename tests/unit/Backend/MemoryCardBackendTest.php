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

use DavServices\Backend\ICardBackend;
use DavServices\Dav\Cards\AddressBookInfo;
use DavServices\Dav\Cards\CardInfo;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The contract, run against the store that lives in an array.
 *
 * And one thing the contract cannot say from outside: **`cards()` answers a
 * `PROPFIND Depth: 1` without reading a single card.** That is the promise
 * R-BE-02 is made of, and a counting store is the only way to see it kept.
 */
#[CoversClass(CardInfo::class)]
#[CoversClass(AddressBookInfo::class)]
final class MemoryCardBackendTest extends CardBackendContract
{
    public function testTheListingReadsNoCardAtAll(): void
    {
        $backend = $this->backend();

        self::assertInstanceOf(MemoryCardBackend::class, $backend);

        $backend->put($this->principal(), 'work', 'tim.vcf', self::card('tim', 'Tim Howes'));
        $backend->cards($this->principal(), 'work');
        $backend->card($this->principal(), 'work', 'simon.vcf');

        self::assertSame(0, $backend->bodyReads);
    }

    /**
     * **And asking for many bodies is one pass over the names**, which is the
     * other half: a multiget of forty cards reads forty, not sixteen hundred.
     */
    public function testAskingForManyBodiesReadsEachOnce(): void
    {
        $backend = $this->backend();

        self::assertInstanceOf(MemoryCardBackend::class, $backend);

        $backend->put($this->principal(), 'work', 'tim.vcf', self::card('tim', 'Tim Howes'));
        $backend->bodies($this->principal(), 'work', ['simon.vcf', 'tim.vcf', 'gone.vcf']);

        self::assertSame(2, $backend->bodyReads);
    }

    protected function backend(): ICardBackend
    {
        $backend = new MemoryCardBackend();

        $backend->provision($this->principal(), new AddressBookInfo('work', 'Work', 'Colleagues'));
        $backend->provision($this->principal(), new AddressBookInfo('home'));
        $backend->provision('tim', new AddressBookInfo('work', 'Work'));
        $backend->put($this->principal(), 'work', 'simon.vcf', self::card('simon', 'Simon Perreault'));

        return $backend;
    }

    protected function secondPrincipal(): string
    {
        return 'tim';
    }
}
