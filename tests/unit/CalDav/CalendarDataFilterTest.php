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

namespace DavServices\Tests\Unit\CalDav;

use DavServices\CalDav\CalendarDataFilter;
use DavServices\CalDav\CalendarObject;
use DavServices\Exception\BadRequest;
use DavServices\VObject\Component;
use DavServices\VObject\Serializer;
use DavServices\Xml\Element;
use DavServices\Xml\Reader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for which parts of an object a report returns. R-CAL-13, P6-07.
 *
 * RFC 4791 §9.6.1 to §9.6.4:
 *
 *     <!ELEMENT comp ((allprop | prop*), (allcomp | comp*))>
 *     <!ATTLIST comp name CDATA #REQUIRED>
 *     <!ELEMENT allcomp EMPTY>
 *     <!ELEMENT allprop EMPTY>
 *     <!ELEMENT prop EMPTY>
 *     <!ATTLIST prop name CDATA #REQUIRED
 *                    novalue (yes | no) "no">
 *
 * **Why a client narrows at all** is §9.6's own sentence: "one can explicitly
 * specify the calendar components and properties that should be returned in
 * the calendar object resource data that matches the filter". A month view
 * needs a summary and a start, not every alarm and attachment of every
 * appointment.
 *
 * **And the answer may be invalid iCalendar on purpose.** §9.6: "calendar
 * object resources specified in the CALDAV:calendar-data XML element MAY be
 * invalid per their media type specification if the CALDAV:calendar-data XML
 * element part of the calendaring REPORT request did not specify required
 * properties (e.g., UID, DTSTAMP, etc.)". So nothing is patched up.
 */
#[CoversClass(CalendarDataFilter::class)]
final class CalendarDataFilterTest extends TestCase
{
    private const MEETING = "BEGIN:VEVENT\r\nUID:1@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
        . "DTSTART:20260105T120000Z\r\nSUMMARY:A Meeting\r\n"
        . "ATTENDEE;PARTSTAT=ACCEPTED;CN=Bernard:mailto:bernard@example.com\r\n"
        . "BEGIN:VALARM\r\nACTION:DISPLAY\r\nDESCRIPTION:Soon\r\nTRIGGER:-PT15M\r\nEND:VALARM\r\n"
        . "END:VEVENT\r\n";

    private const TASK = "BEGIN:VTODO\r\nUID:2@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
        . "DUE:20260110T120000Z\r\nSUMMARY:Something to do\r\nEND:VTODO\r\n";

    /**
     * **§9.6.4: "The 'name' attribute specifies the name of the calendar
     * property to return".** So what is named comes back and the rest does
     * not — which is §7.8.1's example in miniature, where "the only property
     * returned in the VCALENDAR object is VERSION".
     */
    public function testOnlyThePropertiesNamedComeBack(): void
    {
        $narrowed = $this->applied('<prop name="VERSION"/>');

        self::assertStringContainsString("VERSION:2.0\r\n", $narrowed);
        self::assertStringNotContainsString('PRODID', $narrowed);
    }

    /**
     * §9.6.3: "The CALDAV:allprop XML element can be used when the client
     * wants all properties of components returned by a calendaring REPORT
     * request."
     */
    public function testAllpropBringsEveryProperty(): void
    {
        $narrowed = $this->applied('<allprop/>');

        self::assertStringContainsString("VERSION:2.0\r\n", $narrowed);
        self::assertStringContainsString('PRODID:', $narrowed);
    }

    /**
     * And `prop*` with no `prop` at all is a request for no property, because
     * that is what the DTD's star means — the client asked for the components
     * inside and nothing of this one.
     */
    public function testWithoutAllpropAndWithoutAPropNoPropertyComesBack(): void
    {
        $narrowed = $this->applied('<comp name="VEVENT"><prop name="SUMMARY"/></comp>');

        self::assertStringNotContainsString('VERSION', $narrowed);
        self::assertStringNotContainsString('PRODID', $narrowed);
        self::assertStringContainsString("SUMMARY:A Meeting\r\n", $narrowed);
    }

    /**
     * **§9.6.1: "Defines which component types to return."** A kind nobody
     * named is not one of them.
     */
    public function testOnlyTheComponentsNamedComeBack(): void
    {
        $narrowed = $this->applied('<allprop/><comp name="VEVENT"><allprop/></comp>');

        self::assertStringContainsString('SUMMARY:A Meeting', $narrowed);
        self::assertStringNotContainsString('VTODO', $narrowed);
    }

    /**
     * §9.6.2: "The CALDAV:allcomp XML element can be used when the client
     * wants all types of components returned by a calendaring REPORT
     * request." All types, and whole: there is no `CALDAV:comp` beside it to
     * say which parts of them.
     */
    public function testAllcompBringsEveryComponentWhole(): void
    {
        $narrowed = $this->applied('<allprop/><allcomp/>');

        self::assertStringContainsString('SUMMARY:A Meeting', $narrowed);
        self::assertStringContainsString('SUMMARY:Something to do', $narrowed);
        self::assertStringContainsString('BEGIN:VALARM', $narrowed);
    }

    /**
     * And `comp*` with no `comp` at all leaves the components out, which is
     * the request a client makes when it wants the calendar's own properties
     * and nothing else.
     */
    public function testWithoutAllcompAndWithoutACompNoComponentComesBack(): void
    {
        $narrowed = $this->applied('<allprop/>');

        self::assertStringContainsString("VERSION:2.0\r\n", $narrowed);
        self::assertStringNotContainsString('BEGIN:VEVENT', $narrowed);
        self::assertStringNotContainsString('BEGIN:VTODO', $narrowed);
    }

    /**
     * **The restriction reaches as deep as it is written**, because §9.6.1's
     * element carries itself: an alarm inside an event inside the object is
     * three `CALDAV:comp` elements deep, and each says what of its own
     * component comes back.
     */
    public function testTheRestrictionReachesAsDeepAsItIsWritten(): void
    {
        $narrowed = $this->applied(
            '<comp name="VEVENT"><prop name="SUMMARY"/>'
            . '<comp name="VALARM"><prop name="TRIGGER"/></comp></comp>',
        );

        self::assertStringContainsString("SUMMARY:A Meeting\r\n", $narrowed);
        self::assertStringNotContainsString('DTSTART', $narrowed);
        self::assertStringContainsString("TRIGGER:-PT15M\r\n", $narrowed);
        self::assertStringNotContainsString('ACTION', $narrowed);
    }

    /**
     * **§9.6.4 on `novalue`: "the server will return just the iCalendar
     * property name and any iCalendar parameters and a trailing ':' without
     * the subsequent value data."**
     *
     * Which is what a client asks for when it wants to know that an
     * attachment is there without being sent it.
     */
    public function testNovalueKeepsTheNameAndTheParametersAndDropsTheValue(): void
    {
        $narrowed = $this->applied(
            '<comp name="VEVENT"><prop name="ATTENDEE" novalue="yes"/></comp>',
        );

        self::assertStringContainsString("ATTENDEE;PARTSTAT=ACCEPTED;CN=Bernard:\r\n", $narrowed);
        self::assertStringNotContainsString('mailto:bernard@example.com', $narrowed);
    }

    /**
     * §9.6.4's `<!ATTLIST>` gives `novalue` the default `"no"`, and saying it
     * says what silence already meant.
     *
     * @param string $written How the attribute is written, or nothing at all
     */
    #[DataProvider('waysOfAskingForTheValue')]
    public function testWithoutNovalueTheValueComesBack(string $written): void
    {
        $narrowed = $this->applied(
            sprintf('<comp name="VEVENT"><prop name="SUMMARY"%s/></comp>', $written),
        );

        self::assertStringContainsString("SUMMARY:A Meeting\r\n", $narrowed);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function waysOfAskingForTheValue(): iterable
    {
        yield 'no attribute at all' => [''];
        yield 'novalue="no"' => [' novalue="no"'];
    }

    /**
     * **A name is a name however loudly it is written.** RFC 5545 §3.1: "the
     * property name `DUE` is the same as `due` and `Due`", and §3.6 writes the
     * component names in the same grammar — so a client that wrote them
     * quietly asked for the same parts.
     */
    public function testNamesAreReadWithoutRegardToCase(): void
    {
        $narrowed = $this->applied('<comp name="vevent"><prop name="summary"/></comp>');

        self::assertStringContainsString("SUMMARY:A Meeting\r\n", $narrowed);
    }

    /**
     * **Every occurrence of a property named comes back**, not the first:
     * RFC 5545 §3.6.1 lets a `VEVENT` carry one `ATTENDEE` per attendee, and a
     * client that asked for them asked for all of them.
     */
    public function testEveryOccurrenceOfAPropertyNamedComesBack(): void
    {
        $twice = str_replace(
            "SUMMARY:A Meeting\r\n",
            "SUMMARY:A Meeting\r\nATTENDEE:mailto:cyrus@example.com\r\n",
            self::MEETING,
        );
        $narrowed = $this->appliedTo(
            '<comp name="VEVENT"><prop name="ATTENDEE"/></comp>',
            $this->objectOf($twice),
        );

        self::assertSame(2, substr_count($narrowed, 'ATTENDEE'));
    }

    /**
     * **The order is the object's, not the request's.** A restriction says
     * which parts come back, and §9.6 says nothing about rearranging them —
     * while RFC 5545 §3.6 writes a component's properties before the
     * components inside it, which is the order the answer keeps.
     */
    public function testThePartsComeBackInTheOrderTheObjectHasThem(): void
    {
        $narrowed = $this->applied(
            '<prop name="PRODID"/><prop name="VERSION"/>'
            . '<comp name="VEVENT"><prop name="SUMMARY"/><prop name="UID"/></comp>',
        );

        self::assertLessThan(strpos($narrowed, 'PRODID'), strpos($narrowed, 'VERSION'));
        self::assertLessThan(strpos($narrowed, 'SUMMARY'), strpos($narrowed, 'UID'));
    }

    /**
     * **§9.6 lets the answer be invalid, and this is the case it means:** an
     * event without `UID` or `DTSTAMP` is no calendar object resource (§4.1,
     * RFC 5545 §3.6.1), and it is still what the client asked for. Nothing is
     * patched up — unlike {@see \DavServices\CardDav\AddressData}, which
     * writes a `VERSION` from its own attribute, because a vCard without one
     * cannot be read back at all while iCalendar names its version on the
     * object rather than on the component.
     */
    public function testWhatComesBackMayBeInvalidOnPurpose(): void
    {
        $narrowed = $this->applied('<comp name="VEVENT"><prop name="SUMMARY"/></comp>');

        self::assertStringContainsString("BEGIN:VEVENT\r\nSUMMARY:A Meeting\r\nEND:VEVENT\r\n", $narrowed);
        self::assertStringNotContainsString('UID', $narrowed);
        self::assertStringNotContainsString('DTSTAMP', $narrowed);
    }

    /**
     * **A restriction about another kind of component keeps nothing of this
     * one.** §9.6.1 defines "which component types to return", and a type
     * that is not this one names no part of it — the same reading §9.7.1 gets
     * for the outermost `comp-filter`, which is about the calendar object
     * itself.
     */
    public function testARestrictionAboutAnotherKindKeepsNothing(): void
    {
        $element = new Element(CalendarDataFilter::NAME, ['name' => 'VTODO']);

        $element->append(new Element('{urn:ietf:params:xml:ns:caldav}allprop'));

        self::assertNull(CalendarDataFilter::of($element)->appliedTo($this->objectOf(self::MEETING . self::TASK)));
    }

    /**
     * §9.6.1 makes the attribute `#REQUIRED`.
     */
    public function testACompWithoutANameIsRefused(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('name');

        CalendarDataFilter::of(new Element(CalendarDataFilter::NAME));
    }

    public function testANestedCompWithoutANameIsRefused(): void
    {
        $element = new Element(CalendarDataFilter::NAME, ['name' => 'VCALENDAR']);

        $element->append(new Element(CalendarDataFilter::NAME));

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('name');

        CalendarDataFilter::of($element);
    }

    /**
     * **`(allprop | prop*)` is a choice, and so is `(allcomp | comp*)`.**
     * Asking for everything and for three named things says two things at
     * once, and only one of them can be meant.
     *
     * @param string $children What the `CALDAV:comp` carries
     * @param string $says What the refusal names
     */
    #[DataProvider('bodiesTheDtdDoesNotAdmit')]
    public function testWhatTheDtdDoesNotAdmitIsRefused(string $children, string $says): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage($says);

        $this->filter($children);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bodiesTheDtdDoesNotAdmit(): iterable
    {
        yield 'allprop beside a prop' => ['<allprop/><prop name="VERSION"/>', 'allprop | prop*'];
        yield 'a prop beside an allprop' => ['<prop name="VERSION"/><allprop/>', 'allprop | prop*'];
        yield 'allcomp beside a comp' => ['<allcomp/><comp name="VEVENT"/>', 'allcomp | comp*'];
        yield 'a comp beside an allcomp' => ['<comp name="VEVENT"/><allcomp/>', 'allcomp | comp*'];

        // The DTD writes the properties before the components, and the order
        // it writes is the order it means.
        yield 'a prop after a comp' => ['<comp name="VEVENT"/><prop name="VERSION"/>', 'before'];
        yield 'an allprop after a comp' => ['<comp name="VEVENT"/><allprop/>', 'before'];

        yield 'a child the DTD does not name' => ['<time-range start="20260101T000000Z"/>', 'time-range'];

        // §9.6.4 makes the attribute `#REQUIRED` of a `CALDAV:prop` too.
        yield 'a prop without a name' => ['<prop/>', '#REQUIRED'];
        yield 'a novalue saying neither' => ['<prop name="VERSION" novalue="maybe"/>', 'novalue'];
    }

    /**
     * The object as the filter leaves it, written out.
     *
     * @param string $children What goes inside the outermost `CALDAV:comp`,
     *                         which is about the calendar object itself
     */
    private function applied(string $children): string
    {
        return $this->appliedTo($children, $this->objectOf(self::MEETING . self::TASK));
    }

    private function appliedTo(string $children, Component $object): string
    {
        $narrowed = $this->filter($children)->appliedTo($object);

        self::assertInstanceOf(Component::class, $narrowed);

        return (new Serializer())->write($narrowed);
    }

    /**
     * One restriction about the calendar object, from what goes inside it.
     */
    private function filter(string $children): CalendarDataFilter
    {
        $body = sprintf(
            '<comp xmlns="urn:ietf:params:xml:ns:caldav" name="VCALENDAR">%s</comp>',
            $children,
        );

        return CalendarDataFilter::of((new Reader())->parse($body));
    }

    private function objectOf(string $components): Component
    {
        $text = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\n"
            . $components . "END:VCALENDAR\r\n";

        return CalendarObject::in($text) ?? self::fail('The case built something that is no calendar object.');
    }
}
