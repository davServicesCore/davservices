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

namespace DavServices\Dav\Collation;

/**
 * `i;unicode-casemap`: titlecased, canonicalised, then compared as octets
 * (RFC 5051).
 *
 * **The collation CardDAV means when a client says nothing.** RFC 6352 §8.3:
 * "In the absence of a collation explicitly specified by the client, or if the
 * client specifies the 'default' collation identifier […] the server MUST
 * default to using 'i;unicode-casemap' as the collation." So this is what a
 * search means in practice, and RFC 5051 §1 says why it rather than
 * `i;ascii-casemap`: that one "does not produce satisfactory results with
 * non-ASCII characters."
 *
 * ## The four steps, in the memo's order
 *
 * Each input string becomes a "titlecased canonicalized UTF-8" string:
 *
 * 1. a codepoint is read from the input;
 * 2. (a) if it has a **titlecase** property it becomes that, then
 *    (b) if the result has a **decomposition property of any type** it becomes
 *    that, recursively, "effectively Normalization Form KD";
 * 3. the result is appended in UTF-8;
 * 4. repeat until the input is spent.
 *
 * "Following the above preparation process on each string, the equality,
 * ordering, and substring operations are as for i;octet" — which is why this
 * only supplies {@see prepare()} and {@see Prepared} does the comparing.
 *
 * ## Titlecase comes from PHP, decomposition from a table
 *
 * **The titlecase property is `mb_convert_case()` on one character.** That was
 * measured rather than assumed, against the four digraphs where titlecase and
 * uppercase differ: U+01C4 gives U+01C5, U+01C7 gives U+01C8, U+01CA gives
 * U+01CB, U+01F1 gives U+01F2 — and `ß` is left alone, exactly as
 * UnicodeData.txt leaves it. Reaching for uppercase instead would be wrong in
 * those four places and nowhere else in Latin, which is the kind of wrong that
 * ships.
 *
 * **The decomposition comes from {@see Decompositions}**, generated from
 * UnicodeData.txt, because PHP's core has no normaliser. `ext-intl` has one,
 * and it is deliberately not used — two code paths, intl where it is installed
 * and a table elsewhere, would be two answers under one identifier, which
 * RFC 4790 §4.2 forbids:
 * "given a collation with a specific identifier, and any two fixed input
 * strings, the result MUST be the same for the same operation." A search
 * result that depended on how PHP was compiled is not a search result anybody
 * can reproduce.
 *
 * **Hangul is arithmetic, not a table.** Unicode gives its eleven thousand
 * syllables no decomposition property; theirs is the algorithm in
 * {@see hangul()}. A collation that only read the table would leave Korean
 * uncollated and never say so.
 *
 * ## Invalid input is compared as bytes
 *
 * Step (1)(b): "If the input string is in an unknown charset, or an invalid
 * sequence occurs in step (1)(a), conversion ceases. No further preparation is
 * performed, and any partial preparation results are discarded. The original
 * string is used unchanged with the i;octet comparator."
 *
 * So a broken sequence is neither refused nor repaired. A vCard that arrived
 * with one stays searchable and still matches itself — and "overlong UTF-8
 * sequences are invalid" is the memo's own example of what counts as broken.
 */
final class UnicodeCasemap extends Prepared
{
    public const IDENTIFIER = 'i;unicode-casemap';

    /**
     * The Hangul syllable block and the jamo it is built from, as the Unicode
     * standard names them.
     */
    private const SYLLABLE_BASE = 0xAC00;

    private const SYLLABLE_COUNT = 11172;

    private const LEAD_BASE = 0x1100;

    private const VOWEL_BASE = 0x1161;

    private const TAIL_BASE = 0x11A7;

    private const VOWEL_COUNT = 21;

    private const TAIL_COUNT = 28;

    /**
     * The identifier a client names this collation by.
     */
    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    /**
     * The "titlecased canonicalized UTF-8" of a string, or the string itself
     * where it is not valid UTF-8.
     */
    protected function prepare(string $subject): string
    {
        // Step (1)(b), done once rather than per codepoint: a single invalid
        // sequence discards the whole preparation, so there is nothing to gain
        // by finding out where it is.
        if (!mb_check_encoding($subject, 'UTF-8')) {
            return $subject;
        }

        $prepared = '';

        foreach (mb_str_split($subject, 1, 'UTF-8') as $character) {
            // Step (2)(a). On one character, mbstring's titlecasing is the
            // codepoint's own titlecase property.
            $titled = mb_convert_case($character, MB_CASE_TITLE_SIMPLE, 'UTF-8');

            foreach (mb_str_split($titled, 1, 'UTF-8') as $piece) {
                $prepared .= self::decomposed($piece);
            }
        }

        return $prepared;
    }

    /**
     * Step (2)(b) for one character: its full decomposition, or itself.
     */
    private static function decomposed(string $character): string
    {
        // No guard against a failure here: the character came out of
        // `mb_str_split()` on a string `mb_check_encoding()` had already
        // passed, so it is one valid character and nothing else. A check would
        // be a line no test could reach, and static analysis says the same.
        $codepoint = mb_ord($character, 'UTF-8');

        $hangul = self::hangul($codepoint);

        if ($hangul !== null) {
            return $hangul;
        }

        return Decompositions::MAP[$codepoint] ?? $character;
    }

    /**
     * The jamo a Hangul syllable is made of, or null where this is not one.
     *
     * Unicode defines this decomposition arithmetically rather than listing
     * it, which is why it is here and not in {@see Decompositions}: the table
     * would otherwise carry eleven thousand entries that a dozen lines can
     * work out.
     */
    private static function hangul(int $codepoint): ?string
    {
        $index = $codepoint - self::SYLLABLE_BASE;

        if ($index < 0 || $index >= self::SYLLABLE_COUNT) {
            return null;
        }

        $tail = $index % self::TAIL_COUNT;
        $vowelAndTail = intdiv($index, self::TAIL_COUNT);

        $jamo = mb_chr(self::LEAD_BASE + intdiv($vowelAndTail, self::VOWEL_COUNT), 'UTF-8')
            . mb_chr(self::VOWEL_BASE + $vowelAndTail % self::VOWEL_COUNT, 'UTF-8');

        // A syllable without a final consonant is two jamo rather than three,
        // and `TAIL_BASE` is the index that means "none".
        return $tail === 0 ? $jamo : $jamo . mb_chr(self::TAIL_BASE + $tail, 'UTF-8');
    }
}
