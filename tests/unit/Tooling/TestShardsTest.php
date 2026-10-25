<?php

/*
 * This file is part of davServices.
 *
 * (c) Felix Böck <https://dav.services>
 *
 * Licensed under the Apache License, Version 2.0.
 * For the full copyright and license information, see the LICENSE file.
 */

declare(strict_types=1);

namespace DavServices\Tests\Unit\Tooling;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TestShards;

require_once __DIR__ . '/../../../bin/lib/TestShards.php';

/**
 * Test list for the split of the coverage run into shards.
 *
 * Xdebug's branch check makes every call slower the more calls the process
 * has already made, so one process for the whole suite costs time that grows
 * with the square of the suite. Shards are separate processes, and the split
 * has to keep up with the suite on its own:
 *
 * - every test file lands in exactly one shard, so a new test cannot be left
 *   out of the coverage run or counted twice;
 * - the number of shards follows the size of the suite, so a growing suite
 *   gets more shards instead of slower ones;
 * - shards come out about equally heavy, because the slowest one sets the
 *   pace;
 * - the split is the same on every machine, because each shard works it out
 *   for itself and they have to agree.
 *
 * And the collector's crash is recognised by its signature rather than by any
 * failure, which is **two** numbers: a POSIX shell reports a child killed by
 * SIGSEGV as 128 + 11, and Windows, having no signals, hands back the access
 * violation itself.
 */
final class TestShardsTest extends TestCase
{
    /**
     * **A crash is recognised, a failure is not.** The retry exists for the
     * collector falling over, and for nothing else: a shard whose tests fail
     * exits 1 or 2, fails on the first attempt and stays failed, because a
     * real failure repeats.
     *
     * @param int $status What `passthru` gave back
     */
    #[DataProvider('theWaysAProcessComesBack')]
    public function testACrashIsToldApartFromAFailure(int $status, bool $crashed): void
    {
        self::assertSame($crashed, TestShards::crashed($status));
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function theWaysAProcessComesBack(): iterable
    {
        // **The five faults that dump core**, as a shell reports a child
        // killed by one: 128 plus the signal's number. Heap corruption inside
        // an extension can surface as any of them, and this rule was written
        // three times before it said so — SIGSEGV on 23.10.2026, SIGABRT the
        // same day after `double free or corruption (out)`, SIGBUS on
        // 25.10.2026 in the nightly. Each time the identical crash came back
        // as an ordinary failure because the number was not on the list.
        yield 'killed by SIGILL' => [132, true];
        yield 'killed by SIGABRT' => [134, true];
        yield 'killed by SIGBUS' => [135, true];
        yield 'killed by SIGFPE' => [136, true];
        yield 'killed by SIGSEGV' => [139, true];

        // Windows has no signals: the process ends with the fault itself,
        // which comes back as a signed int. Both are faults this bug has
        // produced — an access violation and heap corruption.
        yield 'an access violation, as Windows reports it' => [-1073741819, true];
        yield 'heap corruption, as Windows reports it' => [-1073740940, true];

        yield 'nothing wrong' => [0, false];
        yield 'a test that failed' => [1, false];
        yield 'a run that could not start' => [2, false];

        // **A process somebody stopped is no crash**, and that distinction has
        // teeth: a stopped run stops part way, and running it again would
        // fight whoever stopped it.
        yield 'killed by SIGINT' => [130, false];
        yield 'killed by SIGKILL' => [137, false];
        yield 'killed by SIGTERM' => [143, false];
    }

    /**
     * **A shard that crashed on the way out has still done its work**, and
     * this is how that is told apart from one that crashed on the way in.
     *
     * The run above wrote `Generating code coverage report in PHP format ...
     * done` before it aborted: the tests had passed and the report was on
     * disc. Running it again would measure the same thing a second time and
     * stand the same chance of crashing again — which is what happened the
     * first time, when the retry fired and the collector fell over once more.
     *
     * So the report decides. It has to be **this attempt's**: the file is
     * written at the end of a run, so one left by an earlier attempt would
     * otherwise vouch for a run that never finished.
     *
     * @param bool $write Whether the report is there at all
     * @param int $age How many seconds before the attempt began it was written
     * @param bool $survived Whether it speaks for this attempt
     */
    #[DataProvider('reportsAndWhetherTheySpeakForTheAttempt')]
    public function testAReportIsOnlyTakenWhereThisAttemptWroteIt(
        bool $write,
        int $age,
        bool $survived,
    ): void {
        $path = sys_get_temp_dir() . '/davservices-shard-' . bin2hex(random_bytes(8)) . '.cov';
        $startedAt = time();

        if ($write) {
            file_put_contents($path, '<?php return 1;');
            touch($path, $startedAt - $age);
        }

        try {
            self::assertSame($survived, TestShards::reportSurvived($path, $startedAt));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * @return iterable<string, array{bool, int, bool}>
     */
    public static function reportsAndWhetherTheySpeakForTheAttempt(): iterable
    {
        yield 'written as the attempt ended' => [true, 0, true];

        // The attempt is what wrote it, and a clock that ticked over during
        // the run must not make its own report look stale.
        yield 'written a moment after it began' => [true, -2, true];

        yield 'left by an earlier attempt' => [true, 5, false];

        yield 'never written at all' => [false, 0, false];
    }

    /**
     * **A crash that is a coin toss needs more than one retry**, and the
     * number is measured rather than picked. Over six attempts at one shard on
     * 23. and 24.10.2026 the collector crashed three times and survived three,
     * both outcomes twice in a row — so a single retry leaves a job red on
     * about a quarter of pushes, and five attempts on about one in thirty.
     *
     * The case is here so that lowering the number is a decision somebody
     * makes rather than a line somebody edits: at two attempts the arithmetic
     * above is what goes wrong, and nothing else would complain.
     */
    public function testAShardIsTriedOftenEnoughForACoinToss(): void
    {
        self::assertGreaterThanOrEqual(5, TestShards::ATTEMPTS);
    }

    /**
     * And an empty file is no report. A crash while the report was being
     * written leaves one, and taking it would hand the merge something it
     * cannot read — `bin/merge-coverage.php` refuses anything that is not a
     * `CodeCoverage` by name, so the worst case is a loud failure rather than
     * a quiet gap, but there is no reason to walk into it.
     */
    public function testAnEmptyFileIsNoReport(): void
    {
        $path = sys_get_temp_dir() . '/davservices-shard-' . bin2hex(random_bytes(8)) . '.cov';

        file_put_contents($path, '');

        try {
            self::assertFalse(TestShards::reportSurvived($path, time()));
        } finally {
            unlink($path);
        }
    }

    public function testEveryTestFileLandsInExactlyOneShard(): void
    {
        $sizes = ['a/OneTest.php' => 5, 'b/TwoTest.php' => 3, 'c/ThreeTest.php' => 8, 'd/FourTest.php' => 1, 'e/FiveTest.php' => 4];

        $shards = TestShards::split($sizes, 3);
        $assigned = array_merge(...$shards);
        sort($assigned);

        $expected = array_keys($sizes);
        sort($expected);

        self::assertSame($expected, $assigned);
    }

    public function testShardsComeOutAboutEquallyHeavy(): void
    {
        $sizes = ['ATest.php' => 9, 'BTest.php' => 7, 'CTest.php' => 6, 'DTest.php' => 5, 'ETest.php' => 4, 'FTest.php' => 3];

        $weights = array_map(
            static fn (array $shard): int => array_sum(array_intersect_key($sizes, array_flip($shard))),
            TestShards::split($sizes, 2),
        );

        self::assertSame([17, 17], $weights);
    }

    public function testTheSplitDoesNotDependOnTheOrderTheFilesWereFoundIn(): void
    {
        $sizes = ['xTest.php' => 4, 'yTest.php' => 4, 'zTest.php' => 4, 'wTest.php' => 2];
        $reversed = array_reverse($sizes, true);

        self::assertSame(TestShards::split($sizes, 2), TestShards::split($reversed, 2));
    }

    public function testNoShardIsLeftEmptyWhenThereAreFilesEnough(): void
    {
        $shards = TestShards::split(['ATest.php' => 100, 'BTest.php' => 1, 'CTest.php' => 1], 3);

        self::assertCount(3, $shards);
        self::assertNotContains([], $shards);
    }

    public function testASplitIntoNoShardsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TestShards::split(['ATest.php' => 1], 0);
    }

    public function testTheNumberOfShardsGrowsWithTheSuite(): void
    {
        self::assertSame(1, TestShards::countFor(['ATest.php' => 100], 250));
        self::assertSame(1, TestShards::countFor(['ATest.php' => 150, 'BTest.php' => 100], 250));
        self::assertSame(2, TestShards::countFor(['ATest.php' => 150, 'BTest.php' => 101], 250));
        self::assertSame(4, TestShards::countFor(['ATest.php' => 300, 'BTest.php' => 300, 'CTest.php' => 300, 'DTest.php' => 100], 250));
    }

    public function testAnEmptySuiteStillHasOneShard(): void
    {
        self::assertSame(1, TestShards::countFor([], 250));
    }

    public function testThereAreNeverMoreShardsThanFiles(): void
    {
        self::assertSame(2, TestShards::countFor(['ATest.php' => 5000, 'BTest.php' => 5000], 250));
    }

    public function testABudgetBelowOneIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TestShards::countFor(['ATest.php' => 1], 0);
    }

    public function testFindsTestFilesOnlyAndNamesThemRelativeToTheDirectory(): void
    {
        $dir = sys_get_temp_dir() . '/davservices-shards-' . bin2hex(random_bytes(4));
        mkdir($dir . '/Sub', 0o777, true);
        file_put_contents($dir . '/Sub/ThingTest.php', '12345');
        file_put_contents($dir . '/Sub/ThingContract.php', 'abc');
        file_put_contents($dir . '/OtherTest.php', '123');

        try {
            self::assertSame(
                ['OtherTest.php' => 3, 'Sub/ThingTest.php' => 5],
                TestShards::testFiles($dir),
            );
        } finally {
            unlink($dir . '/Sub/ThingTest.php');
            unlink($dir . '/Sub/ThingContract.php');
            unlink($dir . '/OtherTest.php');
            rmdir($dir . '/Sub');
            rmdir($dir);
        }
    }
}
