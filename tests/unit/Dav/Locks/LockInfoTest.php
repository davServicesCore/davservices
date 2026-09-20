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

use DateTimeImmutable;
use DavServices\Dav\Locks\LockInfo;
use DavServices\Dav\Locks\LockScope;
use DavServices\Xml\Element;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-LOCK-01 (write locks, exclusive and shared, at
 * `Depth: 0` and `infinity`) and R-LOCK-03 (a lock ends).
 *
 * One lock, and the two questions everything else will ask it.
 *
 * **What does it reach?** A lock taken at `Depth: 0` holds one resource; one
 * taken at `infinity` holds everything below its root as well, which is how a
 * client stops a whole calendar from moving under it. The slash matters here
 * as everywhere: `alice2` does not lie below `alice`, and a lock that thought
 * so would refuse writes to somebody else's account.
 *
 * **Is it still there?** A lock ends, and one that has ended is not a lock
 * (R-LOCK-03). The moment is handed in rather than read from the clock, so
 * that the question has an answer a test can check — and so that two parts of
 * one request cannot disagree about what time it is.
 */
#[CoversClass(LockInfo::class)]
#[CoversClass(LockScope::class)]
final class LockInfoTest extends TestCase
{
    public function testKeepsWhatItWasTakenWith(): void
    {
        $until = new DateTimeImmutable('2026-09-20 12:00:00');
        $lock = new LockInfo('calendars/work.ics', 'opaquelocktoken:abc', LockScope::Exclusive, false, null, $until);

        self::assertSame('calendars/work.ics', $lock->root());
        self::assertSame('opaquelocktoken:abc', $lock->token());
        self::assertSame(LockScope::Exclusive, $lock->scope());
        self::assertFalse($lock->isDeep());
        self::assertSame($until, $lock->expiresAt());
    }

    /**
     * RFC 4918 §14.17: the owner is whatever the client sent — a name, an
     * address, a whole document of its own. It comes back as it went in
     * (R-PROP-03), because a client reads its own writing there to tell its
     * own lock from somebody else's.
     */
    public function testKeepsTheOwnerAsTheClientWroteIt(): void
    {
        $owner = new Element('{DAV:}owner');
        $lock = new LockInfo('x', 'opaquelocktoken:abc', LockScope::Exclusive, false, $owner, null);

        self::assertSame($owner, $lock->owner());
    }

    public function testMayBeHeldByNobodyInParticular(): void
    {
        self::assertNull($this->lock()->owner());
    }

    /**
     * R-LOCK-01: a lock at `Depth: 0` holds its own resource and nothing else.
     */
    #[DataProvider('pathsAroundALock')]
    public function testAShallowLockHoldsItsOwnResourceAlone(string $path, bool $held): void
    {
        $lock = new LockInfo('calendars/alice', 'opaquelocktoken:abc', LockScope::Exclusive, false, null, null);

        self::assertSame($path === 'calendars/alice', $lock->covers($path));
    }

    /**
     * And one at `infinity` holds everything below its root as well, which is
     * what a client takes to stop a calendar moving under it.
     */
    #[DataProvider('pathsAroundALock')]
    public function testADeepLockHoldsEverythingBelowIt(string $path, bool $held): void
    {
        $lock = new LockInfo('calendars/alice', 'opaquelocktoken:abc', LockScope::Exclusive, true, null, null);

        self::assertSame($held, $lock->covers($path));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function pathsAroundALock(): iterable
    {
        yield 'the root of the lock itself' => ['calendars/alice', true];
        yield 'a member of it' => ['calendars/alice/work.ics', true];
        yield 'something deeper still' => ['calendars/alice/old/last-year.ics', true];
        yield 'a name that merely begins the same way' => ['calendars/alice2/work.ics', false];
        yield 'exactly that name' => ['calendars/alice2', false];
        yield 'the collection above it' => ['calendars', false];
        yield 'somewhere else entirely' => ['addressbooks/friends.vcf', false];
    }

    /**
     * A lock on the root of the tree reaches everything there is, and the root
     * is spelt as nothing at all.
     */
    public function testADeepLockOnTheRootHoldsTheWholeTree(): void
    {
        $lock = new LockInfo('', 'opaquelocktoken:abc', LockScope::Exclusive, true, null, null);

        self::assertTrue($lock->covers(''));
        self::assertTrue($lock->covers('calendars/alice/work.ics'));
    }

    /**
     * R-LOCK-03: a lock ends, and one that has ended is not a lock. The moment
     * it ends is the first moment it is gone — a lock good "for ten seconds"
     * is not still good at the tenth.
     */
    public function testHasEndedFromTheMomentItRunsOut(): void
    {
        $until = new DateTimeImmutable('2026-09-20 12:00:00');
        $lock = new LockInfo('x', 'opaquelocktoken:abc', LockScope::Exclusive, false, null, $until);

        self::assertFalse($lock->hasExpired(new DateTimeImmutable('2026-09-20 11:59:59')));
        self::assertTrue($lock->hasExpired($until));
        self::assertTrue($lock->hasExpired(new DateTimeImmutable('2026-09-20 12:00:01')));
    }

    /**
     * RFC 4918 §10.7 allows `Timeout: Infinite`, and a lock taken that way
     * never runs out on its own. Whether a server hands one out is its own
     * decision (R-LOCK-03 asks for a maximum); being able to say so is this
     * object's.
     */
    public function testALockWithNoEndNeverRunsOut(): void
    {
        self::assertFalse($this->lock()->hasExpired(new DateTimeImmutable('2099-01-01 00:00:00')));
    }

    private function lock(): LockInfo
    {
        return new LockInfo('x', 'opaquelocktoken:abc', LockScope::Exclusive, false, null, null);
    }
}
