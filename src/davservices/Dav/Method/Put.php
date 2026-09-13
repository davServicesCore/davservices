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

namespace DavServices\Dav\Method;

use DavServices\Dav\Event\AfterBind;
use DavServices\Dav\Event\AfterCreateFile;
use DavServices\Dav\Event\AfterWriteContent;
use DavServices\Dav\Event\BeforeBind;
use DavServices\Dav\Event\BeforeCreateFile;
use DavServices\Dav\Event\BeforeWriteContent;
use DavServices\Dav\IFile;
use DavServices\Dav\Server;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Conflict;
use DavServices\Exception\MethodNotAllowed;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Uri\Path;

/**
 * Answers `PUT`: the content a client sends becomes the content of a file.
 *
 * The status says which of two things happened, and clients act on the
 * difference: `201` where the file was created, `204` where one that was
 * already there was replaced. A client told the wrong one writes the wrong
 * thing into its own cache.
 *
 * Two refusals are worth more than they cost.
 *
 * **A `PUT` carrying `Content-Range` is refused** (R-DAV-09). A server that
 * ignores that header writes the *part* as though it were the whole file, and
 * nothing in the exchange tells the client that the rest is gone. There is no
 * safe way to guess what was meant, so nothing is guessed.
 *
 * **A `PUT` into a collection that is not there is a `409`** (RFC 4918
 * §9.7.1), never a quiet creation of the ancestors: that is how one typo
 * becomes a tree of empty folders.
 *
 * Everything that can refuse the request is decided **before the body is
 * touched**. The interim `100 Continue` of R-HTTP-09 is the web server's to
 * send, but its whole purpose is that a client need not upload a gigabyte to
 * be told the write was never going to be allowed.
 *
 * Registered like any other method:
 *
 *     $put = new Put($server);
 *     $server->onMethod('PUT', $put(...));
 */
final class Put
{
    public function __construct(private readonly Server $server)
    {
    }

    /**
     * Writes the body to the path, creating the file where there is none.
     *
     * @throws BadRequest If the request claims to carry part of a file
     * @throws MethodNotAllowed If the path names a collection
     * @throws Conflict If the collection it would go in is not there
     */
    public function __invoke(Request $request): Response
    {
        // R-DAV-09: refused before anything else, because the alternative is
        // to write a part of a file over the whole of one.
        if ($request->headers()->has('Content-Range')) {
            throw new BadRequest('A PUT carrying Content-Range is not answered here.');
        }

        $path = $this->server->path($request);
        $existing = $this->existing($path);

        return $existing === null
            ? $this->create($path, $request)
            : $this->replace($path, $existing, $request);
    }

    /**
     * The file that is already at the path, or null where there is none.
     *
     * @throws MethodNotAllowed If something is there that is no file
     */
    private function existing(string $path): ?IFile
    {
        if (!$this->server->tree()->exists($path)) {
            return null;
        }

        $node = $this->server->tree()->node($path);

        if ($node instanceof IFile) {
            return $node;
        }

        throw new MethodNotAllowed('A collection cannot be written over.');
    }

    /**
     * @throws Conflict If the collection it would go in is not there
     */
    private function create(string $path, Request $request): Response
    {
        [$parentPath, $name] = Path::split($path);
        $parent = $this->server->tree()->collectionAt($parentPath);

        $this->server->events()->emit(new BeforeBind($path));

        $event = $this->server->events()->emit(new BeforeCreateFile($path, $request->body()->stream()));
        $etag = $parent->createFile($name, $event->content());

        $this->server->tree()->forget($parentPath);
        $this->server->events()->emit(new AfterCreateFile($path));
        $this->server->events()->emit(new AfterBind($path));

        return $this->answer(201, $etag);
    }

    private function replace(string $path, IFile $file, Request $request): Response
    {
        $event = $this->server->events()->emit(new BeforeWriteContent($path, $request->body()->stream()));
        $etag = $file->put($event->content());

        $this->server->tree()->forget($path);
        $this->server->events()->emit(new AfterWriteContent($path));

        return $this->answer(204, $etag);
    }

    /**
     * A backend that cannot say what it stored is the ordinary case. Inventing
     * an entity tag would be worse than sending none: a client believes it,
     * and then never notices the file changing underneath.
     */
    private function answer(int $status, ?string $etag): Response
    {
        $response = new Response($status);

        return $etag === null ? $response : $response->withHeader('ETag', $etag);
    }
}
