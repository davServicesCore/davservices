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
}
