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

use DavServices\CalDav\Filter\ParamFilter;
use DavServices\CalDav\Filter\TextMatch;
use DavServices\Exception\BadRequest;
use DavServices\VObject\Property;
use DavServices\VObject\Reader;
use DavServices\Xml\Element;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A condition on one parameter of one property (RFC 4791 §9.7.3).
 *
 *     <!ELEMENT param-filter (is-not-defined | text-match?)>
 *     <!ATTLIST param-filter name CDATA #REQUIRED>
 *
 * §9.7.3 gives two of the three cases its own DTD admits, and the third is
 * written down where the corresponding memo for cards states it — see the
 * class under test.
 */
#[CoversClass(ParamFilter::class)]
final class ParamFilterTest extends TestCase
{
    /**
     * **§9.7.3: "The CALDAV:param-filter XML element is empty and a parameter
     * of the type specified by the 'name' attribute exists on the calendar
     * property being examined".**
     */
    public function testAnEmptyFilterAsksWhetherTheParameterIsThere(): void
    {
        $filter = $this->filter('PARTSTAT');

        self::assertTrue($filter->holds($this->property('ATTENDEE;PARTSTAT=ACCEPTED:mailto:a@example.com')));
        self::assertFalse($filter->holds($this->property('ATTENDEE:mailto:a@example.com')));
    }

    /**
     * **§9.7.3: "contains a CALDAV:is-not-defined XML element and no parameter
     * of the type specified by the 'name' attribute exists".**
     */
    public function testIsNotDefinedAsksTheOtherWayRound(): void
    {
        $filter = $this->filter('PARTSTAT', new Element(ParamFilter::NOT_DEFINED));

        self::assertFalse($filter->holds($this->property('ATTENDEE;PARTSTAT=ACCEPTED:mailto:a@example.com')));
        self::assertTrue($filter->holds($this->property('ATTENDEE:mailto:a@example.com')));
    }

    /**
     * **A `text-match` asks about the parameter's value**, which §9.7.3's own
     * bullets leave out although its DTD admits the element. RFC 6352 §10.5.2
     * states the case for cards in as many words — "A parameter of the type
     * specified by the 'name' attribute exists, and the CARDDAV:param-filter
     * is empty, or it matches the CARDDAV:text-match conditions if specified"
     * — and there is nothing in RFC 4791 that would make CalDAV mean anything
     * else.
     */
    public function testATextMatchAsksAboutTheParametersValue(): void
    {
        $filter = $this->filter('PARTSTAT', $this->match('ACCEPTED'));

        self::assertTrue($filter->holds($this->property('ATTENDEE;PARTSTAT=ACCEPTED:mailto:a@example.com')));
        self::assertFalse($filter->holds($this->property('ATTENDEE;PARTSTAT=DECLINED:mailto:a@example.com')));
    }

    /**
     * And a parameter that is not there matches no text, because the first
     * half of that sentence is "a parameter … exists".
     */
    public function testATextMatchNeedsTheParameterToBeThere(): void
    {
        $filter = $this->filter('PARTSTAT', $this->match('ACCEPTED'));

        self::assertFalse($filter->holds($this->property('ATTENDEE:mailto:a@example.com')));
    }

    /**
     * **A parameter may carry several values**, which RFC 5545 §3.2 allows —
     * `param = param-name "=" param-value *("," param-value)` — and any of
     * them satisfying the match satisfies the filter. The alternative would be
     * to compare the list as one string, which would make a filter depend on
     * the order a writer happened to use.
     */
    public function testAnyOfSeveralValuesSatisfiesTheMatch(): void
    {
        $filter = $this->filter('MEMBER', $this->match('team'));

        self::assertTrue($filter->holds(
            $this->property('ATTENDEE;MEMBER="mailto:board@example.com","mailto:team@example.com":mailto:a@example.com'),
        ));
    }

    /**
     * **The parameter's name is read whatever its case** (RFC 5545 §3.1:
     * "property names, parameter names and enumerated parameter values are
     * case-insensitive"), and {@see \DavServices\VObject\Property} already
     * looks parameters up that way.
     */
    public function testTheNameIsReadWhateverItsCase(): void
    {
        self::assertTrue($this->filter('partstat')->holds(
            $this->property('ATTENDEE;PARTSTAT=ACCEPTED:mailto:a@example.com'),
        ));
    }

    /**
     * §9.7.3 makes the attribute `#REQUIRED`, so a filter without one is a
     * request this server cannot read.
     */
    public function testAFilterWithoutANameIsRefused(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('name');

        ParamFilter::of(new Element(ParamFilter::NAME));
    }

    /**
     * The DTD is a choice of one, so two children are more than it admits.
     */
    public function testAFilterWithTwoChildrenIsRefused(): void
    {
        $element = new Element(ParamFilter::NAME, ['name' => 'PARTSTAT']);

        $element->append(new Element(ParamFilter::NOT_DEFINED));
        $element->append($this->match('ACCEPTED'));

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('one child');

        ParamFilter::of($element);
    }

    /**
     * And a child the DTD does not name is refused rather than ignored: a
     * server that quietly dropped a condition would answer a question the
     * client did not ask.
     */
    public function testAChildTheDtdDoesNotNameIsRefused(): void
    {
        $element = new Element(ParamFilter::NAME, ['name' => 'PARTSTAT']);

        $element->append(new Element('{urn:ietf:params:xml:ns:caldav}time-range', ['start' => '20260101T000000Z']));

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('time-range');

        ParamFilter::of($element);
    }

    private function filter(string $name, ?Element $child = null): ParamFilter
    {
        $element = new Element(ParamFilter::NAME, ['name' => $name]);

        if ($child !== null) {
            $element->append($child);
        }

        return ParamFilter::of($element);
    }

    private function match(string $lookedFor): Element
    {
        $element = new Element(TextMatch::NAME);

        $element->appendText($lookedFor);

        return $element;
    }

    /**
     * One property, read the way a client would have written it.
     */
    private function property(string $line): Property
    {
        $text = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//x//y//EN\r\nBEGIN:VEVENT\r\n"
            . "UID:1@example.com\r\nDTSTAMP:20260101T000000Z\r\n" . $line . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        foreach ((new Reader($text))->objects() as $object) {
            $properties = $object->component('VEVENT')?->properties() ?? [];

            foreach ($properties as $property) {
                if (!in_array(strtoupper($property->name()), ['UID', 'DTSTAMP'], true)) {
                    return $property;
                }
            }
        }

        self::fail('The case built no property.');
    }
}
