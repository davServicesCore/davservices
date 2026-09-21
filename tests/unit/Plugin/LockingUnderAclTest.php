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
use DavServices\Dav\Event\CurrentPrincipalRequested;
use DavServices\Dav\Method\Get;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Plugin\Acl;
use DavServices\Plugin\Locks;
use DavServices\Tests\Unit\Acl\ArrayPrivilegeResolver;
use DavServices\Tests\Unit\Backend\MemoryLockBackend;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 3744 §3.4 and §3.5, RFC 4918 §9.10.4, and
 * R-ACL-05.
 *
 * **A hole in something already shipped, which is why it comes before new
 * work.** P3-10 guards every write, but a `LOCK` writes nothing — so a client
 * that may not change a file could still take a lock on it and stop everybody
 * else from changing it. A denial of service in three lines of curl.
 *
 * ## What a `LOCK` needs
 *
 * **It depends on what is there**, and that is the one thing about this worth
 * saying twice. A lock on an existing resource is a claim on the right to
 * change it, so it needs `DAV:write-content` (§3.4). A lock on a path that
 * holds nothing **creates** an empty resource (RFC 4918 §9.10.4), and
 * creating a member of a collection is `DAV:bind` (§3.9) — which the bind
 * seam already guards, and guards *before* anything is created, so a refusal
 * leaves nothing behind.
 *
 * So the check here is only for what exists. The other case is not an
 * oversight; it is the seam that was already right.
 *
 * ## Why `UNLOCK` needs no check at all
 *
 * This was the open question, and the answer turned out to be short.
 * RFC 3744 §3.5 gives `DAV:unlock` for removing a lock **held by another
 * principal** — breaking a lock. **This server has no way to break one:**
 * `UNLOCK` only ever removes a lock whose token was submitted, so holding the
 * token is the proof of ownership, and the privilege has nothing left to
 * guard. A server that offered lock-breaking would need it; this one would be
 * adding a check for a door it has not built.
 *
 * That is written down rather than left as silence, because the next person
 * to read RFC 3744 §3.5 will wonder where `DAV:unlock` went.
 */
#[CoversClass(Acl::class)]
final class LockingUnderAclTest extends TestCase
{
    private const ALICE = '/principals/alice';

    private const LOCK_INFO = '<D:lockinfo xmlns:D="DAV:"><D:lockscope><D:exclusive/></D:lockscope><D:locktype><D:write/></D:locktype></D:lockinfo>';

    /**
     * **The hole this chunk closes.** A reader could lock a file and stop
     * everybody who may write it from doing so.
     */
    public function testAReaderMayNotLockWhatTheyMayNotWrite(): void
    {
        $response = $this->lock('/calendars/work.ics', ['calendars/work.ics' => ['{DAV:}read']]);

        self::assertSame(403, $response->status());
    }

    /**
     * RFC 3744 §3.4: a lock is a claim on the right to change the resource,
     * so whoever may change it may lock it.
     */
    public function testSomebodyWhoMayWriteMayLock(): void
    {
        $response = $this->lock('/calendars/work.ics', [
            'calendars/work.ics' => ['{DAV:}read', '{DAV:}write-content'],
        ]);

        self::assertSame(200, $response->status());
    }

    /**
     * And `DAV:write` aggregates it, so granting that grants locking too —
     * which is the point of the tree and the reason nothing here asks about
     * aggregates itself.
     */
    public function testGrantingWriteGrantsLocking(): void
    {
        $response = $this->lock('/calendars/work.ics', ['calendars/work.ics' => ['{DAV:}write']]);

        self::assertSame(200, $response->status());
    }

    /**
     * R-PRIV-03 again: somebody who has not signed in holds nothing, and so
     * may not lock.
     */
    public function testSomebodyWhoIsNotSignedInMayNotLock(): void
    {
        $response = $this->lock('/calendars/work.ics', ['calendars/work.ics' => ['{DAV:}write']], signedIn: false);

        self::assertSame(403, $response->status());
    }

    /**
     * **RFC 4918 §9.10.4: a lock on nothing creates something**, and creating
     * a member is `DAV:bind` on the collection — which the bind seam already
     * guards. So this passes with `bind` and nothing else.
     */
    public function testLockingAnEmptyPathNeedsBindOnTheCollection(): void
    {
        $response = $this->lock('/calendars/new.ics', ['calendars' => ['{DAV:}bind']]);

        self::assertSame(201, $response->status());
    }

    /**
     * And without it the lock is refused — **and nothing is left behind**.
     * The bind seam runs before anything is created, so a refusal does not
     * cost an empty file that nobody asked for.
     */
    public function testARefusedLockOnAnEmptyPathCreatesNothing(): void
    {
        $root = $this->tree();

        $response = $this->lock('/calendars/new.ics', [], root: $root);

        self::assertSame(403, $response->status());

        $calendars = $root->child('calendars');

        self::assertInstanceOf(MemoryCollection::class, $calendars);
        self::assertFalse($calendars->hasChild('new.ics'), 'A refusal leaves nothing behind.');
    }

    /**
     * **`UNLOCK` needs no privilege, and that is the finding rather than an
     * omission.** RFC 3744 §3.5 gives `DAV:unlock` for breaking somebody
     * else's lock; this server only ever removes a lock whose token was
     * submitted, so the token is the proof and the privilege has nothing left
     * to guard.
     */
    public function testWhoeverHoldsTheTokenMayUnlockWithoutAnyPrivilege(): void
    {
        $server = $this->server($this->tree(), [
            'calendars/work.ics' => ['{DAV:}read', '{DAV:}write-content'],
        ]);

        $taken = $server->handle(new Request(
            'LOCK',
            '/calendars/work.ics',
            body: new Body(self::LOCK_INFO),
        ));

        $token = $taken->headers()->first('Lock-Token') ?? '';

        self::assertSame(200, $taken->status());

        $released = $server->handle(new Request(
            'UNLOCK',
            '/calendars/work.ics',
            headers: new Headers(['Lock-Token' => $token]),
        ));

        self::assertSame(204, $released->status());
    }

    /**
     * A method this plugin has no rule for passes without a question being
     * asked: `UNLOCK` is the one that matters here, and `OPTIONS` is the one
     * that has never been guarded.
     */
    public function testAMethodWithNoRuleIsNotGuarded(): void
    {
        $server = $this->server($this->tree(), []);

        self::assertSame(400, $server->handle(new Request('UNLOCK', '/calendars/work.ics'))->status());
    }

    /**
     * Without the access control plugin nothing of this happens: a plain
     * WebDAV server with locking locks for anybody (R-ARC-02).
     */
    public function testAServerWithoutAccessControlLocksForAnybody(): void
    {
        $server = new Server(new Tree($this->tree()));

        (new Locks($server, new MemoryLockBackend(), 3600, static fn (): DateTimeImmutable => new DateTimeImmutable()))
            ->register();

        $response = $server->handle(new Request('LOCK', '/calendars/work.ics', body: new Body(self::LOCK_INFO)));

        self::assertSame(200, $response->status());
    }

    private function tree(): MemoryCollection
    {
        $root = new MemoryCollection('');
        $calendars = new MemoryCollection('calendars');

        $calendars->add(new MemoryFile('work.ics', 'BEGIN:VCALENDAR'));
        $root->add($calendars);

        return $root;
    }

    /**
     * @param array<string, list<string>> $granted
     */
    private function server(MemoryCollection $root, array $granted, bool $signedIn = true): Server
    {
        $server = new Server(new Tree($root));
        $get = new Get($server);

        $server->onMethod('GET', $get(...));

        if ($signedIn) {
            $server->events()->on(
                CurrentPrincipalRequested::class,
                static fn (CurrentPrincipalRequested $event) => $event->answerWith('principals/alice'),
            );
        }

        (new Locks($server, new MemoryLockBackend(), 3600, static fn (): DateTimeImmutable => new DateTimeImmutable()))
            ->register();

        (new Acl($server, new ArrayPrivilegeResolver([self::ALICE => $granted])))->register();

        return $server;
    }

    /**
     * @param array<string, list<string>> $granted
     */
    private function lock(
        string $target,
        array $granted,
        bool $signedIn = true,
        ?MemoryCollection $root = null,
    ): Response {
        return $this
            ->server($root ?? $this->tree(), $granted, $signedIn)
            ->handle(new Request('LOCK', $target, body: new Body(self::LOCK_INFO)));
    }
}
