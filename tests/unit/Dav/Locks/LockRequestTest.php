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

use DavServices\Dav\Locks\LockRequest;
use DavServices\Dav\Locks\LockScope;
use DavServices\Exception\BadRequest;
use DavServices\Http\Headers;
use DavServices\Xml\Element;
use DavServices\Xml\Reader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 4918 §9.10 and §10.7, for R-LOCK-01 and
 * R-LOCK-03.
 *
 * What a client asked for, taken apart in one place: the `DAV:lockinfo` body,
 * the `Depth` header and the `Timeout` header. They arrive separately and mean
 * one thing together, and the part that decides what to do with it should not
 * have to know three grammars.
 *
 * **The two headers are not read alike, and the difference is the point.**
 * `Depth` says how far the lock reaches, so a value the server does not
 * understand is refused: a lock that held less than the client believes is
 * worse than no lock. `Timeout` is a wish — RFC 4918 §10.7 lets a server
 * ignore it entirely — so rubbish there costs the client its preference and
 * nothing more.
 *
 * A missing `Depth` means `infinity` (§9.10.3). That is the RFC's choice and
 * not an obvious one, so it is written down here.
 */
#[CoversClass(LockRequest::class)]
final class LockRequestTest extends TestCase
{
    public function testReadsAnExclusiveWriteLock(): void
    {
        $asked = $this->read('
            <D:lockinfo xmlns:D="DAV:">
                <D:lockscope><D:exclusive/></D:lockscope>
                <D:locktype><D:write/></D:locktype>
            </D:lockinfo>
        ');

        self::assertSame(LockScope::Exclusive, $asked->scope());
        self::assertNull($asked->owner());
    }

    /**
     * RFC 4918 §6.1: a shared lock is one several clients may hold at once,
     * and a server that read every request as exclusive would refuse the
     * second holder a lock it is entitled to.
     */
    public function testReadsASharedWriteLock(): void
    {
        $asked = $this->read('
            <D:lockinfo xmlns:D="DAV:">
                <D:lockscope><D:shared/></D:lockscope>
                <D:locktype><D:write/></D:locktype>
            </D:lockinfo>
        ');

        self::assertSame(LockScope::Shared, $asked->scope());
    }

    /**
     * RFC 4918 §14.17: the owner is whatever the client sent — a name, an
     * address, a document of its own — and it is kept as it came, because the
     * client reads its own writing back to tell its lock from somebody else's.
     */
    public function testKeepsTheOwnerAsTheClientWroteIt(): void
    {
        $asked = $this->read('
            <D:lockinfo xmlns:D="DAV:">
                <D:lockscope><D:exclusive/></D:lockscope>
                <D:locktype><D:write/></D:locktype>
                <D:owner><D:href>/principals/alice</D:href></D:owner>
            </D:lockinfo>
        ');

        $owner = $asked->owner();

        self::assertInstanceOf(Element::class, $owner);
        self::assertSame('{DAV:}href', $owner->name());
        self::assertSame('/principals/alice', $owner->text());
    }

    public function testKeepsAnOwnerThatIsPlainText(): void
    {
        $asked = $this->read('
            <D:lockinfo xmlns:D="DAV:">
                <D:lockscope><D:exclusive/></D:lockscope>
                <D:locktype><D:write/></D:locktype>
                <D:owner>Alice</D:owner>
            </D:lockinfo>
        ');

        self::assertSame('Alice', $asked->owner());
    }

    /**
     * RFC 4918 §9.10.3: no `Depth` at all means `infinity`. A server that
     * assumed `0` would hand out a lock that holds one resource where the
     * client believes it holds a whole collection.
     */
    public function testALockWithNoDepthReachesEverythingBelowIt(): void
    {
        self::assertTrue($this->read($this->lockinfo())->isDeep());
    }

    public function testALockAtDepthZeroHoldsItsOwnResourceAlone(): void
    {
        self::assertFalse($this->read($this->lockinfo(), ['Depth' => '0'])->isDeep());
    }

    public function testALockAtDepthInfinityReachesEverythingBelowIt(): void
    {
        self::assertTrue($this->read($this->lockinfo(), ['Depth' => 'infinity'])->isDeep());
    }

    public function testReadsTheDepthWhateverItIsSpeltLike(): void
    {
        self::assertTrue($this->read($this->lockinfo(), ['Depth' => 'Infinity'])->isDeep());
    }

    /**
     * RFC 4918 §9.10.3: values other than `0` and `infinity` must not be used
     * with `LOCK`. **This one is refused rather than guessed at** — a lock
     * that held less than the client was told is worse than no lock at all.
     */
    #[DataProvider('depthsNoLockCanHave')]
    public function testRefusesADepthNoLockCanHave(string $depth): void
    {
        $this->expectException(BadRequest::class);

        $this->read($this->lockinfo(), ['Depth' => $depth]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function depthsNoLockCanHave(): iterable
    {
        yield 'one, which only PROPFIND has' => ['1'];
        yield 'a number nobody asked for' => ['2'];
        yield 'nothing at all' => [''];
        yield 'something else entirely' => ['deep'];
    }

    /**
     * RFC 4918 §10.7: `Second-<n>` is how long the client would like to hold
     * it. What the server makes of that is the server's decision.
     */
    public function testReadsHowLongTheClientWouldLikeToHoldIt(): void
    {
        self::assertSame(600, $this->read($this->lockinfo(), ['Timeout' => 'Second-600'])->seconds());
    }

    /**
     * `Infinite` and no header at all come to the same thing here: the client
     * has named no limit of its own, and the server's maximum decides.
     */
    public function testALockWithNoLimitOfItsOwnAsksForNothingInParticular(): void
    {
        self::assertNull($this->read($this->lockinfo(), ['Timeout' => 'Infinite'])->seconds());
        self::assertNull($this->read($this->lockinfo())->seconds());
    }

    /**
     * RFC 4918 §10.7: the header is a list of preferences, best first. The
     * first one that can be read is the one that counts.
     */
    public function testTakesTheFirstPreferenceItCanRead(): void
    {
        self::assertSame(120, $this->read($this->lockinfo(), ['Timeout' => 'Second-120, Second-60'])->seconds());
    }

    /**
     * **The RFC's own example is `Infinite, Second-4100000000`**, so the one
     * that counts is often not the first. Every preference after a comma
     * arrives with the space that separated them, and a reader that did not
     * account for it would answer every such header with its own maximum.
     */
    public function testReadsAPreferenceThatFollowsOneItCannotHonour(): void
    {
        self::assertSame(60, $this->read($this->lockinfo(), ['Timeout' => 'Infinite, Second-60'])->seconds());
    }

    /**
     * **A timeout is a wish, not a condition.** RFC 4918 §10.7 lets a server
     * ignore the header altogether, so one it cannot read costs the client its
     * preference and nothing more. Refusing the lock over it would help
     * nobody: the client wants the lock, not the number.
     */
    #[DataProvider('timeoutsNobodyCanRead')]
    public function testFallsBackToTheServersOwnLimitWhereTheHeaderMakesNoSense(string $timeout): void
    {
        self::assertNull($this->read($this->lockinfo(), ['Timeout' => $timeout])->seconds());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function timeoutsNobodyCanRead(): iterable
    {
        yield 'a unit this does not know' => ['Minute-5'];
        yield 'seconds without a number' => ['Second-'];
        yield 'a number that is not one' => ['Second-many'];
        yield 'a negative stretch of time' => ['Second--1'];
        yield 'nothing at all' => [''];
    }

    /**
     * A body that is not a `DAV:lockinfo` is a request this cannot answer, and
     * `400` says so. Reading it as a default lock would hand out a hold the
     * client never asked for.
     */
    public function testRefusesABodyThatIsNoLockInfo(): void
    {
        $this->expectException(BadRequest::class);

        $this->read('<D:propfind xmlns:D="DAV:"><D:allprop/></D:propfind>');
    }

    /**
     * **And one that carries the right parts under the wrong name.** A body
     * this server cannot name is a request it has not understood, whatever
     * else is inside it; reading the pieces anyway would hand out a lock from
     * a document that meant something else entirely.
     */
    public function testRefusesABodyThatIsNoLockInfoEvenWhereItHoldsTheRightParts(): void
    {
        $this->expectException(BadRequest::class);

        $this->read('
            <D:somethingelse xmlns:D="DAV:">
                <D:lockscope><D:exclusive/></D:lockscope>
                <D:locktype><D:write/></D:locktype>
            </D:somethingelse>
        ');
    }

    /**
     * An empty `DAV:locktype` names no kind of lock at all, which is as
     * unanswerable as naming one this server does not take.
     */
    public function testRefusesALockTypeThatIsEmpty(): void
    {
        $this->expectException(BadRequest::class);

        $this->read('
            <D:lockinfo xmlns:D="DAV:">
                <D:lockscope><D:exclusive/></D:lockscope>
                <D:locktype/>
            </D:lockinfo>
        ');
    }

    /**
     * RFC 4918 §7: write is the only lock type there is. A request for
     * another kind is refused rather than quietly answered with a write lock,
     * which is not what was asked for.
     */
    public function testRefusesALockThatIsNotAWriteLock(): void
    {
        $this->expectException(BadRequest::class);

        $this->read('
            <D:lockinfo xmlns:D="DAV:">
                <D:lockscope><D:exclusive/></D:lockscope>
                <D:locktype><D:read/></D:locktype>
            </D:lockinfo>
        ');
    }

    /**
     * A scope of neither kind cannot be answered either: exclusive and shared
     * are different promises, and neither may be handed out on a guess.
     */
    public function testRefusesALockOfNeitherScope(): void
    {
        $this->expectException(BadRequest::class);

        $this->read('
            <D:lockinfo xmlns:D="DAV:">
                <D:lockscope><D:somehow/></D:lockscope>
                <D:locktype><D:write/></D:locktype>
            </D:lockinfo>
        ');
    }

    /**
     * A write lock with no scope at all is as unanswerable as one of a scope
     * this does not know: exclusive and shared are different promises.
     */
    public function testRefusesALockThatNamesNoScope(): void
    {
        $this->expectException(BadRequest::class);

        $this->read('
            <D:lockinfo xmlns:D="DAV:">
                <D:locktype><D:write/></D:locktype>
            </D:lockinfo>
        ');
    }

    public function testRefusesALockInfoThatSaysNothingAtAll(): void
    {
        $this->expectException(BadRequest::class);

        $this->read('<D:lockinfo xmlns:D="DAV:"/>');
    }

    /**
     * A refresh carries no body at all, so the timeout is read on its own —
     * from the same place and by the same rules, because a grammar written
     * twice is a grammar that will be read two ways.
     */
    public function testReadsTheTimeoutOfARefreshWithoutABody(): void
    {
        self::assertSame(300, LockRequest::secondsAsked(new Headers(['Timeout' => 'Second-300'])));
        self::assertNull(LockRequest::secondsAsked(new Headers()));
    }

    /**
     * @param array<string, string> $headers
     */
    private function read(string $xml, array $headers = []): LockRequest
    {
        return LockRequest::read((new Reader())->parse(trim($xml)), new Headers($headers));
    }

    private function lockinfo(): string
    {
        return '
            <D:lockinfo xmlns:D="DAV:">
                <D:lockscope><D:exclusive/></D:lockscope>
                <D:locktype><D:write/></D:locktype>
            </D:lockinfo>
        ';
    }
}
