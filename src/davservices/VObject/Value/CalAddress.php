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

/**
 * The `CAL-ADDRESS` value type: who a calendar user is.
 *
 *     cal-address = uri
 *
 * RFC 5545 §3.3.3: "The value is a URI as defined by [RFC3986] or any other
 * IANA-registered form for a URI. When used to address an Internet email
 * transport address for a calendar user, the value MUST be a mailto URI" —
 * which is what every `ATTENDEE` and `ORGANIZER` in practice is.
 *
 * It carries its value through untouched, for the reason §3.3.3 gives in the
 * same breath as every other type but `TEXT`: "No additional content value
 * encoding (i.e., BACKSLASH character encoding …) is defined for this value
 * type." An address may hold the characters `TEXT` escapes, and escaping them
 * would corrupt it.
 *
 * Whether the URI is well-formed, and whether a mail address really is a
 * `mailto:` one, are statements about the value — so they belong with the
 * validation of P4-06 rather than with reading it.
 */
final class CalAddress
{
    /**
     * The address a raw value carries, which is the raw value.
     */
    public static function decode(string $raw): string
    {
        return $raw;
    }

    /**
     * The raw value for an address, which is the address.
     */
    public static function encode(string $value): string
    {
        return $value;
    }
}
