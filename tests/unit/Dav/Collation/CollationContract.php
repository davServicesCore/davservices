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
use PHPUnit\Framework\TestCase;

/**
 * What every collation has to do, whatever it does to the strings first.
 *
 * Written once and run against each of them, because RFC 4790 §4.2 makes the
 * operations the same question for all: "In general, collation operations
 * should behave as their names suggest. While a collation may be new, the
 * operations are not."
 *
 * ## The promises, and where they come from
 *
 * **Substring is defined in terms of equality**, not as a byte search. §4.2.3:
 * "The substring matching operation determines if the first string is a
 * substring of the second string, i.e., if one or more substrings of the second
 * string is equal to the first, **as defined by the collation's equality
 * operation**." That matters as soon as a collation changes the length of what
 * it prepares.
 *
 * **Two edge cases are stated outright** in the same section, and both are easy
 * to get wrong: "A string is a substring of itself. The empty string is a
 * substring of all strings."
 *
 * **Prefix and suffix are special cases of substring**, so nothing may match as
 * a prefix that does not match as a substring.
 *
 * **And a collation is deterministic** (§4.2): "given a collation with a
 * specific identifier, and any two fixed input strings, the result MUST be the
 * same for the same operation". The identifier is therefore a promise about
 * behaviour, not a label — which is why this library implements one code path
 * per collation rather than a fast one and a slow one that may disagree.
 */
abstract class CollationContract extends TestCase
{
    abstract protected function collation(): Collation;

    /**
     * The identifier this collation is registered under, as a client names it.
     */
    abstract protected function identifier(): string;

    public function testSaysWhatItIsCalled(): void
    {
        self::assertSame($this->identifier(), $this->collation()->identifier());
    }

    public function testAStringEqualsItself(): void
    {
        self::assertTrue($this->collation()->equals('Simon Perreault', 'Simon Perreault'));
    }

    public function testTwoDifferentStringsAreNotEqual(): void
    {
        self::assertFalse($this->collation()->equals('Simon', 'Tim'));
    }

    public function testTheEmptyStringEqualsTheEmptyString(): void
    {
        self::assertTrue($this->collation()->equals('', ''));
    }

    public function testTheEmptyStringDoesNotEqualSomething(): void
    {
        $collation = $this->collation();

        self::assertFalse($collation->equals('', 'Simon'));
        self::assertFalse($collation->equals('Simon', ''));
    }

    /**
     * §4.2.3: "A string is a substring of itself."
     */
    public function testAStringIsASubstringOfItself(): void
    {
        self::assertTrue($this->collation()->contains('Simon', 'Simon'));
    }

    /**
     * §4.2.3: "The empty string is a substring of all strings."
     */
    public function testTheEmptyStringIsASubstringOfEverything(): void
    {
        $collation = $this->collation();

        self::assertTrue($collation->contains('', 'Simon'));
        self::assertTrue($collation->contains('', ''));
    }

    public function testNothingIsASubstringOfTheEmptyStringButTheEmptyString(): void
    {
        self::assertFalse($this->collation()->contains('Simon', ''));
    }

    public function testFindsAStringInTheMiddle(): void
    {
        self::assertTrue($this->collation()->contains('mon Per', 'Simon Perreault'));
    }

    public function testDoesNotFindWhatIsNotThere(): void
    {
        self::assertFalse($this->collation()->contains('Howes', 'Simon Perreault'));
    }

    public function testFindsAPrefix(): void
    {
        $collation = $this->collation();

        self::assertTrue($collation->startsWith('Simon', 'Simon Perreault'));
        self::assertFalse($collation->startsWith('Perreault', 'Simon Perreault'));
    }

    public function testFindsASuffix(): void
    {
        $collation = $this->collation();

        self::assertTrue($collation->endsWith('Perreault', 'Simon Perreault'));
        self::assertFalse($collation->endsWith('Simon', 'Simon Perreault'));
    }

    /**
     * **Every string is its own prefix and its own suffix**, which falls out of
     * §4.2.3's "A string is a substring of itself" once prefix and suffix are
     * special cases of substring.
     */
    public function testAStringIsItsOwnPrefixAndSuffix(): void
    {
        $collation = $this->collation();

        self::assertTrue($collation->startsWith('Simon', 'Simon'));
        self::assertTrue($collation->endsWith('Simon', 'Simon'));
    }

    /**
     * And the empty string sits at both ends of everything, for the same
     * reason.
     */
    public function testTheEmptyStringIsAPrefixAndASuffixOfEverything(): void
    {
        $collation = $this->collation();

        self::assertTrue($collation->startsWith('', 'Simon'));
        self::assertTrue($collation->endsWith('', 'Simon'));
        self::assertTrue($collation->startsWith('', ''));
        self::assertTrue($collation->endsWith('', ''));
    }

    /**
     * **Whatever matches as a prefix or a suffix matches as a substring.**
     * §4.2.3 makes both special cases of substring matching, so a collation
     * that answered otherwise would be answering two different questions
     * under one name.
     */
    public function testWhatMatchesAtAnEndMatchesInTheMiddleToo(): void
    {
        $collation = $this->collation();

        foreach (['Simon', 'Perreault', '', 'Simon Perreault'] as $part) {
            if ($collation->startsWith($part, 'Simon Perreault')) {
                self::assertTrue($collation->contains($part, 'Simon Perreault'), $part);
            }

            if ($collation->endsWith($part, 'Simon Perreault')) {
                self::assertTrue($collation->contains($part, 'Simon Perreault'), $part);
            }
        }
    }

    /**
     * **The same question twice gives the same answer** (§4.2: "Collations must
     * be deterministic").
     */
    public function testTheSameQuestionTwiceGivesTheSameAnswer(): void
    {
        $collation = $this->collation();

        self::assertSame(
            $collation->contains('mon', 'Simon Perreault'),
            $collation->contains('mon', 'Simon Perreault'),
        );
    }
}
