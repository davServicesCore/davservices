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

namespace DavServices\VObject\Value;

use DavServices\VObject\ParseError;

/**
 * The `BINARY` value type: octets, carried as base64.
 *
 *     binary = *(4b-char) [b-end]
 *     b-end  = (2b-char "==") / (3b-char "=")
 *     b-char = ALPHA / DIGIT / "+" / "/"
 *
 * RFC 5545 §3.3.1: "all inline binary data MUST first be character encoded
 * using the 'BASE64' encoding method", and "No additional content value
 * encoding (i.e., BACKSLASH character encoding …) is defined for this value
 * type".
 *
 * **The grammar is checked here rather than left to PHP.** `base64_decode`
 * with its strict flag is looser than `binary` is: it accepts white space in
 * the middle of a value and a final group with characters missing, decoding
 * `aGVsbG` to three octets of the five somebody meant. Both were measured
 * rather than assumed. A value that is not what the grammar says is not the
 * octets anybody put there, so it is refused.
 *
 * **vCard 4.0 has no `BINARY` at all** — RFC 6350 §4 lists nine value types
 * and this is not among them. A photograph there is a `URI`, and in practice
 * a `data:` one: see {@see DataUri}.
 */
final class Binary
{
    /**
     * `binary` written out, so that what is accepted is what §3.3.1 defines:
     * whole groups of four, with at most one padded group at the end.
     */
    private const GRAMMAR = '#^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$#';

    /**
     * The octets a raw value carries.
     *
     * @throws ParseError If it is not base64 by the grammar of §3.3.1
     */
    public static function decode(string $raw): string
    {
        if (preg_match(self::GRAMMAR, $raw) !== 1) {
            throw new ParseError('This value is no BASE64 character string.');
        }

        // The grammar has already settled it, so the second answer can only
        // agree.
        return (string) base64_decode($raw, true);
    }

    /**
     * The raw value for some octets.
     */
    public static function encode(string $octets): string
    {
        return base64_encode($octets);
    }
}
