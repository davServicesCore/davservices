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
use DavServices\VObject\Recur\NotExpanded;
use DavServices\VObject\Recur\Rule;
use DavServices\VObject\Recur\TooManyIterations;
use DavServices\VObject\Recur\Weeks;
use DavServices\VObject\Value\Date;
use DavServices\VObject\Value\DateTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for the `BYxxx` rule parts that narrow and widen a recurrence
 * set, from RFC 5545 §3.3.10. R-RRULE-01.
 *
 * **The memo publishes the specification as a table**, which is unusual
 * enough to be worth saying: §3.3.10 gives, for every one of the nine parts
 * and every one of the seven frequencies, whether the part **expands** the
 * set or **limits** it — or is `N/A`, which "means that the corresponding
 * BYxxx rule part MUST NOT be used with the corresponding FREQ value". Every
 * `N/A` cell is already refused by {@see Rule} since P4-07.
 *
 * ## Six parts here, three in the chunk after
 *
 * **The memo draws the line itself.** It sets `BYDAY` apart in prose — "BYDAY
 * has some special behavior depending on the FREQ value and this is described
 * in separate notes below the table" — and those two notes are the only
 * conditional cells in it. And it evaluates `BYSETPOS` last of all, after
 * every other part has had its say. `BYWEEKNO` goes with them because its one
 * worked example does not stand without `BYDAY`, and because `BYDAY`'s second
 * note is written in terms of it.
 *
 * So this chunk is `BYMONTH`, `BYYEARDAY`, `BYMONTHDAY`, `BYHOUR`,
 * `BYMINUTE` and `BYSECOND`, and a rule carrying one of the other three is
 * **refused** rather than expanded without it. The memo licenses ignoring a
 * rule part exactly once and says so by name — "These rule parts MUST be
 * ignored in RECUR value that violate the above requirement" — and everywhere
 * else the parts "are applied". A set that quietly left one out would be a
 * set the memo does not define.
 *
 * ## Which field a part sets, and which it filters
 *
 * An instance is a year, a month, a day and a time. **Expand means the
 * field's values come from the rule; limit means the field has to be in the
 * rule's list.** That is what the table says, and the memo states the other
 * half in a note of its own: "Since none of the BYDAY, BYMONTHDAY, or
 * BYYEARDAY components are specified, **the day is gotten from 'DTSTART'**."
 *
 * ## Where the memo cannot be followed to the letter
 *
 * `BYYEARDAY` fixes a month **and** a day together, so in a `YEARLY` rule it
 * collides with `BYMONTH` and `BYMONTHDAY`, which the table also calls
 * `Expand`. Two parts cannot both set the month.
 *
 * **The memo resolves exactly this shape of collision elsewhere**, in
 * `BYDAY`'s Note 2: "Limit if BYYEARDAY or BYMONTHDAY is present" — the part
 * that names the coarser thing gives way and filters instead. Read the same
 * way here: where `BYYEARDAY` is present it sets the month and the day, and
 * `BYMONTH` and `BYMONTHDAY` limit. **That is a reading rather than a
 * quotation**, and it is marked as one because the table and the evaluation
 * order cannot both be satisfied.
 */
#[CoversClass(ByRules::class)]
#[CoversClass(Weeks::class)]
#[CoversClass(Iterator::class)]
#[CoversClass(NotExpanded::class)]
final class ByRulesTest extends TestCase
{
    /**
     * **The memo's own examples, rule and answer together.** §3.8.5.3
     * publishes eleven that use these six parts alone.
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
        yield 'monthly on the third-to-the-last day of the month, the first six of forever' => [
            'FREQ=MONTHLY;BYMONTHDAY=-3',
            '19970928T090000',
            self::atNine(['19970928', '19971029', '19971128', '19971229', '19980129', '19980226']),
        ];

        yield 'monthly on the 2nd and 15th for 10 occurrences' => [
            'FREQ=MONTHLY;COUNT=10;BYMONTHDAY=2,15',
            '19970902T090000',
            self::atNine(['19970902', '19970915', '19971002', '19971015', '19971102',
                '19971115', '19971202', '19971215', '19980102', '19980115']),
        ];

        yield 'monthly on the first and last day for 10 occurrences' => [
            'FREQ=MONTHLY;COUNT=10;BYMONTHDAY=1,-1',
            '19970930T090000',
            self::atNine(['19970930', '19971001', '19971031', '19971101', '19971130',
                '19971201', '19971231', '19980101', '19980131', '19980201']),
        ];

        yield 'every 18 months on the 10th thru 15th for 10 occurrences' => [
            'FREQ=MONTHLY;INTERVAL=18;COUNT=10;BYMONTHDAY=10,11,12,13,14,15',
            '19970910T090000',
            self::atNine(['19970910', '19970911', '19970912', '19970913', '19970914',
                '19970915', '19990310', '19990311', '19990312', '19990313']),
        ];

        yield 'yearly in June and July for 10 occurrences' => [
            'FREQ=YEARLY;COUNT=10;BYMONTH=6,7',
            '19970610T090000',
            self::atNine(['19970610', '19970710', '19980610', '19980710', '19990610',
                '19990710', '20000610', '20000710', '20010610', '20010710']),
        ];

        yield 'every other year in January, February and March for 10 occurrences' => [
            'FREQ=YEARLY;INTERVAL=2;COUNT=10;BYMONTH=1,2,3',
            '19970310T090000',
            self::atNine(['19970310', '19990110', '19990210', '19990310', '20010110',
                '20010210', '20010310', '20030110', '20030210', '20030310']),
        ];

        yield 'every third year on the 1st, 100th and 200th day for 10 occurrences' => [
            'FREQ=YEARLY;INTERVAL=3;COUNT=10;BYYEARDAY=1,100,200',
            '19970101T090000',
            self::atNine(['19970101', '19970410', '19970719', '20000101', '20000409',
                '20000718', '20030101', '20030410', '20030719', '20060101']),
        ];

        yield 'every day in January, for 3 years' => [
            'FREQ=DAILY;UNTIL=20000131T140000Z;BYMONTH=1',
            '19980101T090000',
            self::atNine(self::januaries()),
        ];

        yield 'an invalid date is ignored' => [
            'FREQ=MONTHLY;BYMONTHDAY=15,30;COUNT=5',
            '20070115T090000',
            self::atNine(['20070115', '20070130', '20070215', '20070315', '20070330']),
        ];
    }

    /**
     * **"Every 20 minutes from 9:00 AM to 4:40 PM every day"**, which the
     * memo gives twice over — "`FREQ=DAILY;BYHOUR=9,…,16;BYMINUTE=0,20,40`
     * **or** `FREQ=MINUTELY;INTERVAL=20;BYHOUR=9,…,16`" — and says the two
     * come to the same thing.
     *
     * **The table is what makes them agree.** `BYHOUR` expands a `DAILY` rule
     * and limits a `MINUTELY` one; the first builds the day out of the hours
     * it names, the second steps every twenty minutes and throws away what
     * falls outside them.
     *
     * @param non-empty-string $rule
     */
    #[DataProvider('theTwoWaysOfSayingEveryTwentyMinutes')]
    public function testEitherWayOfSayingEveryTwentyMinutesComesToTheSame(string $rule): void
    {
        self::assertSame(
            ['19970902T090000', '19970902T092000', '19970902T094000', '19970902T100000'],
            self::expand($rule, '19970902T090000', 4),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function theTwoWaysOfSayingEveryTwentyMinutes(): iterable
    {
        yield 'as a daily rule, where BYHOUR expands' => [
            'FREQ=DAILY;BYHOUR=9,10,11,12,13,14,15,16;BYMINUTE=0,20,40',
        ];

        yield 'as a minutely rule, where BYHOUR limits' => [
            'FREQ=MINUTELY;INTERVAL=20;BYHOUR=9,10,11,12,13,14,15,16',
        ];
    }

    /**
     * And the day of it holds twenty-four, after which the next day begins
     * again at nine: eight hours by three minutes, and nothing past 16:40.
     */
    public function testTheDayOfItHoldsTwentyFourAndThenBeginsAgain(): void
    {
        $instances = self::expand('FREQ=DAILY;BYHOUR=9,10,11,12,13,14,15,16;BYMINUTE=0,20,40', '19970902T090000', 25);

        self::assertSame(['19970902T164000'], array_slice($instances, 23, 1));
        self::assertSame(['19970903T090000'], array_slice($instances, 24, 1));
    }

    /**
     * **The table, in both directions, part by part.** Each of the six
     * expands at one frequency and limits at another, and the two readings
     * have to come out differently or the table would say nothing.
     *
     * @param non-empty-string $rule
     * @param non-empty-string $start
     * @param list<string> $instances
     */
    #[DataProvider('eachPartExpandingAndLimiting')]
    public function testEachPartExpandsAtOneFrequencyAndLimitsAtAnother(
        string $rule,
        string $start,
        array $instances,
    ): void {
        self::assertSame($instances, self::expand($rule, $start, count($instances)));
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function eachPartExpandingAndLimiting(): iterable
    {
        yield 'BYMONTH expands a yearly rule' => [
            'FREQ=YEARLY;BYMONTH=3,6;COUNT=3', '19970315', ['19970315', '19970615', '19980315'],
        ];

        yield 'BYMONTH limits a monthly one' => [
            'FREQ=MONTHLY;BYMONTH=3,6;COUNT=3', '19970315', ['19970315', '19970615', '19980315'],
        ];

        yield 'BYMONTHDAY expands a monthly rule' => [
            'FREQ=MONTHLY;BYMONTHDAY=5,20;COUNT=3', '19970105', ['19970105', '19970120', '19970205'],
        ];

        yield 'BYMONTHDAY limits a daily one' => [
            'FREQ=DAILY;BYMONTHDAY=5,20;COUNT=3', '19970105', ['19970105', '19970120', '19970205'],
        ];

        yield 'BYYEARDAY expands a yearly rule' => [
            'FREQ=YEARLY;BYYEARDAY=1,365;COUNT=3', '19970101', ['19970101', '19971231', '19980101'],
        ];

        yield 'BYYEARDAY limits an hourly one' => [
            'FREQ=HOURLY;BYYEARDAY=1;COUNT=3', '19970101T000000',
            ['19970101T000000', '19970101T010000', '19970101T020000'],
        ];

        yield 'BYHOUR expands a daily rule' => [
            'FREQ=DAILY;BYHOUR=6,18;COUNT=3', '19970101T060000',
            ['19970101T060000', '19970101T180000', '19970102T060000'],
        ];

        yield 'BYHOUR limits an hourly one' => [
            'FREQ=HOURLY;BYHOUR=6,18;COUNT=3', '19970101T060000',
            ['19970101T060000', '19970101T180000', '19970102T060000'],
        ];

        yield 'BYMINUTE expands an hourly rule' => [
            'FREQ=HOURLY;BYMINUTE=5,35;COUNT=3', '19970101T000500',
            ['19970101T000500', '19970101T003500', '19970101T010500'],
        ];

        yield 'BYMINUTE limits a minutely one' => [
            'FREQ=MINUTELY;BYMINUTE=5,35;COUNT=3', '19970101T000500',
            ['19970101T000500', '19970101T003500', '19970101T010500'],
        ];

        yield 'BYSECOND expands a minutely rule' => [
            'FREQ=MINUTELY;BYSECOND=10,40;COUNT=3', '19970101T000010',
            ['19970101T000010', '19970101T000040', '19970101T000110'],
        ];

        yield 'BYSECOND limits a secondly one' => [
            'FREQ=SECONDLY;BYSECOND=10,40;COUNT=3', '19970101T000010',
            ['19970101T000010', '19970101T000040', '19970101T000110'],
        ];
    }

    /**
     * **"Since none of the BYDAY, BYMONTHDAY, or BYYEARDAY components are
     * specified, the day is gotten from 'DTSTART'."** The memo says it in a
     * note of its own, and it is the half of the table that is easy to
     * forget: a field no part names keeps the start's value.
     */
    public function testAFieldNoPartNamesKeepsTheStartsValue(): void
    {
        self::assertSame(
            ['19970610T143000', '19970710T143000'],
            self::expand('FREQ=YEARLY;BYMONTH=6,7;COUNT=2', '19970610T143000'),
        );
    }

    /**
     * **A negative day counts back from the end**, and the memo gives the
     * arithmetic twice: "-10 represents the tenth to the last day of the
     * month", and "-1 represents the last day of the year (December 31st) and
     * -306 represents the 306th to the last day of the year (March 1st)".
     *
     * @param non-empty-string $rule
     * @param non-empty-string $start
     * @param list<string> $instances
     */
    #[DataProvider('daysCountedBackFromTheEnd')]
    public function testADayCountedBackFromTheEnd(string $rule, string $start, array $instances): void
    {
        self::assertSame($instances, self::expand($rule, $start, count($instances)));
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function daysCountedBackFromTheEnd(): iterable
    {
        yield 'the tenth to the last day of the month' => [
            'FREQ=MONTHLY;BYMONTHDAY=-10;COUNT=3', '19970122',
            ['19970122', '19970219', '19970322'],
        ];

        yield 'the last day of the year' => [
            'FREQ=YEARLY;BYYEARDAY=-1;COUNT=2', '19971231', ['19971231', '19981231'],
        ];

        // The memo picked this number for a reason worth seeing: 365 - 306 + 1
        // is 60 and 366 - 306 + 1 is 61, and the sixtieth day of a common year
        // and the sixty-first of a leap year are both the first of March. A
        // day counted back from the end moves with the length of the year.
        yield 'the 306th to the last day of the year, which is the first of March' => [
            'FREQ=YEARLY;BYYEARDAY=-306;COUNT=1', '19970301', ['19970301'],
        ];

        yield 'and in a leap year it is the first of March as well' => [
            'FREQ=YEARLY;BYYEARDAY=-306;COUNT=1', '20000301', ['20000301'],
        ];
    }

    /**
     * **`BYYEARDAY` sets the month as well as the day**, so where it meets
     * `BYMONTH` or `BYMONTHDAY` the coarser part gives way and filters —
     * read from `BYDAY`'s Note 2, "Limit if BYYEARDAY or BYMONTHDAY is
     * present", because the table and the evaluation order cannot both be
     * followed. **A reading, not a quotation.**
     *
     * @param non-empty-string $rule
     * @param list<string> $instances
     */
    #[DataProvider('whereTheCoarserPartGivesWay')]
    public function testWhereTheCoarserPartGivesWayItFilters(string $rule, array $instances): void
    {
        self::assertSame($instances, self::expand($rule, '19970101', count($instances) + 1));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function whereTheCoarserPartGivesWay(): iterable
    {
        // Day 1 is in January and day 100 in April, so naming April keeps one
        // of the two rather than multiplying them.
        yield 'BYMONTH filters what BYYEARDAY chose' => [
            'FREQ=YEARLY;BYMONTH=4;BYYEARDAY=1,100;COUNT=2',
            ['19970410', '19980410'],
        ];

        // Day 100 of 1997 is the tenth of April, so naming the tenth keeps it
        // and naming the first would keep nothing.
        yield 'BYMONTHDAY filters it too' => [
            'FREQ=YEARLY;BYMONTHDAY=10;BYYEARDAY=1,100;COUNT=2',
            ['19970410', '19980410'],
        ];

    }

    /**
     * **And where the filter matches nothing, nothing comes of it — for
     * ever.** A rule naming April's days of the year and December as its
     * month describes a contradiction, so no year holds an instance and the
     * expansion is stopped by its limit rather than looked for endlessly.
     * That is what R-CAL-09's „niemals mit Abbruch" asks of it.
     */
    public function testWhereTheFilterMatchesNothingTheLimitStopsIt(): void
    {
        $this->expectException(TooManyIterations::class);

        self::expand('FREQ=YEARLY;BYMONTH=12;BYYEARDAY=1,100;COUNT=2', '19970101', 5, 40);
    }

    /**
     * **An instance that is no day at all is still ignored**, which the memo
     * demonstrates itself: "An example where an invalid date (i.e., February
     * 30) is ignored." The rule names the fifteenth and the thirtieth, and
     * February keeps one of them.
     */
    public function testAnInstanceThatIsNoDayIsIgnored(): void
    {
        self::assertSame(
            ['20070215'],
            array_slice(self::expand('FREQ=MONTHLY;BYMONTHDAY=15,30;COUNT=5', '20070115'), 2, 1),
        );
    }

    /**
     * **And a sixtieth second is no time this library can place.** `seconds =
     * 1*2DIGIT ;0 to 60` admits it, because a leap second is a real thing,
     * but no minute PHP can address has one — so the instance is ignored for
     * the same reason February the thirtieth is.
     */
    public function testASixtiethSecondIsIgnored(): void
    {
        self::assertSame(
            ['19970101T000010', '19970101T000110'],
            self::expand('FREQ=MINUTELY;BYSECOND=10,60;COUNT=2', '19970101T000010'),
        );
    }

    /**
     * **And the fifty-ninth second is a second like any other.** `seconds =
     * 1*2DIGIT ;0 to 60` runs to sixty, so the line between what a minute has
     * and what it has not falls after fifty-nine and not before it.
     */
    public function testTheFiftyNinthSecondIsKept(): void
    {
        self::assertSame(
            ['19970101T000059', '19970101T000159'],
            self::expand('FREQ=MINUTELY;BYSECOND=59;COUNT=2', '19970101T000059'),
        );
    }

    /**
     * **A day named twice is handed over once.** §3.8.5.3: "Duplicate
     * instances are ignored." The thirtieth and the last day of a month of
     * thirty are the same day, and a rule naming both names it once.
     */
    public function testADayNamedTwiceIsHandedOverOnce(): void
    {
        self::assertSame(
            ['19970930', '19971030', '19971031'],
            self::expand('FREQ=MONTHLY;BYMONTHDAY=30,-1;COUNT=3', '19970930'),
        );
    }

    /**
     * **The accounting, at the boundary.** A period costs one iteration and
     * so does every candidate in it, so five iterations of a daily rule buy
     * two instances: period, candidate, period, candidate, period — and the
     * third period's candidate is the one too many.
     *
     * **Five rather than four**, because four cannot tell the counting from a
     * counting that starts one lower: with four, the third period's candidate
     * is refused either way. An odd number puts the refusal on a different
     * instance.
     */
    public function testFiveIterationsBuyTwoInstances(): void
    {
        $instances = [];

        try {
            foreach ((new Iterator(Rule::decode('FREQ=DAILY'), Date::decode('19970101'), 5))->instances() as $one) {
                $instances[] = $one->encode();
            }
        } catch (TooManyIterations) {
            self::assertSame(['19970101', '19970102'], $instances);

            return;
        }

        self::fail('five iterations of an unbounded rule should have reached the limit');
    }

    /**
     * **"then COUNT and UNTIL are evaluated"**    /**
     * **"then COUNT and UNTIL are evaluated"** — the evaluation order puts
     * them last, after every `BYxxx` part has had its say. A count applied
     * before the parts widened the set would count the wrong things.
     */
    public function testTheCountIsOfWhatThePartsLeave(): void
    {
        self::assertSame(
            ['19970610T090000', '19970710T090000', '19980610T090000'],
            self::expand('FREQ=YEARLY;BYMONTH=6,7;COUNT=3', '19970610T090000'),
        );
    }

    /**
     * And the same for a bound: it cuts the widened set rather than the
     * intervals.
     */
    public function testTheBoundCutsTheWidenedSet(): void
    {
        self::assertSame(
            ['19970610T090000', '19970710T090000'],
            self::expand('FREQ=YEARLY;BYMONTH=6,7;UNTIL=19971231T000000', '19970610T090000'),
        );
    }

    /**
     * **An instance before the start is not in the set.** §3.8.5.3: "The
     * 'DTSTART' property defines the first instance in the recurrence set."
     * The first interval of a monthly rule on the first and last day holds
     * both, and the start is the last.
     */
    public function testAnInstanceBeforeTheStartIsNotInTheSet(): void
    {
        self::assertSame(
            ['19970930T090000', '19971001T090000'],
            self::expand('FREQ=MONTHLY;BYMONTHDAY=1,-1;COUNT=2', '19970930T090000'),
        );
    }

    /**
     * **The set of one interval comes out in order**, however the lists were
     * written: §3.1.1 says "There is no significance to the order of values
     * in a list", so a rule may name its days backwards.
     */
    public function testTheSetComesOutInOrderHoweverTheListWasWritten(): void
    {
        self::assertSame(
            self::expand('FREQ=MONTHLY;BYMONTHDAY=2,15;COUNT=4', '19970902'),
            self::expand('FREQ=MONTHLY;BYMONTHDAY=15,2;COUNT=4', '19970902'),
        );
    }

    /**
     * **The one part still not expanded is refused**, not left out. §3.3.10
     * licenses ignoring a part exactly once and by name — "These rule parts
     * MUST be ignored in RECUR value that violate the above requirement" —
     * and everywhere else says the parts "are applied". A set that quietly
     * left one out would be a set the memo does not define.
     *
     * `BYDAY` and `BYWEEKNO` were refused here until P4-08b, which expands
     * them. `BYSETPOS` is the last, and the memo sets it apart twice over: it
     * evaluates it after every other part, and it is the only one that needs
     * a whole period at once — "BYSETPOS operates on a set of recurrence
     * instances in one interval of the recurrence rule" — where this hands a
     * period over one candidate at a time.
     */
    public function testTheOnePartNotYetExpandedIsRefused(): void
    {
        $this->expectException(NotExpanded::class);
        $this->expectExceptionMessage('BYSETPOS');

        self::expand('FREQ=MONTHLY;BYMONTHDAY=1,2;BYSETPOS=-1', '19970101T090000');
    }

    /**
     * **The hard limit still bounds the work**, and now it has two ways to be
     * spent: many intervals, or one interval holding a great many instances.
     */
    public function testTheLimitBoundsAnIntervalThatHoldsAGreatMany(): void
    {
        $this->expectException(TooManyIterations::class);

        self::expand(
            'FREQ=YEARLY;BYMONTH=1,2,3,4,5,6,7,8,9,10,11,12;BYMONTHDAY=1,2,3,4,5,6,7,8,9,10',
            '19970101',
            500,
            60,
        );
    }

    /**
     * **A period that holds no candidate at all is bounded too.** The
     * three-hundred-and-sixty-sixth day of the year exists only in a leap
     * one, so a rule naming it looks at three common years and finds nothing
     * to look at in them — which is a different way of spending the limit
     * from a period holding a great many candidates, and has to be bounded by
     * the same number.
     */
    public function testAPeriodThatHoldsNoCandidateAtAllIsBoundedToo(): void
    {
        $this->expectException(TooManyIterations::class);

        self::expand('FREQ=YEARLY;BYYEARDAY=366', '19970101', 5, 3);
    }

    /**
     * And where the leap year comes within reach, the day is found.
     */
    public function testAndTheLeapYearIsFoundWhereItIsWithinReach(): void
    {
        self::assertSame(['20001231'], self::expand('FREQ=YEARLY;BYYEARDAY=366;COUNT=1', '19970101', 5, 20));
    }

    /**
     * **And an interval that holds nothing still costs.**    /**
     * **And an interval that holds nothing still costs.** A rule naming the
     * thirtieth of February finds nothing in any year, and without the
     * interval itself counting against the limit it would look for ever.
     */
    public function testAnIntervalThatHoldsNothingStillCosts(): void
    {
        $this->expectException(TooManyIterations::class);

        self::expand('FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=30', '19970101', 5, 40);
    }

    /**
     * The instances a rule expands to from a start, as they are written.
     *
     * @param non-empty-string $rule
     * @param non-empty-string $start
     *
     * @return list<string>
     */
    private static function expand(string $rule, string $start, int $take = 500, ?int $iterations = null): array
    {
        $iterator = new Iterator(
            Rule::decode($rule),
            str_contains($start, 'T') ? DateTime::decode($start) : Date::decode($start),
            $iterations ?? Iterator::ITERATIONS,
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

    /**
     * Every day of January in 1998, 1999 and 2000, which is what "every day
     * in January, for 3 years" comes to.
     *
     * @return list<string>
     */
    private static function januaries(): array
    {
        $days = [];

        foreach ([1998, 1999, 2000] as $year) {
            for ($day = 1; $day <= 31; ++$day) {
                $days[] = sprintf('%04d01%02d', $year, $day);
            }
        }

        return $days;
    }
}
