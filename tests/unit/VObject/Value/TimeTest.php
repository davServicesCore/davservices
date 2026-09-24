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
use DavServices\VObject\Value\Time;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for `TIME`, derived from RFC 5545 §3.3.12.
 *
 *     time        = time-hour time-minute time-second [time-utc]
 *     time-hour   = 2DIGIT        ;00-23
 *     time-minute = 2DIGIT        ;00-59
 *     time-second = 2DIGIT        ;00-60
 *     ;The "60" value is used to account for positive "leap" seconds.
 *     time-utc    = "Z"
 *
 * **The plan does not name this type and the specification does**, so it is
 * here: `VALUE=TIME` is a thing a client may write, and the `DATE-TIME` type
 * is built out of it besides.
 *
 * Two rules worth having tests of their own:
 *
 * - **"The seconds value of 60 MUST only be used to account for positive
 *   'leap' seconds"** — so sixty is a second, and sixty-one is not.
 * - **"The form of time with UTC offset MUST NOT be used"**, with the
 *   specification's own counter-example: `230000-0800`.
 *
 * And: "Fractions of a second are not supported by this format."
 */
#[CoversClass(Time::class)]
final class TimeTest extends TestCase
{
    /**
     * The three parts, and no zone designator.
     */
    public function testReadsATimeOfDay(): void
    {
        $time = Time::decode('230000');

        self::assertSame(23, $time->hour());
        self::assertSame(0, $time->minute());
        self::assertSame(0, $time->second());
        self::assertFalse($time->isUtc());
    }

    /**
     * §3.3.12: `time-utc = "Z"`, "the UTC designator".
     */
    public function testTheZSaysItIsUtc(): void
    {
        self::assertTrue(Time::decode('070000Z')->isUtc());
    }

    /**
     * **Sixty seconds is a second**, because §3.3.12 keeps room for a
     * positive leap second — and a reader that refused it would turn away a
     * moment that has actually happened twenty-seven times.
     */
    public function testSixtySecondsIsALeapSecond(): void
    {
        self::assertSame(60, Time::decode('235960')->second());
    }

    /**
     * **What the grammar does not allow is refused**, and the offset form is
     * the one the specification troubles to write out: "The form of time with
     * UTC offset MUST NOT be used … `230000-0800` ;Invalid time format".
     *
     * @param non-empty-string $raw
     */
    #[DataProvider('whatIsNoTime')]
    public function testWhatIsNoTimeIsRefused(string $raw): void
    {
        $this->expectException(ParseError::class);

        Time::decode($raw);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function whatIsNoTime(): iterable
    {
        yield 'the offset form the specification forbids' => ['230000-0800'];

        yield 'and its positive twin' => ['230000+0100'];

        yield 'an hour of twenty-four' => ['240000'];

        yield 'a minute of sixty' => ['236000'];

        yield 'a second of sixty-one' => ['235961'];

        yield 'a fraction of a second, which the format does not support' => ['230000.5'];

        yield 'separators, which the format has none of' => ['23:00:00'];

        yield 'too few digits' => ['2300'];

        yield 'nothing at all' => [''];

        yield 'a lower-case designator' => ['070000z'];

        yield 'a time with something in front of it' => ['at 230000'];
    }

    /**
     * Written back as six digits, with the designator where there was one.
     *
     * @param non-empty-string $raw
     */
    #[DataProvider('whatSurvivesTheRoundTrip')]
    public function testSurvivesTheRoundTrip(string $raw): void
    {
        self::assertSame($raw, Time::decode($raw)->encode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function whatSurvivesTheRoundTrip(): iterable
    {
        yield 'local' => ['230000'];

        yield 'UTC' => ['070000Z'];

        yield 'midnight' => ['000000'];

        yield 'a leap second' => ['235960Z'];
    }
}
