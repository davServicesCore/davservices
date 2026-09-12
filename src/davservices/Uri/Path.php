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

namespace DavServices\Uri;

/**
 * Conversion between a request target and the library's internal path form.
 *
 * The internal form is decoded, carries no leading or trailing slash, and
 * spells the root as the empty string: `calendars/alice/work week.ics`. Every
 * path entering the library passes through normalise(); every path leaving it
 * in a `DAV:href` passes through encode().
 *
 * The order of the two operations is what makes traversal impossible. A target
 * is cut into segments on `/` first and decoded afterwards, exactly once
 * (RFC 3986 §3.3 for the cut, §2.3 for what a percent-escape means). A `%2F`
 * therefore never becomes a separator and a `%2e%2e` never becomes a dot
 * segment — both decode into an ordinary name, which is then refused because
 * no name may contain a separator or consist of dots alone.
 */
final class Path
{
    /**
     * Turns a request target into the internal path form.
     *
     * @throws MalformedPath If the target escapes the root or decodes into a
     *                       name the library cannot address safely
     */
    public static function normalise(string $target): string
    {
        $names = [];

        foreach (self::removeDotSegments(self::splitOnSlash($target)) as $segment) {
            $names[] = self::checked(rawurldecode($segment));
        }

        return implode('/', $names);
    }

    /**
     * Percent-encodes an internal path for use in a `DAV:href`.
     *
     * Separators stay separators; everything a segment contains beyond the
     * unreserved characters of RFC 3986 §2.3 is escaped.
     *
     * @throws MalformedPath If the path holds a name that may not be addressed
     */
    public static function encode(string $path): string
    {
        $encoded = [];

        foreach (self::segments($path) as $segment) {
            $encoded[] = rawurlencode($segment);
        }

        return implode('/', $encoded);
    }

    /**
     * The individual names of an internal path.
     *
     * @throws MalformedPath If the path holds a name that may not be addressed
     *
     * @return list<string> Empty for the root
     */
    public static function segments(string $path): array
    {
        $names = [];

        foreach (self::splitOnSlash($path) as $segment) {
            $names[] = self::checked($segment);
        }

        return $names;
    }

    /**
     * Splits an internal path into the path of its parent and its own name.
     *
     * `calendars/alice/work.ics` becomes `['calendars/alice', 'work.ics']`.
     *
     * @throws MalformedPath If the path is the root, which has no parent
     *
     * @return array{string, string}
     */
    public static function split(string $path): array
    {
        $names = self::segments($path);
        $own = array_pop($names);

        if ($own === null) {
            throw new MalformedPath('The root has no parent collection to split off.');
        }

        return [implode('/', $names), $own];
    }

    /**
     * Joins already decoded parts into an internal path.
     *
     * The parts are not decoded: they have been through normalise() before, and
     * a second decode is precisely the bug that turns `%2F` into a separator.
     *
     * @throws MalformedPath If a part holds a name that may not be addressed
     */
    public static function join(string ...$parts): string
    {
        $names = [];

        foreach ($parts as $part) {
            foreach (self::segments($part) as $segment) {
                $names[] = $segment;
            }
        }

        return implode('/', $names);
    }

    /**
     * Cuts a path into its non-empty segments.
     *
     * Dropping the empty ones is what collapses `//`, the leading slash and the
     * trailing slash that clients append to collections.
     *
     * @return list<string>
     */
    private static function splitOnSlash(string $path): array
    {
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment !== '') {
                $segments[] = $segment;
            }
        }

        return $segments;
    }

    /**
     * Resolves `.` and `..` segments, as RFC 3986 §5.2.4 prescribes.
     *
     * Unlike that algorithm, a `..` that would climb past the root is refused
     * rather than silently dropped: at the root there is nothing above to hide,
     * so such a target is either a broken client or an attempt, and both are
     * better answered with a `400` than with a guess.
     *
     * @param list<string> $segments Still encoded, as percent-escapes are data
     *                               rather than dot segments
     *
     * @throws MalformedPath If the target climbs above the root
     *
     * @return list<string>
     */
    private static function removeDotSegments(array $segments): array
    {
        $resolved = [];

        foreach ($segments as $segment) {
            if ($segment === '.') {
                continue;
            }

            if ($segment !== '..') {
                $resolved[] = $segment;
                continue;
            }

            if ($resolved === []) {
                throw new MalformedPath('The target climbs above the root of the server.');
            }

            array_pop($resolved);
        }

        return $resolved;
    }

    /**
     * Refuses a decoded name the library cannot address safely.
     *
     * @throws MalformedPath
     */
    private static function checked(string $segment): string
    {
        if ($segment === '.' || $segment === '..') {
            throw new MalformedPath(sprintf('"%s" is a dot segment rather than a name.', $segment));
        }

        // A backslash is a separator to every Windows file system, and a
        // decoded slash would silently add a level to the path.
        if (strpbrk($segment, '/\\') !== false) {
            throw new MalformedPath(sprintf('"%s" contains a path separator.', $segment));
        }

        // Control characters end up in response headers and in file names.
        if (preg_match('/[\x00-\x1F\x7F]/', $segment) === 1) {
            throw new MalformedPath(sprintf('"%s" contains a control character.', rawurlencode($segment)));
        }

        // Overlong sequences are the Unicode variant of the traversal trick:
        // %C0%AE decodes to a dot on a lenient reader and to nothing here.
        if (!mb_check_encoding($segment, 'UTF-8')) {
            throw new MalformedPath(sprintf('"%s" is not valid UTF-8.', rawurlencode($segment)));
        }

        return $segment;
    }
}
