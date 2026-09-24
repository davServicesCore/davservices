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

namespace DavServices\VObject\Value;

use DateTimeImmutable;
use DateTimeZone;
use DavServices\VObject\ParseError;

/**
 * The `DATE` value type: a day in the calendar.
 *
 *     date-value    = date-fullyear date-month date-mday
 *     date-fullyear = 4DIGIT
 *     date-month    = 2DIGIT        ;01-12
 *     date-mday     = 2DIGIT        ;01-28, 01-29, 01-30, 01-31
 *                                   ;based on month/year
 *
 * **"based on month/year" is a rule rather than a comment**: the thirty-first
 * of February is not a date, and neither is the twenty-ninth of a year that
 * is not a leap year.
 *
 * ## R-TZ-04: a date is not a moment
 *
 * A day has no instant until somebody names a time zone, **so there is no
 * method here that hands one over**. Reading `VALUE=DATE` as midnight UTC is
 * what moves an all-day event a day either way for everybody east or west of
 * Greenwich, and it is a mistake a class can simply decline to offer.
 *
 * {@see self::at()} is where the zone gets named, by a caller who can then be
 * held to it.
 */
final class Date
{
    private function __construct(
        private readonly int $year,
        private readonly int $month,
        private readonly int $day,
    ) {
    }

    /**
     * The day a raw value names.
     *
     * @throws ParseError If it is no date, or no day that exists
     */
    public static function decode(string $raw): self
    {
        if (preg_match('/^([0-9]{4})([0-9]{2})([0-9]{2})$/', $raw, $parts) !== 1) {
            throw new ParseError(sprintf('"%s" is no date.', $raw));
        }

        $year = (int) $parts[1];
        $month = (int) $parts[2];
        $day = (int) $parts[3];

        // "01-28, 01-29, 01-30, 01-31 based on month/year" — which is what
        // `checkdate` answers, leap years and all.
        if (!checkdate($month, $day, $year)) {
            throw new ParseError(sprintf('"%s" is no day that exists.', $raw));
        }

        return new self($year, $month, $day);
    }

    /**
     * The four-digit year.
     */
    public function year(): int
    {
        return $this->year;
    }

    /**
     * The month, from one to twelve.
     */
    public function month(): int
    {
        return $this->month;
    }

    /**
     * The day of the month, as far as that month goes.
     */
    public function day(): int
    {
        return $this->day;
    }

    /**
     * The moment this day begins in a named zone.
     *
     * **The zone is a parameter because it has to be a decision.** The same
     * day begins at two different instants in London and in New York, and
     * nothing in the value says which was meant (R-TZ-04).
     */
    public function at(DateTimeZone $zone): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $this->year, $this->month, $this->day), $zone);
    }

    /**
     * The raw value: four digits, two, two, with nothing between them.
     */
    public function encode(): string
    {
        return sprintf('%04d%02d%02d', $this->year, $this->month, $this->day);
    }
}
