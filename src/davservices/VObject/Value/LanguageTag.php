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
 * The `LANGUAGE-TAG` value type of vCard: carried through exactly as written.
 *
 * RFC 6350 §4.8, in full: "'language-tag': A single language tag, as defined
 * in [RFC5646]." There is no escaping and nothing to undo.
 *
 * **The spelling is kept**, although RFC 5646 §2.1.1 makes tags
 * case-insensitive — precisely because the case carries no meaning, changing
 * it would be a change to somebody's file for nothing.
 *
 * **The tag is not checked against RFC 5646's grammar.** That is a statement
 * about whether the value is well-formed, which is the validation of P4-06;
 * reading it is not where a judgement belongs.
 */
final class LanguageTag
{
    /**
     * The tag a raw value carries, which is the raw value.
     */
    public static function decode(string $raw): string
    {
        return $raw;
    }

    /**
     * The raw value for a tag, which is the tag.
     */
    public static function encode(string $value): string
    {
        return $value;
    }
}
