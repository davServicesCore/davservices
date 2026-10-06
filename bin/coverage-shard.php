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
 * Xdebug's coverage collector has segfaulted on this suite (24.09.2026, at
 * test 1934 of 1954, in CI and in the nightly the same morning). Smaller
 * processes should make that rarer, but nothing says they make it
 * impossible, so a shard is tried once more — and only for that signature:
 * 139 is 128 + 11, which is how a shell reports a child killed by SIGSEGV. A
 * test that fails exits 1 or 2, fails on the first attempt and stays failed,
 * because a real failure repeats.
 *
 * Usage:
 *   php bin/coverage-shard.php 3      runs shard 3 (CI runs the shards side by side)
 *   php bin/coverage-shard.php all    runs every shard, one after the other
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

require __DIR__ . '/lib/TestShards.php';

const KILLED_BY_SIGSEGV = 139;

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

    $status = 0;
    passthru($command, $status);

    if ($status === KILLED_BY_SIGSEGV) {
        fwrite(STDOUT, "::warning::Xdebug's coverage collector crashed (signal 11) in shard {$number}. Running once more.\n");
        passthru($command, $status);
    }

    if ($status !== 0) {
        fwrite(STDERR, "::error::Shard {$number} failed with exit code {$status}.\n");
        exit($status);
    }
}
