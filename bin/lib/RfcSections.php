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

/**
 * The sections a memo holds, read out of the memo.
 *
 * R-CODE-12 wants a conformance matrix that names, per section of the memos
 * this library implements, the state and the test. **A matrix is only worth
 * having if it is complete**, and a list of sections kept by hand beside one
 * is a second thing to forget: a section added to the matrix's own idea of
 * the memo is not a section of the memo. So the gate behind the matrix asks
 * the text.
 *
 * **A heading is a line that starts in the first column.** RFC text indents
 * prose by three spaces and headings by none, and that is what tells a
 * heading from the table of contents, where the same numbers and titles
 * appear indented with a run of dots and a page number after them.
 *
 * **The numbers alone are read.** RFC 6638's Appendix B.6 wraps its title
 * across two lines, so a gate that compared titles would be a gate about
 * typesetting rather than about completeness.
 *
 * @license Apache-2.0
 */
final class RfcSections
{
    /**
     * How a heading is printed: the number, a full stop, and at least two
     * spaces before the title.
     *
     * The two spaces are RFC typesetting, and asking for them is what keeps
     * an ordinary sentence beginning with a number out — prose is indented
     * as well, so this is the second of two guards rather than the only one.
     *
     * Three shapes of number appear: `6.2.4` for a numbered section,
     * `Appendix A` for an appendix, and `A.2` for a section inside one.
     */
    private const HEADING = '/^(?:Appendix (?<appendix>[A-Z])|(?<number>\d+(?:\.\d+)*|[A-Z](?:\.\d+)+))\.  +\S/';

    /**
     * Every section of that memo, in the order it prints them.
     *
     * An appendix comes back as its letter alone — `A` for "Appendix A" — so
     * that it reads the way the memo cites it and the way a matrix row names
     * it.
     *
     * @param string $text The memo as published, line breaks and page
     *                     furniture and all
     *
     * @return list<string>
     */
    public static function of(string $text): array
    {
        $sections = [];

        // **Normalised first, then split on one character**, so that
        // there is no failure to answer for: a split that can fail is a
        // branch no case could ever reach.
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $line) {
            if (preg_match(self::HEADING, $line, $found) !== 1) {
                continue;
            }

            $number = $found['appendix'] === '' ? $found['number'] : $found['appendix'];

            // A memo prints each heading once. Saying so here rather than
            // trusting it keeps a second copy — a stray line in an example,
            // say — from asking the matrix for two rows that are one section.
            if (!in_array($number, $sections, true)) {
                $sections[] = $number;
            }
        }

        return $sections;
    }
}
