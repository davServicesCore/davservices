<?php

/**
 * Writes MANIFEST.json: version, release date and a SHA-256 checksum for every shipped file.
 *
 * Consumers can verify an installed copy against this file, so a tampered or partially updated library is detected before it can misbehave at runtime.
 *
 * Usage: php bin/build-manifest.php <version>
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

$version = $argv[1] ?? null;

if ($version === null || preg_match('/^v?\d+\.\d+\.\d+/', $version) !== 1) {
    fwrite(STDERR, "Usage: php bin/build-manifest.php <version>\n");
    exit(2);
}

$root = dirname(__DIR__);
$files = [];

/**
 * Repository-relative, forward-slashed path for a file below $root.
 *
 * Separators are normalised before the prefix is stripped. Iterating
 * "$root/src" on Windows yields a mixed path such as "D:\repo/src\Foo.php",
 * where trimming DIRECTORY_SEPARATOR leaves the leading forward slash in
 * place and every entry ends up as "/src/...". Normalising first makes the
 * result identical on both platforms.
 */
function manifestPath(string $root, string $path): string
{
    $root = str_replace('\\', '/', $root);
    $path = str_replace('\\', '/', $path);

    return ltrim(str_replace($root, '', $path), '/');
}

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)
);

foreach ($it as $entry) {
    if (!$entry instanceof SplFileInfo || $entry->getExtension() !== 'php') {
        continue;
    }

    $files[manifestPath($root, $entry->getPathname())] = hash_file('sha256', $entry->getPathname());
}

// Everything else the package ships. Keep this in step with the export-ignore
// rules in .gitattributes: a file that reaches the consumer unhashed is a file
// the manifest cannot vouch for.
foreach (['autoload.php', 'composer.json', 'LICENSE', 'NOTICE', 'README.md'] as $extra) {
    if (is_file($root . '/' . $extra)) {
        $files[$extra] = hash_file('sha256', $root . '/' . $extra);
    }
}

ksort($files);

$manifest = [
    'name'        => 'davservices/davservices',
    'version'     => ltrim($version, 'v'),
    'released'    => gmdate('c'),
    'php'         => '^8.2',
    'algorithm'   => 'sha256',
    'fileCount'   => count($files),
    'files'       => $files,
];

file_put_contents(
    $root . '/MANIFEST.json',
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);

printf("MANIFEST.json written: %d files, version %s\n", count($files), $manifest['version']);
