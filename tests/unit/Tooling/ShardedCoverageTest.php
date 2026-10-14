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

use PHPUnit\Framework\TestCase;
use ShardedCoverage;

require_once __DIR__ . '/../../../bin/lib/ShardedCoverage.php';

/**
 * Test list for what a shard may say about a file's branches.
 *
 * ## The measurement this is about
 *
 * The suite runs in shards and the reports are merged. Branch coverage is
 * recorded per *opcode*, and **PHP compiles the same function differently
 * depending on which classes happen to be loaded already**: a `match` whose
 * arms name constants of other classes comes out with one opcode more where
 * those classes are not yet in memory, which shifts every branch id after it.
 *
 * Measured on 14.10.2026, `Dav\Collation\Collations::named()` came out in
 * three layouts across nine shards:
 *
 *     shard 4  ids 0,4,6,8,11,14,15,19,23,27,33   none hit
 *     shard 5  ids 0,4,6,9,12,15,16,20,24,28,34   all hit
 *     shard 6  ids 0,4,6,8,10,12,13,17,21,25,31   all hit
 *     shard 9  ids 0,4,6,9,12,15,16,20,24,28,34   none hit
 *
 * Shards 4 and 9 never tested that class — they merely *loaded* it, because a
 * `CARDDAV:text-match` resolves its collation through it. Merging all four
 * unions the ids, so six of shard 4's land in the report having never been
 * reachable by any test, and a floor of 100 % can never be met again.
 *
 * ## The rule, and why it cannot hide a gap
 *
 * **A shard that measured nothing in a file has nothing to say about that
 * file's branches**, so its layout is left out — unless no shard measured the
 * file at all, in which case it is the only thing there is to say, and an
 * untested class must keep counting against the floor.
 *
 * It cannot hide a real gap: an uncovered branch is uncovered in the layout of
 * the shard that *did* run the tests, and that layout stays. What goes is a
 * second numbering of the same source, which measures the compiler rather than
 * the tests.
 *
 * Where two shards both measured a file, both layouts stay. That case is
 * genuinely ambiguous — each has tested part of the function — and keeping
 * both is the strict reading.
 */
final class ShardedCoverageTest extends TestCase
{
    private const FILE = 'src/davservices/Dav/Collation/Collations.php';

    private const OTHER = 'src/davservices/Dav/Collation/Octet.php';

    /**
     * **The whole point**: the shard that ran the tests keeps its layout, and
     * the shard that only loaded the class loses its own.
     */
    public function testAShardThatMeasuredNothingSaysNothingAboutTheBranches(): void
    {
        $kept = ShardedCoverage::layoutsWorthKeeping([
            self::shard(measured: false, ids: [0, 4, 8]),
            self::shard(measured: true, ids: [0, 4, 9]),
        ]);

        self::assertSame([], self::filesIn($kept, 0), 'the shard that only loaded it');
        self::assertSame([self::FILE], self::filesIn($kept, 1), 'the shard that tested it');
    }

    /**
     * **A file nobody measured keeps every record**, because dropping them all
     * would take the file out of the report, and a class no test touches is
     * exactly what a coverage floor is for.
     */
    public function testAFileNobodyMeasuredIsStillReported(): void
    {
        $kept = ShardedCoverage::layoutsWorthKeeping([
            self::shard(measured: false, ids: [0, 4, 8]),
            self::shard(measured: false, ids: [0, 4, 9]),
        ]);

        self::assertSame([self::FILE], self::filesIn($kept, 0));
        self::assertSame([self::FILE], self::filesIn($kept, 1));
    }

    /**
     * Two shards that each tested part of a file both keep their layouts: each
     * has something to say, and which of the two numberings is the "real" one
     * is not a question this can answer.
     */
    public function testTwoShardsThatBothMeasuredItBothKeepTheirLayout(): void
    {
        $kept = ShardedCoverage::layoutsWorthKeeping([
            self::shard(measured: true, ids: [0, 4, 8]),
            self::shard(measured: true, ids: [0, 4, 9]),
        ]);

        self::assertSame([self::FILE], self::filesIn($kept, 0));
        self::assertSame([self::FILE], self::filesIn($kept, 1));
    }

    /**
     * What a shard measured in one file says nothing about another: the rule is
     * per file, because that is where the layouts are.
     */
    public function testTheRuleIsAppliedPerFile(): void
    {
        $kept = ShardedCoverage::layoutsWorthKeeping([
            [
                'lines' => [self::FILE => [90 => []], self::OTHER => [20 => ['a test']]],
                'functions' => [self::FILE => ['named' => []], self::OTHER => ['prepare' => []]],
            ],
            self::shard(measured: true, ids: [0, 4, 9]),
        ]);

        self::assertSame([self::OTHER], self::filesIn($kept, 0), 'it kept the file it measured');
    }

    /**
     * A shard with no record for the file at all is left exactly as it was —
     * there is nothing to drop, and inventing an empty record would add a file
     * to the report that this shard never saw.
     */
    public function testAShardWithoutTheFileIsLeftAlone(): void
    {
        $kept = ShardedCoverage::layoutsWorthKeeping([
            ['lines' => [], 'functions' => []],
            self::shard(measured: true, ids: [0, 4, 9]),
        ]);

        self::assertSame([], self::filesIn($kept, 0));
    }

    /**
     * A file whose lines were measured but which has no function record at all
     * — a script rather than a class — is no special case and no crash.
     */
    public function testAMeasuredFileWithoutFunctionsIsNoTrouble(): void
    {
        $kept = ShardedCoverage::layoutsWorthKeeping([
            ['lines' => [self::FILE => [90 => ['a test']]], 'functions' => []],
        ]);

        self::assertSame([], self::filesIn($kept, 0));
    }

    /**
     * One line hit anywhere in the file is enough to have measured it: the
     * question is whether this shard ran any of that code, not how much.
     */
    public function testOneLineHitIsEnoughToHaveMeasuredIt(): void
    {
        $kept = ShardedCoverage::layoutsWorthKeeping([
            [
                'lines' => [self::FILE => [90 => [], 91 => [], 92 => ['a test']]],
                'functions' => [self::FILE => ['named' => []]],
            ],
            self::shard(measured: true, ids: [0, 4, 9]),
        ]);

        self::assertSame([self::FILE], self::filesIn($kept, 0));
    }

    /**
     * **A record that is no list of lines is no answer either.** The reports
     * come from another library, whose own types promise nothing about their
     * shape, so a shard that handed over something else is passed over rather
     * than read — and it has said nothing about that file's branches, which is
     * exactly what being passed over means here.
     */
    public function testARecordThatIsNoListOfLinesSaysNothing(): void
    {
        $kept = ShardedCoverage::layoutsWorthKeeping([
            ['lines' => [self::FILE => 'not a list of lines'], 'functions' => [self::FILE => ['named' => []]]],
            self::shard(measured: true, ids: [0, 4, 9]),
        ]);

        self::assertSame([], self::filesIn($kept, 0));
    }
    /**
     * The files one shard kept a layout for.
     *
     * Indexed through here rather than in each case, because the result is a
     * list, and the static analysis this project runs is right that a list has
     * no promised length.
     *
     * @param list<array<array-key, mixed>> $kept
     *
     * @return list<array-key>
     */
    private static function filesIn(array $kept, int $shard): array
    {
        $files = $kept[$shard] ?? null;

        self::assertIsArray($files);

        return array_keys($files);
    }

    /**
     * One shard's record of one file, with the branch ids it compiled to.
     *
     * @param bool $measured Whether any of its lines were run here
     * @param list<int> $ids The opcodes the branches came out at
     *
     * @return array{lines: array<array-key, mixed>, functions: array<array-key, mixed>}
     */
    private static function shard(bool $measured, array $ids): array
    {
        $branches = [];

        foreach ($ids as $id) {
            $branches[$id] = ['hit' => $measured ? ['a test'] : [], 'line_start' => 90, 'line_end' => 96];
        }

        return [
            'lines' => [self::FILE => [90 => $measured ? ['a test'] : []]],
            'functions' => [self::FILE => ['named' => ['branches' => $branches, 'paths' => []]]],
        ];
    }
}
