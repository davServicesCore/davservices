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

namespace DavServices\Tests\Unit\Uri;

use DavServices\Uri\MalformedPath;
use DavServices\Uri\Path;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-HTTP-14 (URI handling lives in one tested utility)
 * and R-TREE-04 (decoded, normalised, secured against directory traversal).
 *
 * Accepted: the root in every spelling, collapsed slashes, decoded escapes,
 * decoding exactly once, a literal plus, UTF-8, unreserved and sub-delimiter
 * characters, a stray percent sign, dot-segment removal, `..` resolution.
 *
 * Rejected: escaping the root, an encoded separator, encoded dot segments,
 * a backslash, a NUL byte, control characters, overlong and truncated UTF-8.
 *
 * Round trip: encode() and normalise() are inverse for awkward names.
 */
#[CoversClass(Path::class)]
#[CoversClass(MalformedPath::class)]
final class PathTest extends TestCase
{
    #[DataProvider('normalisablePaths')]
    public function testNormalisesAPathIntoItsInternalForm(string $raw, string $expected): void
    {
        self::assertSame($expected, Path::normalise($raw));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function normalisablePaths(): iterable
    {
        yield 'a single slash is the root' => ['/', ''];
        yield 'an empty target is the root' => ['', ''];
        yield 'repeated slashes collapse' => ['//a///b//', 'a/b'];
        yield 'leading and trailing slashes are dropped' => ['/calendars/alice/', 'calendars/alice'];
        yield 'a path without a leading slash is already internal' => ['calendars/alice', 'calendars/alice'];
        yield 'a percent escape is decoded' => ['/work%20week', 'work week'];
        yield 'decoding happens exactly once' => ['/100%2525', '100%25'];
        yield 'a plus sign stays literal' => ['/a+b', 'a+b'];
        yield 'utf-8 is decoded' => ['/%C3%9Cbung', "\u{00DC}bung"];
        yield 'unreserved characters survive' => ['/a-b_c.d~e', 'a-b_c.d~e'];
        yield 'sub-delimiters survive' => ['/a!$&,;=:@b', 'a!$&,;=:@b'];
        yield 'a stray percent sign survives' => ['/50%', '50%'];
        yield 'a single dot segment is removed' => ['/a/./b', 'a/b'];
        yield 'a double dot segment is resolved' => ['/a/b/../c', 'a/c'];
        yield 'a trailing double dot segment is resolved' => ['/a/b/..', 'a'];
        yield 'three dots are an ordinary name' => ['/...', '...'];
        yield 'a leading dot is an ordinary name' => ['/.hidden', '.hidden'];
    }

    #[DataProvider('rejectedPaths')]
    public function testRejectsAPathThatCannotBeResolvedSafely(string $raw): void
    {
        $this->expectException(MalformedPath::class);

        Path::normalise($raw);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedPaths(): iterable
    {
        yield 'escaping the root' => ['/..'];
        yield 'escaping the root after descending' => ['/a/../..'];
        yield 'an encoded separator' => ['/a%2Fb'];
        yield 'an encoded separator in lower case' => ['/a%2fb'];
        yield 'an encoded double dot' => ['/a/%2e%2e/b'];
        yield 'an encoded single dot' => ['/a/%2e/b'];
        yield 'a backslash' => ['/a\\b'];
        yield 'an encoded backslash' => ['/a%5Cb'];
        yield 'a nul byte' => ['/a%00b'];
        yield 'a carriage return' => ['/a%0Db'];
        yield 'a line feed' => ['/a%0Ab'];
        yield 'a delete character' => ['/a%7Fb'];
        yield 'an overlong utf-8 dot' => ['/%C0%AE%C0%AE'];
        yield 'an invalid utf-8 byte' => ['/%FF'];
        yield 'a truncated utf-8 sequence' => ['/%C3'];
    }

    /**
     * A double-encoded traversal is the classic path-traversal bug: decode
     * twice and `%252e%252e` turns into `..`. RFC 3986 §2.3 settles it — a
     * percent-encoded dot is data, never a dot segment.
     */
    public function testDoubleEncodedTraversalStaysData(): void
    {
        self::assertSame('a/%2e%2e/b', Path::normalise('/a/%252e%252e/b'));
    }

    #[DataProvider('encodablePaths')]
    public function testEncodesAnInternalPathForAnHref(string $path, string $expected): void
    {
        self::assertSame($expected, Path::encode($path));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function encodablePaths(): iterable
    {
        yield 'the root encodes to nothing' => ['', ''];
        yield 'a space becomes an escape' => ['work week', 'work%20week'];
        yield 'separators are not encoded' => ['a b/c d', 'a%20b/c%20d'];
        yield 'utf-8 becomes escapes' => ["\u{00DC}bung", '%C3%9Cbung'];
        yield 'unreserved characters survive' => ['a-b_c.d~e', 'a-b_c.d~e'];
        yield 'a percent sign is escaped' => ['50%', '50%25'];
        yield 'a question mark is escaped' => ['a?b', 'a%3Fb'];
        yield 'a hash is escaped' => ['a#b', 'a%23b'];
    }

    /**
     * Every name the library accepts must survive the trip out into an href
     * and back in as a request target. Without that, a client following a
     * href we produced would be told the resource does not exist.
     */
    #[DataProvider('awkwardNames')]
    public function testEncodingAndNormalisingAreInverse(string $name): void
    {
        self::assertSame($name, Path::normalise('/' . Path::encode($name)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function awkwardNames(): iterable
    {
        yield 'a space' => ['work week'];
        yield 'utf-8' => ["\u{00DC}bung"];
        yield 'a plus sign' => ['a+b'];
        yield 'a question mark' => ['a?b'];
        yield 'a hash' => ['a#b'];
        yield 'a percent sign' => ['50%'];
        yield 'a semicolon' => ['a;b'];
        yield 'three dots' => ['...'];
    }

    public function testSplitsAPathIntoParentAndName(): void
    {
        self::assertSame(['calendars/alice', 'work.ics'], Path::split('calendars/alice/work.ics'));
    }

    public function testSplitsATopLevelPathIntoTheRootAndAName(): void
    {
        self::assertSame(['', 'alice'], Path::split('alice'));
    }

    /**
     * Splitting is what a bind or an unbind does before it touches the parent
     * collection. The root has no parent, so asking for one is a bug in the
     * caller rather than a question the library should answer with a guess.
     */
    public function testRefusesToSplitTheRoot(): void
    {
        $this->expectException(MalformedPath::class);

        Path::split('');
    }

    public function testJoinsDecodedParts(): void
    {
        self::assertSame('calendars/alice/work.ics', Path::join('calendars/alice', 'work.ics'));
    }

    public function testJoinIgnoresEmptyPartsAndStraySlashes(): void
    {
        self::assertSame('a/b', Path::join('', 'a/', '/b', ''));
    }

    public function testJoiningNothingYieldsTheRoot(): void
    {
        self::assertSame('', Path::join());
    }

    /**
     * Parts reaching join() are already decoded, so a `..` is a member name
     * the caller invented rather than a dot segment the URI syntax asked for.
     */
    public function testJoinRejectsATraversalPart(): void
    {
        $this->expectException(MalformedPath::class);

        Path::join('calendars', '..');
    }

    /**
     * Decoding here would be the second decode of the same input and would
     * turn a legitimate name into a separator.
     */
    public function testJoinDoesNotDecodeItsParts(): void
    {
        self::assertSame('a%2Fb', Path::join('a%2Fb'));
    }

    public function testListsTheSegments(): void
    {
        self::assertSame(['calendars', 'alice', 'work.ics'], Path::segments('calendars/alice/work.ics'));
    }

    public function testTheRootHasNoSegments(): void
    {
        self::assertSame([], Path::segments(''));
    }

    public function testSegmentsRejectAnUnsafeName(): void
    {
        $this->expectException(MalformedPath::class);

        Path::segments("a\x00b");
    }

    /**
     * The message names the offending segment, because a bare "malformed
     * path" in a log leaves the reader guessing which of them it was.
     */
    public function testTheErrorNamesTheOffendingSegment(): void
    {
        $this->expectExceptionMessageMatches('/a\\\\b/');

        Path::normalise('/a%5Cb');
    }

    /**
     * A caller that never heard of this library still catches the SPL type.
     */
    public function testTheErrorIsAnInvalidArgumentException(): void
    {
        self::assertInstanceOf(InvalidArgumentException::class, new MalformedPath('boom'));
    }
    /**
     * The slash is the whole of it. A prefix comparison without the separator
     * between the two is the same mistake every time: a tree that forgets the
     * wrong node, a store that deletes somebody else'''s properties, a lock
     * that refuses writes to somebody else'''s account.
     */
    #[DataProvider('pathsAndPrefixes')]
    public function testSaysWhetherAPathLiesAtOrBelowAnother(string $path, string $prefix, bool $below): void
    {
        self::assertSame($below, Path::isBelow($path, $prefix));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function pathsAndPrefixes(): iterable
    {
        yield 'the same path' => ['calendars/alice', 'calendars/alice', true];
        yield 'a member of it' => ['calendars/alice/work.ics', 'calendars/alice', true];
        yield 'something deeper still' => ['calendars/alice/old/last.ics', 'calendars/alice', true];
        yield 'a name that merely begins the same way' => ['calendars/alice2', 'calendars/alice', false];
        yield 'a member of such a name' => ['calendars/alice2/work.ics', 'calendars/alice', false];
        yield 'the collection above it' => ['calendars', 'calendars/alice', false];
        yield 'somewhere else entirely' => ['addressbooks/friends.vcf', 'calendars/alice', false];
        yield 'everything lies below the root' => ['calendars/alice', '', true];
        yield 'the root lies below itself' => ['', '', true];
        yield 'the root lies below nothing else' => ['', 'calendars', false];
    }

}
