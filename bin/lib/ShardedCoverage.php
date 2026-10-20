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
 * ## And one thing a collector gets wrong
 *
 * A function whose body was run was entered at the top, whatever the report
 * says — see {@see withTheEntryOfEveryFunctionThatRan()} for the measurement
 * and why repairing it cannot hide a gap.
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
     * One record per function, for the records of one file.
     *
     * **A method reached two ways is recorded twice, and the second record
     * carries no class name.** Measured on 15.10.2026 on
     * `Plugin\CalDav::refuseNesting`, which an event reaches through
     * `$this->refuseNesting(...)` and a test reaches as a method:
     *
     *     DavServices\Plugin\CalDav->refuseNesting   0* 11* 13  21  23
     *     refuseNesting                              0* 11  13* 21* 23*
     *
     * The branch ids are **the same** — it is the same compiled function — and
     * only the hits differ. Unioned, the function is fully covered, which is
     * the truth about the source. Left as two records, a floor of 100 % would
     * demand that every case of every listener be written twice: once through
     * the event it is wired to and once as a direct call. That measures the
     * mechanism rather than the tests.
     *
     * **Two records are the same function only where their branch ids *and*
     * the lines each one spans agree.** Ids alone would not do: two trivial
     * methods in one file can both compile to a single branch at id 0, and
     * folding those together would union the hits of two different functions —
     * hiding a gap instead of measuring one.
     *
     * The qualified name is the one kept, because it is the one a reader can
     * find; where neither is qualified the first stands.
     *
     * @param array<array-key, mixed> $functions The function records of one
     *                                           file
     *
     * @return array<array-key, mixed> The same records, folded
     */
    public static function oneRecordPerFunction(array $functions): array
    {
        $folded = [];
        $seen = [];

        foreach ($functions as $name => $record) {
            $signature = self::signatureOf($record);

            if ($signature === null) {
                $folded[$name] = $record;

                continue;
            }

            $already = $seen[$signature] ?? null;

            if ($already === null) {
                $seen[$signature] = $name;
                $folded[$name] = $record;

                continue;
            }

            $kept = self::qualified($name) && !self::qualified($already) ? $name : $already;
            $mine = $folded[$already] ?? null;

            $folded[$kept] = self::withTheHitsOfBoth($mine, $record, $kept === $name);

            if ($kept !== $already) {
                unset($folded[$already]);
                $seen[$signature] = $kept;
            }
        }

        return $folded;
    }

    /**
     * The same records, with the entry of every function that ran marked as
     * entered.
     *
     * **Control enters a function at its first opcode**, which is the branch
     * at id 0, so a record whose later branches were hit and whose entry was
     * not is describing something that cannot happen. A generator is no
     * exception: creating one runs the prologue, and resuming one continues
     * inside the body.
     *
     * Measured on 20.10.2026 on `VObject\Recur\ObjectSet::instances()`, in the
     * shard that ran its tests:
     *
     *     branch 0  ops 0-6    lines 183-187  hit: []          out_hit: [0, 0]
     *     branch 7  ops 7-12   lines 193-195  hit: [1 test]    out_hit: [0]
     *     branch 13 ops 13-21  lines 198-201  hit: [13 tests]  out_hit: [1]
     *
     * Branch 7 is the body of the `if` that branch 0 evaluates, and there is
     * no way into it but through branch 0. The same test file reported the
     * entry hit when it ran alone and unhit with two unrelated test files
     * beside it in the process, so what the entry says depends on the company
     * it keeps — a property of the collector rather than of the suite.
     *
     * **It cannot hide a gap.** An entry branch is unreachable only where the
     * function is never called, and then no branch of it is hit, so nothing is
     * claimed and an untested function keeps counting against the floor.
     *
     * The tests named are the ones that ran the function, because they are the
     * ones that entered it: a hit by nobody would say a branch was reached and
     * leave no way to ask which case reached it.
     *
     * @param array<array-key, mixed> $functions The function records of one
     *                                           file
     *
     * @return array<array-key, mixed> The same records, repaired
     */
    public static function withTheEntryOfEveryFunctionThatRan(array $functions): array
    {
        foreach ($functions as $name => $record) {
            if (!is_array($record) || !is_array($record['branches'] ?? null)) {
                continue;
            }

            $branches = $record['branches'];
            $entry = $branches[0] ?? null;

            if (!is_array($entry) || ($entry['hit'] ?? null) !== []) {
                continue;
            }

            $ran = self::theTestsThatRanIt($branches);

            if ($ran === []) {
                continue;
            }

            $entry['hit'] = $ran;
            $branches[0] = $entry;
            $record['branches'] = $branches;
            $functions[$name] = $record;
        }

        return $functions;
    }

    /**
     * Every test that entered any branch of this function, each once, in the
     * order they turn up.
     *
     * @param array<array-key, mixed> $branches
     *
     * @return list<string>
     */
    private static function theTestsThatRanIt(array $branches): array
    {
        $tests = [];

        foreach ($branches as $branch) {
            if (!is_array($branch) || !is_array($branch['hit'] ?? null)) {
                continue;
            }

            foreach ($branch['hit'] as $test) {
                if (is_string($test) && !in_array($test, $tests, true)) {
                    $tests[] = $test;
                }
            }
        }

        return $tests;
    }

    /**
     * What tells one compiled function from another: the branch ids it has and
     * the lines each one spans. Null where this is no function record.
     *
     */
    private static function signatureOf(mixed $record): ?string
    {
        if (!is_array($record) || !is_array($record['branches'] ?? null)) {
            return null;
        }

        $spans = [];

        foreach ($record['branches'] as $id => $branch) {
            if (!is_array($branch)) {
                return null;
            }

            $spans[] = sprintf(
                '%s:%s-%s',
                is_int($id) ? $id : 'x',
                self::numberIn($branch, 'line_start'),
                self::numberIn($branch, 'line_end'),
            );
        }

        return implode(' ', $spans);
    }

    /**
     * @param array<array-key, mixed> $branch
     */
    private static function numberIn(array $branch, string $key): string
    {
        $value = $branch[$key] ?? null;

        return is_int($value) ? (string) $value : 'x';
    }

    /**
     * Whether a name says which class the function belongs to.
     */
    private static function qualified(string|int $name): bool
    {
        return is_string($name) && (str_contains($name, '->') || str_contains($name, '::'));
    }

    /**
     * The two records as one, with every branch hit either of them saw.
     *
     * The paths travel with whichever record is kept, so that the merge hands
     * on a whole record rather than half of one.
     *
     * @param mixed $kept The record already in hand
     * @param mixed $arrived The one that turned out to be the same function
     * @param bool $second Whether the arriving record's name is the one kept
     *
     */
    private static function withTheHitsOfBoth(mixed $kept, mixed $arrived, bool $second): mixed
    {
        if (!is_array($kept) || !is_array($arrived)) {
            return $kept;
        }

        $branches = $kept['branches'] ?? null;
        $arriving = $arrived['branches'] ?? null;

        if (!is_array($branches) || !is_array($arriving)) {
            return $kept;
        }

        foreach ($arriving as $id => $branch) {
            $hit = is_array($branch) ? ($branch['hit'] ?? []) : [];
            $mine = $branches[$id] ?? null;

            if (!is_array($mine) || !is_array($hit) || $hit === []) {
                continue;
            }

            $mine['hit'] = [...(is_array($mine['hit'] ?? null) ? $mine['hit'] : []), ...$hit];
            $branches[$id] = $mine;
        }

        $whole = $second ? $arrived : $kept;
        $whole['branches'] = $branches;

        return $whole;
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
