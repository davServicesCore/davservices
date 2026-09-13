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

namespace DavServices\Tests\Unit\Backend\File;

use DavServices\Backend\File\Directory;
use DavServices\Backend\File\PropertyStorage;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\Method\Copy;
use DavServices\Dav\Method\Delete;
use DavServices\Dav\Method\Get;
use DavServices\Dav\Method\MkCol;
use DavServices\Dav\Method\Move;
use DavServices\Dav\Method\Options;
use DavServices\Dav\Method\PropFind;
use DavServices\Dav\Method\PropPatch;
use DavServices\Dav\Method\Put;
use DavServices\Dav\Property\LiveProperties;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Plugin\DeadProperties;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The whole library over a real directory, which is the first time it is a
 * server rather than a set of parts.
 *
 * Every other test in this suite stands one class up against doubles. This one
 * puts the pieces together the way an application would — a directory on a
 * disc, the methods, the live properties, a store for the dead ones — and
 * makes a client's own sequence of requests: make a collection, write into it,
 * read it back, ask what is there, name it, copy it, move it, remove it.
 *
 * It proves nothing about any single class, and it is the test that would
 * notice if two of them stopped fitting together.
 */
#[CoversNothing]
final class ServerOnADirectoryTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/davservices-server-' . bin2hex(random_bytes(8));

        if (!mkdir($root) || !mkdir($root . '/files') || !mkdir($root . '/properties')) {
            self::fail(sprintf('The test could not make "%s".', $root));
        }

        $this->root = $root;
    }

    protected function tearDown(): void
    {
        self::removeEverythingIn($this->root);
    }

    public function testServesACollectionOfFilesFromEndToEnd(): void
    {
        $server = $this->server();

        self::assertSame(201, $this->ask($server, 'MKCOL', '/calendars')->status());
        self::assertDirectoryExists($this->root . '/files/calendars');

        $written = $this->ask($server, 'PUT', '/calendars/work.ics', body: 'BEGIN:VCALENDAR');

        self::assertSame(201, $written->status());
        self::assertSame('BEGIN:VCALENDAR', file_get_contents($this->root . '/files/calendars/work.ics'));

        $read = $this->ask($server, 'GET', '/calendars/work.ics');

        self::assertSame(200, $read->status());
        self::assertSame('text/calendar', $read->headers()->first('Content-Type'));
        self::assertSame('BEGIN:VCALENDAR', stream_get_contents($this->streamOf($read)));
    }

    /**
     * What a client does first: ask what is there, and what each of them is.
     */
    public function testAnswersAListingWithWhatTheFilesystemKnows(): void
    {
        $server = $this->server();

        $this->ask($server, 'MKCOL', '/calendars');
        $this->ask($server, 'PUT', '/calendars/work.ics', body: 'BEGIN:VCALENDAR');

        $listing = (string) $this->ask($server, 'PROPFIND', '/calendars', ['Depth' => '1'])->body();

        self::assertSame(2, substr_count($listing, '<d:response>'));
        self::assertStringContainsString('<d:href>/calendars/</d:href>', $listing);
        self::assertStringContainsString('<d:href>/calendars/work.ics</d:href>', $listing);
        self::assertStringContainsString('<d:collection/>', $listing);
        self::assertStringContainsString('<d:getcontentlength>15</d:getcontentlength>', $listing);
        self::assertStringContainsString('<d:getcontenttype>text/calendar</d:getcontenttype>', $listing);
    }

    /**
     * A name a client gave its calendar is a dead property: the filesystem has
     * nowhere to put it, and the store beside it does.
     */
    public function testKeepsAPropertyTheFilesystemHasNowhereToPut(): void
    {
        $server = $this->server();

        $this->ask($server, 'MKCOL', '/calendars');
        $this->ask($server, 'PROPPATCH', '/calendars', body: '
            <D:propertyupdate xmlns:D="DAV:"><D:set><D:prop><D:displayname>Work</D:displayname></D:prop></D:set></D:propertyupdate>
        ');

        $listing = (string) $this->ask($server, 'PROPFIND', '/calendars', ['Depth' => '0'])->body();

        self::assertStringContainsString('<d:displayname>Work</d:displayname>', $listing);
    }

    /**
     * And it goes where the collection goes, and stays where a copy is made.
     */
    public function testCarriesThePropertiesWithACopyAndAMove(): void
    {
        $server = $this->server();

        $this->ask($server, 'MKCOL', '/calendars');
        $this->ask($server, 'PROPPATCH', '/calendars', body: '
            <D:propertyupdate xmlns:D="DAV:"><D:set><D:prop><D:displayname>Work</D:displayname></D:prop></D:set></D:propertyupdate>
        ');

        self::assertSame(201, $this->ask($server, 'COPY', '/calendars', ['Destination' => '/archive'])->status());
        self::assertStringContainsString('<d:displayname>Work</d:displayname>', (string) $this->ask($server, 'PROPFIND', '/archive', ['Depth' => '0'])->body());

        self::assertSame(201, $this->ask($server, 'MOVE', '/calendars', ['Destination' => '/last-year'])->status());
        self::assertDirectoryDoesNotExist($this->root . '/files/calendars');
        self::assertStringContainsString('<d:displayname>Work</d:displayname>', (string) $this->ask($server, 'PROPFIND', '/last-year', ['Depth' => '0'])->body());
    }

    public function testRemovesACollectionWithEverythingInIt(): void
    {
        $server = $this->server();

        $this->ask($server, 'MKCOL', '/calendars');
        $this->ask($server, 'PUT', '/calendars/work.ics', body: 'BEGIN:VCALENDAR');

        self::assertSame(204, $this->ask($server, 'DELETE', '/calendars')->status());
        self::assertDirectoryDoesNotExist($this->root . '/files/calendars');
        self::assertSame(404, $this->ask($server, 'GET', '/calendars/work.ics')->status());
    }

    /**
     * What a client asks before anything else, and what it decides from.
     */
    public function testSaysWhatItIsWhenItIsAsked(): void
    {
        $response = $this->ask($this->server(), 'OPTIONS', '/');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('1', (string) $response->headers()->first('DAV'));
        self::assertStringContainsString('PROPFIND', (string) $response->headers()->first('Allow'));
    }

    /**
     * A name the filesystem would quietly change is refused, and the refusal
     * reaches the client as a refusal rather than as a file it cannot find
     * again.
     */
    public function testRefusesANameTheFilesystemWouldNotKeep(): void
    {
        self::assertSame(403, $this->ask($this->server(), 'PUT', '/work.ics ', body: 'BEGIN:VCALENDAR')->status());
    }

    /**
     * The pieces an application puts together, in the order it would.
     */
    private function server(): Server
    {
        $events = new EventEmitter();
        $live = new LiveProperties();

        $events->on(PropertiesRequested::class, $live(...));
        (new DeadProperties(new PropertyStorage($this->root . '/properties')))->registerOn($events);

        $server = new Server(new Tree(new Directory($this->root . '/files')), $events);

        $get = new Get($server);
        $methods = [
            'OPTIONS' => new Options($server),
            'PUT' => new Put($server),
            'DELETE' => new Delete($server),
            'MKCOL' => new MkCol($server),
            'PROPFIND' => new PropFind($server),
            'PROPPATCH' => new PropPatch($server),
            'COPY' => new Copy($server),
            'MOVE' => new Move($server),
        ];

        $server->onMethod('GET', $get(...));
        $server->onMethod('HEAD', $get(...));

        foreach ($methods as $name => $method) {
            $server->onMethod($name, $method(...));
        }

        return $server;
    }

    /**
     * @param array<string, string> $headers
     */
    private function ask(Server $server, string $method, string $target, array $headers = [], ?string $body = null): Response
    {
        return $server->handle(new Request(
            $method,
            $target,
            new Headers($headers),
            $body === null ? null : new Body(trim($body)),
        ));
    }

    /**
     * @return resource
     */
    private function streamOf(Response $response): mixed
    {
        $body = $response->body();

        if (!is_resource($body)) {
            self::fail('The answer carried no stream.');
        }

        return $body;
    }

    private static function removeEverythingIn(string $directory): void
    {
        $entries = glob($directory . '/*');

        foreach ($entries === false ? [] : $entries as $entry) {
            is_dir($entry) ? self::removeEverythingIn($entry) : unlink($entry);
        }

        rmdir($directory);
    }
}
