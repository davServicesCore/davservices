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
 */
final class TestShardsTest extends TestCase
{
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
