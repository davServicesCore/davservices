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
use DavServices\VObject\Value\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for `DURATION`, derived from RFC 5545 §3.3.6.
 *
 *     dur-value  = (["+"] / "-") "P" (dur-date / dur-time / dur-week)
 *     dur-date   = dur-day [dur-time]
 *     dur-time   = "T" (dur-hour / dur-minute / dur-second)
 *     dur-week   = 1*DIGIT "W"
 *     dur-hour   = 1*DIGIT "H" [dur-minute]
 *     dur-minute = 1*DIGIT "M" [dur-second]
 *     dur-second = 1*DIGIT "S"
 *     dur-day    = 1*DIGIT "D"
 *
 * **Read it closely and it says more than it looks.**
 *
 * - **No years and no months.** §3.3.6: "unlike [ISO.8601.2004], this value
 *   type doesn't support the 'Y' and 'M' designators to specify durations in
 *   terms of years and months." A month is not a length.
 * - **Weeks stand alone.** `dur-week` is an alternative to `dur-date` and
 *   `dur-time`, not a part of them — so `P1W` is a duration and `P1WT1H` is
 *   not.
 * - **The components run downwards without gaps.** `dur-hour` may be followed
 *   by minutes, and minutes by seconds. So `PT5H20S` skips a rung and is no
 *   duration by this grammar, however many clients write it. What the lenient
 *   mode of R-VOBJ-03 makes of that is P4-06's question.
 * - **Negative durations are the ordinary case, not the edge.** §3.3.6:
 *   "Negative durations are typically used to schedule an alarm to trigger
 *   before an associated time" — every `TRIGGER:-PT15M` in the world is one.
 *
 * ## Why a `DateInterval` is not enough on its own
 *
 * PHP's `DateInterval` has no idea of weeks: `P1W` goes in and seven days come
 * out, so writing it back would change somebody's file. The written form is
 * therefore kept here and the interval offered beside it, for arithmetic.
 */
#[CoversClass(Duration::class)]
final class DurationTest extends TestCase
{
    /**
     * The forms the grammar allows, read and written back unchanged.
     *
     * @param non-empty-string $raw
     */
    #[DataProvider('theFormsTheGrammarAllows')]
    public function testEachFormSurvivesTheRoundTrip(string $raw): void
    {
        self::assertSame($raw, Duration::decode($raw)->encode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function theFormsTheGrammarAllows(): iterable
    {
        yield 'a week' => ['P1W'];

        yield 'days' => ['P15D'];

        yield 'days and a time' => ['P15DT5H0M20S'];

        yield 'an hour on its own' => ['PT1H'];

        yield 'hours and minutes' => ['PT5H30M'];

        yield 'hours with the minutes spelled out as none' => ['PT5H0M'];

        yield 'minutes alone' => ['PT10M'];

        yield 'no minutes at all, spelled out' => ['PT0M'];

        yield 'and with seconds after them' => ['PT0M30S'];

        yield 'seconds alone' => ['PT30S'];

        yield 'minutes and seconds' => ['PT15M30S'];

        yield 'a negative one, which every alarm is' => ['-PT15M'];

        yield 'a negative week' => ['-P2W'];

        yield 'a negative nothing, which is still a sign' => ['-PT0S'];
    }

    /**
     * **The optional plus is read and not written back**, because it says the
     * same thing as no sign at all and the shorter form is what every reader
     * takes.
     */
    public function testTheOptionalPlusIsDroppedOnTheWayOut(): void
    {
        self::assertSame('P1D', Duration::decode('+P1D')->encode());
    }

    /**
     * §3.3.6: "Negative durations are typically used to schedule an alarm to
     * trigger before an associated time."
     */
    public function testANegativeDurationSaysSo(): void
    {
        self::assertTrue(Duration::decode('-PT15M')->isNegative());
        self::assertFalse(Duration::decode('PT15M')->isNegative());
    }

    /**
     * **A zero minute in the middle is kept**, because the grammar has no way
     * to write seconds after hours without it: `dur-hour = 1*DIGIT "H"
     * [dur-minute]` and `dur-minute = 1*DIGIT "M" [dur-second]`. Dropping it
     * would produce a value this very class refuses.
     */
    public function testAZeroInTheMiddleIsKept(): void
    {
        self::assertSame('P15DT5H0M20S', Duration::decode('P15DT5H0M20S')->encode());
        self::assertSame('PT1H0M30S', Duration::decode('PT1H0M30S')->encode());
    }

    /**
     * **What the grammar does not allow is refused**, and three of these are
     * things the specification troubles to rule out by name.
     *
     * @param non-empty-string $raw
     */
    #[DataProvider('whatIsNoDuration')]
    public function testWhatIsNoDurationIsRefused(string $raw): void
    {
        $this->expectException(ParseError::class);

        Duration::decode($raw);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function whatIsNoDuration(): iterable
    {
        yield 'a year, which §3.3.6 removes' => ['P1Y'];

        yield 'a month, which it removes too' => ['P1M'];

        yield 'a week with anything after it' => ['P1WT1H'];

        yield 'and a week after days' => ['P1D1W'];

        yield 'seconds straight after hours, skipping a rung' => ['PT5H20S'];

        yield 'a T with nothing after it' => ['P1DT'];

        yield 'a P with nothing after it' => ['P'];

        yield 'no P at all' => ['1D'];

        yield 'a time with no T' => ['P5H'];

        yield 'lower case' => ['p1d'];

        yield 'a fraction' => ['PT1.5H'];

        yield 'nothing at all' => [''];

        yield 'something in front of it' => ['xP1D'];
    }

    /**
     * **The interval is for arithmetic**, and carries the sign where there is
     * one: PHP marks that with `invert` rather than with negative numbers.
     */
    public function testTheIntervalCarriesTheSign(): void
    {
        self::assertSame(1, Duration::decode('-PT15M')->toDateInterval()->invert);
        self::assertSame(0, Duration::decode('PT15M')->toDateInterval()->invert);
        self::assertSame(15, Duration::decode('-PT15M')->toDateInterval()->i);
    }

    /**
     * **And a week becomes seven days in it**, which is where the written form
     * would have been lost had it not been kept separately.
     */
    public function testAWeekIsSevenDaysInTheInterval(): void
    {
        $duration = Duration::decode('P2W');

        self::assertSame(14, $duration->toDateInterval()->d);
        self::assertSame('P2W', $duration->encode(), 'and the written form is still a week');
    }

    /**
     * **And a week is only days**: nothing else creeps into the interval.
     */
    public function testAWeekBringsNoHoursOrMinutesWithIt(): void
    {
        $interval = Duration::decode('P2W')->toDateInterval();

        self::assertSame([14, 0, 0, 0], [$interval->d, $interval->h, $interval->i, $interval->s]);
    }

    /**
     * **A duration with no time part has no time in its interval either.**
     * `P15D` says days and nothing else, and an hour or a second that crept
     * in would move every alarm built on it.
     */
    public function testADurationOfDaysHasNoTimeInItsInterval(): void
    {
        $interval = Duration::decode('P15D')->toDateInterval();

        self::assertSame([15, 0, 0, 0], [$interval->d, $interval->h, $interval->i, $interval->s]);
    }

    /**
     * And one of seconds has nothing above them.
     */
    public function testADurationOfSecondsHasNothingAboveThem(): void
    {
        $interval = Duration::decode('PT30S')->toDateInterval();

        self::assertSame([0, 0, 0, 30], [$interval->d, $interval->h, $interval->i, $interval->s]);
    }

    /**
     * The parts of a longer one reach the interval unchanged.
     */
    public function testEveryPartReachesTheInterval(): void
    {
        $interval = Duration::decode('P15DT5H30M20S')->toDateInterval();

        self::assertSame([15, 5, 30, 20], [$interval->d, $interval->h, $interval->i, $interval->s]);
    }

    /**
     * **A duration of nothing is written as `PT0S`.** The grammar offers more
     * than one way to say it — `P0D` says the same — and this is the one that
     * is a duration in every form of the grammar.
     */
    public function testNothingIsWrittenAsZeroSeconds(): void
    {
        self::assertSame('PT0S', Duration::decode('P0D')->encode());
        self::assertSame('PT0S', Duration::decode('PT0S')->encode());
    }
}
