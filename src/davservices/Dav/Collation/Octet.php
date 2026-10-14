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
 * `i;octet`: the octets as they are (RFC 4790 §9.3).
 *
 * > The "i;octet" collation is a simple and fast collation intended for use on
 * > binary octet strings rather than on character data.
 *
 * It changes nothing before comparing, which makes it the one collation a
 * client can use to ask for an exact match — and the one that will happily
 * match half of a multi-byte character, because at this level there are no
 * characters.
 *
 * §9.3.1 adds a condition the protocol has to meet: "Protocols that want to
 * make this collation available have to do so by explicitly allowing it. If
 * not explicitly allowed, it MUST NOT be used." CardDAV allows it — RFC 6352
 * §8.3.1's own example of `CARDDAV:supported-collation-set` lists `i;octet`
 * among the three, and R-CARD-09 requires reporting it.
 */
final class Octet extends Prepared
{
    public const IDENTIFIER = 'i;octet';

    /**
     * The identifier a client names this collation by.
     */
    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    /**
     * Nothing at all, which is the whole specification.
     */
    protected function prepare(string $subject): string
    {
        return $subject;
    }
}
