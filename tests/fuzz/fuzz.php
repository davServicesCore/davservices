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
 * Fuzzes the iCalendar and vCard parsers (R-QS-19, P4-14).
 *
 * **It is a script and not a test, for two reasons.** The suite runs under
 * Xdebug's path coverage, where everything takes fifty times as long — a run
 * of thousands of inputs would cost more than the rest of the gates together,
 * and {@see DavServices\Tests\Fuzz\Run}'s time limit would report an endless
 * loop on every slow input. And a memory error cannot be caught from inside
 * the test that caused it; here the shutdown function below names the input
 * the process died on, which is the only report a fatal error allows.
 *
 * The run is **deterministic**: a seed makes an input, and the same seed makes
 * it again, so a finding here is a finding anybody can see.
 *
 *     php tests/fuzz/fuzz.php                 # the gate's budget
 *     php tests/fuzz/fuzz.php --seeds=1000    # a longer look
 *     php tests/fuzz/fuzz.php --from=5000     # seeds a previous run did not spend
 *     php tests/fuzz/fuzz.php --memory=64     # a tighter ceiling to hunt under
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

use DavServices\Tests\Fuzz\Mutations;
use DavServices\Tests\Fuzz\Sweep;
use DavServices\Tests\Fuzz\Targets;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// The seeds carry dates and offsets, so the run has to agree with the suite
// about what time it is. Anything else makes a finding depend on the machine.
ini_set('date.timezone', 'UTC');
ini_set('error_reporting', (string) -1);

/**
 * How much memory one input may make the library want, in megabytes.
 *
 * R-QS-19 asks for no memory error, and that is only a claim if there is a
 * ceiling to hit: "a deep nesting" opens a thousand components and "a very
 * long line" carries a hundred and fifty thousand bytes, and a reader that
 * kept the whole of either in a structure per line would find this. Lower it
 * with `--memory=` to hunt for a reader that keeps more than it needs to.
 */
const MEMORY = 256;

/**
 * The arguments there are.
 *
 * **Named, and anything else refused.** `--seed=2000` for `--seeds=2000`
 * would otherwise be taken, ignored and run at the default, and a run that
 * spent an hour on a budget nobody asked for is the quietest kind of waste.
 *
 * Each takes a **positive** number, which is why the pattern below will not
 * read a zero: `--seeds=0` is a run of nothing, and a gate that passes
 * because it did nothing is worse than one that fails.
 */
const ARGUMENTS = ['seeds', 'from', 'memory'];

$arguments = [];

foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z]+)=([1-9]\d*)$/', $argument, $parts) !== 1
        || !in_array($parts[1], ARGUMENTS, true)) {
        fwrite(STDERR, sprintf(
            "  FAIL fuzz — cannot read the argument \"%s\"; there are %s, each a positive number\n",
            $argument,
            '--' . implode('=, --', ARGUMENTS) . '=',
        ));

        exit(1);
    }

    $arguments[$parts[1]] = (int) $parts[2];
}

/**
 * How many seeds each combination is tried at when nobody says.
 *
 * Measured at about fifteen seconds, which is nothing beside the coverage
 * shards the same gate run pays for, and enough that every mutation meets
 * every object a hundred different ways. A longer hunt is `--seeds=`, and the
 * seeds it spends are the same ones, so a finding at seed 2 400 is a finding
 * the gate will keep seeing once the budget reaches it.
 */
const SEEDS = 100;

/**
 * How many findings are printed before the rest are only counted.
 *
 * One defect shows up under thousands of inputs at once, and a report of
 * thousands is a report nobody reads. A handful of cases and an honest total
 * is what somebody can act on.
 */
const REPORTED = 20;

$seeds = $arguments['seeds'] ?? SEEDS;
$from = $arguments['from'] ?? 1;
$memory = $arguments['memory'] ?? MEMORY;

// **The ceiling is checked, not just asked for.** PHP declines to lower
// `memory_limit` below what the process already holds, and says so in a
// warning rather than by failing — so a run that trusted the call would go on
// under the old ceiling and report the one it wanted. The whole claim here is
// that nothing passed the ceiling; a report naming a number that was never in
// force would be worth less than no report.
if (@ini_set('memory_limit', $memory . 'M') === false) {
    fwrite(STDERR, sprintf(
        "  FAIL fuzz — the ceiling cannot be set to %d MB; %s is already in use\n",
        $memory,
        number_format(memory_get_usage(true) / 1_048_576, 1) . ' MB',
    ));

    exit(1);
}

$objects = count(Mutations::seeds());
$mutations = count(Mutations::names());
$targets = count(Targets::names());
$runs = $objects * $mutations * $seeds * $targets;

// What the process was doing when it died, for the shutdown function: a fatal
// error leaves no stack to ask, so the input has to be written down before it
// is tried rather than after.
$current = 'nothing yet';

register_shutdown_function(static function () use (&$current): void {
    $fatal = error_get_last();

    if ($fatal === null || ($fatal['type'] & (E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR)) === 0) {
        return;
    }

    fwrite(STDERR, sprintf(
        "\n  FAIL fuzz — the process died on %s\n       %s\n       %s:%d\n",
        $current,
        $fatal['message'],
        $fatal['file'],
        $fatal['line'],
    ));
});

printf(
    "  .... fuzz — %d object(s) × %d mutation(s) × %d seed(s) from %d × %d target(s) = %d run(s), %d MB\n",
    $objects,
    $mutations,
    $seeds,
    $from,
    $targets,
    $runs,
    $memory,
);

$done = 0;
$findings = 0;
$started = hrtime(true);

// **Written out as they are found, not collected first.** One defect usually
// shows up under thousands of inputs at once, and a report of thousands is one
// nobody reads; worse, a run that collected them and then died would have
// found them for nothing. So the first few are printed straight away and the
// rest are counted, which is what a reader needs: one case to work from, and
// the size of the problem.
foreach (Sweep::findings($seeds, $from, null, static function (string $at) use (&$current, &$done): void {
    $current = $at;
    ++$done;
}) as $finding) {
    ++$findings;

    if ($findings > REPORTED) {
        continue;
    }

    if ($findings === 1) {
        fwrite(STDERR, "  FAIL fuzz — findings:\n");
    }

    // Every line of the finding, not only its first: the lines below the
    // heading carry their own indent relative to it.
    fwrite(STDERR, '       ' . str_replace("\n", "\n       ", $finding) . "\n");
}

$took = (hrtime(true) - $started) / 1e9;

if ($findings > 0) {
    fwrite(STDERR, sprintf(
        "  FAIL fuzz — %d finding(s) in %d run(s) in %.1f s%s\n",
        $findings,
        $done,
        $took,
        $findings > REPORTED ? sprintf(', the first %d shown', REPORTED) : '',
    ));

    exit(1);
}

printf("  OK   fuzz — %d run(s) in %.1f s, no findings\n", $done, $took);
