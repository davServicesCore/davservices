<?php

/**
 * davServices — Composer-free PSR-4 autoloader.
 *
 * The library has no runtime dependencies, so it must be usable without Composer. Include this file and you are done:
 *
 *     require __DIR__ . '/vendor/davservices/autoload.php';
 *
 * If you do use Composer, ignore this file — the generated autoloader already maps DavServices\ to src/davservices/.
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'DavServices\\';
    $length = strlen($prefix);

    if (strncmp($class, $prefix, $length) !== 0) {
        return;
    }

    $relative = substr($class, $length);
    $file = __DIR__ . '/src/davservices/' . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
