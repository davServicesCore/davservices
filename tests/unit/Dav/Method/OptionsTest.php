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

namespace DavServices\Tests\Unit\Dav\Method;

use DavServices\Dav\Event\OptionsRequested;
use DavServices\Dav\Method\Options;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-HTTP-11 (compliance classes worked out from the
 * plugins that are active, plus `Allow` and `MS-Author-Via`) and R-DAV-01.
 *
 * `OPTIONS` is how a client decides what this server is. Windows will not
 * mount a share whose answer lacks `MS-Author-Via`, and no client offers to
 * lock a resource on a server whose `DAV` header does not say `2`. Everything
 * here is therefore a promise, and a promise the server cannot keep is worse
 * than one it never made — which is why the classes come from the plugins that
 * are actually registered rather than from a list somebody wrote once.
 */
#[CoversClass(Options::class)]
#[CoversClass(OptionsRequested::class)]
final class OptionsTest extends TestCase
{
    public function testAnswers(): void
    {
        self::assertSame(200, $this->answer()->status());
    }

    /**
     * RFC 4918 §18.1: every WebDAV server is at least class 1, and a server
     * that claimed nothing would be taken for a plain HTTP server.
     */
    public function testSaysItIsAWebDavServerAtAll(): void
    {
        self::assertSame('1', $this->answer()->headers()->first('DAV'));
    }

    /**
     * Without it the Windows redirector refuses to work with the server at
     * all, and says nothing useful about why (RFC 4918 has no such header —
     * this one is Microsoft's).
     */
    public function testTellsWindowsItMayWrite(): void
    {
        self::assertSame('DAV', $this->answer()->headers()->first('MS-Author-Via'));
    }

    public function testSendsNothingAtAll(): void
    {
        $response = $this->answer();

        self::assertNull($response->body());
        self::assertSame('0', $response->headers()->first('Content-Length'));
    }

    public function testNamesTheMethodsTheServerAnswers(): void
    {
        $server = $this->server();
        $server->onMethod('GET', static fn (): Response => new Response(200));
        $server->onMethod('PROPFIND', static fn (): Response => new Response(207));

        $allow = $this->answer($server)->headers()->first('Allow');

        self::assertSame('OPTIONS, GET, PROPFIND', $allow);
    }

    /**
     * A plugin that answers a method on the event before it — which is how the
     * sharing protocol takes over a `POST` — has no handler of its own, and
     * would otherwise be left out of a list clients read as the truth.
     */
    public function testAPluginCanNameAMethodOfItsOwn(): void
    {
        $events = new EventEmitter();
        $events->on(OptionsRequested::class, static function (OptionsRequested $event): void {
            $event->allowMethod('POST');
        });

        self::assertSame('OPTIONS, POST', $this->answer($this->server($events))->headers()->first('Allow'));
    }

    /**
     * R-HTTP-11: the classes are worked out from what is registered. The lock
     * plugin says `2`, access control says `3`, CalDAV says
     * `calendar-access` — and a server without them says none of it.
     */
    public function testAPluginNamesItsOwnComplianceClass(): void
    {
        $events = new EventEmitter();
        $events->on(OptionsRequested::class, static function (OptionsRequested $event): void {
            $event->addCompliance('2');
        });

        self::assertSame('1, 2', $this->answer($this->server($events))->headers()->first('DAV'));
    }

    public function testAPluginMayNameSeveralAtOnce(): void
    {
        $events = new EventEmitter();
        $events->on(OptionsRequested::class, static function (OptionsRequested $event): void {
            $event->addCompliance('3', 'access-control', 'calendar-access');
        });

        self::assertSame('1, 3, access-control, calendar-access', $this->answer($this->server($events))->headers()->first('DAV'));
    }

    /**
     * Two plugins may believe the same thing about the server — the lock
     * plugin and a sharing plugin both need class 2 — and the header must not
     * say so twice.
     */
    public function testAClassTwoPluginsNameIsListedOnce(): void
    {
        $events = new EventEmitter();
        $events->on(OptionsRequested::class, static function (OptionsRequested $event): void {
            $event->addCompliance('2');
        });
        $events->on(OptionsRequested::class, static function (OptionsRequested $event): void {
            $event->addCompliance('2', '3');
        });

        self::assertSame('1, 2, 3', $this->answer($this->server($events))->headers()->first('DAV'));
    }

    public function testAMethodTwoPluginsNameIsListedOnce(): void
    {
        $events = new EventEmitter();
        $events->on(OptionsRequested::class, static function (OptionsRequested $event): void {
            $event->allowMethod('GET', 'POST');
        });

        $server = $this->server($events);
        $server->onMethod('GET', static fn (): Response => new Response(200));

        self::assertSame('OPTIONS, GET, POST', $this->answer($server)->headers()->first('Allow'));
    }

    /**
     * RFC 9110 §7.1 lets a client ask about the server itself rather than
     * about a resource, and `OPTIONS *` is how it does so. Nothing here looks
     * at the path, so it answers like any other — but a server that fell over
     * it would fail the very first request several clients send.
     */
    public function testAnswersTheQuestionAboutTheServerItself(): void
    {
        $server = $this->server();
        $options = new Options($server);
        $server->onMethod('OPTIONS', $options(...));

        self::assertSame(200, $server->handle(new Request('OPTIONS', '*'))->status());
    }

    public function testTheEventCarriesTheRequest(): void
    {
        $seen = null;
        $events = new EventEmitter();
        $events->on(OptionsRequested::class, static function (OptionsRequested $event) use (&$seen): void {
            $seen = $event->request();
        });

        $server = $this->server($events);
        $options = new Options($server);
        $server->onMethod('OPTIONS', $options(...));

        $request = new Request('OPTIONS', '/calendars/');
        $server->handle($request);

        self::assertSame($request, $seen);
    }

    /**
     * A server whose methods are all answered by plugins has no handler of its
     * own, and `OPTIONS` still has to say what it does: itself, and whatever
     * the plugins name.
     */
    public function testAnswersForAServerThatHasNoHandlersAtAll(): void
    {
        $response = (new Options($this->server()))(new Request('OPTIONS', '/'));

        self::assertSame('OPTIONS', $response->headers()->first('Allow'));
        self::assertSame('1', $response->headers()->first('DAV'));
    }

    private function server(?EventEmitter $events = null): Server
    {
        return new Server(new Tree(new MemoryCollection('')), $events);
    }

    private function answer(?Server $server = null): Response
    {
        $server ??= $this->server();
        $options = new Options($server);
        $server->onMethod('OPTIONS', $options(...));

        return $server->handle(new Request('OPTIONS', '/'));
    }
}
