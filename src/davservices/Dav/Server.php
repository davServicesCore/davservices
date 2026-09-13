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

namespace DavServices\Dav;

use Closure;
use DavServices\Dav\Event\AfterMethod;
use DavServices\Dav\Event\BeforeMethod;
use DavServices\Dav\Event\ExceptionRaised;
use DavServices\Event\EventEmitter;
use DavServices\Exception\IHttpFailure;
use DavServices\Exception\NotFound;
use DavServices\Exception\NotImplemented;
use DavServices\Http\MalformedHeader;
use DavServices\Http\MalformedRequest;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Uri\MalformedPath;
use DavServices\Uri\Path;
use DavServices\Xml\Element;
use DavServices\Xml\Reader;
use DavServices\Xml\Writer;
use Throwable;

/**
 * Takes a request and answers it.
 *
 * The server does three things and leaves the rest to others: it runs the
 * chain of listeners around a request, it hands the request to the handler
 * registered for its method, and it turns whatever went wrong into an answer.
 *
 * Every method of WebDAV is a handler registered with `onMethod()`, and every
 * protocol extension is a listener on the emitter (R-ARC-02). A server with
 * nothing registered is still a working server: it answers `501` to
 * everything, which is the truth about a server that has been given no
 * methods.
 *
 * What a failure becomes is the part worth reading closely. A refusal that
 * knows its own status says so, and names its precondition in a `DAV:error`
 * body where it has one (RFC 4918 §16). The refusals of the lower layers — a
 * path that cannot be resolved, a header that could forge another — are the
 * client's doing and become `400`. Everything else is ours, and becomes a
 * `500` with nothing in it: the message belongs in a log, and a client that
 * received it would learn about paths, queries and versions it has no business
 * knowing.
 */
final class Server
{
    /** @var array<string, Closure(Request): Response> */
    private array $handlers = [];

    private readonly EventEmitter $events;

    private readonly Writer $writer;

    private readonly Reader $reader;

    /** Where this server is mounted, in the form the tree uses. */
    private readonly string $base;

    /**
     * @param Tree $tree What this server serves
     * @param EventEmitter|null $events Null builds one of its own, which is
     *                                  all a server without plugins needs
     * @param Writer|null $writer Null builds one with the usual prefixes
     * @param Reader|null $reader Null builds one with the usual caps
     * @param string $baseUri Where the application mounted this server, such
     *                        as `/dav/`. The tree knows nothing of it: a
     *                        node's path is its path inside the tree, wherever
     *                        the tree hangs.
     */
    public function __construct(
        private readonly Tree $tree,
        ?EventEmitter $events = null,
        ?Writer $writer = null,
        ?Reader $reader = null,
        string $baseUri = '/',
    ) {
        $this->events = $events ?? new EventEmitter();
        $this->writer = $writer ?? new Writer();
        $this->reader = $reader ?? new Reader();
        $this->base = Path::normalise($baseUri);
    }

    /**
     * The path inside the tree that a request names.
     *
     * @throws NotFound If the target lies outside what this server serves
     * @throws MalformedPath If the target cannot be resolved safely
     */
    public function path(Request $request): string
    {
        return $this->inside($request->path());
    }

    /**
     * The same, for a target that has not been through a request.
     *
     * The `Destination` of a `COPY` or a `MOVE` is a URL in a header rather
     * than the target of the request, and it arrives percent-encoded. It is
     * decoded here — **once**, which is the whole of what keeps a `%2F` from
     * becoming a separator.
     *
     * @param string $target A path as it arrived, still encoded
     *
     * @throws NotFound If the target lies outside what this server serves
     * @throws MalformedPath If it cannot be resolved safely
     */
    public function pathOf(string $target): string
    {
        return $this->inside(Path::normalise($target));
    }

    /**
     * Strips what the server is mounted at.
     *
     * @throws NotFound If the path lies outside it
     */
    private function inside(string $path): string
    {
        if ($this->base === '') {
            return $path;
        }

        if ($path === $this->base) {
            return '';
        }

        // The slash matters: a server mounted at /dav does not serve /davos,
        // and a prefix comparison without it would hand out somebody else's
        // tree.
        if (!str_starts_with($path, $this->base . '/')) {
            throw new NotFound(sprintf('"%s" is not served here.', $path));
        }

        return substr($path, strlen($this->base) + 1);
    }

    /**
     * The URL a path inside the tree is asked for by — the inverse of
     * `path()`.
     *
     * Every `DAV:href` this library sends is made here. A node knows only its
     * path inside the tree, and a method that guessed at the base would send
     * clients to a place that does not answer.
     *
     * @throws MalformedPath If the path holds a name that may not be addressed
     */
    public function href(string $path): string
    {
        return '/' . Path::encode(Path::join($this->base, $path));
    }

    /**
     * The tree this server serves.
     */
    public function tree(): Tree
    {
        return $this->tree;
    }

    /**
     * The emitter plugins register their listeners on.
     */
    public function events(): EventEmitter
    {
        return $this->events;
    }

    /**
     * The writer this server puts XML out with.
     *
     * A method that builds a multi-status writes it with this one, so that a
     * server told to use other prefixes uses them in every answer rather than
     * in some of them.
     */
    public function writer(): Writer
    {
        return $this->writer;
    }

    /**
     * The reader this server takes request bodies with.
     *
     * The caps of R-XML-05 are a setting of the server rather than of each
     * method: one place to raise or lower them, and no method left behind when
     * they change.
     */
    public function reader(): Reader
    {
        return $this->reader;
    }

    /**
     * Registers what answers a method.
     *
     * This is the `method:<VERB>` extension point of R-ARC-04, and it is a
     * lookup rather than an event on purpose: waking every listener in the
     * server for every request, so that each can ask whether the method is the
     * one it cares about, is exactly what the emitter's per-class dispatch was
     * built to avoid.
     *
     * @param string $method The verb, spelled as it arrives
     * @param Closure(Request): Response $handler
     */
    public function onMethod(string $method, Closure $handler): void
    {
        $this->handlers[$method] = $handler;
    }

    /**
     * The methods this server has handlers for, in the order they were
     * registered.
     *
     * What `OPTIONS` answers with, and the one thing that keeps the `Allow`
     * header from becoming a list somebody has to remember to update.
     *
     * @return list<string>
     */
    public function methods(): array
    {
        return array_keys($this->handlers);
    }

    /**
     * Answers one request.
     *
     * Nothing thrown below this line reaches the caller: whatever happens, a
     * client gets an answer.
     */
    public function handle(Request $request): Response
    {
        try {
            return $this->answer($request);
        } catch (Throwable $failure) {
            return $this->answerFailure($request, $failure);
        }
    }

    /**
     * @throws Throwable Whatever a listener or a handler raised
     */
    private function answer(Request $request): Response
    {
        $before = $this->events->emit(new BeforeMethod($request));
        $response = $before->response() ?? $this->invoke($request);

        return $this->events->emit(new AfterMethod($request, $response))->response();
    }

    /**
     * @throws NotImplemented If no handler is registered for the method
     * @throws Throwable Whatever the handler raised
     */
    private function invoke(Request $request): Response
    {
        $handler = $this->handlers[$request->method()] ?? null;

        if ($handler === null) {
            throw new NotImplemented(sprintf('This server does not answer %s.', $request->method()));
        }

        return $handler($request);
    }

    private function answerFailure(Request $request, Throwable $failure): Response
    {
        return $this->answeredByAListener($request, $failure) ?? $this->answerFor($failure);
    }

    /**
     * A listener may know better than the server what a failure means — a
     * backend's timeout is a `502`, its quota a `507`. One that fails while
     * answering is not allowed to take the server with it: the client is still
     * owed an answer to the failure that came first.
     */
    private function answeredByAListener(Request $request, Throwable $failure): ?Response
    {
        try {
            return $this->events->emit(new ExceptionRaised($request, $failure))->response();
        } catch (Throwable) {
            return null;
        }
    }

    private function answerFor(Throwable $failure): Response
    {
        if ($failure instanceof IHttpFailure) {
            return $this->answerForRefusal($failure);
        }

        // The lower layers refuse a path or a header long before anything
        // DAV-shaped is in play, so their refusals extend the SPL types rather
        // than this library's own. Here is where they become an answer.
        if ($failure instanceof MalformedPath
            || $failure instanceof MalformedHeader
            || $failure instanceof MalformedRequest) {
            return new Response(400);
        }

        // Nothing here is the client's doing, and nothing about it is the
        // client's business. The message goes to the listeners, which is where
        // an application logs it.
        return new Response(500);
    }

    private function answerForRefusal(IHttpFailure $failure): Response
    {
        $precondition = $failure->errorElement();

        if ($precondition === null) {
            return new Response($failure->status());
        }

        $error = new Element('{DAV:}error');
        $error->append(new Element($precondition));

        return (new Response($failure->status(), body: $this->writer->write($error)))
            ->withHeader('Content-Type', 'application/xml; charset=utf-8');
    }
}
