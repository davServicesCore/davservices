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
     * Test files that have been measured to crash the coverage collector when
     * they share a process, and are therefore packed into different shards.
     *
     * **One pair, found by measuring rather than by reasoning.** Xdebug bug
     * 2252 is open since March 2024 and nothing here can cure it; what can be
     * done is to not hand it the one combination this suite is known to fall
     * over on. {@see self::ATTEMPTS} carries the narrowing that found it: the
     * whole of shard 4 crashed 18 of 20 runs, and halving it twice came down
     * to two files.
     *
     * **It is the pair that crashes, not either file.** Twenty runs each on
     * Linux with Xdebug 3.4.7 on 24.11.2026, every target named by path:
     *
     * | Sharing one process | Crashed |
     * |---|---|
     * | `FreeBusyQueryTest` alone | 0 of 20 |
     * | `SchedulingCancelTest` alone | 0 of 20 |
     * | `FreeBusyQueryTest` + `PropPatchResultTest` | 0 of 20 |
     * | `FreeBusyQueryTest` + `TimeRangeTest` | 0 of 20 |
     * | `FreeBusyQueryTest` + `Recur\IteratorTest` | 0 of 20 |
     * | `FreeBusyQueryTest` + `Recur\ObjectSetTest` | 0 of 20 |
     * | `SchedulingCancelTest` + `SchedulingMessageTest` | 0 of 20 |
     * | `FreeBusyQueryTest` + `SchedulingMessageTest` | **1 of 20** |
     * | **`FreeBusyQueryTest` + `SchedulingCancelTest`** | **13 of 20** |
     *
     * The stray single crash stays in the table rather than being rounded
     * away: it says the collector can fall over elsewhere, and that this list
     * avoids one pair rather than fixing the bug.
     *
     * **The pair was named wrong first, and that is recorded rather than
     * tidied away.** The narrowing measured `4:2-3` — places in a shard, not
     * names — and the first version of this list read those places off a
     * working tree whose own test files had grown since. Packing is by file
     * size, so any such change reshuffles every shard: the list named
     * `CompFilterTest`, which is harmless, the rule moved a file that was
     * never in the way, and shard 4 went on crashing 16 of 20. Measure by
     * number if it is quicker; name by path, resolved in the tree the
     * measurement ran on.
     *
     * **Nothing explains it, and that is said rather than dressed up.** Five
     * explanations were measured and fell — the three in ATTEMPTS, a shared
     * `TimeRange`, and the recurrence engine, which both files drive and whose
     * own tests drive it harder beside either without crashing. So this list
     * is a workaround with a measurement behind it, not a theory.
     *
     * **It is a list of files, not of pairs**, because one pair is all there
     * is and a structure for more would be a structure for a problem nobody
     * has. Every file named here lands in a shard of its own as far as the
     * other names go; add a third and all three are kept apart.
     *
     * **And it is a mitigation, not a promise.** Asked for one shard, the
     * split hands back one shard — see {@see self::split()}.
     *
     * @var list<string>
     */
    public const KEPT_APART = [
        'CalDav/Report/FreeBusyQueryTest.php',
        'Plugin/SchedulingCancelTest.php',
    ];

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
     * **Not because the crash is a coin toss** — that was measured and found
     * wrong. Over eight jobs between 23. and 25.10.2026 the attempts read 0 of
     * 1, 2 of 2, 1 of 2, 0, 2 of 2, 0, 1 of 1 and **5 of 5**: a job that
     * crashes tends to crash every time, so retrying inside one buys only the
     * rare mixed job. It is kept because that job happened once and an attempt
     * costs well under half a minute, not because it makes a crashing job
     * pass.
     *
     * **What a crashing job needed was the trigger gone.** Xdebug's branch
     * coverage segfaulted inside one report's tests at a different test each
     * time — 74, 104, 104, 102, 102 of the same shard — which ruled out a
     * fixed test and pointed at the code every one of them drives.
     * {@see \DavServices\Sync\Report\SyncCollection::membersUnder()} was a
     * generator that both yielded and delegated with `yield from` in one
     * frame; written as a worklist it stopped. Two hypotheses fell on the way:
     * the version (3.4.7 crashes as 3.5.3 did, only less often) and the
     * closure seam the bug report pointed at (an interface changed nothing).
     *
     * **The same shape turned up a second time**, and this is what it looks
     * like from the outside: on 18.11.2026 shard 14 crashed five attempts of
     * five, and narrowing it down left one test file that crashes on its own
     * in a second.
     * {@see \DavServices\Plugin\Scheduling::objectsUnder()} was a generator
     * that yielded and delegated to itself in one frame, exactly as
     * `membersUnder()` had been. Measured on that one file rather than argued:
     * nine crashes in twenty runs before, none in twenty after.
     *
     * **A flaky crash is not bisectable, which cost a wrong answer first.**
     * One run of each of five commits read like a clean bisect and meant
     * nothing — at that rate a single pass is a coin, and the branch head
     * passed while the merge of the same content crashed. The first step with
     * a crash like this is to measure its rate, not to halve the history.
     *
     * **And the shape alone is not enough to fall over.** Three generators
     * carry it and were cleared — `CalendarQuery::matchesUnder()`,
     * `FreeBusyQuery::objectsUnder()` and `AddressBookQuery::matchesUnder()`,
     * none of which crashed in twelve runs each, so they were left alone. A
     * resemblance is not a reproducer.
     *
     * `Serializer::lines()` was on that list and is not on this one: it
     * delegates and no longer yields in the same frame, so it does not carry
     * the shape at all.
     *
     * ## What twenty runs each said on 24.11.2026
     *
     * Measured with `bin/measure-crash-rate.php` on Linux, which exists
     * because none of this can be measured on Windows: the pair below went
     * twelve runs there without a murmur.
     *
     * | Target | Xdebug | Crashed |
     * |---|---|---|
     * | shard 4, all 13 files | 3.4.7 | 18 of 20 |
     * | shard 4, all 13 files | 3.4.0 | 13 of 20 |
     * | files 1-6 | 3.4.7 | 16 of 20 |
     * | files 7-13 | 3.4.7 | **0 of 20** |
     * | files 1-3 | 3.4.7 | 18 of 20 |
     * | file 1, `SchedulingMessageTest` | 3.4.7 | **0 of 20** |
     * | file 2, `CompFilterTest` | 3.4.7 | **0 of 20** |
     * | file 3, `FreeBusyQueryTest` | 3.4.7 | **0 of 20** |
     * | files 1+2 | 3.4.7 | **0 of 20** |
     * | files 1+3 | 3.4.7 | 1 of 20 |
     * | **files 2+3** | 3.4.7 | **14 of 20** |
     *
     * **So there is a reproducer, and it is a pair rather than a file.**
     * `4:2-3` crashes about seven runs in ten in some three minutes, and
     * fourteen of the eighteen whole-shard crashes were SIGSEGV at exactly 63
     * of 258 tests — the same point every time, which is the opposite of the
     * moving point that ruled out a fixed test above.
     *
     * ## Three explanations fell against it
     *
     * **More attempts.** See ATTEMPTS: measured not to help, and the new rate
     * makes it worse rather than better.
     *
     * **A smaller shard.** Two files crash as often as thirteen, so splitting
     * would move the pair rather than break it. The file-size cost model is
     * still wrong, and still about how long a measurement takes.
     *
     * **Two carriers of the shape in one process.** The pair drives
     * {@see \DavServices\VObject\Recur\ObjectSet::instances()} and
     * `FreeBusyQuery::objectsUnder()`, one carrier each, which read like an
     * answer. Taking the shape out of the first left the pair at 15 of 20 and
     * the whole shard at 14 of 20 — no cure, and the experiment was taken
     * back rather than kept as a false explanation.
     *
     * **What is still the list to try**, now against a reproducer rather than
     * a resemblance: the other carrier of the pair, the two that remain
     * untested at all ({@see \DavServices\CalDav\Expander::instancesIn()}
     * and `CalendarQuery::matchesUnder()`), and halving the tests inside the
     * two files.
     *
     * **This is a flaky instrument, not a flaky measurement.** Every attempt
     * that finishes gives the same figures, and a shard whose *tests* fail
     * exits 1 or 2, is no crash, and is not tried again. The cure is upstream:
     * Xdebug bug 2252, open since March 2024.
     *
     * **Raising it was weighed again on 24.11.2026 and rejected.** Shard 4
     * crashed five of five on main while the same tree's pull request run
     * crashed once and passed, which looks like a coin and is not one: the
     * eight jobs above were measured, and a job that crashes tends to crash
     * every time. More attempts buy the rare mixed job a second time over,
     * not a crashing job. What a crashing job needs is still the trigger
     * gone, and what that needs is a reproducer — which is what
     * `bin/measure-crash-rate.php` is for.
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
            $lightest = self::lightest($weights, self::wouldShareAProcess($shards, $file['file']));
            $shards[$lightest][] = $file['file'];
            $weights[$lightest] = ($weights[$lightest] ?? 0) + $file['size'];
        }

        return $shards;
    }

    /**
     * The number of the first shard that carries the least so far.
     *
     * @param array<int, int> $weights
     * @param list<int> $avoiding Shards this file may not join, which
     *                            {@see self::wouldShareAProcess()} works out
     */
    private static function lightest(array $weights, array $avoiding = []): int
    {
        $lightest = 0;
        $least = PHP_INT_MAX;

        foreach ($weights as $index => $weight) {
            if ($weight < $least && !in_array($index, $avoiding, true)) {
                $lightest = $index;
                $least = $weight;
            }
        }

        // **Where every shard is to be avoided, the lightest wins anyway.**
        // {@see self::KEPT_APART} is a mitigation and not a promise: asked for
        // one shard, this hands back one shard rather than refusing a split it
        // was asked for.
        return $least === PHP_INT_MAX ? self::lightest($weights) : $lightest;
    }

    /**
     * The shards this file may not join, because one of them already holds a
     * file {@see self::KEPT_APART} keeps it away from.
     *
     * @param list<list<string>> $shards
     *
     * @return list<int>
     */
    private static function wouldShareAProcess(array $shards, string $file): array
    {
        if (!in_array($file, self::KEPT_APART, true)) {
            return [];
        }

        $avoid = [];

        foreach ($shards as $number => $files) {
            if (array_intersect($files, self::KEPT_APART) !== []) {
                $avoid[] = $number;
            }
        }

        return $avoid;
    }
}
