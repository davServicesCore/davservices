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

use DavServices\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Request::class)]
final class RequestTest extends TestCase
{
    public function testExposesTheMethodItWasConstructedWith(): void
    {
        self::assertSame('PROPFIND', (new Request('PROPFIND'))->method);
    }

    /**
     * The method token is case-sensitive per RFC 9110 §9.1, so the request
     * must hand back exactly what it was given rather than normalising it.
     */
    #[DataProvider('methodProvider')]
    public function testKeepsTheMethodTokenVerbatim(string $method): void
    {
        self::assertSame($method, (new Request($method))->method);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function methodProvider(): iterable
    {
        yield 'WebDAV'          => ['PROPFIND'];
        yield 'WebDAV write'    => ['MKCOL'];
        yield 'CalDAV report'   => ['REPORT'];
        yield 'HTTP'            => ['GET'];
        yield 'lower case'      => ['get'];
        yield 'extension token' => ['X-CUSTOM'];
    }
}
