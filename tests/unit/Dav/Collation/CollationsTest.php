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
use DavServices\Dav\Collation\Collations;
use DavServices\Dav\Collation\Octet;
use DavServices\Dav\Collation\UnicodeCasemap;
use DavServices\Dav\Collation\UnknownCollation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for finding a collation by the name a client sends. R-CARD-09.
 *
 * Three of them, and the memos say which: RFC 6352 §8.3 makes
 * `i;ascii-casemap` and `i;unicode-casemap` REQUIRED, and R-CARD-09 adds
 * `i;octet` — which RFC 4790 §9.3.1 allows only where a protocol says so
 * explicitly, and §8.3.1's own example of `supported-collation-set` does.
 */
#[CoversClass(Collations::class)]
#[CoversClass(UnknownCollation::class)]
final class CollationsTest extends TestCase
{
    /**
     * **All three are there, in the order the memo's example writes them.**
     */
    public function testAllThreeAreThere(): void
    {
        self::assertSame(
            ['i;ascii-casemap', 'i;octet', 'i;unicode-casemap'],
            Collations::names(),
        );
    }

    /**
     * **Each name gives the collation it names.**
     *
     * @param non-empty-string $identifier
     * @param class-string $expected
     */
    #[DataProvider('everyName')]
    public function testEachNameGivesTheCollationItNames(string $identifier, string $expected): void
    {
        $collation = Collations::named($identifier);

        self::assertInstanceOf($expected, $collation);
        self::assertSame($identifier, $collation->identifier());
    }

    /**
     * @return iterable<string, array{string, class-string}>
     */
    public static function everyName(): iterable
    {
        yield 'i;octet' => [Octet::IDENTIFIER, Octet::class];

        yield 'i;ascii-casemap' => [AsciiCasemap::IDENTIFIER, AsciiCasemap::class];

        yield 'i;unicode-casemap' => [UnicodeCasemap::IDENTIFIER, UnicodeCasemap::class];
    }

    /**
     * **`default` means `i;unicode-casemap`**, and so does saying nothing.
     *
     * RFC 6352 §8.3: "In the absence of a collation explicitly specified by the
     * client, or if the client specifies the 'default' collation identifier
     * (as defined in [RFC4790], Section 3.1), the server MUST default to using
     * 'i;unicode-casemap' as the collation."
     *
     * Both ways of saying nothing in particular therefore end at the same
     * place, which is why they are one test: a server that answered `default`
     * correctly and an absent collation with something else would be wrong in
     * the case that happens most.
     */
    public function testSayingNothingInParticularMeansUnicodeCasemap(): void
    {
        self::assertInstanceOf(UnicodeCasemap::class, Collations::named('default'));
        self::assertInstanceOf(UnicodeCasemap::class, Collations::named(null));
    }

    /**
     * **A collation this server has not got is refused**, and the refusal
     * carries the name so that the layer above can write §8.3's
     * `CARDDAV:supported-collation` precondition.
     */
    public function testACollationThisServerHasNotGotIsRefused(): void
    {
        $refusal = null;

        try {
            Collations::named('i;basic');
        } catch (UnknownCollation $thrown) {
            $refusal = $thrown;
        }

        self::assertNotNull($refusal);
        self::assertSame('i;basic', $refusal->identifier());
        self::assertStringContainsString('i;basic', $refusal->getMessage());
    }

    /**
     * **A wildcard is refused by the same door.** §8.3: "Wildcards (as defined
     * in [RFC4790], Section 3.2) MUST NOT be used in the collation
     * identifier." An identifier with one in it is simply not one of the
     * three, so it needs no rule of its own — but it needs a test, or somebody
     * will add one.
     *
     * @param non-empty-string $identifier
     */
    #[DataProvider('identifiersNobodyHasHere')]
    public function testAnIdentifierNobodyHasHereIsRefused(string $identifier): void
    {
        $this->expectException(UnknownCollation::class);

        Collations::named($identifier);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function identifiersNobodyHasHere(): iterable
    {
        yield 'a wildcard' => ['i;unicode-*'];

        yield 'a bare wildcard' => ['*'];

        yield 'another registered collation' => ['i;basic'];

        yield 'nothing at all' => [''];

        yield 'the wrong case' => ['I;OCTET'];
    }
}
