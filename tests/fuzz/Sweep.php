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

namespace DavServices\Tests\Fuzz;

use Generator;

/**
 * One fuzz run: every object, every mutation, a range of seeds, every target.
 * R-QS-19.
 *
 * **It is a plain cross product on purpose.** A curated list of interesting
 * cases is a list of the cases somebody already thought of, which is what the
 * unit tests are for; the value of a sweep is that it tries the combinations
 * nobody chose. So adding one mutation adds it against every object and every
 * target, rather than in the one place somebody remembered to wire it up.
 *
 * **Every input carries a name that says how to make it again** — which
 * object, which mutation, which seed — because a finding that cannot be
 * reproduced is a rumour. The three parts of the name are exactly the three
 * arguments of {@see Mutations::apply()}, so a reader of a report can get the
 * same bytes back without this class.
 */
final class Sweep
{
    /**
     * The inputs one run works through, under the names that make them again.
     *
     * @param int $seeds How many seeds each combination is tried at
     * @param int $from The first seed, so a later run can go on past the
     *                  seeds an earlier one spent
     *
     * @return Generator<string, string>
     */
    public static function inputs(int $seeds, int $from = 1): Generator
    {
        foreach (Mutations::seeds() as $object => $valid) {
            foreach (Mutations::names() as $mutation) {
                for ($seed = $from; $seed < $from + $seeds; ++$seed) {
                    yield sprintf('%s / %s / seed %d', $object, $mutation, $seed)
                        => Mutations::apply($mutation, $valid, $seed);
                }
            }
        }
    }

    /**
     * Everything the run found, as it finds it.
     *
     * The targets are {@see Targets} unless somebody says otherwise, and they
     * can be said otherwise only so that the test list can watch a finding
     * happen without waiting for the library to produce one.
     *
     * **`$beginning` is told where the run is before each one starts**, not
     * after. A memory error is not an exception: it ends the process, and
     * nothing is left to ask which input caused it. The only report such a
     * failure allows is one that was already written down.
     *
     * @param int $seeds How many seeds each combination is tried at
     * @param int $from The first seed
     * @param array<string, callable(string): mixed>|null $targets The steps
     * @param (callable(string): void)|null $beginning Told where the run is
     *
     * @return Generator<int, string>
     */
    public static function findings(
        int $seeds,
        int $from = 1,
        ?array $targets = null,
        ?callable $beginning = null,
    ): Generator {
        $targets ??= self::every();

        foreach (self::inputs($seeds, $from) as $name => $input) {
            foreach ($targets as $target => $take) {
                $at = $name . ', ' . $target;

                if ($beginning !== null) {
                    $beginning($at);
                }

                $found = Run::of($input, $take);

                if ($found !== null) {
                    yield sprintf("%s:\n  %s", $at, $found);
                }
            }
        }
    }

    /**
     * Every target there is, ready to be handed an input.
     *
     * @return array<string, callable(string): mixed>
     */
    public static function every(): array
    {
        $targets = [];

        foreach (Targets::names() as $name) {
            $targets[$name] = Targets::of($name);
        }

        return $targets;
    }
}
