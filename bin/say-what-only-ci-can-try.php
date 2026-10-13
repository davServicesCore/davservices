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
 * Says what a green run on this machine still does not say.
 *
 * **The last line of a gate run is the one somebody believes.** So after the
 * gates pass, this names the jobs no single machine can do: the other PHP
 * versions, Litmus against the example server, and whichever side of `intl`
 * this machine is not on. Without it, a green `pre-push` would read as "CI
 * will be green", and the first time that turned out false the hook would
 * have cost more than it saved.
 *
 * It is the last step of `composer gates:commit` and `composer gates:push`,
 * so it only ever runs after everything before it passed — Composer stops a
 * script list at the first failure.
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

require __DIR__ . '/lib/Gates.php';

$stage = $argv[1] ?? Gates::PUSH;

try {
    Gates::scriptFor($stage);
} catch (InvalidArgumentException $refusal) {
    fwrite(STDERR, '  FAIL gates — ' . $refusal->getMessage() . "\n");

    exit(1);
}

printf("  OK   gates before the %s — every gate that can run here is green\n", $stage);

foreach (Gates::whatOnlyTheCiCanTry(extension_loaded('intl')) as $only) {
    printf("       CI still tries %s\n", $only);
}
