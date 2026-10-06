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
 * The merged report can count a few more branches than a single process
 * would. PHP compiles a function slightly differently depending on which
 * classes are already loaded — WeekdayNumber::decode() came out with one
 * opcode more in some shards on 05.10.2026, which shifts its branch ids by
 * one — and the merge keeps both versions. Each has to be covered on its own,
 * so this can only make the floor stricter, never looser. The split and the
 * order are fixed, so it does not come and go between runs either.
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

$merged = null;

foreach ($reports as $report) {
    $coverage = include $report;

    if (!$coverage instanceof CodeCoverage) {
        fwrite(STDERR, "  FAIL coverage — not a coverage report: {$report}\n");
        exit(1);
    }

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
