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
 * A collation that prepares its strings and then compares octets.
 *
 * **This is the shape RFC 4790 and RFC 5051 describe, not one invented here.**
 * Each of the three collations defines itself the same way:
 *
 * - `i;octet` compares the octets as they are (§9.3.1);
 * - `i;ascii-casemap` is "as for i;octet, except that **at first**, the
 *   lower-case letters (octet values 97-122) in each input string are changed
 *   to upper case" (§9.2.1);
 * - `i;unicode-casemap` says "**Following the above preparation process on
 *   each string**, the equality, ordering, and substring operations are as for
 *   i;octet" (RFC 5051 §2).
 *
 * So there is one place that does the comparing and one method per collation
 * that says what to do first. A collation cannot then get the substring rule
 * wrong on its own — and that rule is the easy one to get wrong, because
 * RFC 4790 §4.2.3 defines substring in terms of **equality**: "if one or more
 * substrings of the second string is equal to the first, as defined by the
 * collation's equality operation". Searching the *input* for the *prepared*
 * needle would be a different question, and `i;unicode-casemap` changes the
 * length of what it prepares, so the two answers come apart.
 */
abstract class Prepared implements Collation
{
    /**
     * The string this collation actually compares.
     *
     * Called once per argument per operation, so an implementation does the
     * work and keeps no state: §4.2 wants the same answer every time.
     */
    abstract protected function prepare(string $subject): string;

    /**
     * Whether the two strings are equal once both have been prepared.
     */
    final public function equals(string $one, string $other): bool
    {
        return $this->prepare($one) === $this->prepare($other);
    }

    /**
     * Whether the first string occurs somewhere in the second, both prepared.
     *
     * @param string $lookedFor What a client is searching for
     * @param string $lookedIn What is being searched
     */
    final public function contains(string $lookedFor, string $lookedIn): bool
    {
        return str_contains($this->prepare($lookedIn), $this->prepare($lookedFor));
    }

    /**
     * Whether the second string begins with the first, both prepared.
     *
     * @param string $lookedFor What a client is searching for
     * @param string $lookedIn What is being searched
     */
    final public function startsWith(string $lookedFor, string $lookedIn): bool
    {
        return str_starts_with($this->prepare($lookedIn), $this->prepare($lookedFor));
    }

    /**
     * Whether the second string ends with the first, both prepared.
     *
     * @param string $lookedFor What a client is searching for
     * @param string $lookedIn What is being searched
     */
    final public function endsWith(string $lookedFor, string $lookedIn): bool
    {
        return str_ends_with($this->prepare($lookedIn), $this->prepare($lookedFor));
    }
}
