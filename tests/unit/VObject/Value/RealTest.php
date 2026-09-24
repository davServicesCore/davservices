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
use DavServices\VObject\Value\Real;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for `FLOAT`, derived from RFC 5545 §3.3.7 and RFC 6350 §4.6.
 *
 *     float = (["+"] / "-") 1*DIGIT ["." 1*DIGIT]
 *
 * **The class is called `Real` because PHP will not have it called `Float`** —
 * `float` is a reserved class name and names are case-insensitive. §3.3.7's
 * own purpose sentence gives the word: "properties that contain a real-number
 * value".
 *
 * ## No exponent, in either specification
 *
 * The grammar has none, and RFC 6350 §4.6 spells out why it matters: "Note:
 * Scientific notation is disallowed. Implementers wishing to use their
 * favorite language's %f formatting should be careful." So `1e5` is refused on
 * the way in — and on the way out nothing may ever produce an `E`, which is
 * exactly what PHP's ordinary number formatting does for large values.
 *
 * RFC 6350 §4.6 also sets the floor for precision: "Implementations MUST
 * support a precision equal or better than that of the IEEE 'binary64'
 * format", which is what PHP's float is.
 */
#[CoversClass(Real::class)]
final class RealTest extends TestCase
{
    /**
     * RFC 5545 §3.3.7's own examples.
     *
     * @param non-empty-string $raw
     */
    #[DataProvider('theExamplesFromTheSpecification')]
    public function testReadsTheExamples(string $raw, float $expected): void
    {
        self::assertSame($expected, Real::decode($raw));
    }

    /**
     * @return iterable<string, array{string, float}>
     */
    public static function theExamplesFromTheSpecification(): iterable
    {
        yield '1000000.0000001' => ['1000000.0000001', 1000000.0000001];

        yield '1.333' => ['1.333', 1.333];

        yield '-3.14' => ['-3.14', -3.14];

        yield '20.30, from RFC 6350 §4.6' => ['20.30', 20.30];
    }

    /**
     * **The fraction is optional** — `1*DIGIT ["." 1*DIGIT]` — so a whole
     * number is a float as well.
     */
    public function testTheFractionIsOptional(): void
    {
        self::assertSame(42.0, Real::decode('42'));
    }

    /**
     * "If sign is not specified, the value is assumed positive" (RFC 6350
     * §4.6), and an explicit plus says the same.
     */
    public function testAnExplicitPlusIsPositive(): void
    {
        self::assertSame(3.5, Real::decode('+3.5'));
    }

    /**
     * **What the grammar does not allow is refused**, and the exponent is the
     * one worth naming: RFC 6350 §4.6 warns about it directly.
     *
     * @param non-empty-string $raw
     */
    #[DataProvider('whatIsNoFloat')]
    public function testWhatIsNoFloatIsRefused(string $raw): void
    {
        $this->expectException(ParseError::class);

        Real::decode($raw);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function whatIsNoFloat(): iterable
    {
        yield 'nothing at all' => [''];

        yield 'scientific notation, which §4.6 disallows' => ['1e5'];

        yield 'and its capital form' => ['1E5'];

        yield 'a point with no digits after it' => ['1.'];

        yield 'a point with no digits before it' => ['.5'];

        yield 'two points' => ['1.2.3'];

        yield 'a sign on its own' => ['-'];

        yield 'surrounding space' => [' 1.5 '];

        yield 'a comma for a point' => ['1,5'];
    }

    /**
     * **Writing one never produces an exponent**, however large it is. That
     * is the trap RFC 6350 §4.6 names, and PHP walks into it by default: the
     * ordinary string form of `1.0E+20` is exactly what the grammar forbids.
     */
    public function testWritingNeverProducesAnExponent(): void
    {
        $written = Real::encode(1.0E+20);

        self::assertStringNotContainsStringIgnoringCase('e', $written);
        self::assertSame('100000000000000000000', $written);
    }

    /**
     * A whole number is written without a pointless fraction, because the
     * grammar makes the fraction optional and a trailing `.0` is noise in
     * somebody's file.
     */
    public function testAWholeNumberIsWrittenWhole(): void
    {
        self::assertSame('42', Real::encode(42.0));
    }

    /**
     * And a fraction keeps its digits rather than being rounded to something
     * shorter: RFC 6350 §4.6 asks for binary64 precision or better.
     */
    public function testAFractionKeepsItsDigits(): void
    {
        self::assertSame('1000000.0000001', Real::encode(1000000.0000001));
    }

    /**
     * What is written can be read back, which is the round trip R-VOBJ-05
     * wants at the level of one value.
     */
    #[DataProvider('valuesThatMustSurvive')]
    public function testWhatIsWrittenCanBeReadBack(float $value): void
    {
        self::assertSame($value, Real::decode(Real::encode($value)));
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function valuesThatMustSurvive(): iterable
    {
        yield 'a whole number' => [42.0];

        yield 'a fraction' => [1.333];

        yield 'a negative one' => [-3.14];

        yield 'a long one' => [1000000.0000001];

        yield 'zero' => [0.0];
    }

    /**
     * §3.3.7: "If the property permits, multiple 'float' values are specified
     * by a COMMA-separated list of values." RFC 6350 §4.6 shows `1.333,3.14`,
     * and `GEO` is two of them.
     */
    public function testAListIsSplitOnCommas(): void
    {
        self::assertSame([1.333, 3.14], Real::decodeList('1.333,3.14'));
    }

    /**
     * And is written back the same way.
     */
    public function testAListIsWrittenWithCommas(): void
    {
        self::assertSame('1.333,3.14', Real::encodeList([1.333, 3.14]));
    }

    /**
     * A list with something in it that is no float is refused whole.
     */
    public function testAListWithSomethingElseInItIsRefused(): void
    {
        $this->expectException(ParseError::class);

        Real::decodeList('1.5,x');
    }
}
