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
 * Splits the test suite into shards for the coverage run.
 *
 * Xdebug's branch check makes every call of a measured function slower the
 * more calls the process has made before, and stopping the coverage does not
 * reset it. One process for the whole suite therefore costs time that grows
 * with the square of the suite: on 05.10.2026 the same 263 tests took 7.7 s on
 * their own and 62.7 s at the end of the full run. Separate processes start
 * from zero, so the suite is cut into shards that each stay small.
 *
 * The file size of a test stands in for its cost. It is not exact, but it
 * needs nothing but the checkout, and it grows with the suite on its own.
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

final class TestShards
{
    /**
     * How many bytes of test code one shard takes on.
     *
     * Measured on 05.10.2026: a shard of this size runs in well under a minute
     * with path coverage, where the whole suite of 1.3 MB in one process took
     * nine. Raising it makes fewer, slower shards.
     */
    public const BYTES_PER_SHARD = 200_000;

    /**
     * @return array<string, int> Size in bytes of every *Test.php below $dir,
     *                            keyed by its path relative to $dir, sorted.
     */
    public static function testFiles(string $dir): array
    {
        $root = realpath($dir);

        if ($root === false) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !str_ends_with($file->getFilename(), 'Test.php')) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $files[$relative] = (int) $file->getSize();
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * @param array<string, int> $sizes
     */
    public static function countFor(array $sizes, int $bytesPerShard = self::BYTES_PER_SHARD): int
    {
        if ($bytesPerShard < 1) {
            throw new InvalidArgumentException('A shard has to take on at least one byte.');
        }

        $wanted = (int) ceil(array_sum($sizes) / $bytesPerShard);

        return max(1, min($wanted, count($sizes)));
    }

    /**
     * Hands out the heaviest file first, always to the lightest shard so far.
     *
     * Ties are broken by path and by shard number, so every machine arrives at
     * the same split — each shard works it out for itself.
     *
     * @param array<string, int> $sizes
     *
     * @return list<list<string>>
     */
    public static function split(array $sizes, int $count): array
    {
        if ($count < 1) {
            throw new InvalidArgumentException('There has to be at least one shard.');
        }

        $files = [];

        foreach ($sizes as $file => $size) {
            $files[] = ['file' => $file, 'size' => $size];
        }

        usort($files, static fn (array $a, array $b): int => [$b['size'], $a['file']] <=> [$a['size'], $b['file']]);

        $shards = array_fill(0, $count, []);
        $weights = array_fill(0, $count, 0);

        foreach ($files as $file) {
            $lightest = self::lightest($weights);
            $shards[$lightest][] = $file['file'];
            $weights[$lightest] = ($weights[$lightest] ?? 0) + $file['size'];
        }

        return $shards;
    }

    /**
     * The number of the first shard that carries the least so far.
     *
     * @param array<int, int> $weights
     */
    private static function lightest(array $weights): int
    {
        $lightest = 0;
        $least = PHP_INT_MAX;

        foreach ($weights as $index => $weight) {
            if ($weight < $least) {
                $lightest = $index;
                $least = $weight;
            }
        }

        return $lightest;
    }
}
