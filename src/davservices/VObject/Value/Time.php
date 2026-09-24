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

use DavServices\VObject\ParseError;

/**
 * The `TIME` value type: a time of day.
 *
 *     time        = time-hour time-minute time-second [time-utc]
 *     time-hour   = 2DIGIT        ;00-23
 *     time-minute = 2DIGIT        ;00-59
 *     time-second = 2DIGIT        ;00-60
 *     time-utc    = "Z"
 *
 * **Sixty seconds is a second.** RFC 5545 §3.3.12: "The '60' value is used to
 * account for positive 'leap' seconds" — so a reader that refused it would
 * turn away a moment that has happened twenty-seven times.
 *
 * **The offset form is refused**, and §3.3.12 writes the counter-example out:
 * "The form of time with UTC offset MUST NOT be used … `230000-0800`". So is
 * a fraction: "Fractions of a second are not supported by this format."
 *
 * The plan does not name this type and the specification does, so it is here:
 * a client may write `VALUE=TIME`, and {@see DateTime} is built out of it
 * besides.
 */
final class Time
{
    private function __construct(
        private readonly int $hour,
        private readonly int $minute,
        private readonly int $second,
        private readonly bool $utc,
    ) {
    }

    /**
     * The time of day a raw value names.
     *
     * @throws ParseError If it is no time by the grammar
     */
    public static function decode(string $raw): self
    {
        if (preg_match('/^([0-9]{2})([0-9]{2})([0-9]{2})(Z?)$/', $raw, $parts) !== 1) {
            throw new ParseError(sprintf('"%s" is no time of day.', $raw));
        }

        $hour = (int) $parts[1];
        $minute = (int) $parts[2];
        $second = (int) $parts[3];

        if ($hour > 23 || $minute > 59 || $second > 60) {
            throw new ParseError(sprintf('"%s" is no time of day.', $raw));
        }

        return new self($hour, $minute, $second, $parts[4] === 'Z');
    }

    /**
     * The hour of the day, from zero to twenty-three.
     */
    public function hour(): int
    {
        return $this->hour;
    }

    /**
     * The minute of the hour, from zero to fifty-nine.
     */
    public function minute(): int
    {
        return $this->minute;
    }

    /**
     * The second of the minute — up to sixty, which is a leap second.
     */
    public function second(): int
    {
        return $this->second;
    }

    /**
     * Whether the value carried the UTC designator.
     */
    public function isUtc(): bool
    {
        return $this->utc;
    }

    /**
     * The raw value: six digits, and the designator where there was one.
     */
    public function encode(): string
    {
        return sprintf('%02d%02d%02d%s', $this->hour, $this->minute, $this->second, $this->utc ? 'Z' : '');
    }
}
