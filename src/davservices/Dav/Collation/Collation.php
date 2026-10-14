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

namespace DavServices\Dav\Collation;

/**
 * One way of deciding whether two strings match (RFC 4790).
 *
 * **A collation is not a setting but a definition.** RFC 6352 §8.3 makes the
 * point for CardDAV: a `text-match` means nothing until it is said *how* text
 * is compared, and the identifier a client sends is what says it. The same
 * applies to CalDAV later.
 *
 * ## Four operations, and why these four
 *
 * RFC 6352 §8.3: "Collations supported by the server MUST support 'equality'
 * and 'substring' match operations as per [RFC4790], Section 4.2, including
 * the 'prefix' and 'suffix' options for 'substring' matching. CardDAV uses
 * these match options for 'equals', 'contains', 'starts-with', and 'ends-with'
 * match operations."
 *
 * **Ordering is deliberately absent.** RFC 4790 defines it and all three
 * collations here would support it, but no report in this library sorts by a
 * collation — and an operation nobody calls is an untested promise. It is a
 * few lines on the day something needs it.
 *
 * ## The argument order is the RFC's, not PHP's
 *
 * §4.2.3: "The substring matching operation determines if the **first** string
 * is a substring of the **second** string". So {@see contains()} takes what is
 * looked for first and what is looked in second — the opposite way round from
 * `str_contains()`, which is a trap worth naming rather than quietly matching.
 *
 * ## Substring is defined in terms of equality
 *
 * §4.2.3 again: "if one or more substrings of the second string is equal to
 * the first, **as defined by the collation's equality operation**". A
 * collation that changes the length of what it compares — and
 * `i;unicode-casemap` does, since decomposing a letter makes it longer —
 * therefore cannot answer the substring question by searching the input. It
 * answers it on what it prepared. {@see Prepared} is where that lives, so that
 * no collation can get it wrong on its own.
 *
 * ## And it is deterministic
 *
 * §4.2: "Collations must be deterministic, i.e., given a collation with a
 * specific identifier, and any two fixed input strings, the result MUST be the
 * same for the same operation."
 *
 * The identifier is therefore a promise about behaviour rather than a label,
 * and it is why this library gives each collation **one** code path. A faster
 * one for servers with an extension installed and a slower one for the rest
 * would be two answers under one identifier, and a search whose result
 * depended on how the server was compiled.
 */
interface Collation
{
    /**
     * The identifier a client names this collation by, as registered with
     * IANA — `i;octet`, `i;ascii-casemap`, `i;unicode-casemap`.
     */
    public function identifier(): string;

    /**
     * Whether the two strings are equal under this collation.
     */
    public function equals(string $one, string $other): bool;

    /**
     * Whether the first string occurs somewhere in the second.
     *
     * @param string $lookedFor What a client is searching for
     * @param string $lookedIn What is being searched
     */
    public function contains(string $lookedFor, string $lookedIn): bool;

    /**
     * Whether the second string begins with the first.
     *
     * @param string $lookedFor What a client is searching for
     * @param string $lookedIn What is being searched
     */
    public function startsWith(string $lookedFor, string $lookedIn): bool;

    /**
     * Whether the second string ends with the first.
     *
     * @param string $lookedFor What a client is searching for
     * @param string $lookedIn What is being searched
     */
    public function endsWith(string $lookedFor, string $lookedIn): bool;
}
