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

use DavServices\Exception\PayloadTooLarge;

/**
 * The body of one message, readable as often as it is needed.
 *
 * What arrives from a client is a stream that is gone once it has been read,
 * while a request passes through a chain of plugins that each want to look at
 * it. The body is therefore copied into `php://temp` the first time somebody
 * asks for it: small bodies stay in memory, larger ones are moved to disk by
 * PHP itself, and every reader gets the whole of it from the beginning.
 *
 * The copy is made on demand rather than up front, because most requests —
 * `GET`, `HEAD`, `OPTIONS`, `DELETE`, `PROPFIND` with an empty body — never
 * read one, and copying what nobody reads is work for nothing.
 *
 * PHP reports a failed stream operation as `false`. A body that cannot be read
 * is an empty body here, and the request it belongs to is refused by whichever
 * handler needed it — there is nothing this class could do about a source that
 * has gone away mid-request.
 */
final class Body
{
    /** What `php://temp` keeps in memory before it moves the buffer to disk. */
    public const DEFAULT_MEMORY_LIMIT = 2_097_152;

    /** @var resource|string|null Dropped as soon as it has been copied. */
    private mixed $source;

    /** @var resource|null The copy every reader is served from. */
    private mixed $buffer = null;

    /** Counted while copying, so that no reader has to ask the file system. */
    private int $size = 0;

    /** Kept so that a body refused once is refused again rather than served short. */
    private ?PayloadTooLarge $refusal = null;

    /**
     * @param resource|string|null $source What the message arrived with
     * @param int $memoryLimit Bytes to hold in memory before
     *                         the buffer moves to disk
     */
    public function __construct(mixed $source = null, private readonly int $memoryLimit = self::DEFAULT_MEMORY_LIMIT)
    {
        $this->source = $source;
    }

    /**
     * The whole body, rewound and ready to be read again afterwards.
     *
     * **Impure, and that is the point.** The first call draws the source into
     * the buffer and every call rewinds it, so two calls are two different
     * events however alike their answers look. A reader that assumed
     * otherwise would conclude that reading a body twice cannot be worth
     * testing — which is the one thing this class exists to make true.
     *
     * @throws PayloadTooLarge If an earlier read refused the body
     *
     * @return resource
     *
     * @phpstan-impure
     */
    public function stream(): mixed
    {
        $buffer = $this->buffered(null);
        rewind($buffer);

        return $buffer;
    }

    /**
     * The whole body as a string.
     *
     * @param int|null $maximumBytes The ceiling this reader applies, or null
     *                               for none. R-HTTP-12 gives XML bodies and
     *                               uploads ceilings of their own, so it
     *                               belongs to the read rather than to the body.
     *
     * @throws PayloadTooLarge If the body is longer than the ceiling
     *
     * @phpstan-impure See {@see self::stream()}: the first call fills the
     *                 buffer, and every call moves the file pointer
     */
    public function contents(?int $maximumBytes = null): string
    {
        $buffer = $this->buffered($maximumBytes);
        rewind($buffer);

        return (string) stream_get_contents($buffer);
    }

    /**
     * Is there nothing to read?
     *
     * A `PROPFIND` without a body means `allprop` (RFC 4918 §9.1), so nearly
     * every request is asked this.
     *
     * @throws PayloadTooLarge If an earlier read refused the body
     */
    public function isEmpty(): bool
    {
        $this->buffered(null);

        return $this->size === 0;
    }

    /**
     * The buffer, filled from the source on the first call.
     *
     * @throws PayloadTooLarge
     *
     * @return resource
     */
    private function buffered(?int $maximumBytes): mixed
    {
        if ($this->refusal !== null) {
            throw $this->refusal;
        }

        $buffer = $this->buffer ?? $this->fill($maximumBytes);
        $this->buffer = $buffer;

        $this->refuseIfTooLong($maximumBytes, $buffer);

        return $buffer;
    }

    /**
     * Copies the source into a fresh buffer, taking at most one byte more than
     * the ceiling allows.
     *
     * Storing the whole of an oversized body only to refuse it afterwards
     * would spend exactly the memory and the disk that the ceiling is there to
     * protect, and one byte past it is all it takes to know.
     *
     * @return resource
     */
    private function fill(?int $maximumBytes): mixed
    {
        $source = $this->source;
        $this->source = null;

        /** @var resource $buffer */
        $buffer = fopen('php://temp/maxmemory:' . $this->memoryLimit, 'r+b');

        if (is_string($source)) {
            $this->size = strlen($source);
            fwrite($buffer, $source);

            return $buffer;
        }

        if ($source !== null) {
            $this->size = $maximumBytes === null
                ? (int) stream_copy_to_stream($source, $buffer)
                : (int) stream_copy_to_stream($source, $buffer, $maximumBytes + 1);
        }

        return $buffer;
    }

    /**
     * @param resource $buffer Closed on refusal, since nothing will ever be
     *                         served from it
     *
     * @throws PayloadTooLarge
     */
    private function refuseIfTooLong(?int $maximumBytes, mixed $buffer): void
    {
        if ($maximumBytes === null || $this->size <= $maximumBytes) {
            return;
        }

        $this->refusal = new PayloadTooLarge(
            sprintf('The body is longer than the %d bytes this request may carry.', $maximumBytes),
        );

        fclose($buffer);
        $this->buffer = null;

        throw $this->refusal;
    }
}
