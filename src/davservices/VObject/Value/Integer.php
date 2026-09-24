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
 * The `INTEGER` value type: a signed whole number.
 *
 *     integer = (["+"] / "-") 1*DIGIT
 *
 * "If the sign is not specified, then the value is assumed to be positive"
 * (RFC 5545 §3.3.8), and "No additional content value encoding … is defined
 * for this value type", which is why nothing here undoes a backslash.
 *
 * ## The one place the two specifications disagree
 *
 * - RFC 5545 §3.3.8: "The valid range for 'integer' is -2147483648 to
 *   2147483647."
 * - RFC 6350 §4.5: "The maximum value is 9223372036854775807, and the minimum
 *   value is -9223372036854775808."
 *
 * Thirty-two bits against sixty-four, for one and the same grammar. **So the
 * range is not part of reading the value.** One class cannot hold both rules,
 * and which of them applies depends on which file is in front of it — that is
 * a question about the format, and it belongs with the validation of P4-06.
 *
 * What is refused here is what will not fit in a PHP integer at all, which is
 * RFC 6350's range exactly.
 */
final class Integer
{
    private const SEPARATOR = ',';

    /**
     * The number a raw value carries.
     *
     * @throws ParseError If it is no integer, or too large to hold
     */
    public static function decode(string $raw): int
    {
        if (preg_match('/^[+-]?[0-9]+$/', $raw) !== 1) {
            throw new ParseError(sprintf('"%s" is no integer.', $raw));
        }

        $value = (int) $raw;

        // A cast saturates rather than failing, so the only way to know the
        // number survived is to write it back and compare it with what was
        // written — once the parts the grammar makes optional are gone.
        if (self::encode($value) !== self::withoutWhatIsOptional($raw)) {
            throw new ParseError(sprintf('"%s" is outside the range an integer can hold.', $raw));
        }

        return $value;
    }

    /**
     * The raw value for a number, in the plain form without the optional
     * plus: it is the one of the three spellings every reader takes.
     */
    public static function encode(int $value): string
    {
        return (string) $value;
    }

    /**
     * The numbers a raw list carries.
     *
     * §3.3.8: "If the property permits, multiple 'integer' values are
     * specified by a COMMA-separated list of values."
     *
     * @throws ParseError If any of them is no integer. A list read only as
     *                    far as the first mistake is a list of the wrong
     *                    length, and whoever receives it cannot tell
     *
     * @return list<int>
     */
    public static function decodeList(string $raw): array
    {
        $values = [];

        foreach (explode(self::SEPARATOR, $raw) as $piece) {
            $values[] = self::decode($piece);
        }

        return $values;
    }

    /**
     * The raw value for a list of numbers.
     *
     * @param list<int> $values
     */
    public static function encodeList(array $values): string
    {
        $written = [];

        foreach ($values as $value) {
            $written[] = self::encode($value);
        }

        return implode(self::SEPARATOR, $written);
    }

    /**
     * The same number without the leading plus and the leading zeros that
     * `1*DIGIT` allows and that say nothing.
     */
    private static function withoutWhatIsOptional(string $raw): string
    {
        $sign = str_starts_with($raw, '-') ? '-' : '';
        $digits = ltrim(ltrim($raw, '+-'), '0');

        return $digits === '' ? '0' : $sign . $digits;
    }
}
