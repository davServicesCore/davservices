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
use DavServices\VObject\Value\Integer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for `INTEGER`, derived from RFC 5545 §3.3.8 and RFC 6350 §4.5.
 *
 *     integer = (["+"] / "-") 1*DIGIT
 *
 * "If the sign is not specified, then the value is assumed to be positive."
 * And: "No additional content value encoding … is defined for this value
 * type", which is why nothing here undoes a backslash.
 *
 * ## The one place the two specifications disagree
 *
 * - RFC 5545 §3.3.8: "The valid range for 'integer' is -2147483648 to
 *   2147483647."
 * - RFC 6350 §4.5: "The maximum value is 9223372036854775807, and the minimum
 *   value is -9223372036854775808."
 *
 * Thirty-two bits against sixty-four, for the same grammar. **So the range is
 * not part of reading the value** — one class cannot hold both rules, and
 * which of them applies depends on which file is in front of it. What is
 * refused here is only what will not fit in a PHP integer at all, which is
 * RFC 6350's range exactly; RFC 5545's narrower one is a rule about iCalendar
 * and belongs with the validation of P4-06.
 */
#[CoversClass(Integer::class)]
final class IntegerTest extends TestCase
{
    /**
     * `1*DIGIT`, the ordinary case.
     */
    public function testReadsADecimalNumber(): void
    {
        self::assertSame(1234567890, Integer::decode('1234567890'));
    }

    /**
     * **"If the sign is not specified, then the value is assumed to be
     * positive"** — and a `+` that is specified means the same thing.
     *
     * @param non-empty-string $raw
     */
    #[DataProvider('theThreeSigns')]
    public function testEachSignIsRead(string $raw, int $expected): void
    {
        self::assertSame($expected, Integer::decode($raw));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function theThreeSigns(): iterable
    {
        yield 'none at all' => ['432109876', 432109876];

        yield 'an explicit plus' => ['+1234556790', 1234556790];

        yield 'a minus' => ['-1234556790', -1234556790];
    }

    /**
     * **Zero is an ordinary integer**, and written with nothing but zeros.
     * `SEQUENCE:0` opens every event RFC 5545 §3.8.7.4 describes, and
     * `PERCENT-COMPLETE:0` is a task nobody has started.
     *
     * @param non-empty-string $raw
     */
    #[DataProvider('theWaysOfWritingZero')]
    public function testZeroIsRead(string $raw): void
    {
        self::assertSame(0, Integer::decode($raw));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function theWaysOfWritingZero(): iterable
    {
        yield 'a single zero' => ['0'];

        yield 'several of them' => ['000'];

        yield 'with the optional plus' => ['+0'];

        yield 'and a signed zero, which is still zero' => ['-0'];
    }

    /**
     * **Leading zeros are allowed and say nothing**: `1*DIGIT` puts no
     * bound on how many digits there are, so `007` is seven.
     */
    public function testLeadingZerosAreRead(): void
    {
        self::assertSame(7, Integer::decode('007'));
        self::assertSame(-7, Integer::decode('-007'));
    }

    /**
     * **What the grammar does not allow is refused.** `1*DIGIT` is digits and
     * nothing else: no spaces, no decimal point, no exponent, and at least
     * one digit.
     *
     * @param non-empty-string $raw
     */
    #[DataProvider('whatIsNoInteger')]
    public function testWhatIsNoIntegerIsRefused(string $raw): void
    {
        $this->expectException(ParseError::class);

        Integer::decode($raw);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function whatIsNoInteger(): iterable
    {
        yield 'nothing at all' => [''];

        yield 'a sign on its own' => ['-'];

        yield 'a decimal point' => ['1.5'];

        yield 'an exponent' => ['1e5'];

        yield 'surrounding space' => [' 12 '];

        yield 'a thousands separator' => ['1,000'];

        yield 'letters' => ['twelve'];

        yield 'a number too large for sixty-four bits' => ['9223372036854775808'];
    }

    /**
     * **A refusal says which of the two rules was broken.** The grammar and
     * the range are different mistakes, and somebody reading a log wants to
     * know whether the value was never a number or merely too large for one.
     *
     * @param non-empty-string $raw
     */
    #[DataProvider('theTwoWaysOfBeingRefused')]
    public function testARefusalSaysWhichRuleWasBroken(string $raw, string $says): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage($says);

        Integer::decode($raw);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function theTwoWaysOfBeingRefused(): iterable
    {
        yield 'no integer at all' => ['1.5', 'is no integer'];

        yield 'and one with a thousands separator' => ['1,000', 'is no integer'];

        yield 'too large to hold' => ['9223372036854775808', 'outside the range'];
    }

    /**
     * RFC 6350 §4.5's own limits, which are those of a signed sixty-four bit
     * integer, are read exactly.
     */
    public function testTheLimitsOfSixtyFourBitsAreRead(): void
    {
        self::assertSame(PHP_INT_MAX, Integer::decode('9223372036854775807'));
        self::assertSame(PHP_INT_MIN, Integer::decode('-9223372036854775808'));
    }

    /**
     * **RFC 5545's narrower range is not enforced here**, and that is the
     * decision: the same grammar carries two different limits depending on
     * the file, so the limit is a rule about the format rather than about
     * reading the number.
     */
    public function testTheNarrowerRangeOfICalendarIsLeftToValidation(): void
    {
        self::assertSame(2147483648, Integer::decode('2147483648'));
    }

    /**
     * Writing it back gives the plain decimal form, without the optional
     * plus: it is the one of the three spellings every reader takes.
     */
    public function testWritingGivesThePlainForm(): void
    {
        self::assertSame('42', Integer::encode(42));
        self::assertSame('-42', Integer::encode(-42));
    }

    /**
     * §3.3.8: "If the property permits, multiple 'integer' values are
     * specified by a COMMA-separated list of values." RFC 6350 §4.5 shows
     * `+1234556790,432109876`.
     */
    public function testAListIsSplitOnCommas(): void
    {
        self::assertSame([1234556790, 432109876], Integer::decodeList('+1234556790,432109876'));
    }

    /**
     * And is written back the same way.
     */
    public function testAListIsWrittenWithCommas(): void
    {
        self::assertSame('1,-2,3', Integer::encodeList([1, -2, 3]));
    }

    /**
     * One value is a list of one.
     */
    public function testOneValueIsAListOfOne(): void
    {
        self::assertSame([7], Integer::decodeList('7'));
    }

    /**
     * A list with something in it that is no integer is refused whole: a
     * partial answer would be a list of the wrong length, and whoever gets it
     * has no way to tell.
     */
    public function testAListWithSomethingElseInItIsRefused(): void
    {
        $this->expectException(ParseError::class);

        Integer::decodeList('1,x,3');
    }
}
