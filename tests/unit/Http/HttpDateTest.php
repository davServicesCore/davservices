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

namespace DavServices\Tests\Unit\Http;

use DateTimeImmutable;
use DateTimeZone;
use DavServices\Http\HttpDate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 9110 §5.6.7.
 *
 * HTTP has one date format that a server may send, and clients compare what
 * they are handed byte for byte with what they stored. A `Last-Modified` in
 * the server's own time zone, or with a month spelt in the server's own
 * language, is a cache that never hits again.
 *
 * It lives here rather than in the one method that first needed it because
 * `getlastmodified` is the same format as `Last-Modified` — and two places
 * spelling it out is two places for them to drift apart.
 */
#[CoversClass(HttpDate::class)]
final class HttpDateTest extends TestCase
{
    public function testWritesTheFormatOfTheSpecification(): void
    {
        $moment = new DateTimeImmutable('2026-09-13 12:34:56', new DateTimeZone('UTC'));

        self::assertSame('Sun, 13 Sep 2026 12:34:56 GMT', HttpDate::format($moment));
    }

    /**
     * The moment is the same moment wherever the server stands; what a client
     * is sent is that moment in GMT.
     */
    public function testWritesInGmtWhateverTheZoneItWasGivenIn(): void
    {
        $moment = new DateTimeImmutable('2026-09-13 14:34:56', new DateTimeZone('Europe/Berlin'));

        self::assertSame('Sun, 13 Sep 2026 12:34:56 GMT', HttpDate::format($moment));
    }

    /**
     * Day and month are the English abbreviations of the specification, never
     * the ones a server's locale would give.
     */
    public function testWritesTheDayAndMonthTheSpecificationNames(): void
    {
        $moment = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));

        self::assertSame('Thu, 01 Jan 2026 00:00:00 GMT', HttpDate::format($moment));
    }
}
