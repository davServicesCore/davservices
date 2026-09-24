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
 * The `BOOLEAN` value type.
 *
 *     boolean = "TRUE" / "FALSE"
 *
 * "These values are case-insensitive text" (RFC 5545 §3.3.2), and RFC 6350
 * §4.4 puts all three spellings side by side to make the point: `TRUE`,
 * `false`, `True`.
 *
 * "No additional content value encoding … is defined for this value type", so
 * nothing here undoes a backslash.
 *
 * **Two words, and nothing else.** A reader that took `yes`, `1` or an empty
 * value for one of them would be deciding what somebody meant, and getting it
 * wrong turns an alarm off.
 */
final class Boolean
{
    private const TRUE = 'TRUE';

    private const FALSE = 'FALSE';

    /**
     * What a raw value says.
     *
     * @throws ParseError If it says neither of the two words
     */
    public static function decode(string $raw): bool
    {
        if (strcasecmp($raw, self::TRUE) === 0) {
            return true;
        }

        if (strcasecmp($raw, self::FALSE) === 0) {
            return false;
        }

        throw new ParseError(sprintf('"%s" is neither TRUE nor FALSE.', $raw));
    }

    /**
     * The raw value, in the upper case the grammar shows — which is also what
     * RFC 6350 §3.3 recommends on output.
     */
    public static function encode(bool $value): string
    {
        return $value ? self::TRUE : self::FALSE;
    }
}
