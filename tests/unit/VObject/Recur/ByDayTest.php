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
use DavServices\VObject\Recur\Weeks;
use DavServices\VObject\Value\Date;
use DavServices\VObject\Value\DateTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for `BYDAY` and `BYWEEKNO`, from RFC 5545 §3.3.10 and §3.8.5.3.
 * R-RRULE-01.
 *
 * **These are the two parts the memo sets apart.** It says so of the first:
 * "BYDAY has some special behavior depending on the FREQ value and this is
 * described in separate notes below the table", and those two notes are the
 * only conditional cells the table has. `BYWEEKNO` comes with it because the
 * second note is written in terms of it and because its one worked example
 * does not stand without it.
 *
 * ## The two notes, which are the whole specification
 *
 * > Note 1: Limit if BYMONTHDAY is present; otherwise, special expand for
 * > MONTHLY.
 * >
 * > Note 2: Limit if BYYEARDAY or BYMONTHDAY is present; otherwise, special
 * > expand for WEEKLY if BYWEEKNO present; otherwise, special expand for
 * > MONTHLY if BYMONTH present; otherwise, special expand for YEARLY.
 *
 * So `BYDAY` filters where a part naming a day more exactly is there, and
 * otherwise widens — and **what it widens is a week, a month or a year**,
 * whichever the rest of the rule names. That is also the unit an ordinal
 * counts in: `-1MO` is the last Monday of the month in a `MONTHLY` rule and
 * the last Monday of the year in a `YEARLY` one.
 *
 * ## A sentence that contradicts itself, and the note that settles it
 *
 * §3.3.10 also says this in prose, and as written it cannot be followed:
 *
 * > The numeric value in a BYDAY rule part with the FREQ rule part set to
 * > YEARLY corresponds to an offset **within the month when the BYMONTH rule
 * > part is present**, and corresponds to an offset **within the year when
 * > the BYWEEKNO or BYMONTH rule parts are present**.
 *
 * `BYMONTH` being present cannot mean both. **Note 2 is what the sentence was
 * reaching for** and says it without ambiguity: with `BYWEEKNO` the unit is
 * the week, else with `BYMONTH` the month, else the year. The notes are also
 * where the table sends a reader for exactly this, so they are the authority
 * and the prose is a slip. Tested here against the memo's own published
 * answers, which agree with the notes.
 *
 * ## Weeks are counted the memo's way
 *
 * > A week is defined as a seven day period, starting on the day of the week
 * > defined to be the week start (see WKST). Week number one of the calendar
 * > year is the first week that contains at least four (4) days in that
 * > calendar year.
 *
 * And the memo checks its own arithmetic in a note: "Assuming a Monday week
 * start, week 53 can only occur when Thursday is January 1 or if it is a leap
 * year and Wednesday is January 1." That note is a test, and it is here.
 *
 * ## `WKST` finally does something
 *
 * It has been read since P4-07 and has changed nothing, because §3.3.10 says
 * exactly where it matters: "significant when a WEEKLY 'RRULE' has an
 * interval greater than 1, and a BYDAY rule part is specified. This is also
 * significant when in a YEARLY 'RRULE' when a BYWEEKNO rule part is
 * specified." Both of those arrive with this chunk, and §3.8.5.3 publishes a
 * pair of rules that differ in nothing but `WKST` and come to different
 * answers — the best test of it there is.
 */
#[CoversClass(ByRules::class)]
#[CoversClass(Weeks::class)]
#[CoversClass(Iterator::class)]
final class ByDayTest extends TestCase
{
    /**
     * **The memo's own examples, rule and answer together.**
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
        yield 'weekly on Tuesday and Thursday for five weeks, as a count' => [
            'FREQ=WEEKLY;COUNT=10;WKST=SU;BYDAY=TU,TH',
            '19970902T090000',
            self::atNine(['19970902', '19970904', '19970909', '19970911', '19970916',
                '19970918', '19970923', '19970925', '19970930', '19971002']),
        ];

        yield 'and as a bound, which the memo says comes to the same' => [
            'FREQ=WEEKLY;UNTIL=19971007T000000Z;WKST=SU;BYDAY=TU,TH',
            '19970902T090000',
            self::atNine(['19970902', '19970904', '19970909', '19970911', '19970916',
                '19970918', '19970923', '19970925', '19970930', '19971002']),
        ];

        yield 'every other week on Tuesday and Thursday, for 8 occurrences' => [
            'FREQ=WEEKLY;INTERVAL=2;COUNT=8;WKST=SU;BYDAY=TU,TH',
            '19970902T090000',
            self::atNine(['19970902', '19970904', '19970916', '19970918', '19970930',
                '19971002', '19971014', '19971016']),
        ];

        yield 'monthly on the first Friday for 10 occurrences' => [
            'FREQ=MONTHLY;COUNT=10;BYDAY=1FR',
            '19970905T090000',
            self::atNine(['19970905', '19971003', '19971107', '19971205', '19980102',
                '19980206', '19980306', '19980403', '19980501', '19980605']),
        ];

        yield 'monthly on the first Friday until December 24, 1997' => [
            'FREQ=MONTHLY;UNTIL=19971224T000000Z;BYDAY=1FR',
            '19970905T090000',
            self::atNine(['19970905', '19971003', '19971107', '19971205']),
        ];

        yield 'every other month on the first and last Sunday for 10 occurrences' => [
            'FREQ=MONTHLY;INTERVAL=2;COUNT=10;BYDAY=1SU,-1SU',
            '19970907T090000',
            self::atNine(['19970907', '19970928', '19971102', '19971130', '19980104',
                '19980125', '19980301', '19980329', '19980503', '19980531']),
        ];

        yield 'monthly on the second-to-last Monday for 6 months' => [
            'FREQ=MONTHLY;COUNT=6;BYDAY=-2MO',
            '19970922T090000',
            self::atNine(['19970922', '19971020', '19971117', '19971222', '19980119', '19980216']),
        ];

        yield 'every 20th Monday of the year, the first three of forever' => [
            'FREQ=YEARLY;BYDAY=20MO',
            '19970519T090000',
            self::atNine(['19970519', '19980518', '19990517']),
        ];

        yield 'Monday of week number 20, the first three of forever' => [
            'FREQ=YEARLY;BYWEEKNO=20;BYDAY=MO',
            '19970512T090000',
            self::atNine(['19970512', '19980511', '19990517']),
        ];

        yield 'every Thursday in March, the first year of forever' => [
            'FREQ=YEARLY;BYMONTH=3;BYDAY=TH',
            '19970313T090000',
            self::atNine(['19970313', '19970320', '19970327', '19980305']),
        ];

        yield 'every Thursday in June, July and August, the first year' => [
            'FREQ=YEARLY;BYDAY=TH;BYMONTH=6,7,8',
            '19970605T090000',
            self::atNine(['19970605', '19970612', '19970619', '19970626', '19970703',
                '19970710', '19970717', '19970724', '19970731', '19970807',
                '19970814', '19970821', '19970828', '19980604']),
        ];

        yield 'every Friday the 13th, where BYMONTHDAY makes BYDAY a filter' => [
            'FREQ=MONTHLY;BYDAY=FR;BYMONTHDAY=13',
            '19970902T090000',
            self::atNine(['19980213', '19980313', '19981113']),
        ];
    }

    /**
     * **"Every other week on Monday, Wednesday, and Friday until December 24,
     * 1997, starting on Monday, September 1, 1997"** — the memo gives this one
     * as ranges across four months, so the count and the ends are what is
     * asserted.
     */
    public function testEveryOtherWeekOnThreeDaysUntilChristmasEve(): void
    {
        $instances = self::expand(
            'FREQ=WEEKLY;INTERVAL=2;UNTIL=19971224T000000Z;WKST=SU;BYDAY=MO,WE,FR',
            '19970901T090000',
        );

        self::assertCount(25, $instances);
        self::assertSame(['19970901T090000'], array_slice($instances, 0, 1));
        self::assertSame(['19971222T090000'], array_slice($instances, -1));
    }

    /**
     * **§3.8.5.3's `WKST` pair**: two rules differing in nothing but the week
     * start, with two published answers. "An example where the days generated
     * makes a difference because of WKST."
     *
     * @param non-empty-string $rule
     * @param list<string> $instances
     */
    #[DataProvider('theWeekStartPair')]
    public function testTheWeekStartChangesWhichDaysFallInAPeriod(string $rule, array $instances): void
    {
        self::assertSame($instances, self::expand($rule, '19970805T090000'));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function theWeekStartPair(): iterable
    {
        yield 'with the week starting on Monday' => [
            'FREQ=WEEKLY;INTERVAL=2;COUNT=4;BYDAY=TU,SU;WKST=MO',
            self::atNine(['19970805', '19970810', '19970819', '19970824']),
        ];

        yield 'and with it starting on Sunday' => [
            'FREQ=WEEKLY;INTERVAL=2;COUNT=4;BYDAY=TU,SU;WKST=SU',
            self::atNine(['19970805', '19970817', '19970819', '19970831']),
        ];
    }

    /**
     * **Note 2, unit by unit.** An ordinal counts in the week, the month or
     * the year, whichever the rest of the rule names — which is what the
     * prose sentence was reaching for and said twice over.
     *
     * @param non-empty-string $rule
     * @param non-empty-string $start
     * @param list<string> $instances
     */
    #[DataProvider('theUnitAnOrdinalCountsIn')]
    public function testTheUnitAnOrdinalCountsIn(string $rule, string $start, array $instances): void
    {
        self::assertSame($instances, self::expand($rule, $start, count($instances)));
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function theUnitAnOrdinalCountsIn(): iterable
    {
        // "otherwise, special expand for MONTHLY if BYMONTH present"
        yield 'with BYMONTH, within the month' => [
            'FREQ=YEARLY;BYMONTH=3;BYDAY=1TH;COUNT=2', '19970101',
            ['19970306', '19980305'],
        ];

        // "otherwise, special expand for YEARLY"
        yield 'without it, within the year' => [
            'FREQ=YEARLY;BYDAY=1TH;COUNT=2', '19970101',
            ['19970102', '19980101'],
        ];

        // Note 1: "otherwise, special expand for MONTHLY"
        yield 'and a monthly rule counts in its own month' => [
            'FREQ=MONTHLY;BYDAY=1TH;COUNT=3', '19970101',
            ['19970102', '19970206', '19970306'],
        ];
    }

    /**
     * **Note 2's filter half**: "Limit if BYYEARDAY or BYMONTHDAY is
     * present." Day 100 of 1997 is the tenth of April and a Thursday, and of
     * 1998 the tenth of April and a Friday — so a rule asking for a Friday
     * passes over the first and keeps the second.
     */
    public function testADayOfTheYearMakesABareWeekdayAFilter(): void
    {
        self::assertSame(['19980410'], self::expand('FREQ=YEARLY;BYYEARDAY=100;BYDAY=FR;COUNT=1', '19970101'));
    }

    /**
     * And the same day asked for as a Thursday is the one in 1997.
     */
    public function testAndTheWeekdayThatMatchesIsKept(): void
    {
        self::assertSame(['19970410'], self::expand('FREQ=YEARLY;BYYEARDAY=100;BYDAY=TH;COUNT=1', '19970101'));
    }

    /**
     * **`BYWEEKNO` without `BYDAY` takes the day from the start**, which is
     * the note that governs every such case: "Since none of the BYDAY,
     * BYMONTHDAY, or BYYEARDAY components are specified, the day is gotten
     * from 'DTSTART'." The twelfth of May 1997 is a Monday, so naming week 20
     * and nothing else comes to the same as naming its Monday.
     */
    public function testAWeekNumberWithoutAWeekdayTakesTheDayFromTheStart(): void
    {
        self::assertSame(
            self::expand('FREQ=YEARLY;BYWEEKNO=20;BYDAY=MO;COUNT=3', '19970512'),
            self::expand('FREQ=YEARLY;BYWEEKNO=20;COUNT=3', '19970512'),
        );
    }

    /**
     * **A week counted back from the end.** "Valid values are 1 to 53 or -53
     * to -1", and 1997 holds fifty-two weeks, whose Monday is the
     * twenty-second of December.
     */
    public function testAWeekCountedBackFromTheEnd(): void
    {
        self::assertSame(['19971222'], self::expand('FREQ=YEARLY;BYWEEKNO=-1;BYDAY=MO;COUNT=1', '19970101'));
    }

    /**
     * **The memo checks its own arithmetic, and so does this.** "Assuming a
     * Monday week start, week 53 can only occur when Thursday is January 1 or
     * if it is a leap year and Wednesday is January 1."
     *
     * The first of January 1998 is a Thursday, so 1998 has a fifty-third
     * week; the first of January 1997 is a Wednesday in a common year, so
     * 1997 has not.
     */
    public function testAFiftyThirdWeekOnlyWhereTheMemoSaysItCanBe(): void
    {
        self::assertSame(['19981228'], self::expand('FREQ=YEARLY;BYWEEKNO=53;BYDAY=MO;COUNT=1', '19980101'));
    }

    /**
     * And 1997 has no such week, so a rule bounded inside it finds nothing.
     */
    public function testAndNoFiftyThirdWeekWhereItCannot(): void
    {
        self::assertSame(
            [],
            self::expand('FREQ=YEARLY;BYWEEKNO=53;BYDAY=MO;UNTIL=19971231', '19970101'),
        );
    }

    /**
     * **The week start moves the numbering too.** With Monday as the start,
     * the first of January 1998 falls four days into its week, so that week
     * has four days of 1998 in it and is week one — which begins on the
     * twenty-ninth of December 1997. With Sunday as the start it falls five
     * days in, that week has only three, and week one begins on the fourth of
     * January 1998.
     */
    public function testTheWeekStartMovesTheNumbering(): void
    {
        self::assertSame(
            ['19971229'],
            self::expand('FREQ=YEARLY;BYWEEKNO=1;BYDAY=MO;COUNT=1;WKST=MO', '19971201'),
        );
        self::assertSame(
            ['19980105'],
            self::expand('FREQ=YEARLY;BYWEEKNO=1;BYDAY=MO;COUNT=1;WKST=SU', '19971201'),
        );
    }

    /**
     * **A bare weekday means every one of them in the unit**: "If an integer
     * modifier is not present, it means all days of this type within the
     * specified frequency. For example, within a MONTHLY rule, MO represents
     * all Mondays within the month."
     */
    public function testABareWeekdayMeansEveryOneOfThemInTheUnit(): void
    {
        self::assertSame(
            ['19970203', '19970210', '19970217', '19970224'],
            self::expand('FREQ=MONTHLY;BYDAY=MO;COUNT=4', '19970203'),
        );
    }

    /**
     * **And a weekday the unit has not got yields nothing for it.** A fifth
     * Monday is a thing some months have and others have not, and the ones
     * without are passed over rather than borrowing from the next.
     */
    public function testAWeekdayTheUnitHasNotGotIsPassedOver(): void
    {
        self::assertSame(
            ['19970929', '19971229'],
            self::expand('FREQ=MONTHLY;BYDAY=5MO;COUNT=2', '19970901'),
        );
    }

    /**
     * **The days of one period come out in order**, whichever way the list
     * was written — §3.1.1: "There is no significance to the order of values
     * in a list."
     */
    public function testTheDaysOfOnePeriodComeOutInOrder(): void
    {
        self::assertSame(
            self::expand('FREQ=WEEKLY;BYDAY=TU,SU;COUNT=4;WKST=SU', '19970803'),
            self::expand('FREQ=WEEKLY;BYDAY=SU,TU;COUNT=4;WKST=SU', '19970803'),
        );
    }

    /**
     * **And the other half of the memo's own check.** "Week 53 can only occur
     * when Thursday is January 1 **or if it is a leap year and Wednesday is
     * January 1**" — the first of January 2020 is a Wednesday in a leap year,
     * so 2020 has a fifty-third week, which begins on the twenty-eighth of
     * December.
     */
    public function testTheOtherHalfOfTheMemosOwnCheckOnWeekFiftyThree(): void
    {
        self::assertSame(['20201228'], self::expand('FREQ=YEARLY;BYWEEKNO=53;BYDAY=MO;COUNT=1', '20200101'));
    }

    /**
     * **A week can begin six days before the day that named it**, and so
     * hold days before the period's own. Every other Sunday with Monday as
     * the week start puts the Tuesday of each week ahead of its Sunday — and
     * the first of them ahead of `DTSTART`, where §3.8.5.3 leaves it out:
     * "The 'DTSTART' property defines the first instance in the recurrence
     * set."
     */
    public function testAWeekThatBeginsSixDaysBeforeTheDayThatNamedIt(): void
    {
        self::assertSame(
            ['19970803', '19970812', '19970817'],
            self::expand('FREQ=WEEKLY;INTERVAL=2;COUNT=3;BYDAY=SU,TU;WKST=MO', '19970803'),
        );
    }

    /**
     * **An ordinal counts in the unit where `BYDAY` filters too**, there
     * being no reason for the memo's cascade to mean one thing for a set it
     * builds and another for a set it filters.
     *
     * The pair proves itself: a sixth that is a Friday is always the month's
     * first Friday, and a thirteenth that is a Friday always its second,
     * because thirteen is six and a week. So the same two days of the same
     * months fall to the two rules, one each.
     */
    public function testAnOrdinalCountsInItsUnitWhereTheWeekdayFilters(): void
    {
        self::assertSame(
            ['19970606', '19980206', '19980306'],
            self::expand('FREQ=MONTHLY;BYMONTHDAY=6,13;BYDAY=1FR;COUNT=3', '19970101'),
        );
        self::assertSame(
            ['19970613', '19980213', '19980313'],
            self::expand('FREQ=MONTHLY;BYMONTHDAY=6,13;BYDAY=2FR;COUNT=3', '19970101'),
        );
    }

    /**
     * **A week number beside a day of the month.** The memo gives no example
     * of the pair and admits it, so the reading is the one its own Note 2
     * uses for every such collision: the part naming the coarser thing gives
     * way. A week sets the days and the day of the month filters them, which
     * makes this "the twelfth, if it falls in week twenty" — and it does, in
     * both years, the week moving and the day not.
     *
     * **It also unseats the note that usually settles the day**: "Since none
     * of the BYDAY, BYMONTHDAY, or BYYEARDAY components are specified, the
     * day is gotten from 'DTSTART'." One of them is specified here, so the
     * week's seven days are all candidates rather than only the start's own.
     */
    public function testAWeekNumberBesideADayOfTheMonth(): void
    {
        self::assertSame(
            ['19970512', '19980512'],
            self::expand('FREQ=YEARLY;BYWEEKNO=20;BYMONTHDAY=12;COUNT=2', '19970101'),
        );
    }

    /**
     * And with a weekday filtering as well, the twelfth of 1998 is a Tuesday
     * and falls away.
     */
    public function testAndAWeekdayFilteringTheSameWeek(): void
    {
        self::assertSame(
            ['19970512'],
            self::expand('FREQ=YEARLY;BYWEEKNO=20;BYMONTHDAY=12;BYDAY=MO;COUNT=1', '19970101'),
        );
    }

    /**
     * **Friday the thirteenth of June**, where the month comes from
     * `BYMONTH`, the day from `BYMONTHDAY` and the weekday only filters — so
     * the unit the filter counts in is that month.
     */
    public function testAMonthADayOfItAndAWeekdayToFilterBy(): void
    {
        self::assertSame(
            ['19970613'],
            self::expand('FREQ=YEARLY;BYMONTH=6;BYMONTHDAY=13;BYDAY=FR;COUNT=1', '19970101'),
        );
    }

    /**
     * **Every part's values come out in order, however the rule wrote them**
     * — §3.1.1: "There is no significance to the order of values in a list."
     *
     * It is not a nicety. `UNTIL` stops at the first instance past the bound,
     * which is only the last instance if they arrive in order; a rule naming
     * August before June would otherwise end after one year.
     */
    public function testThePartsOfAPeriodComeOutInOrderHoweverTheyWereWritten(): void
    {
        self::assertSame(
            ['19970605', '19970612'],
            self::expand('FREQ=YEARLY;BYMONTH=8,6;BYDAY=TH;COUNT=2', '19970101'),
        );
        self::assertSame(
            ['19970101T090000', '19970101T160000'],
            self::expand('FREQ=DAILY;BYHOUR=16,9;COUNT=2', '19970101T090000'),
        );
    }

    /**
     * **A week that runs past the end of the calendar.** The last week of
     * 9999 begins on the twenty-seventh of December and ends on the second of
     * January 10000 — a date `date-fullyear = 4DIGIT` (§3.3.4) cannot write
     * down. So its Friday is an instance and its Saturday and Sunday are not.
     *
     * This is the case that could not arise before `BYWEEKNO`: every other
     * part sets a field inside the period, and only a week reaches outside
     * the year that numbers it.
     */
    public function testAWeekThatRunsPastTheEndOfTheCalendar(): void
    {
        self::assertSame(
            ['99991231'],
            self::expand('FREQ=YEARLY;BYWEEKNO=-1;BYDAY=FR,SA,SU', '99990101'),
        );
    }

    /**
     * **A week number filters where a day of the year sets the day.** The
     * table never calls `BYWEEKNO` `Limit`, and here it is one: a day of the
     * year names a finer thing than a week, so the week gives way — the same
     * resolution the memo's own Note 2 uses for every such collision.
     *
     * The hundredth day of the year is the tenth of April, and which week it
     * falls in moves with the year: week fifteen in 1997, 1998 and 2001, and
     * week fourteen in 1999 and 2000. So naming week fifteen keeps three of
     * those five years and passes over two — which is the filter admitting
     * and refusing in one answer.
     */
    public function testAWeekNumberFiltersWhereADayOfTheYearSetsTheDay(): void
    {
        self::assertSame(
            ['19970410', '19980410', '20010410'],
            self::expand('FREQ=YEARLY;BYYEARDAY=100;BYWEEKNO=15;COUNT=3', '19970101'),
        );
    }

    /**
     * **`WKST` makes no difference to a weekly rule of interval one**, and
     * §3.3.10 says as much: it is "significant when a WEEKLY 'RRULE' has an
     * interval greater than 1, and a BYDAY rule part is specified". With
     * every week a period of its own the weeks tile the calendar wherever
     * they are cut, so all seven starts come to one answer — and this is the
     * memo's own first example, whose published `WKST=SU` could have been any
     * of them.
     */
    public function testTheWeekStartMakesNoDifferenceToAnIntervalOfOne(): void
    {
        $published = self::atNine(['19970902', '19970904', '19970909', '19970911', '19970916', '19970918']);

        foreach (['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'] as $weekStart) {
            self::assertSame(
                $published,
                self::expand('FREQ=WEEKLY;COUNT=6;BYDAY=TU,TH;WKST=' . $weekStart, '19970902T090000'),
                'mit WKST=' . $weekStart,
            );
        }
    }

    /**
     * **The last day of a year is in it**, which is the other end of the unit
     * Note 2's last clause names: "otherwise, special expand for YEARLY". The
     * last Wednesday of 1997 is the thirty-first of December.
     */
    public function testTheLastDayOfTheYearIsInTheYearsUnit(): void
    {
        self::assertSame(['19971231'], self::expand('FREQ=YEARLY;BYDAY=-1WE;COUNT=1', '19970101'));
    }

    /**
     * **Both ends of a named week belong to it.** A week is seven days
     * inclusive, so the day a week begins on and the day it ends on are both
     * in it — which the filter has to say as well as the expansion. Week
     * fifteen of 1997 runs from the seventh to the thirteenth of April, and
     * those are days ninety-seven and a hundred and three of the year.
     *
     * @param non-empty-string $rule
     * @param list<string> $instances
     */
    #[DataProvider('theEndsOfANamedWeek')]
    public function testBothEndsOfANamedWeekBelongToIt(string $rule, array $instances): void
    {
        self::assertSame($instances, self::expand($rule, '19970101'));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function theEndsOfANamedWeek(): iterable
    {
        yield 'the day it begins on' => [
            'FREQ=YEARLY;BYYEARDAY=97;BYWEEKNO=15;COUNT=1', ['19970407'],
        ];

        yield 'and the day it ends on' => [
            'FREQ=YEARLY;BYYEARDAY=103;BYWEEKNO=15;COUNT=1', ['19970413'],
        ];
    }

    /**
     * **A period can name several weeks**, and they come out in order —
     * §3.1.1 again. Weeks fifteen and twenty of 1997 begin on the seventh of
     * April and the twelfth of May.
     */
    public function testAPeriodCanNameSeveralWeeks(): void
    {
        self::assertSame(
            ['19970407', '19970512'],
            self::expand('FREQ=YEARLY;BYWEEKNO=15,20;BYDAY=MO;COUNT=2', '19970101'),
        );
    }

    /**
     * **A year's unit begins on the first of January**, not on the last day
     * of the year before. The first of January 1999 is a Friday, so the first
     * Thursday of 1999 is the seventh — and the thirty-first of December 1998
     * was a Thursday, which is the day a unit that began too early would
     * offer instead.
     */
    public function testAYearsUnitBeginsOnTheFirstOfJanuary(): void
    {
        self::assertSame(['19990107'], self::expand('FREQ=YEARLY;BYDAY=1TH;COUNT=1', '19990101'));
    }

    /**
     * **And it ends on the thirty-first of December.** The last Thursday of
     * 1997 is the twenty-fifth of December, because the thirty-first is a
     * Wednesday — and the first of January 1998 is a Thursday, which is the
     * day a unit that ran on too long would offer instead.
     */
    public function testAndItEndsOnTheThirtyFirstOfDecember(): void
    {
        self::assertSame(['19971225'], self::expand('FREQ=YEARLY;BYDAY=-1TH;COUNT=1', '19970101'));
    }

    /**
     * **An ordinal counts in the month where `BYMONTH` names one**, even
     * though the rule is yearly and `BYDAY` only filters — Note 2's cascade
     * reads the same for a set it filters as for one it builds. The
     * thirteenth of June 1997 is the second Friday of **June**; the second
     * Friday of the **year** is in January, so a rule counting there would
     * find nothing.
     */
    public function testAnOrdinalCountsInTheMonthWhereOneIsNamed(): void
    {
        self::assertSame(
            ['19970613'],
            self::expand('FREQ=YEARLY;BYMONTH=6;BYMONTHDAY=13;BYDAY=2FR;COUNT=1', '19970101'),
        );
    }

    /**
     * **The last day of a week is one of its days**, where the week supplies
     * the candidates for a `BYMONTHDAY` to filter. Week twenty of 1997 ends
     * on Sunday the eighteenth of May.
     */
    public function testTheLastDayOfAWeekIsOneOfItsDays(): void
    {
        self::assertSame(
            ['19970518'],
            self::expand('FREQ=YEARLY;BYWEEKNO=20;BYMONTHDAY=18;COUNT=1', '19970101'),
        );
    }

    /**
     * **The last week is the fifty-third where there is one.** 1997 holds
     * fifty-two weeks and 1998 fifty-three, so counting back from the end
     * lands on the twenty-second of December in the one year and the
     * twenty-eighth in the other.
     */
    public function testCountingBackFromTheEndFollowsTheLengthOfTheYear(): void
    {
        self::assertSame(
            ['19971222', '19981228'],
            self::expand('FREQ=YEARLY;BYWEEKNO=-1;BYDAY=MO;COUNT=2', '19970101'),
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
