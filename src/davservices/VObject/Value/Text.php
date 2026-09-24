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

/**
 * The `TEXT` value type: human-readable text, and the only one with escaping.
 *
 *     ESCAPED-CHAR = ("\\" / "\;" / "\," / "\N" / "\n")
 *       ; \\ encodes \, \N or \n encodes newline
 *       ; \; encodes ;, \, encodes ,
 *
 * **Every other value type says of itself that it has none**: "No additional
 * content value encoding (i.e., BACKSLASH character encoding, see Section
 * 3.3.11) is defined for this value type" appears under `BINARY`, `BOOLEAN`,
 * `FLOAT`, `INTEGER` and `URI` alike. That is why
 * {@see \DavServices\VObject\Property::value()} hands its value over raw and
 * the unescaping waits until here, where the type is known — undoing it any
 * earlier would corrupt every value that is not text.
 *
 *     Text::decode('Lunch\, then the review\nat three');
 *     // "Lunch, then the review\nat three"
 *
 * **Four escapes and no more.** RFC 6350 §3.4 closes the list outright: "In
 * all other cases, escaping MUST NOT be used." A backslash before anything
 * else is therefore not an escape, and both characters stay — the same
 * decision RFC 6868 §3 makes for the `^` escapes in this family of formats,
 * where it is a MUST. RFC 5545 §3.3.11 names the one people get wrong: "a
 * COLON character in a 'TEXT' property value SHALL NOT be escaped with a
 * BACKSLASH character."
 *
 * **Lists are the property's business.** "If the property permits, multiple
 * TEXT values are specified by a COMMA-separated list of values" — *if the
 * property permits*, so both readings are offered and the caller knows which
 * it wants. An escaped comma never separates, which is the whole reason it is
 * escaped.
 */
final class Text
{
    private const ESCAPE = '\\';

    private const SEPARATOR = ',';

    /**
     * What a backslash and the character after it stand for, keyed by that
     * character. Anything not here is no escape at all.
     */
    private const ESCAPES = [
        '\\' => '\\',
        ';' => ';',
        ',' => ',',
        'n' => "\n",
        'N' => "\n",
    ];

    /**
     * The text a raw value carries, with the escaping undone.
     */
    public static function decode(string $raw): string
    {
        $text = '';

        for ($at = 0; $at < strlen($raw); ++$at) {
            $escaped = $raw[$at] === self::ESCAPE ? (self::ESCAPES[$raw[$at + 1] ?? ''] ?? null) : null;

            if ($escaped === null) {
                $text .= $raw[$at];

                continue;
            }

            $text .= $escaped;
            ++$at;
        }

        return $text;
    }

    /**
     * The raw value for a piece of text.
     *
     * Every line break becomes `\n`, whichever way it was written: RFC 5545
     * §3.3.11 allows a break in a text value to be represented one way only,
     * "with the character sequence of BACKSLASH, followed by a LATIN SMALL
     * LETTER N or a LATIN CAPITAL LETTER N".
     *
     * A colon is left alone, because §3.3.11 says it "SHALL NOT be escaped".
     */
    public static function encode(string $value): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\n", "\r"],
            ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'],
            $value,
        );
    }

    /**
     * The values a raw list carries.
     *
     * An empty raw value is one empty value rather than none: `CATEGORIES:`
     * says the list holds an empty string, and a reader answering with
     * nothing would lose the difference between that and the property being
     * absent altogether.
     *
     * @return list<string>
     */
    public static function decodeList(string $raw): array
    {
        $values = [];
        $piece = '';

        for ($at = 0; $at < strlen($raw); ++$at) {
            // An escape is stepped over whole, which is what keeps `\,` from
            // separating — and `\\,` from failing to.
            if ($raw[$at] === self::ESCAPE && $at + 1 < strlen($raw)) {
                $piece .= $raw[$at] . $raw[$at + 1];
                ++$at;

                continue;
            }

            if ($raw[$at] === self::SEPARATOR) {
                $values[] = self::decode($piece);
                $piece = '';

                continue;
            }

            $piece .= $raw[$at];
        }

        $values[] = self::decode($piece);

        return $values;
    }

    /**
     * The raw value for a list of texts.
     *
     * @param list<string> $values
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
