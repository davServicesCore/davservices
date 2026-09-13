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

use DavServices\Dav\Event\AfterMethod;
use DavServices\Dav\Event\BeforeMethod;
use DavServices\Dav\Event\ExceptionRaised;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Exception\Forbidden;
use DavServices\Exception\Locked;
use DavServices\Exception\NotFound;
use DavServices\Http\MalformedHeader;
use DavServices\Http\MalformedRequest;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Uri\MalformedPath;
use DavServices\Xml\Writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Test list, derived from R-ARC-02 (every extension is a plugin, and plain
 * WebDAV runs without any), R-ARC-04 (the extension points) and R-XML-06 (a
 * failure becomes a defined status and a `DAV:error` body).
 *
 * The server itself does almost nothing, and that is the design: it runs the
 * chain, picks the handler for the method, and turns whatever went wrong into
 * an answer. Every method of WebDAV arrives later as a handler, and every
 * protocol extension as a listener.
 *
 * The part worth testing hardest is the last one. A server that let an
 * exception through would answer a client with a stack trace; one that
 * answered every failure the same way would tell a client nothing it could
 * act on. What goes out has to be exactly the status of the failure — and for
 * the message, nothing at all where the failure was not one the client caused.
 */
#[CoversClass(Server::class)]
#[CoversClass(BeforeMethod::class)]
#[CoversClass(AfterMethod::class)]
#[CoversClass(ExceptionRaised::class)]
final class ServerTest extends TestCase
{
    /**
     * R-ARC-02: a server with nothing registered at all is a working server.
     * It answers `501` to everything, which is what a server that has been
     * given no methods should say.
     */
    public function testAServerWithoutAnyHandlerAnswersThatItDoesNotDoThat(): void
    {
        self::assertSame(501, $this->server()->handle(new Request('PROPFIND', '/'))->status());
    }

    public function testTheHandlerOfTheMethodAnswers(): void
    {
        $server = $this->server();
        $server->onMethod('GET', static fn (): Response => new Response(200, body: 'the file'));

        $response = $server->handle(new Request('GET', '/file.txt'));

        self::assertSame(200, $response->status());
        self::assertSame('the file', $response->body());
    }

    public function testEachMethodHasItsOwnHandler(): void
    {
        $server = $this->server();
        $server->onMethod('GET', static fn (): Response => new Response(200));
        $server->onMethod('DELETE', static fn (): Response => new Response(204));

        self::assertSame(200, $server->handle(new Request('GET', '/x'))->status());
        self::assertSame(204, $server->handle(new Request('DELETE', '/x'))->status());
        self::assertSame(501, $server->handle(new Request('MKCOL', '/x'))->status());
    }

    public function testTheHandlerIsGivenTheRequest(): void
    {
        $seen = null;
        $server = $this->server();
        $server->onMethod('GET', static function (Request $request) use (&$seen): Response {
            $seen = $request;

            return new Response(200);
        });

        $request = new Request('GET', '/file.txt');
        $server->handle($request);

        self::assertSame($request, $seen);
    }

    public function testRunsTheListenersBeforeTheMethod(): void
    {
        $order = [];
        $events = new EventEmitter();
        $events->on(BeforeMethod::class, static function () use (&$order): void {
            $order[] = 'before';
        });

        $server = $this->server($events);
        $server->onMethod('GET', static function () use (&$order): Response {
            $order[] = 'method';

            return new Response(200);
        });

        $server->handle(new Request('GET', '/x'));

        self::assertSame(['before', 'method'], $order);
    }

    /**
     * This is how a plugin takes a method over: it answers on the event and
     * stops it, and the server's own handler is never asked. Authentication
     * and the sharing protocol both work this way.
     */
    public function testAListenerThatAnswersReplacesTheMethod(): void
    {
        $events = new EventEmitter();
        $events->on(BeforeMethod::class, static function (BeforeMethod $event): void {
            $event->answerWith(new Response(401));
        });

        $server = $this->server($events);
        $server->onMethod('GET', static fn (): Response => new Response(200, body: 'never sent'));

        $response = $server->handle(new Request('GET', '/x'));

        self::assertSame(401, $response->status());
        self::assertNull($response->body());
    }

    /**
     * Stopping the event without answering keeps the later listeners out, and
     * nothing else. A plugin that means to take the method over says so by
     * answering — otherwise a plugin that only wanted to be last would silently
     * turn every request into a `501`.
     */
    public function testStoppingWithoutAnsweringLeavesTheMethodToItsHandler(): void
    {
        $events = new EventEmitter();
        $events->on(BeforeMethod::class, static function (BeforeMethod $event): void {
            $event->stop();
        });

        $server = $this->server($events);
        $server->onMethod('GET', static fn (): Response => new Response(200, body: 'sent after all'));

        self::assertSame('sent after all', $server->handle(new Request('GET', '/x'))->body());
    }

    public function testAListenerAfterTheMethodSeesTheAnswer(): void
    {
        $seen = null;
        $events = new EventEmitter();
        $events->on(AfterMethod::class, static function (AfterMethod $event) use (&$seen): void {
            $seen = $event->response()->status();
        });

        $server = $this->server($events);
        $server->onMethod('GET', static fn (): Response => new Response(207));

        $server->handle(new Request('GET', '/x'));

        self::assertSame(207, $seen);
    }

    /**
     * The answer a listener hands back is the answer that goes out — which is
     * how a plugin adds a header to everything the server sends.
     */
    public function testAListenerAfterTheMethodCanChangeTheAnswer(): void
    {
        $events = new EventEmitter();
        $events->on(AfterMethod::class, static function (AfterMethod $event): void {
            $event->answerWith($event->response()->withHeader('DAV', '1'));
        });

        $server = $this->server($events);
        $server->onMethod('GET', static fn (): Response => new Response(200));

        self::assertSame('1', $server->handle(new Request('GET', '/x'))->headers()->first('DAV'));
    }

    #[DataProvider('failuresOfTheClient')]
    public function testAFailureBecomesTheStatusItCarries(Throwable $failure, int $status): void
    {
        $server = $this->server();
        $server->onMethod('GET', static fn (): Response => throw $failure);

        self::assertSame($status, $server->handle(new Request('GET', '/x'))->status());
    }

    /**
     * The three refusals of the lower layers extend the SPL types rather than
     * this library's own, because a path or a header is refused long before
     * anything DAV-shaped is in play. The server is where they become an
     * answer, and `400` is what they all are: the client sent something that
     * cannot be made sense of.
     *
     * @return iterable<string, array{Throwable, int}>
     */
    public static function failuresOfTheClient(): iterable
    {
        yield 'nothing there' => [new NotFound('No such node.'), 404];
        yield 'not allowed' => [new Forbidden('Not for you.'), 403];
        yield 'locked' => [new Locked('Somebody else holds it.'), 423];
        yield 'a path that cannot be resolved' => [new MalformedPath('..'), 400];
        yield 'a header that could forge another' => [new MalformedHeader('A newline.'), 400];
        yield 'a request line nobody can read' => [new MalformedRequest('No method.'), 400];
    }

    /**
     * RFC 4918 §16: a refusal may name the precondition that was not met, and
     * a client that knows the precondition knows what to do differently.
     */
    public function testAPreconditionGoesOutAsADavErrorBody(): void
    {
        $server = $this->server();
        $server->onMethod('PROPFIND', static fn (): Response => throw new Forbidden(
            'Depth: infinity is disabled.',
            '{DAV:}propfind-finite-depth',
        ));

        $response = $server->handle(new Request('PROPFIND', '/x'));

        self::assertSame(403, $response->status());
        self::assertStringContainsString('propfind-finite-depth', (string) $response->body());
        self::assertStringContainsString('application/xml', (string) $response->headers()->first('Content-Type'));
    }

    /**
     * The writer is handed in, so an application that has registered its own
     * namespaces gets error bodies written with the prefixes it chose rather
     * than ones this library invented.
     */
    public function testWritesTheErrorWithTheWriterItWasGiven(): void
    {
        $server = new Server(
            new Tree(new MemoryCollection('')),
            null,
            new Writer(['DAV:' => 'dav']),
        );
        $server->onMethod('LOCK', static fn (): Response => throw new Locked(
            'Somebody else holds it.',
            '{DAV:}lock-token-submitted',
        ));

        $body = (string) $server->handle(new Request('LOCK', '/x'))->body();

        self::assertStringContainsString('<dav:error xmlns:dav="DAV:">', $body);
    }

    public function testAFailureWithNoPreconditionSendsNoBody(): void
    {
        $server = $this->server();
        $server->onMethod('GET', static fn (): Response => throw new NotFound('No such node.'));

        $response = $server->handle(new Request('GET', '/x'));

        self::assertSame(404, $response->status());
        self::assertNull($response->body());
    }

    /**
     * Everything else is ours, not the client's. A `500` goes out with nothing
     * in it: the message belongs in a log, where somebody can act on it, and a
     * client that received it would learn about paths, queries and versions it
     * has no business knowing.
     */
    public function testAnythingElseIsFiveHundredAndSaysNothing(): void
    {
        $server = $this->server();
        $server->onMethod('GET', static fn (): Response => throw new RuntimeException(
            'SQLSTATE[08006]: connection to server at "10.0.0.5" failed',
        ));

        $response = $server->handle(new Request('GET', '/x'));

        self::assertSame(500, $response->status());
        self::assertNull($response->body());
    }

    /**
     * The exception reaches a listener all the same, because something has to
     * be able to write it down — the server itself holds no opinion about
     * logging (R-PROD-07 keeps that out of the library).
     */
    public function testTheFailureIsHandedToTheListeners(): void
    {
        $seen = null;
        $events = new EventEmitter();
        $events->on(ExceptionRaised::class, static function (ExceptionRaised $event) use (&$seen): void {
            $seen = $event->failure();
        });

        $failure = new RuntimeException('the backend is gone');
        $server = $this->server($events);
        $server->onMethod('GET', static fn (): Response => throw $failure);

        $server->handle(new Request('GET', '/x'));

        self::assertSame($failure, $seen);
    }

    /**
     * And a listener may answer instead — which is what a plugin turning its
     * own backend's failures into `507` or `502` is for.
     */
    public function testAListenerCanAnswerAFailureItself(): void
    {
        $events = new EventEmitter();
        $events->on(ExceptionRaised::class, static function (ExceptionRaised $event): void {
            $event->answerWith(new Response(502));
        });

        $server = $this->server($events);
        $server->onMethod('GET', static fn (): Response => throw new RuntimeException('upstream'));

        self::assertSame(502, $server->handle(new Request('GET', '/x'))->status());
    }

    /**
     * A listener that fails while answering a failure must not take the server
     * with it: the first failure is still what the client is owed.
     */
    public function testAFailureWhileAnsweringAFailureStillLeavesAnAnswer(): void
    {
        $events = new EventEmitter();
        $events->on(ExceptionRaised::class, static function (): void {
            throw new RuntimeException('the log is full');
        });

        $server = $this->server($events);
        $server->onMethod('GET', static fn (): Response => throw new NotFound('No such node.'));

        self::assertSame(404, $server->handle(new Request('GET', '/x'))->status());
    }

    /**
     * Every one of the three events carries the request it is about. A
     * listener that could not see it would be of no use: authentication reads
     * the headers, the sharing protocol reads the body, and a log wants to
     * name the target of the request that failed.
     */
    public function testEveryEventCarriesTheRequestItIsAbout(): void
    {
        $seen = [];
        $events = new EventEmitter();

        $events->on(BeforeMethod::class, static function (BeforeMethod $event) use (&$seen): void {
            $seen['before'] = $event->request();
        });
        $events->on(AfterMethod::class, static function (AfterMethod $event) use (&$seen): void {
            $seen['after'] = $event->request();
        });
        $events->on(ExceptionRaised::class, static function (ExceptionRaised $event) use (&$seen): void {
            $seen['failed'] = $event->request();
        });

        $server = $this->server($events);
        $server->onMethod('GET', static fn (): Response => new Response(200));
        $server->onMethod('DELETE', static fn (): Response => throw new NotFound('No such node.'));

        $answered = new Request('GET', '/file.txt');
        $failed = new Request('DELETE', '/file.txt');

        $server->handle($answered);

        self::assertSame($answered, $seen['before'] ?? null);
        self::assertSame($answered, $seen['after'] ?? null);

        $server->handle($failed);

        self::assertSame($failed, $seen['before']);
        self::assertSame($failed, $seen['failed'] ?? null);
        self::assertSame($answered, $seen['after'], 'A request that failed never reached the method.');
    }

    public function testTwoServersKnowNothingOfEachOther(): void
    {
        $one = $this->server();
        $other = $this->server();

        $one->onMethod('GET', static fn (): Response => new Response(200));

        self::assertSame(200, $one->handle(new Request('GET', '/x'))->status());
        self::assertSame(501, $other->handle(new Request('GET', '/x'))->status());
    }

    public function testHandsOverTheTreeItServes(): void
    {
        $tree = new Tree(new MemoryCollection(''));

        self::assertSame($tree, (new Server($tree))->tree());
    }

    public function testHandsOverTheEmitterPluginsRegisterOn(): void
    {
        $events = new EventEmitter();

        self::assertSame($events, $this->server($events)->events());
    }

    private function server(?EventEmitter $events = null): Server
    {
        return new Server(new Tree(new MemoryCollection('')), $events);
    }
}
