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

use DavServices\Http\ETag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-HTTP-08 (strong tags delivered, weak ones marked
 * `W/` and compared accordingly) and RFC 9110 §8.8.3 and §13.1.
 *
 * The two comparison functions are the point of the class. `If-Match` uses the
 * strong one, where a weak tag never matches anything — a client updating a
 * resource must know it has the exact bytes it thinks it has. `If-None-Match`
 * uses the weak one, where two tags match if their opaque parts do, which is
 * enough to answer "you already have this".
 */
#[CoversClass(ETag::class)]
final class ETagTest extends TestCase
{
    public function testReadsAStrongTag(): void
    {
        $etag = ETag::parse('"abc"');

        self::assertNotNull($etag);
        self::assertFalse($etag->isWeak());
        self::assertSame('"abc"', $etag->toString());
    }

    public function testReadsAWeakTag(): void
    {
        $etag = ETag::parse('W/"abc"');

        self::assertNotNull($etag);
        self::assertTrue($etag->isWeak());
        self::assertSame('W/"abc"', $etag->toString());
    }

    public function testReadsAnEmptyOpaqueTag(): void
    {
        $etag = ETag::parse('""');

        self::assertNotNull($etag);
        self::assertSame('""', $etag->toString());
    }

    #[DataProvider('unreadableTags')]
    public function testRefusesWhatIsNoTag(string $value): void
    {
        self::assertNull(ETag::parse($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableTags(): iterable
    {
        yield 'no quotes' => ['abc'];
        yield 'only an opening quote' => ['"abc'];
        yield 'only a closing quote' => ['abc"'];
        yield 'empty' => [''];
        yield 'a lower-case weakness marker, which RFC 9110 §8.8.3 does not allow' => ['w/"abc"'];
        yield 'a weakness marker without a tag' => ['W/'];
        yield 'the wildcard, which is not a tag but a header of its own' => ['*'];
        yield 'a quote inside the tag' => ['"a"b"'];
    }

    #[DataProvider('comparisons')]
    public function testComparesByBothFunctions(string $left, string $right, bool $strongly, bool $weakly): void
    {
        $one = ETag::parse($left);
        $other = ETag::parse($right);

        self::assertNotNull($one);
        self::assertNotNull($other);
        self::assertSame($strongly, $one->matchesStrongly($other));
        self::assertSame($weakly, $one->matchesWeakly($other));
    }

    /**
     * RFC 9110 §8.8.3.2: the strong function matches only if both tags are
     * strong and their opaque parts are the same; the weak function ignores
     * the marker.
     *
     * @return iterable<string, array{string, string, bool, bool}>
     */
    public static function comparisons(): iterable
    {
        yield 'two equal strong tags' => ['"abc"', '"abc"', true, true];
        yield 'a strong and a weak tag' => ['"abc"', 'W/"abc"', false, true];
        yield 'a weak and a strong tag' => ['W/"abc"', '"abc"', false, true];
        yield 'two equal weak tags' => ['W/"abc"', 'W/"abc"', false, true];
        yield 'two different strong tags' => ['"abc"', '"def"', false, false];
        yield 'two different weak tags' => ['W/"abc"', 'W/"def"', false, false];
        yield 'tags differing in case' => ['"abc"', '"ABC"', false, false];
    }

    public function testReadsAListOfTags(): void
    {
        $tags = ETag::parseList('"abc", W/"def" ,"ghi"');

        self::assertSame(['"abc"', 'W/"def"', '"ghi"'], array_map(
            static fn (ETag $etag): string => $etag->toString(),
            $tags,
        ));
    }

    public function testReadsASingleTagAsAListOfOne(): void
    {
        self::assertCount(1, ETag::parseList('"abc"'));
    }

    /**
     * An entry nobody can read is one the resource cannot match. Dropping it
     * leaves the condition to the entries that can be read, which is the safe
     * direction: a list of nothing but rubbish matches nothing.
     */
    public function testDropsTheEntriesOfAListThatAreNoTags(): void
    {
        self::assertCount(1, ETag::parseList('garbage, "abc"'));
        self::assertSame([], ETag::parseList('garbage'));
        self::assertSame([], ETag::parseList(''));
    }
}
