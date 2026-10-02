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
use DavServices\VObject\Recur\Frequency;
use DavServices\VObject\Recur\Rule;
use DavServices\VObject\Recur\Weekday;
use DavServices\VObject\Recur\WeekdayNumber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for `RECUR`, derived from RFC 5545 §3.3.10. R-RRULE-01.
 *
 *     recur           = recur-rule-part *( ";" recur-rule-part )
 *
 * **This reads and writes a recurrence rule. It does not expand one.** The
 * two are separate jobs and this library has done them separately all through
 * P4: the lexer reads lines before anything holds them, the model holds an
 * object before anything judges it. A rule that cannot be read correctly
 * cannot be expanded correctly either, and the grammar is long enough to
 * deserve its own chunk — fourteen rule parts, nine of them lists with ranges
 * of their own, and six MUSTs about which may stand beside which.
 *
 * ## Liberal in what it accepts, strict in what it writes
 *
 * §3.3.10 says both in one sentence, and they pull in opposite directions:
 *
 * > Compliant applications MUST accept rule parts ordered in any sequence,
 * > but to ensure backward compatibility with applications that pre-date this
 * > revision of iCalendar the FREQ rule part MUST be the first rule part
 * > specified in a RECUR value.
 *
 * So reading takes the parts in any order without a word of complaint, and
 * writing always puts `FREQ` first.
 *
 * ## A RECUR value is case-sensitive
 *
 * ABNF's own default is the other way — a quoted literal in RFC 5234 matches
 * either case — but RFC 5545 overrides it twice, in §2 and again in §3.1, in
 * the same words: "All names of properties, property parameters, enumerated
 * property values and property parameter values are case-insensitive.
 * **However, all other property values are case-sensitive, unless otherwise
 * stated.**"
 *
 * A `RECUR` value is a property value and is none of the four listed kinds,
 * and §3.3.10 states nothing otherwise. So `FREQ=DAILY` is a rule and
 * `freq=daily` is not.
 *
 * ## What is written back is the rule, not the spelling
 *
 * A part that says what the default says is left out: `INTERVAL=1` and
 * `WKST=MO` are exactly the absence of those parts. {@see Duration} set the
 * precedent by dropping the optional `+` — a value type is for callers who
 * mean to change something, and normalising what carries no meaning is not
 * the same as changing what does.
 */
#[CoversClass(Rule::class)]
#[CoversClass(Frequency::class)]
#[CoversClass(Weekday::class)]
#[CoversClass(WeekdayNumber::class)]
final class RuleTest extends TestCase
{
    /**
     * The forms of the grammar, read and written back unchanged.
     *
     * @param non-empty-string $raw
     */
    #[DataProvider('rulesThatSurviveTheRoundTrip')]
    public function testEachRuleSurvivesTheRoundTrip(string $raw): void
    {
        self::assertSame($raw, Rule::decode($raw)->encode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rulesThatSurviveTheRoundTrip(): iterable
    {
        yield 'the least a rule can be' => ['FREQ=DAILY'];

        yield 'every ten days' => ['FREQ=DAILY;INTERVAL=10'];

        yield 'ten of them' => ['FREQ=DAILY;COUNT=10'];

        yield 'until a date' => ['FREQ=DAILY;UNTIL=19971224'];

        yield 'until an instant' => ['FREQ=DAILY;UNTIL=19971224T000000Z'];

        yield 'a week that starts on Sunday' => ['FREQ=WEEKLY;WKST=SU'];

        yield 'seconds within the minute' => ['FREQ=MINUTELY;BYSECOND=0,30'];

        yield 'minutes within the hour' => ['FREQ=HOURLY;BYMINUTE=0,15,30,45'];

        yield 'hours of the day' => ['FREQ=DAILY;BYHOUR=9,17'];

        yield 'days of the week' => ['FREQ=WEEKLY;BYDAY=MO,WE,FR'];

        yield 'the first Monday of the month' => ['FREQ=MONTHLY;BYDAY=1MO'];

        yield 'and the last one' => ['FREQ=MONTHLY;BYDAY=-1MO'];

        yield 'days of the month' => ['FREQ=MONTHLY;BYMONTHDAY=1,15'];

        yield 'the last day of the month' => ['FREQ=MONTHLY;BYMONTHDAY=-1'];

        yield 'days of the year' => ['FREQ=YEARLY;BYYEARDAY=1,100,-1'];

        yield 'weeks of the year' => ['FREQ=YEARLY;BYWEEKNO=20,-1'];

        yield 'months' => ['FREQ=YEARLY;BYMONTH=1,12'];

        yield 'the last working day of the month' => ['FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1'];

        yield 'all of it at once' => [
            'FREQ=YEARLY;INTERVAL=2;COUNT=10;WKST=SU;BYMONTH=1;BYDAY=1MO;BYHOUR=9;BYMINUTE=30;BYSETPOS=1',
        ];
    }

    /**
     * **"The FREQ rule part identifies the type of recurrence rule."** All
     * seven of them, and nothing else.
     *
     * @param non-empty-string $raw
     */
    #[DataProvider('theSevenFrequencies')]
    public function testEveryFrequencyIsRead(string $raw, Frequency $frequency): void
    {
        self::assertSame($frequency, Rule::decode($raw)->frequency());
    }

    /**
     * @return iterable<string, array{string, Frequency}>
     */
    public static function theSevenFrequencies(): iterable
    {
        yield 'SECONDLY' => ['FREQ=SECONDLY', Frequency::Secondly];

        yield 'MINUTELY' => ['FREQ=MINUTELY', Frequency::Minutely];

        yield 'HOURLY' => ['FREQ=HOURLY', Frequency::Hourly];

        yield 'DAILY' => ['FREQ=DAILY', Frequency::Daily];

        yield 'WEEKLY' => ['FREQ=WEEKLY', Frequency::Weekly];

        yield 'MONTHLY' => ['FREQ=MONTHLY', Frequency::Monthly];

        yield 'YEARLY' => ['FREQ=YEARLY', Frequency::Yearly];
    }

    /**
     * **"Compliant applications MUST accept rule parts ordered in any
     * sequence"** — so a rule whose parts arrive the other way round is the
     * same rule.
     */
    public function testThePartsMayArriveInAnyOrder(): void
    {
        self::assertEquals(
            Rule::decode('FREQ=WEEKLY;COUNT=5;WKST=SU'),
            Rule::decode('WKST=SU;COUNT=5;FREQ=WEEKLY'),
        );
    }

    /**
     * **And writing always puts `FREQ` first**, for the reason the same
     * sentence gives: "to ensure backward compatibility with applications
     * that pre-date this revision of iCalendar".
     */
    public function testWritingAlwaysPutsTheFrequencyFirst(): void
    {
        self::assertSame('FREQ=WEEKLY;COUNT=5;WKST=SU', Rule::decode('WKST=SU;COUNT=5;FREQ=WEEKLY')->encode());
    }

    /**
     * **"The INTERVAL rule part contains a positive integer […] The default
     * value is '1'."** A part that says what the default says is the same as
     * no part at all, and is not written back.
     */
    public function testTheIntervalDefaultsToOneAndIsNotWrittenBack(): void
    {
        self::assertSame(1, Rule::decode('FREQ=DAILY')->interval());
        self::assertSame(1, Rule::decode('FREQ=DAILY;INTERVAL=1')->interval());
        self::assertSame('FREQ=DAILY', Rule::decode('FREQ=DAILY;INTERVAL=1')->encode());
    }

    /**
     * **"The default value is MO"** — the same again for `WKST`.
     */
    public function testTheWeekStartsOnMondayUnlessItSaysOtherwise(): void
    {
        self::assertSame(Weekday::Monday, Rule::decode('FREQ=WEEKLY')->weekStart());
        self::assertSame('FREQ=WEEKLY', Rule::decode('FREQ=WEEKLY;WKST=MO')->encode());
        self::assertSame(Weekday::Sunday, Rule::decode('FREQ=WEEKLY;WKST=SU')->weekStart());
    }

    /**
     * **"If not present, and the COUNT rule part is also not present, the
     * 'RRULE' is considered to repeat forever."** Both of them answer null,
     * which is the only way to say "no bound" without inventing one.
     */
    public function testARuleWithNoBoundSaysSoTwice(): void
    {
        $rule = Rule::decode('FREQ=DAILY');

        self::assertNull($rule->count());
        self::assertNull($rule->until());
    }

    /**
     * **"The COUNT rule part defines the number of occurrences at which to
     * range-bound the recurrence."**
     */
    public function testACountIsRead(): void
    {
        self::assertSame(10, Rule::decode('FREQ=DAILY;COUNT=10')->count());
    }

    /**
     * **`enddate = date / date-time`**, and both are kept as what they are:
     * a bound of a day is not a bound of an instant.
     */
    public function testAnUntilIsReadAsWhicheverItIs(): void
    {
        self::assertSame('19971224', Rule::decode('FREQ=DAILY;UNTIL=19971224')->until()?->encode());
        self::assertSame(
            '19971224T000000Z',
            Rule::decode('FREQ=DAILY;UNTIL=19971224T000000Z')->until()?->encode(),
        );
    }

    /**
     * **`weekdaynum = [[plus / minus] ordwk] weekday`** — the ordinal is
     * optional, and where it is missing it means "all days of this type
     * within the specified frequency".
     */
    public function testADayOfTheWeekMayCarryAnOrdinalOrNot(): void
    {
        self::assertEquals(
            [new WeekdayNumber(Weekday::Monday, null), new WeekdayNumber(Weekday::Friday, -2)],
            Rule::decode('FREQ=MONTHLY;BYDAY=MO,-2FR')->byDay(),
        );
    }

    /**
     * **"For example, within a MONTHLY rule, +1MO (or simply 1MO) represents
     * the first Monday within the month."** The plus says nothing the bare
     * number does not, so it is read and not written back — the same choice
     * {@see Duration} makes for `+P1D`.
     */
    public function testTheOptionalPlusIsDroppedOnTheWayOut(): void
    {
        self::assertSame('FREQ=MONTHLY;BYDAY=1MO', Rule::decode('FREQ=MONTHLY;BYDAY=+1MO')->encode());
        self::assertSame('FREQ=YEARLY;BYYEARDAY=100', Rule::decode('FREQ=YEARLY;BYYEARDAY=+100')->encode());
    }

    /**
     * Every list is a list, and each is handed back as what it holds.
     */
    public function testEachListIsHandedBackAsNumbers(): void
    {
        $rule = Rule::decode(
            'FREQ=YEARLY;BYSECOND=0,30;BYMINUTE=15;BYHOUR=9;BYMONTHDAY=1,-1;BYYEARDAY=-1;BYWEEKNO=20;BYMONTH=6;BYSETPOS=2',
        );

        self::assertSame([0, 30], $rule->bySecond());
        self::assertSame([15], $rule->byMinute());
        self::assertSame([9], $rule->byHour());
        self::assertSame([1, -1], $rule->byMonthDay());
        self::assertSame([-1], $rule->byYearDay());
        self::assertSame([20], $rule->byWeekNumber());
        self::assertSame([6], $rule->byMonth());
        self::assertSame([2], $rule->bySetPosition());
    }

    /**
     * And a rule with none of them says so with empty lists rather than null:
     * there is no difference between "no BYMONTH" and "no months named".
     */
    public function testARuleWithNoListsSaysSoWithEmptyOnes(): void
    {
        $rule = Rule::decode('FREQ=DAILY');

        self::assertSame([], $rule->bySecond());
        self::assertSame([], $rule->byMinute());
        self::assertSame([], $rule->byHour());
        self::assertSame([], $rule->byDay());
        self::assertSame([], $rule->byMonthDay());
        self::assertSame([], $rule->byYearDay());
        self::assertSame([], $rule->byWeekNumber());
        self::assertSame([], $rule->byMonth());
        self::assertSame([], $rule->bySetPosition());
    }

    /**
     * **What the grammar does not allow is refused.**
     *
     * @param non-empty-string $raw
     */
    #[DataProvider('whatIsNoRule')]
    public function testWhatIsNoRuleIsRefused(string $raw): void
    {
        $this->expectException(ParseError::class);

        Rule::decode($raw);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function whatIsNoRule(): iterable
    {
        yield 'nothing at all' => [''];

        yield 'no FREQ, which is REQUIRED' => ['COUNT=10'];

        yield 'a frequency nobody has heard of' => ['FREQ=FORTNIGHTLY'];

        yield 'a rule part nobody has heard of' => ['FREQ=DAILY;BYFORTNIGHT=2'];

        yield 'a part with no equals sign' => ['FREQ=DAILY;COUNT'];

        yield 'a part with no name' => ['FREQ=DAILY;=10'];

        yield 'an empty part' => ['FREQ=DAILY;'];

        yield 'lower case, which §2 makes a different value' => ['freq=daily'];

        yield 'a frequency in lower case' => ['FREQ=daily'];

        yield 'FREQ twice, and parts MUST only be specified once' => ['FREQ=DAILY;FREQ=WEEKLY'];

        yield 'any other part twice' => ['FREQ=DAILY;COUNT=2;COUNT=3'];

        yield 'UNTIL and COUNT together' => ['FREQ=DAILY;UNTIL=19971224;COUNT=10'];

        yield 'an INTERVAL of nought, where the prose says positive' => ['FREQ=DAILY;INTERVAL=0'];

        yield 'an INTERVAL that is no number' => ['FREQ=DAILY;INTERVAL=x'];

        yield 'a signed INTERVAL, which 1*DIGIT does not allow' => ['FREQ=DAILY;INTERVAL=-2'];

        yield 'a COUNT that is no number' => ['FREQ=DAILY;COUNT=many'];

        yield 'an UNTIL that is no date' => ['FREQ=DAILY;UNTIL=soon'];

        yield 'a weekday nobody has heard of' => ['FREQ=WEEKLY;BYDAY=XX'];

        yield 'a WKST that is no weekday' => ['FREQ=WEEKLY;WKST=XX'];

        yield 'a WKST with an ordinal, which weekday has no room for' => ['FREQ=WEEKLY;WKST=1MO'];

        yield 'an empty list' => ['FREQ=DAILY;BYHOUR='];

        yield 'a hole in a list' => ['FREQ=DAILY;BYHOUR=9,,17'];
    }

    /**
     * **Every list has its own range, and each is in the grammar's own
     * comment.** A value outside it is no value of that list.
     *
     * @param non-empty-string $raw
     */
    #[DataProvider('valuesOutsideTheirRange')]
    public function testAValueOutsideItsRangeIsRefused(string $raw): void
    {
        $this->expectException(ParseError::class);

        Rule::decode($raw);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function valuesOutsideTheirRange(): iterable
    {
        yield 'a 61st second' => ['FREQ=MINUTELY;BYSECOND=61'];

        yield 'a negative second' => ['FREQ=MINUTELY;BYSECOND=-1'];

        yield 'a 60th minute' => ['FREQ=HOURLY;BYMINUTE=60'];

        yield 'a 24th hour' => ['FREQ=DAILY;BYHOUR=24'];

        yield 'a 54th week of the ordinal' => ['FREQ=MONTHLY;BYDAY=54MO'];

        yield 'a nought-th Monday' => ['FREQ=MONTHLY;BYDAY=0MO'];

        yield 'a 32nd day of the month' => ['FREQ=MONTHLY;BYMONTHDAY=32'];

        yield 'a nought-th day of the month' => ['FREQ=MONTHLY;BYMONTHDAY=0'];

        yield 'a -32nd day of the month' => ['FREQ=MONTHLY;BYMONTHDAY=-32'];

        yield 'a 367th day of the year' => ['FREQ=YEARLY;BYYEARDAY=367'];

        yield 'a -367th day of the year' => ['FREQ=YEARLY;BYYEARDAY=-367'];

        yield 'a 54th week of the year' => ['FREQ=YEARLY;BYWEEKNO=54'];

        yield 'a 13th month' => ['FREQ=YEARLY;BYMONTH=13'];

        yield 'a nought-th month' => ['FREQ=YEARLY;BYMONTH=0'];

        yield 'a 367th position in the set' => ['FREQ=MONTHLY;BYDAY=MO;BYSETPOS=367'];

        yield 'a nought-th position in the set' => ['FREQ=MONTHLY;BYDAY=MO;BYSETPOS=0'];
    }

    /**
     * **The seconds go to sixty**, which is not a slip: `seconds = 1*2DIGIT
     * ;0 to 60`, and the sixty-first is what a leap second is called.
     */
    public function testTheSecondsGoToSixty(): void
    {
        self::assertSame([60], Rule::decode('FREQ=MINUTELY;BYSECOND=60')->bySecond());
    }

    /**
     * **Six sentences say which rule parts may not stand beside which**, and
     * each is a MUST NOT of §3.3.10 in its own words.
     *
     * @param non-empty-string $raw
     */
    #[DataProvider('partsThatMayNotStandTogether')]
    public function testPartsThatMayNotStandTogetherAreRefused(string $raw, string $because): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches($because);

        Rule::decode($raw);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function partsThatMayNotStandTogether(): iterable
    {
        // "The BYDAY rule part MUST NOT be specified with a numeric value
        // when the FREQ rule part is not set to MONTHLY or YEARLY."
        yield 'an ordinal weekday in a weekly rule' => ['FREQ=WEEKLY;BYDAY=1MO', '/BYDAY/'];

        yield 'and in a daily one' => ['FREQ=DAILY;BYDAY=-1FR', '/BYDAY/'];

        // "Furthermore, the BYDAY rule part MUST NOT be specified with a
        // numeric value with the FREQ rule part set to YEARLY when the
        // BYWEEKNO rule part is specified."
        yield 'an ordinal weekday in a yearly rule that names weeks' => [
            'FREQ=YEARLY;BYWEEKNO=20;BYDAY=1MO',
            '/BYDAY/',
        ];

        // "The BYMONTHDAY rule part MUST NOT be specified when the FREQ rule
        // part is set to WEEKLY."
        yield 'days of the month in a weekly rule' => ['FREQ=WEEKLY;BYMONTHDAY=1', '/BYMONTHDAY/'];

        // "The BYYEARDAY rule part MUST NOT be specified when the FREQ rule
        // part is set to DAILY, WEEKLY, or MONTHLY."
        yield 'days of the year in a daily rule' => ['FREQ=DAILY;BYYEARDAY=1', '/BYYEARDAY/'];

        yield 'days of the year in a weekly rule' => ['FREQ=WEEKLY;BYYEARDAY=1', '/BYYEARDAY/'];

        yield 'days of the year in a monthly rule' => ['FREQ=MONTHLY;BYYEARDAY=1', '/BYYEARDAY/'];

        // "This rule part MUST NOT be used when the FREQ rule part is set to
        // anything other than YEARLY."
        yield 'weeks of the year in a monthly rule' => ['FREQ=MONTHLY;BYWEEKNO=20', '/BYWEEKNO/'];

        // "It MUST only be used in conjunction with another BYxxx rule part."
        yield 'a position in a set nothing else selects' => ['FREQ=MONTHLY;BYSETPOS=-1', '/BYSETPOS/'];
    }

    /**
     * **And what the same sentences allow is allowed**, which is the half a
     * test list forgets.
     *
     * @param non-empty-string $raw
     */
    #[DataProvider('partsThatMayStandTogether')]
    public function testPartsThatMayStandTogetherAreRead(string $raw): void
    {
        self::assertSame($raw, Rule::decode($raw)->encode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function partsThatMayStandTogether(): iterable
    {
        yield 'an ordinal weekday in a monthly rule' => ['FREQ=MONTHLY;BYDAY=1MO'];

        yield 'and in a yearly one that names no weeks' => ['FREQ=YEARLY;BYDAY=20MO'];

        yield 'a bare weekday in a weekly rule' => ['FREQ=WEEKLY;BYDAY=MO'];

        yield 'and a bare one beside weeks of the year' => ['FREQ=YEARLY;BYWEEKNO=20;BYDAY=MO'];

        yield 'days of the year in a yearly rule' => ['FREQ=YEARLY;BYYEARDAY=100'];

        yield 'days of the month in a monthly rule' => ['FREQ=MONTHLY;BYMONTHDAY=15'];

        yield 'a position in a set something else selects' => ['FREQ=MONTHLY;BYDAY=MO;BYSETPOS=-1'];
    }

    /**
     * **A refusal says what it saw.** A calendar of ten thousand lines has
     * one bad rule in it, and the rule's own words are what say which.
     */
    public function testARefusalSaysWhatItSaw(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('FORTNIGHTLY');

        Rule::decode('FREQ=FORTNIGHTLY');
    }

    /**
     * **§3.3.10's own worked example**, word for word — the one it walks
     * through rule part by rule part to arrive at "every Sunday in January at
     * 8:30 AM and 9:30 AM, every other year".
     */
    public function testTheExampleFromTheSpecification(): void
    {
        $rule = Rule::decode('FREQ=YEARLY;INTERVAL=2;BYMONTH=1;BYDAY=SU;BYHOUR=8,9;BYMINUTE=30');

        self::assertSame(Frequency::Yearly, $rule->frequency());
        self::assertSame(2, $rule->interval());
        self::assertSame([1], $rule->byMonth());
        self::assertEquals([new WeekdayNumber(Weekday::Sunday, null)], $rule->byDay());
        self::assertSame([8, 9], $rule->byHour());
        self::assertSame([30], $rule->byMinute());
    }
}
