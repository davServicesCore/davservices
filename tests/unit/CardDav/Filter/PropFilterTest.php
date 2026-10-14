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

use DavServices\CardDav\Filter\PropFilter;
use DavServices\Exception\BadRequest;
use DavServices\VObject\Component;
use DavServices\VObject\Reader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `CARDDAV:prop-filter`, from RFC 6352 §10.5.1.
 *
 *     <!ELEMENT prop-filter (is-not-defined |
 *                            (text-match*, param-filter*))>
 *     <!ATTLIST prop-filter name CDATA #REQUIRED
 *                           test (anyof | allof) "anyof">
 *
 * "An address object is said to match a CARDDAV:prop-filter if: A vCard
 * property of the type specified by the 'name' attribute exists, and the
 * CARDDAV:prop-filter is empty, or it matches any specified CARDDAV:text-match
 * or CARDDAV:param-filter conditions. […] or: A vCard property of the type
 * specified by the 'name' attribute does not exist, and the
 * CARDDAV:is-not-defined element is specified."
 */
#[CoversClass(PropFilter::class)]
final class PropFilterTest extends TestCase
{
    use ReadsFilterElements;

    private const CARDDAV = 'xmlns="urn:ietf:params:xml:ns:carddav"';

    public function testRefusesAFilterWithoutAName(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('name');

        PropFilter::of(self::element(sprintf('<prop-filter %s/>', self::CARDDAV)));
    }

    /**
     * **An empty `prop-filter` asks whether the property is there**, which is
     * the shortest of the memo's two bullets and the one a client uses to find
     * every card that has a photograph at all.
     */
    public function testAnEmptyFilterAsksOnlyWhetherThePropertyExists(): void
    {
        $card = $this->card('FN:Simon Perreault');

        self::assertTrue($this->filterOn('FN')->holds($card));
        self::assertFalse($this->filterOn('NICKNAME')->holds($card));
    }

    public function testTheNameIsMatchedWhateverItsCase(): void
    {
        self::assertTrue($this->filterOn('fn')->holds($this->card('FN:Simon Perreault')));
    }

    /**
     * §10.5.3: a match "occurs if the enclosing vCard property or parameter
     * value […] does not exist".
     */
    public function testIsNotDefinedTurnsTheQuestionRound(): void
    {
        $filter = $this->filterOn('NICKNAME', '<is-not-defined/>');

        self::assertTrue($filter->holds($this->card('FN:Simon Perreault')));
        self::assertFalse($filter->holds($this->card('FN:Simon Perreault', 'NICKNAME:Si')));
    }

    /**
     * **A name without a group prefix matches whatever the prefix is**, and
     * §10.5.1 gives the example itself: "a 'name' set to 'TEL' will match
     * 'TEL', 'X-ABC.TEL', 'X-ABC-1.TEL' vCard properties."
     */
    public function testANameWithoutAPrefixMatchesAnyPrefix(): void
    {
        $filter = $this->filterOn('TEL');

        self::assertTrue($filter->holds($this->card('TEL:+1')));
        self::assertTrue($filter->holds($this->card('X-ABC.TEL:+1')));
        self::assertTrue($filter->holds($this->card('X-ABC-1.TEL:+1')));
    }

    /**
     * **A name with a prefix matches that prefix and no other**, which is the
     * other half of the same paragraph: "A 'name' set to 'X-ABC.TEL' will
     * match an 'X-ABC.TEL' vCard property only, it will not match 'TEL' or
     * 'X-ABC-1.TEL'."
     */
    public function testANameWithAPrefixMatchesThatPrefixOnly(): void
    {
        $filter = $this->filterOn('X-ABC.TEL');

        self::assertTrue($filter->holds($this->card('X-ABC.TEL:+1')));
        self::assertFalse($filter->holds($this->card('TEL:+1')));
        self::assertFalse($filter->holds($this->card('X-ABC-1.TEL:+1')));
    }

    /**
     * **And it sees every property of that prefix, not the first.** The two
     * halves of §10.5.1 are about which properties a name picks out; once
     * picked out, "a vCard property […] exists, and […] it matches" is
     * satisfied by any one of them, prefix or no prefix.
     */
    public function testAPrefixedNameSeesEveryPropertyOfThatPrefix(): void
    {
        $filter = $this->filterOn('X-ABC.TEL', '<text-match>+2</text-match>');

        self::assertTrue($filter->holds($this->card('X-ABC.TEL:+1', 'X-ABC.TEL:+2')));
        self::assertFalse($filter->holds($this->card('X-ABC.TEL:+1', 'X-ABC.TEL:+3')));
    }

    /**
     * RFC 6350's own ABNF settles the spelling, in the comment under the
     * `group` production: "Group and name are case-insensitive."
     */
    public function testThePrefixIsMatchedWhateverItsCase(): void
    {
        self::assertTrue($this->filterOn('x-abc.tel')->holds($this->card('X-ABC.TEL:+1')));
    }

    /**
     * **A name no vCard property could carry matches nothing**, and it needs
     * no rule of its own to do so: RFC 6350 spells a group `1*(ALPHA / DIGIT /
     * "-")` and a name the same way, so neither may be empty and neither may
     * hold a full stop. A prefix that is empty, a name that is, and a second
     * full stop are therefore all names that no property has.
     */
    public function testANameNoPropertyCouldCarryFindsNothing(): void
    {
        $card = $this->card('TEL:+1', 'X-ABC.TEL:+2');

        self::assertFalse($this->filterOn('.TEL')->holds($card));
        self::assertFalse($this->filterOn('X-ABC.')->holds($card));
        self::assertFalse($this->filterOn('X-ABC.TEL.X')->holds($card));
    }

    public function testATextMatchIsPutToThePropertyValue(): void
    {
        $filter = $this->filterOn('FN', '<text-match>perreault</text-match>');

        self::assertTrue($filter->holds($this->card('FN:Simon Perreault')));
        self::assertFalse($filter->holds($this->card('FN:Simon Howes')));
    }

    /**
     * **The value is matched as the card means it, not as it is written.** RFC
     * 6350 §3.4 makes the backslash a way of writing a comma, a semicolon or a
     * line break inside a text value rather than part of the value, so a
     * client searching for a name with a comma in it must not have to know how
     * this server escaped it.
     *
     * Undoing it for every property is safe because of what RFC 6350 says
     * about the other value types — "No additional content value encoding […]
     * is defined for this value type" under `BINARY`, `BOOLEAN`, `FLOAT`,
     * `INTEGER` and `URI` alike — and because none of their grammars admits a
     * backslash at all. There is nothing in a legal non-text value for the
     * undoing to act on.
     */
    public function testTheValueIsMatchedUnescaped(): void
    {
        $card = $this->card('N:Perreault;Simon;;;ing. jr\,M.Sc.');

        self::assertTrue($this->filterOn('N', '<text-match>jr,M.Sc.</text-match>')->holds($card));
        self::assertFalse($this->filterOn('N', '<text-match>jr\\,M.Sc.</text-match>')->holds($card));
    }

    /**
     * **A property may appear as often as it likes**, and the memo's "a vCard
     * property […] exists, and […] it matches" is satisfied by any one of
     * them.
     */
    public function testAnyOneInstanceOfThePropertyMayAnswer(): void
    {
        $filter = $this->filterOn('TEL', '<text-match>+2</text-match>');

        self::assertTrue($filter->holds($this->card('TEL:+1', 'TEL:+2')));
        self::assertFalse($filter->holds($this->card('TEL:+1', 'TEL:+3')));
    }

    /**
     * **Without the `test` attribute one condition is enough.** This is the
     * default that costs a client cards when a server gets it wrong, and it is
     * the opposite of the instinct.
     */
    public function testWithoutATestAttributeOneConditionIsEnough(): void
    {
        $filter = $this->filterOn(
            'FN',
            '<text-match>perreault</text-match><text-match>nobody</text-match>',
        );

        self::assertTrue($filter->holds($this->card('FN:Simon Perreault')));
    }

    public function testAllOfWantsEveryCondition(): void
    {
        $filter = $this->filterOn(
            'FN',
            '<text-match>perreault</text-match><text-match>nobody</text-match>',
            'test="allof"',
        );

        self::assertFalse($filter->holds($this->card('FN:Simon Perreault')));
    }

    /**
     * **And it wants them of one and the same property.** The memo puts the
     * conditions to "a vCard property" — one of them — so a card whose home
     * number is not its work number does not satisfy `allof` by answering half
     * the question twice.
     *
     * The card below holds both answers between its two `TEL` properties and
     * neither in one of them, which is what tells the two readings apart.
     */
    public function testAllOfWantsThemOfOneAndTheSameProperty(): void
    {
        $card = $this->card('TEL;TYPE=home:+1', 'TEL;TYPE=work:+2');
        $conditions = '<text-match match-type="equals">+2</text-match>'
            . '<param-filter name="TYPE"><text-match match-type="equals">home</text-match></param-filter>';

        self::assertFalse($this->filterOn('TEL', $conditions, 'test="allof"')->holds($card));
        self::assertTrue($this->filterOn('TEL', $conditions)->holds($card));
    }

    public function testAParamFilterIsPutToThePropertyItFound(): void
    {
        $filter = $this->filterOn('TEL', '<param-filter name="TYPE"><text-match>work</text-match></param-filter>');

        self::assertTrue($filter->holds($this->card('TEL;TYPE=work:+1')));
        self::assertFalse($filter->holds($this->card('TEL;TYPE=home:+1')));
    }

    /**
     * **The order of the children carries no meaning, so it is not enforced.**
     * The DTD spells `(text-match*, param-filter*)`, which puts the text
     * matches first, but the `test` attribute combines every condition however
     * they arrived and no child is passed over — so a client that wrote them
     * the other way round gets the answer it asked for rather than a refusal
     * that would tell it nothing it could act on.
     */
    public function testTheOrderOfTheConditionsDoesNotMatter(): void
    {
        $filter = $this->filterOn(
            'TEL',
            '<param-filter name="TYPE"><text-match>work</text-match></param-filter><text-match>+1</text-match>',
            'test="allof"',
        );

        self::assertTrue($filter->holds($this->card('TEL;TYPE=work:+1')));
        self::assertFalse($filter->holds($this->card('TEL;TYPE=work:+2')));
    }

    /**
     * `(is-not-defined | (text-match*, param-filter*))` is a choice, not a
     * list: asking that a property be absent and that its value match says two
     * things at once, and only one of them can be meant.
     */
    public function testRefusesIsNotDefinedBesideAnyCondition(): void
    {
        $this->expectException(BadRequest::class);

        $this->filterOn('TEL', '<is-not-defined/><text-match>+1</text-match>');
    }

    public function testRefusesIsNotDefinedTwice(): void
    {
        $this->expectException(BadRequest::class);

        $this->filterOn('TEL', '<is-not-defined/><is-not-defined/>');
    }

    /**
     * **Where two things are wrong at once, the more specific one is said.** A
     * filter with `is-not-defined` beside an element the memo does not name
     * breaks two rules, and naming the stranger tells the client something it
     * can act on — while "this asks nothing else" would send it looking at the
     * element it already knows about.
     */
    public function testTheStrangerIsNamedEvenWhenSomethingElseIsWrongToo(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('no place');

        $this->filterOn('TEL', sprintf('<is-not-defined/><filter %s/>', self::CARDDAV));
    }

    public function testRefusesAChildTheMemoDoesNotName(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('filter');

        $this->filterOn('TEL', sprintf('<filter %s/>', self::CARDDAV));
    }

    private function filterOn(string $name, string $children = '', string $test = ''): PropFilter
    {
        return PropFilter::of(self::element(sprintf(
            '<prop-filter %s name="%s" %s>%s</prop-filter>',
            self::CARDDAV,
            $name,
            $test,
            $children,
        )));
    }

    private function card(string ...$lines): Component
    {
        $text = sprintf("BEGIN:VCARD\r\nVERSION:4.0\r\n%s\r\nEND:VCARD\r\n", implode("\r\n", $lines));
        $objects = iterator_to_array((new Reader($text))->objects(), false);
        $first = $objects[0] ?? null;

        self::assertInstanceOf(Component::class, $first);

        return $first;
    }
}
