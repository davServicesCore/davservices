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

use DavServices\VObject\Recur\ByRules;
use DavServices\VObject\Recur\Iterator;
use DavServices\VObject\Recur\Rule;
use DavServices\VObject\Value\Date;
use DavServices\VObject\Value\DateTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for `BYSETPOS`, from RFC 5545 §3.3.10 and §3.8.5.3. R-RRULE-01.
 *
 * **The last rule part, and the one the memo sets furthest apart.** It is the
 * only part that needs a whole interval at once:
 *
 * > The BYSETPOS rule part specifies a COMMA-separated list of values that
 * > corresponds to the nth occurrence within the set of recurrence instances
 * > specified by the rule. **BYSETPOS operates on a set of recurrence
 * > instances in one interval of the recurrence rule.** For example, in a
 * > WEEKLY rule, the interval would be one week.
 *
 * ## Two sentences decide the whole design
 *
 * **"A set of recurrence instances starts at the beginning of the interval
 * defined by the FREQ rule part."** So the counting begins where the interval
 * begins and not at `DTSTART` — and the memo's own first example proves it,
 * which is why it is here rather than only quoted. The third Tuesday,
 * Wednesday or Thursday of September 1997 is the **fourth** of September,
 * counted from the first of the month; counted from a `DTSTART` of the fourth
 * it would be the tenth, and the memo publishes the fourth.
 *
 * **And the evaluation order puts it last**: "BYMONTH, BYWEEKNO, BYYEARDAY,
 * BYMONTHDAY, BYDAY, BYHOUR, BYMINUTE, BYSECOND and BYSETPOS; then COUNT and
 * UNTIL are evaluated." So the set it counts through is what every other part
 * left, and what it throws away is thrown away **before** `COUNT` counts and
 * before `UNTIL` bounds. Both halves are tested.
 *
 * ## What it does not change
 *
 * §3.8.5.3 still holds: "The 'DTSTART' property defines the first instance in
 * the recurrence set." A position may well name an instance earlier than the
 * start — the first weekday of the month that the start falls in the middle of
 * — and that instance is counted for the position and then left out of the
 * set. The two sentences are about different things, and a test says so.
 *
 * `Rule` has refused a nonsensical `BYSETPOS` since P4-06c: the range, "Valid
 * values are 1 to 366 or -366 to -1", and the one condition the part carries,
 * "It MUST only be used in conjunction with another BYxxx rule part".
 */
#[CoversClass(ByRules::class)]
#[CoversClass(Iterator::class)]
final class BySetPositionTest extends TestCase
{
    /**
     * **The memo's own two examples, rule and answer together.**
     *
     * @param non-empty-string $rule
     * @param non-empty-string $start
     * @param list<string> $instances
     */
    #[DataProvider('theExamplesOfTheSpecification')]
    public function testTheExamplesOfTheSpecificationComeOut(string $rule, string $start, array $instances): void
    {
        self::assertSame($instances, self::expand($rule, $start, count($instances)));
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function theExamplesOfTheSpecification(): iterable
    {
        yield 'the third instance into the month of one of Tuesday, Wednesday, or Thursday' => [
            'FREQ=MONTHLY;COUNT=3;BYDAY=TU,WE,TH;BYSETPOS=3',
            '19970904T090000',
            self::atNine(['19970904', '19971007', '19971106']),
        ];

        yield 'the second-to-last weekday of the month' => [
            'FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-2',
            '19970929T090000',
            self::atNine(['19970929', '19971030', '19971127', '19971230',
                '19980129', '19980226', '19980330']),
        ];
    }

    /**
     * **§3.3.10's own example**, which it gives without instances: "the last
     * work day of the month".
     */
    public function testTheLastWorkDayOfTheMonth(): void
    {
        self::assertSame(
            ['19970930', '19971031', '19971128', '19971231'],
            self::expand('FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1;COUNT=4', '19970929'),
        );
    }

    /**
     * **The set begins with the interval, and an instance before the start is
     * still left out.** Both sentences at once: counted from the first of
     * September, the third Tuesday, Wednesday or Thursday is the fourth — and
     * a rule starting on the twenty-fifth has passed it, so the first instance
     * is October's.
     *
     * Counted from the start instead, September's third would be the
     * twenty-eighth and would be in the set.
     */
    public function testTheSetBeginsWithTheIntervalAndTheStartStillBounds(): void
    {
        self::assertSame(
            ['19971007'],
            self::expand('FREQ=MONTHLY;BYDAY=TU,WE,TH;BYSETPOS=3;COUNT=1', '19970925'),
        );
    }

    /**
     * And the same the other way round: the first weekday of the month the
     * start falls in is the first of September, which the start has passed.
     */
    public function testAPositionNamingADayBeforeTheStartLosesThatInstance(): void
    {
        self::assertSame(
            ['19971001', '19971103'],
            self::expand('FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=1;COUNT=2', '19970915'),
        );
    }

    /**
     * **Several positions, in order however they were written** — §3.1.1:
     * "There is no significance to the order of values in a list." September
     * 1997 holds thirteen Tuesdays, Wednesdays and Thursdays; the first is the
     * second of the month and the last the thirtieth.
     */
    public function testSeveralPositionsComeOutInOrderHoweverTheyWereWritten(): void
    {
        self::assertSame(
            ['19970902', '19970930'],
            self::expand('FREQ=MONTHLY;BYDAY=TU,WE,TH;BYSETPOS=1,-1;COUNT=2', '19970901'),
        );
        self::assertSame(
            ['19970902', '19970930'],
            self::expand('FREQ=MONTHLY;BYDAY=TU,WE,TH;BYSETPOS=-1,1;COUNT=2', '19970901'),
        );
    }

    /**
     * **Two positions naming one instance hand it over once** — §3.8.5.3:
     * "Duplicate instances are ignored." A set of two holds its first instance
     * at position one and at position minus two.
     */
    public function testTwoPositionsNamingOneInstanceHandItOverOnce(): void
    {
        self::assertSame(
            ['19970101', '19970201'],
            self::expand('FREQ=MONTHLY;BYMONTHDAY=1,2;BYSETPOS=1,-2;COUNT=2', '19970101'),
        );
    }

    /**
     * **A position the set has not got names nothing**, which is the same
     * ignoring §3.3.10 asks for of a day that does not exist. A set of two has
     * no third member, and the rule comes to its second one alone.
     */
    public function testAPositionTheSetHasNotGotNamesNothing(): void
    {
        self::assertSame(
            ['19970102', '19970202'],
            self::expand('FREQ=MONTHLY;BYMONTHDAY=1,2;BYSETPOS=2,3;COUNT=2', '19970101'),
        );
    }

    /**
     * **And a position below the first names nothing either.** The range has
     * two ends — "Valid values are 1 to 366 or -366 to -1" — and a set of two
     * has no fifth-from-last member any more than it has a third.
     *
     * It is worth its own test because the two ends fail differently: a
     * position past the last reads past the end of the set, which is empty,
     * while one below the first would be read **from the other end** if it
     * were not refused.
     */
    public function testAPositionBelowTheFirstNamesNothingEither(): void
    {
        self::assertSame(
            ['19970102', '19970202'],
            self::expand('FREQ=MONTHLY;BYMONTHDAY=1,2;BYSETPOS=2,-5;COUNT=2', '19970101'),
        );
    }

    /**
     * **A day that does not exist is not in the set**, so it cannot be
     * counted: "Such recurrence instances MUST be ignored and MUST NOT be
     * counted as part of the recurrence set." The last of the twenty-eighth,
     * twenty-ninth, thirtieth and thirty-first is therefore the last day of
     * the month, whichever month it is — February 1997 has only the first of
     * the four.
     */
    public function testADayThatDoesNotExistIsNotCounted(): void
    {
        self::assertSame(
            ['19970131', '19970228', '19970331', '19970430'],
            self::expand('FREQ=MONTHLY;BYMONTHDAY=28,29,30,31;BYSETPOS=-1;COUNT=4', '19970101'),
        );
    }

    /**
     * **"For example, in a WEEKLY rule, the interval would be one week"** —
     * the memo says it of this part itself. The last weekday of each week is
     * its Friday.
     */
    public function testAWeeklyIntervalIsOneWeek(): void
    {
        self::assertSame(
            ['19970905', '19970912', '19970919'],
            self::expand('FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1;WKST=MO;COUNT=3', '19970901'),
        );
    }

    /**
     * **The table gives `BYSETPOS` `Limit` at every frequency**, the three
     * shorter than a day included. The second of three hours is noon.
     */
    public function testASetPositionCountsTheTimesOfADayToo(): void
    {
        self::assertSame(
            ['19970902T120000', '19970903T120000'],
            self::expand('FREQ=DAILY;BYHOUR=9,12,15;BYSETPOS=2;COUNT=2', '19970902T090000'),
        );
    }

    /**
     * **The positions are taken before `COUNT` counts.** Two positions a
     * month, so three occurrences reach into the second month.
     */
    public function testThePositionsAreTakenBeforeTheCountIsApplied(): void
    {
        self::assertSame(
            ['19970901', '19970930', '19971001'],
            self::expand('FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=1,-1;COUNT=3', '19970901'),
        );
    }

    /**
     * **And before `UNTIL` bounds.** November's last weekday is the
     * twenty-eighth, which is past the bound, so the set ends in October —
     * and the bound is not reached by any earlier instance of November's.
     */
    public function testThePositionsAreTakenBeforeTheBoundIsApplied(): void
    {
        self::assertSame(
            ['19970930', '19971031'],
            self::expand('FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1;UNTIL=19971115', '19970901'),
        );
    }

    /**
     * **The set is what every other part left**, the memo's evaluation order
     * putting this one last. `BYMONTHDAY` sets the days, `BYDAY` filters them
     * — Note 1: "Limit if BYMONTHDAY is present" — and only then is the last
     * of what remains taken.
     *
     * The first five days of January 1997 are a Wednesday, a Thursday and then
     * the weekend, so the set is the first and the second and its last member
     * is the second. February's first five begin on a Saturday, leaving the
     * fourth and the fifth.
     */
    public function testTheSetIsWhatTheOtherPartsLeft(): void
    {
        self::assertSame(
            ['19970102', '19970205'],
            self::expand('FREQ=MONTHLY;BYDAY=TU,WE,TH;BYMONTHDAY=1,2,3,4,5;BYSETPOS=-1;COUNT=2', '19970101'),
        );
    }

    /**
     * The instances a rule expands to from a start, as they are written.
     *
     * @param non-empty-string $rule
     * @param non-empty-string $start
     *
     * @return list<string>
     */
    private static function expand(string $rule, string $start, int $take = 500): array
    {
        $iterator = new Iterator(
            Rule::decode($rule),
            str_contains($start, 'T') ? DateTime::decode($start) : Date::decode($start),
        );

        $instances = [];

        foreach ($iterator->instances() as $instance) {
            $instances[] = $instance->encode();

            if (count($instances) === $take) {
                break;
            }
        }

        return $instances;
    }

    /**
     * The nine-in-the-morning of §3.8.5.3's examples, written onto a list of
     * days.
     *
     * @param list<string> $days
     *
     * @return list<string>
     */
    private static function atNine(array $days): array
    {
        $instances = [];

        foreach ($days as $day) {
            $instances[] = $day . 'T090000';
        }

        return $instances;
    }
}
