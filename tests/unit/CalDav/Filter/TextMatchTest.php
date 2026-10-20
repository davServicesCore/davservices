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

namespace DavServices\Tests\Unit\CalDav\Filter;

use DavServices\CalDav\Filter\TextMatch;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use DavServices\Xml\Element;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A substring match on one value out of a calendar object (RFC 4791 §9.7.5).
 *
 *     <!ELEMENT text-match (#PCDATA)>
 *     <!ATTLIST text-match collation        CDATA "i;ascii-casemap"
 *                          negate-condition (yes | no) "no">
 *
 * Two attributes and a string, and §7.5 settles what the comparison is: "The
 * comparisons used in CalDAV are all 'substring' matches, as per [RFC4790],
 * Section 4.2." There is no `match-type` here — that is RFC 6352's addition
 * for cards, and asking for one would be asking for something CalDAV does not
 * define.
 */
#[CoversClass(TextMatch::class)]
final class TextMatchTest extends TestCase
{
    /**
     * **A substring, and nothing narrower.** §7.5 again: all of CalDAV's
     * comparisons are substring matches, so a value that merely contains what
     * the client wrote is a match.
     */
    public function testItMatchesASubstring(): void
    {
        $match = $this->match('Meeting');

        self::assertTrue($match->holds('A Meeting of Minds'));
        self::assertTrue($match->holds('Meeting'));
        self::assertFalse($match->holds('A gathering'));
    }

    /**
     * **The default collation is `i;ascii-casemap`**, which §9.7.5's own
     * ATTLIST writes as the attribute's default and §7.5 says twice: "In the
     * absence of this attribute, the server MUST use the 'i;ascii-casemap'
     * collation", and "In the absence of a collation explicitly specified by
     * the client, or if the client specifies the 'default' collation
     * identifier […] the server MUST default to using 'i;ascii-casemap' as the
     * collation."
     *
     * **That is not the default for cards**, where RFC 6352 §8.3 names
     * `i;unicode-casemap` — which is why the defaulting happens here rather
     * than in the shared registry.
     *
     * @param string|null $written What the attribute says, or null for no
     *                             attribute at all
     */
    #[DataProvider('waysOfSayingNothingInParticular')]
    public function testTheDefaultCollationIsAsciiCasemap(?string $written): void
    {
        // RFC 4790 §9.2 describes `i;ascii-casemap` as folding "only ASCII
        // letters": it finds a word whatever its case while the letters are
        // ASCII, and stops folding where they are not.
        self::assertTrue($this->match('MEETING', $written)->holds('A meeting of minds'));
        self::assertFalse($this->match('ÄPFEL', $written)->holds('äpfel und birnen'));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function waysOfSayingNothingInParticular(): iterable
    {
        yield 'no attribute at all' => [null];

        // RFC 4790 §3.1: "There is a special identifier called 'default'. For
        // protocols that have a default collation, 'default' refers to that
        // collation."
        yield 'the special identifier' => ['default'];

        yield 'the name itself' => ['i;ascii-casemap'];
    }

    /**
     * **A collation the client names is the one used.** §7.5 makes two
     * REQUIRED — "CalDAV servers are REQUIRED to support the
     * 'i;ascii-casemap' and 'i;octet' collations" — and lets a server add
     * more, which this one does.
     *
     * @param string $collation What the client asked for
     * @param bool $holds Whether the word is found in "Bahnhofstraße 1"
     */
    #[DataProvider('collationsAndTheirAnswers')]
    public function testACollationTheClientNamesIsUsed(string $collation, string $lookedFor, string $lookedIn, bool $holds): void
    {
        self::assertSame($holds, $this->match($lookedFor, $collation)->holds($lookedIn));
    }

    /**
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function collationsAndTheirAnswers(): iterable
    {
        // RFC 4790 §9.3: `i;octet` compares "octet by octet", so not even the
        // case of an ASCII letter folds.
        yield 'i;octet folds nothing' => ['i;octet', 'MEETING', 'A meeting of minds', false];

        yield 'i;octet finds what is written' => ['i;octet', 'meeting', 'A meeting of minds', true];

        // RFC 5051 folds beyond ASCII, which is the point of having it — and
        // the one thing that tells it from `i;ascii-casemap`.
        yield 'i;unicode-casemap folds beyond ASCII' => [
            'i;unicode-casemap',
            'ÄPFEL',
            'äpfel und birnen',
            true,
        ];

        // **Measured rather than assumed**: RFC 5051 §2 works from "the
        // titlecase property" of UnicodeData, which gives U+00DF none — so
        // "ß" stays itself and is not folded to "ss" by any of the three.
        yield 'and not even it makes "ß" two letters' => [
            'i;unicode-casemap',
            'STRASSE',
            'Bahnhofstraße 1',
            false,
        ];
    }

    /**
     * **A collation this server has not got is a precondition failure**, not
     * an answer. §7.5: "If the client chooses a collation not supported by the
     * server, the server MUST respond with a CALDAV:supported-collation
     * precondition error response."
     *
     * It is refused while the body is being read, before any object has been
     * fetched, because that is what makes a precondition a precondition.
     */
    public function testACollationThisServerHasNotGotIsRefused(): void
    {
        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage('supported-collation');

        $this->match('Meeting', 'i;klingon');
    }

    /**
     * **A wildcard is not an identifier.** §7.5: "Wildcards (as defined in
     * [RFC4790], Section 3.2) MUST NOT be used in the collation identifier."
     * One with a wildcard in it is simply not among the three this server has,
     * so it is refused by the same sentence as any other unknown one.
     */
    public function testAWildcardIsNoIdentifier(): void
    {
        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage('supported-collation');

        $this->match('Meeting', 'i;ascii-*');
    }

    /**
     * **`negate-condition` turns the answer over**, which §9.7.5 explains by
     * example: "this can be used to match components with a STATUS property
     * not set to CANCELLED".
     */
    public function testNegateConditionTurnsTheAnswerOver(): void
    {
        $match = $this->match('CANCELLED', null, 'yes');

        self::assertFalse($match->holds('CANCELLED'));
        self::assertTrue($match->holds('CONFIRMED'));
    }

    /**
     * And `no` is the default, which the ATTLIST writes out.
     */
    public function testNotNegatingIsTheDefault(): void
    {
        self::assertTrue($this->match('CANCELLED', null, 'no')->holds('CANCELLED'));
        self::assertTrue($this->match('CANCELLED')->holds('CANCELLED'));
    }

    /**
     * **And nothing else is admitted.** The ATTLIST is
     * `negate-condition (yes | no) "no"` — an enumeration, so a third word is
     * a request this server cannot read rather than one it guesses at.
     */
    public function testAnythingButYesOrNoIsRefused(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('negate-condition');

        $this->match('CANCELLED', null, 'perhaps');
    }

    /**
     * An element with no text looks for nothing, and every value contains
     * nothing — which is what a substring match of the empty string means.
     * The DTD admits it (`(#PCDATA)` may be empty), so it is answered rather
     * than refused.
     */
    public function testLookingForNothingFindsEverything(): void
    {
        self::assertTrue($this->match('')->holds('Anything at all'));
    }

    private function match(string $lookedFor, ?string $collation = null, ?string $negate = null): TextMatch
    {
        $attributes = [];

        if ($collation !== null) {
            $attributes['collation'] = $collation;
        }

        if ($negate !== null) {
            $attributes['negate-condition'] = $negate;
        }

        $element = new Element(TextMatch::NAME, $attributes);

        $element->appendText($lookedFor);

        return TextMatch::of($element);
    }
}
