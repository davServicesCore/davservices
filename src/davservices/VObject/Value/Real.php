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
 * The `FLOAT` value type: a real number.
 *
 *     float = (["+"] / "-") 1*DIGIT ["." 1*DIGIT]
 *
 * **The class is called `Real` because PHP will not have it called `Float`**:
 * `float` is a reserved class name and names are case-insensitive. RFC 5545
 * §3.3.7's own purpose sentence gives the word — "properties that contain a
 * real-number value".
 *
 * ## No exponent, on the way in or out
 *
 * The grammar has none, and RFC 6350 §4.6 spells out why that is worth
 * saying: "Note: Scientific notation is disallowed. Implementers wishing to
 * use their favorite language's %f formatting should be careful."
 *
 * PHP walks into exactly that: the ordinary string form of a large number is
 * `1.0E+20`, which is what the grammar forbids. So the shortest decimal that
 * reads back as the same number is used where it has no exponent, and the
 * number is written out in full where it has.
 *
 * §4.6 also sets the floor for precision — "a precision equal or better than
 * that of the IEEE 'binary64' format" — which is what a PHP float is.
 */
final class Real
{
    private const SEPARATOR = ',';

    /**
     * The number a raw value carries.
     *
     * @throws ParseError If it is no float by the grammar
     */
    public static function decode(string $raw): float
    {
        if (preg_match('/^[+-]?[0-9]+(\.[0-9]+)?$/', $raw) !== 1) {
            throw new ParseError(sprintf('"%s" is no float.', $raw));
        }

        return (float) $raw;
    }

    /**
     * The raw value for a number, never in scientific notation.
     */
    public static function encode(float $value): string
    {
        // `var_export` writes the shortest decimal that reads back as the
        // same number, which is what keeps the round trip exact. It reaches
        // for scientific notation on very large and very small numbers, and
        // the grammar has no room for that.
        $written = var_export($value, true);

        if (stripos($written, 'E') !== false) {
            $written = sprintf('%.15F', $value);
        }

        // Both forms always carry a point, so trimming cannot eat a digit —
        // and the fraction is optional, so a point with nothing left after it
        // goes too.
        return rtrim(rtrim($written, '0'), '.');
    }

    /**
     * The numbers a raw list carries.
     *
     * §3.3.7: "If the property permits, multiple 'float' values are specified
     * by a COMMA-separated list of values." `GEO` is two of them.
     *
     * @throws ParseError If any of them is no float
     *
     * @return list<float>
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
     * @param list<float> $values
     */
    public static function encodeList(array $values): string
    {
        $written = [];

        foreach ($values as $value) {
            $written[] = self::encode($value);
        }

        return implode(self::SEPARATOR, $written);
    }
}
