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
 * other one it limits. The `N/A` cells are the three {@see Rule} refuses
 * before anything reaches here — `BYYEARDAY` in a `DAILY`, `WEEKLY` or
 * `MONTHLY` rule, and `BYMONTHDAY` in a `WEEKLY` one.
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
 * ## Where the memo cannot be followed to the letter
 *
 * `BYYEARDAY` fixes a month **and** a day, so in a `YEARLY` rule it collides
 * with `BYMONTH` and `BYMONTHDAY`, which the table also calls `Expand`. Two
 * parts cannot both set the month.
 *
 * **The memo resolves this very shape of collision elsewhere**, in `BYDAY`'s
 * Note 2: "Limit if BYYEARDAY or BYMONTHDAY is present" — the part naming the
 * coarser thing gives way and filters instead. Read the same way here. **That
 * is a reading rather than a quotation**, and it is marked as one because the
 * table and the evaluation order cannot both be satisfied.
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
     * Where `BYMONTHDAY` expands: the two periods that hold whole months.
     * `BYMONTH` and `BYYEARDAY` expand in a `YEARLY` rule and nowhere else.
     */
    private const MONTHLY_OR_YEARLY = ['MONTHLY', 'YEARLY'];

    /**
     * The frequencies whose period is longer than a day, so that the day of
     * an instance comes from the rule or from `DTSTART` rather than from the
     * period. A weekly period is longer than a day too, but the day within it
     * needs `BYDAY` to be named, so it waits for P4-08b.
     */
    private const LONGER_THAN_A_DAY = ['MONTHLY', 'YEARLY'];

    /** @var array{values: list<int>, expands: bool, limits: bool} */
    private readonly array $months;

    /** @var array{values: list<int>, expands: bool, limits: bool} */
    private readonly array $yearDays;

    /** @var array{values: list<int>, expands: bool, limits: bool} */
    private readonly array $monthDays;

    /** @var array{values: list<int>, expands: bool, limits: bool} */
    private readonly array $hours;

    /** @var array{values: list<int>, expands: bool, limits: bool} */
    private readonly array $minutes;

    /** @var array{values: list<int>, expands: bool, limits: bool} */
    private readonly array $seconds;

    private readonly bool $dayComesFromTheStart;

    public function __construct(Rule $rule, private readonly DateTimeImmutable $start)
    {
        $frequency = $rule->frequency()->value;
        $yearly = $frequency === 'YEARLY';
        $yearDays = $rule->byYearDay();

        // The collision the memo leaves open: a day of the year fixes the
        // month as well, so where it is named the two parts that name the
        // coarser thing give way and filter.
        $noDayOfTheYear = $yearDays === [];

        $this->yearDays = self::part($yearDays, $yearly);
        $this->months = self::part($rule->byMonth(), $yearly && $noDayOfTheYear);
        $this->monthDays = self::part(
            $rule->byMonthDay(),
            $noDayOfTheYear && in_array($frequency, self::MONTHLY_OR_YEARLY, true),
        );
        $this->hours = self::part($rule->byHour(), in_array($frequency, self::DAILY_AND_COARSER, true));
        $this->minutes = self::part($rule->byMinute(), in_array($frequency, self::HOURLY_AND_COARSER, true));
        $this->seconds = self::part($rule->bySecond(), in_array($frequency, self::MINUTELY_AND_COARSER, true));

        $this->dayComesFromTheStart = in_array($frequency, self::LONGER_THAN_A_DAY, true);
    }

    /**
     * Every candidate one period holds, in order, and null where a candidate
     * is no instance.
     *
     * @return Generator<int, ?DateTimeImmutable>
     */
    public function candidatesIn(DateTimeImmutable $anchor): Generator
    {
        foreach ($this->daysIn($anchor) as [$month, $day]) {
            foreach ($this->timesIn($anchor) as [$hour, $minute, $second]) {
                yield $this->candidate($anchor, $month, $day, $hour, $minute, $second);
            }
        }
    }

    /**
     * One part of the rule, with what the table makes of it here.
     *
     * @param list<int> $values
     * @param bool $expandsHere Whether the table says `Expand` at this
     *                          frequency
     *
     * @return array{values: list<int>, expands: bool, limits: bool}
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
     * One candidate, or null where its fields name no moment or a limiting
     * part excludes it.
     */
    private function candidate(
        DateTimeImmutable $anchor,
        int $month,
        int $day,
        int $hour,
        int $minute,
        int $second,
    ): ?DateTimeImmutable {
        $year = (int) $anchor->format('Y');

        // "Recurrence rules may generate recurrence instances with an invalid
        // date (e.g., February 30) […] Such recurrence instances MUST be
        // ignored and MUST NOT be counted as part of the recurrence set." A
        // sixtieth second is the same case: `seconds = 1*2DIGIT ;0 to 60`
        // admits a leap second, because leap seconds are real, and no minute
        // this library can address has one.
        if (!checkdate($month, $day, $year) || $second > 59) {
            return null;
        }

        $candidate = $anchor->setDate($year, $month, $day)->setTime($hour, $minute, $second);

        return $this->isKept($candidate) ? $candidate : null;
    }

    /**
     * Whether every limiting part admits this candidate.
     */
    private function isKept(DateTimeImmutable $candidate): bool
    {
        foreach ($this->fieldsOf($candidate) as [$value, $allowed, $limits]) {
            if ($limits && !in_array($value, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * What each part would be asked of this candidate: the candidate's value
     * for the field it names, the values it allows, and whether it is
     * filtering at all.
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
     * The months and days one period holds, in order.
     *
     * @return Generator<int, array{int, int}>
     */
    private function daysIn(DateTimeImmutable $anchor): Generator
    {
        if ($this->yearDays['expands']) {
            yield from $this->daysOfTheYearIn($anchor);

            return;
        }

        foreach ($this->monthsIn($anchor) as $month) {
            foreach ($this->daysOfTheMonthIn($anchor, $month) as $day) {
                yield [$month, $day];
            }
        }
    }

    /**
     * `BYYEARDAY` names a day of the year, which fixes the month as well.
     *
     * @return Generator<int, array{int, int}>
     */
    private function daysOfTheYearIn(DateTimeImmutable $anchor): Generator
    {
        $length = self::daysInTheYearOf($anchor);

        foreach (self::counted($this->yearDays['values'], $length) as $day) {
            // `setDate` would roll a day past the end of the year into the
            // next one, which would be an instance nobody asked for.
            if ($day >= 1 && $day <= $length) {
                $moment = $anchor->setDate((int) $anchor->format('Y'), 1, $day);

                yield [(int) $moment->format('n'), (int) $moment->format('j')];
            }
        }
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
        //
        // **The day before the first of the next month** is the last of this
        // one, and `setDate` turns a thirteenth month into January of the
        // year after — so December needs no case of its own.
        $length = (int) $anchor->setDate((int) $anchor->format('Y'), $month + 1, 0)->format('j');

        return self::counted($this->monthDays['values'], $length);
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
     * A list of days with the negative ones counted back from the end:
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
     * significance to the order of values in a list." A day the period has
     * not got is left in and refused at the candidate, which is where
     * §3.3.10's rule about ignoring one lives.
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
