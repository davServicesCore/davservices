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

use DavServices\Exception\BadRequest;
use DavServices\Http\Request;

/**
 * How deep a report was asked to go.
 *
 * **A report without a `Depth` header was asked for depth `0`** (RFC 3253
 * §3.6). That is the opposite of `PROPFIND`, where RFC 4918 §9.1 makes a
 * missing header mean infinity, and getting it the wrong way round is a bad
 * mistake in either direction: read as infinity, a report that should have
 * looked at one resource walks a whole tree; read as zero where infinity was
 * meant, a client is quietly told there is nothing there.
 *
 * Most reports of RFC 3744 are defined at depth `0` and nowhere else, and
 * they say so in the same sentence — so the sentence is written once.
 *
 * This is also why {@see Method\Report} does not read the header itself:
 * RFC 3253 lets each report define what depth means for it, and the ones that
 * make something of it do not agree. A report that wants this rule asks for
 * it.
 */
final class ReportDepth
{
    /** What `Depth: infinity` becomes: deeper than any tree a backend holds. */
    public const ENDLESS = PHP_INT_MAX;

    /**
     * Insists on the only depth this report is defined at.
     *
     * @throws BadRequest If some other depth was asked for
     */
    public static function mustBeZero(Request $request): void
    {
        if (($request->headers()->first('Depth') ?? '0') !== '0') {
            throw new BadRequest('This report is only defined at Depth: 0.');
        }
    }

    /**
     * How far down a report that walks a collection was asked to reach.
     *
     * **The three reports of RFC 4791 that read the header word the sentence
     * identically** — §7.8 and §7.10: "The request MAY include a Depth header.
     * If no Depth header is included, Depth:0 is assumed." — so the sentence
     * is read in one place rather than three. RFC 4918 §10.2 is where the
     * three values come from, and a fourth is a request this report has no
     * meaning for rather than one to guess at.
     *
     * `Depth: infinity` comes back as {@see self::ENDLESS} so that a walk can
     * count down through it without a case of its own; no tree a backend holds
     * is that deep.
     *
     * @throws BadRequest If the header names a depth no report is defined at
     */
    public static function of(Request $request): int
    {
        $header = $request->headers()->first('Depth');

        if ($header === null) {
            return 0;
        }

        // Without regard to case: RFC 4918 §10.2 writes the value as `("0" |
        // "1" | "infinity")`, and RFC 5234 §2.3 makes the grammar's own
        // strings case-insensitive.
        $depth = strtolower($header);

        if ($depth === '0' || $depth === '1') {
            return (int) $depth;
        }

        if ($depth === 'infinity') {
            return self::ENDLESS;
        }

        throw new BadRequest(sprintf('This report goes 0, 1 or infinity deep, not "%s".', $header));
    }
}
