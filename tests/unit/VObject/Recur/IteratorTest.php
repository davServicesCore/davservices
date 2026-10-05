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
use DavServices\VObject\Recur\Iterator;
use DavServices\VObject\Recur\Rule;
use DavServices\VObject\Recur\TooManyIterations;
use DavServices\VObject\Value\Date;
use DavServices\VObject\Value\DateTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for expanding the core of a recurrence rule, from RFC 5545
 * §3.3.10 and §3.8.5.3. R-RRULE-01 and R-RRULE-04.
 *
 * **P4-07 read the rule; this works it out.** `FREQ`, `INTERVAL`, `COUNT`,
 * `UNTIL` and `WKST` for all seven frequencies. The `BYxxx` parts narrow and
 * widen the set and are P4-08's subject; a rule that carries one is read by
 * {@see Rule} already and expanded without it here, which is why that chunk
 * follows directly.
 *
 * ## Four sentences decide nearly everything
 *
 * - "The 'DTSTART' property value **always counts as the first
 *   occurrence**." So the first instance is the start, and `COUNT` counts it.
 * - "The UNTIL rule part defines a DATE or DATE-TIME value that bounds the
 *   recurrence rule in an **inclusive** manner."
 * - "If not present, and the COUNT rule part is also not present, the 'RRULE'
 *   is considered to **repeat forever**." Which is why this is a generator.
 * - "Recurrence rules may generate recurrence instances with an invalid date
 *   (e.g., February 30) […] Such recurrence instances **MUST be ignored and
 *   MUST NOT be counted** as part of the recurrence set." A monthly rule on
 *   the thirty-first keeps seven months a year, and a yearly one on the
 *   twenty-ninth of February keeps one year in four.
 *
 * ## The examples of §3.8.5.3 are the test material
 *
 * The memo publishes a rule and the instances it expands to, which is better
 * evidence than anything this test could invent. Ten of them use the core
 * parts alone and all ten are here.
 *
 * **One of them is worth reading twice.** "Every 3 hours from 9:00 AM to 5:00
 * PM on a specific day" starts at `19970902T090000` in New York and bounds
 * itself with `UNTIL=19970902T170000Z`, and the memo's own answer is 09:00,
 * 12:00 and 15:00. Nine in the morning in New York that day is 13:00 UTC, so
 * 15:00 local is 19:00 UTC — **past** the bound as an instant, and inside it
 * as a wall clock. The memo's published answer is the wall-clock one.
 *
 * That matters because a value carries no time zone: `TZID` is a parameter of
 * the property, and this expands the value. So the comparison here is of wall
 * clocks, which is exact for a floating or UTC start and is what the memo's
 * own examples expect. Nobody should later "correct" it into disagreement
 * with the published answers without reading this paragraph first.
 */
#[CoversClass(Iterator::class)]
#[CoversClass(TooManyIterations::class)]
final class IteratorTest extends TestCase
{
    /**
     * The start of every example in §3.8.5.3: "All examples assume the
     * Eastern United States time zone."
     */
    private const START = '19970902T090000';

    /**
     * **The memo's own examples, rule and answer together.**
     *
     * @param non-empty-string $rule
     * @param list<string> $instances
     */
    #[DataProvider('theExamplesOfTheSpecification')]
    public function testTheExamplesOfTheSpecificationComeOut(string $rule, array $instances): void
    {
        self::assertSame($instances, self::expand($rule, self::START, count($instances)));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function theExamplesOfTheSpecification(): iterable
    {
        yield 'daily for 10 occurrences' => [
            'FREQ=DAILY;COUNT=10',
            self::times(['19970902', '19970903', '19970904', '19970905', '19970906',
                '19970907', '19970908', '19970909', '19970910', '19970911']),
        ];

        yield 'every other day, the first five of forever' => [
            'FREQ=DAILY;INTERVAL=2',
            self::times(['19970902', '19970904', '19970906', '19970908', '19970910']),
        ];

        yield 'every 10 days, 5 occurrences' => [
            'FREQ=DAILY;INTERVAL=10;COUNT=5',
            self::times(['19970902', '19970912', '19970922', '19971002', '19971012']),
        ];

        yield 'weekly for 10 occurrences' => [
            'FREQ=WEEKLY;COUNT=10',
            self::times(['19970902', '19970909', '19970916', '19970923', '19970930',
                '19971007', '19971014', '19971021', '19971028', '19971104']),
        ];

        yield 'every other week, the first thirteen of forever' => [
            'FREQ=WEEKLY;INTERVAL=2;WKST=SU',
            self::times(['19970902', '19970916', '19970930', '19971014', '19971028',
                '19971111', '19971125', '19971209', '19971223', '19980106', '19980120',
                '19980203', '19980217']),
        ];

        yield 'every 3 hours from 9:00 AM to 5:00 PM on a specific day' => [
            'FREQ=HOURLY;INTERVAL=3;UNTIL=19970902T170000Z',
            ['19970902T090000', '19970902T120000', '19970902T150000'],
        ];

        yield 'every 15 minutes for 6 occurrences' => [
            'FREQ=MINUTELY;INTERVAL=15;COUNT=6',
            ['19970902T090000', '19970902T091500', '19970902T093000',
                '19970902T094500', '19970902T100000', '19970902T101500'],
        ];

        yield 'every hour and a half for 4 occurrences' => [
            'FREQ=MINUTELY;INTERVAL=90;COUNT=4',
            ['19970902T090000', '19970902T103000', '19970902T120000', '19970902T133000'],
        ];
    }

    /**
     * **"Daily until December 24, 1997"** — the memo gives the answer as
     * ranges rather than a list: "September 2-30; October 1-31; November 1-30;
     * December 1-23", which is a hundred and thirteen days.
     */
    public function testDailyUntilChristmasEve(): void
    {
        $instances = self::expand('FREQ=DAILY;UNTIL=19971224T000000Z', self::START);

        self::assertCount(113, $instances);
        self::assertSame(['19970902T090000'], array_slice($instances, 0, 1), 'the first is the start');
        self::assertSame(['19971223T090000'], array_slice($instances, -1), 'and the last is the day before');
    }

    /**
     * And weekly until the same bound: "September 2,9,16,23,30; October
     * 7,14,21; October 28; November 4,11,18,25; December 2,9,16,23".
     */
    public function testWeeklyUntilChristmasEve(): void
    {
        $instances = self::expand('FREQ=WEEKLY;UNTIL=19971224T000000Z', self::START);

        self::assertCount(17, $instances);
        self::assertSame(['19971223T090000'], array_slice($instances, -1));
    }

    /**
     * **"The 'DTSTART' property value always counts as the first
     * occurrence"**, so a rule bounded to one occurrence is the start and
     * nothing else.
     */
    public function testTheStartIsAlwaysTheFirstOccurrence(): void
    {
        self::assertSame(['19970902T090000'], self::expand('FREQ=DAILY;COUNT=1', self::START));
    }

    /**
     * Every frequency steps in its own unit, and `INTERVAL` is "the default
     * value is '1', meaning every second for a SECONDLY rule, every minute
     * for a MINUTELY rule, … and every year for a YEARLY rule".
     *
     * @param non-empty-string $rule
     * @param list<string> $instances
     */
    #[DataProvider('everyFrequencyStepsInItsOwnUnit')]
    public function testEveryFrequencyStepsInItsOwnUnit(string $rule, array $instances): void
    {
        self::assertSame($instances, self::expand($rule, self::START));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function everyFrequencyStepsInItsOwnUnit(): iterable
    {
        yield 'secondly' => ['FREQ=SECONDLY;COUNT=3',
            ['19970902T090000', '19970902T090001', '19970902T090002']];

        yield 'minutely' => ['FREQ=MINUTELY;COUNT=3',
            ['19970902T090000', '19970902T090100', '19970902T090200']];

        yield 'hourly' => ['FREQ=HOURLY;COUNT=3',
            ['19970902T090000', '19970902T100000', '19970902T110000']];

        yield 'daily' => ['FREQ=DAILY;COUNT=3',
            ['19970902T090000', '19970903T090000', '19970904T090000']];

        yield 'weekly' => ['FREQ=WEEKLY;COUNT=3',
            ['19970902T090000', '19970909T090000', '19970916T090000']];

        yield 'monthly' => ['FREQ=MONTHLY;COUNT=3',
            ['19970902T090000', '19971002T090000', '19971102T090000']];

        yield 'yearly' => ['FREQ=YEARLY;COUNT=3',
            ['19970902T090000', '19980902T090000', '19990902T090000']];
    }

    /**
     * And an interval greater than one on each of the two that count in
     * calendar months rather than in a fixed length of time.
     *
     * @param non-empty-string $rule
     * @param list<string> $instances
     */
    #[DataProvider('intervalsInMonthsAndYears')]
    public function testAnIntervalInMonthsOrYears(string $rule, array $instances): void
    {
        self::assertSame($instances, self::expand($rule, self::START));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function intervalsInMonthsAndYears(): iterable
    {
        yield 'every other month' => ['FREQ=MONTHLY;INTERVAL=2;COUNT=3',
            ['19970902T090000', '19971102T090000', '19980102T090000']];

        yield 'every eighteen months' => ['FREQ=MONTHLY;INTERVAL=18;COUNT=3',
            ['19970902T090000', '19990302T090000', '20000902T090000']];

        yield 'every other year' => ['FREQ=YEARLY;INTERVAL=2;COUNT=3',
            ['19970902T090000', '19990902T090000', '20010902T090000']];
    }

    /**
     * **A count of nought bounds the recurrence at nought.** P4-07 read
     * `COUNT=0` without judging it, because §3.3.10 gives COUNT the grammar
     * `1*DIGIT` and says nothing about it being positive, and said the
     * meaning belonged to whatever expands the rule. Here it is: "The COUNT
     * rule part defines the number of occurrences at which to range-bound the
     * recurrence", and nought occurrences is an empty set.
     *
     * The sentence about `DTSTART` does not contradict it. "The 'DTSTART'
     * property value always counts as the first occurrence" says how the
     * counting works — that `COUNT=1` is the start alone — not that the set
     * can never be empty.
     */
    public function testACountOfNoughtBoundsItAtNought(): void
    {
        self::assertSame([], self::expand('FREQ=DAILY;COUNT=0', self::START));
    }

    /**
     * **"[UNTIL] bounds the recurrence rule in an inclusive manner. If the
     * value specified by UNTIL is synchronized with the specified recurrence,
     * this DATE or DATE-TIME becomes the last instance."** So a bound that
     * falls exactly on an instance keeps it.
     */
    public function testABoundThatFallsOnAnInstanceKeepsIt(): void
    {
        self::assertSame(
            ['19970902T090000', '19970903T090000'],
            self::expand('FREQ=DAILY;UNTIL=19970903T090000', self::START),
        );
    }

    /**
     * And a bound before the start keeps nothing: the start is the first
     * instance, and the first instance is already past the bound.
     */
    public function testABoundBeforeTheStartKeepsNothing(): void
    {
        self::assertSame([], self::expand('FREQ=DAILY;UNTIL=19970901T090000', self::START));
    }

    /**
     * **"Such recurrence instances MUST be ignored."** A monthly rule on the
     * thirty-first has no instance in February, April, June, September or
     * November.
     */
    public function testAMonthlyRuleOnTheThirtyFirstSkipsTheMonthsWithoutOne(): void
    {
        self::assertSame(
            ['19970131', '19970331', '19970531', '19970731', '19970831', '19971031', '19971231'],
            self::expand('FREQ=MONTHLY;UNTIL=19971231', '19970131'),
        );
    }

    /**
     * **"and MUST NOT be counted as part of the recurrence set"** — which is
     * the other half, and the half a count makes visible: four occurrences of
     * the thirty-first reach into July, not into April.
     */
    public function testTheSkippedMonthsAreNotCountedEither(): void
    {
        self::assertSame(
            ['19970131', '19970331', '19970531', '19970731'],
            self::expand('FREQ=MONTHLY;COUNT=4', '19970131'),
        );
    }

    /**
     * And a yearly rule on the twenty-ninth of February keeps one year in
     * four, which is the other case R-RRULE-05 names.
     */
    public function testAYearlyRuleOnALeapDayKeepsOneYearInFour(): void
    {
        self::assertSame(
            ['20240229', '20280229', '20320229'],
            self::expand('FREQ=YEARLY;COUNT=3', '20240229'),
        );
    }

    /**
     * **A date stays a date** (R-TZ-04): "Ganztägige Termine (`VALUE=DATE`)
     * DÜRFEN NICHT implizit in UTC-Zeitpunkte gewandelt werden", and
     * {@see Date} offers no way to turn one into a moment for that very
     * reason.
     */
    public function testADateStartYieldsDates(): void
    {
        self::assertSame(['19970902', '19970903'], self::expand('FREQ=DAILY;COUNT=2', '19970902'));
    }

    /**
     * **And an instant stays an instant.** A start in UTC yields instances in
     * UTC, because dropping the `Z` would move every one of them by whatever
     * the reader's offset happens to be.
     */
    public function testAUtcStartYieldsUtcInstances(): void
    {
        self::assertSame(
            ['19970902T090000Z', '19970903T090000Z'],
            self::expand('FREQ=DAILY;COUNT=2', '19970902T090000Z'),
        );
    }

    /**
     * And a floating start stays floating, for the same reason the other way
     * about.
     */
    public function testAFloatingStartYieldsFloatingInstances(): void
    {
        self::assertSame(
            ['19970902T090000', '19970903T090000'],
            self::expand('FREQ=DAILY;COUNT=2', '19970902T090000'),
        );
    }

    /**
     * **"the 'RRULE' is considered to repeat forever"**, which is why this is
     * a generator: a caller takes what it needs and the rest is never worked
     * out. Fifty of an unbounded rule, and no limit in sight.
     */
    public function testAnUnboundedRuleHandsOverAsMuchAsIsAskedFor(): void
    {
        $instances = self::expand('FREQ=DAILY', self::START, 50);

        self::assertCount(50, $instances);
        self::assertSame(['19971021T090000'], array_slice($instances, -1));
    }

    /**
     * **But iterating an unbounded rule to its end is refused** rather than
     * run for ever. R-RRULE-04 asks for a hard iteration limit and R-CAL-09
     * says what exceeding a limit means: „MUSS mit der zugehörigen
     * Precondition abgelehnt werden, **niemals mit Abbruch**" — so a refusal
     * the layer above can answer with, not a hang and not a memory error.
     */
    public function testIteratingAnUnboundedRuleToTheEndIsRefused(): void
    {
        $this->expectException(TooManyIterations::class);

        self::expand('FREQ=DAILY', self::START, 20, 12);
    }

    /**
     * And the limit is configurable (R-CAL-08: „Die Werte MÜSSEN
     * konfigurierbar sein, die genannten sind Vorgaben"), with ten thousand
     * as the value the requirements name.
     */
    public function testTheLimitIsConfigurableAndTenThousandByDefault(): void
    {
        self::assertSame(10000, Iterator::ITERATIONS);
        self::assertCount(5, self::expand('FREQ=DAILY', self::START, 5, 20));
    }

    /**
     * **The limit counts iterations and not instances**, which is what
     * „Harte Iterationsgrenze" says and what makes it a bound on the work
     * rather than on the answer.
     *
     * **A period costs one and so does every candidate in it**, because both
     * are ways for a rule to run away: a great many periods, or one period
     * holding a great many candidates. So twenty-four iterations of a monthly
     * rule on the thirty-first look at a year — twelve periods with one
     * candidate each — and find the seven months that have one.
     */
    public function testTheLimitCountsIterationsRatherThanInstances(): void
    {
        $instances = [];

        try {
            foreach ((new Iterator(Rule::decode('FREQ=MONTHLY'), Date::decode('19970131'), 24))->instances() as $one) {
                $instances[] = $one->encode();
            }
        } catch (TooManyIterations) {
            self::assertSame(
                ['19970131', '19970331', '19970531', '19970731', '19970831', '19971031', '19971231'],
                $instances,
            );

            return;
        }

        self::fail('twenty-four iterations of an unbounded rule should have reached the limit');
    }

    /**
     * **`WKST` cannot matter here, and that is a rule rather than an
     * omission.** §3.3.10: "This is significant when a WEEKLY 'RRULE' has an
     * interval greater than 1, **and a BYDAY rule part is specified**. This
     * is also significant when in a YEARLY 'RRULE' when a BYWEEKNO rule part
     * is specified." There is no `BYDAY` and no `BYWEEKNO` in the core, so
     * the two readings have to agree.
     */
    public function testTheWeekStartChangesNothingWithoutAByDay(): void
    {
        self::assertSame(
            self::expand('FREQ=WEEKLY;INTERVAL=2;COUNT=4;WKST=MO', self::START),
            self::expand('FREQ=WEEKLY;INTERVAL=2;COUNT=4;WKST=SU', self::START),
        );
    }

    /**
     * **The calendar this grammar can write down has a last year**:
     * `date-fullyear = 4DIGIT` (§3.3.4). A rule that reaches it has no later
     * instances, so the expansion ends there rather than refusing — there is
     * nothing wrong with the rule, the years have simply run out.
     */
    public function testTheExpansionEndsWhenTheCalendarRunsOut(): void
    {
        self::assertSame(
            ['99970101', '99980101', '99990101'],
            self::expand('FREQ=YEARLY', '99970101', 10),
        );
    }

    /**
     * **And a rule that walks up to the last day the grammar can write down
     * ends on it.** `date-fullyear = 4DIGIT` (§3.3.4), so the thirty-first of
     * December 9999 is the last day there is and the first of January after
     * it is past the end.
     */
    public function testADailyRuleEndsOnTheLastDayOfTheCalendar(): void
    {
        self::assertSame(['99991231'], self::expand('FREQ=DAILY', '99991231', 10, 20));
    }

    /**
     * And the same from an instant, a second before the calendar runs out:
     * the end is the first moment of the year that cannot be written, not
     * some moment inside the last one.
     */
    public function testADailyRuleEndsOnTheLastSecondOfTheCalendar(): void
    {
        self::assertSame(
            ['99991231T235959'],
            self::expand('FREQ=DAILY', '99991231T235959', 10, 20),
        );
    }

    /**
     * **A rule that asks for a time the start has none of is refused.** A
     * `DATE` is a day and carries no hour, so an hourly rule on one has
     * nothing to step by. §3.8.5.3 calls an unsynchronised pair undefined —
     * "The recurrence set generated with a 'DTSTART' property value not
     * synchronized with the recurrence rule is undefined" — so this is a
     * choice, and it is the choice that cannot quietly invent a calendar.
     *
     * @param non-empty-string $rule
     */
    #[DataProvider('frequenciesThatNeedATime')]
    public function testAFrequencyThatNeedsATimeIsRefusedForADate(string $rule): void
    {
        $this->expectException(ParseError::class);

        new Iterator(Rule::decode($rule), Date::decode('19970902'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function frequenciesThatNeedATime(): iterable
    {
        yield 'hourly' => ['FREQ=HOURLY'];

        yield 'minutely' => ['FREQ=MINUTELY'];

        yield 'secondly' => ['FREQ=SECONDLY'];
    }

    /**
     * **And a bound of the wrong kind is refused**, which is a debt of P4-07
     * paid here. §3.3.10: "The value of the UNTIL rule part MUST have the
     * same value type as the 'DTSTART' property." {@see Rule} could not check
     * it, having no `DTSTART` to compare with; this has both.
     *
     * @param non-empty-string $rule
     * @param non-empty-string $start
     */
    #[DataProvider('boundsOfTheWrongKind')]
    public function testABoundOfTheWrongKindIsRefused(string $rule, string $start): void
    {
        $this->expectException(ParseError::class);

        self::expand($rule, $start);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function boundsOfTheWrongKind(): iterable
    {
        yield 'a day bounding an instant' => ['FREQ=DAILY;UNTIL=19971224', '19970902T090000'];

        yield 'an instant bounding a day' => ['FREQ=DAILY;UNTIL=19971224T000000Z', '19970902'];
    }

    /**
     * **And a start in UTC takes a bound in UTC**, which is the other half of
     * the same paragraph and the half a value can be asked: "If the 'DTSTART'
     * property is specified as a date with UTC time […] then the UNTIL rule
     * part MUST be specified as a date with UTC time."
     *
     * The half about local time cannot be asked here — a `TZID` is a
     * parameter of the property and this has the value — and §3.3.10
     * contradicts itself about it in any case, as {@see RuleTest} sets out.
     */
    public function testAStartInUtcTakesABoundInUtc(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('UTC');

        self::expand('FREQ=DAILY;UNTIL=19971224T000000', '19970902T090000Z');
    }

    /**
     * And the pairing the memo's own examples use is left alone: a start
     * written in local time with a bound in UTC, which is what §3.3.10 asks
     * for where the start carries a `TZID`.
     */
    public function testALocalStartWithAUtcBoundIsRead(): void
    {
        self::assertCount(113, self::expand('FREQ=DAILY;UNTIL=19971224T000000Z', self::START));
    }

    /**
     * **A refusal at the limit says which limit.** Somebody reading a log
     * wants the number, and the layer above wants to know it was the
     * expansion rather than the data.
     */
    public function testTheRefusalAtTheLimitSaysWhichLimit(): void
    {
        $this->expectException(TooManyIterations::class);
        $this->expectExceptionMessage('limit of 12 iterations');

        self::expand('FREQ=DAILY', self::START, 20, 12);
    }

    /**
     * **A rule whose step leaps clear out of the calendar ends**, rather than
     * looking for a day in a year `checkdate` has never heard of and calling
     * every one of them ignorable until the iterations run out.
     */
    public function testARuleThatLeapsOutOfTheCalendarEnds(): void
    {
        self::assertSame(['99970101'], self::expand('FREQ=YEARLY;INTERVAL=30000', '99970101', 10, 20));
    }

    /**
     * A refusal says which two things do not go together.
     */
    public function testARefusalSaysWhatDidNotGoTogether(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('UNTIL');

        self::expand('FREQ=DAILY;UNTIL=19971224', '19970902T090000');
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
     * The nine-in-the-morning of every example in §3.8.5.3, written onto a
     * list of days.
     *
     * @param list<string> $days
     *
     * @return list<string>
     */
    private static function times(array $days): array
    {
        $instances = [];

        foreach ($days as $day) {
            $instances[] = $day . 'T090000';
        }

        return $instances;
    }
}
