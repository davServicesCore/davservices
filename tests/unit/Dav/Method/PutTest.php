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

use DavServices\Dav\Event\AfterCreateFile;
use DavServices\Dav\Event\AfterWriteContent;
use DavServices\Dav\Event\BeforeCreateFile;
use DavServices\Dav\Event\BeforeWriteContent;
use DavServices\Dav\Method\Put;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Conflict;
use DavServices\Exception\MethodNotAllowed;
use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-DAV-01 (`PUT`), R-DAV-09 (`Content-Range` is
 * refused), R-HTTP-09 (`Expect: 100-continue`), R-TREE-03 (the body arrives as
 * a stream) and R-ARC-04 (the four extension points a write raises).
 *
 * The first method that changes anything, and the statuses matter more than
 * they look: `201` and `204` are how a client learns whether it created
 * something or replaced it, and a client that is told the wrong one writes the
 * wrong thing into its own cache.
 *
 * Two refusals are worth more than they cost. A `PUT` carrying
 * `Content-Range` is refused outright, because a server that ignores that
 * header writes the *part* as though it were the whole file — silent data
 * loss, and the client has no way to tell. And a `PUT` into a collection that
 * is not there is a `409`: creating the ancestors quietly is how a typo
 * becomes a tree of empty folders.
 */
#[CoversClass(Put::class)]
#[CoversClass(BeforeCreateFile::class)]
#[CoversClass(AfterCreateFile::class)]
#[CoversClass(BeforeWriteContent::class)]
#[CoversClass(AfterWriteContent::class)]
final class PutTest extends TestCase
{
    public function testCreatesAFileThatWasNotThere(): void
    {
        $root = $this->tree();

        $response = $this->put($root, '/notes.txt', 'a new note');

        self::assertSame(201, $response->status());
        self::assertTrue($root->hasChild('notes.txt'));
    }

    public function testReplacesAFileThatWasThere(): void
    {
        $root = $this->tree();
        $root->add(new MemoryFile('notes.txt', 'the old note'));

        $response = $this->put($root, '/notes.txt', 'the new note');

        self::assertSame(204, $response->status());
        self::assertSame('the new note', $this->contentOf($root, 'notes.txt'));
    }

    /**
     * R-TREE-03: the body reaches the backend as a stream. A `PUT` of a
     * recording would otherwise be read into memory on its way past.
     */
    public function testTheBodyReachesTheBackendAsAStream(): void
    {
        $root = $this->tree();
        $file = new MemoryFile('notes.txt', 'the old note');
        $root->add($file);

        $this->put($root, '/notes.txt', 'the new note');

        self::assertTrue($file->wasWrittenFromAStream);
    }

    public function testTellsTheClientWhatTheFileIsTaggedAs(): void
    {
        $root = $this->tree();
        $root->add(new MemoryFile('notes.txt', 'the old note'));

        $response = $this->put($root, '/notes.txt', 'the new note');

        self::assertSame('"' . md5('the new note') . '"', $response->headers()->first('ETag'));
    }

    /**
     * A backend that cannot say what it stored is the ordinary case. Inventing
     * an entity tag here would be worse than sending none: the client would
     * believe it and never notice the file had changed underneath.
     */
    public function testSendsNoTagWhereTheBackendGivesNone(): void
    {
        $response = $this->put($this->tree(), '/notes.txt', 'a new note');

        self::assertFalse($response->headers()->has('ETag'));
    }

    public function testSendsTheTagOfWhatItCreatedWhereTheBackendGivesOne(): void
    {
        $root = $this->tree()->tellsItsEtag();

        $response = $this->put($root, '/notes.txt', 'a new note');

        self::assertSame('"' . md5('a new note') . '"', $response->headers()->first('ETag'));
    }

    /**
     * RFC 4918 §9.7.1. Creating the ancestors quietly is how one typo becomes
     * a tree of empty folders nobody meant to make.
     */
    public function testRefusesToWriteIntoACollectionThatIsNotThere(): void
    {
        $response = $this->put($this->tree(), '/nowhere/notes.txt', 'a new note');

        self::assertSame(409, $response->status());
    }

    /**
     * A client with a wrong path asks to write inside a file. There is no such
     * place, and there never can be, so it is refused for the same reason a
     * missing collection is.
     */
    public function testRefusesToWriteInsideAFile(): void
    {
        $root = $this->tree();
        $root->add(new MemoryFile('notes.txt', 'a note'));

        self::assertSame(409, $this->put($root, '/notes.txt/child.txt', 'nonsense')->status());
    }

    public function testACollectionIsNotSomethingToOverwrite(): void
    {
        $root = $this->tree();
        $root->add(new MemoryCollection('calendars'));

        self::assertSame(405, $this->put($root, '/calendars', 'nonsense')->status());
    }

    /**
     * R-DAV-09. A server that ignores this header writes the part as though it
     * were the whole file, and the client has no way to tell that the rest is
     * gone. Refusing is the only answer that cannot lose data.
     */
    public function testRefusesAWriteThatClaimsToBeAPartOfAFile(): void
    {
        $root = $this->tree();
        $root->add(new MemoryFile('notes.txt', 'the old note'));

        $response = $this->put($root, '/notes.txt', 'a part', ['Content-Range' => 'bytes 0-5/100']);

        self::assertSame(400, $response->status());
        self::assertSame('the old note', $this->contentOf($root, 'notes.txt'));
    }

    /**
     * R-HTTP-09, as far as a library can keep it. The interim `100 Continue`
     * is the web server's to send, but its whole purpose is that a client need
     * not upload a gigabyte to be told the write was never going to be
     * allowed — so everything that can refuse this request is decided before
     * the body is touched at all.
     */
    public function testDecidesWhetherItWillWriteBeforeItReadsTheBody(): void
    {
        $source = fopen('php://memory', 'r+b');

        self::assertIsResource($source);

        fwrite($source, str_repeat('x', 4096));
        rewind($source);

        $this->putBody($this->tree(), '/nowhere/notes.txt', new Body($source));

        self::assertSame(0, ftell($source), 'The body was read before the request was refused.');
    }

    public function testTellsTheListenersBeforeItCreatesAFile(): void
    {
        $seen = null;
        $events = new EventEmitter();
        $events->on(BeforeCreateFile::class, static function (BeforeCreateFile $event) use (&$seen): void {
            $seen = $event->path();
        });

        $this->put($this->tree(), '/notes.txt', 'a new note', events: $events);

        self::assertSame('notes.txt', $seen);
    }

    /**
     * The seam a protocol extension validates through: CalDAV refuses an
     * object it cannot parse here, and a plugin that normalises what it stores
     * changes it here rather than after the fact.
     */
    public function testAListenerCanChangeWhatIsWritten(): void
    {
        $events = new EventEmitter();
        $events->on(BeforeCreateFile::class, static function (BeforeCreateFile $event): void {
            $event->writeInstead('what the plugin decided');
        });

        $root = $this->tree();
        $this->put($root, '/notes.txt', 'what the client sent', events: $events);

        self::assertSame('what the plugin decided', $this->contentOf($root, 'notes.txt'));
    }

    public function testTellsTheListenersAfterItCreatedAFile(): void
    {
        $seen = null;
        $events = new EventEmitter();
        $events->on(AfterCreateFile::class, static function (AfterCreateFile $event) use (&$seen): void {
            $seen = $event->path();
        });

        $this->put($this->tree(), '/notes.txt', 'a new note', events: $events);

        self::assertSame('notes.txt', $seen);
    }

    public function testTellsTheListenersAroundAWriteToAFileThatWasThere(): void
    {
        $seen = [];
        $events = new EventEmitter();
        $events->on(BeforeWriteContent::class, static function (BeforeWriteContent $event) use (&$seen): void {
            $seen['before'] = $event->path();
        });
        $events->on(AfterWriteContent::class, static function (AfterWriteContent $event) use (&$seen): void {
            $seen['after'] = $event->path();
        });

        $root = $this->tree();
        $root->add(new MemoryFile('notes.txt', 'the old note'));

        $this->put($root, '/notes.txt', 'the new note', events: $events);

        self::assertSame(['before' => 'notes.txt', 'after' => 'notes.txt'], $seen);
    }

    public function testAListenerCanChangeWhatIsWrittenOverAFileThatWasThere(): void
    {
        $events = new EventEmitter();
        $events->on(BeforeWriteContent::class, static function (BeforeWriteContent $event): void {
            $event->writeInstead('what the plugin decided');
        });

        $root = $this->tree();
        $root->add(new MemoryFile('notes.txt', 'the old note'));

        $this->put($root, '/notes.txt', 'what the client sent', events: $events);

        self::assertSame('what the plugin decided', $this->contentOf($root, 'notes.txt'));
    }

    /**
     * R-TREE-05 the other way round: the tree keeps what it found for the
     * length of a request, and a write makes some of that wrong. A node handed
     * out after it was written over is worse than one that was never cached,
     * so the write says what to forget — and the proof is that the backend is
     * asked again.
     */
    public function testTheTreeForgetsTheFileThatWasWrittenOver(): void
    {
        $calendars = new MemoryCollection('calendars');
        $calendars->add(new MemoryFile('notes.txt', 'the old note'));

        $root = $this->tree();
        $root->add($calendars);

        $tree = new Tree($root);
        $server = $this->serverFor($tree);

        $tree->node('calendars/notes.txt');
        $server->handle(new Request('PUT', '/calendars/notes.txt', body: new Body('the new note')));
        $tree->node('calendars/notes.txt');

        self::assertSame(2, $calendars->lookups, 'The file was handed out from the cache after it was written.');
    }

    /**
     * And the collection a file was created in: a listing made before the
     * write would otherwise be handed out without the new member in it.
     */
    public function testTheTreeForgetsTheCollectionTheFileWasCreatedIn(): void
    {
        $root = $this->tree();
        $root->add(new MemoryCollection('calendars'));

        $tree = new Tree($root);
        $server = $this->serverFor($tree);

        $tree->node('calendars');
        $server->handle(new Request('PUT', '/calendars/notes.txt', body: new Body('a new note')));
        $tree->node('calendars');

        self::assertSame(2, $root->lookups, 'The collection was handed out from the cache after a file was added.');
    }

    private function serverFor(Tree $tree): Server
    {
        $server = new Server($tree);
        $put = new Put($server);
        $server->onMethod('PUT', $put(...));

        return $server;
    }

    /**
     * Asked as a method rather than through the server: the decisions are the
     * refusals, and here they are still the refusals they were thrown as.
     */
    public function testAskedAsAMethodItCreatesTheFile(): void
    {
        $root = $this->tree();

        $response = ($this->method($root))(new Request('PUT', '/notes.txt', body: new Body('a new note')));

        self::assertSame(201, $response->status());
    }

    public function testAskedAsAMethodItReplacesTheFile(): void
    {
        $root = $this->tree();
        $root->add(new MemoryFile('notes.txt', 'the old note'));

        $response = ($this->method($root))(new Request('PUT', '/notes.txt', body: new Body('the new note')));

        self::assertSame(204, $response->status());
    }

    public function testAskedAsAMethodItRefusesAPartOfAFile(): void
    {
        $this->expectException(BadRequest::class);

        ($this->method($this->tree()))(new Request(
            'PUT',
            '/notes.txt',
            new Headers(['Content-Range' => 'bytes 0-5/100']),
            new Body('a part'),
        ));
    }

    public function testAskedAsAMethodItRefusesACollection(): void
    {
        $root = $this->tree();
        $root->add(new MemoryCollection('calendars'));

        $this->expectException(MethodNotAllowed::class);

        ($this->method($root))(new Request('PUT', '/calendars', body: new Body('nonsense')));
    }

    public function testAskedAsAMethodItRefusesAMissingCollection(): void
    {
        $this->expectException(Conflict::class);

        ($this->method($this->tree()))(new Request('PUT', '/nowhere/notes.txt', body: new Body('a note')));
    }

    private function tree(): MemoryCollection
    {
        return new MemoryCollection('');
    }

    private function method(MemoryCollection $root, ?EventEmitter $events = null): Put
    {
        return new Put(new Server(new Tree($root), $events));
    }

    /**
     * @param array<string, string> $headers
     */
    private function put(
        MemoryCollection $root,
        string $target,
        string $content,
        array $headers = [],
        ?EventEmitter $events = null,
    ): Response {
        return $this->putBody($root, $target, new Body($content), $headers, $events);
    }

    /**
     * @param array<string, string> $headers
     */
    private function putBody(
        MemoryCollection $root,
        string $target,
        Body $body,
        array $headers = [],
        ?EventEmitter $events = null,
    ): Response {
        $server = new Server(new Tree($root), $events);
        $put = new Put($server);
        $server->onMethod('PUT', $put(...));

        return $server->handle(new Request('PUT', $target, new Headers($headers), $body));
    }

    private function contentOf(MemoryCollection $root, string $name): string
    {
        $file = $root->child($name);

        if (!$file instanceof MemoryFile) {
            self::fail(sprintf('"%s" is no file.', $name));
        }

        return $file->get();
    }
}
