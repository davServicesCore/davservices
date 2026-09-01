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
 * Proves that the library loads and works without Composer.
 *
 * The CI job "Composer-free autoloader" runs this file on a bare checkout.
 * If it exits non-zero, the standalone autoloader is broken — which would
 * only be noticed by users who do not use Composer, i.e. late.
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/autoload.php';

$failures = [];

// Every shipped class must be loadable through the bundled autoloader alone.
$root = dirname(__DIR__, 2) . '/src/davservices';
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
);

$loaded = 0;

foreach ($it as $entry) {
    if (!$entry instanceof SplFileInfo || $entry->getExtension() !== 'php') {
        continue;
    }

    $relative = trim(str_replace($root, '', $entry->getPathname()), DIRECTORY_SEPARATOR);
    $class = 'DavServices\\' . str_replace(
        [DIRECTORY_SEPARATOR, '.php'],
        ['\\', ''],
        $relative,
    );

    if (class_exists($class) || interface_exists($class) || enum_exists($class) || trait_exists($class)) {
        $loaded++;
        continue;
    }

    $failures[] = $class;
}

if ($failures !== []) {
    fwrite(STDERR, "  FAIL standalone autoloader — could not load:\n");
    foreach ($failures as $class) {
        fwrite(STDERR, '       ' . $class . "\n");
    }
    exit(1);
}

printf("  OK   standalone autoloader — %d class(es) loaded without Composer\n", $loaded);
