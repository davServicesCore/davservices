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

use DavServices\CardDav\Filter\ParamFilter;
use DavServices\Exception\BadRequest;
use DavServices\VObject\Parameter;
use DavServices\VObject\Property;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `CARDDAV:param-filter`, from RFC 6352 §10.5.2.
 *
 *     <!ELEMENT param-filter (is-not-defined | text-match)?>
 *     <!ATTLIST param-filter name CDATA #REQUIRED>
 *
 * "A vCard property is said to match a CARDDAV:param-filter if: A parameter of
 * the type specified by the 'name' attribute exists, and the
 * CARDDAV:param-filter is empty, or it matches the CARDDAV:text-match
 * conditions if specified. or: A parameter of the type specified by the 'name'
 * attribute does not exist, and the CARDDAV:is-not-defined element is
 * specified."
 *
 * So the element asks about one property at a time rather than about the card:
 * it sits inside a `prop-filter`, and that is what picks the properties.
 */
#[CoversClass(ParamFilter::class)]
final class ParamFilterTest extends TestCase
{
    use ReadsFilterElements;

    private const CARDDAV = 'xmlns="urn:ietf:params:xml:ns:carddav"';

    /**
     * The DTD marks `name` `#REQUIRED`, and there is nothing to fall back to:
     * a filter that does not say which parameter it is about is not a filter
     * with a default, it is an unfinished request.
     */
    public function testRefusesAFilterWithoutAName(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('name');

        ParamFilter::of(self::element(sprintf('<param-filter %s/>', self::CARDDAV)));
    }

    /**
     * **An empty `param-filter` asks whether the parameter is there at all**,
     * and nothing more.
     */
    public function testAnEmptyFilterAsksOnlyWhetherTheParameterExists(): void
    {
        $filter = $this->filterOn('TYPE');

        self::assertTrue($filter->holds($this->tel(new Parameter('TYPE', ['work']))));
        self::assertFalse($filter->holds($this->tel(new Parameter('PREF', ['1']))));
    }

    /**
     * RFC 6350 §3.3 makes parameter names case-insensitive, so a client
     * writing `type` means the `TYPE` that is in the card.
     */
    public function testTheNameIsMatchedWhateverItsCase(): void
    {
        self::assertTrue($this->filterOn('type')->holds($this->tel(new Parameter('TYPE', ['work']))));
    }

    /**
     * §10.5.3: `is-not-defined` "specifies that a match occurs if the
     * enclosing vCard property or parameter value […] does not exist".
     */
    public function testIsNotDefinedTurnsTheQuestionRound(): void
    {
        $filter = $this->filterOn('TYPE', '<is-not-defined/>');

        self::assertTrue($filter->holds($this->tel(new Parameter('PREF', ['1']))));
        self::assertFalse($filter->holds($this->tel(new Parameter('TYPE', ['work']))));
    }

    public function testATextMatchIsPutToTheParameterValue(): void
    {
        $filter = $this->filterOn('TYPE', '<text-match>work</text-match>');

        self::assertTrue($filter->holds($this->tel(new Parameter('TYPE', ['work']))));
        self::assertFalse($filter->holds($this->tel(new Parameter('TYPE', ['home']))));
    }

    /**
     * **Every value is asked, not only the first.** RFC 6350 §5 lets a
     * parameter carry a list — `TYPE=work,voice` is one parameter with two
     * values — and a client searching for `voice` means the card that has it.
     */
    public function testEveryValueOfTheParameterIsAsked(): void
    {
        $filter = $this->filterOn('TYPE', '<text-match match-type="equals">voice</text-match>');

        self::assertTrue($filter->holds($this->tel(new Parameter('TYPE', ['work', 'voice']))));
    }

    /**
     * **And they are asked one at a time, not joined back together.** A search
     * for `k,v` would find `TYPE=work,voice` if the values were put back into
     * one string with their commas, and the comma was never part of a value.
     */
    public function testTheValuesAreNotJoinedBackIntoOneString(): void
    {
        $filter = $this->filterOn('TYPE', '<text-match>k,v</text-match>');

        self::assertFalse($filter->holds($this->tel(new Parameter('TYPE', ['work', 'voice']))));
        self::assertTrue($filter->holds($this->tel(new Parameter('TYPE', ['work,voice']))));
    }

    /**
     * **A parameter written with nothing after the equals sign has one empty
     * value**, and an empty search finds it: RFC 6350 §3.3 spells the value
     * `*SAFE-CHAR`, so none at all is a value, and RFC 4790 §4.2.3 makes the
     * empty string a substring of every string.
     */
    public function testAnEmptyValueIsStillAValue(): void
    {
        $filter = $this->filterOn('FOO', '<text-match/>');

        self::assertTrue($filter->holds($this->tel(new Parameter('FOO', ['']))));
    }

    /**
     * **A parameter written without any equals sign has no value at all**, and
     * then there is nothing for a text match to hold on — not even an empty
     * one. RFC 6350's ABNF does not admit that form; it reaches this library
     * because vCard 2.1 wrote its type parameters that way, and the reader
     * keeps what it was given rather than inventing a value for it.
     */
    public function testAParameterWithoutAnyValueMatchesNoText(): void
    {
        $property = $this->tel(new Parameter('FOO'));

        self::assertFalse($this->filterOn('FOO', '<text-match/>')->holds($property));
        self::assertTrue($this->filterOn('FOO')->holds($property));
        self::assertFalse($this->filterOn('FOO', '<is-not-defined/>')->holds($property));
    }

    /**
     * **A name written twice is still one question.** The reader folds
     * `TYPE=work;TYPE=voice` into a single parameter carrying two values, but
     * `Property` itself admits two parameters of one name, so a filter that
     * asked only the first would answer differently depending on how the
     * property reached it.
     */
    public function testANameWrittenTwiceIsAskedThroughout(): void
    {
        $property = $this->tel(new Parameter('TYPE', ['work']), new Parameter('TYPE', ['voice']));
        $filter = $this->filterOn('TYPE', '<text-match match-type="equals">voice</text-match>');

        self::assertTrue($filter->holds($property));
    }

    /**
     * The DTD admits one child or none — `(is-not-defined | text-match)?`. A
     * filter carrying both asks two contradictory questions, and this server
     * says so rather than picking the one it likes.
     */
    public function testRefusesBothChildrenAtOnce(): void
    {
        $this->expectException(BadRequest::class);

        $this->filterOn('TYPE', '<is-not-defined/><text-match>work</text-match>');
    }

    /**
     * An element the memo does not put there is not something to skip over: a
     * client that sent it meant something by it, and reading the filter
     * without it would answer a question nobody asked.
     */
    public function testRefusesAChildTheMemoDoesNotName(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('prop-filter');

        $this->filterOn('TYPE', '<prop-filter name="TEL"/>');
    }

    private function filterOn(string $name, string $children = ''): ParamFilter
    {
        return ParamFilter::of(self::element(sprintf(
            '<param-filter %s name="%s">%s</param-filter>',
            self::CARDDAV,
            $name,
            $children,
        )));
    }

    private function tel(Parameter ...$parameters): Property
    {
        return new Property('TEL', '+1 418 656 9254', array_values($parameters));
    }
}
