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

/**
 * Weeks as a recurrence rule counts them (RFC 5545 §3.3.10).
 *
 * **Two sentences are the whole of it**, and both are quoted where they are
 * used:
 *
 * > A week is defined as a seven day period, starting on the day of the week
 * > defined to be the week start (see WKST). Week number one of the calendar
 * > year is the first week that contains at least four (4) days in that
 * > calendar year.
 *
 * So a week is not a calendar object: it begins where `WKST` says and it
 * belongs to the year that holds most of it, which means **week one can begin
 * in December and the last week can end in January**. Every awkwardness
 * {@see ByRules} has with weeks comes from those two facts.
 *
 * **`WKST` is what this class is for.** Read since P4-07 and inert until
 * P4-08b, because §3.3.10 says exactly where it matters: "significant when a
 * WEEKLY 'RRULE' has an interval greater than 1, and a BYDAY rule part is
 * specified. This is also significant when in a YEARLY 'RRULE' when a
 * BYWEEKNO rule part is specified." Elsewhere the weeks tile the calendar
 * wherever they are cut, so where they are cut makes no difference.
 *
 * ## Why no table of the seven days
 *
 * PHP's `D` gives `Sun` to `Sat`, **whose first two letters in capitals are
 * exactly §3.3.10's own `weekday = "SU" / "MO" / "TU" / "WE" / "TH" / "FR" /
 * "SA"`**. So a day can say which weekday it is in the memo's spelling, and
 * there is no seven-entry table to keep in step with {@see Weekday} — which
 * matters more than it sounds, PHPStan at level 9 admitting no dynamic lookup
 * of any kind.
 */
final class Weeks
{
    /**
     * The seven days of a week, as offsets from one of them — which is as far
     * as a search for a weekday ever has to look.
     */
    private const A_WEEK = [0, 1, 2, 3, 4, 5, 6];

    /**
     * @param string $start `WKST` as a rule spells it, `SU` to `SA`
     */
    public function __construct(private readonly string $start)
    {
    }

    /**
     * How a day spells its weekday in a rule.
     *
     * §3.3.10 gives seven: `weekday = "SU" / "MO" / "TU" / "WE" / "TH" / "FR"
     * / "SA"`. PHP's `D` gives `Sun` to `Sat`, whose first two letters in
     * capitals are exactly those.
     */
    public static function spellingOf(DateTimeImmutable $day): string
    {
        return strtoupper(substr($day->format('D'), 0, 2));
    }

    /**
     * The first day on or after a given one that bears a weekday.
     *
     * Written like {@see self::startOfTheWeekOf()}, and for the same reason:
     * one of seven consecutive days bears any given weekday, so a search that
     * returned on finding it would leave its empty-handed way through
     * untestable.
     */
    public static function firstSuchDay(DateTimeImmutable $from, string $spelling): DateTimeImmutable
    {
        $first = $from;

        foreach (self::A_WEEK as $forward) {
            $later = $from->add(new DateInterval(sprintf('P%dD', $forward)));

            if (self::spellingOf($later) === $spelling) {
                $first = $later;
            }
        }

        return $first;
    }

    /**
     * The week holding a day, as its first day and its last.
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    public function holding(DateTimeImmutable $day): array
    {
        return self::from($this->startOfTheWeekOf($day));
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
    public function inTheYearOf(DateTimeImmutable $moment): int
    {
        $year = (int) $moment->format('Y');
        $fiftyTwo = $this->oneOf($moment, $year)->add(new DateInterval('P52W'));

        return $fiftyTwo < $this->oneOf($moment, $year + 1) ? 53 : 52;
    }

    /**
     * The week of a year that bears a number, or null where the year has not
     * got it.
     *
     * "Valid values are 1 to 53 or -53 to -1" — and a year without the week
     * named comes to nothing for it, which is the ignoring §3.3.10 asks for
     * of a day that does not exist. The number counts in the year the moment
     * falls in, and the week it names may reach outside that year at either
     * end.
     *
     * @param int $number Counted from one, the negatives already resolved
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}|null
     */
    public function numbered(int $number, DateTimeImmutable $moment): ?array
    {
        if ($number < 1 || $number > $this->inTheYearOf($moment)) {
            return null;
        }

        $first = $this->oneOf($moment, (int) $moment->format('Y'));

        return self::from($first->add(new DateInterval(sprintf('P%dW', $number - 1))));
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
    private function oneOf(DateTimeImmutable $moment, int $year): DateTimeImmutable
    {
        $january = $moment->setDate($year, 1, 1);
        $week = $this->startOfTheWeekOf($january);

        if ($week->add(new DateInterval('P3D')) >= $january) {
            return $week;
        }

        return $week->add(new DateInterval('P1W'));
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

        foreach (self::A_WEEK as $back) {
            $earlier = $day->sub(new DateInterval(sprintf('P%dD', $back)));

            if (self::spellingOf($earlier) === $this->start) {
                $start = $earlier;
            }
        }

        return $start;
    }

    /**
     * "A week is defined as a seven day period", counted from the day it
     * begins on.
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private static function from(DateTimeImmutable $first): array
    {
        return [$first, $first->add(new DateInterval('P6D'))];
    }
}
