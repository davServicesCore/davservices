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
 * Runs shards of the suite with path coverage, each in a process of its own.
 *
 * Every shard writes build/coverage/shard-<n>.cov for bin/merge-coverage.php
 * to put together. Why the suite is split at all is explained in
 * bin/lib/TestShards.php.
 *
 * `--order-by=default` because this is the run that **measures**, and a
 * measurement has to be reproducible. The suite runs in random order
 * everywhere else, which is where an order dependence between tests actually
 * shows itself. Here it only shows itself in Xdebug: a generator's branch map
 * is attached the first time that function runs in the process, and for a
 * class driven by another class's tests the map can land on a test that does
 * not cover it and be thrown away. Naming the class in a second `CoversClass`
 * cures each case (#72), and a fixed order cures the kind.
 *
 * Xdebug's coverage collector crashes on this suite under branch coverage —
 * Xdebug bug 2252, open since March 2024, with no fixed version. So a crash
 * is answered rather than reported, and only a crash, by its signature: three
 * numbers rather than one, see TestShards::crashed().
 *
 * **It is answered in two ways, because the collector falls over in two
 * places.**
 *
 * *After* writing the report: on 23.10.2026 shard 4 printed `OK (329 tests,
 * 695 assertions)`, wrote its report, and then aborted on a double free. The
 * work was done, so the report is taken — see TestShards::reportSurvived().
 *
 * *Part way through*, with nothing written: then the shard is run again, up to
 * TestShards::ATTEMPTS times. One retry is not enough, and that is measured
 * rather than assumed — over six attempts at shard 4 the collector crashed
 * three times and survived three, twice in a row each way, which leaves a
 * single retry red on about a quarter of pushes.
 *
 * A shard whose **tests** fail exits 1 or 2, is no crash, and is not run
 * again: a real failure repeats.
 *
 * Usage:
 *   php bin/coverage-shard.php 3      runs shard 3 (CI runs the shards side by side)
 *   php bin/coverage-shard.php all    runs every shard, one after the other
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

require __DIR__ . '/lib/TestShards.php';

$sizes = TestShards::testFiles(__DIR__ . '/../tests/unit');
$count = TestShards::countFor($sizes);

$wanted = $argv[1] ?? '';
$number = (int) $wanted;

if ($wanted !== 'all' && ($number < 1 || $number > $count)) {
    fwrite(STDERR, "Usage: php bin/coverage-shard.php <1..{$count}> | all\n");
    exit(1);
}

if (!is_dir('build/coverage')) {
    mkdir('build/coverage', 0o777, true);
}

// A report left over from an earlier split would be merged as a shard of its
// own, or make up for one that is missing.
$stale = glob('build/coverage/*.cov');

if ($wanted === 'all' && $stale !== false) {
    array_map('unlink', $stale);
}

foreach (TestShards::split($sizes, $count) as $index => $shard) {
    $number = $index + 1;

    if ($wanted !== 'all' && $wanted !== (string) $number) {
        continue;
    }

    $files = array_map(static fn (string $file): string => 'tests/unit/' . $file, $shard);

    $command = implode(' ', array_map('escapeshellarg', [
        PHP_BINARY,
        'vendor/phpunit/phpunit/phpunit',
        '--configuration=tests/.config/phpunit.xml.dist',
        '--order-by=default',
        "--coverage-php=build/coverage/shard-{$number}.cov",
        ...$files,
    ]));

    fwrite(STDOUT, "== Shard {$number} of {$count}: " . count($files) . " test files\n");

    $report = "build/coverage/shard-{$number}.cov";
    $status = 0;

    for ($attempt = 1; $attempt <= TestShards::ATTEMPTS; $attempt++) {
        $startedAt = time();

        passthru($command, $status);

        if (!TestShards::crashed($status)) {
            break;
        }

        // **A crash on the way out is not a measurement lost.** The collector
        // falls over after writing the report as well as before it, so the
        // report is asked rather than assumed: where this attempt wrote one,
        // the shard did what it was asked and there is nothing to repeat.
        if (TestShards::reportSurvived($report, $startedAt)) {
            fwrite(STDOUT, "::warning::Xdebug's coverage collector crashed on the way out of shard "
                . "{$number} (exit {$status}), after writing its report. Taking the report.\n");
            $status = 0;

            break;
        }

        fwrite(STDOUT, sprintf(
            "::warning::Xdebug's coverage collector crashed in shard %d (exit %d) with no report "
            . "written, on attempt %d of %d.\n",
            $number,
            $status,
            $attempt,
            TestShards::ATTEMPTS,
        ));
    }

    if ($status !== 0) {
        fwrite(STDERR, "::error::Shard {$number} failed with exit code {$status}.\n");
        exit($status);
    }
}
