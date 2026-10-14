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

namespace DavServices\CardDav\Filter;

use DavServices\Exception\BadRequest;

/**
 * Whether any of the conditions below have to match, or all of them.
 *
 * The `test` attribute appears on two elements, and RFC 6352 gives it the same
 * two values and the same default in both (§10.5, §10.5.1):
 *
 *     <!ATTLIST filter test (anyof | allof) "anyof">
 *     <!ATTLIST prop-filter name CDATA #REQUIRED
 *                           test (anyof | allof) "anyof">
 *
 * **The default is `anyof`, and it is worth saying twice.** A filter carrying
 * three conditions and no `test` attribute matches a card that satisfies *one*
 * of them. CalDAV's filters nest rather than combine, so the instinct is to
 * read this one as a conjunction — and a server that did would answer a
 * client's search with a fraction of the cards it asked for, without any sign
 * that it had.
 *
 * It is one enum rather than a flag on each of the two elements because the
 * memo states the question once, and because the default and the refusal would
 * otherwise be written twice and could drift apart.
 */
enum Test: string
{
    case AnyOf = 'anyof';

    case AllOf = 'allof';

    /**
     * What the attribute says, or the default where there is none.
     *
     * @throws BadRequest If it says something else. The DTD admits two values,
     *                    and a third is a request this server cannot answer
     *                    rather than one it ought to guess at: reading an
     *                    unknown word as `anyof` hands a client that meant
     *                    `allof` the cards it was trying to exclude. An
     *                    enumerated attribute is also matched as it is written,
     *                    so `ANYOF` is none of the two
     */
    public static function of(?string $attribute): self
    {
        if ($attribute === null) {
            return self::AnyOf;
        }

        return self::tryFrom($attribute)
            ?? throw new BadRequest(sprintf('"%s" is no test of RFC 6352 §10.5.', $attribute));
    }

    /**
     * Whether this test holds over those outcomes.
     *
     * **Over nothing the two part company**, and neither answer is a choice:
     * `allof` holds because nothing failed, `anyof` does not because nothing
     * matched. Callers lean on that — a `prop-filter` carrying no conditions at
     * all never reaches here, because §10.5.1 gives that case a rule of its
     * own.
     *
     * @param list<bool> $outcomes
     */
    public function holds(array $outcomes): bool
    {
        if ($this === self::AllOf) {
            return !in_array(false, $outcomes, true);
        }

        return in_array(true, $outcomes, true);
    }
}
