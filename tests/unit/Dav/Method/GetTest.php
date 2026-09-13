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

use DateTimeImmutable;
use DavServices\Dav\Method\Get;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Exception\MethodNotAllowed;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\StreamFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-DAV-01 (`GET` and `HEAD`), R-HTTP-10 (the same
 * headers either way), R-HTTP-05 (ranges) and R-TREE-02 (streaming).
 *
 * Two things this method must not do, and both are easy to do by accident.
 *
 * It must not read a file into a string to send it: a recording of a meeting
 * would then cost as much memory as the recording is long, and a server that
 * does this falls over on the first large file rather than on the tenth.
 *
 * And `HEAD` must answer exactly as `GET` does, minus the body. Clients use it
 * to check a length or an entity tag before they commit to a transfer, and one
 * that gets different headers from the two is a client that will either
 * transfer needlessly or not at all.
 */
#[CoversClass(Get::class)]
final class GetTest extends TestCase
{
    private const CONTENT = 'BEGIN:VCALENDAR and a great deal more';

    public function testSendsTheFile(): void
    {
        $response = $this->get('/work.ics');

        self::assertSame(200, $response->status());
        self::assertSame(self::CONTENT, $this->bodyOf($response));
    }

    /**
     * R-TREE-02: what the node handed over is what goes out. A string here
     * would mean the whole file had been read to send it.
     */
    public function testSendsTheFileAsTheStreamTheNodeHandedOver(): void
    {
        self::assertIsResource($this->get('/work.ics')->body());
    }

    public function testTellsTheClientWhatItIsSending(): void
    {
        $response = $this->get('/work.ics');

        self::assertSame('text/plain', $response->headers()->first('Content-Type'));
        self::assertSame((string) strlen(self::CONTENT), $response->headers()->first('Content-Length'));
        self::assertSame('"abc"', $response->headers()->first('ETag'));
    }

    /**
     * RFC 9110 §5.6.7 wants the date in the one format of IMF-fixdate, in GMT,
     * whatever the server's own time zone is — a client compares it byte for
     * byte with what it stored.
     */
    public function testTellsTheClientWhenTheFileLastChanged(): void
    {
        $file = new StreamFile('work.ics', self::CONTENT, lastModified: new DateTimeImmutable('2026-09-13 08:49:37 +02:00'));

        $response = $this->get('/work.ics', file: $file);

        self::assertSame('Sun, 13 Sep 2026 06:49:37 GMT', $response->headers()->first('Last-Modified'));
    }

    /**
     * A backend that cannot say is the ordinary case, not the odd one. What it
     * does not know is left out rather than invented: a wrong entity tag is
     * worse than none, because a client will believe it.
     */
    public function testLeavesOutWhatTheNodeCannotSay(): void
    {
        $file = new StreamFile('work.ics', self::CONTENT, contentType: null, etag: null, knowsItsLength: false);

        $response = $this->get('/work.ics', file: $file);

        self::assertFalse($response->headers()->has('Content-Type'));
        self::assertFalse($response->headers()->has('Content-Length'));
        self::assertFalse($response->headers()->has('ETag'));
        self::assertFalse($response->headers()->has('Last-Modified'));
    }

    /**
     * R-HTTP-10. The headers are the ones a `GET` would send, and there is no
     * body at all.
     */
    public function testHeadAnswersLikeAGetWithoutTheBody(): void
    {
        $get = $this->get('/work.ics');
        $head = $this->get('/work.ics', method: 'HEAD');

        self::assertSame($get->status(), $head->status());
        self::assertSame($get->headers()->toArray(), $head->headers()->toArray());
        self::assertNull($head->body());
    }

    /**
     * A collection is there, so it is not a `404`; `GET` simply does not apply
     * to it while no directory browser is registered (R-DAV-10).
     */
    public function testACollectionIsNotSomethingToGet(): void
    {
        self::assertSame(405, $this->get('/calendars')->status());
    }

    public function testACollectionIsNoMoreSomethingToAskTheHeadOf(): void
    {
        self::assertSame(405, $this->get('/calendars', method: 'HEAD')->status());
    }

    public function testAFileThatIsNotThereIsNotFound(): void
    {
        self::assertSame(404, $this->get('/nothing.ics')->status());
    }

    public function testSaysItCanSendPartsOfAFile(): void
    {
        self::assertSame('bytes', $this->get('/work.ics')->headers()->first('Accept-Ranges'));
    }

    /**
     * R-HTTP-05. The client asked for six bytes and gets six bytes, out of the
     * file's own stream rather than a copy of it.
     */
    public function testSendsThePartOfTheFileThatWasAskedFor(): void
    {
        $response = $this->get('/work.ics', headers: ['Range' => 'bytes=6-14']);

        self::assertSame(206, $response->status());
        self::assertSame('VCALENDAR', $this->bodyOf($response));
        self::assertSame(sprintf('bytes 6-14/%d', strlen(self::CONTENT)), $response->headers()->first('Content-Range'));
        self::assertSame('9', $response->headers()->first('Content-Length'));
    }

    public function testSendsTheEndOfTheFileWhenThatIsWhatWasAskedFor(): void
    {
        $response = $this->get('/work.ics', headers: ['Range' => 'bytes=-4']);

        self::assertSame(206, $response->status());
        self::assertSame('more', $this->bodyOf($response));
    }

    /**
     * RFC 9110 §14.2: a range header the server cannot follow is ignored, and
     * the whole resource is sent. Refusing would lock a client out of a file it
     * is allowed to read over a header it need not have sent.
     */
    #[DataProvider('rangesToIgnore')]
    public function testSendsTheWholeFileWhenTheRangeSaysNothingItCanFollow(string $range): void
    {
        $response = $this->get('/work.ics', headers: ['Range' => $range]);

        self::assertSame(200, $response->status());
        self::assertSame(self::CONTENT, $this->bodyOf($response));
    }

    public function testAnswersAboutTheWholeFileWhenTheRangeSaysNothingItCanFollow(): void
    {
        $response = $this->get('/work.ics', method: 'HEAD', headers: ['Range' => 'items=0-10']);

        self::assertSame(200, $response->status());
        self::assertNull($response->body());
        self::assertSame((string) strlen(self::CONTENT), $response->headers()->first('Content-Length'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rangesToIgnore(): iterable
    {
        yield 'a unit nobody knows' => ['items=0-10'];
        yield 'nothing readable' => ['bytes=abc'];
        yield 'reversed, which RFC 9110 §14.1.1 calls invalid' => ['bytes=20-10'];
        yield 'more than one range' => ['bytes=0-5,10-15'];
    }

    /**
     * A range that is well formed but asks for bytes beyond the file is the
     * one case that is refused, and the answer says how long the file really
     * is so that the client can ask again (RFC 9110 §15.5.17).
     *
     * Asked either way: R-HTTP-10 is a claim about every answer this method
     * gives, not only about the plain one, and a `HEAD` that took a different
     * turn through the code would be the kind of difference nobody notices
     * until a client does.
     */
    #[DataProvider('bothMethods')]
    public function testRefusesARangeBeyondTheEndOfTheFile(string $method): void
    {
        $response = $this->get('/work.ics', method: $method, headers: ['Range' => 'bytes=9999-']);

        self::assertSame(416, $response->status());
        self::assertSame(sprintf('bytes */%d', strlen(self::CONTENT)), $response->headers()->first('Content-Range'));
        self::assertNull($response->body());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bothMethods(): iterable
    {
        yield 'asked for' => ['GET'];
        yield 'asked about' => ['HEAD'];
    }

    /**
     * A backend that cannot say how long a file is cannot have a range served
     * from it — there is nothing to measure the range against — so the header
     * is ignored and the whole file goes out.
     */
    #[DataProvider('bothMethods')]
    public function testIgnoresARangeOfAFileOfUnknownLength(string $method): void
    {
        $file = new StreamFile('work.ics', self::CONTENT, knowsItsLength: false);

        $response = $this->get('/work.ics', method: $method, file: $file, headers: ['Range' => 'bytes=0-5']);

        self::assertSame(200, $response->status());
        self::assertFalse($response->headers()->has('Content-Length'));
    }

    public function testHeadOfAPartIsStillWithoutABody(): void
    {
        $response = $this->get('/work.ics', method: 'HEAD', headers: ['Range' => 'bytes=6-14']);

        self::assertSame(206, $response->status());
        self::assertNull($response->body());
        self::assertSame('9', $response->headers()->first('Content-Length'));
    }

    /**
     * `IFile::get()` may hand over a string as well as a stream — a backend
     * holding small objects in memory does, and so will the PDO one. Both
     * have to be served, and a range of either has to be the same range.
     */
    public function testServesAFileWhoseContentIsAString(): void
    {
        $response = $this->fromMemory('/notes.txt');

        self::assertSame(200, $response->status());
        self::assertSame('a short note', $response->body());
    }

    public function testServesAPartOfAFileWhoseContentIsAString(): void
    {
        $response = $this->fromMemory('/notes.txt', ['Range' => 'bytes=2-6']);

        self::assertSame(206, $response->status());
        self::assertSame('short', $response->body());
        self::assertSame('bytes 2-6/12', $response->headers()->first('Content-Range'));
    }

    public function testAnswersAboutAFileWhoseContentIsAString(): void
    {
        $response = $this->fromMemory('/notes.txt', method: 'HEAD');

        self::assertSame(200, $response->status());
        self::assertNull($response->body());
        self::assertSame('12', $response->headers()->first('Content-Length'));
    }

    /**
     * @param array<string, string> $headers
     */
    private function fromMemory(string $target, array $headers = [], string $method = 'GET'): Response
    {
        $root = new MemoryCollection('');
        $root->createFile('notes.txt', 'a short note');

        $server = new Server(new Tree($root));
        $get = new Get($server);
        $server->onMethod('GET', $get(...));
        $server->onMethod('HEAD', $get(...));

        return $server->handle(new Request($method, $target, new Headers($headers)));
    }

    /**
     * The three decisions this method makes, asked of the method itself rather
     * than through the server: whether the node is something to fetch, and
     * whether the answer keeps its body.
     *
     * Going through the server is how a client reaches it, and most of these
     * tests do — but a refusal is a status by then, and here it is still the
     * refusal it was thrown as.
     */
    public function testAskedAsAMethodItAnswersWithTheFile(): void
    {
        $response = ($this->method())(new Request('GET', '/work.ics'));

        self::assertSame(200, $response->status());
        self::assertIsResource($response->body());
    }

    public function testAskedAsAMethodAboutAFileItKeepsNoBody(): void
    {
        self::assertNull(($this->method())(new Request('HEAD', '/work.ics'))->body());
    }

    public function testAskedAsAMethodForACollectionItRefuses(): void
    {
        $this->expectException(MethodNotAllowed::class);

        ($this->method())(new Request('GET', '/calendars'));
    }

    private function method(): Get
    {
        $root = new MemoryCollection('');
        $root->add(new StreamFile('work.ics', self::CONTENT));
        $root->add(new MemoryCollection('calendars'));

        return new Get(new Server(new Tree($root)));
    }

    /**
     * @param array<string, string> $headers
     */
    private function get(
        string $target,
        string $method = 'GET',
        ?StreamFile $file = null,
        array $headers = [],
    ): Response {
        $root = new MemoryCollection('');
        $root->add($file ?? new StreamFile('work.ics', self::CONTENT));
        $root->add(new MemoryCollection('calendars'));

        $server = new Server(new Tree($root));
        $get = new Get($server);
        $server->onMethod('GET', $get(...));
        $server->onMethod('HEAD', $get(...));

        return $server->handle(new Request($method, $target, new Headers($headers)));
    }

    private function bodyOf(Response $response): string
    {
        $body = $response->body();

        if (is_string($body)) {
            return $body;
        }

        if (!is_resource($body)) {
            self::fail('The answer carries no body.');
        }

        $length = $response->bodyLength();

        return $length === null
            ? (string) stream_get_contents($body)
            : (string) stream_get_contents($body, $length);
    }
}
