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

namespace DavServices\Tests\Unit\CardDav\Filter;

use DavServices\CardDav\Filter\MatchType;
use DavServices\Dav\Collation\AsciiCasemap;
use DavServices\Dav\Collation\Octet;
use DavServices\Exception\BadRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The `match-type` attribute of RFC 6352 §10.5.4.
 *
 *     <!ATTLIST text-match
 *        collation        CDATA "i;unicode-casemap"
 *        negate-condition (yes | no) "no"
 *        match-type (equals|contains|starts-with|ends-with) "contains">
 *
 * The four name the four operations RFC 4790 §4.2 gives every collation, and the
 * memo describes them in those terms: "equals" is "an exact match to the
 * target string", "contains" "a substring match, matching anywhere within the
 * target string", and the other two the same "matching only at the start" and
 * "only at the end".
 */
#[CoversClass(MatchType::class)]
final class MatchTypeTest extends TestCase
{
    /**
     * **The default is `contains`**, not `equals`. A server that matched
     * exactly where a client left the attribute out would answer a search for
     * "Simon" with nothing at all.
     */
    public function testTheDefaultIsContains(): void
    {
        self::assertSame(MatchType::Contains, MatchType::of(null));
    }

    public function testReadsAllFourAsTheyAreSpelt(): void
    {
        self::assertSame(MatchType::Equals, MatchType::of('equals'));
        self::assertSame(MatchType::Contains, MatchType::of('contains'));
        self::assertSame(MatchType::StartsWith, MatchType::of('starts-with'));
        self::assertSame(MatchType::EndsWith, MatchType::of('ends-with'));
    }

    public function testRefusesAFifth(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('matches');

        MatchType::of('matches');
    }

    /**
     * **Each of the four reaches a different operation**, and this table is
     * what says so: the three needles below tell all four apart. "Simon"
     * separates `equals` from `starts-with`, "Perreault" separates
     * `starts-with` from `ends-with`, and either of them separates `contains`
     * from both ends.
     */
    #[DataProvider('needles')]
    public function testTheFourOperationsAreToldApart(
        MatchType $type,
        string $lookedFor,
        bool $expected,
    ): void {
        self::assertSame($expected, $type->holds(new Octet(), $lookedFor, 'Simon Perreault'));
    }

    /**
     * @return iterable<string, array{MatchType, string, bool}>
     */
    public static function needles(): iterable
    {
        yield 'equals a part' => [MatchType::Equals, 'Simon', false];
        yield 'equals the whole' => [MatchType::Equals, 'Simon Perreault', true];
        yield 'contains the front' => [MatchType::Contains, 'Simon', true];
        yield 'contains the middle' => [MatchType::Contains, 'mon Per', true];
        yield 'contains the back' => [MatchType::Contains, 'Perreault', true];
        yield 'contains what is absent' => [MatchType::Contains, 'Howes', false];
        yield 'starts with the front' => [MatchType::StartsWith, 'Simon', true];
        yield 'starts with the back' => [MatchType::StartsWith, 'Perreault', false];
        yield 'ends with the back' => [MatchType::EndsWith, 'Perreault', true];
        yield 'ends with the front' => [MatchType::EndsWith, 'Simon', false];
    }

    /**
     * **The collation handed in is the one that answers.** The same needle and
     * the same target give two answers under two collations, which is the whole
     * point of the `collation` attribute standing beside this one.
     */
    public function testTheCollationIsTheOneDoingTheMatching(): void
    {
        self::assertFalse(MatchType::Contains->holds(new Octet(), 'simon', 'Simon Perreault'));
        self::assertTrue(MatchType::Contains->holds(new AsciiCasemap(), 'simon', 'Simon Perreault'));
    }
}
