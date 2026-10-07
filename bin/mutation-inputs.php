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
 * Builds the coverage Infection needs, out of the shards the coverage run left.
 *
 * **Why Infection is not allowed to measure the suite itself.** It decides
 * whether to run a mutant at all by the *nominal* time of the tests covering
 * its line:
 *
 *     if ($mutant->getMutation()->getNominalTestExecutionTime() < $this->timeout)
 *
 * and that time is a sum of **per test class** durations
 * (`JUnitTestCaseTimeAdder`: "Timings are per test suite, not per test") taken
 * from the run that produced the coverage. Left to itself, Infection produces
 * that coverage with Xdebug switched on, where a test of a millisecond is
 * measured at two hundred — so the sum passes the timeout and the mutant is
 * **skipped in silence**. On 07.10.2026 that was 269 of 604 mutants, reported
 * as an MSI of 96 % over the 312 that ran. Fed the same coverage with timings
 * from an ordinary run, the same suite skipped none and killed 584.
 *
 * So the two things Infection wants are produced apart:
 *
 * - **which tests cover which line** comes from the sharded coverage run,
 *   written here as the XML report Infection reads;
 * - **how long a test takes** comes from a plain run, with a `--log-junit` of
 *   its own and no coverage anywhere near it.
 *
 * Usage:
 *   php bin/mutation-inputs.php          reads build/coverage/*.cov
 *
 * It expects `build/mutation/junit.xml` to be there already — `composer
 * mutation` writes it before calling this — and refuses rather than let
 * Infection fall back to a measurement nobody asked for.
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/TestShards.php';

use PHPUnit\Runner\Version;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Report\Xml\Facade;

$shards = glob('build/coverage/*.cov');

if ($shards === false || $shards === []) {
    fwrite(STDERR, "::error::No coverage shards in build/coverage; run bin/coverage-shard.php first.\n");
    exit(1);
}

// A missing shard would leave Infection believing that nothing covers the
// lines it held, and every mutant there would be "not covered" rather than
// killed — the same silence this script exists to end.
$wanted = TestShards::countFor(TestShards::testFiles(__DIR__ . '/../tests/unit'));

if (count($shards) !== $wanted) {
    fwrite(STDERR, sprintf(
        "::error::Expected %d shard reports in build/coverage, found %d.\n",
        $wanted,
        count($shards),
    ));
    exit(1);
}

if (!is_file('build/mutation/junit.xml')) {
    fwrite(STDERR, "::error::build/mutation/junit.xml is missing; it comes from a run without coverage.\n");
    exit(1);
}

$merged = null;

foreach ($shards as $shard) {
    $one = require $shard;

    if (!$one instanceof CodeCoverage) {
        fwrite(STDERR, sprintf("::error::%s held no coverage.\n", $shard));
        exit(1);
    }

    if ($merged === null) {
        $merged = $one;

        continue;
    }

    $merged->merge($one);
}

// No guard for an unmerged report: the shard list was checked above, so the
// loop ran at least once, and the analyser says as much.

(new Facade(Version::id()))->process($merged, 'build/mutation/coverage-xml');

printf("  OK   mutation coverage — %d shards into build/mutation/coverage-xml\n", count($shards));
