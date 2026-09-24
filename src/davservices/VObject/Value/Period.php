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
 * The `PERIOD` value type: a stretch of time.
 *
 *     period          = period-explicit / period-start
 *     period-explicit = date-time "/" date-time
 *     period-start    = date-time "/" dur-value
 *
 * **Two forms, and each carries a MUST of its own** (RFC 5545 §3.3.9):
 *
 * - `period-explicit`: "The start MUST be before the end."
 * - `period-start`: "a period of time consisting of a start and **positive**
 *   duration of time."
 *
 * The two agree with each other, which is why a stretch of no length is
 * refused however it is written: "before" is not "before or at", and a
 * duration of nothing is not positive.
 *
 * ## How "before" is decided
 *
 * By the wall clock as written. A {@see DateTime} knows whether it carries the
 * UTC designator and nothing more — FORM #3 of §3.3.5 hangs on a `TZID`
 * parameter, and a parameter belongs to the property. So a period whose halves
 * are written in different forms is compared as it stands, and whether such a
 * period means anything is a question for the validation of P4-06.
 */
final class Period
{
    private const SEPARATOR = '/';

    /**
     * **One field, not two.** A period ends either at a moment or after a
     * length, never both and never neither — so holding two nullable fields
     * would be holding two states the grammar has no room for, and every
     * reader of them would have to be told which pairs are possible.
     */
    private function __construct(
        private readonly DateTime $start,
        private readonly DateTime|Duration $until,
    ) {
    }

    /**
     * The stretch a raw value names.
     *
     * @throws ParseError If it is neither form, or breaks the rule that form
     *                    carries
     */
    public static function decode(string $raw): self
    {
        $halves = explode(self::SEPARATOR, $raw);

        if (count($halves) !== 2) {
            throw new ParseError(sprintf('"%s" is no period: it has no start and end.', $raw));
        }

        $start = DateTime::decode($halves[0]);

        // `dur-value = (["+"] / "-") "P" …`, so the sign is optional and the
        // `P` is not: that one letter is the whole difference between the two
        // forms.
        if (preg_match('/^[+-]?P/', $halves[1]) === 1) {
            return self::lasting($start, Duration::decode($halves[1]));
        }

        return self::until($start, DateTime::decode($halves[1]));
    }

    /**
     * Where it begins.
     */
    public function start(): DateTime
    {
        return $this->start;
    }

    /**
     * Where it ends, or null where it was written as a length instead.
     */
    public function end(): ?DateTime
    {
        return $this->until instanceof DateTime ? $this->until : null;
    }

    /**
     * How long it lasts, or null where it was written as an end instead.
     */
    public function duration(): ?Duration
    {
        return $this->until instanceof Duration ? $this->until : null;
    }

    /**
     * The raw value, in the form it was read in. Nothing is normalised: a
     * period written as a start and an end stays that way.
     */
    public function encode(): string
    {
        return $this->start->encode() . self::SEPARATOR . $this->until->encode();
    }

    /**
     * @throws ParseError If the end is not after the start (§3.3.9)
     */
    private static function until(DateTime $start, DateTime $end): self
    {
        if (self::wallClockOf($start) >= self::wallClockOf($end)) {
            throw new ParseError('A period starts before it ends.');
        }

        return new self($start, $end);
    }

    /**
     * @throws ParseError If the length is not positive (§3.3.9)
     */
    private static function lasting(DateTime $start, Duration $duration): self
    {
        if ($duration->isNegative() || $duration->encode() === 'PT0S') {
            throw new ParseError('A period lasts a positive duration.');
        }

        return new self($start, $duration);
    }

    /**
     * The wall clock as written, which is all a value knows of itself.
     */
    private static function wallClockOf(DateTime $value): string
    {
        return $value->date()->encode() . sprintf(
            '%02d%02d%02d',
            $value->time()->hour(),
            $value->time()->minute(),
            $value->time()->second(),
        );
    }
}
