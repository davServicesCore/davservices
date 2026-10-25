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
 * Splits the test suite into shards for the coverage run.
 *
 * Xdebug's branch check makes every call of a measured function slower the
 * more calls the process has made before, and stopping the coverage does not
 * reset it. One process for the whole suite therefore costs time that grows
 * with the square of the suite: on 05.10.2026 the same 263 tests took 7.7 s on
 * their own and 62.7 s at the end of the full run. Separate processes start
 * from zero, so the suite is cut into shards that each stay small.
 *
 * The file size of a test stands in for its cost. It is not exact, but it
 * needs nothing but the checkout, and it grows with the suite on its own.
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

final class TestShards
{
    /**
     * How many bytes of test code one shard takes on.
     *
     * Measured on 05.10.2026: a shard of this size runs in well under a minute
     * with path coverage, where the whole suite of 1.3 MB in one process took
     * nine. Raising it makes fewer, slower shards.
     */
    public const BYTES_PER_SHARD = 200_000;

    /**
     * How many times a shard whose collector crashed is tried.
     *
     * **Because the crash is a coin toss, and one retry is not enough of
     * them.** Measured on 23. and 24.10.2026 over six attempts at shard 4 with
     * Xdebug 3.4.7: three crashed and three did not, and both outcomes came up
     * twice in a row. With one retry a job therefore goes red on roughly a
     * quarter of pushes, which is often enough that nobody trusts the colour.
     *
     * At that rate five attempts leave about one job in thirty red, and a
     * crashed attempt is cheap: it dies a third of the way through the shard,
     * in well under half a minute. Raising the number further buys little and
     * hides a rising crash rate, which is the thing worth noticing if the
     * suite grows.
     *
     * **This is a flaky instrument, not a flaky measurement.** Every attempt
     * that finishes gives the same figures, and a shard whose *tests* fail
     * exits 1 or 2, is no crash, and is not tried again. The cure is upstream:
     * Xdebug bug 2252, open since March 2024.
     */
    public const ATTEMPTS = 5;

    /**
     * Whether a shard's process crashed rather than failed.
     *
     * **This is for the collector falling over and for nothing else.** A shard
     * whose tests fail exits 1 or 2, fails on the first attempt and stays
     * failed, because a real failure repeats.
     *
     * ## Why a set of signals rather than a list of the ones seen
     *
     * This rule was written three times, and each time a run brought a
     * signature it did not know: SIGSEGV on 23.10.2026, SIGABRT the same day
     * after `double free or corruption (out)`, and SIGBUS on 25.10.2026 in the
     * nightly. Each time the identical crash came back as an ordinary failure
     * because the number was not on the list. Keeping a list of what has
     * happened is a rule that is wrong until the next time.
     *
     * So the rule is the **closed** set POSIX already defines: the signals
     * that say a process was killed by a fault **in itself**, each of which
     * dumps core. There are five — SIGILL, SIGABRT, SIGBUS, SIGFPE and
     * SIGSEGV — and a shell reports a child killed by one as 128 plus its
     * number. Heap corruption inside an extension can surface as any of them,
     * which is exactly what has been seen.
     *
     * **A process somebody stopped is still not a crash.** SIGTERM, SIGINT and
     * SIGKILL are deliberately outside the set: a stopped run stops part way
     * and running it again would fight whoever stopped it.
     *
     * **And Windows has no signals**, so the equivalents are the status codes
     * for the two faults this bug produces — an access violation and heap
     * corruption — which `passthru` hands back as signed ints. This project is
     * measured in CI on Linux and written on Windows, so a rule that knew only
     * the POSIX side would be dead exactly where somebody is watching it
     * happen.
     *
     * @param int $status What `passthru` gave back
     */
    public static function crashed(int $status): bool
    {
        // SIGILL, SIGABRT, SIGBUS, SIGFPE, SIGSEGV — the faults that dump
        // core, as a shell reports them.
        if (in_array($status, [128 + 4, 128 + 6, 128 + 7, 128 + 8, 128 + 11], true)) {
            return true;
        }

        // 0xC0000005 and 0xC0000374: the access violation and heap corruption
        // Windows reports in place of them.
        return $status === -1073741819 || $status === -1073740940;
    }

    /**
     * Whether a crashed attempt nonetheless left the report it was asked for.
     *
     * **A shard that crashed on the way out has still done its work.** The run
     * named above wrote `Generating code coverage report in PHP format ...
     * done` before it aborted: the tests had passed and the measurement was on
     * disc. Running it again would measure the same thing a second time and
     * stand the same chance of crashing again — which is what happened on the
     * attempt before, where the retry fired and the collector fell over once
     * more.
     *
     * So the report decides, and it has to be **this attempt's**. PHPUnit
     * writes it at the end of a run, so a file left by an earlier attempt would
     * otherwise vouch for a run that never finished.
     *
     * **An empty file is no report.** A crash *during* the write leaves one,
     * and taking it would hand the merge something it cannot read.
     * {@see in\merge-coverage.php} refuses anything that is not a
     * `CodeCoverage` by name, so the worst case is a loud failure rather than a
     * quiet gap — but there is no reason to walk into it.
     *
     * @param string $report Where the shard was told to write it
     * @param int $startedAt When the attempt began, as `time()` gives it
     */
    public static function reportSurvived(string $report, int $startedAt): bool
    {
        if (!is_file($report)) {
            return false;
        }

        clearstatcache(true, $report);

        // At or after the second the attempt began: a run that takes under a
        // second would otherwise look as though somebody else had written its
        // report.
        return filesize($report) > 0 && filemtime($report) >= $startedAt;
    }

    /**
     * @return array<string, int> Size in bytes of every *Test.php below $dir,
     *                            keyed by its path relative to $dir, sorted.
     */
    public static function testFiles(string $dir): array
    {
        $root = realpath($dir);

        if ($root === false) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !str_ends_with($file->getFilename(), 'Test.php')) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $files[$relative] = (int) $file->getSize();
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * @param array<string, int> $sizes
     */
    public static function countFor(array $sizes, int $bytesPerShard = self::BYTES_PER_SHARD): int
    {
        if ($bytesPerShard < 1) {
            throw new InvalidArgumentException('A shard has to take on at least one byte.');
        }

        $wanted = (int) ceil(array_sum($sizes) / $bytesPerShard);

        return max(1, min($wanted, count($sizes)));
    }

    /**
     * Hands out the heaviest file first, always to the lightest shard so far.
     *
     * Ties are broken by path and by shard number, so every machine arrives at
     * the same split — each shard works it out for itself.
     *
     * @param array<string, int> $sizes
     *
     * @return list<list<string>>
     */
    public static function split(array $sizes, int $count): array
    {
        if ($count < 1) {
            throw new InvalidArgumentException('There has to be at least one shard.');
        }

        $files = [];

        foreach ($sizes as $file => $size) {
            $files[] = ['file' => $file, 'size' => $size];
        }

        usort($files, static fn (array $a, array $b): int => [$b['size'], $a['file']] <=> [$a['size'], $b['file']]);

        $shards = array_fill(0, $count, []);
        $weights = array_fill(0, $count, 0);

        foreach ($files as $file) {
            $lightest = self::lightest($weights);
            $shards[$lightest][] = $file['file'];
            $weights[$lightest] = ($weights[$lightest] ?? 0) + $file['size'];
        }

        return $shards;
    }

    /**
     * The number of the first shard that carries the least so far.
     *
     * @param array<int, int> $weights
     */
    private static function lightest(array $weights): int
    {
        $lightest = 0;
        $least = PHP_INT_MAX;

        foreach ($weights as $index => $weight) {
            if ($weight < $least) {
                $lightest = $index;
                $least = $weight;
            }
        }

        return $lightest;
    }
}
