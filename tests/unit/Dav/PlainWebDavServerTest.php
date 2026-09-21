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

namespace DavServices\Tests\Unit\Dav;

use DavServices\Dav\Method\Delete;
use DavServices\Dav\Method\Get;
use DavServices\Dav\Method\MkCol;
use DavServices\Dav\Method\Options;
use DavServices\Dav\Method\PropFind;
use DavServices\Dav\Method\Put;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-LOCK-06 and R-ARC-02.
 *
 * **A server built without the lock plugin is a plain WebDAV server, not a
 * broken one.** R-ARC-02 asks that every extension be optional and that plain
 * WebDAV work on its own; this is the file that holds the library to it for
 * locking, which is the first extension that had anything to enforce.
 *
 * Everything here is asked of a server assembled without a single line about
 * locks. What it must do is say so honestly and go on working:
 *
 * **It says `DAV: 1`, not `1, 2`.** A client reads that header to decide
 * whether to try locking at all, and one that claimed class 2 without
 * answering `LOCK` would have clients wait for holds nobody can grant.
 *
 * **`LOCK` and `UNLOCK` are `501`.** Not `405`: the method is one this
 * protocol has and this server does not implement, which is what `501` means.
 *
 * **Writes go through.** No lock is held, because nothing takes locks, and a
 * server that refused writes for a hold nobody could have taken would be
 * unusable.
 *
 * **The two lock properties are `404`.** A server that does not lock does not
 * know the question, and an empty answer would say "nobody holds this", which
 * it cannot know.
 */
#[CoversClass(Server::class)]
final class PlainWebDavServerTest extends TestCase
{
    /**
     * R-LOCK-06: `DAV: 1` and nothing more, because the compliance classes
     * come from the plugins and there are none.
     */
    public function testSaysItIsOfClassOneOnly(): void
    {
        self::assertSame('1', $this->handle(new Request('OPTIONS', '/'))->headers()->first('DAV'));
    }

    /**
     * And the methods it lists are the ones it has. A client that read `LOCK`
     * there would send one.
     */
    public function testListsNoLockingMethods(): void
    {
        $allow = $this->handle(new Request('OPTIONS', '/'))->headers()->first('Allow') ?? '';

        self::assertStringNotContainsString('LOCK', $allow);
        self::assertStringContainsString('PUT', $allow);
    }

    /**
     * `501 Not Implemented`, and not `405 Method Not Allowed`: the difference
     * is whether the method exists in the protocol at all. A client told `405`
     * would conclude the resource cannot be locked; `501` tells it the server
     * does not lock, which is the truth.
     */
    #[DataProvider('theLockingMethods')]
    public function testAnswersTheLockingMethodsWithNotImplemented(string $method): void
    {
        self::assertSame(501, $this->handle(new Request($method, '/calendars/work.ics'))->status());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function theLockingMethods(): iterable
    {
        yield 'LOCK' => ['LOCK'];
        yield 'UNLOCK' => ['UNLOCK'];
    }

    /**
     * **Nothing guards a write here, and nothing should.** This is the test
     * that proves the enforcement of R-LOCK-04 hangs on the plugin rather
     * than sitting inside the methods: take the plugin away and the writes
     * are plain again.
     */
    public function testLetsEveryWriteThrough(): void
    {
        self::assertSame(204, $this->handle(new Request('PUT', '/calendars/work.ics', body: new Body('changed')))->status());
        self::assertSame(204, $this->handle(new Request('DELETE', '/calendars/work.ics'))->status());
        self::assertSame(201, $this->handle(new Request('MKCOL', '/calendars/inner'))->status());
    }

    /**
     * RFC 4918 §15.8 and §15.10: a server that does not lock has neither
     * property. **`404` is the honest answer** — an empty `DAV:lockdiscovery`
     * would tell a client that nobody holds the resource, which is a thing
     * this server cannot know, and an empty `DAV:supportedlock` would invite
     * it to ask for a kind of lock that does not exist here.
     */
    #[DataProvider('theLockProperties')]
    public function testDoesNotAnswerTheLockProperties(string $property): void
    {
        $body = (string) $this->handle(new Request(
            'PROPFIND',
            '/calendars/work.ics',
            headers: new Headers(['Depth' => '0']),
            body: new Body(sprintf('<D:propfind xmlns:D="DAV:"><D:prop><D:%s/></D:prop></D:propfind>', $property)),
        ))->body();

        self::assertStringContainsString('404 Not Found', $body);
        self::assertStringContainsString($property, $body);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function theLockProperties(): iterable
    {
        yield 'lockdiscovery' => ['lockdiscovery'];
        yield 'supportedlock' => ['supportedlock'];
    }

    /**
     * **The open question of this chunk, written down rather than left to be
     * discovered.** RFC 4918 §10.4 is core WebDAV, not a lock extension: an
     * `If` header may name entity tags alone, and those need no locking to
     * evaluate. Here nothing evaluates it, because the evaluation was built
     * with the lock plugin in P3-04 — so a client that guarded its write with
     * a stale tag is not refused.
     *
     * That is a guard quietly dropped, which this library refuses to do
     * elsewhere: {@see \DavServices\Http\IfHeader} answers `400` to a header
     * it cannot read for exactly this reason. **This test states the present
     * behaviour so that changing it is a decision somebody makes rather than
     * a surprise**, and the progress notes carry the recommendation: the
     * evaluation belongs in the core with the lock plugin contributing its
     * state tokens.
     */
    public function testDoesNotYetHoldARequestToAnIfHeaderOfEntityTagsAlone(): void
    {
        $response = $this->handle(new Request(
            'PUT',
            '/calendars/work.ics',
            headers: new Headers(['If' => '(["a tag this resource has never had"])']),
            body: new Body('changed'),
        ));

        self::assertSame(204, $response->status(), 'Without the lock plugin, nothing evaluates the If header.');
    }

    private function handle(Request $request): Response
    {
        $root = new MemoryCollection('');
        $calendars = new MemoryCollection('calendars');

        $calendars->add(new MemoryFile('work.ics', 'BEGIN:VCALENDAR'));
        $root->add($calendars);

        $server = new Server(new Tree($root));
        $get = new Get($server);

        $server->onMethod('GET', $get(...));

        foreach ([
            'OPTIONS' => new Options($server),
            'PUT' => new Put($server),
            'DELETE' => new Delete($server),
            'MKCOL' => new MkCol($server),
            'PROPFIND' => new PropFind($server),
        ] as $name => $method) {
            $server->onMethod($name, $method(...));
        }

        return $server->handle($request);
    }
}
