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

use Closure;
use DavServices\Dav\Server;
use DavServices\Exception\BadRequest;
use DavServices\Exception\NotFound;
use DavServices\Exception\UnsupportedMediaType;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Xml\Element;

/**
 * Answers `POST`: whatever the body asks the resource to do, by name.
 *
 * **WebDAV leaves this method alone on purpose**, and says so in one
 * sentence — RFC 4918 §9.5: "This specification does not define the behavior
 * of POST for collections." RFC 9110 §9.3.3 is why it can afford to: "The
 * POST method requests that the target resource process the representation
 * enclosed in the request according to **the resource's own specific
 * semantics**."
 *
 * So what a `POST` means belongs to whatever defines it, and the extensions
 * that use it each name the body they take: `caldav-sharing` §5.3.2 a
 * `CS:share` on a calendar, §5.4.1 a `CS:invite-reply` on a calendar home,
 * RFC 6638 §6 a free/busy request on an outbox.
 *
 * **Which makes this a dispatcher and nothing else** — the same shape
 * {@see Report} has, and little more on its own:
 *
 *     $post = new Post($server);
 *     $post->on('{http://calendarserver.org/ns/}share', $share(...));
 *
 *     $server->onMethod('POST', $post(...));
 *
 * It **reads the body once** and hands over the element it found, so that no
 * handler parses the request twice: two readings are two chances to disagree
 * about what the client asked for.
 *
 * It **looks for the target first**, because every `POST` of this family acts
 * on the resource at the request target, and a handler that forgot to look
 * would change something for a resource nobody has.
 *
 * And it **refuses a root element nobody answers with `415`**. RFC 9110
 * §15.5.16 has that status for a server "refusing to service the request
 * because the content is in a format not supported by this method on the
 * target resource", which is this case in the memo's own words; it is also
 * the reading {@see \DavServices\CalDav\Method\MkCalendar} already makes of a
 * body this server does not take. `REPORT` refuses an unknown name with `403`
 * instead, but only because RFC 3253 §3.6 gives it `DAV:supported-report` to
 * say why — no memo gives `POST` such an element, and a bare `403` reads as
 * "not for you".
 *
 * **It is handed to the server rather than registering itself**, unlike
 * `REPORT`: that method brings a property with it and so has to be wired in
 * one piece, and this one brings nothing but itself.
 *
 * Every handler is offered on every resource, as every report is. The handler
 * is what knows which resource its body belongs on — §5.3.2's `CS:share`
 * means nothing anywhere but a calendar — so that is where it is said.
 */
final class Post
{
    /** @var array<string, Closure(Request, Element): Response> */
    private array $handlers = [];

    public function __construct(private readonly Server $server)
    {
    }

    /**
     * Takes a handler, by the root element it answers.
     *
     * @param string $name As `{namespace}localname`
     * @param callable(Request, Element): Response $handler What answers it,
     *                                                      given the request
     *                                                      and the element the
     *                                                      client sent
     */
    public function on(string $name, callable $handler): void
    {
        $this->handlers[$name] = $handler(...);
    }

    /**
     * Hands the request to whichever handler its body names.
     *
     * @throws NotFound If there is nothing at the path
     * @throws BadRequest If the body is empty, or cannot be read
     * @throws UnsupportedMediaType If nothing here answers that body
     */
    public function __invoke(Request $request): Response
    {
        // Before the body, for the reason `REPORT` does the same: a client
        // that posted to something that is not there has that wrong with its
        // request whatever it sent, and the more specific answer is the
        // useful one.
        $this->server->tree()->node($this->server->path($request));

        // An empty body names nothing, and the reader is what says so — with
        // the `400` a malformed one gets, since both are bodies this server
        // cannot act on. A second guard here would be a second place to keep
        // right.
        $sent = $this->server->reader()->parse($request->body()->contents());
        $handler = $this->handlers[$sent->name()] ?? null;

        if ($handler === null) {
            throw new UnsupportedMediaType(
                sprintf('This server takes no POST whose body is a "%s".', $sent->name()),
            );
        }

        return $handler($request, $sent);
    }
}
