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

namespace DavServices\VObject\Recur;

use DateInterval;
use DateTimeImmutable;
use Generator;

/**
 * Narrows and widens one period of a recurrence (RFC 5545 §3.3.10).
 *
 * **The memo publishes the specification as a table**, which is unusual
 * enough to be worth saying. For every `BYxxx` part and every frequency it
 * gives `Expand`, `Limit` or `N/A`, and says what the third means: "The term
 * 'N/A' means that the corresponding BYxxx rule part MUST NOT be used with
 * the corresponding FREQ value."
 *
 * **Every cell of it is here**, said once rather than forty-two times: each
 * part carries the frequencies at which the table says `Expand`, and at every
 * other one it limits. The `N/A` cells are the ones {@see Rule} refuses before
 * anything reaches here.
 *
 * **And the table has a shape worth seeing.** A part naming a field finer
 * than the period widens the set, and one naming a field the period already
 * fixes narrows it: `BYSECOND` expands from `MINUTELY` upwards, `BYMINUTE`
 * from `HOURLY`, `BYHOUR` from `DAILY`.
 *
 * ## Expand sets a field; limit filters one
 *
 * An instance is a year, a month, a day and a time. **Where a part expands,
 * the field's values come from the rule; where it limits, the field has to be
 * in the rule's list.** The memo states the other half in a note of its own:
 * "Since none of the BYDAY, BYMONTHDAY, or BYYEARDAY components are
 * specified, **the day is gotten from 'DTSTART'**."
 *
 * More generally: **the period fixes the fields down to its own granularity
 * and `DTSTART` supplies the rest** — "Information, not contained in the
 * rule, necessary to determine the various recurrence instance start time and
 * dates are derived from the Start Time ('DTSTART') component attribute." A
 * monthly period fixes the year and the month, so the day and the time come
 * from the start; an hourly one fixes everything down to the hour.
 *
 * **And a part that is not there sets nothing**, whatever the table says of
 * it: the table answers what a part does where it appears. A rule with no
 * `BYSECOND` takes its seconds from the period or the start, not from an
 * empty list.
 *
 * ## `BYDAY` is the part with notes instead of cells
 *
 * The table sends a reader away for it — "BYDAY has some special behavior
 * depending on the FREQ value and this is described in separate notes below
 * the table" — and the two notes are the only conditional cells it has:
 *
 * > Note 1: Limit if BYMONTHDAY is present; otherwise, special expand for
 * > MONTHLY.
 * >
 * > Note 2: Limit if BYYEARDAY or BYMONTHDAY is present; otherwise, special
 * > expand for WEEKLY if BYWEEKNO present; otherwise, special expand for
 * > MONTHLY if BYMONTH present; otherwise, special expand for YEARLY.
 *
 * **Both halves of both notes are the same two sentences.** A part naming a
 * day more exactly makes `BYDAY` filter; otherwise it widens, and what it
 * widens — the **unit** an ordinal counts in — is the finest thing the rest
 * of the rule names: a week, a month, or the period itself. That is
 * {@see self::unitsIn()}, and it is the whole of both notes.
 *
 * **§3.3.10 says the same thing in prose and cannot be followed there.** "The
 * numeric value in a BYDAY rule part with the FREQ rule part set to YEARLY
 * corresponds to an offset within the month when the BYMONTH rule part is
 * present, and corresponds to an offset within the year when the BYWEEKNO or
 * BYMONTH rule parts are present" — `BYMONTH` being present cannot mean both
 * the month and the year. Note 2 is what that sentence was reaching for and
 * says it without ambiguity, and the table sends a reader to the notes for
 * exactly this, so the notes are the authority and the prose is a slip.
 *
 * ## Where the memo cannot be followed to the letter
 *
 * Three parts can each fix a day, and the table calls all three `Expand` in a
 * `YEARLY` rule: `BYYEARDAY` fixes a month as well as a day, `BYMONTHDAY`
 * fixes a day of whichever month, and `BYWEEKNO` fixes a week that no month
 * contains. Two parts cannot both set the same field.
 *
 * **The memo resolves this very shape of collision itself**, in `BYDAY`'s Note
 * 2: "Limit if BYYEARDAY or BYMONTHDAY is present" — the part naming the
 * coarser thing gives way and filters instead. Read the same way throughout:
 * `BYYEARDAY` sets the day where it is there, then `BYWEEKNO`, then
 * `BYMONTHDAY`, which is also the memo's own evaluation order. **That is a
 * reading rather than a quotation**, and it is marked as one because the table
 * and the evaluation order cannot both be satisfied.
 *
 * ## Nothing is counted that was not looked at
 *
 * {@see self::candidatesIn()} hands over **every candidate it considers**,
 * and null where that candidate is no instance — February the thirtieth, or a
 * field a limiting part excludes. R-RRULE-04's hard limit is on iterations
 * rather than on instances, and a caller can only honour that if it is told
 * what was looked at rather than only what came of it.
 */
final class ByRules
{
    /**
     * Where `BYHOUR` expands: every frequency whose period is a day or
     * longer.
     */
    private const DAILY_AND_COARSER = ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'];

    /**
     * Where `BYMINUTE` expands.
     */
    private const HOURLY_AND_COARSER = ['HOURLY', 'DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'];

    /**
     * Where `BYSECOND` expands.
     */
    private const MINUTELY_AND_COARSER = ['MINUTELY', 'HOURLY', 'DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'];

    /**
     * Where `BYDAY` expands, the two notes allowing: the three frequencies
     * whose period holds whole days. `WEEKLY` is a plain `Expand` cell and
     * the other two are the notes', which comes to the same test here —
     * `BYMONTHDAY` and `BYYEARDAY` are both `N/A` beside `WEEKLY`, so a
     * weekly rule can carry neither of the parts that would make it filter.
     */
    private const WEEKLY_AND_COARSER = ['WEEKLY', 'MONTHLY', 'YEARLY'];

    /**
     * Where `BYMONTHDAY` expands: the two periods that hold whole months.
     * `BYMONTH`, `BYWEEKNO` and `BYYEARDAY` expand in a `YEARLY` rule and
     * nowhere else.
     */
    private const MONTHLY_OR_YEARLY = ['MONTHLY', 'YEARLY'];

    /**
     * The frequencies whose period is longer than a month, so that the day of
     * an instance comes from the rule or from `DTSTART` rather than from the
     * period. A weekly period is longer than a day too, but its anchor falls
     * on the start's own weekday already, so there the period gives the day.
     */
    private const LONGER_THAN_A_DAY = ['MONTHLY', 'YEARLY'];

    /**
     * How many days back the seven candidates for the start of a week lie.
     */
    private const A_WEEK_BACK = [0, 1, 2, 3, 4, 5, 6];

    /** @var array{values: list<int>, expands: bool, limits: bool} */
    private readonly array $months;

    /** @var array{values: list<int>, expands: bool, limits: bool} */
    private readonly array $weekNumbers;

    /** @var array{values: list<int>, expands: bool, limits: bool} */
    private readonly array $yearDays;

    /** @var array{values: list<int>, expands: bool, limits: bool} */
    private readonly array $monthDays;

    /** @var array{values: list<WeekdayNumber>, expands: bool, limits: bool} */
    private readonly array $weekdays;

    /** @var array{values: list<int>, expands: bool, limits: bool} */
    private readonly array $hours;

    /** @var array{values: list<int>, expands: bool, limits: bool} */
    private readonly array $minutes;

    /** @var array{values: list<int>, expands: bool, limits: bool} */
    private readonly array $seconds;

    private readonly string $frequency;

    /**
     * `WKST` as a rule spells it, which is what a day's own weekday is
     * compared with — see {@see self::spellingOf()}.
     */
    private readonly string $weekStart;

    private readonly bool $dayComesFromTheStart;

    public function __construct(Rule $rule, private readonly DateTimeImmutable $start)
    {
        $this->frequency = $rule->frequency()->value;
        $this->weekStart = $rule->weekStart()->value;

        $yearly = $this->frequency === 'YEARLY';
        $yearDays = $rule->byYearDay();
        $weekNumbers = $rule->byWeekNumber();
        $monthDays = $rule->byMonthDay();

        // The collisions the memo leaves open, resolved as its own Note 2
        // resolves them: where a part fixes the day more exactly, the ones
        // naming something coarser give way and filter.
        $noDayOfTheYear = $yearDays === [];
        $noWeekOfTheYear = $weekNumbers === [];
        $noDayOfTheMonth = $monthDays === [];

        $this->yearDays = self::part($yearDays, $yearly);

        // The table gives `BYWEEKNO` `Expand` for `YEARLY` and `N/A` for
        // every other frequency, which {@see Rule} refuses — so where it is
        // here at all it expands, unless a day of the year has already fixed
        // the day and left it nothing to set.
        $this->weekNumbers = self::part($weekNumbers, $noDayOfTheYear);

        // A week is in no month, so a rule naming both can only mean the days
        // of that week which fall in that month.
        $this->months = self::part(
            self::inOrder($rule->byMonth()),
            $yearly && $noDayOfTheYear && $noWeekOfTheYear,
        );

        $this->monthDays = self::part(
            $monthDays,
            $noDayOfTheYear && $noWeekOfTheYear && in_array($this->frequency, self::MONTHLY_OR_YEARLY, true),
        );

        // Note 1 and Note 2, which say the same thing twice: "Limit if
        // BYYEARDAY or BYMONTHDAY is present; otherwise, special expand".
        $this->weekdays = self::part(
            $rule->byDay(),
            $noDayOfTheYear && $noDayOfTheMonth && in_array($this->frequency, self::WEEKLY_AND_COARSER, true),
        );

        $this->hours = self::part(
            self::inOrder($rule->byHour()),
            in_array($this->frequency, self::DAILY_AND_COARSER, true),
        );
        $this->minutes = self::part(
            self::inOrder($rule->byMinute()),
            in_array($this->frequency, self::HOURLY_AND_COARSER, true),
        );
        $this->seconds = self::part(
            self::inOrder($rule->bySecond()),
            in_array($this->frequency, self::MINUTELY_AND_COARSER, true),
        );

        $this->dayComesFromTheStart = in_array($this->frequency, self::LONGER_THAN_A_DAY, true);
    }

    /**
     * Every candidate one period holds, in order, and null where a candidate
     * is no instance.
     *
     * @return Generator<int, ?DateTimeImmutable>
     */
    public function candidatesIn(DateTimeImmutable $anchor): Generator
    {
        foreach ($this->daysIn($anchor) as $day) {
            foreach ($this->timesIn($anchor) as $time) {
                yield $this->candidate($anchor, $day, $time);
            }
        }
    }

    /**
     * One part of the rule, with what the table makes of it here.
     *
     * @template TValue of int|WeekdayNumber
     *
     * @param list<TValue> $values
     * @param bool $expandsHere Whether the table says `Expand` at this
     *                          frequency
     *
     * @return array{values: list<TValue>, expands: bool, limits: bool}
     */
    private static function part(array $values, bool $expandsHere): array
    {
        $expands = $values !== [] && $expandsHere;

        return [
            'values' => $values,
            'expands' => $expands,
            'limits' => $values !== [] && !$expands,
        ];
    }

    /**
     * A part's values in order, §3.1.1: "There is no significance to the
     * order of values in a list."
     *
     * So one period's candidates come out in order however the rule was
     * written — which is also what lets `UNTIL` stop at the first instance
     * past the bound instead of having to look at them all.
     *
     * **The parts counted from the end of something need this later**, not
     * here: a negative value cannot be put in order until it has been
     * resolved against a length, which is {@see self::counted()}'s business.
     *
     * @param list<int> $values
     *
     * @return list<int>
     */
    private static function inOrder(array $values): array
    {
        sort($values);

        return $values;
    }

    /**
     * One candidate, or null where its fields name no moment or a limiting
     * part excludes it.
     *
     * @param array{int, int, int} $day A year, a month and a day of it
     * @param array{int, int, int} $time
     */
    private function candidate(DateTimeImmutable $anchor, array $day, array $time): ?DateTimeImmutable
    {
        [$year, $month, $dayOfTheMonth] = $day;
        [$hour, $minute, $second] = $time;

        // "Recurrence rules may generate recurrence instances with an invalid
        // date (e.g., February 30) […] Such recurrence instances MUST be
        // ignored and MUST NOT be counted as part of the recurrence set." A
        // sixtieth second is the same case: `seconds = 1*2DIGIT ;0 to 60`
        // admits a leap second, because leap seconds are real, and no minute
        // this library can address has one.
        if (!checkdate($month, $dayOfTheMonth, $year) || $second > 59) {
            return null;
        }

        $candidate = $anchor->setDate($year, $month, $dayOfTheMonth)->setTime($hour, $minute, $second);

        return $this->isKept($candidate) ? $candidate : null;
    }

    /**
     * Whether every limiting part admits this candidate.
     */
    private function isKept(DateTimeImmutable $candidate): bool
    {
        // `BYDAY` names a weekday rather than a number, so it is asked apart
        // from the six that name one. Both its notes begin the same way:
        // "Limit if …".
        if ($this->weekdays['limits'] && !$this->namesTheDayOf($candidate)) {
            return false;
        }

        // **And `BYWEEKNO` is the one part the table never calls `Limit`**,
        // which it nonetheless does here: a `BYYEARDAY` names a finer thing
        // than a week and so sets the day, leaving the week to filter. The
        // table and the evaluation order cannot both be satisfied, as the
        // class says above — and a part that filtered nothing would be a
        // part left out, which §3.3.10 licenses exactly once and not here.
        if ($this->weekNumbers['limits'] && !$this->fallsInANamedWeek($candidate)) {
            return false;
        }

        foreach ($this->fieldsOf($candidate) as [$value, $allowed, $limits]) {
            if ($limits && !in_array($value, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a filtering `BYDAY` names this candidate's day.
     *
     * An ordinal counts in the same unit here as where the part widens
     * instead ({@see self::unitsIn()}), there being no reason for the memo's
     * cascade to mean one thing for a set it builds and another for a set it
     * filters. So `BYMONTHDAY=13;BYDAY=2FR` is the thirteenth where it is the
     * second Friday of its month — which it is whenever it is a Friday at
     * all, thirteen being six more than seven.
     */
    private function namesTheDayOf(DateTimeImmutable $candidate): bool
    {
        $wanted = $candidate->format('Ymd');

        foreach ($this->weekdaysIn($this->unitAround($candidate)) as $day) {
            if ($day->format('Ymd') === $wanted) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a filtering `BYWEEKNO` holds this candidate's day.
     *
     * The weeks are numbered in the candidate's own year, which is the
     * period's: a day of the year cannot fall outside the year that counts
     * it.
     */
    private function fallsInANamedWeek(DateTimeImmutable $candidate): bool
    {
        foreach ($this->weeksIn($candidate) as [$first, $last]) {
            if ($candidate >= $first && $candidate <= $last) {
                return true;
            }
        }

        return false;
    }

    /**
     * What each part that names a number would be asked of this candidate:
     * the candidate's value for the field it names, the values it allows, and
     * whether it is filtering at all.
     *
     * The two parts that count days are resolved against this candidate's own
     * year and month, because "-10 represents the tenth to the last day of
     * the month" is a different day in February than in March.
     *
     * @return list<array{int, list<int>, bool}>
     */
    private function fieldsOf(DateTimeImmutable $candidate): array
    {
        return [
            [(int) $candidate->format('n'), $this->months['values'], $this->months['limits']],
            [
                (int) $candidate->format('z') + 1,
                self::counted($this->yearDays['values'], self::daysInTheYearOf($candidate)),
                $this->yearDays['limits'],
            ],
            [
                (int) $candidate->format('j'),
                self::counted($this->monthDays['values'], (int) $candidate->format('t')),
                $this->monthDays['limits'],
            ],
            [(int) $candidate->format('G'), $this->hours['values'], $this->hours['limits']],
            [(int) $candidate->format('i'), $this->minutes['values'], $this->minutes['limits']],
            [(int) $candidate->format('s'), $this->seconds['values'], $this->seconds['limits']],
        ];
    }

    /**
     * The days one period holds, in order, each as a year, a month and a day
     * of that month.
     *
     * **Three numbers rather than a date** because a part can name a day that
     * does not exist — February the thirtieth — and that day has to reach
     * {@see self::candidate()} to be ignored there rather than be quietly
     * turned into the second of March.
     *
     * **At most one part sets the day**, the collisions above having been
     * resolved, and they are asked in the memo's own evaluation order:
     * "BYMONTH, BYWEEKNO, BYYEARDAY, BYMONTHDAY, BYDAY".
     *
     * **A list rather than a generator.** A period holds at most a year of
     * days, so there is nothing here to hand over lazily; the laziness
     * R-RRULE-04 asks for is at the candidates, where one period can hold
     * eighty-six thousand of them. And `ci.yml` has the other reason: the
     * coverage driver attaches a generator's branch map the first time the
     * function runs, and it is the fragile part of a suite this size.
     *
     * @return list<array{int, int, int}>
     */
    private function daysIn(DateTimeImmutable $anchor): array
    {
        if ($this->yearDays['expands']) {
            return $this->daysOfTheYearIn($anchor);
        }

        if ($this->weekNumbers['expands'] || $this->weekdays['expands']) {
            return $this->daysOfTheUnitsIn($anchor);
        }

        $year = (int) $anchor->format('Y');
        $days = [];

        foreach ($this->monthsIn($anchor) as $month) {
            foreach ($this->daysOfTheMonthIn($anchor, $month) as $day) {
                $days[] = [$year, $month, $day];
            }
        }

        return $days;
    }

    /**
     * `BYYEARDAY` names a day of the year, which fixes the month as well.
     *
     * @return list<array{int, int, int}>
     */
    private function daysOfTheYearIn(DateTimeImmutable $anchor): array
    {
        $year = (int) $anchor->format('Y');
        $length = self::daysInTheYearOf($anchor);
        $days = [];

        foreach (self::counted($this->yearDays['values'], $length) as $day) {
            // `setDate` would roll a day past the end of the year into the
            // next one, which would be an instance nobody asked for.
            if ($day >= 1 && $day <= $length) {
                $moment = $anchor->setDate($year, 1, $day);

                $days[] = [$year, (int) $moment->format('n'), (int) $moment->format('j')];
            }
        }

        return $days;
    }

    /**
     * The days the units of one period hold, in order.
     *
     * **A unit can reach outside the period that named it.** Week one of a
     * year begins in the December before it where that week holds four days
     * of the new year, so a candidate's year is its own and not the anchor's.
     *
     * **A list rather than a generator**, as the units themselves are: a
     * period holds at most fifty-three weeks or twelve months, and so at most
     * a year of days. The laziness R-RRULE-04 asks for is at the candidates,
     * which is where it stays — and every generator nested inside another is
     * a frame the coverage driver has to carry through every resumption.
     *
     * @return list<array{int, int, int}>
     */
    private function daysOfTheUnitsIn(DateTimeImmutable $anchor): array
    {
        $days = [];

        foreach ($this->unitsIn($anchor) as $unit) {
            foreach ($this->daysOfTheUnit($unit) as $day) {
                $days[] = [(int) $day->format('Y'), (int) $day->format('n'), (int) $day->format('j')];
            }
        }

        return $days;
    }

    /**
     * The stretches of one period that hold its days, in order, each as its
     * first day and its last.
     *
     * **This cascade is Note 2's**: "special expand for WEEKLY if BYWEEKNO
     * present; otherwise, special expand for MONTHLY if BYMONTH present;
     * otherwise, special expand for YEARLY." The unit is the finest thing the
     * rest of the rule names, and it is what an ordinal in `BYDAY` counts in
     * — `-1MO` is the last Monday of the month in a monthly rule and the last
     * Monday of the year in a yearly one.
     *
     * @return list<array{DateTimeImmutable, DateTimeImmutable}>
     */
    private function unitsIn(DateTimeImmutable $anchor): array
    {
        if ($this->weekNumbers['expands']) {
            return $this->weeksIn($anchor);
        }

        if ($this->months['expands']) {
            $months = [];

            foreach ($this->months['values'] as $month) {
                $months[] = self::theMonthOf($anchor, $month);
            }

            return $months;
        }

        return [$this->thePeriodItself($anchor)];
    }

    /**
     * The weeks `BYWEEKNO` names in one period, in order.
     *
     * @return list<array{DateTimeImmutable, DateTimeImmutable}>
     */
    private function weeksIn(DateTimeImmutable $anchor): array
    {
        $year = (int) $anchor->format('Y');
        $first = $this->startOfWeekOne($anchor, $year);
        $length = $this->weeksInTheYear($anchor, $year);
        $weeks = [];

        foreach (self::counted($this->weekNumbers['values'], $length) as $number) {
            // "Valid values are 1 to 53 or -53 to -1" — and a year without
            // the week named comes to nothing for it, which is the ignoring
            // §3.3.10 asks for of a day that does not exist.
            if ($number >= 1 && $number <= $length) {
                $weeks[] = self::theWeekFrom($first->add(new DateInterval(sprintf('P%dW', $number - 1))));
            }
        }

        return $weeks;
    }

    /**
     * How many weeks a year holds, which is fifty-two or fifty-three.
     *
     * A year is fifty-two weeks and a day or two, so there is a fifty-third
     * exactly where the following year's first week begins later than
     * fifty-two weeks on. **The memo checks the same arithmetic itself**:
     * "Assuming a Monday week start, week 53 can only occur when Thursday is
     * January 1 or if it is a leap year and Wednesday is January 1."
     */
    private function weeksInTheYear(DateTimeImmutable $moment, int $year): int
    {
        $fiftyTwo = $this->startOfWeekOne($moment, $year)->add(new DateInterval('P52W'));

        return $fiftyTwo < $this->startOfWeekOne($moment, $year + 1) ? 53 : 52;
    }

    /**
     * Where week one of a year begins.
     *
     * "Week number one of the calendar year is the first week that contains
     * at least four (4) days in that calendar year." Four days of the year
     * are the first of January and the three after it, so the week holding
     * the first of January is week one where it begins no more than three
     * days before it, and week two otherwise.
     */
    private function startOfWeekOne(DateTimeImmutable $moment, int $year): DateTimeImmutable
    {
        $january = $moment->setDate($year, 1, 1);
        $week = $this->startOfTheWeekOf($january);

        if ($week->add(new DateInterval('P3D')) >= $january) {
            return $week;
        }

        return $week->add(new DateInterval('P1W'));
    }

    /**
     * The unit the period itself names, where no other part names a finer
     * one: Note 1's "otherwise, special expand for MONTHLY" and Note 2's
     * "otherwise, special expand for YEARLY".
     *
     * **A month answers for the frequencies whose own period is a day or
     * less** as well as for a monthly rule, and the unit makes no difference
     * to them: §3.3.10 admits no ordinal there — "The BYDAY rule part MUST
     * NOT be specified with a numeric value when the FREQ rule part is not
     * set to MONTHLY or YEARLY" — and a bare weekday is the same answer in
     * any unit.
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private function thePeriodItself(DateTimeImmutable $moment): array
    {
        if ($this->frequency === 'WEEKLY') {
            return $this->theWeekOf($moment);
        }

        if ($this->frequency === 'YEARLY') {
            $year = (int) $moment->format('Y');

            return [$moment->setDate($year, 1, 1), $moment->setDate($year, 12, 31)];
        }

        return self::theMonthOf($moment, (int) $moment->format('n'));
    }

    /**
     * The unit a filtering `BYDAY` counts an ordinal in: the one holding the
     * candidate, by the same cascade that builds the units of a period.
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private function unitAround(DateTimeImmutable $moment): array
    {
        if ($this->weekNumbers['expands']) {
            return $this->theWeekOf($moment);
        }

        if ($this->months['expands']) {
            return self::theMonthOf($moment, (int) $moment->format('n'));
        }

        return $this->thePeriodItself($moment);
    }

    /**
     * One month of a year, from its first day to its last.
     *
     * **The day before the first of the next month** is the last of this one,
     * and `setDate` turns a thirteenth month into January of the year after,
     * so December needs no case of its own.
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private static function theMonthOf(DateTimeImmutable $moment, int $month): array
    {
        $year = (int) $moment->format('Y');

        return [$moment->setDate($year, $month, 1), $moment->setDate($year, $month + 1, 0)];
    }

    /**
     * The week holding a day.
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private function theWeekOf(DateTimeImmutable $day): array
    {
        return self::theWeekFrom($this->startOfTheWeekOf($day));
    }

    /**
     * "A week is defined as a seven day period", counted from the day it
     * begins on.
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private static function theWeekFrom(DateTimeImmutable $first): array
    {
        return [$first, $first->add(new DateInterval('P6D'))];
    }

    /**
     * Where the week holding a day begins.
     *
     * "A week is defined as a seven day period, starting on the day of the
     * week defined to be the week start (see WKST)" — so it begins at the
     * latest `WKST` day that is not after this one, which is one of the seven
     * ending here.
     *
     * **Written without a way out of the loop on purpose.** Exactly one of
     * seven consecutive days bears any given weekday, so a search that
     * returned as soon as it found one would leave its empty-handed way
     * through untestable, and the coverage gate would be right to say so.
     */
    private function startOfTheWeekOf(DateTimeImmutable $day): DateTimeImmutable
    {
        $start = $day;

        foreach (self::A_WEEK_BACK as $back) {
            $earlier = $day->sub(new DateInterval(sprintf('P%dD', $back)));

            if (self::spellingOf($earlier) === $this->weekStart) {
                $start = $earlier;
            }
        }

        return $start;
    }

    /**
     * The days one unit holds, in order.
     *
     * @param array{DateTimeImmutable, DateTimeImmutable} $unit
     *
     * @return list<DateTimeImmutable>
     */
    private function daysOfTheUnit(array $unit): array
    {
        if ($this->weekdays['expands']) {
            return $this->weekdaysIn($unit);
        }

        // "Since none of the BYDAY, BYMONTHDAY, or BYYEARDAY components are
        // specified, the day is gotten from 'DTSTART'." Where one of them
        // **is** specified and filtering rather than setting the day, that
        // sentence does not hold, and every day of the unit is a candidate
        // for it to filter. Reached with a `BYWEEKNO` beside a `BYMONTHDAY`,
        // which the memo gives no example of and does admit.
        if ($this->monthDays['limits']) {
            return self::everyDayOf($unit);
        }

        // A week names seven days and nothing else says which, so the start's
        // own weekday is the one: there is no other sense in which a week has
        // a day.
        return self::daysOf($unit, self::spellingOf($this->start), null);
    }

    /**
     * The days `BYDAY` names in one unit, in order and each of them once.
     *
     * @param array{DateTimeImmutable, DateTimeImmutable} $unit
     *
     * @return list<DateTimeImmutable>
     */
    private function weekdaysIn(array $unit): array
    {
        $days = [];

        foreach ($this->weekdays['values'] as $wanted) {
            foreach (self::daysOf($unit, $wanted->day()->value, $wanted->ordinal()) as $day) {
                // §3.8.5.3: "Duplicate instances are ignored." `BYDAY=MO,1MO`
                // names the first Monday of the unit twice.
                $days[$day->format('Ymd')] = $day;
            }
        }

        // §3.1.1: "There is no significance to the order of values in a
        // list", so a unit's days come out in order however it was written.
        ksort($days);

        return array_values($days);
    }

    /**
     * The days one entry of `BYDAY` names inside a unit, in order.
     *
     * Without an ordinal, every one of them: "If an integer modifier is not
     * present, it means all days of this type within the specified frequency.
     * For example, within a MONTHLY rule, MO represents all Mondays within
     * the month." With one, that one: "+1MO (or simply 1MO) represents the
     * first Monday within the month, whereas -1MO represents the last Monday
     * of the month."
     *
     * **A unit without that many comes to nothing for it**, which is the same
     * ignoring §3.3.10 asks of a day that does not exist: a fifth Monday is
     * something some months have and others have not.
     *
     * @param array{DateTimeImmutable, DateTimeImmutable} $unit
     * @param string $spelling One of the seven weekdays as §3.3.10 spells
     *                         them, which is what {@see self::spellingOf()}
     *                         answers of a day
     *
     * @return list<DateTimeImmutable>
     */
    private static function daysOf(array $unit, string $spelling, ?int $ordinal): array
    {
        $days = [];

        foreach (self::everyDayOf($unit) as $day) {
            if (self::spellingOf($day) === $spelling) {
                $days[] = $day;
            }
        }

        if ($ordinal === null) {
            return $days;
        }

        // A negative ordinal counts from the end, which is what a negative
        // offset into the list does — and `ordwk` is "1 to 53", so there is
        // no nought to tell the two apart for.
        return array_slice($days, $ordinal > 0 ? $ordinal - 1 : $ordinal, 1);
    }

    /**
     * Every day one unit holds, in order.
     *
     * @param array{DateTimeImmutable, DateTimeImmutable} $unit
     *
     * @return list<DateTimeImmutable>
     */
    private static function everyDayOf(array $unit): array
    {
        [$day, $last] = $unit;
        $days = [];

        while ($day <= $last) {
            $days[] = $day;
            $day = $day->add(new DateInterval('P1D'));
        }

        return $days;
    }

    /**
     * How a day spells its weekday in a rule.
     *
     * §3.3.10 gives seven: `weekday = "SU" / "MO" / "TU" / "WE" / "TH" /
     * "FR" / "SA"`. PHP's `D` gives `Sun` to `Sat`, whose first two letters
     * in capitals are exactly those — so a day can say which weekday it is in
     * the memo's own spelling, and no table of seven has to be kept in step
     * with {@see Weekday}.
     */
    private static function spellingOf(DateTimeImmutable $day): string
    {
        return strtoupper(substr($day->format('D'), 0, 2));
    }

    /**
     * @return list<int>
     */
    private function monthsIn(DateTimeImmutable $anchor): array
    {
        return $this->months['expands'] ? $this->months['values'] : [(int) $anchor->format('n')];
    }

    /**
     * @return list<int>
     */
    private function daysOfTheMonthIn(DateTimeImmutable $anchor, int $month): array
    {
        if (!$this->monthDays['expands']) {
            return [(int) ($this->dayComesFromTheStart ? $this->start : $anchor)->format('j')];
        }

        // Counted in this candidate's month rather than the anchor's: a
        // period longer than a month holds several, and they are not all the
        // same length.
        [, $last] = self::theMonthOf($anchor, $month);

        return self::counted($this->monthDays['values'], (int) $last->format('j'));
    }

    /**
     * The times one period holds, in order.
     *
     * @return Generator<int, array{int, int, int}>
     */
    private function timesIn(DateTimeImmutable $anchor): Generator
    {
        foreach (self::fieldIn($this->hours, $anchor, 'G') as $hour) {
            foreach (self::fieldIn($this->minutes, $anchor, 'i') as $minute) {
                foreach (self::fieldIn($this->seconds, $anchor, 's') as $second) {
                    yield [$hour, $minute, $second];
                }
            }
        }
    }

    /**
     * One time field: the rule's values where the part expands, and the
     * anchor's own where it does not.
     *
     * @param array{values: list<int>, expands: bool, limits: bool} $part
     *
     * @return list<int>
     */
    private static function fieldIn(array $part, DateTimeImmutable $anchor, string $format): array
    {
        return $part['expands'] ? $part['values'] : [(int) $anchor->format($format)];
    }

    /**
     * A list of numbers with the negative ones counted back from the end:
     * "-10 represents the tenth to the last day of the month", and "-1
     * represents the last day of the year (December 31st) and -306 represents
     * the 306th to the last day of the year (March 1st)".
     *
     * **That last number was chosen to be read twice.** 365 − 306 + 1 is 60
     * and 366 − 306 + 1 is 61, and the sixtieth day of a common year and the
     * sixty-first of a leap year are both the first of March: a day counted
     * back from the end moves with the length of the year.
     *
     * Sorted and without repetition, so that one period's candidates come out
     * in order however the list was written — §3.1.1: "There is no
     * significance to the order of values in a list." A value the period has
     * not got is left in and refused later, which is where §3.3.10's rule
     * about ignoring one lives.
     *
     * @param list<int> $values
     *
     * @return list<int>
     */
    private static function counted(array $values, int $length): array
    {
        $days = [];

        foreach ($values as $value) {
            $day = $value < 0 ? $length + $value + 1 : $value;

            // §3.8.5.3: "Duplicate instances are ignored." `BYMONTHDAY=30,-1`
            // names one day twice in a month of thirty, and a period hands it
            // over once.
            if (!in_array($day, $days, true)) {
                $days[] = $day;
            }
        }

        sort($days);

        return $days;
    }

    private static function daysInTheYearOf(DateTimeImmutable $moment): int
    {
        return (int) $moment->format('L') === 1 ? 366 : 365;
    }
}
