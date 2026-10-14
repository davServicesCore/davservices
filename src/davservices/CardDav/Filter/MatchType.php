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

use DavServices\Dav\Collation\Collation;
use DavServices\Exception\BadRequest;

/**
 * Which of a collation's four operations a `text-match` asks for (§10.5.4).
 *
 *     <!ATTLIST text-match
 *        collation        CDATA "i;unicode-casemap"
 *        negate-condition (yes | no) "no"
 *        match-type (equals|contains|starts-with|ends-with) "contains">
 *
 * RFC 6352 describes the four in the terms RFC 4790 §4.2 gives every
 * collation: "equals" is "an exact match to the target string", "contains" "a
 * substring match, matching anywhere within the target string", and the last
 * two the same thing "matching only at the start" and "only at the end". So
 * this enum does no matching of its own — it names which question to put to
 * the collation the client chose.
 *
 * **The default is `contains`, not `equals`.** A client that leaves the
 * attribute out is searching, and a server that answered exactly would hand it
 * nothing.
 */
enum MatchType: string
{
    case Equals = 'equals';

    case Contains = 'contains';

    case StartsWith = 'starts-with';

    case EndsWith = 'ends-with';

    /**
     * What the attribute says, or the default where there is none.
     *
     * @throws BadRequest If it names a fifth operation. RFC 4790 §4.2 gives a
     *                    collation these four and no more, so there is nothing
     *                    to fall back to
     */
    public static function of(?string $attribute): self
    {
        if ($attribute === null) {
            return self::Contains;
        }

        return self::tryFrom($attribute)
            ?? throw new BadRequest(sprintf('"%s" is no match type of RFC 6352 §10.5.4.', $attribute));
    }

    /**
     * Whether the text the client sent matches the value from the card.
     *
     * The argument order is the collation's, which is RFC 4790's and the
     * opposite of `str_contains`: what is looked for comes first.
     *
     * **It is written as an `if` chain rather than a `match`**, for the reason
     * {@see Test::holds()} is: PHP compiles an exhaustive `match` with an
     * implicit arm that throws `UnhandledMatchError`, and no value of this
     * enum can reach it. This library holds itself to covering every branch it
     * has, and a branch excused from that is one nobody looks at again.
     *
     * Nothing is lost by it. A fifth match type would be a new memo — RFC 4790
     * §4.2 gives a collation these four operations and §10.5.4's DTD lists
     * these four names — and it would arrive with a fifth method on
     * {@see Collation}, which is where it would be missed.
     */
    public function holds(Collation $collation, string $lookedFor, string $lookedIn): bool
    {
        if ($this === self::Equals) {
            return $collation->equals($lookedFor, $lookedIn);
        }

        if ($this === self::Contains) {
            return $collation->contains($lookedFor, $lookedIn);
        }

        if ($this === self::StartsWith) {
            return $collation->startsWith($lookedFor, $lookedIn);
        }

        return $collation->endsWith($lookedFor, $lookedIn);
    }
}
