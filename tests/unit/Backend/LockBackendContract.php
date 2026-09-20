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

namespace DavServices\Tests\Unit\Backend;

use DateTimeImmutable;
use DavServices\Backend\ILockBackend;
use DavServices\Dav\Locks\LockInfo;
use DavServices\Dav\Locks\LockScope;
use PHPUnit\Framework\TestCase;

/**
 * What every lock storage has to do, whatever it keeps its locks in
 * (R-LOCK-05).
 *
 * Written once and run against each implementation, because a backend that is
 * exchangeable is only exchangeable if they all behave the same — and a lock
 * that one storage honours and another forgets is worse than no locking at
 * all: the client is told its file is safe.
 *
 * The two questions are not the same question. **What holds this path** is
 * what a write has to get past: the locks taken on it, and the deep ones taken
 * above it. **What lies below this path** is what a new deep lock has to get
 * past, because a collection cannot be locked whole while somebody holds a
 * piece of it.
 *
 * And a lock that has run out is not a lock (R-LOCK-03). Neither question
 * hands one back.
 */
abstract class LockBackendContract extends TestCase
{
    private const NOW = '2026-09-20 12:00:00';

    public function testHoldsNothingAgainstAPathNobodyHasLocked(): void
    {
        self::assertSame([], $this->backend()->locksOn('calendars/work.ics', $this->now()));
    }

    public function testHandsBackALockTakenOnThePathItself(): void
    {
        $backend = $this->backend();
        $lock = $this->lock('calendars/work.ics');

        $backend->set($lock);

        self::assertSame([$lock->token()], $this->tokensOf($backend->locksOn('calendars/work.ics', $this->now())));
    }

    /**
     * R-LOCK-01: a lock taken at `Depth: 0` holds its own resource and nothing
     * else, so a member of it is free.
     */
    public function testAShallowLockHoldsNothingBelowIt(): void
    {
        $backend = $this->backend();

        $backend->set($this->lock('calendars'));

        self::assertSame([], $backend->locksOn('calendars/work.ics', $this->now()));
    }

    /**
     * And one taken at `infinity` holds everything below it, which is the
     * whole reason a client takes one on a collection.
     */
    public function testADeepLockHoldsEverythingBelowIt(): void
    {
        $backend = $this->backend();
        $lock = $this->lock('calendars', deep: true);

        $backend->set($lock);

        self::assertSame([$lock->token()], $this->tokensOf($backend->locksOn('calendars/alice/work.ics', $this->now())));
    }

    /**
     * The slash matters here as everywhere: a lock on `alice` does not hold
     * `alice2`, and a storage that thought so would refuse writes to somebody
     * else's account.
     */
    public function testADeepLockDoesNotReachAPathThatMerelyBeginsTheSameWay(): void
    {
        $backend = $this->backend();

        $backend->set($this->lock('calendars/alice', deep: true));

        self::assertSame([], $backend->locksOn('calendars/alice2/work.ics', $this->now()));
    }

    /**
     * RFC 4918 §6.1: a shared lock is one several clients may hold at once,
     * and every one of them has to come back or the second holder is invisible
     * to the third.
     */
    public function testHandsBackEveryLockThatHoldsAPath(): void
    {
        $backend = $this->backend();
        $one = $this->lock('calendars/work.ics', scope: LockScope::Shared, token: 'opaquelocktoken:one');
        $other = $this->lock('calendars/work.ics', scope: LockScope::Shared, token: 'opaquelocktoken:other');

        $backend->set($one);
        $backend->set($other);

        $tokens = $this->tokensOf($backend->locksOn('calendars/work.ics', $this->now()));

        sort($tokens);

        self::assertSame(['opaquelocktoken:one', 'opaquelocktoken:other'], $tokens);
    }

    public function testFindsALockTakenBelowAPath(): void
    {
        $backend = $this->backend();
        $lock = $this->lock('calendars/alice/work.ics');

        $backend->set($lock);

        self::assertSame([$lock->token()], $this->tokensOf($backend->locksBelow('calendars', $this->now())));
    }

    /**
     * Strictly below: what is asked here is whether anything *inside* the
     * collection is held, and the collection's own lock is the caller's own
     * business.
     */
    public function testWhatLiesBelowAPathDoesNotIncludeThePathItself(): void
    {
        $backend = $this->backend();

        $backend->set($this->lock('calendars', deep: true));

        self::assertSame([], $backend->locksBelow('calendars', $this->now()));
    }

    public function testWhatLiesBelowDoesNotReachAPathThatMerelyBeginsTheSameWay(): void
    {
        $backend = $this->backend();

        $backend->set($this->lock('calendars/alice2/work.ics'));

        self::assertSame([], $backend->locksBelow('calendars/alice', $this->now()));
    }

    public function testEverythingLiesBelowTheRoot(): void
    {
        $backend = $this->backend();
        $lock = $this->lock('calendars/work.ics');

        $backend->set($lock);

        self::assertSame([$lock->token()], $this->tokensOf($backend->locksBelow('', $this->now())));
    }

    /**
     * R-LOCK-03: a lock that has run out is not a lock. A client that was told
     * otherwise would wait for a hold that nobody has any more.
     */
    public function testHandsBackNoLockThatHasRunOut(): void
    {
        $backend = $this->backend();

        $backend->set($this->lock('calendars/work.ics', until: '2026-09-20 11:00:00'));

        self::assertSame([], $backend->locksOn('calendars/work.ics', $this->now()));
        self::assertSame([], $backend->locksBelow('calendars', $this->now()));
    }

    public function testHandsBackALockThatHasNotRunOutYet(): void
    {
        $backend = $this->backend();
        $lock = $this->lock('calendars/work.ics', until: '2026-09-20 13:00:00');

        $backend->set($lock);

        self::assertSame([$lock->token()], $this->tokensOf($backend->locksOn('calendars/work.ics', $this->now())));
    }

    /**
     * RFC 4918 §9.10.2: a refresh is the same lock held until later, and it
     * replaces what was kept rather than standing beside it.
     */
    public function testKeepingALockOfTheSameTokenAgainReplacesIt(): void
    {
        $backend = $this->backend();

        $backend->set($this->lock('calendars/work.ics', until: '2026-09-20 11:00:00'));
        $backend->set($this->lock('calendars/work.ics', until: '2026-09-20 13:00:00'));

        self::assertCount(1, $backend->locksOn('calendars/work.ics', $this->now()));
    }

    public function testDropsALock(): void
    {
        $backend = $this->backend();
        $lock = $this->lock('calendars/work.ics');

        $backend->set($lock);
        $backend->remove($lock);

        self::assertSame([], $backend->locksOn('calendars/work.ics', $this->now()));
    }

    public function testDroppingOneLeavesTheOthersWhereTheyAre(): void
    {
        $backend = $this->backend();
        $one = $this->lock('calendars/work.ics', scope: LockScope::Shared, token: 'opaquelocktoken:one');
        $other = $this->lock('calendars/work.ics', scope: LockScope::Shared, token: 'opaquelocktoken:other');

        $backend->set($one);
        $backend->set($other);
        $backend->remove($one);

        self::assertSame(['opaquelocktoken:other'], $this->tokensOf($backend->locksOn('calendars/work.ics', $this->now())));
    }

    /**
     * An `UNLOCK` of a lock that has already run out is a client being out of
     * date, not an error: what it asked for is the case.
     */
    public function testDroppingOneThatIsNotThereIsNoError(): void
    {
        $backend = $this->backend();

        $backend->remove($this->lock('calendars/work.ics'));

        self::assertSame([], $backend->locksOn('calendars/work.ics', $this->now()));
    }

    /**
     * The storage under test, holding nothing.
     */
    abstract protected function backend(): ILockBackend;

    protected function lock(
        string $root,
        bool $deep = false,
        LockScope $scope = LockScope::Exclusive,
        string $token = 'opaquelocktoken:one',
        ?string $until = null,
    ): LockInfo {
        return new LockInfo(
            $root,
            $token,
            $scope,
            $deep,
            null,
            $until === null ? null : new DateTimeImmutable($until),
        );
    }

    protected function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }

    /**
     * @param list<LockInfo> $locks
     *
     * @return list<string>
     */
    protected function tokensOf(array $locks): array
    {
        return array_map(static fn (LockInfo $lock): string => $lock->token(), $locks);
    }
}
