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

namespace DavServices\Tests\Unit\VObject\Value;

use DavServices\VObject\ParseError;
use DavServices\VObject\Value\Boolean;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for `BOOLEAN`, derived from RFC 5545 §3.3.2 and RFC 6350 §4.4.
 *
 *     boolean = "TRUE" / "FALSE"
 *
 * "These values are case-insensitive text" (§3.3.2), and RFC 6350 §4.4 shows
 * all three spellings side by side: `TRUE`, `false`, `True`.
 *
 * "No additional content value encoding … is defined for this value type", so
 * nothing here undoes a backslash.
 */
#[CoversClass(Boolean::class)]
final class BooleanTest extends TestCase
{
    /**
     * **RFC 6350 §4.4's own examples**, which exist to make the point about
     * case: `TRUE`, `false`, `True`.
     *
     * @param non-empty-string $raw
     */
    #[DataProvider('theSpellingsTheSpecificationShows')]
    public function testTheSpellingIsIgnored(string $raw, bool $expected): void
    {
        self::assertSame($expected, Boolean::decode($raw));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function theSpellingsTheSpecificationShows(): iterable
    {
        yield 'TRUE' => ['TRUE', true];

        yield 'false' => ['false', false];

        yield 'True' => ['True', true];

        yield 'FALSE' => ['FALSE', false];

        yield 'fAlSe' => ['fAlSe', false];
    }

    /**
     * **Anything else is refused.** The grammar has two words in it, and a
     * reader that took "yes", "1" or an empty value for one of them would be
     * deciding what somebody meant.
     *
     */
    #[DataProvider('whatIsNoBoolean')]
    public function testWhatIsNoBooleanIsRefused(string $raw): void
    {
        $this->expectException(ParseError::class);

        Boolean::decode($raw);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function whatIsNoBoolean(): iterable
    {
        yield 'nothing at all' => [''];

        yield 'a one' => ['1'];

        yield 'a zero' => ['0'];

        yield 'yes' => ['yes'];

        yield 'surrounding space' => [' TRUE '];

        yield 'something else entirely' => ['maybe'];
    }

    /**
     * Written back in the upper case the grammar shows, which is the form
     * every reader takes and the one RFC 6350 §3.3 recommends on output.
     */
    public function testIsWrittenInUpperCase(): void
    {
        self::assertSame('TRUE', Boolean::encode(true));
        self::assertSame('FALSE', Boolean::encode(false));
    }

    /**
     * And survives the round trip.
     */
    public function testSurvivesTheRoundTrip(): void
    {
        self::assertTrue(Boolean::decode(Boolean::encode(true)));
        self::assertFalse(Boolean::decode(Boolean::encode(false)));
    }
}
