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
 * R-CODE-12 wants `docs/rfc-compliance.md` to name, per section, the state and the test. A matrix with gaps is worse than none: it reads as a statement about every section and is one about some of them. So this asks for the memo's own list of sections, and both directions of the comparison.
 *
 * **The list is carried, the memo is not.** `docs/referenzen/README.md` says why the texts are not committed — "sie mitzuliefern hieße, fremde Dokumente zu verteilen" — and `download-rfcs.sh` fetches them on demand. A gate that read the text would pass or fail by whether somebody had run that script, which is no gate at all. `bin/build-rfc-sections.php` writes the numbers to `docs/rfc-sections/`, once per memo, and this reads those.
 *
 * A section with no row means the matrix has stopped being complete. A row naming a section the memo does not have means a typo nobody would ever notice, since nothing else in the repository reads these numbers.
 *
 * The last column is held to the same standard, because naming the test is half of what R-CODE-12 asks for: a case that no longer exists is a claim with nothing behind it, and a row that says something is kept and names no case is a claim with nothing beside it. The column before it is prose about where the thing lives and is left to a reader — a gate over prose would be a gate about wording.
 *
 * COVERED is the record of which memos the matrix speaks for, and adding one to it is what makes this gate red until its rows are written. That is deliberate: the list is a promise, and a promise that goes green on its own would be none.
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

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
 * The sections of one memo, as `bin/build-rfc-sections.php` wrote them down.
 *
 * Comments and blank lines are skipped, so the file can say what it is.
 *
 * @return list<string>|null Null where the list has not been written yet
 */
function sectionsOf(string $rfc): ?array
{
    $list = __DIR__ . '/../docs/rfc-sections/rfc' . $rfc . '.txt';
    $text = file_get_contents($list);

    if (!is_string($text)) {
        return null;
    }

    $sections = [];

    foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $line) {
        $number = trim($line);

        if ($number !== '' && !str_starts_with($number, '#')) {
            $sections[] = $number;
        }
    }

    return $sections;
}

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

/**
 * What each row claims, as the section number, the state and the cases it
 * names.
 *
 * Five columns: the section, what it asks, the state, where it is kept, and
 * the cases. Only the first, the third and the last are read — the fourth is
 * prose.
 *
 * @return list<array{string, string, list<string>}>
 */
function claimsIn(string $block): array
{
    preg_match_all(
        '/^\|\s*(\d+(?:\.\d+)*|[A-Z](?:\.\d+)*)\s*\|[^|]*\|\s*(\S*)\s*\|[^|]*\|([^|]*)\|/m',
        $block,
        $found,
        PREG_SET_ORDER,
    );

    $claims = [];

    foreach ($found as $row) {
        preg_match_all('/`([A-Za-z][A-Za-z0-9]*)`/', $row[3], $named);

        $claims[] = [$row[1], $row[2], $named[1]];
    }

    return $claims;
}

/**
 * Every test class this repository holds, by its name.
 *
 * @return list<string>
 */
function theCases(): array
{
    $names = [];

    foreach (Scanner::phpFiles(__DIR__ . '/../tests') as $file) {
        $names[] = basename($file, '.php');
    }

    return $names;
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
    $sections = sectionsOf($rfc);

    if ($sections === null) {
        $violations[] = sprintf(
            'RFC %s (%s): no section list; run php bin/build-rfc-sections.php %s',
            $rfc,
            $title,
            $rfc,
        );

        continue;
    }

    $block = blockFor($matrix, $rfc);

    if ($block === null) {
        $violations[] = sprintf('RFC %s (%s): the matrix has no "## RFC %s" section', $rfc, $title, $rfc);

        continue;
    }

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

    foreach (claimsIn($block) as [$section, $state, $cases]) {
        // A row that says something is kept owes the case that tries it,
        // which is the half of R-CODE-12 that says "den zugehörigen Test
        // benennen". Nothing is owed where there is nothing to keep.
        if ($state === '✔' && $cases === []) {
            $violations[] = sprintf('RFC %s §%s is kept and names no case', $rfc, $section);
        }

        foreach (array_diff($cases, theCases()) as $gone) {
            $violations[] = sprintf('RFC %s §%s names "%s", and there is no such case', $rfc, $section, $gone);
        }
    }
}

Scanner::report('the RFC conformance matrix', $violations);
