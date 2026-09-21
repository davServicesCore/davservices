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
use DavServices\Dav\Locks\LockDiscovery;
use DavServices\Dav\Locks\LockInfo;
use DavServices\Dav\Locks\LockScope;
use DavServices\Xml\Element;
use DavServices\Xml\Writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 4918 §14.1 and §15.8, for R-LOCK-01.
 *
 * What a client is told about a lock, and the one place it is written. The
 * same element answers a `LOCK` request and the `DAV:lockdiscovery` property
 * of a `PROPFIND`: **two spellings of one thing would be two chances to
 * disagree**, and a client that read a lock one way and refreshed it the other
 * would be refreshing something else.
 *
 * Three parts of it are easy to get subtly wrong, and each costs a client
 * something real.
 *
 * **The timeout is what is left, not what was asked for.** RFC 4918 §10.7
 * counts from now, so a lock taken for an hour half an hour ago has half an
 * hour in it. A server that echoed the original hour would have its clients
 * refresh too late.
 *
 * **The token is an href.** `DAV:locktoken` holds `DAV:href`, and a client
 * reads it to put it in `If` and `Lock-Token` headers; text where an element
 * belongs is a token the client cannot find.
 *
 * **The lock root is where the lock was taken, not where the question was
 * asked.** A deep lock on a collection answers for every path inside it, and
 * §14.12 wants the collection — that is how a client knows which resource to
 * unlock.
 */
#[CoversClass(LockDiscovery::class)]
final class LockDiscoveryTest extends TestCase
{
    private const NOW = '2026-09-21 12:00:00';

    public function testSaysNothingWhereNothingIsHeld(): void
    {
        self::assertStringEndsWith('<d:lockdiscovery xmlns:d="DAV:"/>', $this->written([]));
    }

    /**
     * RFC 4918 §14.1: an `activelock` names the scope, the type, the depth,
     * the timeout, the token and the root. A client uses every one of them.
     */
    public function testDescribesAnExclusiveLock(): void
    {
        $written = $this->written([$this->lock()]);

        self::assertStringContainsString('<d:activelock>', $written);
        self::assertStringContainsString('<d:lockscope><d:exclusive/></d:lockscope>', $written);
        self::assertStringContainsString('<d:locktype><d:write/></d:locktype>', $written);
        self::assertStringContainsString('<d:depth>0</d:depth>', $written);
        self::assertStringContainsString('<d:locktoken><d:href>opaquelocktoken:abc</d:href></d:locktoken>', $written);
        self::assertStringContainsString('<d:lockroot><d:href>/dav/calendars/work.ics</d:href></d:lockroot>', $written);
    }

    /**
     * RFC 4918 §6.1: a shared lock is a different promise from an exclusive
     * one, and a client decides whether to try for one by reading this.
     */
    public function testSaysWhenALockIsShared(): void
    {
        $written = $this->written([$this->lock(scope: LockScope::Shared)]);

        self::assertStringContainsString('<d:lockscope><d:shared/></d:lockscope>', $written);
    }

    /**
     * RFC 4918 §9.10.3: a lock is taken at `Depth: 0` or `Depth: infinity`,
     * and the answer spells the second one out — a client that saw `1` there
     * would be reading a depth no lock can have.
     */
    public function testSaysHowFarADeepLockReaches(): void
    {
        $written = $this->written([$this->lock(deep: true)]);

        self::assertStringContainsString('<d:depth>infinity</d:depth>', $written);
    }

    /**
     * **What is left, counted from now.** A lock taken for an hour with half
     * an hour gone has half an hour in it, and a client refreshes on what it
     * reads here.
     */
    public function testCountsTheTimeoutFromNow(): void
    {
        $written = $this->written([$this->lock(until: '2026-09-21 12:30:00')]);

        self::assertStringContainsString('<d:timeout>Second-1800</d:timeout>', $written);
    }

    /**
     * RFC 4918 §10.7 allows `Infinite`, and a lock that never runs out says
     * so rather than naming a number nobody can count to.
     */
    public function testSaysInfiniteWhereALockNeverRunsOut(): void
    {
        $written = $this->written([$this->lock(until: null)]);

        self::assertStringContainsString('<d:timeout>Infinite</d:timeout>', $written);
    }

    /**
     * A lock whose moment has arrived has nothing left in it. Reporting the
     * seconds as a negative number would have a client compute a refresh time
     * in the past; zero is the truth and the client asks again.
     */
    public function testNeverCountsTheTimeoutBackwards(): void
    {
        $written = $this->written([$this->lock(until: '2026-09-21 11:59:00')]);

        self::assertStringContainsString('<d:timeout>Second-0</d:timeout>', $written);
    }

    /**
     * RFC 4918 §14.17: the owner is whatever the client sent, and it comes
     * back as it went in — that is how a client tells its own lock from
     * somebody else's when several are held.
     */
    public function testHandsTheOwnerBackAsItWasSent(): void
    {
        $owner = new Element('{DAV:}href');

        $owner->appendText('/principals/alice');

        $written = $this->written([$this->lock(owner: $owner)]);

        self::assertStringContainsString('<d:owner><d:href>/principals/alice</d:href></d:owner>', $written);
    }

    public function testHandsBackAnOwnerThatIsPlainText(): void
    {
        $written = $this->written([$this->lock(owner: 'Alice')]);

        self::assertStringContainsString('<d:owner>Alice</d:owner>', $written);
    }

    /**
     * A lock nobody claimed carries no `DAV:owner` at all. An empty one would
     * say that somebody sent nothing, which is a different thing from not
     * having been asked.
     */
    public function testLeavesTheOwnerOutWhereThereIsNone(): void
    {
        self::assertStringNotContainsString('owner', $this->written([$this->lock()]));
    }

    /**
     * RFC 4918 §6.1 again, from the other side: several clients may hold a
     * shared lock at once, and every holder is named. A discovery that
     * reported only the first would make the others invisible.
     */
    public function testNamesEveryLockThatIsHeld(): void
    {
        $written = $this->written([
            $this->lock(scope: LockScope::Shared, token: 'opaquelocktoken:one'),
            $this->lock(scope: LockScope::Shared, token: 'opaquelocktoken:other'),
        ]);

        self::assertStringContainsString('opaquelocktoken:one', $written);
        self::assertStringContainsString('opaquelocktoken:other', $written);
        self::assertSame(2, substr_count($written, '<d:activelock>'));
    }

    /**
     * **The root is where the lock was taken.** A deep lock on a collection
     * answers for every path inside it, and a client reading this has to be
     * told the collection — that is the resource it would unlock.
     */
    public function testNamesTheRootOfTheLockAndNotThePathThatWasAsked(): void
    {
        $lock = new LockInfo('calendars', 'opaquelocktoken:abc', LockScope::Exclusive, true, null, null);

        self::assertStringContainsString('<d:lockroot><d:href>/dav/calendars</d:href></d:lockroot>', $this->written([$lock]));
    }

    /**
     * The whole of what a `LOCK` answers with: `DAV:prop` around the
     * discovery, which is the shape RFC 4918 §9.10.1 asks for.
     */
    public function testWrapsItselfInThePropertyAnswerALockRequestNeeds(): void
    {
        $written = (new Writer())->write(LockDiscovery::asProperty([$this->lock()], $this->href(...), $this->now()));

        self::assertStringContainsString('<d:prop xmlns:d="DAV:"><d:lockdiscovery>', $written);
        self::assertStringContainsString('<d:lockdiscovery><d:activelock>', $written);
        self::assertStringEndsWith('</d:lockdiscovery></d:prop>', $written);
    }

    /**
     * @param list<LockInfo> $locks
     */
    private function written(array $locks): string
    {
        return (new Writer())->write(LockDiscovery::element($locks, $this->href(...), $this->now()));
    }

    /**
     * Where the server is mounted is the server's own business, so it is
     * handed in rather than guessed at.
     */
    private function href(string $path): string
    {
        return '/dav/' . $path;
    }

    private function lock(
        bool $deep = false,
        LockScope $scope = LockScope::Exclusive,
        string $token = 'opaquelocktoken:abc',
        ?string $until = '2026-09-21 13:00:00',
        Element|string|null $owner = null,
    ): LockInfo {
        return new LockInfo(
            'calendars/work.ics',
            $token,
            $scope,
            $deep,
            $owner,
            $until === null ? null : new DateTimeImmutable($until),
        );
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }
}
