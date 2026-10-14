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

use DavServices\CardDav\Filter\Filter;
use DavServices\Exception\BadRequest;
use DavServices\VObject\Component;
use DavServices\VObject\Reader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `CARDDAV:filter`, from RFC 6352 §10.5.
 *
 *     <!ELEMENT filter (prop-filter*)>
 *     <!ATTLIST filter test (anyof | allof) "anyof">
 *
 * "The 'test' attribute specifies whether any (logical OR) or all (logical
 * AND) of the prop-filter tests need to match in order for the overall filter
 * to match."
 */
#[CoversClass(Filter::class)]
final class FilterTest extends TestCase
{
    use ReadsFilterElements;

    private const CARDDAV = 'xmlns="urn:ietf:params:xml:ns:carddav"';

    /** Two conditions, of which the card below satisfies the first alone. */
    private const TWO = '<prop-filter name="FN"><text-match>perreault</text-match></prop-filter>'
        . '<prop-filter name="NICKNAME"/>';

    public function testOneConditionAnswersForTheWholeFilter(): void
    {
        $filter = $this->filterOf('<prop-filter name="FN"><text-match>perreault</text-match></prop-filter>');

        self::assertTrue($filter->holds($this->card('FN:Simon Perreault')));
        self::assertFalse($filter->holds($this->card('FN:Simon Howes')));
    }

    /**
     * **Without the `test` attribute one condition is enough**, the same
     * default as inside a `prop-filter` and the same trap: read as a
     * conjunction it would answer a client's search with a fraction of the
     * cards it asked for.
     */
    public function testWithoutATestAttributeOneConditionIsEnough(): void
    {
        $filter = $this->filterOf(self::TWO);

        self::assertTrue($filter->holds($this->card('FN:Simon Perreault')));
    }

    public function testAllOfWantsEveryCondition(): void
    {
        $filter = $this->filterOf(self::TWO, 'test="allof"');

        self::assertFalse($filter->holds($this->card('FN:Simon Perreault')));
        self::assertTrue($filter->holds($this->card('FN:Simon Perreault', 'NICKNAME:Si')));
    }

    /**
     * **A filter with no conditions at all means what the two tests mean over
     * nothing**, and neither answer is a choice this library makes. §10.5
     * reads the attribute as a logical OR or a logical AND over "the
     * prop-filter tests": an OR of nothing is false and an AND of nothing is
     * true, so an empty filter returns no card and an empty `allof` filter
     * returns every one.
     *
     * The DTD admits both — `(prop-filter*)` — so neither is a request to
     * refuse, and reading an empty filter as "no restriction" would be reading
     * `anyof` as something other than what the same section says it is.
     */
    public function testAFilterWithNoConditionsFollowsItsOwnTest(): void
    {
        $card = $this->card('FN:Simon Perreault');

        self::assertFalse($this->filterOf()->holds($card));
        self::assertTrue($this->filterOf('', 'test="allof"')->holds($card));
    }

    public function testRefusesAChildTheMemoDoesNotName(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('param-filter');

        $this->filterOf('<param-filter name="TYPE"/>');
    }

    public function testRefusesATestTheDtdDoesNotAdmit(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('noneof');

        $this->filterOf('', 'test="noneof"');
    }

    private function filterOf(string $children = '', string $test = ''): Filter
    {
        return Filter::of(self::element(sprintf(
            '<filter %s %s>%s</filter>',
            self::CARDDAV,
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
