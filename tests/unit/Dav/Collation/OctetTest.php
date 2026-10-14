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

namespace DavServices\Tests\Unit\Dav\Collation;

use DavServices\Dav\Collation\Collation;
use DavServices\Dav\Collation\Octet;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The contract, run against `i;octet`, plus what only it can get wrong.
 *
 * RFC 4790 §9.3.1: "a simple and fast collation intended for use on binary
 * octet strings rather than on character data". It changes nothing before
 * comparing, which is the whole of it — and the reason it is the one collation
 * a client can use to ask for an exact match.
 */
#[CoversClass(Octet::class)]
final class OctetTest extends CollationContract
{
    /**
     * **It is case sensitive**, and that is the point of it: a client that
     * wants `simon` to match `Simon` asks for a casemap collation instead.
     */
    public function testItTellsCaseApart(): void
    {
        $collation = $this->collation();

        self::assertFalse($collation->equals('simon', 'Simon'));
        self::assertFalse($collation->contains('simon', 'Simon Perreault'));
    }

    /**
     * **It compares octets, not characters.** A string is whatever bytes it
     * holds — which is why §9.3.1 calls it suitable for binary data, and why
     * two different encodings of the same letter are two different strings
     * here.
     */
    public function testItComparesOctetsRatherThanCharacters(): void
    {
        $collation = $this->collation();

        // U+00FC as one precomposed character, and as u followed by a
        // combining diaeresis. The same letter to a reader, two strings here.
        self::assertFalse($collation->equals("\u{00fc}", "u\u{0308}"));

        // And it does not stumble over a byte that is no character at all.
        self::assertTrue($collation->equals("\xff\x00", "\xff\x00"));
        self::assertTrue($collation->contains("\x00", "\xff\x00"));
    }

    /**
     * **A match inside a multi-byte character is a match**, because there are
     * no characters here. It is worth stating: this is exactly the behaviour
     * `i;unicode-casemap` exists to avoid for text.
     */
    public function testItWillMatchHalfOfACharacter(): void
    {
        self::assertTrue($this->collation()->contains("\xc3", "\u{00fc}"));
    }

    protected function collation(): Collation
    {
        return new Octet();
    }

    protected function identifier(): string
    {
        return 'i;octet';
    }
}
