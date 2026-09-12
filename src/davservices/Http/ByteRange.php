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

use DavServices\Exception\RangeNotSatisfiable;

/**
 * The part of a resource a client asked for with a `Range` header.
 *
 * Reading one has three outcomes, and telling them apart is what this class is
 * for (RFC 9110 §14):
 *
 * - a range, which the server answers with `206` and a `Content-Range`;
 * - nothing, meaning the header is to be ignored and the whole resource sent;
 * - a refusal, which is the only case that becomes a `416`.
 *
 * The middle one is easy to get wrong. A unit the server does not know must be
 * ignored (§14.2), and a reversed range is invalid rather than unsatisfiable
 * (§14.1.1) — answering `416` to either would lock a client out of a file it
 * is allowed to read, over a header it need not have sent at all.
 *
 * Only one range is served. R-HTTP-05 allows multipart to be left out, and a
 * server may always answer with the whole resource instead.
 */
final class ByteRange
{
    private function __construct(
        private readonly int $start,
        private readonly int $last,
        private readonly int $size,
    ) {
    }

    /**
     * Reads a `Range` header against the size of the resource it names.
     *
     * @param string|null $header The header value, or null where none was sent
     * @param int $size The length of the resource in bytes
     *
     * @throws RangeNotSatisfiable If the range is well formed but asks for
     *                             bytes the resource does not have
     *
     * @return self|null Null where the header is to be ignored and the whole
     *                   resource sent
     */
    public static function parse(?string $header, int $size): ?self
    {
        $spec = self::specOf($header);

        if ($spec === null || preg_match('/^(\d*)-(\d*)$/', $spec, $parts) !== 1) {
            return null;
        }

        [, $firstByte, $lastByte] = $parts;

        // `bytes=-` names no range at all, rather than an empty one.
        if ($firstByte === '' && $lastByte === '') {
            return null;
        }

        if ($firstByte === '') {
            return self::fromSuffix($lastByte, $size);
        }

        $start = (int) $firstByte;

        if ($lastByte === '') {
            return self::satisfiable($start, $size - 1, $size);
        }

        $last = (int) $lastByte;

        // RFC 9110 §14.1.1 calls this invalid, not unsatisfiable: ignore it.
        if ($last < $start) {
            return null;
        }

        return self::satisfiable($start, min($last, $size - 1), $size);
    }

    /**
     * The first byte to send.
     */
    public function start(): int
    {
        return $this->start;
    }

    /**
     * The last byte to send, which HTTP counts as part of the range while
     * almost every programming language would not.
     */
    public function last(): int
    {
        return $this->last;
    }

    /**
     * How many bytes to send.
     */
    public function length(): int
    {
        return $this->last - $this->start + 1;
    }

    /**
     * The `Content-Range` field that belongs to a `206`.
     */
    public function contentRange(): string
    {
        return sprintf('bytes %d-%d/%d', $this->start, $this->last, $this->size);
    }

    /**
     * The range set of a header naming bytes, or null where it names something
     * else — another unit, or nothing this server can follow.
     */
    private static function specOf(?string $header): ?string
    {
        if ($header === null || !str_starts_with($header, 'bytes=')) {
            return null;
        }

        $spec = substr($header, 6);

        // More than one range needs a multipart body, which R-HTTP-05 lets go.
        return str_contains($spec, ',') ? null : $spec;
    }

    /**
     * `bytes=-500`: the last 500 bytes.
     *
     * A resource shorter than the suffix is sent whole (RFC 9110 §14.1.2). A
     * suffix of nothing needs no case of its own: it starts one byte past the
     * resource, which is refused like any other range that does.
     *
     * @throws RangeNotSatisfiable
     */
    private static function fromSuffix(string $suffixLength, int $size): self
    {
        return self::satisfiable(max(0, $size - (int) $suffixLength), $size - 1, $size);
    }

    /**
     * A start at or past the end of the resource is the one thing that cannot
     * be served — and the only thing left to check, because every caller above
     * has already brought its last byte inside the resource or given up.
     *
     * An empty resource needs no case of its own: every range starts at or
     * past its end.
     *
     * @throws RangeNotSatisfiable
     */
    private static function satisfiable(int $start, int $last, int $size): self
    {
        if ($start >= $size) {
            throw new RangeNotSatisfiable(
                sprintf('The requested range lies outside the %d bytes of the resource.', $size),
            );
        }

        return new self($start, $last, $size);
    }
}
