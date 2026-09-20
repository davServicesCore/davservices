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

namespace DavServices\Http;

use DavServices\Uri\MalformedPath;
use DavServices\Uri\Path;

/**
 * One request, as it arrived.
 *
 * The object never changes after it has been built: what a plugin reads in the
 * chain is what the client sent, whoever ran before it. A plugin that needs a
 * request of its own builds one — there is no `with…` to reach for, because a
 * request that quietly differs from the one on the wire makes a log unreadable.
 *
 * The body is the one part that is not a plain value, and it stays honest
 * about it: it can be read as often as needed (see `Body`).
 */
final class Request
{
    private readonly Headers $headers;

    private readonly Body $body;

    /**
     * @param string $target As it came off the wire, still encoded, query and
     *                       all — `path()` and `query()` take it apart
     *
     * @throws MalformedRequest If the method is no token or the target is empty
     */
    public function __construct(
        private readonly string $method,
        private readonly string $target,
        ?Headers $headers = null,
        ?Body $body = null,
        private readonly string $protocolVersion = '1.1',
    ) {
        // RFC 9110 §9.1: a method is a token, and it is case-sensitive.
        if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $method) !== 1) {
            throw new MalformedRequest(sprintf('"%s" is not a valid request method.', $method));
        }

        if ($target === '') {
            throw new MalformedRequest('A request must name a target.');
        }

        // RFC 9112 §3.2: a request-target is an absolute path and a query, and
        // never a fragment — a client keeps that to itself. One that arrives
        // all the same is refused rather than cut short, because cutting it
        // means acting on a resource the client did not name: a `DELETE` of
        // `/calendars/#anything` would otherwise remove the collection.
        if (str_contains($target, '#')) {
            throw new MalformedRequest('A request target carries no fragment.');
        }

        $this->headers = $headers ?? new Headers();
        $this->body = $body ?? new Body();
    }

    /**
     * The method, spelled as it arrived.
     */
    public function method(): string
    {
        return $this->method;
    }

    /**
     * The request target, untouched.
     */
    public function target(): string
    {
        return $this->target;
    }

    /**
     * The target as a path this library can look up: decoded, normalised, no
     * leading slash, the root spelled as the empty string.
     *
     * RFC 9110 §7.1 allows `OPTIONS *`, which asks about the server rather
     * than about a resource; it answers as the root rather than as a
     * collection that happens to be named `*`.
     *
     * @throws MalformedPath If the target cannot be resolved safely
     */
    public function path(): string
    {
        if ($this->target === '*') {
            return '';
        }

        return Path::normalise($this->split()[0]);
    }

    /**
     * The query of the target, still encoded and unparsed.
     *
     * Nothing in WebDAV reads it, and guessing at a form encoding for the one
     * embedding application that does would be guessing for everyone.
     */
    public function query(): string
    {
        return $this->split()[1];
    }

    /**
     * The header fields of the request.
     */
    public function headers(): Headers
    {
        return $this->headers;
    }

    /**
     * The body of the request, readable more than once.
     */
    public function body(): Body
    {
        return $this->body;
    }

    /**
     * The HTTP version the request came in on, such as `1.1`.
     */
    public function protocolVersion(): string
    {
        return $this->protocolVersion;
    }

    /**
     * The target, cut into path and query at the first question mark.
     *
     * A fragment is cut away with it. One never reaches a server — RFC 9110
     * §7.1 keeps it at the client — but a request built by hand may carry one,
     * and it has no business becoming part of a resource name.
     *
     * @return array{string, string}
     */
    private function split(): array
    {
        $target = $this->target;
        $query = strpos($target, '?');

        if ($query === false) {
            return [$target, ''];
        }

        return [substr($target, 0, $query), substr($target, $query + 1)];
    }
}
