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
use DavServices\CalDav\Filter\Filter;
use DavServices\CalDav\Filter\ParamFilter;
use DavServices\CalDav\Filter\PropFilter;
use DavServices\CalDav\Filter\TextMatch;
use DavServices\CalDav\Moments;
use DavServices\CalDav\TimeRange;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use DavServices\VObject\Component;
use DavServices\Xml\Element;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A condition on one property of one component (RFC 4791 §9.7.2).
 *
 *     <!ELEMENT prop-filter (is-not-defined |
 *                            ((time-range | text-match)?,
 *                             param-filter*))>
 *     <!ATTLIST prop-filter name CDATA #REQUIRED>
 */
#[CoversClass(PropFilter::class)]
final class PropFilterTest extends TestCase
{
    /**
     * **§9.7.2: "The CALDAV:prop-filter XML element is empty and a property of
     * the type specified by the 'name' attribute exists in the enclosing
     * calendar component".**
     */
    public function testAnEmptyFilterAsksWhetherThePropertyIsThere(): void
    {
        $filter = $this->filter('SUMMARY');

        self::assertTrue($this->holds($filter, "SUMMARY:A meeting\r\n"));
        self::assertFalse($this->holds($filter, "DESCRIPTION:Something\r\n"));
    }

    /**
     * **§9.7.2: "contains a CALDAV:is-not-defined XML element and no property
     * of the type specified by the 'name' attribute exists".**
     */
    public function testIsNotDefinedAsksTheOtherWayRound(): void
    {
        $filter = $this->filter('SUMMARY', new Element(ParamFilter::NOT_DEFINED));

        self::assertFalse($this->holds($filter, "SUMMARY:A meeting\r\n"));
        self::assertTrue($this->holds($filter, "DESCRIPTION:Something\r\n"));
    }

    /**
     * **§9.7.2: "contains a CALDAV:text-match XML element and the property
     * value matches it".**
     */
    public function testATextMatchAsksAboutThePropertysValue(): void
    {
        $filter = $this->filter('SUMMARY', $this->match('meeting'));

        self::assertTrue($this->holds($filter, "SUMMARY:A Meeting of Minds\r\n"));
        self::assertFalse($this->holds($filter, "SUMMARY:A gathering\r\n"));
    }

    /**
     * **§9.7.2: "contains a CALDAV:time-range XML element and the property
     * value overlaps the specified time range".**
     *
     * §9.9's last table is what "overlaps" means for a property:
     * `(start <= date-time) AND (end > date-time)`.
     */
    public function testATimeRangeAsksWhenThePropertysValueIs(): void
    {
        $filter = $this->filter('DTSTART', $this->range('20260105T120000Z', '20260105T130000Z'));

        self::assertTrue($this->holds($filter, "DTSTART:20260105T123000Z\r\n"));
        self::assertFalse($this->holds($filter, "DTSTART:20260105T140000Z\r\n"));
    }

    /**
     * **And a range on a property that has no date is refused, in §7.8's own
     * example.** The precondition is spelled with this very nesting:
     *
     * > (CALDAV:valid-filter): The CALDAV:filter XML element (see Section 9.7)
     * > specified in the REPORT request MUST be valid. For instance, […] a
     * > CALDAV:filter cannot nest a <C:time-range start="..." end="..."> element
     * > in a <C:prop name="SUMMARY"> element.
     *
     * §9.9 is the reason: it names seven properties its last table is for and
     * says of everything else that "the semantic of CALDAV:time-range is not
     * defined". A server that answered such a filter with "nothing matched"
     * would let a client's typo look like an empty calendar.
     *
     * @param string $property The property the range is nested in
     */
    #[DataProvider('propertiesARangeMeansNothingFor')]
    public function testATimeRangeOnAPropertyWithNoDateIsRefused(string $property): void
    {
        try {
            $this->filter($property, $this->range('20260101T000000Z', '20270101T000000Z'));
        } catch (Forbidden $refusal) {
            self::assertSame(Filter::VALID, $refusal->errorElement());
            self::assertSame(403, $refusal->status());
            self::assertStringContainsString($property, $refusal->getMessage());

            return;
        }

        self::fail('A time range was taken for a property §9.9 defines none for.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function propertiesARangeMeansNothingFor(): iterable
    {
        yield 'SUMMARY, the memo\'s own example' => ['SUMMARY'];
        yield 'DURATION, which is a length and no moment' => ['DURATION'];
        yield 'summary, in the case a client wrote it in' => ['summary'];
    }

    /**
     * And the seven it does name are taken, or the refusal above would have
     * closed the door on the filter §9.9 is written for.
     *
     * @param string $property One of §9.9's last table's seven
     */
    #[DataProvider('propertiesARangeIsAbout')]
    public function testATimeRangeOnADatedPropertyIsTaken(string $property): void
    {
        $this->filter($property, $this->range('20260101T000000Z', '20270101T000000Z'));

        $this->expectNotToPerformAssertions();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function propertiesARangeIsAbout(): iterable
    {
        foreach (['COMPLETED', 'CREATED', 'DTEND', 'DTSTAMP', 'DTSTART', 'DUE', 'LAST-MODIFIED'] as $named) {
            yield $named => [$named];
        }
    }

    /**
     * **A `param-filter` is asked of the property the value matched**, which
     * §9.7.2 says twice: "and all specified CALDAV:param-filter child XML
     * elements also match **the targeted property**".
     *
     * So an event with two attendees, one of whom has accepted, matches a
     * filter asking for the one who accepted — and does **not** match a filter
     * that asks for a name on one line and a parameter on the other.
     */
    public function testAParamFilterIsAskedOfThePropertyThatMatched(): void
    {
        $two = "ATTENDEE;PARTSTAT=ACCEPTED:mailto:anne@example.com\r\n"
            . "ATTENDEE;PARTSTAT=DECLINED:mailto:bert@example.com\r\n";

        $accepted = $this->filter(
            'ATTENDEE',
            $this->match('anne'),
            $this->paramFilter('PARTSTAT', $this->match('ACCEPTED')),
        );
        $crossed = $this->filter(
            'ATTENDEE',
            $this->match('anne'),
            $this->paramFilter('PARTSTAT', $this->match('DECLINED')),
        );

        self::assertTrue($this->holds($accepted, $two));
        self::assertFalse($this->holds($crossed, $two));
    }

    /**
     * **Any one occurrence satisfying the filter satisfies it.** A property may
     * appear as often as it likes — an event has as many `ATTENDEE` properties
     * as it has attendees — and §9.7.2 asks whether "a calendar property" is
     * said to match, not whether every one of them is.
     */
    public function testAnyOneOccurrenceIsEnough(): void
    {
        $filter = $this->filter('ATTENDEE', $this->match('bert'));
        $two = "ATTENDEE:mailto:anne@example.com\r\nATTENDEE:mailto:bert@example.com\r\n";

        self::assertTrue($this->holds($filter, $two));
    }

    /**
     * **`param-filter` children without a value test are asked all the same**,
     * which is the case §9.7.2's bullets leave out while its DTD admits it:
     * `((time-range | text-match)?, param-filter*)` makes the value test
     * optional and the parameter tests independent of it. RFC 6352 §10.5.1
     * states the same case for cards — "A property of the type specified by
     * the 'name' attribute exists, and the CARDDAV:prop-filter is empty, or it
     * matches any CARDDAV:text-match or CARDDAV:param-filter conditions
     * specified."
     */
    public function testParamFiltersAloneAreStillAsked(): void
    {
        $filter = $this->filter('ATTENDEE', null, $this->paramFilter('PARTSTAT', $this->match('ACCEPTED')));

        self::assertTrue($this->holds($filter, "ATTENDEE;PARTSTAT=ACCEPTED:mailto:a@example.com\r\n"));
        self::assertFalse($this->holds($filter, "ATTENDEE;PARTSTAT=DECLINED:mailto:a@example.com\r\n"));
    }

    /**
     * **All of them, not any.** §9.7.2 reads "**all** specified
     * CALDAV:param-filter child XML elements also match".
     */
    public function testEveryParamFilterHasToMatch(): void
    {
        $filter = $this->filter(
            'ATTENDEE',
            null,
            $this->paramFilter('PARTSTAT', $this->match('ACCEPTED')),
            $this->paramFilter('ROLE', $this->match('CHAIR')),
        );

        self::assertTrue($this->holds(
            $filter,
            "ATTENDEE;PARTSTAT=ACCEPTED;ROLE=CHAIR:mailto:a@example.com\r\n",
        ));
        self::assertFalse($this->holds(
            $filter,
            "ATTENDEE;PARTSTAT=ACCEPTED;ROLE=REQ-PARTICIPANT:mailto:a@example.com\r\n",
        ));
    }

    /**
     * The property's name is read whatever its case (RFC 5545 §3.1).
     */
    public function testTheNameIsReadWhateverItsCase(): void
    {
        self::assertTrue($this->holds($this->filter('summary'), "SUMMARY:A meeting\r\n"));
    }

    /**
     * §9.7.2 makes the attribute `#REQUIRED`.
     */
    public function testAFilterWithoutANameIsRefused(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('name');

        PropFilter::of(new Element(PropFilter::NAME));
    }

    /**
     * **`is-not-defined` is a choice against everything else**, so it does not
     * stand beside a value test or a parameter test: the DTD is
     * `(is-not-defined | ((time-range | text-match)?, param-filter*))`.
     */
    public function testIsNotDefinedBesideAnythingElseIsRefused(): void
    {
        $element = new Element(PropFilter::NAME, ['name' => 'SUMMARY']);

        $element->append(new Element(ParamFilter::NOT_DEFINED));
        $element->append($this->match('meeting'));

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('is-not-defined');

        PropFilter::of($element);
    }

    /**
     * **And a value is tested one way or the other, not both**: the DTD writes
     * `(time-range | text-match)?`, a choice of at most one.
     */
    public function testATimeRangeBesideATextMatchIsRefused(): void
    {
        $element = new Element(PropFilter::NAME, ['name' => 'DTSTART']);

        $element->append($this->range('20260101T000000Z', null));
        $element->append($this->match('2026'));

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('one of');

        PropFilter::of($element);
    }

    /**
     * A child the DTD does not name is refused rather than ignored: a server
     * that quietly dropped a condition would answer a question the client did
     * not ask.
     */
    public function testAChildTheDtdDoesNotNameIsRefused(): void
    {
        $element = new Element(PropFilter::NAME, ['name' => 'SUMMARY']);

        $element->append(new Element('{urn:ietf:params:xml:ns:caldav}comp-filter', ['name' => 'VEVENT']));

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('comp-filter');

        PropFilter::of($element);
    }

    /**
     * And the order the DTD writes is the order it means: the parameter tests
     * come after the value test, so a value test behind one of them is a
     * document this server cannot read.
     */
    public function testAValueTestAfterAParameterTestIsRefused(): void
    {
        $element = new Element(PropFilter::NAME, ['name' => 'ATTENDEE']);

        $element->append($this->paramFilter('PARTSTAT', $this->match('ACCEPTED')));
        $element->append($this->match('anne'));

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('before');

        PropFilter::of($element);
    }

    private function filter(string $name, ?Element $value = null, Element ...$params): PropFilter
    {
        $element = new Element(PropFilter::NAME, ['name' => $name]);

        if ($value !== null) {
            $element->append($value);
        }

        foreach ($params as $param) {
            $element->append($param);
        }

        return PropFilter::of($element);
    }

    private function paramFilter(string $name, Element $match): Element
    {
        $element = new Element(ParamFilter::NAME, ['name' => $name]);

        $element->append($match);

        return $element;
    }

    private function match(string $lookedFor): Element
    {
        $element = new Element(TextMatch::NAME);

        $element->appendText($lookedFor);

        return $element;
    }

    private function range(string $from, ?string $until): Element
    {
        $attributes = ['start' => $from];

        if ($until !== null) {
            $attributes['end'] = $until;
        }

        return new Element(TimeRange::NAME, $attributes);
    }

    /**
     * Whether the filter holds of an event carrying those lines.
     */
    private function holds(PropFilter $filter, string $lines): bool
    {
        $object = $this->objectOf(
            "BEGIN:VEVENT\r\nUID:1@example.com\r\nDTSTAMP:20260101T000000Z\r\n" . $lines . "END:VEVENT\r\n",
        );
        $event = $object->component('VEVENT');

        self::assertNotNull($event);

        return $filter->holds($event, new Moments($object, new DateTimeZone('UTC')));
    }

    private function objectOf(string $components): Component
    {
        $text = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\n"
            . $components . "END:VCALENDAR\r\n";

        return CalendarObject::in($text) ?? self::fail('The case built something that is no calendar object.');
    }
}
