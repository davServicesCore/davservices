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
 * The collations this server has, by the identifier a client names.
 *
 * ## Three, and the memos say which
 *
 * RFC 6352 §8.3: "CardDAV servers are REQUIRED to support the
 * 'i;ascii-casemap' [RFC4790] and 'i;unicode-casemap' [RFC5051] collations and
 * MAY support other collations." R-CARD-09 adds the third:
 * `supported-collation-set` must report `i;octet` as well, which RFC 4790
 * §9.3.1 permits only where a protocol allows it explicitly — and §8.3.1's own
 * example of the property lists it.
 *
 * ## `default` is not a collation
 *
 * RFC 4790 §3.1: "There is a special identifier called 'default'. For protocols
 * that have a default collation, 'default' refers to that collation." RFC 6352
 * §8.3 says which one CardDAV means: "In the absence of a collation explicitly
 * specified by the client, or if the client specifies the 'default' collation
 * identifier […] the server MUST default to using 'i;unicode-casemap' as the
 * collation."
 *
 * So both ways of saying nothing in particular end at the same place, and
 * {@see named()} answers `default` with {@see UnicodeCasemap}.
 *
 * ## An unknown one is refused, and the refusal belongs to the layer above
 *
 * §8.3: "If the client chooses a collation not supported by the server, the
 * server MUST respond with a CARDDAV:supported-collation precondition error
 * response." That element is CardDAV's, and this is the `Dav` layer, which may
 * not reach up into it — so {@see UnknownCollation} carries the identifier and
 * the CardDAV filter that caught it writes the precondition — which is
 * `CardDav\Filter\TextMatch`, where the attribute is read. The same arrangement as
 * `VObject\Recur\TooManyIterations`, for the same reason.
 *
 * **Wildcards are not matching, either.** §8.3: "Wildcards (as defined in
 * [RFC4790], Section 3.2) MUST NOT be used in the collation identifier." An
 * identifier with one in it is simply not one of these three, so it is refused
 * by the same door rather than by a rule of its own.
 *
 * **There is no `has()` beside {@see named()}**, and there was: asking whether
 * a name is known and asking for the collation are one question, and a second
 * way of putting it had no caller in the library — only its own test. A public
 * method nobody calls is an untested promise, and the shards made that visible
 * before a reader would have.
 */
final class Collations
{
    /**
     * What RFC 4790 §3.1 calls the special identifier.
     */
    public const ANY = 'default';

    /**
     * The identifiers, in the order RFC 6352 §8.3.1's example writes them.
     *
     * `supported-collation-set` is built from this, so the order a client sees
     * is the order the memo's own example has.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return [AsciiCasemap::IDENTIFIER, Octet::IDENTIFIER, UnicodeCasemap::IDENTIFIER];
    }

    /**
     * The collation a client named, or the default where it named none.
     *
     * @param string|null $identifier What the client asked for; null is "the
     *                                absence of a collation explicitly
     *                                specified", which §8.3 treats exactly
     *                                like `default`
     *
     * @throws UnknownCollation If this server has no collation of that name
     */
    public static function named(?string $identifier): Collation
    {
        return match ($identifier) {
            null, self::ANY, UnicodeCasemap::IDENTIFIER => new UnicodeCasemap(),
            AsciiCasemap::IDENTIFIER => new AsciiCasemap(),
            Octet::IDENTIFIER => new Octet(),
            default => throw new UnknownCollation($identifier),
        };
    }
}
