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
 * The two stages the gates run at, and what a local run still cannot say.
 *
 * **Running the gates locally is about learning the answer before GitHub
 * does.** Splitting them in two is about the answer still being wanted:
 * `git commit --no-verify` is one flag away, and a hook that costs minutes
 * teaches people to reach for it. A check that gets switched off protects
 * nothing.
 *
 * The requirements already draw that line for the slowest gate there is —
 * R-QS-26: „Der Mutationslauf ist zu langsam für jeden Commit und läuft
 * nächtlich sowie vor jedem Release." The same reasoning reaches two more:
 * the **sharded coverage run** takes minutes, because Xdebug's branch check
 * makes every call slower than the last ({@see TestShards}), and the **fuzz
 * sweep** spends tens of thousands of runs on purpose. Both belong before a
 * push, where they are paid for once per claim instead of once per save
 * point. What is left costs seconds and names a mistake where it was made.
 *
 * ## There is no list in this file, and that is the point
 *
 * What each stage runs lives in `composer.json` beside every other script,
 * and `gates:push` is `@check` itself — the very list CI runs. A second list
 * here would be a second thing to keep in step, and the day somebody added a
 * gate to `check` and forgot this file is the day the hook started lying. So
 * this reads the manifest, and the test list holds what it finds there to
 * three promises: a commit runs only gates `check` runs too, a push runs the
 * whole of `check`, and the slow ones are left out of a commit on purpose.
 *
 * It is a plain class in the global namespace like the rest of `bin/lib`,
 * because `bin/` is tooling and runs before anything is installed.
 *
 * @license Apache-2.0
 */
final class Gates
{
    /** Before a commit: the gates that cost seconds. */
    public const COMMIT = 'commit';

    /** Before a push: everything CI runs. */
    public const PUSH = 'push';

    /** @var list<string> */
    public const STAGES = [self::COMMIT, self::PUSH];

    /**
     * The Composer script a hook for that stage runs.
     *
     * @throws InvalidArgumentException If there are no gates for that stage
     */
    public static function scriptFor(string $stage): string
    {
        if (!in_array($stage, self::STAGES, true)) {
            throw new InvalidArgumentException(
                sprintf('There are no gates for "%s"; there are %s.', $stage, implode(' and ', self::STAGES)),
            );
        }

        return 'gates:' . $stage;
    }

    /**
     * What that stage runs, as the manifest has it and without the `@` that
     * marks a reference to another script.
     *
     * @param array<array-key, mixed> $manifest `composer.json`, decoded
     *
     * @throws InvalidArgumentException If there are no gates for that stage,
     *                                  or the manifest does not define them
     *
     * @return list<string>
     */
    public static function before(string $stage, array $manifest): array
    {
        $script = self::scriptFor($stage);
        $scripts = $manifest['scripts'] ?? null;
        $steps = is_array($scripts) ? $scripts[$script] ?? null : null;

        if (!is_array($steps)) {
            throw new InvalidArgumentException(sprintf('The manifest defines no "%s".', $script));
        }

        $gates = [];

        foreach ($steps as $step) {
            $gates[] = ltrim(is_string($step) ? $step : '', '@');
        }

        return $gates;
    }

    /**
     * What a green run here still does not say.
     *
     * **A hook that implied it had answered CI would be the most expensive
     * kind of wrong**, because the answer would arrive late and be believed
     * in the meantime. Three things are out of reach of any one machine: the
     * other PHP versions, Litmus against the example server, and whichever
     * side of `intl` this machine is not on — R-ENV-03 asks that the library
     * work without it, and CI proves both sides.
     *
     * @param bool $intlIsHere Whether this machine has the extension loaded
     *
     * @return list<string>
     */
    public static function whatOnlyTheCiCanTry(bool $intlIsHere): array
    {
        return [
            'the suite on PHP 8.3 and 8.4',
            $intlIsHere ? 'the library without intl (R-ENV-03)' : 'the library with intl loaded (R-ENV-03)',
            'Litmus against examples/minimal-webdav (R-QS-04)',
        ];
    }
}
