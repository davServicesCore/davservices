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

namespace DavServices\Tests\Unit\Dav\Locks;

use DavServices\Dav\Locks\LockToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-LOCK-02 and RFC 4918 §6.5.
 *
 * A lock token is the only thing standing between one client's half-written
 * file and another client's write. Whoever holds it may change what is locked,
 * and whoever can **guess** it may do the same — which is why the requirement
 * says "cryptographically random" and why this list checks the shape of what
 * comes out rather than trusting the description.
 *
 * What no test can prove is where the randomness came from: a weak source
 * produces tokens that look exactly like these. That is a matter for the code
 * and for whoever reads it — `random_bytes()`, the one source PHP guarantees
 * is suitable, and nothing else.
 */
#[CoversClass(LockToken::class)]
final class LockTokenTest extends TestCase
{
    /**
     * RFC 4918 §6.5: `opaquelocktoken:` is the scheme a server uses where it
     * has nothing better, and a UUID is what it recommends after it.
     *
     * **Asked of many, not of one.** Every digit but two is random here, so a
     * single token proves nothing about the next: a generator that put the
     * version in the wrong place would look right one time in sixteen, and
     * one sample is a test that agrees with it five times out of six.
     */
    public function testEveryTokenIsAnOpaqueLockTokenWithAUuidAfterIt(): void
    {
        foreach (self::many() as $token) {
            self::assertMatchesRegularExpression(
                '/^opaquelocktoken:[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $token,
            );
        }
    }

    /**
     * RFC 9562 §5.4: the four bits after the second dash say which kind of
     * UUID this is, and version 4 means "made of random". A client that reads
     * the version learns what it may assume about the rest.
     */
    public function testEveryTokenSaysItIsMadeOfRandom(): void
    {
        foreach (self::many() as $token) {
            self::assertSame('4', substr(self::uuidOf($token), 14, 1));
        }
    }

    /**
     * RFC 9562 §4.1: and the two bits after the third dash say which layout
     * the rest follows, which is what `8`, `9`, `a` and `b` are.
     */
    public function testEveryTokenSaysWhichLayoutItFollows(): void
    {
        foreach (self::many() as $token) {
            self::assertContains(substr(self::uuidOf($token), 19, 1), ['8', '9', 'a', 'b']);
        }
    }

    /**
     * And the digits that are not the version or the layout do vary. A
     * generator that held one of them still would be handing out less than it
     * promised, and the promise is the whole of what a lock is worth.
     */
    public function testTheRestOfItIsNotTheSameEveryTime(): void
    {
        $seen = array_map(static fn (string $token): string => self::uuidOf($token), self::many());

        // The dashes sit at 8, 13, 18 and 23; these are digits.
        foreach ([0, 9, 15, 20, 30] as $digit) {
            $atThatDigit = array_unique(array_map(static fn (string $uuid): string => $uuid[$digit], $seen));

            self::assertGreaterThan(1, count($atThatDigit), sprintf('Digit %d never changes.', $digit));
        }
    }

    /**
     * Two locks are never the same lock. A thousand of them is no proof of a
     * good source — a poor one would pass this too — but a source that repeats
     * itself is caught here rather than by whoever loses a file to it.
     */
    public function testNeverMakesTheSameTokenTwice(): void
    {
        $tokens = [];

        for ($made = 0; $made < 1000; $made++) {
            $tokens[LockToken::fresh()] = true;
        }

        self::assertCount(1000, $tokens);
    }

    /**
     * Enough of them that a digit which is right one time in sixteen is not
     * right here.
     *
     * @return list<string>
     */
    private static function many(): array
    {
        $tokens = [];

        for ($made = 0; $made < 200; $made++) {
            $tokens[] = LockToken::fresh();
        }

        return $tokens;
    }

    private static function uuidOf(string $token): string
    {
        return substr($token, strlen('opaquelocktoken:'));
    }
}
