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

use DavServices\Dav\Collation\AsciiCasemap;
use DavServices\Dav\Collation\Collation;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The contract, run against `i;ascii-casemap`, plus what only it can get wrong.
 *
 * RFC 4790 §9.2.1 defines the whole collation in one sentence:
 *
 * > Its equality, ordering, and substring operations are as for i;octet, except
 * > that at first, the lower-case letters (octet values 97-122) in each input
 * > string are changed to upper case (octet values 65-90).
 *
 * **Twenty-six letters, and not one more.** The same section says so twice:
 * "Note that letters outside ASCII are not treated case-insensitively", and
 * then warns about the obvious shortcut — "Care should be taken when using
 * OS-supplied functions to implement this collation, as it is not locale
 * sensitive. Functions, such as strcasecmp and toupper, are sometimes locale
 * sensitive, and may inappropriately map lower-case letters other than a-z to
 * upper case."
 */
#[CoversClass(AsciiCasemap::class)]
final class AsciiCasemapTest extends CollationContract
{
    /**
     * **The twenty-six letters are case insensitive**, which is the one thing
     * this collation adds to `i;octet`.
     */
    public function testTheTwentySixLettersAreCaseInsensitive(): void
    {
        $collation = $this->collation();

        self::assertTrue($collation->equals('simon', 'SIMON'));
        self::assertTrue($collation->equals('Simon Perreault', 'sIMON pERREAULT'));
        self::assertTrue($collation->contains('perreault', 'Simon PERREAULT'));
        self::assertTrue($collation->startsWith('SIMON', 'simon perreault'));
        self::assertTrue($collation->endsWith('perreault', 'Simon Perreault'));
    }

    /**
     * **And nothing outside them is.** §9.2.1: "letters outside ASCII are not
     * treated case-insensitively." A `toupper` that honoured a locale would
     * fold these, and then two servers would answer the same search
     * differently depending on how they were started.
     */
    public function testNothingOutsideThoseTwentySixIs(): void
    {
        $collation = $this->collation();

        self::assertFalse($collation->equals("\u{00fc}", "\u{00dc}"));
        self::assertFalse($collation->equals("\u{00e9}", "\u{00c9}"));
        self::assertFalse($collation->equals("\u{0131}", 'I'));
        self::assertFalse($collation->equals("\u{03c3}", "\u{03a3}"));
    }

    /**
     * **Only the letters are mapped, not the octets around them.** The range
     * 97–122 is `a`–`z`; the brace, the backtick and the braces beside them in
     * ASCII must come through untouched, or a search for `{` would find `[`.
     */
    public function testOnlyTheLettersAreMapped(): void
    {
        $collation = $this->collation();

        self::assertFalse($collation->equals('{', '['));
        self::assertFalse($collation->equals('`', '@'));
        self::assertFalse($collation->equals('|', '\\'));
        self::assertTrue($collation->equals('{|}~`', '{|}~`'));
    }

    /**
     * **A byte that is no character at all comes through as itself**, because
     * the operations are `i;octet`'s once the letters have been mapped.
     */
    public function testAByteThatIsNoCharacterComesThroughAsItself(): void
    {
        $collation = $this->collation();

        self::assertTrue($collation->equals("\xff\x00a", "\xff\x00A"));
        self::assertFalse($collation->equals("\xff", "\xfe"));
    }

    protected function collation(): Collation
    {
        return new AsciiCasemap();
    }

    protected function identifier(): string
    {
        return 'i;ascii-casemap';
    }
}
