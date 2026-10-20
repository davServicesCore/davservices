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
 * Merges the coverage of every shard into one Clover report.
 *
 * Each shard writes what it measured with --coverage-php. php-code-coverage,
 * which PHPUnit brings along anyway, can merge those itself, so no further
 * package is needed. The number of reports is checked against the number of
 * shards: a shard whose report went missing would otherwise drop out without
 * a word, and the floor would be measured on part of the suite.
 *
 * **The branch ids are opcodes, and a shard that only loaded a class records
 * a numbering of its own.** PHP compiles a function differently depending on
 * what is already in memory, so merging every report would union numberings
 * rather than branches, and the ids of a process that ran no test of that file
 * would arrive with nothing able to hit them. {@see ShardedCoverage} holds the
 * rule that settles it, and the measurement it came from.
 *
 * Usage: php bin/merge-coverage.php build/coverage build/logs/clover.xml [build/coverage-html]
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Report\Clover;
use SebastianBergmann\CodeCoverage\Report\Html\Facade as Html;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/ShardedCoverage.php';
require __DIR__ . '/lib/TestShards.php';

$directory = $argv[1] ?? '';
$clover = $argv[2] ?? '';
$html = $argv[3] ?? '';

if ($directory === '' || $clover === '') {
    fwrite(STDERR, "Usage: php bin/merge-coverage.php <shard dir> <clover.xml> [html dir]\n");
    exit(1);
}

$expected = TestShards::countFor(TestShards::testFiles(__DIR__ . '/../tests/unit'));
$reports = glob($directory . '/*.cov');

if ($reports === false) {
    $reports = [];
}

if (count($reports) !== $expected) {
    fwrite(STDERR, sprintf("  FAIL coverage — expected %d shard reports in %s, found %d\n", $expected, $directory, count($reports)));
    exit(1);
}

$shards = [];

foreach ($reports as $report) {
    $coverage = include $report;

    if (!$coverage instanceof CodeCoverage) {
        fwrite(STDERR, "  FAIL coverage — not a coverage report: {$report}\n");
        exit(1);
    }

    $shards[] = $coverage;
}

// **A shard that measured nothing in a file says nothing about its branches.**
// The ids are opcodes, and a process that merely loaded a class recorded a
// numbering of its own that no test of its own could hit. See ShardedCoverage
// for the measurement this comes from.
//
// The data is taken **raw**. CodeCoverage::getData() without that flag
// re-analyses the source filter and adds uncovered files as this process
// sees them, which is not what the shards measured: on 14.10.2026 that
// alone put Prepared::prepare() — an abstract method, with no body to run —
// into the report as an uncovered statement. Each shard already applied
// includeUncoveredFiles at its own end, so there is nothing to add here.
$layouts = ShardedCoverage::layoutsWorthKeeping(array_map(
    /**
     * @return array{
     *     lines: array<string, array<int, list<string>|null>>,
     *     functions: array<string, array<string, mixed>>
     * }
     */
    static fn (CodeCoverage $coverage): array => [
        'lines' => $coverage->getData(true)->lineCoverage(),
        'functions' => $coverage->getData(true)->functionCoverage(),
    ],
    $shards,
));

$merged = null;

foreach ($shards as $at => $coverage) {
    $layout = $layouts[$at] ?? null;

    // One layout per shard comes back, in the order they went in. Saying so
    // out loud rather than reaching for an empty array: a shard whose
    // functions had quietly become none would report every branch of every
    // class it measured as missing, and the floor would be a mystery.
    if ($layout === null) {
        fwrite(STDERR, sprintf("  FAIL coverage — nothing came back for shard %d\n", $at + 1));
        exit(1);
    }

    // **And one record per function.** A method reached both through an
    // event and as a method call is recorded twice, the second time without
    // its class name — the same compiled function, with the hits split
    // between the two. See ShardedCoverage for the measurement.
    // **And a function that ran was entered at the top**, which a report can
    // deny: measured on 20.10.2026, `ObjectSet::instances()` came back with
    // its entry branch unhit and the body of the `if` that branch evaluates
    // hit, in the one shard that ran its tests. See ShardedCoverage.
    foreach ($layout as $file => $functions) {
        $layout[$file] = is_array($functions)
            ? ShardedCoverage::withTheEntryOfEveryFunctionThatRan(
                ShardedCoverage::oneRecordPerFunction($functions),
            )
            : $functions;
    }

    $coverage->getData(true)->setFunctionCoverage($layout);

    if ($merged === null) {
        $merged = $coverage;

        continue;
    }

    $merged->merge($coverage);
}

if ($merged === null) {
    fwrite(STDERR, "  FAIL coverage — no shard reports to merge\n");
    exit(1);
}

(new Clover())->process($merged, $clover);

if ($html !== '') {
    (new Html())->process($merged, $html);
}

fwrite(STDOUT, sprintf("  merged %d shard reports into %s\n", count($reports), $clover));
