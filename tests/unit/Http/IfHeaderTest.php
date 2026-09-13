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

namespace DavServices\Tests\Unit\Http;

use DavServices\Exception\BadRequest;
use DavServices\Http\ETag;
use DavServices\Http\IfCondition;
use DavServices\Http\IfHeader;
use DavServices\Http\IfList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-HTTP-07, which asks for the whole of the WebDAV
 * `If` header of RFC 4918 §10.4 and for no fewer than thirty cases.
 *
 * The grammar in one breath: the header is a list of lists. Every condition
 * inside a list must hold — they are an *and*; one list holding is enough for
 * the header — they are an *or*. A condition is a state token in angle
 * brackets or an entity tag in square ones, either of them optionally negated
 * by `Not`. A list can be tagged with the resource it is about, and the tag
 * then holds for every list that follows it until the next one.
 *
 * That "until the next one" and the mixture of *and* and *or* are where this
 * header is usually got wrong. A client that submits a lock token for one
 * resource and an entity tag for another gets both checked against the wrong
 * thing, and its request then either fails for no reason or — worse — goes
 * through when it should not have.
 *
 * What this class does **not** do is decide whether the conditions hold: that
 * needs locks and resources, and it arrives with the lock plugin in P3.
 */
#[CoversClass(IfHeader::class)]
#[CoversClass(IfList::class)]
#[CoversClass(IfCondition::class)]
final class IfHeaderTest extends TestCase
{
    public function testReadsAStateToken(): void
    {
        $lists = IfHeader::parse('(<urn:uuid:181d4fae-7d8c-11d0-a765-00a0c91e6bf2>)')->lists();

        self::assertCount(1, $lists);
        self::assertNull(self::listAt($lists, 0)->resource());

        $condition = self::conditionAt(self::listAt($lists, 0)->conditions(), 0);

        self::assertFalse($condition->isNegated());
        self::assertSame('urn:uuid:181d4fae-7d8c-11d0-a765-00a0c91e6bf2', $condition->stateToken());
        self::assertNull($condition->etag());
    }

    public function testReadsAnEntityTag(): void
    {
        $condition = self::firstCondition('(["abc"])');

        self::assertNull($condition->stateToken());
        self::assertSame('"abc"', self::etagOf($condition)->toString());
    }

    public function testReadsAWeakEntityTag(): void
    {
        self::assertTrue(self::etagOf(self::firstCondition('([W/"abc"])'))->isWeak());
    }

    /**
     * Every condition inside one list has to hold: they are an *and*.
     */
    public function testReadsSeveralConditionsInOneList(): void
    {
        $conditions = self::firstList('(<urn:a> ["abc"] Not <urn:b>)')->conditions();

        self::assertCount(3, $conditions);
        self::assertSame('urn:a', self::conditionAt($conditions, 0)->stateToken());
        self::assertNotNull(self::conditionAt($conditions, 1)->etag());
        self::assertTrue(self::conditionAt($conditions, 2)->isNegated());
        self::assertSame('urn:b', self::conditionAt($conditions, 2)->stateToken());
    }

    public function testReadsANegatedEntityTag(): void
    {
        $condition = self::firstCondition('(Not ["abc"])');

        self::assertTrue($condition->isNegated());
        self::assertNotNull($condition->etag());
    }

    /**
     * RFC 5234 §2.3 makes the literals of an ABNF grammar case-insensitive,
     * so `Not`, `not` and `NOT` are the same word — and clients send all three.
     */
    #[DataProvider('negations')]
    public function testReadsTheNegationHoweverItIsSpelled(string $header): void
    {
        self::assertTrue(self::firstCondition($header)->isNegated());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function negations(): iterable
    {
        yield 'as the grammar writes it' => ['(Not <urn:a>)'];
        yield 'in lower case' => ['(not <urn:a>)'];
        yield 'in upper case' => ['(NOT <urn:a>)'];
        yield 'mixed' => ['(nOt <urn:a>)'];
        yield 'without a space after it' => ['(Not<urn:a>)'];
    }

    /**
     * One list holding is enough for the header: they are an *or*.
     */
    public function testReadsSeveralLists(): void
    {
        $lists = IfHeader::parse('(<urn:a>) (<urn:b>)')->lists();

        self::assertCount(2, $lists);
        self::assertSame('urn:a', self::conditionAt(self::listAt($lists, 0)->conditions(), 0)->stateToken());
        self::assertSame('urn:b', self::conditionAt(self::listAt($lists, 1)->conditions(), 0)->stateToken());
    }

    #[DataProvider('resourceTags')]
    public function testReadsTheResourceAListIsAbout(string $header, string $resource): void
    {
        self::assertSame($resource, self::firstList($header)->resource());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function resourceTags(): iterable
    {
        yield 'an absolute path' => ['</calendars/alice/> (<urn:a>)', '/calendars/alice/'];
        yield 'an absolute URI' => ['<http://dav.example/x> (<urn:a>)', 'http://dav.example/x'];
        yield 'a path with an escape in it' => ['</work%20week.ics> (<urn:a>)', '/work%20week.ics'];
    }

    /**
     * A tag holds for every list that follows it, which is the part of §10.4
     * that is easiest to get wrong: the second list here is about the same
     * resource as the first, not about the request target.
     */
    public function testATagHoldsForEveryListThatFollowsIt(): void
    {
        $lists = IfHeader::parse('</a> (<urn:1>) (<urn:2>)')->lists();

        self::assertCount(2, $lists);
        self::assertSame('/a', self::listAt($lists, 0)->resource());
        self::assertSame('/a', self::listAt($lists, 1)->resource());
    }

    public function testReadsSeveralTaggedGroups(): void
    {
        $lists = IfHeader::parse('</a> (<urn:1>) </b> (<urn:2>) (<urn:3>)')->lists();

        self::assertCount(3, $lists);
        self::assertSame('/a', self::listAt($lists, 0)->resource());
        self::assertSame('/b', self::listAt($lists, 1)->resource());
        self::assertSame('/b', self::listAt($lists, 2)->resource());
    }

    /**
     * The grammar of §10.4 is either all tagged or all untagged, and this is
     * neither. It is read all the same: the meaning is not in doubt, and a
     * `400` for a header the server understands perfectly would cost a client
     * an operation it was entitled to.
     */
    public function testReadsAHeaderThatMixesTaggedAndUntaggedLists(): void
    {
        $lists = IfHeader::parse('(<urn:1>) </a> (<urn:2>)')->lists();

        self::assertCount(2, $lists);
        self::assertNull(self::listAt($lists, 0)->resource());
        self::assertSame('/a', self::listAt($lists, 1)->resource());
    }

    #[DataProvider('spacings')]
    public function testReadsAHeaderWhateverTheSpacing(string $header): void
    {
        $lists = IfHeader::parse($header)->lists();

        self::assertCount(2, $lists);
        self::assertSame('urn:a', self::conditionAt(self::listAt($lists, 0)->conditions(), 0)->stateToken());
        self::assertSame('urn:b', self::conditionAt(self::listAt($lists, 1)->conditions(), 0)->stateToken());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function spacings(): iterable
    {
        yield 'one space between the lists' => ['(<urn:a>) (<urn:b>)'];
        yield 'none at all' => ['(<urn:a>)(<urn:b>)'];
        yield 'several' => ['(<urn:a>)    (<urn:b>)'];
        yield 'tabs' => ["(<urn:a>)\t(<urn:b>)"];
        yield 'space inside the lists' => ['( <urn:a> ) ( <urn:b> )'];
        yield 'space around the whole header' => ['  (<urn:a>) (<urn:b>)  '];
    }

    /**
     * Entity tags are opaque. Whatever stands between the quotes belongs to
     * the tag, brackets and spaces included, and a parser that stopped at the
     * first `>` would cut one in half.
     */
    #[DataProvider('opaqueTags')]
    public function testKeepsWhatIsInsideAnEntityTagIntact(string $header, string $etag): void
    {
        self::assertSame($etag, self::etagOf(self::firstCondition($header))->toString());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function opaqueTags(): iterable
    {
        yield 'a space inside' => ['(["a b"])', '"a b"'];
        yield 'angle brackets inside' => ['(["a<b>c"])', '"a<b>c"'];
        yield 'a parenthesis inside' => ['(["a(b)c"])', '"a(b)c"'];
        yield 'the word Not inside' => ['(["Not"])', '"Not"'];
    }

    public function testReadsAStateTokenHoldingBrackets(): void
    {
        self::assertSame('urn:x:[1]', self::firstCondition('(<urn:x:[1]>)')->stateToken());
    }

    public function testReadsAHeaderOfManyLists(): void
    {
        self::assertCount(5, IfHeader::parse('(<urn:1>)(<urn:2>)(<urn:3>)(<urn:4>)(<urn:5>)')->lists());
    }

    /**
     * The shape a `PUT` to a locked resource actually arrives in.
     */
    public function testReadsTheHeaderALockedWriteSends(): void
    {
        $header = '<http://dav.example/file.txt> '
            . '(<urn:uuid:181d4fae-7d8c-11d0-a765-00a0c91e6bf2> ["etag-of-the-file"])';

        $list = self::firstList($header);

        self::assertSame('http://dav.example/file.txt', $list->resource());
        self::assertCount(2, $list->conditions());
    }

    /**
     * The reason is checked along with the refusal. Every one of these ends in
     * the same `400`, so a test that only asked for the exception could not
     * tell a header refused for the right reason from one refused by accident
     * three tokens further on.
     */
    #[DataProvider('unreadableHeaders')]
    public function testRefusesAHeaderItCannotRead(string $header, string $because): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($because, '/') . '/');

        IfHeader::parse($header);
    }

    /**
     * A header that cannot be read cannot be honoured, and honouring it is the
     * whole point: it guards a write. Anything unreadable is a `400`, never a
     * condition quietly dropped.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function unreadableHeaders(): iterable
    {
        yield 'nothing at all' => ['', 'names no condition at all'];
        yield 'only space' => ['   ', 'names no condition at all'];
        yield 'a list with no condition' => ['()', 'holds no condition'];
        yield 'a list that is never closed' => ['(<urn:a>', 'is never closed'];
        yield 'a state token that is never closed' => ['(<urn:a)', 'never closed by a ">"'];
        yield 'an entity tag that is never closed' => ['(["abc")', 'never closed by a "]"'];
        yield 'a negation of nothing' => ['(Not)', 'where a condition was expected'];
        yield 'a negation the header ends after' => ['(Not', 'ends where a condition was expected'];
        yield 'a resource tag with no list' => ['</a>', 'followed by no list'];
        yield 'a resource tag so long that it swallows the list' => ['</a (<urn:b>)', 'followed by no list'];
        yield 'a second resource tag with no list' => ['</a> (<urn:1>) </b>', 'followed by no list'];
        yield 'an empty state token' => ['(<>)', 'holds nothing'];
        yield 'an empty entity tag' => ['([])', 'holds nothing'];
        yield 'an entity tag without quotes' => ['([abc])', 'is no entity tag'];
        yield 'a condition that is neither' => ['(abc)', 'where a condition was expected'];
        yield 'plain rubbish' => ['garbage', 'where a list was expected'];
        yield 'rubbish after a good list' => ['(<urn:a>) garbage', 'where a list was expected'];
        yield 'a closing parenthesis on its own' => [')', 'where a list was expected'];
        yield 'nested lists, which the grammar has no room for' => ['((<urn:a>))', 'where a condition was expected'];
    }

    private static function firstList(string $header): IfList
    {
        return self::listAt(IfHeader::parse($header)->lists(), 0);
    }

    private static function firstCondition(string $header): IfCondition
    {
        return self::conditionAt(self::firstList($header)->conditions(), 0);
    }

    /**
     * @param list<IfList> $lists
     */
    private static function listAt(array $lists, int $index): IfList
    {
        $list = $lists[$index] ?? null;

        if ($list === null) {
            self::fail(sprintf('The header holds no list at %d.', $index));
        }

        return $list;
    }

    /**
     * @param list<IfCondition> $conditions
     */
    private static function conditionAt(array $conditions, int $index): IfCondition
    {
        $condition = $conditions[$index] ?? null;

        if ($condition === null) {
            self::fail(sprintf('The list holds no condition at %d.', $index));
        }

        return $condition;
    }

    private static function etagOf(IfCondition $condition): ETag
    {
        $etag = $condition->etag();

        if ($etag === null) {
            self::fail('The condition holds no entity tag.');
        }

        return $etag;
    }
}
