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

use DavServices\Dav\Event\OptionsRequested;
use DavServices\Dav\Server;
use DavServices\Http\Request;
use DavServices\Http\Response;

/**
 * Answers `OPTIONS`: what this server is, and what it will do.
 *
 * A client decides everything else from this answer. Windows will not mount a
 * share whose answer lacks `MS-Author-Via`, and no client offers to lock a
 * resource on a server whose `DAV` header does not say `2`.
 *
 * Which makes every line of it a promise, and a promise the server cannot keep
 * worse than one it never made. So nothing here is written down in advance:
 * the methods are the ones registered with the server, and the compliance
 * classes are named by the plugins that provide them (R-HTTP-11). A server
 * built without the lock plugin says nothing about class 2, and a client then
 * never asks it for something it cannot do.
 *
 * Registered like any other method:
 *
 *     $options = new Options($server);
 *     $server->onMethod('OPTIONS', $options(...));
 */
final class Options
{
    public function __construct(private readonly Server $server)
    {
    }

    /**
     * Answers the request.
     *
     * RFC 9110 §7.1 lets a client ask about the server itself with
     * `OPTIONS *` rather than about a resource. Nothing here looks at the
     * path, so both are answered the same way.
     */
    public function __invoke(Request $request): Response
    {
        $event = new OptionsRequested($request);

        // The server answers OPTIONS itself, and is class 1 by being a WebDAV
        // server at all (RFC 4918 §18.1). Everything beyond that is somebody
        // else's to claim.
        $event->allowMethod('OPTIONS', ...$this->server->methods());
        $event->addCompliance('1');

        $answered = $this->server->events()->emit($event);

        return (new Response(200))
            ->withHeader('DAV', implode(', ', $answered->compliance()))
            ->withHeader('Allow', implode(', ', $answered->methods()))
            ->withHeader('MS-Author-Via', 'DAV')
            ->withHeader('Content-Length', '0');
    }
}
