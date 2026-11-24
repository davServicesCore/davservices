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
 * Fails if the conformance matrix has stopped describing the memos it covers.
 *
 * R-CODE-12 wants `docs/rfc-compliance.md` to name, per section, the state and the test. A matrix with gaps is worse than none: it reads as a statement about every section and is one about some of them. So this asks the memo — the only authority on which sections there are — and both directions of the comparison.
 *
 * A section with no row means the matrix has stopped being complete. A row naming a section the memo does not have means a typo nobody would ever notice, since nothing else in the repository reads these numbers.
 *
 * COVERED is the record of which memos the matrix speaks for, and adding one to it is what makes this gate red until its rows are written. That is deliberate: the list is a promise, and a promise that goes green on its own would be none.
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

require __DIR__ . '/lib/RfcSections.php';
require __DIR__ . '/lib/Scanner.php';

/**
 * The memos the matrix speaks for, keyed by RFC number.
 *
 * Six more are still to come — RFC 4918, 3744, 4791, 6352, 6578 and 5545 —
 * and each is a chunk of its own, because a row is a claim and a claim has to
 * be checked against the code before it is written down.
 *
 * @var array<string, string>
 */
const COVERED = [
    '6638' => 'CalDAV Scheduling',
];

const MATRIX = __DIR__ . '/../docs/rfc-compliance.md';

/**
 * The part of the matrix that speaks for one memo: everything under its
 * `## RFC <number>` heading, down to the next heading of that level.
 */
function blockFor(string $matrix, string $rfc): ?string
{
    $start = preg_quote('## RFC ' . $rfc, '/');

    if (preg_match('/\n' . $start . '\b(.*?)(?=\n## |\z)/s', $matrix, $found) !== 1) {
        return null;
    }

    return $found[1] ?? '';
}

/**
 * The sections a block claims something about: the first cell of every row of
 * its tables.
 *
 * The header row and the dashes under it are rows too, and neither names a
 * section — they are left out by asking for a cell that looks like a section
 * number, which is the same shape {@see RfcSections} answers.
 *
 * @return list<string>
 */
function rowsIn(string $block): array
{
    preg_match_all('/^\|\s*(\d+(?:\.\d+)*|[A-Z](?:\.\d+)*)\s*\|/m', $block, $found);

    return $found[1];
}

$matrix = file_get_contents(MATRIX);

if (!is_string($matrix)) {
    Scanner::report('the RFC conformance matrix', [sprintf('%s cannot be read.', Scanner::rel(MATRIX))]);

    exit(1);
}

$violations = [];

foreach (COVERED as $number => $title) {
    // PHP turns a key that reads as a number into an integer, however
    // it was written. The RFC number is text here — it is printed and
    // matched, never counted with.
    $rfc = (string) $number;
    $memo = __DIR__ . '/../docs/referenzen/rfc/rfc' . $rfc . '.txt';
    $text = file_get_contents($memo);

    if (!is_string($text)) {
        $violations[] = sprintf('RFC %s (%s): no copy of the memo at %s', $rfc, $title, Scanner::rel($memo));

        continue;
    }

    $block = blockFor($matrix, $rfc);

    if ($block === null) {
        $violations[] = sprintf('RFC %s (%s): the matrix has no "## RFC %s" section', $rfc, $title, $rfc);

        continue;
    }

    $sections = RfcSections::of($text);
    $rows = rowsIn($block);

    foreach (array_diff($sections, $rows) as $missing) {
        $violations[] = sprintf('RFC %s §%s has no row in the matrix', $rfc, $missing);
    }

    foreach (array_diff($rows, $sections) as $invented) {
        $violations[] = sprintf('RFC %s has no §%s, and the matrix has a row for it', $rfc, $invented);
    }

    foreach (array_count_values($rows) as $claimed => $times) {
        if ($times > 1) {
            $violations[] = sprintf('RFC %s §%s has %d rows in the matrix', $rfc, $claimed, $times);
        }
    }
}

Scanner::report('the RFC conformance matrix', $violations);
