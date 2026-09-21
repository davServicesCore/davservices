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
use DavServices\Dav\Event\BeforeBind;
use DavServices\Dav\Event\BeforeCopy;
use DavServices\Dav\Event\BeforeMethod;
use DavServices\Dav\Event\BeforeMove;
use DavServices\Dav\Event\BeforeUnbind;
use DavServices\Dav\Event\BeforeWriteContent;
use DavServices\Dav\Event\PropertiesChanging;
use DavServices\Dav\Locks\LockInfo;
use DavServices\Dav\Locks\LockScope;
use DavServices\Dav\Method\Copy;
use DavServices\Dav\Method\Delete;
use DavServices\Dav\Method\Get;
use DavServices\Dav\Method\MkCol;
use DavServices\Dav\Method\Move;
use DavServices\Dav\Method\PropPatch;
use DavServices\Dav\Method\Put;
use DavServices\Dav\PropPatchResult;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-LOCK-04 and R-HTTP-07, and from the nine tests
 * `litmus locks` failed against P3-03.
 *
 * **This is the chunk that makes a lock worth taking.** Until now the server
 * kept a record of holds and told the truth about them, and a `PUT` from
 * somebody who never asked went through regardless. Litmus said so in
 * numbers: 32 of 41, and every failure the same missing thing.
 *
 * Two answers have to be kept apart, and mixing them up is the usual way this
 * goes wrong:
 *
 * **`423 Locked`** — the resource is held and the request submitted no token
 * for it. The client did not claim anything; it simply may not write.
 * RFC 4918 §9.10.6 names the precondition `DAV:lock-token-submitted`, which is
 * what tells a client that a token is what is missing.
 *
 * **`412 Precondition Failed`** — the request *did* put a condition on itself
 * in an `If` header, and the condition does not hold. The client claimed the
 * resource was in a state it is not in. This is owed even when nothing is
 * locked at all: `If: (<opaquelocktoken:made-up>)` on a free resource is a
 * claim that is simply false, and answering `204` would tell the client its
 * claim was good.
 *
 * Nothing here touches a method class. Every check hangs on a seam that has
 * been there since P2 — `BeforeWriteContent`, `BeforeBind`, `BeforeUnbind`,
 * `BeforeMove`, `BeforeCopy` — which is what R-ARC-02 is for: if a write
 * could not be caught, the answer would be a missing seam, not a special case.
 */
#[CoversClass(Locks::class)]
final class LockEnforcementTest extends TestCase
{
    private const NOW = '2026-09-21 12:00:00';

    private const TOKEN = 'opaquelocktoken:held';

    /**
     * R-LOCK-04, and `litmus notowner_modify`: a write to a held resource by
     * somebody who submitted no token is `423`, whatever the method.
     */
    #[DataProvider('writesToOneResource')]
    public function testRefusesAWriteToAHeldResource(Request $request): void
    {
        $response = $this->handle($request, $this->holding($this->held()));

        self::assertSame(423, $response->status());
        self::assertStringContainsString('<d:lock-token-submitted/>', (string) $response->body());
    }

    /**
     * @return iterable<string, array{Request}>
     */
    public static function writesToOneResource(): iterable
    {
        yield 'PUT over it' => [new Request('PUT', '/calendars/work.ics', body: new Body('changed'))];
        yield 'DELETE of it' => [new Request('DELETE', '/calendars/work.ics')];
        yield 'MOVE away from it' => [self::withDestination('MOVE', '/calendars/work.ics', '/calendars/moved.ics')];
        yield 'MOVE onto it' => [self::withDestination('MOVE', '/calendars/other.ics', '/calendars/work.ics')];
        yield 'COPY onto it' => [self::withDestination('COPY', '/calendars/other.ics', '/calendars/work.ics')];
        yield 'PROPPATCH of it' => [new Request(
            'PROPPATCH',
            '/calendars/work.ics',
            body: new Body('<D:propertyupdate xmlns:D="DAV:"><D:set><D:prop><D:displayname>x</D:displayname></D:prop></D:set></D:propertyupdate>'),
        )];
    }

    /**
     * And the same write with the token in the `If` header goes through. That
     * is the whole point of a lock token: the holder keeps working.
     */
    public function testLetsTheHolderWrite(): void
    {
        $response = $this->handle(
            new Request('PUT', '/calendars/work.ics', headers: $this->submitting(self::TOKEN), body: new Body('changed')),
            $this->holding($this->held()),
        );

        self::assertSame(204, $response->status());
    }

    /**
     * A lock somewhere else holds nothing here.
     */
    public function testLeavesAWriteToAFreeResourceAlone(): void
    {
        $response = $this->handle(
            new Request('PUT', '/calendars/other.ics', body: new Body('changed')),
            $this->holding($this->held()),
        );

        self::assertSame(204, $response->status());
    }

    /**
     * R-LOCK-03 once more, from the other side: a lock that has run out holds
     * nothing, and a client must not be refused over it.
     */
    public function testALockThatHasRunOutRefusesNothing(): void
    {
        $response = $this->handle(
            new Request('PUT', '/calendars/work.ics', body: new Body('changed')),
            $this->holding($this->held(until: '2026-09-21 11:00:00')),
        );

        self::assertSame(204, $response->status());
    }

    /**
     * **A deep lock holds what is inside it.** A `PUT` to a member of a
     * locked collection is a write to something that collection was locked to
     * protect, and this is the case a server that only ever looked at the
     * request path would let through.
     */
    public function testRefusesAWriteToAMemberOfAHeldCollection(): void
    {
        $response = $this->handle(
            new Request('PUT', '/calendars/work.ics', body: new Body('changed')),
            $this->holding($this->held(root: 'calendars', deep: true)),
        );

        self::assertSame(423, $response->status());
    }

    /**
     * **And a collection cannot be moved away from under a lock inside it.**
     * A `MOVE` names the collection, not the member, so the hold is below the
     * path the request speaks of — the question `locksBelow()` answers.
     */
    public function testRefusesToMoveACollectionThatHoldsALockedMember(): void
    {
        $response = $this->handle(
            self::withDestination('MOVE', '/calendars', '/archive'),
            $this->holding($this->held()),
        );

        self::assertSame(423, $response->status());
    }

    /**
     * A `COPY` reads the source and writes the destination, so a lock on the
     * source is no reason to refuse it: nothing about the source changes.
     */
    public function testLeavesACopyOfAHeldResourceAlone(): void
    {
        $response = $this->handle(
            self::withDestination('COPY', '/calendars/work.ics', '/calendars/copy.ics'),
            $this->holding($this->held()),
        );

        self::assertSame(201, $response->status());
    }

    /**
     * Creating something where a deep lock reaches is a write to what that
     * lock holds (RFC 4918 §7.4): the collection was locked so that its
     * members would not change, and a new member is a change.
     */
    public function testRefusesToCreateAResourceInsideAHeldCollection(): void
    {
        $response = $this->handle(
            new Request('PUT', '/calendars/new.ics', body: new Body('new')),
            $this->holding($this->held(root: 'calendars', deep: true)),
        );

        self::assertSame(423, $response->status());
    }

    public function testRefusesToCreateACollectionInsideAHeldCollection(): void
    {
        $response = $this->handle(
            new Request('MKCOL', '/calendars/inner'),
            $this->holding($this->held(root: 'calendars', deep: true)),
        );

        self::assertSame(423, $response->status());
    }

    /**
     * `litmus fail_cond_put_unlocked`: **a condition that does not hold is
     * `412`, even where nothing is locked at all.** The client claimed the
     * resource was in a state it is not in; answering `204` would tell it the
     * claim was good, and it would go on believing something false about the
     * resource it just overwrote.
     */
    public function testRefusesAConditionThatDoesNotHoldOnAFreeResource(): void
    {
        $response = $this->handle(
            new Request(
                'PUT',
                '/calendars/other.ics',
                headers: $this->submitting('opaquelocktoken:made-up'),
                body: new Body('changed'),
            ),
            new MemoryLockBackend(),
        );

        self::assertSame(412, $response->status());
    }

    /**
     * `litmus fail_cond_put` and `cond_put_corrupt_token`: the same on a
     * resource that *is* held, and with a token that is not the one held.
     */
    public function testRefusesAConditionNamingATokenThatIsNotHeld(): void
    {
        $response = $this->handle(
            new Request(
                'PUT',
                '/calendars/work.ics',
                headers: $this->submitting('opaquelocktoken:not-this-one'),
                body: new Body('changed'),
            ),
            $this->holding($this->held()),
        );

        self::assertSame(412, $response->status());
    }

    /**
     * RFC 9110 §13.1: an entity tag is a condition like any other, and `If`
     * is where WebDAV puts them. A stale tag is a client writing over
     * something it has not seen.
     */
    public function testRefusesAConditionNamingAnEntityTagTheResourceNoLongerHas(): void
    {
        $response = $this->handle(
            new Request(
                'PUT',
                '/calendars/other.ics',
                headers: new Headers(['If' => '(["stale"])']),
                body: new Body('changed'),
            ),
            new MemoryLockBackend(),
        );

        self::assertSame(412, $response->status());
    }

    /**
     * And the tag the resource does have lets the write through, which is
     * what proves the refusal above is about the tag rather than about the
     * header being there at all.
     */
    public function testLetsThroughAConditionNamingTheEntityTagTheResourceHas(): void
    {
        $response = $this->handle(
            new Request(
                'PUT',
                '/calendars/other.ics',
                headers: new Headers(['If' => sprintf('(["%s"])', md5('BEGIN:VCALENDAR'))]),
                body: new Body('changed'),
            ),
            new MemoryLockBackend(),
        );

        self::assertSame(204, $response->status());
    }

    /**
     * `litmus fail_complex_cond_put`: a header of several lists is refused
     * only when **none** of them holds. One that does is enough, and a server
     * that stopped at the first failing list would refuse a client that
     * offered it two good reasons.
     */
    public function testAcceptsAHeaderWhereOneOfSeveralListsHolds(): void
    {
        $response = $this->handle(
            new Request(
                'PUT',
                '/calendars/work.ics',
                headers: new Headers(['If' => sprintf('(["stale"]) (<%s>)', self::TOKEN)]),
                body: new Body('changed'),
            ),
            $this->holding($this->held()),
        );

        self::assertSame(204, $response->status());
    }

    public function testRefusesAHeaderWhereNoListHolds(): void
    {
        $response = $this->handle(
            new Request(
                'PUT',
                '/calendars/work.ics',
                headers: new Headers(['If' => '(["stale"]) (<opaquelocktoken:made-up>)']),
                body: new Body('changed'),
            ),
            $this->holding($this->held()),
        );

        self::assertSame(412, $response->status());
    }

    /**
     * RFC 4918 §10.4.2: a tagged list is about the resource it names. A
     * client moving a member out of a locked collection submits the
     * collection's token tagged with the collection, and the server has to
     * resolve that URL to a path of its own before it can be believed.
     */
    public function testReadsAListTaggedWithTheResourceItIsAbout(): void
    {
        $response = $this->handle(
            new Request(
                'PUT',
                '/calendars/work.ics',
                headers: new Headers([
                    'Host' => 'localhost',
                    'If' => sprintf('<http://localhost/calendars/work.ics> (<%s>)', self::TOKEN),
                ]),
                body: new Body('changed'),
            ),
            $this->holding($this->held()),
        );

        self::assertSame(204, $response->status());
    }

    /**
     * **A reading request is not guarded.** RFC 4918 §7.5 is about writes,
     * and a `GET` or a `PROPFIND` of a locked resource is what a client does
     * to find out who holds it.
     */
    public function testLeavesAReadingRequestAlone(): void
    {
        $response = $this->handle(new Request('GET', '/calendars/work.ics'), $this->holding($this->held()));

        self::assertSame(200, $response->status());
    }

    /**
     * But a reading request that puts a condition on itself is still held to
     * it: RFC 9110 §13.1 has `If` guard the request, not only the write.
     */
    public function testHoldsAReadingRequestToTheConditionItSetItself(): void
    {
        $response = $this->handle(
            new Request('GET', '/calendars/work.ics', headers: $this->submitting('opaquelocktoken:made-up')),
            $this->holding($this->held()),
        );

        self::assertSame(412, $response->status());
    }

    /**
     * An `If` header nobody can read is a `400`: the header guards a write,
     * so a condition that was quietly dropped could let through exactly what
     * the client took pains to prevent. That is {@see IfHeader}'s rule from
     * P1-09, and this is the test that it reaches a client.
     */
    public function testRefusesAnIfHeaderThatCannotBeRead(): void
    {
        $response = $this->handle(
            new Request('PUT', '/calendars/other.ics', headers: new Headers(['If' => 'nonsense']), body: new Body('x')),
            new MemoryLockBackend(),
        );

        self::assertSame(400, $response->status());
    }

    /**
     * A `DELETE` of a collection reports what stayed behind, member by member
     * (RFC 4918 §9.6.1). A locked member is one that stays, and **its
     * ancestors stay with it** — a server that removed the collection anyway
     * would leave data nobody can address any more.
     */
    public function testACollectionWithAHeldMemberSurvivesTheDeleteOfTheCollection(): void
    {
        $root = $this->tree();
        $response = $this->handle(new Request('DELETE', '/calendars'), $this->holding($this->held()), $root);

        self::assertSame(207, $response->status());
        self::assertStringContainsString('423 Locked', (string) $response->body());
        self::assertTrue($root->hasChild('calendars'));
    }

    /**
     * **Every seam asked directly, and one at a time.** A listener reached
     * only through the emitter is one Xdebug collects no branch data for, and
     * the gate would report decisions as untaken that every test takes.
     */
    public function testEverySeamRefusesAHeldPathWhenAskedDirectly(): void
    {
        $refusals = 0;

        foreach ([
            fn (Locks $locks) => $locks->refuseAWriteToAHeldPath(new BeforeWriteContent('calendars/work.ics', 'x')),
            fn (Locks $locks) => $locks->refuseAWriteToAHeldPath(new BeforeBind('calendars/work.ics')),
            fn (Locks $locks) => $locks->refuseAWriteToAHeldPath(new BeforeUnbind('calendars/work.ics')),
            fn (Locks $locks) => $locks->refuseACopyOntoAHeldPath(new BeforeCopy('calendars/other.ics', 'calendars/work.ics')),
            fn (Locks $locks) => $locks->refuseAMoveThatIsHeld(new BeforeMove('calendars/other.ics', 'calendars/work.ics')),
            fn (Locks $locks) => $locks->refuseAPropertyChangeOnAHeldPath(
                new PropertiesChanging(new PropPatchResult('calendars/work.ics', ['{DAV:}displayname' => 'x']), new MemoryFile('work.ics', '')),
            ),
        ] as $ask) {
            try {
                $ask($this->plugin($this->holding($this->held())));
            } catch (Locked $refused) {
                $refusals++;
            }
        }

        self::assertSame(6, $refusals, 'Every seam a write passes through refuses a held path.');
    }

    /**
     * And none of them refuses a path nobody holds, which is what proves the
     * refusals above are about the lock rather than about the seam.
     */
    public function testNoSeamRefusesAFreePath(): void
    {
        $locks = $this->plugin($this->holding($this->held()));

        $locks->refuseAWriteToAHeldPath(new BeforeWriteContent('calendars/other.ics', 'x'));
        $locks->refuseACopyOntoAHeldPath(new BeforeCopy('calendars/work.ics', 'calendars/copy.ics'));
        $locks->refuseAMoveThatIsHeld(new BeforeMove('calendars/other.ics', 'calendars/moved.ics'));

        self::expectNotToPerformAssertions();
    }

    /**
     * A request that claims nothing is held to nothing, and the guard has
     * nothing to read.
     */
    public function testARequestWithNoConditionIsHeldToNothing(): void
    {
        $this->plugin(new MemoryLockBackend())->holdTheRequestToWhatItClaimed(
            new BeforeMethod(new Request('PUT', '/calendars/work.ics')),
        );

        self::expectNotToPerformAssertions();
    }

    /**
     * And a request whose condition does hold passes without a word, which is
     * the case the refusals below are told apart from.
     */
    public function testARequestWhoseConditionHoldsPassesTheGuard(): void
    {
        $this->plugin($this->holding($this->held()))->holdTheRequestToWhatItClaimed(new BeforeMethod(new Request(
            'PUT',
            '/calendars/work.ics',
            headers: $this->submitting(self::TOKEN),
        )));

        self::expectNotToPerformAssertions();
    }

    /**
     * **A condition this server cannot check is not one it may call true.** A
     * list tagged with another server names a resource this one knows nothing
     * about; the safe reading is that it does not hold, and the client is told
     * `412` rather than let through on a claim nobody verified.
     */
    public function testACondorationAboutAnotherServerHoldsNothing(): void
    {
        $this->expectException(PreconditionFailed::class);

        $this->plugin($this->holding($this->held()))->holdTheRequestToWhatItClaimed(new BeforeMethod(new Request(
            'PUT',
            '/calendars/work.ics',
            headers: new Headers([
                'Host' => 'localhost',
                'If' => sprintf('<http://elsewhere.example/calendars/work.ics> (<%s>)', self::TOKEN),
            ]),
        )));
    }

    /**
     * A resource tag naming a path that cannot be resolved safely is in the
     * same position: unreadable is not true.
     */
    public function testAConditionAboutAPathNobodyCanResolveHoldsNothing(): void
    {
        $this->expectException(PreconditionFailed::class);

        $this->plugin($this->holding($this->held()))->holdTheRequestToWhatItClaimed(new BeforeMethod(new Request(
            'PUT',
            '/calendars/work.ics',
            headers: new Headers(['If' => sprintf('</../outside> (<%s>)', self::TOKEN)]),
        )));
    }

    /**
     * And a condition about a path that holds nothing at all: a `PUT` that
     * would create a file cannot name an entity tag the file does not have
     * yet.
     */
    public function testAConditionAboutAPathThatHoldsNothingHoldsNothing(): void
    {
        $this->expectException(PreconditionFailed::class);

        $this->plugin(new MemoryLockBackend())->holdTheRequestToWhatItClaimed(new BeforeMethod(new Request(
            'PUT',
            '/calendars/new.ics',
            headers: new Headers(['If' => '(["whatever"])']),
        )));
    }

    /**
     * A collection has no entity tag of its own, so a condition naming one
     * cannot hold of it.
     */
    public function testAConditionAboutTheEntityTagOfACollectionHoldsNothing(): void
    {
        $this->expectException(PreconditionFailed::class);

        $this->plugin(new MemoryLockBackend())->holdTheRequestToWhatItClaimed(new BeforeMethod(new Request(
            'DELETE',
            '/calendars',
            headers: new Headers(['If' => '(["whatever"])']),
        )));
    }

    private function plugin(MemoryLockBackend $backend): Locks
    {
        return new Locks(new Server(new Tree($this->tree())), $backend, 3600, $this->now(...));
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

    private function handle(Request $request, MemoryLockBackend $backend, ?MemoryCollection $root = null): Response
    {
        $server = new Server(new Tree($root ?? $this->tree()));

        (new Locks($server, $backend, 3600, $this->now(...)))->register();

        foreach ([
            'PUT' => new Put($server),
            'DELETE' => new Delete($server),
            'MKCOL' => new MkCol($server),
            'MOVE' => new Move($server),
            'COPY' => new Copy($server),
            'PROPPATCH' => new PropPatch($server),
            'GET' => new Get($server),
        ] as $name => $method) {
            $server->onMethod($name, $method(...));
        }

        return $server->handle($request);
    }

    private static function withDestination(string $method, string $target, string $destination): Request
    {
        return new Request($method, $target, headers: new Headers([
            'Destination' => $destination,
            'Overwrite' => 'T',
        ]));
    }

    private function submitting(string $token): Headers
    {
        return new Headers(['If' => sprintf('(<%s>)', $token)]);
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

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }
}
