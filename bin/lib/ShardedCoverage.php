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

/**
 * What a shard may say about a file's branches when the reports are merged.
 *
 * **Branch coverage is recorded per opcode, and the opcodes are not the same in
 * every process.** PHP compiles a function differently depending on what is
 * already in memory: a `match` whose arms name constants of other classes comes
 * out one opcode longer where those classes have not been loaded yet, and that
 * shifts every branch id after it. A merge that simply unions the reports
 * therefore unions *numberings* rather than branches — and the ids of a process
 * that ran no test of that file arrive with nothing able to hit them.
 *
 * Measured on 14.10.2026, `Dav\Collation\Collations::named()` came out in three
 * layouts across nine shards:
 *
 *     shard 4  ids 0,4,6,8,11,14,15,19,23,27,33   none hit
 *     shard 5  ids 0,4,6,9,12,15,16,20,24,28,34   all hit
 *     shard 6  ids 0,4,6,8,10,12,13,17,21,25,31   all hit
 *     shard 9  ids 0,4,6,9,12,15,16,20,24,28,34   none hit
 *
 * Shards 4 and 9 hold no test of that class. They **load** it, because a
 * `CARDDAV:text-match` resolves its collation through it, and that is enough
 * for the process to record a numbering of its own. Six of shard 4's ids exist
 * in no other layout, so the merged report counted six branches no test could
 * ever reach, and a floor of 100 % stopped being meetable — because of tests
 * added elsewhere in the suite.
 *
 * ## The rule
 *
 * **A shard that measured nothing in a file has nothing to say about that
 * file's branches.** Its layout is left out, unless no shard measured the file
 * at all — then it is the only thing there is to say, and a class no test
 * touches must keep counting against the floor.
 *
 * It cannot hide a gap. A branch no test reaches is unhit in the layout of the
 * shard that *did* run the tests, and that layout stays. What goes is a second
 * numbering of the same source, which measures the compiler rather than the
 * suite.
 *
 * Where two shards both measured a file, both layouts stay. Each has tested
 * part of it, which of the two numberings is the real one is not a question
 * this can answer, and keeping both is the strict reading.
 *
 * ## Why the types here say so little
 *
 * The records come from `php-code-coverage`, whose `lineCoverage()` and
 * `functionCoverage()` are declared as plain arrays. Claiming a shape they do
 * not promise would be this file asserting something nobody checked, so it
 * takes what it is given and looks before it reads.
 *
 * Line coverage is keyed by source line, which is the same in every process,
 * and it is what says whether a shard measured a file at all. A function
 * record is only ever moved from one place to another, never read into.
 *
 * @license Apache-2.0
 */
final class ShardedCoverage
{
    /**
     * The function records each shard should contribute to the merge.
     *
     * @param list<array{lines: array<array-key, mixed>, functions: array<array-key, mixed>}> $shards In
     *                                                                                                the order they will be merged
     *
     * @return list<array<array-key, mixed>> What to keep, in that same order
     */
    public static function layoutsWorthKeeping(array $shards): array
    {
        $measuredHere = [];
        $measuredAnywhere = [];

        foreach ($shards as $at => $shard) {
            $measured = self::filesItMeasured($shard['lines']);
            $measuredHere[$at] = $measured;
            $measuredAnywhere = [...$measuredAnywhere, ...$measured];
        }

        $kept = [];

        foreach ($shards as $at => $shard) {
            $keep = [];

            foreach ($shard['functions'] as $file => $functions) {
                if (isset($measuredHere[$at][$file]) || !isset($measuredAnywhere[$file])) {
                    $keep[$file] = $functions;
                }
            }

            $kept[] = $keep;
        }

        return $kept;
    }

    /**
     * The files this shard ran any line of.
     *
     * One line is enough: the question is whether the shard ran that code at
     * all, not how much of it. A line that was never run arrives as an empty
     * list of tests, and one that is no statement arrives as null.
     *
     * @param array<array-key, mixed> $lines
     *
     * @return array<array-key, true>
     */
    private static function filesItMeasured(array $lines): array
    {
        $measured = [];

        foreach ($lines as $file => $byLine) {
            if (!is_array($byLine)) {
                continue;
            }

            foreach ($byLine as $tests) {
                if (is_array($tests) && $tests !== []) {
                    $measured[$file] = true;

                    break;
                }
            }
        }

        return $measured;
    }
}
