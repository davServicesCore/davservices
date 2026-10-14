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
 * `i;ascii-casemap`: twenty-six letters folded, nothing else (RFC 4790 §9.2).
 *
 * > Its equality, ordering, and substring operations are as for i;octet, except
 * > that at first, the lower-case letters (octet values 97-122) in each input
 * > string are changed to upper case (octet values 65-90).
 *
 * **Exactly those twenty-six**, and the same section says so twice: "Note that
 * letters outside ASCII are not treated case-insensitively."
 *
 * ## The shortcut the memo warns about
 *
 * > Care should be taken when using OS-supplied functions to implement this
 * > collation, as it is not locale sensitive. Functions, such as strcasecmp
 * > and toupper, are sometimes locale sensitive, and may inappropriately map
 * > lower-case letters other than a-z to upper case.
 *
 * So the mapping is written out rather than delegated. `strtoupper()` happens
 * to be ASCII-only since PHP 8.2 and would give the same answer today — but
 * the collation would then be correct because of a release note instead of
 * because of its own code, and a server whose search results depend on the
 * locale it was started in is a server nobody can reproduce a bug on.
 *
 * RFC 5051 §1 is worth reading before reaching for this one: "the
 * 'i;ascii-casemap' collation does not produce satisfactory results with
 * non-ASCII characters", which is why CardDAV defaults to
 * {@see UnicodeCasemap} instead.
 */
final class AsciiCasemap extends Prepared
{
    public const IDENTIFIER = 'i;ascii-casemap';

    /**
     * Octet values 97–122 to octet values 65–90, and not one byte besides.
     */
    private const LOWER = 'abcdefghijklmnopqrstuvwxyz';

    private const UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * The identifier a client names this collation by.
     */
    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    /**
     * The same string with `a`–`z` turned into `A`–`Z`.
     */
    protected function prepare(string $subject): string
    {
        return strtr($subject, self::LOWER, self::UPPER);
    }
}
