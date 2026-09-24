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
use DavServices\VObject\Value\DateTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for `DATE-TIME`, derived from RFC 5545 §3.3.5.
 *
 *     date-time = date "T" time
 *
 * **This is where R-TZ-03 lives**, and §3.3.5 is unusually explicit about
 * why. It names three forms and rules a fourth out:
 *
 * > "The form of date and time with UTC offset MUST NOT be used. For example,
 * > the following is not valid for a DATE-TIME value: `19980119T230000-0800`"
 *
 * **FORM #1, local time:** "DATE-TIME values of this type are said to be
 * 'floating' and are not bound to any time zone in particular. They are used
 * to represent the same hour, minute, and second value regardless of which
 * time zone is currently being observed."
 *
 * **FORM #2, UTC:** "identified by a LATIN CAPITAL LETTER Z suffix character".
 *
 * **FORM #3, local time with a time zone reference**, which is form #1 plus a
 * `TZID` parameter — and the parameter belongs to the property, not to the
 * value. So **this class can tell UTC from local and no more**, which is
 * exactly as much as the value says.
 *
 * ## Why there is no method that simply hands over a moment
 *
 * §3.3.5: "The use of local time in a DATE-TIME value without the 'TZID'
 * property parameter is to be interpreted as floating time, **regardless of
 * the existence of 'VTIMEZONE' calendar components** in the iCalendar
 * object."
 *
 * A floating time has no instant until somebody names a zone. So
 * {@see DateTime::instant()} answers null for anything that is not UTC, and
 * {@see DateTime::in()} is where a zone gets named and the caller can be held
 * to it. Quietly reading a floating time as UTC is the fault that moves every
 * appointment in the file by the offset of wherever the server happens to
 * stand.
 */
#[CoversClass(DateTime::class)]
final class DateTimeTest extends TestCase
{
    /**
     * §3.3.5's FORM #1 example: "the following represents January 18, 1998,
     * at 11 PM".
     */
    public function testReadsTheLocalForm(): void
    {
        $value = DateTime::decode('19980118T230000');

        self::assertSame(1998, $value->date()->year());
        self::assertSame(23, $value->time()->hour());
        self::assertFalse($value->isUtc());
    }

    /**
     * §3.3.5's FORM #2 example: "the following represents January 19, 1998,
     * at 0700 UTC".
     */
    public function testReadsTheUtcForm(): void
    {
        $value = DateTime::decode('19980119T070000Z');

        self::assertTrue($value->isUtc());
        self::assertSame('1998-01-19T07:00:00+00:00', $value->instant()?->format('c'));
    }

    /**
     * **R-TZ-03, said as plainly as a test can say it.** A value with no `Z`
     * is local, and a local value has no instant until a zone is named — so
     * there is none to be had here, and nothing can quietly invent one.
     */
    public function testALocalTimeHasNoMomentOfItsOwn(): void
    {
        self::assertNull(DateTime::decode('19980118T230000')->instant());
    }

    /**
     * And where a zone **is** named, the same wall clock is two different
     * moments — which is what "floating" means: "the same hour, minute, and
     * second value regardless of which time zone is currently being
     * observed".
     */
    public function testALocalTimeIsTheSameWallClockInEveryZone(): void
    {
        $value = DateTime::decode('19980118T230000');

        self::assertSame(
            '1998-01-18T23:00:00+00:00',
            $value->in(new DateTimeZone('Europe/London'))->format('c'),
        );
        self::assertSame(
            '1998-01-18T23:00:00-05:00',
            $value->in(new DateTimeZone('America/New_York'))->format('c'),
        );
    }

    /**
     * A UTC value put in a zone is the same instant said differently, rather
     * than the same wall clock: the `Z` has already fixed it.
     */
    public function testAUtcValueKeepsItsInstantInAnyZone(): void
    {
        $value = DateTime::decode('19980119T070000Z');

        self::assertSame(
            '1998-01-19T02:00:00-05:00',
            $value->in(new DateTimeZone('America/New_York'))->format('c'),
        );
    }

    /**
     * **The fourth form is refused**, and §3.3.5 troubles to write it out:
     * "The form of date and time with UTC offset MUST NOT be used …
     * `19980119T230000-0800` ;Invalid time format".
     *
     * @param non-empty-string $raw
     */
    #[DataProvider('whatIsNoDateTime')]
    public function testWhatIsNoDateTimeIsRefused(string $raw): void
    {
        $this->expectException(ParseError::class);

        DateTime::decode($raw);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function whatIsNoDateTime(): iterable
    {
        yield 'the offset form the specification forbids' => ['19980119T230000-0800'];

        yield 'and its positive twin' => ['19980119T230000+0100'];

        yield 'a date with no time' => ['19980119'];

        yield 'a time with no date' => ['T230000'];

        yield 'no T between them' => ['19980119230000'];

        yield 'a lower-case T' => ['19980119t230000'];

        yield 'a date that is no date' => ['19980229T230000'];

        yield 'a time that is no time' => ['19980119T240000'];

        yield 'separators, which the format has none of' => ['1998-01-19T23:00:00'];

        yield 'nothing at all' => [''];
    }

    /**
     * Written back exactly as the grammar has it, `Z` and all.
     *
     * @param non-empty-string $raw
     */
    #[DataProvider('whatSurvivesTheRoundTrip')]
    public function testSurvivesTheRoundTrip(string $raw): void
    {
        self::assertSame($raw, DateTime::decode($raw)->encode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function whatSurvivesTheRoundTrip(): iterable
    {
        yield 'local' => ['19980118T230000'];

        yield 'UTC' => ['19980119T070000Z'];

        yield 'the turn of a century' => ['20000101T000000Z'];

        yield 'a leap day' => ['20240229T120000'];
    }
}
