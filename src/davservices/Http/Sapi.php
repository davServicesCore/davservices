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

use Closure;

/**
 * The seam between PHP's own server interface and this library.
 *
 * It builds a `Request` from what the SAPI put into `$_SERVER`, and writes a
 * `Response` back out. Everything it touches of the outside world — the call
 * that emits a header field, the stream the body goes to — is handed in, so
 * that the whole of it can be tested rather than only described. Left alone it
 * uses PHP's own `header()` and `php://output`, which is what a real request
 * wants.
 *
 * It is the one class in the library that knows about `$_SERVER`, and nothing
 * above it has to.
 */
final class Sapi
{
    /** @var Closure(string): void */
    private readonly Closure $writeHeader;

    /** @var resource */
    private readonly mixed $output;

    /**
     * @param (Closure(string): void)|null $writeHeader Null writes through PHP's own `header()`
     * @param resource|null $output Null writes to `php://output`
     * @param int $keepBufferLevels How many output buffers belong to
     *                              the caller and are to be left alone;
     *                              a real request owns none
     */
    public function __construct(
        ?Closure $writeHeader = null,
        mixed $output = null,
        private readonly int $keepBufferLevels = 0,
    ) {
        $this->writeHeader = $writeHeader ?? static function (string $line): void {
            header($line, false);
        };

        /** @var resource $target */
        $target = $output ?? fopen('php://output', 'wb');
        $this->output = $target;
    }

    /**
     * Builds the request the server is to answer.
     *
     * @param array<string, mixed> $server What the SAPI collected, usually `$_SERVER`
     * @param resource|false|null $input The body, usually `php://input`; null opens
     *                                   it, and the `false` of a failed `fopen()`
     *                                   is taken for no body
     *
     * @throws MalformedRequest If the environment names no method or no target
     */
    public function request(array $server, mixed $input = null): Request
    {
        $method = self::text($server, 'REQUEST_METHOD');
        $target = self::text($server, 'REQUEST_URI');

        if ($method === null || $target === null) {
            throw new MalformedRequest('The server environment names no request method or no request target.');
        }

        return new Request(
            $method,
            $target,
            self::headers($server),
            new Body(self::input($input)),
            self::protocolVersion($server),
        );
    }

    /**
     * Writes the answer out: status line, fields, body.
     *
     * The body is copied from its stream rather than read into a string, so
     * that a file of any size costs the same (R-TREE-02).
     */
    public function send(Response $response): void
    {
        ($this->writeHeader)(rtrim(sprintf(
            'HTTP/%s %d %s',
            $response->protocolVersion(),
            $response->status(),
            $response->reason(),
        )));

        foreach ($response->headers()->toArray() as $name => $values) {
            foreach ($values as $value) {
                ($this->writeHeader)($name . ': ' . $value);
            }
        }

        $this->emptyOutputBuffers();
        $this->write($response->body(), $response->bodyLength());
    }

    /**
     * The stream the body is to be read from.
     *
     * `fopen()` reports failure as `false`, and a source that is not a stream
     * is simply no body: a request whose body could not be opened is answered
     * by whoever needed one, not here.
     *
     * @param resource|false|null $given Null opens `php://input`
     *
     * @return resource|null
     */
    private static function input(mixed $given): mixed
    {
        $stream = $given ?? fopen('php://input', 'rb');

        return is_resource($stream) ? $stream : null;
    }

    /**
     * @param resource|string|null $body
     * @param int|null $length How much of a stream belongs to this answer;
     *                         null sends all of it
     */
    private function write(mixed $body, ?int $length): void
    {
        if (is_string($body)) {
            fwrite($this->output, $length === null ? $body : substr($body, 0, $length));

            return;
        }

        if ($body === null) {
            return;
        }

        // A 206 hands over the file's own stream and says where its part ends,
        // so that serving a range of a recording costs no more than serving a
        // range of a note.
        if ($length === null) {
            stream_copy_to_stream($body, $this->output);

            return;
        }

        stream_copy_to_stream($body, $this->output, $length);
    }

    /**
     * R-HTTP-13. A buffer that is still open collects the body instead of
     * letting it go out, which turns streaming a large file back into holding
     * the whole of it in memory.
     *
     * They are flushed rather than discarded: output a plugin produced by
     * mistake is evidence of a bug, and swallowing it would only make that bug
     * harder to find.
     */
    private function emptyOutputBuffers(): void
    {
        while (ob_get_level() > $this->keepBufferLevels) {
            ob_end_flush();
        }
    }

    /**
     * The header fields the SAPI collected.
     *
     * PHP prefixes them with `HTTP_` and writes them in upper case with
     * underscores, except for the two that describe the body, which it hands
     * over without any prefix at all.
     *
     * @param array<string, mixed> $server
     */
    private static function headers(array $server): Headers
    {
        $headers = [];

        foreach ($server as $key => $value) {
            $name = self::fieldName($key);

            if ($name !== null && is_string($value)) {
                $headers[$name] = $value;
            }
        }

        // Apache under CGI moves Authorization out of the environment and puts
        // it back under a name of its own. Without this, Basic authentication
        // fails on a very common deployment and nothing says why.
        $authorization = self::text($server, 'HTTP_AUTHORIZATION')
            ?? self::text($server, 'REDIRECT_HTTP_AUTHORIZATION');

        if ($authorization !== null) {
            $headers['Authorization'] = $authorization;
        }

        return new Headers($headers);
    }

    /**
     * The field name an entry of `$_SERVER` stands for, or null where it
     * stands for none.
     */
    private static function fieldName(string $key): ?string
    {
        if (str_starts_with($key, 'HTTP_')) {
            return self::hyphenated(substr($key, 5));
        }

        if ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
            return self::hyphenated($key);
        }

        return null;
    }

    /**
     * `IF_NONE_MATCH` becomes `If-None-Match`: the same field either way, since
     * names are matched without regard to case, but the one a person expects to
     * read in a log.
     */
    private static function hyphenated(string $key): string
    {
        return ucwords(strtolower(str_replace('_', '-', $key)), '-');
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function protocolVersion(array $server): string
    {
        $protocol = self::text($server, 'SERVER_PROTOCOL');

        if ($protocol === null || !str_starts_with($protocol, 'HTTP/')) {
            return '1.1';
        }

        return substr($protocol, 5);
    }

    /**
     * The entry as text, or null where it is absent or is something else.
     *
     * @param array<string, mixed> $server
     */
    private static function text(array $server, string $key): ?string
    {
        $value = $server[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
