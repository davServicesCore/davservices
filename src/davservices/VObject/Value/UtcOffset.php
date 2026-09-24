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
 * The `UTC-OFFSET` value type: a distance from UTC.
 *
 *     utc-offset   = time-numzone
 *     time-numzone = ("+" / "-") time-hour time-minute [time-second]
 *
 * RFC 5545 §3.3.14 carries three rules that are easy to read past, and a
 * client has got each of them wrong somewhere:
 *
 * - **"The PLUS SIGN character MUST be specified for positive UTC offsets"**,
 *   so an unsigned `0100` is no offset.
 * - **"The value of '-0000' and '-000000' are not allowed."** A negative zero
 *   is the older formats' way of saying "no offset known", which is a
 *   different thing from UTC and has no room here.
 * - **"The time-second, if present, MUST NOT be 60"** — a leap second is a
 *   moment, not a distance.
 */
final class UtcOffset
{
    private function __construct(private readonly int $seconds)
    {
    }

    /**
     * The distance a raw value names, in seconds.
     *
     * @throws ParseError If it breaks the grammar or any of §3.3.14's rules
     */
    public static function decode(string $raw): self
    {
        if (preg_match('/^([+-])([0-9]{2})([0-9]{2})([0-9]{2})?$/', $raw, $parts) !== 1) {
            throw new ParseError(sprintf('"%s" is no offset from UTC.', $raw));
        }

        $minutes = (int) $parts[3];
        $seconds = (int) ($parts[4] ?? '0');

        if ($minutes > 59 || $seconds > 59) {
            throw new ParseError(sprintf('"%s" is no offset from UTC.', $raw));
        }

        $distance = (int) $parts[2] * 3600 + $minutes * 60 + $seconds;

        if ($parts[1] === '-' && $distance === 0) {
            throw new ParseError('An offset of "-0000" says no offset is known, which this format has no room for.');
        }

        return new self($parts[1] === '-' ? -$distance : $distance);
    }

    /**
     * The distance in seconds, negative where it is behind UTC.
     */
    public function seconds(): int
    {
        return $this->seconds;
    }

    /**
     * The raw value, with the sign the rule requires and the seconds only
     * where there are any: §3.3.14 makes them optional and has them default
     * to zero, so the shorter form says the same thing.
     */
    public function encode(): string
    {
        $distance = abs($this->seconds);
        $seconds = $distance % 60;
        $written = sprintf(
            '%s%02d%02d',
            $this->seconds < 0 ? '-' : '+',
            intdiv($distance, 3600),
            intdiv($distance % 3600, 60),
        );

        return $seconds === 0 ? $written : $written . sprintf('%02d', $seconds);
    }
}
