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

use DavServices\CardDav\Filter\TextMatch;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `CARDDAV:text-match`, from RFC 6352 §10.5.4.
 *
 *     <!ELEMENT text-match (#PCDATA)>
 *     <!ATTLIST text-match
 *        collation        CDATA "i;unicode-casemap"
 *        negate-condition (yes | no) "no"
 *        match-type (equals|contains|starts-with|ends-with) "contains">
 *
 * Three attributes, three defaults, and a client that sends none of them is
 * the common case — so the defaults are what these tests are mostly about.
 */
#[CoversClass(TextMatch::class)]
final class TextMatchTest extends TestCase
{
    use ReadsFilterElements;

    private const NAMESPACE = 'xmlns="urn:ietf:params:xml:ns:carddav"';

    private const SUPPORTED_COLLATION = '{urn:ietf:params:xml:ns:carddav}supported-collation';

    /**
     * **Without the attribute it is `i;unicode-casemap`**, which §10.5.4 makes
     * a MUST: "In the absence of this attribute, the server MUST use the
     * 'i;unicode-casemap' collation." That one folds case, so the second half
     * of this test is what proves the default was used rather than `i;octet`.
     */
    public function testTheDefaultCollationFoldsCase(): void
    {
        self::assertTrue($this->matchOf('simon')->holds('SIMON PERREAULT'));
        self::assertFalse($this->matchOf('simon', 'collation="i;octet"')->holds('SIMON PERREAULT'));
    }

    /**
     * RFC 4790 §3.1 keeps "default" for "the collation the server would use
     * anyway", so a client may name it instead of leaving the attribute out.
     */
    public function testTheReservedIdentifierAsksForThatSameDefault(): void
    {
        self::assertTrue($this->matchOf('simon', 'collation="default"')->holds('SIMON'));
    }

    /**
     * **Without the attribute it is `contains`**, so a needle in the middle
     * matches.
     */
    public function testTheDefaultMatchTypeLooksAnywhere(): void
    {
        self::assertTrue($this->matchOf('mon Per')->holds('Simon Perreault'));
    }

    public function testReadsTheMatchType(): void
    {
        $equals = $this->matchOf('Simon', 'match-type="equals"');

        self::assertTrue($equals->holds('Simon'));
        self::assertFalse($equals->holds('Simon Perreault'));
    }

    /**
     * §10.5.4: the attribute "is used to […] return a match if the text does
     * not match, if the attribute value is set to 'yes'. For example, this can
     * be used to match components with a CATEGORIES property not set to
     * PERSON."
     */
    public function testNegationTurnsTheAnswerRound(): void
    {
        $notPerson = $this->matchOf('PERSON', 'negate-condition="yes"');

        self::assertTrue($notPerson->holds('COLLEAGUE'));
        self::assertFalse($notPerson->holds('PERSON'));
    }

    public function testSayingNoIsTheSameAsSayingNothing(): void
    {
        self::assertTrue($this->matchOf('PERSON', 'negate-condition="no"')->holds('PERSON'));
    }

    /**
     * The DTD admits `yes` and `no`. A third word is not a value with an
     * obvious reading — taking it for "no" would answer a client that meant
     * "yes" with exactly the cards it was trying to leave out.
     */
    public function testRefusesAThirdAnswerToANegation(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('maybe');

        $this->matchOf('PERSON', 'negate-condition="maybe"');
    }

    /**
     * **An unknown collation is refused while the request is being read**,
     * before a single card has been fetched: §8.6 makes
     * `CARDDAV:supported-collation` a precondition, and a precondition is a
     * reason not to run the report rather than a note about its result.
     *
     * **And it is refused by name.** §8.3: "If the client chooses a collation
     * not supported by the server, the server MUST respond with a
     * CARDDAV:supported-collation precondition error response." A bare failure
     * would leave a client guessing which of the things it sent was the
     * trouble; the precondition sends it to
     * `CARDDAV:supported-collation-set`, which says what it may ask for
     * instead.
     */
    public function testRefusesACollationItDoesNotHaveByName(): void
    {
        try {
            $this->matchOf('42', 'collation="i;ascii-numeric"');
        } catch (Forbidden $refusal) {
            self::assertSame(self::SUPPORTED_COLLATION, $refusal->errorElement());
            self::assertSame(403, $refusal->status());
            self::assertStringContainsString('i;ascii-numeric', $refusal->getMessage());

            return;
        }

        self::fail('A collation this server has not got was accepted.');
    }

    /**
     * **An empty `text-match` matches every value there is**, which is not a
     * special case but what the two memos say together: the element is
     * `#PCDATA`, so empty text is a legal search string, and RFC 4790 §4.2.3
     * states that "the empty string is a substring of all strings".
     */
    public function testAnEmptySearchMatchesAnything(): void
    {
        self::assertTrue($this->matchOf('')->holds('Simon Perreault'));
        self::assertTrue($this->matchOf('')->holds(''));
    }

    /**
     * Whitespace in the element is part of the search string, not decoration:
     * `#PCDATA` is the value, and a client searching for a name with a space
     * in it has no other way to say so.
     */
    public function testTheTextIsTakenAsItStands(): void
    {
        self::assertTrue($this->matchOf('Simon Perreault')->holds('Simon Perreault'));
        self::assertFalse($this->matchOf(' Simon', 'match-type="starts-with"')->holds('Simon'));
    }

    private function matchOf(string $text, string ...$attributes): TextMatch
    {
        return TextMatch::of(self::element(sprintf(
            '<text-match %s %s>%s</text-match>',
            self::NAMESPACE,
            implode(' ', $attributes),
            $text,
        )));
    }
}
