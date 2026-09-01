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
 * Fails if production code references anything outside DavServices\ and the PHP core. This is the machine-enforced version of the project's central promise: davServices has no runtime dependencies.
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

require __DIR__ . '/lib/Scanner.php';

$violations = [];

foreach (Scanner::productionFiles() as $file) {
    foreach (Scanner::useStatements($file) as $line => $fqcn) {
        if (str_starts_with($fqcn, 'DavServices\\')) {
            continue;
        }
        if (Scanner::isCoreSymbol($fqcn)) {
            continue;
        }
        $violations[] = sprintf('%s:%d  use %s', Scanner::rel($file), $line, $fqcn);
    }
}

// composer.json must not carry a single third-party package under "require".
$composer = json_decode(
    (string) file_get_contents(__DIR__ . '/../composer.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);

$require = is_array($composer) && is_array($composer['require'] ?? null)
    ? $composer['require']
    : [];

foreach (array_keys($require) as $package) {
    if (!is_string($package)) {
        continue;
    }
    if ($package !== 'php' && !str_starts_with($package, 'ext-')) {
        $violations[] = sprintf('composer.json  require contains third-party package "%s"', $package);
    }
}

Scanner::report('no runtime dependencies', $violations);
