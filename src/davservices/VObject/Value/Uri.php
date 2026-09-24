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
 * The `URI` value type: carried through exactly as written.
 *
 * **That is the whole point of having it.** RFC 5545 §3.3.13: "No additional
 * content value encoding (i.e., BACKSLASH character encoding, see Section
 * 3.3.11) is defined for this value type."
 *
 * A URI is full of the characters `TEXT` escapes. Every `data:` URI carries a
 * semicolon and a comma in its own syntax, and a `mailto:` with parameters
 * carries both again — so a writer that reached for the `TEXT` rules would
 * corrupt all of them. Saying so here, with a test, is what keeps that from
 * happening when the serialiser comes to dispatch by type.
 *
 * **The syntax is not checked.** §3.3.13 says "Property values with this
 * value type MUST follow the generic URI syntax defined in [RFC3986]" — a
 * MUST about the value, which makes it a question for validation rather than
 * for reading. A reader that refused a malformed URI would take the evidence
 * away from whoever has to repair the file.
 */
final class Uri
{
    /**
     * The URI a raw value carries, which is the raw value.
     */
    public static function decode(string $raw): string
    {
        return $raw;
    }

    /**
     * The raw value for a URI, which is the URI.
     */
    public static function encode(string $value): string
    {
        return $value;
    }
}
