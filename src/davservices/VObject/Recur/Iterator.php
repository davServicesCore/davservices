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
use DateTimeZone;
use DavServices\VObject\ParseError;
use DavServices\VObject\Value\Date;
use DavServices\VObject\Value\DateTime;
use Generator;

/**
 * Works out what a recurrence rule comes to (R-RRULE-01, R-RRULE-04).
 *
 *     foreach ((new Iterator($rule, $start))->instances() as $instance) {
 *         echo $instance->encode(), "\n";
 *     }
 *
 * **{@see Rule} read the rule; this works it out.** `FREQ`, `INTERVAL`,
 * `COUNT`, `UNTIL` and `WKST` for all seven frequencies, and every `BYxxx`
 * part but one: {@see ByRules} narrows and widens each period. `BYSETPOS` is
 * the one left, and it is refused rather than passed over — see
 * {@see NotExpanded}.
 *
 * ## Four sentences decide nearly everything
 *
 * - "The 'DTSTART' property value **always counts as the first
 *   occurrence**." So the first instance is the start itself, and `COUNT`
 *   counts it.
 * - "The UNTIL rule part defines a DATE or DATE-TIME value that bounds the
 *   recurrence rule in an **inclusive** manner."
 * - "If not present, and the COUNT rule part is also not present, the 'RRULE'
 *   is considered to **repeat forever**." Which is why this hands the
 *   instances over one at a time: a caller takes what it needs and the rest
 *   is never worked out (R-RRULE-04's lazy evaluation).
 * - "Recurrence rules may generate recurrence instances with an invalid date
 *   (e.g., February 30) […] Such recurrence instances **MUST be ignored and
 *   MUST NOT be counted** as part of the recurrence set."
 *
 * **That last one is why `DateTimeImmutable::modify('+1 month')` is nowhere
 * near this class.** Asked for a month after the thirty-first of January it
 * answers the third of March, which is neither ignored nor uncounted but
 * invented. The months and years are counted on the calendar instead and the
 * day is then asked whether it exists.
 *
 * ## What a value cannot know
 *
 * A `TZID` is a parameter of the property, and this expands the value. So two
 * things wait for the time-zone chunks (P4-11, P4-12) and are named here
 * rather than left to be discovered:
 *
 * - **The arithmetic is on the wall clock.** For a floating or UTC start that
 *   is exactly right. For one with a `TZID` it is right except across a
 *   change of offset, which is R-RRULE-05's "Wiederholungen über
 *   Zeitumstellungen" and P4-10's to settle.
 * - **And so is half of the sentence about ignoring an instance.** §3.3.10
 *   names two kinds: "an invalid date (e.g., February 30) **or nonexistent
 *   local time** (e.g., 1:30 AM on a day where the local time is moved
 *   forward by an hour at 1:00 AM)." The first is here; the second needs the
 *   zone that says when the clocks went forward, so it waits with the
 *   arithmetic above rather than being quietly forgotten.
 * - **So is the comparison with `UNTIL`** — and here the memo's own examples
 *   agree. "Every 3 hours from 9:00 AM to 5:00 PM on a specific day" starts
 *   at `19970902T090000` in New York and bounds itself with
 *   `UNTIL=19970902T170000Z`; nine in the morning there is 13:00 UTC, so
 *   15:00 local is 19:00 UTC, **past** the bound as an instant and inside it
 *   as a wall clock. §3.8.5.3's published answer is 09:00, 12:00 and 15:00 —
 *   the wall-clock one. Nobody should later "correct" this into disagreement
 *   with the memo's own answers without reading this paragraph first.
 */
final class Iterator
{
    /**
     * The hard iteration limit of the RRULE expansion (R-RRULE-04), as the
     * requirements' table of binding limits gives it.
     */
    public const ITERATIONS = 10000;

    /**
     * `date-fullyear = 4DIGIT` (§3.3.4): the calendar this grammar can write
     * down has a last year.
     */
    private const LAST_YEAR = 9999;

    /**
     * How many of the allowed iterations the expansion has spent. Set where
     * it is spent — asking for the instances starts the count afresh, so that
     * asking twice is asking twice.
     */
    private int $spent = 0;

    /**
     * How long one turn of each frequency that counts a fixed length of time
     * comes to, as a `dur-value`. `P%dW` is PHP's own spelling of a week, so
     * the seven days need not be counted here.
     *
     * **The two that count calendar months are not in it**, and that absence
     * is the dispatch: a month is not a length of time, which is the whole
     * reason {@see self::monthsOn()} exists.
     *
     * **A table rather than a `match` over the frequency**, and the coverage
     * gate is why. An exhaustive `match` over an enum still compiles a
     * fall-through for the case no arm took, and nothing can reach it — so
     * seven named arms cost two branches no test can ever execute. A lookup
     * whose misses are the other two frequencies has no dead way through it.
     *
     * @var array<string, string>
     */
    private const LENGTHS = [
        'SECONDLY' => 'PT%dS',
        'MINUTELY' => 'PT%dM',
        'HOURLY' => 'PT%dH',
        'DAILY' => 'P%dD',
        'WEEKLY' => 'P%dW',
    ];

    /**
     * @param int $iterations The hard limit, R-CAL-08: „Die Werte MÜSSEN
     *                        konfigurierbar sein, die genannten sind
     *                        Vorgaben"
     *
     * @throws ParseError If the rule and the start do not go together
     */
    public function __construct(
        private readonly Rule $rule,
        private readonly Date|DateTime $start,
        private readonly int $iterations = self::ITERATIONS,
    ) {

        // §3.8.5.3 calls an unsynchronised pair undefined — "The recurrence
        // set generated with a 'DTSTART' property value not synchronized with
        // the recurrence rule is undefined" — so refusing is a choice. It is
        // the choice that cannot quietly invent a calendar: a DATE is a day
        // and carries no hour for an hourly rule to step by.
        if ($start instanceof Date && self::needsATime($rule->frequency())) {
            throw new ParseError(sprintf(
                'A %s rule steps by a time, and a DATE start has none.',
                $rule->frequency()->value,
            ));
        }

        // §3.3.10 licenses ignoring a rule part exactly once and says so by
        // name; everywhere else the parts "are applied". So one this library
        // cannot yet apply is refused — see {@see NotExpanded}.
        if ($rule->bySetPosition() !== []) {
            throw new NotExpanded('BYSETPOS');
        }

        // §3.3.10: "The value of the UNTIL rule part MUST have the same value
        // type as the 'DTSTART' property." {@see Rule} could not check this,
        // having no DTSTART to compare with; here both are in hand.
        $until = $rule->until();

        if ($until !== null && $until instanceof Date !== $start instanceof Date) {
            throw new ParseError(
                'An UNTIL has the same value type as the DTSTART it bounds (RFC 5545 §3.3.10).',
            );
        }

        // And the half of the same paragraph that a value can be asked: "If
        // the 'DTSTART' property is specified as a date with UTC time […]
        // then the UNTIL rule part MUST be specified as a date with UTC
        // time." The other half — a start in local time — cannot be checked
        // here, because a `TZID` is a parameter of the property and this has
        // only the value; and §3.3.10 contradicts itself about it anyway, as
        // {@see Rule} sets out.
        if ($until !== null && self::isUtc($start) && !self::isUtc($until)) {
            throw new ParseError(
                'An UNTIL bounding a DTSTART in UTC is itself in UTC (RFC 5545 §3.3.10).',
            );
        }
    }

    /**
     * The instances the rule comes to, in order, one at a time.
     *
     *
     * @throws TooManyIterations If the expansion passes its hard limit
     *
     * @return Generator<int, Date|DateTime>
     */
    public function instances(): Generator
    {
        $yielded = 0;
        $start = $this->moment();

        foreach ($this->moments() as $moment) {
            // Asked before anything is handed over, because a count of nought
            // bounds the recurrence at nought: "The COUNT rule part defines
            // the number of occurrences at which to range-bound the
            // recurrence." The sentence about DTSTART says how the counting
            // works, not that the set can never be empty.
            if ($yielded === $this->rule->count()) {
                return;
            }

            // §3.8.5.3: "The 'DTSTART' property defines the first instance in
            // the recurrence set." One period can hold candidates on either
            // side of it — a monthly rule on the first and last day holds
            // both — and the ones before it are not in the set.
            if ($moment < $start) {
                continue;
            }

            $instance = $this->sameKindAs($moment);

            // "then COUNT and UNTIL are evaluated" — last of all, on what the
            // BYxxx parts left.
            if ($this->isPastTheBound($instance)) {
                return;
            }

            yield $instance;

            ++$yielded;
        }
    }

    /**
     * Every moment the rule considers, period after period, in order.
     *
     * **The hard limit is spent here**, and on two things: a period costs one
     * iteration and so does every candidate looked at inside it. Both are
     * ways for a rule to run away — a great many periods, or one period
     * holding a great many candidates — and R-RRULE-04 asks for a bound on
     * the work rather than on the answer.
     *
     *
     * @throws TooManyIterations If the expansion passes its hard limit
     *
     * @return Generator<int, DateTimeImmutable>
     */
    private function moments(): Generator
    {
        $end = $this->endOfTheCalendar();
        $by = new ByRules($this->rule, $this->moment());
        $this->spent = 0;

        // **A real bound rather than `for (;;)`.** A period costs one iteration
        // at least, so there can be no more periods than iterations — and a
        // loop whose only way out is a `return` leaves an edge the coverage
        // gate is right to call unreachable, which is the lesson P4-05 paid
        // for with `while (true)`.
        for ($step = 0; $step < $this->iterations; ++$step) {
            $this->spend();

            $anchor = $this->anchorAt($step);

            // The years have run out rather than anything being wrong, so the
            // expansion ends instead of refusing. The periods are in order,
            // so the first one past the end is the last there is.
            if ($anchor >= $end) {
                return;
            }

            foreach ($by->candidatesIn($anchor) as $candidate) {
                $this->spend();

                // **And asked of the candidates too, since P4-08b.** A week
                // reaches outside the year that numbers it: the last week of
                // 9999 runs to the second of January 10000, which is a date
                // `date-fullyear = 4DIGIT` cannot write down. Before
                // `BYWEEKNO` no candidate could leave its anchor's year, and
                // the guard was rightly deleted then.
                if ($candidate === null || $candidate >= $end) {
                    continue;
                }

                yield $candidate;
            }
        }

        // Reached where every period cost exactly its own iteration and held
        // no candidate at all — a rule naming the three-hundred-and-sixty-
        // sixth day of a run of common years, say. {@see self::spend()} is
        // what stops a single period from holding more than the whole budget.
        throw new TooManyIterations($this->iterations);
    }

    /**
     * Spends one of the iterations the expansion is allowed.
     *
     * @throws TooManyIterations If there are none left
     */
    private function spend(): void
    {
        if (++$this->spent > $this->iterations) {
            throw new TooManyIterations($this->iterations);
        }
    }

    /**
     * Where the rule's `$step`th turn falls, or null where that is no day
     * that exists.
     *
     * The three sub-day frequencies and the two that count days are a fixed
     * length of time and are added as one; the two that count calendar months
     * are worked out on the calendar, because a month is not a length.
     */
    private function anchorAt(int $step): DateTimeImmutable
    {
        $units = $step * $this->rule->interval();
        $frequency = $this->rule->frequency();
        $length = self::LENGTHS[$frequency->value] ?? null;

        if ($length === null) {
            // MONTHLY counts one month a turn and YEARLY twelve.
            return $this->monthsOn($units * ($frequency === Frequency::Yearly ? 12 : 1));
        }

        return $this->moment()->add(new DateInterval(sprintf($length, $units)));
    }

    /**
     * The start, moved on by a number of calendar months, keeping its day.
     *
     * Null where the month it lands in has no such day — "an invalid date
     * (e.g., February 30)" — which is the rule that forbids reaching for
     * `modify('+1 month')` and its answer of the third of March.
     */
    private function monthsOn(int $months): DateTimeImmutable
    {
        $date = $this->date();
        $counted = $date->month() - 1 + $months;
        $year = $date->year() + intdiv($counted, 12);
        $month = $counted % 12 + 1;

        if ($year > self::LAST_YEAR) {
            return $this->endOfTheCalendar();
        }

        // **The first of the month, not the start's day.** An anchor names a
        // period rather than an instance, and which days of it are instances
        // is {@see ByRules}' question — including the day the start would
        // have given it, which February has not got when the start is the
        // thirty-first of January.
        return $this->moment()->setDate($year, $month, 1);
    }

    /**
     * The start as a moment to count from, in UTC because UTC has no
     * summer time: the arithmetic here is on the wall clock, and UTC is the
     * frame where a day is a day.
     */
    private function moment(): DateTimeImmutable
    {
        // `19970902` and `19970902T090000` are both shapes PHP reads, so the
        // value's own written form is the start — no field needs taking apart
        // and a date needs no time invented for it.
        return new DateTimeImmutable($this->start->encode(), new DateTimeZone('UTC'));
    }

    /**
     * The first moment the grammar can no longer write down.
     *
     * `date-fullyear = 4DIGIT` (§3.3.4), so the calendar ends with 9999 and
     * everything from the first of January after it is past the end. Built
     * from the start rather than parsed, because PHP will not read a
     * five-digit year out of a string.
     */
    private function endOfTheCalendar(): DateTimeImmutable
    {
        return $this->moment()->setDate(self::LAST_YEAR + 1, 1, 1)->setTime(0, 0, 0);
    }

    /**
     * The day the start falls on, whichever kind of value it is.
     */
    private function date(): Date
    {
        return $this->start instanceof Date ? $this->start : $this->start->date();
    }

    /**
     * A moment written back as the kind of value the start was.
     *
     * **A date stays a date** (R-TZ-04): reading `VALUE=DATE` as a moment is
     * what moves an all-day event a day either way for everybody east or west
     * of Greenwich.
     */
    private function sameKindAs(DateTimeImmutable $moment): Date|DateTime
    {
        if ($this->start instanceof Date) {
            return Date::decode($moment->format('Ymd'));
        }

        return DateTime::decode($moment->format('Ymd\THis') . ($this->start->isUtc() ? 'Z' : ''));
    }

    /**
     * Whether an instance falls past the rule's bound.
     *
     * **The written forms are compared as they stand**, which comes out right
     * for three reasons together. The two are the same value type by then, so
     * they are the same shape. Every field is fixed-width and
     * most-significant first, so the shape sorts in time order. And where a
     * floating instance meets a bound in UTC — the pairing §3.8.5.3's own
     * examples use — the bound's trailing `Z` makes it the longer string, so
     * an instance on the bound's own wall clock sorts before it and is kept:
     * which is exactly what "bounds […] in an inclusive manner" asks for.
     */
    private function isPastTheBound(Date|DateTime $instance): bool
    {
        $until = $this->rule->until();

        return $until !== null && $instance->encode() > $until->encode();
    }

    /**
     * Whether a value is an instant in UTC.
     *
     * A `DATE` is a day and is in no time zone at all, so it is not, which is
     * what keeps the rule above from asking anything of a bound beside one.
     */
    private static function isUtc(Date|DateTime $value): bool
    {
        return $value instanceof DateTime && $value->isUtc();
    }

    /**
     * The three frequencies that step by a time rather than by a day.
     */
    private static function needsATime(Frequency $frequency): bool
    {
        return $frequency === Frequency::Hourly
            || $frequency === Frequency::Minutely
            || $frequency === Frequency::Secondly;
    }
}
