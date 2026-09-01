<?php

/*
 * This file is part of davServices.
 *
 * (c) Felix Böck <https://dav.services>
 *
 * Licensed under the Apache License, Version 2.0.
 * For the full copyright and license information, see the LICENSE file.
 */

declare(strict_types=1);

require __DIR__ . '/lib/Scanner.php';

/** Class-like declarations that need documenting. */
const CLASS_LIKE = [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM];

/** Constructors are documented by their class; see the file DocBlock. */
const EXEMPT_METHODS = ['__construct'];

/**
 * Does the DocBlock carry prose, or only annotations?
 */
function hasProse(string $docBlock): bool
{
    foreach (explode("\n", $docBlock) as $line) {
        $line = trim($line, " \t*/");

        if ($line === '' || str_starts_with($line, '@') || str_starts_with($line, '*')) {
            continue;
        }

        return true;
    }

    return false;
}

/**
 * The DocBlock immediately preceding a token, ignoring whitespace, attributes
 * and modifiers such as `final`, `abstract`, `public` or `static`.
 *
 * @param list<array{int, string, int}|string> $tokens
 */
function precedingDocBlock(array $tokens, int $index): ?string
{
    $skip = [
        T_WHITESPACE, T_FINAL, T_ABSTRACT, T_PUBLIC, T_PROTECTED,
        T_PRIVATE, T_STATIC, T_READONLY, T_ATTRIBUTE,
    ];

    foreach (array_reverse(array_slice($tokens, 0, $index)) as $token) {
        if (!is_array($token)) {
            // An attribute closes with `]`; keep walking past it.
            if ($token === ']') {
                continue;
            }

            return null;
        }
        if ($token[0] === T_DOC_COMMENT) {
            return $token[1];
        }
        if (in_array($token[0], $skip, true)) {
            continue;
        }

        return null;
    }

    return null;
}

/**
 * Is this `class` keyword a real declaration, rather than `Foo::class` or an
 * anonymous `new class`?
 *
 * @param list<array{int, string, int}|string> $tokens
 */
function isDeclaration(array $tokens, int $index): bool
{
    foreach (array_reverse(array_slice($tokens, 0, $index)) as $token) {
        if (is_array($token) && $token[0] === T_WHITESPACE) {
            continue;
        }

        return !is_array($token) || !in_array($token[0], [T_DOUBLE_COLON, T_NEW], true);
    }

    return true;
}

/**
 * Token index ranges covered by anonymous class bodies.
 *
 * An anonymous class has no name, so there is nothing to document and nothing
 * a reader could look up — the same reasoning applies to the methods inside it.
 * Skipping the declaration but demanding DocBlocks on its methods would be an
 * odd half-measure, so the whole body is excluded.
 *
 * @param list<array{int, string, int}|string> $tokens
 *
 * @return list<array{int, int}>
 */
function anonymousClassRanges(array $tokens): array
{
    $ranges = [];

    foreach ($tokens as $i => $token) {
        if (!is_array($token) || $token[0] !== T_CLASS || isDeclaration($tokens, $i)) {
            continue;
        }

        // Walk past the constructor arguments to the body's opening brace.
        // Arguments may themselves contain braces, so parentheses are counted.
        $parentheses = 0;
        $start = null;

        foreach (array_slice($tokens, $i) as $offset => $current) {
            if ($current === '(') {
                $parentheses++;
            } elseif ($current === ')') {
                $parentheses--;
            } elseif ($current === '{' && $parentheses === 0) {
                $start = $i + $offset;
                break;
            }
        }

        if ($start === null) {
            continue;
        }

        $depth = 0;

        foreach (array_slice($tokens, $start) as $offset => $current) {
            if ($current === '{' || (is_array($current) && in_array($current[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($current === '}') {
                $depth--;

                if ($depth === 0) {
                    $ranges[] = [$start, $start + $offset];
                    break;
                }
            }
        }
    }

    return $ranges;
}

/**
 * @param list<array{int, int}> $ranges
 */
function withinAnyRange(int $index, array $ranges): bool
{
    foreach ($ranges as [$from, $to]) {
        if ($index > $from && $index < $to) {
            return true;
        }
    }

    return false;
}

/**
 * The name following a declaration keyword.
 *
 * @param list<array{int, string, int}|string> $tokens
 */
function declaredName(array $tokens, int $index): string
{
    foreach (array_slice($tokens, $index + 1) as $token) {
        if (is_array($token) && $token[0] === T_STRING) {
            return $token[1];
        }
        if (is_array($token) && $token[0] === T_WHITESPACE) {
            continue;
        }

        return '';
    }

    return '';
}

$violations = [];

foreach (Scanner::productionFiles() as $file) {
    $tokens = token_get_all((string) file_get_contents($file));
    $anonymous = anonymousClassRanges($tokens);
    $visibility = null;
    $inClass = false;

    foreach ($tokens as $i => $token) {
        if (!is_array($token) || withinAnyRange($i, $anonymous)) {
            continue;
        }

        if (in_array($token[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE], true)) {
            $visibility = $token[0];
            continue;
        }

        if (in_array($token[0], CLASS_LIKE, true) && isDeclaration($tokens, $i)) {
            $inClass = true;
            $visibility = null;
            $name = declaredName($tokens, $i);
            $doc = precedingDocBlock($tokens, $i);

            if ($doc === null) {
                $violations[] = sprintf('%s:%d  %s has no DocBlock', Scanner::rel($file), $token[2], $name);
            } elseif (!hasProse($doc)) {
                $violations[] = sprintf('%s:%d  %s has a DocBlock without prose', Scanner::rel($file), $token[2], $name);
            }

            continue;
        }

        if ($token[0] !== T_FUNCTION) {
            continue;
        }

        // Methods without an explicit modifier are public; plain functions
        // outside a class body are not methods at all.
        $isPublic = $visibility === T_PUBLIC || ($visibility === null && $inClass);
        $name = declaredName($tokens, $i);
        $visibility = null;

        if (!$isPublic || $name === '' || in_array($name, EXEMPT_METHODS, true)) {
            continue;
        }

        $doc = precedingDocBlock($tokens, $i);

        if ($doc === null) {
            $violations[] = sprintf('%s:%d  %s() has no DocBlock', Scanner::rel($file), $token[2], $name);
        } elseif (!hasProse($doc)) {
            $violations[] = sprintf('%s:%d  %s() has a DocBlock without prose', Scanner::rel($file), $token[2], $name);
        }
    }
}

Scanner::report('DocBlocks on the public API', $violations);
