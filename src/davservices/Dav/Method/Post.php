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
 * RFC 6638 §5 a free/busy request on an Outbox.
 *
 * **And that last body is not XML at all.** §5 has it be "a 'VFREEBUSY'
 * calendar component with the 'METHOD' iCalendar property set to the value
 * 'REQUEST'", which no root element can name. So the dispatch is two levels
 * deep: first the media type the client stated, then — for a body nobody
 * claimed that way — the root element inside it.
 *
 * **The first level reads what the client said, and does not look at the
 * body.** RFC 9110 §8.3 makes `Content-Type` the sender's own statement:
 * it "indicates the media type of the associated representation". The same
 * section allows a recipient to "examine the data to determine its type"
 * where the field is absent, and that is deliberately not done here —
 * choosing a handler by the shape of a body would answer a request the
 * client did not make. A body that names no media type goes the XML way and
 * is answered there.
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

    /**
     * By media type, lowercased and without its parameters.
     *
     * @var array<string, Closure(Request): Response>
     */
    private array $byMediaType = [];

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
     * Takes a handler for a body that is no XML document, by the media type
     * the client states for it.
     *
     * This is how RFC 6638 §5's busy time request is answered: an iCalendar
     * object has no root element to be named by, so what it is named by is
     * what RFC 9110 §8.3 has the client say it is.
     *
     * **The handler gets the request and reads the body itself.** The XML
     * level cannot read it first — there is nothing here to parse it with,
     * and a dispatcher that guessed at a syntax it does not own would be
     * deciding for the handler what the body was.
     *
     * @param string $mediaType As `type/subtype`; what the client sends is
     *                          matched to it without its parameters and
     *                          without regard to case, which is how RFC 9110
     *                          §8.3.1 defines the comparison
     * @param callable(Request): Response $handler What answers such a body
     */
    public function forBodyOfType(string $mediaType, callable $handler): void
    {
        $this->byMediaType[strtolower($mediaType)] = $handler(...);
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

        $claimed = $this->byMediaType[self::mediaTypeOf($request)] ?? null;

        if ($claimed !== null) {
            return $claimed($request);
        }

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

    /**
     * What the client said its body is, as the key a handler was taken under.
     *
     * RFC 9110 §8.3.1: a media type is `type/subtype` with optional
     * parameters, and "the type/subtype MAY be followed by parameters in the
     * form of name/value pairs". Those say how to read the body rather than
     * what it is — §5's own example sends `text/calendar; charset="utf-8"` —
     * so they are cut off before the comparison. And the type is matched
     * without regard to case, §8.3.1 having "type, subtype, and parameter
     * name tokens are case-insensitive".
     *
     * A request that states nothing answers with the empty string, which no
     * media type is and so no handler is taken under.
     */
    private static function mediaTypeOf(Request $request): string
    {
        [$type] = explode(';', $request->headers()->first('Content-Type') ?? '', 2);

        return strtolower(trim($type));
    }
}
