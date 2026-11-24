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
 *     php bin/measure-crash-rate.php 4:1-6 20               the first six files of shard 4
 *     php bin/measure-crash-rate.php A.php,B.php 20         those two files together
 *
 * **Why the project needs this and did not have it.** Xdebug bug 2252 has been open since March 2024 and is answered here by retries (`TestShards::ATTEMPTS`) and, twice, by a cure: a generator that both yielded and delegated with `yield from` in one frame, written as a worklist instead. Both cures were earned the same way — narrow the crashing shard to one test file that falls over on its own, then **measure** twenty runs before and twenty after.
 *
 * `TestShards` says what happens without that: "A flaky crash is not bisectable, which cost a wrong answer first. One run of each of five commits read like a clean bisect and meant nothing." The first step is to measure the rate; this is the thing that measures it.
 *
 * **And narrowing means halving, so a subset has to be measurable too.** On 24.11.2026 shard 4 crashed 18 of 20 runs on Xdebug 3.4.7 and 13 of 20 on 3.4.0 — and neither of its two heaviest files crashed once in twenty runs on its own. A crash that needs the *combination* has no single guilty file to find, and the only way to say which part of a shard carries it is to measure parts of it. `4:1-6` is the first six files of shard 4, in the order the shard runs them.
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
    fwrite(
        STDERR,
        "Usage: php bin/measure-crash-rate.php <shard | shard:from-to | paths under tests/unit> [runs]\n",
    );

    exit(1);
}

$sizes = TestShards::testFiles(__DIR__ . '/../tests/unit');
$count = TestShards::countFor($sizes);

/**
 * The files of one shard, in the order the shard runs them.
 *
 * @return list<string>|null Null where there is no such shard
 */
$shardOf = static function (int $number) use ($sizes, $count): ?array {
    // Asked for rather than range-checked first: the split holds exactly as
    // many shards as `countFor()` counted, so looking the number up is the
    // same question as whether it is one — and one guard cannot disagree with
    // itself the way two can.
    return TestShards::split($sizes, $count)[$number - 1] ?? null;
};

if (preg_match('/^(\d+)(?::(\d+)-(\d+))?$/', $what, $asked) === 1) {
    $number = (int) $asked[1];
    $shard = $shardOf($number);

    if ($shard === null) {
        fwrite(STDERR, sprintf("There are %d shards.\n", $count));

        exit(1);
    }

    $from = (int) ($asked[2] ?? 1);
    $until = (int) ($asked[3] ?? count($shard));

    if ($from < 1 || $until > count($shard) || $from > $until) {
        fwrite(STDERR, sprintf("Shard %d holds %d test files.\n", $number, count($shard)));

        exit(1);
    }

    $shard = array_slice($shard, $from - 1, $until - $from + 1);
    $files = array_map(static fn (string $file): string => 'tests/unit/' . $file, $shard);
    $about = sprintf(
        'shard %d of %d, files %d to %d of %d',
        $number,
        $count,
        $from,
        $until,
        count($shardOf($number) ?? []),
    );
} else {
    $files = [];

    foreach (explode(',', $what) as $path) {
        $file = 'tests/unit/' . ltrim(trim($path), '/');

        if (!is_file(__DIR__ . '/../' . $file)) {
            fwrite(STDERR, sprintf("There is no %s.\n", $file));

            exit(1);
        }

        $files[] = $file;
    }

    $about = count($files) === 1 ? $files[0] : sprintf('%d test files', count($files));
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
