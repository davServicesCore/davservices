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

namespace DavServices\Tests\Unit\VObject\Recur;

use DavServices\VObject\ParseError;
use DavServices\VObject\Recur\Weekday;
use DavServices\VObject\Recur\WeekdayNumber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for one entry of a `BYDAY` list, from RFC 5545 §3.3.10.
 *
 *     weekdaynum = [[plus / minus] ordwk] weekday
 *     ordwk      = 1*2DIGIT       ;1 to 53
 *     weekday    = "SU" / "MO" / "TU" / "WE" / "TH" / "FR" / "SA"
 *
 * **The ordinal is the whole reason the type exists.** §3.3.10: "within a
 * MONTHLY rule, +1MO (or simply 1MO) represents the first Monday within the
 * month, whereas -1MO represents the last Monday of the month." And where
 * there is none, "it means all days of this type within the specified
 * frequency" — so the absence of a number is a meaning of its own rather than
 * a gap.
 *
 * It is tested on its own as well as through {@see RuleTest}, because a rule
 * only ever reaches it with values a rule happens to carry, and `ordwk`'s
 * range has two ends.
 */
#[CoversClass(WeekdayNumber::class)]
#[CoversClass(Weekday::class)]
final class WeekdayNumberTest extends TestCase
{
    /**
     * The forms the grammar allows, read and written back.
     *
     * @param non-empty-string $raw
     */
    #[DataProvider('theFormsTheGrammarAllows')]
    public function testEachFormSurvivesTheRoundTrip(string $raw): void
    {
        self::assertSame($raw, WeekdayNumber::decode($raw)->encode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function theFormsTheGrammarAllows(): iterable
    {
        yield 'a bare day' => ['MO'];

        yield 'the first of them' => ['1MO'];

        yield 'the last of them' => ['-1MO'];

        yield 'the fifty-third, which is as far as ordwk goes' => ['53SU'];

        yield 'and the fifty-third from the end' => ['-53SA'];

        yield 'a Tuesday, which is not a Thursday' => ['TU'];

        yield 'a Thursday, which is not a Tuesday' => ['TH'];
    }

    /**
     * **Both halves are handed back separately**, because a caller expanding
     * a rule needs the day to find and the ordinal to count.
     */
    public function testBothHalvesAreHandedBack(): void
    {
        $day = WeekdayNumber::decode('-2FR');

        self::assertSame(Weekday::Friday, $day->day());
        self::assertSame(-2, $day->ordinal());
    }

    /**
     * **And a bare day says its ordinal is absent** rather than naming a
     * number nobody wrote. "If an integer modifier is not present, it means
     * all days of this type within the specified frequency."
     */
    public function testABareDayHasNoOrdinalAtAll(): void
    {
        $day = WeekdayNumber::decode('WE');

        self::assertSame(Weekday::Wednesday, $day->day());
        self::assertNull($day->ordinal());
    }

    /**
     * **"+1MO (or simply 1MO)"** — the specification says in as many words
     * that the plus adds nothing, so it is read and not written back.
     */
    public function testTheOptionalPlusIsDroppedOnTheWayOut(): void
    {
        self::assertSame('1MO', WeekdayNumber::decode('+1MO')->encode());
        self::assertSame(1, WeekdayNumber::decode('+1MO')->ordinal());
    }

    /**
     * Every day of the week is read, and `TU` and `TH` are the pair worth
     * naming twice.
     */
    public function testEveryDayOfTheWeekIsRead(): void
    {
        $days = [];

        foreach (['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'] as $raw) {
            $days[] = WeekdayNumber::decode($raw)->day();
        }

        self::assertSame(
            [
                Weekday::Sunday, Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday,
                Weekday::Thursday, Weekday::Friday, Weekday::Saturday,
            ],
            $days,
        );
    }

    /**
     * **What the grammar does not allow is refused.**
     *
     * @param non-empty-string $raw
     */
    #[DataProvider('whatIsNoWeekdayNumber')]
    public function testWhatIsNoWeekdayNumberIsRefused(string $raw): void
    {
        $this->expectException(ParseError::class);

        WeekdayNumber::decode($raw);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function whatIsNoWeekdayNumber(): iterable
    {
        yield 'nothing at all' => [''];

        yield 'a number with no day' => ['1'];

        yield 'a day nobody has heard of' => ['XX'];

        yield 'a day spelled out' => ['MON'];

        yield 'lower case, which §2 makes a different value' => ['mo'];

        yield 'a sign with no number' => ['+MO'];

        yield 'three digits, where ordwk has 1*2DIGIT' => ['100MO'];

        yield 'a fraction' => ['1.5MO'];

        yield 'something after the day' => ['1MOX'];

        yield 'the nought-th of them' => ['0MO'];

        yield 'the nought-th from the end' => ['-0MO'];

        yield 'the fifty-fourth, which is past ordwk' => ['54MO'];

        yield 'and the fifty-fourth from the end' => ['-54MO'];
    }

    /**
     * **A refusal says what it saw**, because a calendar of ten thousand
     * lines has one bad `BYDAY` in it.
     */
    #[DataProvider('refusalsAndWhatTheyName')]
    public function testARefusalSaysWhatItSaw(string $raw, string $named): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage($named);

        WeekdayNumber::decode($raw);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusalsAndWhatTheyName(): iterable
    {
        yield 'an ordinal past ordwk names the whole entry' => ['54MO', '54MO'];

        yield 'a day nobody has heard of names the day' => ['1XX', 'XX'];

        yield 'and so does a bare one' => ['XX', 'XX'];
    }
}
