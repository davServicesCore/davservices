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
 * Shared helpers for the repository's own quality gates.
 *
 * Deliberately dependency-free and deliberately simple: these checks run before anything else in the pipeline, so they must work on a bare checkout without vendor/.
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

final class Scanner
{
    /**
     * Classes and interfaces that ship with PHP but may not be autoloaded at
     * the moment a check runs, so class_exists() alone would not find them.
     *
     * @var list<string>
     */
    private const ALWAYS_CORE = [
        'ArrayAccess', 'Countable', 'IteratorAggregate', 'Iterator', 'JsonSerializable',
        'Stringable', 'Traversable', 'Throwable', 'DateTimeInterface', 'DateTimeImmutable',
        'DateTimeZone', 'DateInterval', 'DatePeriod', 'DOMDocument', 'DOMElement',
        'XMLReader', 'XMLWriter', 'SplFileInfo', 'SplObjectStorage', 'SplQueue',
        'ArrayIterator', 'ArrayObject', 'Generator', 'Closure', 'WeakMap',
        'PDO', 'PDOStatement', 'PDOException', 'InvalidArgumentException',
        'RuntimeException', 'LogicException', 'UnexpectedValueException',
        'OutOfBoundsException', 'RangeException', 'DomainException', 'Exception',
        'Error', 'TypeError', 'ValueError', 'JsonException',
    ];

    /** @var list<string>|null */
    private static ?array $coreClassNames = null;

    /**
     * @return list<string> Absolute paths of every .php file below $dir.
     */
    public static function phpFiles(string $dir): array
    {
        $real = realpath($dir);

        if ($real === false || !is_dir($real)) {
            return [];
        }

        $files = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($it as $entry) {
            if ($entry instanceof SplFileInfo && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Every PHP file that is shipped inside the package: src/ plus the root
     * autoload.php.
     *
     * The Composer-free autoloader is distributed and is hashed into MANIFEST.json, so it is production code and the production rules apply to it. Leaving it out would exempt the one file that embodies the no-runtime-dependencies promise from the check that enforces it.
     *
     * @return list<string>
     */
    public static function productionFiles(): array
    {
        $files = self::phpFiles(__DIR__ . '/../../src');
        $autoload = realpath(__DIR__ . '/../../autoload.php');

        if ($autoload !== false) {
            $files[] = $autoload;
        }

        sort($files);

        return $files;
    }

    /**
     * Extracts `use` imports without executing or including the file.
     *
     * @return array<int, string> Line number => fully qualified name.
     */
    public static function useStatements(string $file): array
    {
        $imports = [];
        $tokens = token_get_all((string) file_get_contents($file));

        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_USE) {
                continue;
            }

            // Skip closure `use ($var)` and trait `use Foo;` inside classes.
            if (self::isClosureUse($tokens, $i)) {
                continue;
            }

            $name = self::readQualifiedName(array_slice($tokens, $i + 1));

            if ($name !== '') {
                $imports[$token[2]] = $name;
            }
        }

        return $imports;
    }

    /**
     * Reads the qualified name at the start of a token run, up to the end of
     * the statement.
     *
     * Returns an empty string for `use function` and `use const`, which import
     * symbols rather than classes and are outside what the checks care about.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function readQualifiedName(array $tokens): string
    {
        $name = '';

        foreach ($tokens as $token) {
            if ($token === ';' || $token === '{' || $token === ',') {
                break;
            }
            if (!is_array($token)) {
                continue;
            }
            if (in_array($token[0], [T_FUNCTION, T_CONST], true)) {
                return '';
            }
            if (in_array($token[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $name .= $token[1];
            }
        }

        return trim($name, '\\');
    }

    /**
     * A `use` preceded by a closing parenthesis belongs to a closure signature,
     * not to an import. Whitespace between the two is skipped.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function isClosureUse(array $tokens, int $index): bool
    {
        $preceding = array_slice($tokens, max(0, $index - 5), min(5, $index));

        foreach (array_reverse($preceding) as $token) {
            if ($token === ')') {
                return true;
            }
            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            return false;
        }

        return false;
    }

    public static function declaredNamespace(string $file): ?string
    {
        $tokens = token_get_all((string) file_get_contents($file));

        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_NAMESPACE) {
                continue;
            }

            $name = self::readQualifiedName(array_slice($tokens, $i + 1));

            return $name === '' ? null : $name;
        }

        return null;
    }

    /**
     * A symbol counts as "core" when it ships with PHP itself.
     */
    public static function isCoreSymbol(string $fqcn): bool
    {
        // Anything namespaced that is not DavServices\ is by definition third party, except the PHP-internal namespaces.
        if (str_contains($fqcn, '\\')) {
            return str_starts_with($fqcn, 'JetBrains\\PhpStorm\\');
        }

        return class_exists($fqcn, false)
            || interface_exists($fqcn, false)
            || enum_exists($fqcn, false)
            || in_array($fqcn, self::coreClassNames(), true);
    }

    /**
     * @return list<string>
     */
    private static function coreClassNames(): array
    {
        if (self::$coreClassNames === null) {
            self::$coreClassNames = array_values(array_unique(array_merge(
                get_declared_classes(),
                get_declared_interfaces(),
                self::ALWAYS_CORE,
            )));
        }

        return self::$coreClassNames;
    }

    /**
     * @param list<string> $layers
     */
    public static function layerOf(?string $name, array $layers): ?string
    {
        if ($name === null) {
            return null;
        }

        foreach ($layers as $layer) {
            if (str_starts_with($name, 'DavServices\\' . $layer . '\\')
                || $name === 'DavServices\\' . $layer) {
                return $layer;
            }
        }

        return null;
    }

    public static function rel(string $path): string
    {
        $root = realpath(__DIR__ . '/../..');
        $real = realpath($path);

        return $root !== false && $real !== false
            ? ltrim(str_replace($root, '', $real), DIRECTORY_SEPARATOR)
            : $path;
    }

    /**
     * @param list<string> $violations
     */
    public static function report(string $check, array $violations): void
    {
        if ($violations === []) {
            fwrite(STDOUT, sprintf("  OK   %s\n", $check));
            exit(0);
        }

        fwrite(STDERR, sprintf("  FAIL %s — %d violation(s)\n", $check, count($violations)));

        foreach ($violations as $violation) {
            fwrite(STDERR, '       ' . $violation . "\n");
        }

        exit(1);
    }
}
