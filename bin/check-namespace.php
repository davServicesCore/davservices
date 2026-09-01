<?php

/**
 * Fails if src/ declares a namespace other than DavServices\, or if the declared namespace does not match the file's directory (PSR-4).
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

require __DIR__ . '/lib/Scanner.php';

$violations = [];
$root = realpath(__DIR__ . '/../src/davservices');

foreach (Scanner::phpFiles($root) as $file) {
    $namespace = Scanner::declaredNamespace($file);

    if ($namespace === null) {
        $violations[] = Scanner::rel($file) . '  no namespace declared';
        continue;
    }

    if ($namespace !== 'DavServices' && !str_starts_with($namespace, 'DavServices\\')) {
        $violations[] = Scanner::rel($file) . '  declares ' . $namespace;
        continue;
    }

    $expected = rtrim('DavServices\\' . str_replace(
        DIRECTORY_SEPARATOR,
        '\\',
        trim(str_replace($root, '', dirname((string) realpath($file))), DIRECTORY_SEPARATOR)
    ), '\\');

    if ($namespace !== $expected) {
        $violations[] = sprintf(
            '%s  declares %s but PSR-4 requires %s',
            Scanner::rel($file),
            $namespace,
            $expected
        );
    }
}

Scanner::report('namespace consistency', $violations);
