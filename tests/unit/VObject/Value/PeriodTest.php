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
use DavServices\VObject\Value\Period;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for `PERIOD`, derived from RFC 5545 §3.3.9.
 *
 *     period          = period-explicit / period-start
 *     period-explicit = date-time "/" date-time
 *     period-start    = date-time "/" dur-value
 *
 * **Two forms, and each carries a MUST of its own.**
 *
 * - `period-explicit`: "The start MUST be before the end."
 * - `period-start`: "a period of time consisting of a start and **positive**
 *   duration of time."
 *
 * The two agree with each other: a period that begins and ends at the same
 * moment is refused either way it is written.
 *
 * ## How "before" is decided
 *
 * By the wall clock as written. A `DATE-TIME` value knows whether it carries
 * the UTC designator and nothing more — form #3 of §3.3.5 hangs on a `TZID`
 * parameter, and a parameter belongs to the property. So a period whose two
 * halves are written in different forms is compared as it stands, and whether
 * such a period means anything at all is a question for the validation of
 * P4-06.
 */
#[CoversClass(Period::class)]
final class PeriodTest extends TestCase
{
    /**
     * §3.3.9's first example: "The period starting at 18:00:00 UTC, on
     * January 1, 1997 and ending at 07:00:00 UTC on January 2, 1997".
     */
    public function testReadsTheExplicitForm(): void
    {
        $period = Period::decode('19970101T180000Z/19970102T070000Z');

        self::assertSame('19970101T180000Z', $period->start()->encode());
        self::assertSame('19970102T070000Z', $period->end()?->encode());
        self::assertNull($period->duration());
    }

    /**
     * §3.3.9's second: "The period start at 18:00:00 on January 1, 1997 and
     * lasting 5 hours and 30 minutes".
     */
    public function testReadsTheDurationForm(): void
    {
        $period = Period::decode('19970101T180000Z/PT5H30M');

        self::assertSame('19970101T180000Z', $period->start()->encode());
        self::assertSame('PT5H30M', $period->duration()?->encode());
        self::assertNull($period->end());
    }

    /**
     * Both forms are written back exactly as they were read: a period is two
     * halves and a solidus, and nothing about it is normalised.
     *
     * @param non-empty-string $raw
     */
    #[DataProvider('bothFormsOfTheSpecification')]
    public function testSurvivesTheRoundTrip(string $raw): void
    {
        self::assertSame($raw, Period::decode($raw)->encode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bothFormsOfTheSpecification(): iterable
    {
        yield 'start and end' => ['19970101T180000Z/19970102T070000Z'];

        yield 'start and duration' => ['19970101T180000Z/PT5H30M'];

        yield 'a local period' => ['19970101T180000/19970101T190000'];

        yield 'a whole week' => ['19970101T000000Z/P1W'];

        yield 'a duration written with the optional plus' => ['19970101T180000Z/PT1H'];
    }

    /**
     * **The optional plus still makes it a duration.** `dur-value = (["+"] /
     * "-") "P" …`, so the sign may be there — and a reader that looked only
     * for a bare `P` would take `+PT1H` for a date and time and refuse the
     * whole period.
     */
    public function testADurationMayCarryTheOptionalPlus(): void
    {
        $period = Period::decode('19970101T180000Z/+PT1H');

        self::assertSame('PT1H', $period->duration()?->encode());
        self::assertNull($period->end());
    }

    /**
     * **§3.3.9: "The start MUST be before the end."** A period that ends
     * before it begins is not a period, and one busy time reported backwards
     * takes a whole free/busy answer with it.
     */
    public function testAPeriodThatEndsBeforeItBeginsIsRefused(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('before');

        Period::decode('19970102T070000Z/19970101T180000Z');
    }

    /**
     * And one that ends where it begins is refused too: "before" is not
     * "before or at".
     */
    public function testAPeriodOfNoLengthIsRefused(): void
    {
        $this->expectException(ParseError::class);

        Period::decode('19970101T180000Z/19970101T180000Z');
    }

    /**
     * **§3.3.9 asks the duration form for a "positive duration of time"**, so
     * a negative one is refused — and every `TRIGGER:-PT15M` shows how easily
     * one is written.
     */
    public function testANegativeDurationIsRefused(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('positive');

        Period::decode('19970101T180000Z/-PT5H');
    }

    /**
     * And a duration of nothing is refused for the same reason the explicit
     * form refuses a period of no length: the two forms say the same thing
     * and must agree.
     */
    public function testADurationOfNothingIsRefused(): void
    {
        $this->expectException(ParseError::class);

        Period::decode('19970101T180000Z/PT0S');
    }

    /**
     * **What is neither form is refused.**
     *
     * @param non-empty-string $raw
     */
    #[DataProvider('whatIsNoPeriod')]
    public function testWhatIsNoPeriodIsRefused(string $raw): void
    {
        $this->expectException(ParseError::class);

        Period::decode($raw);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function whatIsNoPeriod(): iterable
    {
        yield 'no solidus at all' => ['19970101T180000Z'];

        yield 'nothing after the solidus' => ['19970101T180000Z/'];

        yield 'nothing before it' => ['/19970102T070000Z'];

        yield 'a start that is no date-time' => ['19970101/19970102T070000Z'];

        yield 'an end that is neither a date-time nor a duration' => ['19970101T180000Z/tomorrow'];

        yield 'three halves' => ['19970101T180000Z/19970102T070000Z/PT1H'];

        yield 'nothing at all' => [''];
    }
}
