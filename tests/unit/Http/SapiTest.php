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

namespace DavServices\Tests\Unit\Http;

use Closure;
use DavServices\Http\MalformedRequest;
use DavServices\Http\Response;
use DavServices\Http\Sapi;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-HTTP-02 (a request built from what the SAPI knows,
 * a response written out streaming) and R-HTTP-13 (output buffers emptied
 * before a body is streamed).
 *
 * Coming in: method, target and protocol version; the `HTTP_*` entries turned
 * into field names; `CONTENT_TYPE` and `CONTENT_LENGTH`, which PHP hands over
 * without that prefix; the `Authorization` that Apache moves out of the way;
 * and a body that is not read until somebody asks for it.
 *
 * Going out: the status line, one line per value of a field that repeats, a
 * string body, a stream body copied rather than read into memory, and no body
 * at all where there is none.
 *
 * In between: the output buffers, emptied before the body goes out, because a
 * buffer that is still open turns streaming a large file back into holding it
 * in memory.
 */
#[CoversClass(Sapi::class)]
final class SapiTest extends TestCase
{
    /** @var list<string> */
    private array $lines = [];

    /** @var resource|null */
    private mixed $output = null;

    protected function setUp(): void
    {
        $this->lines = [];

        $output = fopen('php://memory', 'r+b');

        self::assertIsResource($output);

        $this->output = $output;
    }

    public function testBuildsTheRequestFromWhatTheServerKnows(): void
    {
        $request = $this->sapi()->request([
            'REQUEST_METHOD' => 'PROPFIND',
            'REQUEST_URI' => '/calendars/alice/?depth=1',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
        ]);

        self::assertSame('PROPFIND', $request->method());
        self::assertSame('/calendars/alice/?depth=1', $request->target());
        self::assertSame('calendars/alice', $request->path());
        self::assertSame('depth=1', $request->query());
        self::assertSame('1.1', $request->protocolVersion());
    }

    /**
     * A client still on HTTP/1.0 gets answered in HTTP/1.0. Taking the version
     * from the environment only to hand back the same one either way would
     * make this look right while doing nothing.
     */
    public function testTakesTheProtocolVersionFromTheEnvironment(): void
    {
        $request = $this->sapi()->request([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'SERVER_PROTOCOL' => 'HTTP/1.0',
        ]);

        self::assertSame('1.0', $request->protocolVersion());
    }

    /**
     * @param array<string, mixed> $server
     */
    #[DataProvider('unusableProtocols')]
    public function testFallsBackToTheOnlyProtocolVersionItCanSpeak(array $server): void
    {
        $request = $this->sapi()->request(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'] + $server);

        self::assertSame('1.1', $request->protocolVersion());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unusableProtocols(): iterable
    {
        yield 'none at all' => [[]];
        yield 'one this server does not speak' => [['SERVER_PROTOCOL' => 'SPDY/3']];
    }

    public function testTurnsTheServerEntriesIntoFieldNames(): void
    {
        $request = $this->sapi()->request([
            'REQUEST_METHOD' => 'PROPFIND',
            'REQUEST_URI' => '/',
            'HTTP_DEPTH' => '1',
            'HTTP_IF_NONE_MATCH' => '"abc"',
            'HTTP_X_LITMUS' => 'basic: 3 (propfind_invalid)',
        ]);

        self::assertSame('1', $request->headers()->first('Depth'));
        self::assertSame('"abc"', $request->headers()->first('If-None-Match'));
        self::assertSame('basic: 3 (propfind_invalid)', $request->headers()->first('X-Litmus'));
    }

    /**
     * The spelling is not a matter of taste. Fields are matched without regard
     * to case, so nothing breaks either way — but `IF_NONE_MATCH` in a log is
     * a shout, and the name a person expects to read is the one the RFC uses.
     */
    public function testSpellsTheDerivedNamesTheWayTheRfcsDo(): void
    {
        $request = $this->sapi()->request([
            'REQUEST_METHOD' => 'PROPFIND',
            'REQUEST_URI' => '/',
            'HTTP_IF_NONE_MATCH' => '"abc"',
            'CONTENT_TYPE' => 'text/xml',
        ]);

        self::assertSame(
            ['If-None-Match' => ['"abc"'], 'Content-Type' => ['text/xml']],
            $request->headers()->toArray(),
        );
    }

    /**
     * PHP hands these two over without the `HTTP_` prefix every other field
     * carries. A server that misses them cannot tell XML from a calendar.
     */
    public function testPicksUpTheTwoFieldsThatComeWithoutThePrefix(): void
    {
        $request = $this->sapi()->request([
            'REQUEST_METHOD' => 'PUT',
            'REQUEST_URI' => '/work.ics',
            'CONTENT_TYPE' => 'text/calendar; charset=utf-8',
            'CONTENT_LENGTH' => '2048',
        ]);

        self::assertSame('text/calendar; charset=utf-8', $request->headers()->first('Content-Type'));
        self::assertSame('2048', $request->headers()->first('Content-Length'));
    }

    public function testIgnoresEntriesThatAreNoFieldOfARequest(): void
    {
        $request = $this->sapi()->request([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'DOCUMENT_ROOT' => '/var/www',
            'REMOTE_ADDR' => '192.0.2.1',
        ]);

        self::assertSame([], $request->headers()->toArray());
    }

    /**
     * Apache under CGI takes `Authorization` out of the environment and puts
     * it back with a prefix. Without this, Basic authentication fails on a
     * very common deployment and nothing says why.
     */
    public function testFindsTheAuthorizationApacheMovedOutOfTheWay(): void
    {
        $request = $this->sapi()->request([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'REDIRECT_HTTP_AUTHORIZATION' => 'Basic dXNlcjpwYXNz',
        ]);

        self::assertSame('Basic dXNlcjpwYXNz', $request->headers()->first('Authorization'));
    }

    public function testPrefersTheAuthorizationThatArrivedUnmoved(): void
    {
        $request = $this->sapi()->request([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_AUTHORIZATION' => 'Digest username="alice"',
            'REDIRECT_HTTP_AUTHORIZATION' => 'Basic dXNlcjpwYXNz',
        ]);

        self::assertSame('Digest username="alice"', $request->headers()->first('Authorization'));
    }

    public function testIgnoresAnEntryThatIsNotText(): void
    {
        $request = $this->sapi()->request([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_CONTENT_LENGTH' => 2048,
        ]);

        self::assertFalse($request->headers()->has('Content-Length'));
    }

    public function testCarriesTheBodyThatWasHandedOver(): void
    {
        $input = fopen('php://memory', 'r+b');

        self::assertIsResource($input);

        fwrite($input, '<propfind/>');
        rewind($input);

        $request = $this->sapi()->request(['REQUEST_METHOD' => 'PROPFIND', 'REQUEST_URI' => '/'], $input);

        self::assertSame('<propfind/>', $request->body()->contents());
    }

    /**
     * Left to itself the adapter opens `php://input`. The test stops at
     * proving that it is wired up: under the CLI that stream is the terminal,
     * and a test suite that reads it would sit there waiting for somebody to
     * type.
     */
    public function testOpensThePhpInputStreamWhenNoBodyIsHandedOver(): void
    {
        $request = $this->sapi()->request(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/']);

        self::assertSame('GET', $request->method());
    }

    /**
     * `fopen()` reports failure as `false`. A source that is not a stream is
     * no body, rather than an error nobody can do anything about.
     */
    public function testASourceThatIsNoStreamIsAnEmptyBody(): void
    {
        $request = $this->sapi()->request(['REQUEST_METHOD' => 'PUT', 'REQUEST_URI' => '/x'], false);

        self::assertTrue($request->body()->isEmpty());
    }

    /**
     * @param array<string, mixed> $server
     */
    #[DataProvider('incompleteEnvironments')]
    public function testRefusesAnEnvironmentThatNamesNoRequest(array $server): void
    {
        $this->expectException(MalformedRequest::class);

        $this->sapi()->request($server);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function incompleteEnvironments(): iterable
    {
        yield 'no method' => [['REQUEST_URI' => '/']];
        yield 'no target' => [['REQUEST_METHOD' => 'GET']];
        yield 'nothing at all' => [[]];
    }

    public function testWritesTheStatusLineAndTheFields(): void
    {
        $this->sapi()->send(
            (new Response(207, body: '<multistatus/>'))->withHeader('Content-Type', 'application/xml'),
        );

        self::assertSame(
            ['HTTP/1.1 207 Multi-Status', 'Content-Type: application/xml'],
            $this->lines,
        );
    }

    /**
     * A field that carries two values is two lines. Joining them with a comma
     * would be wrong for `WWW-Authenticate`, where each value is a whole
     * challenge of its own.
     */
    public function testWritesOneLinePerValueOfAFieldThatRepeats(): void
    {
        $this->sapi()->send(
            (new Response(401))->withHeader('WWW-Authenticate', 'Basic realm="dav"', 'Digest realm="dav"'),
        );

        self::assertSame(
            [
                'HTTP/1.1 401 Unauthorized',
                'WWW-Authenticate: Basic realm="dav"',
                'WWW-Authenticate: Digest realm="dav"',
            ],
            $this->lines,
        );
    }

    /**
     * RFC 9112 §4 allows an empty reason phrase, and the status line must not
     * be left with the trailing space that would come of pasting one in.
     */
    public function testLeavesNoTrailingSpaceWhenThereIsNoReasonPhrase(): void
    {
        $this->sapi()->send(new Response(299));

        self::assertSame(['HTTP/1.1 299'], $this->lines);
    }

    public function testWritesAStringBody(): void
    {
        $this->sapi()->send(new Response(200, body: '<multistatus/>'));

        self::assertSame('<multistatus/>', $this->written());
    }

    /**
     * A file is copied from its own stream rather than read into a string
     * first, which is the whole point of letting a node hand one over
     * (R-TREE-02).
     */
    public function testCopiesAStreamBodyStraightThrough(): void
    {
        $file = fopen('php://memory', 'r+b');

        self::assertIsResource($file);

        fwrite($file, 'BEGIN:VCALENDAR');
        rewind($file);

        $this->sapi()->send(new Response(200, body: $file));

        self::assertSame('BEGIN:VCALENDAR', $this->written());
    }

    public function testWritesNothingWhereThereIsNoBody(): void
    {
        $this->sapi()->send(new Response(204));

        self::assertSame('', $this->written());
    }

    /**
     * R-HTTP-13. An output buffer that is still open when a body is streamed
     * turns a file of any size back into a copy held in memory, so the buffers
     * are emptied first — flushed rather than discarded, since output that a
     * plugin produced by mistake is evidence of a bug and should be seen.
     */
    public function testEmptiesTheOutputBuffersBeforeTheBodyGoesOut(): void
    {
        $base = ob_get_level();

        ob_start();
        ob_start();
        echo 'left over by somebody';

        $sapi = new Sapi($this->collector(), $this->output, $base + 1);
        $sapi->send(new Response(200, body: 'the answer'));

        $levelAfterwards = ob_get_level();
        $flushed = ob_get_clean();

        self::assertSame($base + 1, $levelAfterwards, 'The stray buffer was left open.');
        self::assertSame('left over by somebody', $flushed);
        self::assertSame('the answer', $this->written());
    }

    /**
     * Left to itself the adapter writes through PHP's own `header()` and
     * `php://output`. Nothing else in the suite goes down that path, and a
     * default nobody ever runs is a default nobody can trust.
     */
    public function testWritesThroughPhpItselfWhenNothingElseIsGiven(): void
    {
        $base = ob_get_level();

        ob_start();

        (new Sapi(keepBufferLevels: $base + 1))->send(new Response(200, body: 'through php'));

        $written = ob_get_clean();

        self::assertSame('through php', $written);
    }

    private function sapi(): Sapi
    {
        return new Sapi($this->collector(), $this->output, ob_get_level());
    }

    /**
     * @return Closure(string): void
     */
    private function collector(): Closure
    {
        return function (string $line): void {
            $this->lines[] = $line;
        };
    }

    private function written(): string
    {
        $output = $this->output;

        if (!is_resource($output)) {
            self::fail('The output stream was never opened.');
        }

        rewind($output);

        return (string) stream_get_contents($output);
    }
}
