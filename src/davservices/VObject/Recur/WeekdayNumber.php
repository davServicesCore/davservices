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

use DavServices\VObject\ParseError;

/**
 * One entry of a `BYDAY` list (RFC 5545 §3.3.10).
 *
 *     weekdaynum = [[plus / minus] ordwk] weekday
 *     ordwk      = 1*2DIGIT       ;1 to 53
 *
 * **The ordinal is the whole point of the type.** §3.3.10: "within a MONTHLY
 * rule, +1MO (or simply 1MO) represents the first Monday within the month,
 * whereas -1MO represents the last Monday of the month." Without one, "it
 * means all days of this type within the specified frequency" — so `MO` in a
 * monthly rule is every Monday of the month, and the absence of a number is
 * itself a meaning rather than a gap.
 *
 * It keeps the name the grammar gives it. `weekdaynum` reads oddly in prose,
 * but a reader with §3.3.10 open finds it at once, and the alternatives all
 * describe one of its two halves at the expense of the other.
 */
final class WeekdayNumber
{
    private const GRAMMAR = '/^(?:([+-])?([0-9]{1,2}))?([A-Z]{2})$/';

    /**
     * @param int|null $ordinal Which one of that day within the period, or
     *                          null for all of them. Never zero: `ordwk` is
     *                          "1 to 53", and a nought-th Monday is no day
     */
    public function __construct(
        private readonly Weekday $day,
        private readonly ?int $ordinal,
    ) {
    }

    /**
     * The entry a raw value names.
     *
     * @throws ParseError If it is no `weekdaynum`
     */
    public static function decode(string $raw): self
    {
        if (preg_match(self::GRAMMAR, $raw, $parts) !== 1) {
            throw new ParseError(sprintf('"%s" is no day of a BYDAY list.', $raw));
        }

        $day = Weekday::tryFrom($parts[3]);

        if ($day === null) {
            throw new ParseError(sprintf('"%s" is no day of the week.', $parts[3]));
        }

        if ($parts[2] === '') {
            return new self($day, null);
        }

        return new self($day, self::ordinalIn($parts[1], $parts[2], $raw));
    }

    /**
     * The day itself.
     */
    public function day(): Weekday
    {
        return $this->day;
    }

    /**
     * Which one of that day within the period, or null for all of them.
     */
    public function ordinal(): ?int
    {
        return $this->ordinal;
    }

    /**
     * As it is written in a rule.
     *
     * **The optional plus is not written back**, because it says exactly what
     * the bare number says: "+1MO (or simply 1MO)". The shorter form is the
     * one every reader takes.
     */
    public function encode(): string
    {
        return ($this->ordinal === null ? '' : $this->ordinal) . $this->day->value;
    }

    /**
     * `[plus / minus] ordwk`, with "1 to 53" read as the range it says.
     *
     * @throws ParseError If it is outside that range
     */
    private static function ordinalIn(string $sign, string $digits, string $raw): int
    {
        $ordinal = (int) $digits;

        if ($ordinal < 1 || $ordinal > 53) {
            throw new ParseError(sprintf('"%s" is outside ordwk, which RFC 5545 §3.3.10 gives as 1 to 53.', $raw));
        }

        return $sign === '-' ? -$ordinal : $ordinal;
    }
}
