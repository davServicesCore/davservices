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
 * Hands the CI the shard numbers of the coverage run, for its matrix.
 *
 * Why the run is split at all is explained in bin/lib/TestShards.php. The
 * number of shards follows the size of tests/unit, so a new test needs no
 * change here or in the workflow: it lands in a shard on its own.
 *
 * Usage: php bin/split-tests.php matrix    prints e.g. [1,2,3]
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

require __DIR__ . '/lib/TestShards.php';

if (($argv[1] ?? '') !== 'matrix') {
    fwrite(STDERR, "Usage: php bin/split-tests.php matrix\n");
    exit(1);
}

$count = TestShards::countFor(TestShards::testFiles(__DIR__ . '/../tests/unit'));

echo json_encode(range(1, $count), JSON_THROW_ON_ERROR), "\n";
