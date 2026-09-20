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
use DateTimeZone;
use DavServices\Dav\Event\BeforeMethod;
use DavServices\Dav\Method\Get;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Plugin\Browser;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Tests\Unit\Dav\StreamFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-DAV-10: a directory browser exists as a
 * development aid, is **off unless it is switched on**, and is safe against
 * what a client may call its files.
 *
 * A `GET` on a collection is a `405` in plain WebDAV: there is nothing to
 * send. This listens in front of the method and answers with a page instead,
 * which is how one looks at a tree with a browser while building something
 * against it.
 *
 * **It is not the web interface.** A server that switched it on in production
 * would be publishing the shape of every account to anyone who could reach the
 * path — and the requirement says so in as many words. The one thing this test
 * list guards most closely is therefore what happens when nobody switched it
 * on: nothing at all.
 *
 * And what a client calls its files is not to be trusted. A calendar named
 * `<script>` is a name, not markup, and a listing that forgot that would carry
 * the client's own script back to the next person who looked.
 */
#[CoversClass(Browser::class)]
final class BrowserTest extends TestCase
{
    /**
     * R-DAV-10: nothing happens unless an application asked for it. This is
     * the answer plain WebDAV gives, and the one a server keeps giving until
     * somebody writes the line that switches the browser on.
     */
    public function testAServerThatNobodySwitchedItOnStillRefusesToShowACollection(): void
    {
        $server = $this->server();
        $get = new Get($server);

        $server->onMethod('GET', $get(...));

        self::assertSame(405, $server->handle(new Request('GET', '/calendars'))->status());
    }

    public function testShowsWhatIsInACollection(): void
    {
        $response = $this->browse('/calendars');

        self::assertSame(200, $response->status());
        self::assertSame('text/html; charset=utf-8', $response->headers()->first('Content-Type'));
        self::assertStringContainsString('>work.ics<', (string) $response->body());
        self::assertStringContainsString('>alice<', (string) $response->body());
    }

    /**
     * Every member is a link to where it is, so that a browser can be used to
     * walk the tree — which is the whole of what this is for.
     */
    public function testLinksToEveryMember(): void
    {
        $page = (string) $this->browse('/calendars')->body();

        self::assertStringContainsString('href="/calendars/work.ics"', $page);
        self::assertStringContainsString('href="/calendars/alice/"', $page);
    }

    /**
     * A collection is named with its trailing slash, as everywhere else: a
     * browser builds the next address by appending to this one.
     */
    public function testNamesACollectionWithItsTrailingSlash(): void
    {
        self::assertStringContainsString('href="/calendars/alice/"', (string) $this->browse('/calendars')->body());
    }

    public function testSaysHowLargeAFileIsBesideItsName(): void
    {
        $page = (string) $this->browse('/calendars')->body();

        self::assertStringContainsString('>work.ics</a> — 9 bytes</li>', $page);
    }

    /**
     * A page a browser will read as a page. The doctype keeps it out of quirks
     * mode, and **the charset is not decoration**: a browser left to guess at
     * an encoding can find markup in what was escaped as text, which would
     * undo the one thing this listing has to get right.
     */
    public function testWritesAPageABrowserCanRead(): void
    {
        $page = (string) $this->browse('/calendars')->body();

        self::assertStringStartsWith('<!DOCTYPE html>', $page);
        self::assertStringContainsString('<html lang="en">', $page);
        self::assertStringContainsString('<meta charset="utf-8">', $page);
        self::assertStringContainsString('<h1>/calendars/</h1>', $page);
        self::assertStringEndsWith("</html>\n", $page);
    }

    /**
     * And when it changed, where the backend knows. One that does not say is
     * not guessed at: an invented time in a listing is a time somebody will
     * believe.
     */
    public function testSaysWhenAFileChangedWhereTheBackendKnows(): void
    {
        $root = new MemoryCollection('');
        $calendars = new MemoryCollection('calendars');

        $calendars->add(new StreamFile(
            'work.ics',
            'a meeting',
            'text/calendar',
            '"abc"',
            new DateTimeImmutable('2026-09-13 12:34:56', new DateTimeZone('UTC')),
        ));
        $root->add($calendars);

        self::assertStringContainsString('Sun, 13 Sep 2026 12:34:56 GMT', (string) $this->browseIn($root, '/calendars')->body());
    }

    /**
     * A target that cannot be resolved at all is the server's business, and it
     * answers `400`. A listener that tried to make a page of it would be
     * answering a request nobody could read.
     */
    public function testLeavesATargetThatCannotBeResolvedAlone(): void
    {
        $event = new BeforeMethod(new Request('GET', '/calendars/../../etc/passwd'));

        $this->browserOn($this->treeWithACalendar())($event);

        self::assertNull($event->response());
    }

    /**
     * The way back, which is what makes it usable at all. The root has nowhere
     * to go back to, and says nothing rather than linking to itself.
     */
    public function testLinksToTheCollectionAbove(): void
    {
        self::assertStringContainsString('href="/"', (string) $this->browse('/calendars')->body());
        self::assertStringNotContainsString('href="/"', (string) $this->browse('/')->body());
    }

    /**
     * R-DAV-10, and the reason this test list exists: what a client calls its
     * files is a name, not markup. A listing that forgot that would carry the
     * client's own script back to the next person who looked at the tree.
     */
    public function testWritesANameAsTextAndNeverAsMarkup(): void
    {
        $root = new MemoryCollection('');
        $root->add(new MemoryCollection('calendars'));

        $calendars = $root->child('calendars');

        self::assertInstanceOf(MemoryCollection::class, $calendars);
        $calendars->add(new MemoryFile('<script>alert(1)</script>.ics', 'a meeting'));

        $page = (string) $this->browseIn($root, '/calendars')->body();

        self::assertStringNotContainsString('<script>', $page);
        self::assertStringContainsString('&lt;script&gt;', $page);
    }

    /**
     * And a quotation mark is a name too: one that would otherwise end the
     * attribute it stands in and start whatever came after it.
     */
    public function testWritesANameWithAQuotationMarkSafely(): void
    {
        $root = new MemoryCollection('');
        $root->add(new MemoryCollection('calendars'));

        $calendars = $root->child('calendars');

        self::assertInstanceOf(MemoryCollection::class, $calendars);
        $calendars->add(new MemoryFile('a" onmouseover="alert(1)', 'a meeting'));

        $page = (string) $this->browseIn($root, '/calendars')->body();

        self::assertStringNotContainsString('onmouseover="alert(1)"', $page);
        self::assertStringContainsString('&quot;', $page);
    }

    /**
     * A `GET` on a file is the business of the method: this one only steps in
     * where there is nothing to send.
     */
    public function testLeavesAFileToTheMethod(): void
    {
        $response = $this->browse('/calendars/work.ics');

        self::assertSame(200, $response->status());
        self::assertSame('a meeting', (string) $response->body());
    }

    public function testLeavesEveryOtherMethodAlone(): void
    {
        $event = new BeforeMethod(new Request('PROPFIND', '/calendars', new Headers(['Depth' => '0'])));

        $this->browserOn($this->treeWithACalendar())($event);

        self::assertNull($event->response(), 'The browser answered a request that was not its own.');
    }

    /**
     * A path that names nothing is the method's business as well: the answer
     * is a `404`, and a listing of nothing would be a worse one.
     */
    public function testLeavesAPathThatNamesNothingAlone(): void
    {
        $event = new BeforeMethod(new Request('GET', '/nowhere'));

        $this->browserOn($this->treeWithACalendar())($event);

        self::assertNull($event->response());
    }

    /**
     * The links carry the base the server is mounted at, like every other
     * address this library writes.
     */
    public function testWritesLinksThatCarryTheBaseUri(): void
    {
        $server = new Server(new Tree($this->treeWithACalendar()), null, baseUri: '/dav/');
        $event = new BeforeMethod(new Request('GET', '/dav/calendars'));

        Browser::forDevelopment($server)($event);

        self::assertStringContainsString('href="/dav/calendars/work.ics"', (string) $event->response()?->body());
    }

    /**
     * Whoever is looking at it is told what it is, because the page itself is
     * the last place the warning can still be read.
     */
    public function testSaysOnThePageWhatItIs(): void
    {
        self::assertStringContainsString('development', (string) $this->browse('/calendars')->body());
    }

    private function treeWithACalendar(): MemoryCollection
    {
        $calendars = new MemoryCollection('calendars');

        $calendars->add(new MemoryFile('work.ics', 'a meeting'));
        $calendars->add(new MemoryCollection('alice'));

        return (new MemoryCollection(''))->add($calendars);
    }

    private function browserOn(MemoryCollection $root): Browser
    {
        return Browser::forDevelopment(new Server(new Tree($root)));
    }

    private function browse(string $target): Response
    {
        return $this->browseIn($this->treeWithACalendar(), $target);
    }

    private function browseIn(MemoryCollection $root, string $target): Response
    {
        $events = new EventEmitter();
        $server = new Server(new Tree($root), $events);
        $browser = Browser::forDevelopment($server);
        $get = new Get($server);

        $events->on(BeforeMethod::class, $browser(...));
        $server->onMethod('GET', $get(...));

        return $server->handle(new Request('GET', $target));
    }

    private function server(): Server
    {
        return new Server(new Tree($this->treeWithACalendar()));
    }
}
