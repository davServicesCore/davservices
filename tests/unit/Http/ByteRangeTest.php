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

use DavServices\Exception\RangeNotSatisfiable;
use DavServices\Http\ByteRange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-HTTP-05 (byte and suffix ranges, `206`,
 * `Content-Range`, `416`; multipart ranges may be left out) and RFC 9110 §14.
 *
 * Three outcomes, and telling them apart is the whole of this class:
 *
 * - **A range** — the request is answered with `206` and a `Content-Range`.
 * - **Nothing** — the header is to be ignored and the whole resource sent with
 *   `200`. RFC 9110 §14.2 requires this for a unit the server does not know,
 *   and §14.1.1 calls a reversed range invalid rather than unsatisfiable.
 * - **A refusal** — the range is well formed but asks for bytes that are not
 *   there. Only this one is a `416`.
 *
 * Confusing the last two is the common mistake: answering `416` to a range
 * nobody could parse leaves a client with no way to get the file at all.
 */
#[CoversClass(ByteRange::class)]
final class ByteRangeTest extends TestCase
{
    #[DataProvider('ranges')]
    public function testReadsTheRangeAgainstTheSizeOfTheResource(
        string $header,
        int $size,
        int $start,
        int $length,
        string $contentRange,
    ): void {
        $range = ByteRange::parse($header, $size);

        self::assertNotNull($range);
        self::assertSame($start, $range->start());
        self::assertSame($length, $range->length());
        self::assertSame($contentRange, $range->contentRange());
    }

    /**
     * @return iterable<string, array{string, int, int, int, string}>
     */
    public static function ranges(): iterable
    {
        yield 'from the beginning' => ['bytes=0-499', 1000, 0, 500, 'bytes 0-499/1000'];
        yield 'a slice in the middle' => ['bytes=200-299', 1000, 200, 100, 'bytes 200-299/1000'];
        yield 'to the end' => ['bytes=500-', 1000, 500, 500, 'bytes 500-999/1000'];
        yield 'a single byte' => ['bytes=0-0', 1000, 0, 1, 'bytes 0-0/1000'];
        yield 'the last byte' => ['bytes=999-999', 1000, 999, 1, 'bytes 999-999/1000'];
        yield 'the whole resource' => ['bytes=0-999', 1000, 0, 1000, 'bytes 0-999/1000'];
        yield 'a suffix' => ['bytes=-500', 1000, 500, 500, 'bytes 500-999/1000'];
        yield 'a suffix of one byte' => ['bytes=-1', 1000, 999, 1, 'bytes 999-999/1000'];
        yield 'an end past the resource is cut back' => ['bytes=0-9999', 1000, 0, 1000, 'bytes 0-999/1000'];
        yield 'a suffix longer than the resource takes all of it' => ['bytes=-5000', 1000, 0, 1000, 'bytes 0-999/1000'];
        yield 'a resource of one byte' => ['bytes=0-', 1, 0, 1, 'bytes 0-0/1'];
    }

    /**
     * RFC 9110 §14.2: a unit the server does not understand is ignored, and a
     * header it cannot parse is no instruction at all. The answer is the whole
     * resource with `200`, never a refusal — a client that mistypes a range
     * would otherwise be locked out of a file it is allowed to read.
     */
    #[DataProvider('ignoredHeaders')]
    public function testIgnoresAHeaderThatSaysNothingItCanFollow(?string $header): void
    {
        self::assertNull(ByteRange::parse($header, 1000));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function ignoredHeaders(): iterable
    {
        yield 'no header at all' => [null];
        yield 'an empty header' => [''];
        yield 'a unit nobody knows' => ['items=0-10'];
        yield 'no unit' => ['0-499'];
        yield 'no range' => ['bytes='];
        yield 'neither end' => ['bytes=-'];
        yield 'not a number' => ['bytes=abc'];
        yield 'a number and a word' => ['bytes=0-abc'];
        yield 'reversed, which RFC 9110 §14.1.1 calls invalid' => ['bytes=500-499'];
        yield 'a negative start' => ['bytes=-500-600'];
        yield 'spaces inside the range' => ['bytes=0 - 499'];
        yield 'more than one range, which needs multipart' => ['bytes=0-10,20-30'];
    }

    /**
     * A range that is well formed but asks for bytes beyond the resource is
     * the one case RFC 9110 §15.5.17 answers with `416`.
     */
    #[DataProvider('unsatisfiableRanges')]
    public function testRefusesARangeThatAsksForBytesThatAreNotThere(string $header, int $size): void
    {
        $this->expectException(RangeNotSatisfiable::class);

        ByteRange::parse($header, $size);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function unsatisfiableRanges(): iterable
    {
        yield 'starting at the size' => ['bytes=1000-', 1000];
        yield 'starting past the size' => ['bytes=2000-2500', 1000];
        yield 'a suffix of nothing' => ['bytes=-0', 1000];
        yield 'any range of an empty resource' => ['bytes=0-', 0];
        yield 'the first byte of an empty resource' => ['bytes=0-0', 0];
    }

    public function testTheRefusalReportsTheStatusForAnUnsatisfiableRange(): void
    {
        try {
            ByteRange::parse('bytes=1000-', 1000);
        } catch (RangeNotSatisfiable $refused) {
            self::assertSame(416, $refused->status());

            return;
        }

        self::fail('A range beyond the resource was accepted.');
    }

    /**
     * The last byte is inclusive in HTTP and exclusive in almost every
     * programming language, which is where the off-by-one lives.
     */
    public function testTheLastByteIsPartOfTheRange(): void
    {
        $range = ByteRange::parse('bytes=0-499', 1000);

        self::assertNotNull($range);
        self::assertSame(499, $range->last());
        self::assertSame(500, $range->length());
    }
}
