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
 * Counts how often Xdebug's coverage collector falls over on a shard, or on one test file.
 *
 *     php bin/measure-crash-rate.php 4 20                 shard 4, twenty times
 *     php bin/measure-crash-rate.php CalDav/FooTest.php 20  one file, twenty times
 *
 * **Why the project needs this and did not have it.** Xdebug bug 2252 has been open since March 2024 and is answered here by retries (`TestShards::ATTEMPTS`) and, twice, by a cure: a generator that both yielded and delegated with `yield from` in one frame, written as a worklist instead. Both cures were earned the same way — narrow the crashing shard to one test file that falls over on its own, then **measure** twenty runs before and twenty after.
 *
 * `TestShards` says what happens without that: "A flaky crash is not bisectable, which cost a wrong answer first. One run of each of five commits read like a clean bisect and meant nothing." The first step is to measure the rate; this is the thing that measures it.
 *
 * **And it has to run where the crash does.** The collector's rate depends on the version — 3.4.7 crashes as 3.5.3 did, only less often — and on the platform: a shard that crashes in CI on Linux has gone twelve runs on Windows with Xdebug 3.4.0 without a murmur. So this is written to be run from a workflow as well as by hand.
 *
 * It writes no coverage report and gates nothing. Its answer is a count.
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

require __DIR__ . '/lib/TestShards.php';

$what = $argv[1] ?? '';
$runs = (int) ($argv[2] ?? 20);

if ($what === '' || $runs < 1) {
    fwrite(STDERR, "Usage: php bin/measure-crash-rate.php <shard number | path under tests/unit> [runs]\n");

    exit(1);
}

$sizes = TestShards::testFiles(__DIR__ . '/../tests/unit');
$count = TestShards::countFor($sizes);
$number = (int) $what;

if ((string) $number === $what) {
    // Asked for rather than range-checked first: the split holds exactly as
    // many shards as `countFor()` counted, so looking the number up is the
    // same question as whether it is one — and one guard cannot disagree with
    // itself the way two can.
    $shard = TestShards::split($sizes, $count)[$number - 1] ?? null;

    if ($shard === null) {
        fwrite(STDERR, sprintf("There are %d shards.\n", $count));

        exit(1);
    }

    $files = array_map(static fn (string $file): string => 'tests/unit/' . $file, $shard);
    $about = sprintf('shard %d of %d (%d test files)', $number, $count, count($files));
} else {
    $files = ['tests/unit/' . ltrim($what, '/')];
    $about = $files[0];

    if (!is_file(__DIR__ . '/../' . $files[0])) {
        fwrite(STDERR, sprintf("There is no %s.\n", $files[0]));

        exit(1);
    }
}

// The same command the shards are measured with, minus the report: what is
// counted here is whether the process survives, and a report written to be
// thrown away would only make each run slower.
$command = implode(' ', array_map('escapeshellarg', [
    PHP_BINARY,
    'vendor/phpunit/phpunit/phpunit',
    '--configuration=tests/.config/phpunit.xml.dist',
    '--order-by=default',
    '--coverage-php=' . sys_get_temp_dir() . '/crash-rate.cov',
    ...$files,
]));

fwrite(STDOUT, sprintf("== %s, %d runs\n", $about, $runs));

$crashes = 0;
$failures = 0;
$statuses = [];

for ($run = 1; $run <= $runs; $run++) {
    $status = 0;

    exec($command . ' 2>&1', $output, $status);

    if (TestShards::crashed($status)) {
        ++$crashes;
        $statuses[] = $status;

        // Where it fell over says which test was running, and a crash point
        // that moves is the signature `TestShards` describes: it rules out a
        // fixed test and points at the code they all drive.
        $dots = '';

        foreach ($output as $line) {
            if (preg_match('/\(\s*\d+%\)$/', $line) === 1) {
                $dots = trim($line);
            }
        }

        fwrite(STDOUT, sprintf("  run %2d: crashed (%d) %s\n", $run, $status, $dots));
    } elseif ($status !== 0) {
        ++$failures;

        fwrite(STDOUT, sprintf("  run %2d: tests failed (%d) — no crash\n", $run, $status));
    }

    $output = [];
}

fwrite(STDOUT, sprintf(
    "\n== %d of %d runs crashed (%.0f%%)%s\n",
    $crashes,
    $runs,
    $crashes / $runs * 100,
    $failures === 0 ? '' : sprintf(', and %d failed without crashing', $failures),
));

if ($statuses !== []) {
    fwrite(STDOUT, '   exit codes: ' . implode(', ', array_unique($statuses)) . "\n");
}

@unlink(sys_get_temp_dir() . '/crash-rate.cov');
