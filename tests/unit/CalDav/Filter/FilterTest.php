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

use DateTimeZone;
use DavServices\CalDav\CalendarObject;
use DavServices\CalDav\Filter\CompFilter;
use DavServices\CalDav\Filter\Filter;
use DavServices\CalDav\Moments;
use DavServices\Exception\BadRequest;
use DavServices\VObject\Component;
use DavServices\Xml\Element;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The whole of a search filter (RFC 4791 §9.7).
 *
 *     <!ELEMENT filter (comp-filter)>
 *
 * One child, and the DTD says so without a `?` or a `*`: a filter is the
 * question, and a question with none or two of them is not one.
 */
#[CoversClass(Filter::class)]
final class FilterTest extends TestCase
{
    private const MEETING = "BEGIN:VEVENT\r\nUID:1@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
        . "DTSTART:20260105T120000Z\r\nDTEND:20260105T130000Z\r\nSUMMARY:A Meeting\r\nEND:VEVENT\r\n";

    /**
     * **It hands the question to the one filter inside it**, which §9.7.1
     * scopes to the calendar object.
     */
    public function testItAsksTheOneFilterInsideIt(): void
    {
        self::assertTrue($this->holds($this->filter($this->comp('VCALENDAR'))));
        self::assertFalse($this->holds($this->filter($this->comp('VTODO'))));
    }

    /**
     * And what that filter says of the object's components is the answer.
     */
    public function testWhatTheFilterSaysIsTheAnswer(): void
    {
        $event = $this->comp('VCALENDAR', $this->comp('VEVENT'));
        $todo = $this->comp('VCALENDAR', $this->comp('VTODO'));

        self::assertTrue($this->holds($this->filter($event)));
        self::assertFalse($this->holds($this->filter($todo)));
    }

    /**
     * §9.7's DTD is `(comp-filter)` — one, named, and not optional.
     */
    public function testAFilterWithNoCompFilterIsRefused(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('one CALDAV:comp-filter');

        Filter::of(new Element(Filter::NAME));
    }

    public function testAFilterWithTwoCompFiltersIsRefused(): void
    {
        $element = new Element(Filter::NAME);

        $element->append($this->comp('VCALENDAR'));
        $element->append($this->comp('VCALENDAR'));

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('one CALDAV:comp-filter');

        Filter::of($element);
    }

    /**
     * And a child of another name is refused rather than ignored: a server
     * that quietly dropped a condition would answer a question the client did
     * not ask.
     */
    public function testAChildOfAnotherNameIsRefused(): void
    {
        $element = new Element(Filter::NAME);

        $element->append(new Element('{urn:ietf:params:xml:ns:caldav}prop-filter', ['name' => 'SUMMARY']));

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('prop-filter');

        Filter::of($element);
    }

    private function filter(Element $comp): Filter
    {
        $element = new Element(Filter::NAME);

        $element->append($comp);

        return Filter::of($element);
    }

    private function comp(string $name, Element ...$children): Element
    {
        $element = new Element(CompFilter::NAME, ['name' => $name]);

        foreach ($children as $child) {
            $element->append($child);
        }

        return $element;
    }

    private function holds(Filter $filter): bool
    {
        $object = $this->objectOf(self::MEETING);

        return $filter->holds($object, new Moments($object, new DateTimeZone('UTC')));
    }

    private function objectOf(string $components): Component
    {
        $text = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\n"
            . $components . "END:VCALENDAR\r\n";

        return CalendarObject::in($text) ?? self::fail('The case built something that is no calendar object.');
    }
}
