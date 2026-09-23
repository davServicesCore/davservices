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

use DavServices\Exception\PayloadTooLarge;
use DavServices\Http\Body;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-HTTP-03 (a body available as a stream and readable
 * more than once, buffered onto `php://temp` with a configurable memory limit)
 * and R-HTTP-12 (a configurable ceiling, exceeded means `413`).
 *
 * Reading: from a string, from a stream, from nothing; as a stream and as a
 * string; twice over, which is what a plugin chain needs; a body larger than
 * the memory limit, which is the one that reaches the disk.
 *
 * Refusing: a body past the ceiling, refused while it is being read rather
 * than after — a body that has to be stored in full before it can be refused
 * is not a ceiling at all. And a body refused once stays refused, because what
 * is left of it has already been consumed.
 */
#[CoversClass(Body::class)]
final class BodyTest extends TestCase
{
    public function testAnAbsentBodyIsEmpty(): void
    {
        $body = new Body();

        self::assertTrue($body->isEmpty());
        self::assertSame('', $body->contents());
    }

    public function testReadsABodyGivenAsAString(): void
    {
        $body = new Body('<propfind/>');

        self::assertFalse($body->isEmpty());
        self::assertSame('<propfind/>', $body->contents());
    }

    public function testReadsABodyGivenAsAStream(): void
    {
        self::assertSame('<propfind/>', (new Body(self::streamOf('<propfind/>')))->contents());
    }

    public function testAnEmptyStreamIsAnEmptyBody(): void
    {
        self::assertTrue((new Body(self::streamOf('')))->isEmpty());
    }

    /**
     * The one requirement the whole class exists for: the chain of plugins
     * that inspects a request each read the same body, and a stream that is
     * consumed by the first of them leaves the rest with nothing.
     */
    public function testTheBodyCanBeReadAgain(): void
    {
        $body = new Body(self::streamOf('<propfind/>'));

        // Read three times over before anything is asserted, so that what is
        // compared is three separate reads of one body and not one read
        // compared with itself.
        $first = $body->contents();
        $again = $body->contents();
        $fromTheStream = stream_get_contents($body->stream());

        self::assertSame('<propfind/>', $first);
        self::assertSame('<propfind/>', $again);
        self::assertSame('<propfind/>', $fromTheStream);
    }

    public function testTheStreamIsHandedOverRewound(): void
    {
        $body = new Body('<propfind/>');

        $first = stream_get_contents($body->stream());
        $again = stream_get_contents($body->stream());

        self::assertSame('<propfind/>', $first);
        self::assertSame('<propfind/>', $again);
    }

    public function testReadingTheStreamDoesNotDisturbTheNextReader(): void
    {
        $body = new Body('<propfind/>');

        fread($body->stream(), 4);

        self::assertSame('<propfind/>', $body->contents());
    }

    /**
     * Below the memory limit the buffer never reaches the disk; above it, PHP
     * moves it there on its own. Both have to come back byte for byte, and a
     * limit of a few bytes is the cheapest way to exercise the second path.
     */
    #[DataProvider('memoryLimits')]
    public function testABodyLargerThanTheMemoryLimitSurvivesIntact(int $memoryLimit): void
    {
        $payload = str_repeat('0123456789', 1000);

        self::assertSame($payload, (new Body($payload, $memoryLimit))->contents());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function memoryLimits(): iterable
    {
        yield 'kept in memory' => [1_048_576];
        yield 'spilled to disk' => [64];
    }

    /**
     * R-HTTP-03 asks for the memory limit to be configurable, and a limit that
     * never reaches the buffer is no limit. PHP hands it back in the stream's
     * own address, which is the only place it can be read again.
     */
    public function testTheMemoryLimitReachesTheBuffer(): void
    {
        $body = new Body('0123456789', 64);

        self::assertSame('php://temp/maxmemory:64', stream_get_meta_data($body->stream())['uri'] ?? null);
    }

    public function testAcceptsABodyUpToTheCeiling(): void
    {
        self::assertSame('0123456789', (new Body('0123456789'))->contents(10));
    }

    public function testRefusesABodyPastTheCeiling(): void
    {
        $this->expectException(PayloadTooLarge::class);

        (new Body('0123456789'))->contents(9);
    }

    public function testTheRefusalReportsTheStatusForAnOversizedBody(): void
    {
        try {
            (new Body('0123456789'))->contents(9);
        } catch (PayloadTooLarge $refused) {
            self::assertSame(413, $refused->status());

            return;
        }

        self::fail('An oversized body was accepted.');
    }

    /**
     * A ceiling that is only noticed once the body is stored in full is no
     * ceiling: the memory or the disk is gone by then. The refusal has to come
     * while the body is being read, so the test hands over a stream that would
     * be ruinous to store and checks that barely more than the ceiling is
     * taken from it.
     */
    public function testRefusesWhileReadingRatherThanAfterwards(): void
    {
        $source = self::streamOf(str_repeat('x', 100_000));

        try {
            (new Body($source))->contents(1_000);
        } catch (PayloadTooLarge) {
            // The ceiling plus the one byte it takes to know the body goes on.
            self::assertLessThanOrEqual(1_001, ftell($source), 'More of the body was read than the ceiling allows.');

            return;
        }

        self::fail('An oversized body was accepted.');
    }

    /**
     * Whatever was left of the body has been consumed by the attempt, so a
     * second look must not quietly hand back a truncated one.
     */
    public function testABodyRefusedOnceStaysRefused(): void
    {
        $body = new Body(self::streamOf('0123456789'));

        try {
            $body->contents(9);
        } catch (PayloadTooLarge) {
            $this->expectException(PayloadTooLarge::class);

            $body->contents();

            return;
        }

        self::fail('An oversized body was accepted.');
    }

    /**
     * The two ceilings of R-HTTP-12 differ — an XML request body is parsed
     * into memory, an upload is not — so the ceiling belongs to the read
     * rather than to the body, and a body already read is measured again.
     */
    public function testTheCeilingIsCheckedEvenWhenTheBodyIsAlreadyBuffered(): void
    {
        $body = new Body('0123456789');

        self::assertSame('0123456789', $body->contents());

        $this->expectException(PayloadTooLarge::class);

        $body->contents(9);
    }

    public function testAnEmptyBodyPassesAnyCeiling(): void
    {
        self::assertSame('', (new Body())->contents(0));
    }

    /**
     * @return resource
     */
    private static function streamOf(string $payload): mixed
    {
        $stream = fopen('php://memory', 'r+b');

        self::assertIsResource($stream);

        fwrite($stream, $payload);
        rewind($stream);

        return $stream;
    }
}
