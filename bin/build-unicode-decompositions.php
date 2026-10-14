<?php

/*
 * This file is part of davServices.
 *
 * (c) Felix Böck <https://dav.services>
 *
 * Licensed under the Apache License, Version 2.0.
 * For the full copyright and license information, see the LICENSE file.
 */

/**
 * Builds the decomposition table `i;unicode-casemap` needs out of
 * UnicodeData.txt.
 *
 *     php bin/build-unicode-decompositions.php /path/to/UnicodeData.txt
 *
 * **Why a generated table and not a library call.** RFC 5051 step (2)(b) asks
 * for the decomposition property of a codepoint, applied recursively — which
 * is NFKD without the canonical reordering. PHP's core has no normaliser, and
 * `ext-intl` is a `suggest` rather than a `require`: R-ENV-03 asks that the
 * library work without it, and RFC 4790 §4.2 asks that one collation
 * identifier mean one behaviour. Two code paths — a fast one where the
 * extension is installed and a slow one elsewhere — would be two answers under
 * one name, and a search result that depended on how PHP was compiled. So the
 * data is carried rather than borrowed.
 *
 * **What it does not carry.** Hangul syllables have no decomposition in
 * UnicodeData.txt at all: theirs is an algorithm, and
 * {@see DavServices\Dav\Collation\UnicodeCasemap} applies it. The table would
 * otherwise be eleven thousand entries longer for no gain.
 *
 * **What it writes is read as data, never as code.** The input is a file from
 * unicode.org and is treated as such: every field is parsed, nothing is
 * evaluated, and the output is a class holding one array of integers to
 * strings.
 *
 * Run it again when the table should follow a newer Unicode. The version is
 * written into the generated file, because a collation is only reproducible
 * if the data behind it is named.
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

$source = $argv[1] ?? '';
$version = $argv[2] ?? 'unknown';

if ($source === '' || !is_file($source)) {
    fwrite(STDERR, "Usage: php bin/build-unicode-decompositions.php <UnicodeData.txt> [version]\n");

    exit(1);
}

$lines = file($source, FILE_IGNORE_NEW_LINES);

if ($lines === false) {
    fwrite(STDERR, sprintf('"%s" could not be read.' . "\n", $source));

    exit(1);
}

/**
 * The decomposition of each codepoint that has one, as a list of codepoints
 * and one step deep.
 *
 * Field 5 of UnicodeData.txt. A compatibility decomposition is marked with a
 * tag in angle brackets — `<compat>`, `<font>`, `<circle>` and the rest — and
 * RFC 5051 wants "a decomposition property of **any** type", so the tag is
 * dropped and the mapping kept.
 *
 * @var array<int, list<int>>
 */
$oneStep = [];

foreach ($lines as $line) {
    $fields = explode(';', $line);

    if (count($fields) < 6 || $fields[5] === '') {
        continue;
    }

    $mapping = [];

    foreach (explode(' ', $fields[5]) as $piece) {
        if ($piece === '' || str_starts_with($piece, '<')) {
            continue;
        }

        $mapping[] = (int) hexdec($piece);
    }

    if ($mapping !== []) {
        $oneStep[(int) hexdec($fields[0])] = $mapping;
    }
}

/**
 * The same, applied until nothing decomposes any further.
 *
 * RFC 5051 step (2)(b): "This step is recursively applied to each of the
 * resulting codepoints until no more decomposition is possible". Doing it here
 * rather than at run time means the collation never walks a chain.
 *
 * @param array<int, list<int>> $oneStep
 *
 * @return list<int>
 */
function fully(int $codepoint, array $oneStep, int $depth = 0): array
{
    // Unicode's decomposition graph has no cycles, so this is a guard against
    // a damaged input file rather than against the data.
    if ($depth > 32 || !isset($oneStep[$codepoint])) {
        return [$codepoint];
    }

    $expanded = [];

    foreach ($oneStep[$codepoint] as $piece) {
        $expanded = [...$expanded, ...fully($piece, $oneStep, $depth + 1)];
    }

    return $expanded;
}

$table = [];

foreach (array_keys($oneStep) as $codepoint) {
    $expanded = fully($codepoint, $oneStep);
    $utf8 = '';

    foreach ($expanded as $piece) {
        // The input is a file from unicode.org and is read as data: a
        // codepoint outside Unicode's range means the file is damaged, and a
        // generator that carried on would write a table nobody could trust.
        if ($piece < 0 || $piece > 0x10FFFF) {
            fwrite(STDERR, sprintf("U+%04X decomposes to %d, which is no codepoint.\n", $codepoint, $piece));

            exit(1);
        }

        $utf8 .= mb_chr($piece, 'UTF-8');
    }

    // A codepoint that decomposes to itself is not a decomposition.
    if ($utf8 !== mb_chr($codepoint, 'UTF-8')) {
        $table[$codepoint] = $utf8;
    }
}

ksort($table);

$entries = '';

foreach ($table as $codepoint => $utf8) {
    $entries .= sprintf("        0x%04X => \"%s\",\n", $codepoint, implode('', array_map(
        static fn (string $byte): string => sprintf('\x%02x', ord($byte)),
        str_split($utf8),
    )));
}

$header = <<<'TXT'
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

    TXT;

$doc = <<<TXT

    /**
     * What every decomposable codepoint decomposes to, for `i;unicode-casemap`.
     *
     * **Generated, and not to be edited by hand.** `bin/build-unicode-decompositions.php`
     * writes it from UnicodeData.txt; run that again to follow a newer Unicode.
     *
     * Unicode %s, %d entries.
     *
     * Each value is already expanded as far as it goes, because RFC 5051 step
     * (2)(b) applies the decomposition "recursively […] until no more
     * decomposition is possible" — doing that here means the collation never walks
     * a chain at run time. Compatibility decompositions are included: the step
     * asks for "a decomposition property of **any** type".
     *
     * **Hangul syllables are not in here**, and not by omission: UnicodeData.txt
     * gives them no decomposition property, because theirs is an algorithm.
     * {@see UnicodeCasemap} applies it, which keeps eleven thousand entries out of
     * this file.
     *
     * A collation is only reproducible if the data behind it is named, which is
     * why the version is written above rather than left to a changelog.
     */
    final class Decompositions
    {
        /**
         * Keyed by codepoint, the UTF-8 of its full decomposition.
         *
         * @var array<int, string>
         */
        public const MAP = [
    %s    ];
    }

    TXT;

$out = dirname(__DIR__) . '/src/davservices/Dav/Collation/Decompositions.php';

file_put_contents($out, $header . sprintf($doc, $version, count($table), $entries));

$written = filesize($out);

printf(
    "  OK   decompositions — %d entries from Unicode %s, %d bytes\n",
    count($table),
    $version,
    $written === false ? 0 : $written,
);
