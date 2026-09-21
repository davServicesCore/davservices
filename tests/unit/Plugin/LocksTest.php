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

namespace DavServices\Tests\Unit\Plugin;

use DateTimeImmutable;
use DavServices\Dav\Event\AfterBind;
use DavServices\Dav\Event\AfterCreateFile;
use DavServices\Dav\Event\BeforeBind;
use DavServices\Dav\Event\OptionsRequested;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\Locks\LockInfo;
use DavServices\Dav\Locks\LockScope;
use DavServices\Dav\Method\Options;
use DavServices\Dav\Method\PropFind;
use DavServices\Dav\PropFindForm;
use DavServices\Dav\PropFindResult;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Conflict;
use DavServices\Exception\Locked;
use DavServices\Exception\PreconditionFailed;
use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Plugin\Locks;
use DavServices\Tests\Unit\Backend\MemoryLockBackend;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Xml\Element;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-LOCK-01 (write locks, exclusive and shared, at
 * `Depth: 0` and `infinity`), R-LOCK-03 (timeouts with a maximum, and locks
 * that end) and R-LOCK-06 (a server without this plugin goes on working).
 *
 * This is where a lock stops being a stored object and becomes a promise to a
 * client. Four things are decided here, and each of them can be got wrong in a
 * way that is silent.
 *
 * **Who may take a lock.** Two locks conflict when at least one of them is
 * exclusive; two shared ones never do (RFC 4918 §6.1). A deep lock has to
 * clear everything below it as well, because a collection cannot be held whole
 * while somebody holds a piece of it. Getting this wrong hands out a lock that
 * does not hold, and the client is told it is safe.
 *
 * **How long it lasts.** The client asks, the server decides, and the maximum
 * is the server's (R-LOCK-03). A lock that never ends is a lock nobody can
 * clear after a client has crashed, so `Infinite` is answered with the
 * maximum rather than with forever.
 *
 * **What a refresh is.** RFC 4918 §9.10.2: a `LOCK` with no body, naming the
 * lock in the `If` header. It is the same lock held until later — and a
 * server that handed out a *new* lock instead would leave the old one
 * standing, held by nobody, until it ran out.
 *
 * **Where a lock is released.** `UNLOCK` names the token, and the token has
 * to be one held at that path. A server that dropped any lock whose token it
 * was shown would let a client unlock somebody else's resource by accident.
 *
 * The first test of this file is the one that keeps the plugin honest about
 * being a plugin: **a server without it answers `501`**, and says so in its
 * `DAV` header (R-LOCK-06, which P3-05 finishes).
 */
#[CoversClass(Locks::class)]
final class LocksTest extends TestCase
{
    private const NOW = '2026-09-21 12:00:00';

    private const TOKEN = 'opaquelocktoken:held';

    /**
     * R-LOCK-06: locking is a plugin, and a server built without it is a
     * plain WebDAV server rather than a broken one.
     */
    public function testAServerWithoutThePluginDoesNotLock(): void
    {
        $server = new Server(new Tree($this->tree()));

        self::assertSame(501, $server->handle(new Request('LOCK', '/calendars/work.ics'))->status());
    }

    /**
     * RFC 4918 §18.2: a server that takes write locks is of compliance class
     * 2, and `OPTIONS` is where a client finds out. A server that kept quiet
     * about it would be asked for locks by nobody.
     */
    public function testAServerWithThePluginSaysItIsOfClassTwo(): void
    {
        $server = $this->server();
        $options = new Options($server);

        $server->onMethod('OPTIONS', $options(...));

        $response = $server->handle(new Request('OPTIONS', '/'));

        self::assertSame('1, 2', $response->headers()->first('DAV'));
        self::assertStringContainsString('LOCK', $response->headers()->first('Allow') ?? '');
        self::assertStringContainsString('UNLOCK', $response->headers()->first('Allow') ?? '');
    }

    public function testTakesALockOnAFile(): void
    {
        $response = $this->lock('/calendars/work.ics');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('<d:activelock>', $this->bodyOf($response));
    }

    /**
     * RFC 4918 §9.10: the token goes in the `Lock-Token` header, in angle
     * brackets, and it is the same token the body names. A client reads one
     * or the other and has to arrive at the same lock.
     */
    public function testHandsTheTokenOverInTheHeaderAndTheBodyAlike(): void
    {
        $response = $this->lock('/calendars/work.ics');
        $header = $response->headers()->first('Lock-Token') ?? '';

        self::assertMatchesRegularExpression('/^<opaquelocktoken:[0-9a-f-]{36}>$/', $header);
        self::assertStringContainsString(trim($header, '<>'), $this->bodyOf($response));
    }

    public function testKeepsTheLockWhereTheNextRequestWillFindIt(): void
    {
        $backend = new MemoryLockBackend();

        $this->lock('/calendars/work.ics', backend: $backend);

        self::assertCount(1, $backend->locksOn('calendars/work.ics', $this->now()));
    }

    /**
     * R-LOCK-01: a lock at `Depth: 0` holds one resource; the default reaches
     * everything below (RFC 4918 §9.10.3).
     */
    public function testTakesTheLockAsDeepAsItWasAskedFor(): void
    {
        $backend = new MemoryLockBackend();

        $this->lock('/calendars', headers: ['Depth' => '0'], backend: $backend);

        self::assertFalse($this->heldOn($backend, 'calendars')->isDeep());

        $deep = new MemoryLockBackend();

        $this->lock('/calendars', backend: $deep);

        self::assertTrue($this->heldOn($deep, 'calendars')->isDeep());
    }

    public function testKeepsTheOwnerTheClientNamed(): void
    {
        $backend = new MemoryLockBackend();

        $this->lock('/calendars/work.ics', body: $this->lockinfo(owner: '<D:href>/principals/alice</D:href>'), backend: $backend);

        $owner = $this->heldOn($backend, 'calendars/work.ics')->owner();

        self::assertInstanceOf(Element::class, $owner);
        self::assertSame('/principals/alice', $owner->text());
    }

    /**
     * R-LOCK-03: the client asks and the server decides. What it decided is
     * in the answer, because the client refreshes on what it reads there.
     */
    public function testHonoursATimeoutTheClientAsksFor(): void
    {
        $response = $this->lock('/calendars/work.ics', headers: ['Timeout' => 'Second-600']);

        self::assertStringContainsString('<d:timeout>Second-600</d:timeout>', $this->bodyOf($response));
    }

    /**
     * **The maximum is the server's, and it is not a suggestion.** A client
     * that asked for a week gets the maximum, and is told so.
     */
    public function testCutsATimeoutDownToTheServersMaximum(): void
    {
        $response = $this->lock('/calendars/work.ics', headers: ['Timeout' => 'Second-604800']);

        self::assertStringContainsString('<d:timeout>Second-3600</d:timeout>', $this->bodyOf($response));
    }

    /**
     * A lock that never ends is one nobody can clear after a client has
     * crashed. `Infinite` and no preference at all both get the maximum.
     */
    #[DataProvider('requestsWithNoLimitOfTheirOwn')]
    public function testALockAlwaysEnds(?string $timeout): void
    {
        $response = $this->lock('/calendars/work.ics', headers: $timeout === null ? [] : ['Timeout' => $timeout]);

        self::assertStringContainsString('<d:timeout>Second-3600</d:timeout>', $this->bodyOf($response));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function requestsWithNoLimitOfTheirOwn(): iterable
    {
        yield 'no header at all' => [null];
        yield 'a wish for forever' => ['Infinite'];
    }

    /**
     * A maximum of nothing would hand out locks that have run out by the time
     * the client reads about them. It is refused where it is set, not puzzled
     * over later.
     */
    public function testRefusesAMaximumThatIsNoTime(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Locks(new Server(new Tree($this->tree())), new MemoryLockBackend(), 0);
    }

    /**
     * RFC 4918 §9.10.4: a `LOCK` on a path that holds nothing creates an
     * empty resource and answers `201`. That is how a client reserves a name
     * before it uploads to it.
     */
    public function testALockOnAnEmptyPathCreatesTheResource(): void
    {
        $root = $this->tree();

        $response = $this->plugin(root: $root)->lock($this->lockRequest('/calendars/new.ics'));

        self::assertSame(201, $response->status());

        $calendars = $root->child('calendars');

        self::assertInstanceOf(MemoryCollection::class, $calendars);
        self::assertTrue($calendars->hasChild('new.ics'));
    }

    /**
     * **A resource this created is a resource like any other**, so the seams
     * every other creation passes through are passed through here too: a
     * plugin that keeps properties, or counts what is bound where, would
     * otherwise never hear of a file that a `LOCK` brought into being.
     */
    public function testACreatedResourceIsAnnouncedLikeAnyOther(): void
    {
        $heard = [];
        $events = new EventEmitter();

        foreach ([BeforeBind::class, AfterCreateFile::class, AfterBind::class] as $event) {
            $events->on($event, static function (object $raised) use (&$heard): void {
                $heard[] = $raised::class;
            });
        }

        $server = new Server(new Tree($this->tree()), $events);

        $this->pluginOn($server)->lock($this->lockRequest('/calendars/new.ics'));

        self::assertSame([BeforeBind::class, AfterCreateFile::class, AfterBind::class], $heard);
    }

    /**
     * And the tree is told to forget the collection it went into, or the next
     * request would be answered from a listing taken before the file existed.
     * The proof is that the backend is asked again.
     */
    public function testTheTreeForgetsTheCollectionTheResourceWasCreatedIn(): void
    {
        $root = $this->tree();
        $tree = new Tree($root);
        $server = new Server($tree);

        $tree->node('calendars');
        $this->pluginOn($server)->lock($this->lockRequest('/calendars/new.ics'));
        $tree->node('calendars');

        self::assertSame(2, $root->lookups, 'The collection was handed out from the cache after a file was added.');
    }

    /**
     * And where the collection it would go in is not there either, nothing is
     * created: `409`, as everywhere else in this library. A server that made
     * the ancestors would be guessing at a tree the client never asked for.
     */
    public function testALockBelowAMissingCollectionCreatesNothing(): void
    {
        self::assertSame(409, $this->lock('/nowhere/new.ics')->status());
    }

    /**
     * RFC 4918 §6.1: an exclusive lock is one holder and no other. A second
     * one is `423` with `DAV:no-conflicting-lock`, which tells the client
     * what is wrong rather than leaving it to guess.
     */
    public function testRefusesASecondExclusiveLock(): void
    {
        $response = $this->lock('/calendars/work.ics', backend: $this->holding($this->held()));

        self::assertSame(423, $response->status());
        self::assertStringContainsString('<d:no-conflicting-lock/>', $this->bodyOf($response));
    }

    public function testRefusesAnExclusiveLockWhereALockIsSharedAlready(): void
    {
        $backend = $this->holding($this->held(scope: LockScope::Shared));

        self::assertSame(423, $this->lock('/calendars/work.ics', backend: $backend)->status());
    }

    public function testRefusesASharedLockWhereOneIsHeldExclusively(): void
    {
        $response = $this->lock(
            '/calendars/work.ics',
            body: $this->lockinfo(scope: 'shared'),
            backend: $this->holding($this->held()),
        );

        self::assertSame(423, $response->status());
    }

    /**
     * RFC 4918 §6.1: several clients may hold a shared lock at once. That is
     * the whole of what shared means, and a server that refused the second
     * holder would have no shared locks at all.
     */
    public function testTakesASecondSharedLock(): void
    {
        $response = $this->lock(
            '/calendars/work.ics',
            body: $this->lockinfo(scope: 'shared'),
            backend: $this->holding($this->held(scope: LockScope::Shared)),
        );

        self::assertSame(200, $response->status());
    }

    /**
     * **A collection cannot be held whole while somebody holds a piece of
     * it.** This is the question `locksBelow()` exists for, and a deep lock
     * that skipped it would promise the client a calendar nobody else is
     * writing to while somebody writes to one of its events.
     */
    public function testRefusesADeepLockOverAMemberSomebodyElseHolds(): void
    {
        $backend = $this->holding($this->held(root: 'calendars/work.ics'));

        $response = $this->lock('/calendars', backend: $backend);

        self::assertSame(423, $response->status());
    }

    /**
     * A lock at `Depth: 0` does not reach the member, so it is not in its
     * way: the two hold different things.
     */
    public function testTakesAShallowLockOverAMemberSomebodyElseHolds(): void
    {
        $backend = $this->holding($this->held(root: 'calendars/work.ics'));

        $response = $this->lock('/calendars', headers: ['Depth' => '0'], backend: $backend);

        self::assertSame(200, $response->status());
    }

    /**
     * Two shared locks never conflict, however deep they reach.
     */
    public function testTakesADeepSharedLockOverASharedMember(): void
    {
        $backend = $this->holding($this->held(root: 'calendars/work.ics', scope: LockScope::Shared));

        $response = $this->lock('/calendars', body: $this->lockinfo(scope: 'shared'), backend: $backend);

        self::assertSame(200, $response->status());
    }

    /**
     * A deep lock above the path holds it as surely as one on it, and the
     * backend answers that question already — this is the test that proves
     * the plugin asks it.
     */
    public function testRefusesALockUnderneathADeepLockSomebodyElseHolds(): void
    {
        $backend = $this->holding($this->held(root: 'calendars', deep: true));

        self::assertSame(423, $this->lock('/calendars/work.ics', backend: $backend)->status());
    }

    /**
     * R-LOCK-03: a lock that has run out is not a lock, and it does not stand
     * in anybody's way. The backend drops it while answering; the plugin has
     * to ask with the same moment it will write with.
     */
    public function testALockThatHasRunOutIsNoObstacle(): void
    {
        $backend = $this->holding($this->held(until: '2026-09-21 11:00:00'));

        self::assertSame(200, $this->lock('/calendars/work.ics', backend: $backend)->status());
    }

    /**
     * RFC 4918 §9.10.2: a refresh is a `LOCK` with no body naming the lock in
     * `If`. **It is the same lock held until later** — a server that handed
     * out a new one would leave the old standing, held by nobody.
     */
    public function testRefreshesALockThatIsNamedInTheIfHeader(): void
    {
        $backend = $this->holding($this->held(until: '2026-09-21 12:10:00'));

        $response = $this->refresh('/calendars/work.ics', sprintf('(<%s>)', self::TOKEN), $backend);

        self::assertSame(200, $response->status());
        self::assertStringContainsString(self::TOKEN, $this->bodyOf($response));
        self::assertCount(1, $backend->locksOn('calendars/work.ics', $this->now()));
        self::assertStringContainsString('<d:timeout>Second-3600</d:timeout>', $this->bodyOf($response));

        // **What was answered has to be what was kept.** An answer of an hour
        // over a lock that still runs out in ten minutes is the worst of both:
        // the client stops refreshing and the lock goes anyway.
        self::assertSame(
            (new DateTimeImmutable(self::NOW))->getTimestamp() + 3600,
            $this->heldOn($backend, 'calendars/work.ics')->expiresAt()?->getTimestamp(),
        );
    }

    /**
     * RFC 4918 §10.4: an `If` header is a list of lists, and the lock that is
     * meant may be named in any of them. A refresh that only ever read the
     * first would refuse a client that sent what it knows about the resource
     * before what it knows about its own lock.
     */
    public function testRefreshesALockNamedAfterOneThisServerDoesNotHold(): void
    {
        $backend = $this->holding($this->held(until: '2026-09-21 12:10:00'));

        $response = $this->refresh(
            '/calendars/work.ics',
            sprintf('(<opaquelocktoken:somebody-else>) (<%s>)', self::TOKEN),
            $backend,
        );

        self::assertSame(200, $response->status());
    }

    /**
     * RFC 4918 §9.10: the `Lock-Token` header answers a lock that was taken.
     * A refresh took none, and a client that saw one there could believe it
     * now holds two.
     */
    public function testARefreshAnswersWithNoLockTokenHeader(): void
    {
        $response = $this->refresh('/calendars/work.ics', sprintf('(<%s>)', self::TOKEN), $this->holding($this->held()));

        self::assertFalse($response->headers()->has('Lock-Token'));
    }

    /**
     * A refresh that names no lock this server holds is `412`: the condition
     * the client put on its request did not hold. Handing out a new lock
     * instead would be answering a question nobody asked.
     */
    #[DataProvider('refreshesThatNameNoLockHere')]
    public function testRefusesARefreshThatNamesNoLockOfThisPath(?string $ifHeader): void
    {
        $response = $this->refresh('/calendars/work.ics', $ifHeader, $this->holding($this->held()));

        self::assertSame(412, $response->status());
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function refreshesThatNameNoLockHere(): iterable
    {
        yield 'no If header at all' => [null];
        yield 'a token nobody here holds' => ['(<opaquelocktoken:somebody-else>)'];
        yield 'an entity tag, which is no lock' => ['([W/"abc"])'];
    }

    /**
     * RFC 4918 §9.11: `UNLOCK` names the token, and what it names goes.
     */
    public function testReleasesALock(): void
    {
        $backend = $this->holding($this->held());

        $response = $this->unlock('/calendars/work.ics', sprintf('<%s>', self::TOKEN), $backend);

        self::assertSame(204, $response->status());
        self::assertNull($response->body());
        self::assertSame([], $backend->locksOn('calendars/work.ics', $this->now()));
    }

    /**
     * An `UNLOCK` without a token says nothing at all: `400`. Dropping every
     * lock on the path instead would release holds the client never named.
     */
    public function testRefusesAnUnlockThatNamesNoToken(): void
    {
        $response = $this->unlock('/calendars/work.ics', null, $this->holding($this->held()));

        self::assertSame(400, $response->status());
    }

    /**
     * RFC 4918 §9.11.1: a token that names no lock at that path is `409`.
     * **A server that dropped any lock whose token it was shown** would let a
     * client release somebody else's resource by accident.
     */
    public function testRefusesToReleaseALockThatIsNotHeldHere(): void
    {
        $backend = $this->holding($this->held(root: 'calendars/other.ics'));

        $response = $this->unlock('/calendars/work.ics', sprintf('<%s>', self::TOKEN), $backend);

        self::assertSame(409, $response->status());
        self::assertCount(1, $backend->locksOn('calendars/other.ics', $this->now()));
    }

    public function testRefusesToReleaseATokenNobodyHolds(): void
    {
        $response = $this->unlock('/calendars/work.ics', '<opaquelocktoken:nobody>', $this->holding($this->held()));

        self::assertSame(409, $response->status());
    }

    /**
     * A deep lock is released where it was taken. Its root is the resource a
     * client was told it holds, and releasing it from a member would be
     * releasing something the client cannot see the extent of.
     */
    public function testRefusesToReleaseADeepLockFromInsideIt(): void
    {
        $backend = $this->holding($this->held(root: 'calendars', deep: true));

        $response = $this->unlock('/calendars/work.ics', sprintf('<%s>', self::TOKEN), $backend);

        self::assertSame(409, $response->status());
    }

    /**
     * RFC 4918 §15.8: `DAV:lockdiscovery` is a live property, and a client
     * asking `PROPFIND` for it is how it finds out what holds a resource
     * without trying to write to it.
     */
    public function testTellsAPropFindWhatHoldsAResource(): void
    {
        $response = $this->propFind('/calendars/work.ics', 'lockdiscovery', $this->holding($this->held()));

        self::assertStringContainsString('<d:lockdiscovery><d:activelock>', $this->bodyOf($response));
        self::assertStringContainsString(self::TOKEN, $this->bodyOf($response));
    }

    /**
     * A resource nobody holds says so with an empty element. A `404` there
     * would have a client believe the server does not know the question,
     * which is a different answer entirely.
     */
    public function testTellsAPropFindThatNobodyHoldsAResource(): void
    {
        $body = $this->bodyOf($this->propFind('/calendars/work.ics', 'lockdiscovery', new MemoryLockBackend()));

        self::assertStringContainsString('<d:lockdiscovery/>', $body);
        self::assertStringContainsString('HTTP/1.1 200 OK', $body);
    }

    /**
     * RFC 4918 §15.10: `DAV:supportedlock` says which kinds of lock may be
     * asked for at all, so that a client need not find out by being refused.
     */
    public function testTellsAPropFindWhichLocksMayBeAskedFor(): void
    {
        $body = $this->bodyOf($this->propFind('/calendars/work.ics', 'supportedlock', new MemoryLockBackend()));

        self::assertStringContainsString('<d:lockentry><d:lockscope><d:exclusive/></d:lockscope><d:locktype><d:write/></d:locktype></d:lockentry>', $body);
        self::assertStringContainsString('<d:lockentry><d:lockscope><d:shared/></d:lockscope><d:locktype><d:write/></d:locktype></d:lockentry>', $body);
    }

    /**
     * The same refusals, asked of the plugin itself rather than read off the
     * status the server turns them into. What is thrown carries the
     * precondition of RFC 4918 §16, and the `423` above proves the server
     * spells it out.
     */
    public function testThrowsWhereALockWouldConflict(): void
    {
        $this->expectException(Locked::class);

        $this->plugin($this->holding($this->held()))->lock($this->lockRequest('/calendars/work.ics'));
    }

    public function testThrowsWhereTheCollectionForANewResourceIsMissing(): void
    {
        $this->expectException(Conflict::class);

        $this->plugin()->lock($this->lockRequest('/nowhere/new.ics'));
    }

    public function testThrowsWhereARefreshNamesNoLockOfThisPath(): void
    {
        $this->expectException(PreconditionFailed::class);

        $this->plugin($this->holding($this->held()))->lock(new Request('LOCK', '/calendars/work.ics'));
    }

    public function testThrowsWhereAnUnlockNamesNoToken(): void
    {
        $this->expectException(BadRequest::class);

        $this->plugin($this->holding($this->held()))->unlock(new Request('UNLOCK', '/calendars/work.ics'));
    }

    public function testThrowsWhereAnUnlockNamesALockThatIsNotHeldHere(): void
    {
        $this->expectException(Conflict::class);

        $this->plugin($this->holding($this->held()))->unlock(new Request(
            'UNLOCK',
            '/calendars/work.ics',
            headers: new Headers(['Lock-Token' => '<opaquelocktoken:nobody>']),
        ));
    }

    /**
     * Taken and released through the plugin alone, which is the shortest path
     * from a request to a lock and back.
     */
    public function testTakesAndReleasesALockWhenAskedDirectly(): void
    {
        $backend = new MemoryLockBackend();
        $plugin = $this->plugin($backend);

        $taken = $plugin->lock($this->lockRequest('/calendars/work.ics'));
        $token = trim($taken->headers()->first('Lock-Token') ?? '', '<>');

        self::assertSame(200, $taken->status());
        self::assertCount(1, $backend->locksOn('calendars/work.ics', $this->now()));

        $released = $plugin->unlock(new Request(
            'UNLOCK',
            '/calendars/work.ics',
            headers: new Headers(['Lock-Token' => sprintf('<%s>', $token)]),
        ));

        self::assertSame(204, $released->status());
        self::assertSame([], $backend->locksOn('calendars/work.ics', $this->now()));
    }

    /**
     * RFC 4918 §18.2, asked of the plugin: it is what puts class 2 in the
     * answer, and the `OPTIONS` method is only where that is written down.
     */
    public function testAddsTheSecondComplianceClassToAnOptionsAnswer(): void
    {
        $event = new OptionsRequested(new Request('OPTIONS', '/'));

        $this->plugin()->announce($event);

        self::assertSame(['2'], $event->compliance());
    }

    /**
     * The properties, asked directly and both at once.
     */
    public function testAnswersBothOfItsPropertiesWhenAsked(): void
    {
        $result = new PropFindResult('calendars/work.ics', PropFindForm::Named, [
            '{DAV:}lockdiscovery',
            '{DAV:}supportedlock',
        ]);

        $this->plugin($this->holding($this->held()))->describe(
            new PropertiesRequested($result, new MemoryFile('work.ics', '')),
        );

        self::assertArrayHasKey('{DAV:}lockdiscovery', $result->byStatus()[200] ?? []);
        self::assertArrayHasKey('{DAV:}supportedlock', $result->byStatus()[200] ?? []);
    }

    /**
     * **Nothing is worked out that nobody asked for.** A `PROPFIND` for one
     * other property must not send this plugin to the lock storage at all: on
     * a listing of two hundred members that is two hundred questions thrown
     * away.
     */
    public function testWorksOutNeitherPropertyWhereNeitherWasAskedFor(): void
    {
        $result = new PropFindResult('calendars/work.ics', PropFindForm::Named, ['{DAV:}getetag']);

        $this->plugin($this->holding($this->held()))->describe(
            new PropertiesRequested($result, new MemoryFile('work.ics', '')),
        );

        self::assertSame([], $result->byStatus()[200] ?? []);
    }

    /**
     * A plugin built without a clock reads the one on the wall. Every other
     * test here hands the time in so that it can say what time it is; this
     * one proves the server does not need to be told.
     */
    public function testReadsTheClockWhereItWasNotToldTheTime(): void
    {
        $backend = new MemoryLockBackend();
        $server = new Server(new Tree($this->tree()));

        $response = (new Locks($server, $backend))->lock($this->lockRequest('/calendars/work.ics'));

        // The lock is there when the wall clock is used to ask for it, which
        // is the whole of what "reads the clock" means. And the answer names
        // the default maximum — an hour, the number written in the README and
        // in every application that leaves the argument out — to the second,
        // because the moment it was worked out from is the moment it is
        // counted against.
        self::assertCount(1, $backend->locksOn('calendars/work.ics', new DateTimeImmutable()));
        self::assertStringContainsString('<d:timeout>Second-3600</d:timeout>', $this->bodyOf($response));
    }

    /**
     * A second is a stretch of time, and the boundary is where a guard is
     * worth having: one second either side of it is the difference between a
     * plugin that works and one that refuses to be built.
     */
    public function testAMaximumOfOneSecondIsATimeAServerMayChoose(): void
    {
        $plugin = new Locks(new Server(new Tree($this->tree())), new MemoryLockBackend(), 1, $this->now(...));

        $response = $plugin->lock($this->lockRequest('/calendars/work.ics'));

        self::assertStringContainsString('<d:timeout>Second-1</d:timeout>', $this->bodyOf($response));
    }

    private function lockRequest(string $target, ?string $body = null): Request
    {
        return new Request('LOCK', $target, body: new Body($body ?? $this->lockinfo()));
    }

    private function tree(): MemoryCollection
    {
        $root = new MemoryCollection('');
        $calendars = new MemoryCollection('calendars');

        $calendars->add(new MemoryFile('work.ics', 'BEGIN:VCALENDAR'));
        $calendars->add(new MemoryFile('other.ics', 'BEGIN:VCALENDAR'));
        $root->add($calendars);

        return $root;
    }

    private function server(?MemoryLockBackend $backend = null, ?MemoryCollection $root = null): Server
    {
        $server = new Server(new Tree($root ?? $this->tree()));

        $this->pluginOn($server, $backend)->register();

        return $server;
    }

    /**
     * The plugin on its own, for the tests that ask it a question directly.
     *
     * **Every entry point needs at least one direct call**: a handler reached
     * only through the server is one Xdebug collects no branch data for, and
     * the gate would report decisions as untaken that every test takes.
     */
    private function plugin(?MemoryLockBackend $backend = null, ?MemoryCollection $root = null): Locks
    {
        return $this->pluginOn(new Server(new Tree($root ?? $this->tree())), $backend);
    }

    private function pluginOn(Server $server, ?MemoryLockBackend $backend = null): Locks
    {
        return new Locks($server, $backend ?? new MemoryLockBackend(), 3600, $this->now(...));
    }

    /**
     * @param array<string, string> $headers
     */
    private function lock(
        string $target,
        ?string $body = null,
        array $headers = [],
        ?MemoryLockBackend $backend = null,
        ?MemoryCollection $root = null,
    ): Response {
        return $this->server($backend, $root)->handle(new Request(
            'LOCK',
            $target,
            headers: new Headers($headers),
            body: new Body($body ?? $this->lockinfo()),
        ));
    }

    private function refresh(string $target, ?string $ifHeader, MemoryLockBackend $backend): Response
    {
        return $this->server($backend)->handle(new Request(
            'LOCK',
            $target,
            headers: new Headers($ifHeader === null ? [] : ['If' => $ifHeader]),
        ));
    }

    private function unlock(string $target, ?string $token, MemoryLockBackend $backend): Response
    {
        return $this->server($backend)->handle(new Request(
            'UNLOCK',
            $target,
            headers: new Headers($token === null ? [] : ['Lock-Token' => $token]),
        ));
    }

    private function propFind(string $target, string $property, MemoryLockBackend $backend): Response
    {
        $server = $this->server($backend);
        $propFind = new PropFind($server);

        $server->onMethod('PROPFIND', $propFind(...));

        return $server->handle(new Request(
            'PROPFIND',
            $target,
            headers: new Headers(['Depth' => '0']),
            body: new Body(sprintf('<D:propfind xmlns:D="DAV:"><D:prop><D:%s/></D:prop></D:propfind>', $property)),
        ));
    }

    /**
     * The one lock that is held there, said out loud: a test reaching for
     * `[0]` and finding nothing would fail further down, on a line that has
     * nothing to do with what went wrong.
     */
    private function heldOn(MemoryLockBackend $backend, string $path, ?DateTimeImmutable $now = null): LockInfo
    {
        $locks = $backend->locksOn($path, $now ?? $this->now());

        self::assertCount(1, $locks);

        return $locks[0] ?? self::fail('Nothing is held there.');
    }

    private function holding(LockInfo $lock): MemoryLockBackend
    {
        $backend = new MemoryLockBackend();

        $backend->set($lock);

        return $backend;
    }

    private function held(
        string $root = 'calendars/work.ics',
        LockScope $scope = LockScope::Exclusive,
        bool $deep = false,
        ?string $until = '2026-09-21 13:00:00',
    ): LockInfo {
        return new LockInfo(
            $root,
            self::TOKEN,
            $scope,
            $deep,
            null,
            $until === null ? null : new DateTimeImmutable($until),
        );
    }

    private function lockinfo(string $scope = 'exclusive', string $owner = ''): string
    {
        return sprintf(
            '<D:lockinfo xmlns:D="DAV:"><D:lockscope><D:%s/></D:lockscope><D:locktype><D:write/></D:locktype>%s</D:lockinfo>',
            $scope,
            $owner === '' ? '' : sprintf('<D:owner>%s</D:owner>', $owner),
        );
    }

    private function bodyOf(Response $response): string
    {
        $body = $response->body();

        self::assertIsString($body, 'The response carries no body.');

        return $body;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }
}
