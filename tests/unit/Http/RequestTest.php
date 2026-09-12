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

use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\MalformedRequest;
use DavServices\Http\Request;
use DavServices\Uri\MalformedPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-HTTP-01: a value object with a mutability that is
 * stated rather than discovered.
 *
 * Carries: the method, the target as it arrived, the path the library works
 * with, the query nobody in DAV reads but nothing may drop, the headers, the
 * body, and the protocol version a response has to match.
 *
 * Splits: a target into path and query, the query staying raw because it is
 * not the path layer's business to guess a form encoding.
 *
 * Refuses: a method that is not a token (RFC 9110 §9.1), and an empty target.
 * The asterisk-form of RFC 9110 §7.1 is the one target that is not a path.
 */
#[CoversClass(Request::class)]
#[CoversClass(MalformedRequest::class)]
final class RequestTest extends TestCase
{
    public function testCarriesTheMethodAndTheTarget(): void
    {
        $request = new Request('PROPFIND', '/calendars/alice/');

        self::assertSame('PROPFIND', $request->method());
        self::assertSame('/calendars/alice/', $request->target());
    }

    public function testHandsOverThePathInTheFormTheLibraryWorksWith(): void
    {
        self::assertSame('calendars/alice', (new Request('PROPFIND', '//calendars//alice/'))->path());
    }

    public function testDecodesThePath(): void
    {
        self::assertSame('work week.ics', (new Request('GET', '/work%20week.ics'))->path());
    }

    #[DataProvider('targets')]
    public function testSplitsTheTargetIntoPathAndQuery(string $target, string $path, string $query): void
    {
        $request = new Request('GET', $target);

        self::assertSame($path, $request->path());
        self::assertSame($query, $request->query());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function targets(): iterable
    {
        yield 'no query' => ['/file.txt', 'file.txt', ''];
        yield 'a query' => ['/file.txt?export=1', 'file.txt', 'export=1'];
        yield 'an empty query' => ['/file.txt?', 'file.txt', ''];
        yield 'a query holding a question mark' => ['/f?a=b?c', 'f', 'a=b?c'];
        yield 'a question mark alone' => ['/?a=b', '', 'a=b'];
        yield 'the root' => ['/', '', ''];
    }

    /**
     * A fragment never reaches a server — RFC 9110 §7.1 keeps it on the
     * client — but a request built by hand may carry one, and it must not
     * become part of a resource name.
     */
    public function testDropsAFragment(): void
    {
        $request = new Request('GET', '/file.txt?a=b#section');

        self::assertSame('file.txt', $request->path());
        self::assertSame('a=b', $request->query());
    }

    /**
     * RFC 9110 §7.1: `OPTIONS *` asks about the server rather than about a
     * resource. Left alone it would be looked up as a collection named `*`.
     */
    public function testTheAsteriskFormAddressesTheServerItself(): void
    {
        $request = new Request('OPTIONS', '*');

        self::assertSame('*', $request->target());
        self::assertSame('', $request->path());
    }

    public function testAMalformedTargetIsRefusedWhenThePathIsAskedFor(): void
    {
        $this->expectException(MalformedPath::class);

        (new Request('GET', '/../etc/passwd'))->path();
    }

    public function testComesWithNoHeadersAndNoBodyUnlessGivenAny(): void
    {
        $request = new Request('GET', '/');

        self::assertSame([], $request->headers()->toArray());
        self::assertTrue($request->body()->isEmpty());
        self::assertSame('1.1', $request->protocolVersion());
    }

    public function testCarriesTheHeadersTheBodyAndTheProtocolVersion(): void
    {
        $request = new Request(
            'PUT',
            '/work.ics',
            new Headers(['Content-Type' => 'text/calendar']),
            new Body('BEGIN:VCALENDAR'),
            '1.0',
        );

        self::assertSame('text/calendar', $request->headers()->first('content-type'));
        self::assertSame('BEGIN:VCALENDAR', $request->body()->contents());
        self::assertSame('1.0', $request->protocolVersion());
    }

    #[DataProvider('refusedMethods')]
    public function testRefusesAMethodThatIsNotAToken(string $method): void
    {
        $this->expectException(MalformedRequest::class);

        new Request($method, '/');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedMethods(): iterable
    {
        yield 'empty' => [''];
        yield 'with a space' => ['PROP FIND'];
        yield 'with a line feed' => ["GET\nDELETE"];
        yield 'with a slash' => ['GET/1'];
        yield 'carrying markup' => ['<script>&'];
    }

    /**
     * The method reaches logs and an `Allow` header as it arrived. Folding it
     * to upper case would turn an unknown lower-case method into a different
     * unknown one, and RFC 9110 §9.1 makes methods case-sensitive anyway.
     */
    public function testKeepsTheMethodExactlyAsItArrived(): void
    {
        self::assertSame('MkCalendar', (new Request('MkCalendar', '/'))->method());
    }

    public function testRefusesAnEmptyTarget(): void
    {
        $this->expectException(MalformedRequest::class);

        new Request('GET', '');
    }
}
