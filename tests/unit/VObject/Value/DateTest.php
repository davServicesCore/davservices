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

use DateTimeZone;
use DavServices\VObject\ParseError;
use DavServices\VObject\Value\Date;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for `DATE`, derived from RFC 5545 §3.3.4.
 *
 *     date-value    = date-fullyear date-month date-mday
 *     date-fullyear = 4DIGIT
 *     date-month    = 2DIGIT        ;01-12
 *     date-mday     = 2DIGIT        ;01-28, 01-29, 01-30, 01-31
 *                                   ;based on month/year
 *
 * **"based on month/year" is a rule, not a comment.** The thirty-first of
 * February is not a date, and neither is the twenty-ninth of a year that is
 * not a leap year.
 *
 * ## R-TZ-04: a date is not a moment
 *
 * `VALUE=DATE` says a day, and a day has no instant until somebody names a
 * time zone. **So there is no method here that hands one over.** Turning a
 * date into midnight UTC is the mistake that moves an all-day event a day
 * either way for everybody west or east of Greenwich — and it is a mistake a
 * class can simply not offer.
 *
 * What it offers instead is {@see Date::at()}, where the caller names the
 * zone and can therefore be held to it.
 */
#[CoversClass(Date::class)]
final class DateTest extends TestCase
{
    /**
     * §3.3.4's own example: "The following represents July 14, 1997".
     */
    public function testReadsTheExampleFromTheSpecification(): void
    {
        $date = Date::decode('19970714');

        self::assertSame(1997, $date->year());
        self::assertSame(7, $date->month());
        self::assertSame(14, $date->day());
    }

    /**
     * And writes it back exactly: four digits, two, two, with nothing
     * between them.
     */
    public function testIsWrittenBackAsEightDigits(): void
    {
        self::assertSame('19970714', Date::decode('19970714')->encode());
    }

    /**
     * **The twenty-ninth of February exists in a leap year** — "01-28,
     * 01-29, 01-30, 01-31 based on month/year".
     */
    public function testTheTwentyNinthOfFebruaryIsADateInALeapYear(): void
    {
        self::assertSame(29, Date::decode('20240229')->day());
    }

    /**
     * **And not otherwise.** 1900 is the one every implementation gets wrong:
     * it is divisible by four and is not a leap year.
     *
     * @param non-empty-string $raw
     */
    #[DataProvider('whatIsNoDate')]
    public function testWhatIsNoDateIsRefused(string $raw): void
    {
        $this->expectException(ParseError::class);

        Date::decode($raw);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function whatIsNoDate(): iterable
    {
        yield 'the twenty-ninth of February in a common year' => ['20230229'];

        yield 'and in 1900, which is divisible by four' => ['19000229'];

        yield 'the thirty-first of February' => ['20240231'];

        yield 'the thirty-first of April' => ['20240431'];

        yield 'a month of zero' => ['20240015'];

        yield 'a month of thirteen' => ['20241315'];

        yield 'a day of zero' => ['20240100'];

        yield 'too few digits' => ['2024071'];

        yield 'too many' => ['202407141'];

        yield 'separators, which the format has none of' => ['2024-07-14'];

        yield 'nothing at all' => [''];

        yield 'letters' => ['2024JUL4'];

        yield 'a date with something in front of it' => ['on 19970714'];
    }

    /**
     * **R-TZ-04, said as plainly as a test can say it: a date becomes a
     * moment only where somebody names the zone.** The same day is two
     * different instants in London and in New York, and the class will not
     * choose between them.
     */
    public function testBecomesAMomentOnlyWhereAZoneIsNamed(): void
    {
        $date = Date::decode('19970714');

        self::assertSame(
            '1997-07-14T00:00:00+01:00',
            $date->at(new DateTimeZone('Europe/London'))->format('c'),
        );
        self::assertSame(
            '1997-07-14T00:00:00-04:00',
            $date->at(new DateTimeZone('America/New_York'))->format('c'),
        );
    }

    /**
     * And it is midnight at the start of that day, not the end of it — the
     * day begins when it begins.
     */
    public function testTheMomentIsTheStartOfTheDay(): void
    {
        self::assertSame(
            '2024-02-29 00:00:00',
            Date::decode('20240229')->at(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        );
    }

    /**
     * A year before the common era is not something the format has: four
     * digits, and nothing to say which era.
     */
    public function testTheEarliestAndLatestYearsTheFormatHasAreRead(): void
    {
        self::assertSame(1, Date::decode('00010101')->year());
        self::assertSame(9999, Date::decode('99991231')->year());
    }

    /**
     * What is written can be read back, which is the round trip R-VOBJ-05
     * wants at the level of one value.
     */
    public function testSurvivesTheRoundTrip(): void
    {
        self::assertSame('20240229', Date::decode('20240229')->encode());
    }
}
