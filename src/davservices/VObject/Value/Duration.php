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

use DateInterval;
use DavServices\VObject\ParseError;

/**
 * The `DURATION` value type: a length of time.
 *
 *     dur-value  = (["+"] / "-") "P" (dur-date / dur-time / dur-week)
 *     dur-date   = dur-day [dur-time]
 *     dur-time   = "T" (dur-hour / dur-minute / dur-second)
 *     dur-week   = 1*DIGIT "W"
 *     dur-hour   = 1*DIGIT "H" [dur-minute]
 *     dur-minute = 1*DIGIT "M" [dur-second]
 *     dur-second = 1*DIGIT "S"
 *     dur-day    = 1*DIGIT "D"
 *
 * **The grammar says more than it looks.**
 *
 * - **No years and no months.** RFC 5545 §3.3.6: "unlike [ISO.8601.2004],
 *   this value type doesn't support the 'Y' and 'M' designators to specify
 *   durations in terms of years and months." A month is not a length.
 * - **Weeks stand alone.** `dur-week` is an alternative to `dur-date` and
 *   `dur-time` rather than a part of them, so `P1W` is a duration and
 *   `P1WT1H` is not.
 * - **The components run downwards without gaps**: hours may be followed by
 *   minutes and minutes by seconds, so `PT5H20S` skips a rung and is no
 *   duration by this grammar, however many clients write it. What the lenient
 *   mode of R-VOBJ-03 makes of that is P4-06's question.
 * - **Negative durations are the ordinary case.** §3.3.6: "Negative durations
 *   are typically used to schedule an alarm to trigger before an associated
 *   time" — every `TRIGGER:-PT15M` is one.
 *
 * ## Why the written form is kept beside the interval
 *
 * PHP's `DateInterval` has no idea of weeks: `P1W` goes in and seven days come
 * out. Writing that back would turn somebody's week into days for no reason,
 * so the form is kept here and {@see self::toDateInterval()} is offered beside
 * it for arithmetic.
 */
final class Duration
{
    /**
     * `dur-value`, with the part after `P` left to {@see self::TIME} where it
     * has one: two small patterns, each mirroring one production, read better
     * than one that mirrors none.
     */
    private const GRAMMAR = '/^([+-])?P(?:([0-9]+)W|([0-9]+)D(?:T(.+))?|T(.+))$/';

    private const TIME = '/^(?:([0-9]+)H(?:([0-9]+)M(?:([0-9]+)S)?)?|([0-9]+)M(?:([0-9]+)S)?|([0-9]+)S)$/';

    private function __construct(
        private readonly bool $negative,
        private readonly int $weeks,
        private readonly int $days,
        private readonly int $hours,
        private readonly ?int $minutes,
        private readonly int $seconds,
    ) {
    }

    /**
     * The length a raw value names.
     *
     * @throws ParseError If it is no duration by the grammar
     */
    public static function decode(string $raw): self
    {
        if (preg_match(self::GRAMMAR, $raw, $parts) !== 1) {
            throw new ParseError(sprintf('"%s" is no duration.', $raw));
        }

        $negative = self::at($parts, 1) === '-';
        // The two alternatives of the grammar that carry a time are mutually
        // exclusive, so at most one of the two groups holds anything and
        // joining them says "whichever matched" without asking which.
        $time = self::at($parts, 4) . self::at($parts, 5);

        if (self::at($parts, 2) !== '') {
            return new self($negative, (int) self::at($parts, 2), 0, 0, null, 0);
        }

        [$hours, $minutes, $seconds] = self::timeIn($time, $raw);

        return new self($negative, 0, (int) self::at($parts, 3), $hours, $minutes, $seconds);
    }

    /**
     * Whether this length runs backwards.
     */
    public function isNegative(): bool
    {
        return $this->negative;
    }

    /**
     * The same length for arithmetic.
     *
     * A week becomes seven days here, which is what `DateInterval` has room
     * for; the written form keeps the week.
     */
    public function toDateInterval(): DateInterval
    {
        $interval = new DateInterval(sprintf(
            'P%dDT%dH%dM%dS',
            $this->weeks * 7 + $this->days,
            $this->hours,
            $this->minutes ?? 0,
            $this->seconds,
        ));

        $interval->invert = $this->negative ? 1 : 0;

        return $interval;
    }

    /**
     * The raw value, in the form it was read in.
     *
     * The optional plus is left off, because it says the same thing as no
     * sign at all. A length of nothing is written `PT0S`: the grammar offers
     * more than one way to say it, and this is the one every form of the
     * grammar has room for.
     */
    public function encode(): string
    {
        $sign = $this->negative ? '-' : '';

        if ($this->weeks > 0) {
            return sprintf('%sP%dW', $sign, $this->weeks);
        }

        $time = $this->timeWritten();

        if ($this->days === 0 && $time === '') {
            return $sign . 'PT0S';
        }

        return $sign . 'P' . ($this->days > 0 ? $this->days . 'D' : '') . $time;
    }

    /**
     * `dur-time`, with the rule that keeps a zero in the middle: the grammar
     * has no way to write seconds after hours without the minutes between
     * them.
     */
    private function timeWritten(): string
    {
        if ($this->hours > 0) {
            // The minutes are written where they were read, even as a zero:
            // the grammar has no way to put seconds after hours without them.
            $minutes = $this->minutes === null ? '' : $this->minutes . 'M';

            return 'T' . $this->hours . 'H' . $minutes . ($this->seconds > 0 ? $this->seconds . 'S' : '');
        }

        // Written where they were read, zero and all: `PT0M` is a duration
        // the grammar allows, and turning it into `PT0S` would change a file
        // for nothing.
        if ($this->minutes !== null) {
            return 'T' . $this->minutes . 'M' . ($this->seconds > 0 ? $this->seconds . 'S' : '');
        }

        return $this->seconds > 0 ? 'T' . $this->seconds . 'S' : '';
    }

    /**
     * The three parts of a `dur-time`, with the minutes null where they were
     * never written — which is a different thing from a written zero, and the
     * only thing that tells `PT1H` from `PT1H0M`.
     *
     * @throws ParseError If it is no `dur-time`
     *
     * @return array{int, int|null, int}
     */
    private static function timeIn(string $time, string $raw): array
    {
        if ($time === '') {
            return [0, null, 0];
        }

        if (preg_match(self::TIME, $time, $parts) !== 1) {
            throw new ParseError(sprintf('"%s" is no duration.', $raw));
        }

        if (self::at($parts, 1) !== '') {
            $minutes = self::at($parts, 2);

            return [(int) self::at($parts, 1), $minutes === '' ? null : (int) $minutes, (int) self::at($parts, 3)];
        }

        if (self::at($parts, 4) !== '') {
            return [0, (int) self::at($parts, 4), (int) self::at($parts, 5)];
        }

        return [0, null, (int) self::at($parts, 6)];
    }

    /**
     * One group of a match, or the empty string where the alternation never
     * reached it.
     *
     * **It is read through here rather than as `$parts[$n] ?? ''`** because a
     * static analyser knows the exact shape each branch of the alternation
     * produces, and would call every one of those guards dead. They are not
     * dead — they are what makes the three branches readable as one — so the
     * widening happens once, here, where it can be explained.
     *
     * @param array<int, string> $parts
     */
    private static function at(array $parts, int $group): string
    {
        return $parts[$group] ?? '';
    }
}
