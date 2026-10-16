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

use DavServices\CalDav\CalendarObject;
use DavServices\Exception\Forbidden;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Tests\Unit\Dav\PlainNode;
use DavServices\VObject\Component;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for what a calendar object resource is. RFC 4791 §4.1, P6-02.
 *
 * **The question both of CalDAV's write-time halves ask.** The limits of §5.2
 * are about "a calendar object resource stored in a calendar collection", and
 * §4.1's own restrictions are about the same thing — so "is this one, and what
 * is in it" is asked once, the way `CardDav\AddressObject` asks it for a card.
 *
 * What §4.1 settles beyond that — one type of component only, no `METHOD`, one
 * `UID` per resource — belongs to the chunk that validates a `PUT` (P6-04).
 * This is the part the limits need: the object, and which kinds of component
 * it holds.
 */
#[CoversClass(CalendarObject::class)]
final class CalendarObjectTest extends TestCase
{
    /** One `VTIMEZONE`, shortened to the one observance it needs. */
    private const ZONE = "BEGIN:VTIMEZONE\r\nTZID:US-Eastern\r\nBEGIN:STANDARD\r\n"
        . "DTSTART:19671029T020000\r\nTZOFFSETFROM:-0400\r\nTZOFFSETTO:-0500\r\n"
        . "END:STANDARD\r\nEND:VTIMEZONE\r\n";

    private const MEETING = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\n"
        . "BEGIN:VEVENT\r\nUID:1@example.com\r\nDTSTAMP:20041210T183904Z\r\nDTSTART:20041207T120000Z\r\n"
        . "SUMMARY:One-off Meeting\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    public function testAFileHoldingOneCalendarIsOne(): void
    {
        $object = CalendarObject::of(new MemoryFile('meeting.ics', self::MEETING));

        self::assertNotNull($object);
        self::assertSame('VCALENDAR', $object->name());
    }

    /**
     * "an iCalendar object", singular, the same reading RFC 6352 §5.1 gets for
     * a card: two of them is not one of them.
     */
    public function testTwoCalendarsInOneFileAreNotOne(): void
    {
        self::assertNull(CalendarObject::in(self::MEETING . self::MEETING));
    }

    public function testTextThatIsNoCalendarIsNotOne(): void
    {
        self::assertNull(CalendarObject::in('This is a note, not a calendar.'));
    }

    public function testNothingAtAllIsNotOne(): void
    {
        self::assertNull(CalendarObject::in(''));
    }

    /**
     * RFC 5545 §3.4 makes `VCALENDAR` the only iCalendar object there is, so a
     * component of another kind on its own is not one.
     */
    public function testAComponentOfAnotherKindIsNotOne(): void
    {
        self::assertNull(CalendarObject::in("BEGIN:VEVENT\r\nUID:x\r\nEND:VEVENT\r\n"));
    }

    public function testACollectionIsNotOne(): void
    {
        self::assertNull(CalendarObject::of(new MemoryCollection('attachments')));
    }

    public function testSomethingThatIsNoFileIsNotOne(): void
    {
        self::assertNull(CalendarObject::of(new PlainNode('a-principal')));
    }

    /**
     * **The kinds of component it holds**, which is what
     * `CALDAV:supported-calendar-component-set` is matched against.
     */
    public function testItSaysWhichKindsOfComponentItHolds(): void
    {
        $object = CalendarObject::in(self::MEETING);

        self::assertNotNull($object);
        self::assertSame(['VEVENT'], CalendarObject::kindsIn($object));
    }

    /**
     * **`VTIMEZONE` is not one of the kinds**, and §5.2.3 says why: "The
     * empty-element tag `<C:comp name="VTIMEZONE"/>` MUST only be specified if
     * support for calendar object resources that only contain VTIMEZONE
     * components is provided or desired. Support for VTIMEZONE components in
     * calendar object resources that contain VEVENT or VTODO components is
     * always assumed."
     *
     * So an object with an event and the zone that event needs holds one kind,
     * not two — and a collection that takes `VEVENT` takes it.
     */
    public function testTheTimeZoneAnEventNeedsIsNotAKindOfItsOwn(): void
    {
        $withZone = str_replace(
            "BEGIN:VEVENT\r\n",
            "BEGIN:VTIMEZONE\r\nTZID:US-Eastern\r\nBEGIN:STANDARD\r\nDTSTART:19671029T020000\r\n"
            . "TZOFFSETFROM:-0400\r\nTZOFFSETTO:-0500\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\nBEGIN:VEVENT\r\n",
            self::MEETING,
        );
        $object = CalendarObject::in($withZone);

        self::assertNotNull($object);
        self::assertSame(['VEVENT'], CalendarObject::kindsIn($object));
    }

    /**
     * **An object of nothing but time zones holds `VTIMEZONE`**, which is the
     * other half of §5.2.3's sentence: the tag is for "calendar object
     * resources that only contain VTIMEZONE components", so such an object has
     * to be able to name its kind.
     */
    public function testAnObjectOfNothingButTimeZonesHoldsThatKind(): void
    {
        $zone = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//x//y//EN\r\n"
            . "BEGIN:VTIMEZONE\r\nTZID:US-Eastern\r\nBEGIN:STANDARD\r\nDTSTART:19671029T020000\r\n"
            . "TZOFFSETFROM:-0400\r\nTZOFFSETTO:-0500\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\nEND:VCALENDAR\r\n";
        $object = CalendarObject::in($zone);

        self::assertNotNull($object);
        self::assertSame(['VTIMEZONE'], CalendarObject::kindsIn($object));
    }

    /**
     * Two kinds are two kinds — §4.1 forbids that in a stored resource, and
     * saying so is P6-04's refusal; here it is only reported truthfully, each
     * kind once however often it appears.
     */
    public function testTwoKindsAreBothNamedAndNamedOnce(): void
    {
        $both = str_replace(
            "END:VCALENDAR\r\n",
            "BEGIN:VTODO\r\nUID:2@example.com\r\nDTSTAMP:20041210T183904Z\r\nEND:VTODO\r\n"
            . "BEGIN:VTODO\r\nUID:3@example.com\r\nDTSTAMP:20041210T183904Z\r\nEND:VTODO\r\nEND:VCALENDAR\r\n",
            self::MEETING,
        );
        $object = CalendarObject::in($both);

        self::assertNotNull($object);
        self::assertSame(['VEVENT', 'VTODO'], CalendarObject::kindsIn($object));
    }

    /**
     * **An object written in lower case is still an object.** RFC 5545 §3.4
     * gives the grammar as `"BEGIN" ":" "VCALENDAR"`, and RFC 5234 §2.3 says
     * what those quotes mean: "ABNF strings are case insensitive". §3.1 says
     * the same of the names inside — "the property name `DUE` is the same as
     * `due` and `Due`" — and {@see \DavServices\VObject\Component} already
     * looks properties up that way.
     *
     * So a reader that only answered to the shouted spelling would refuse
     * legal iCalendar with `CALDAV:valid-calendar-data`, which is the one
     * refusal a client cannot argue with.
     */
    public function testAnObjectWrittenInLowerCaseIsStillAnObject(): void
    {
        $quiet = "begin:vcalendar\r\nversion:2.0\r\nprodid:-//Example Corp.//CalDAV Client//EN\r\n"
            . "begin:vevent\r\nuid:1@example.com\r\ndtstamp:20260101T000000Z\r\n"
            . "dtstart:20260105T120000Z\r\nend:vevent\r\nend:vcalendar\r\n";

        $object = CalendarObject::in($quiet);

        self::assertNotNull($object);
        self::assertSame(['VEVENT'], CalendarObject::kindsIn($object));
    }

    /**
     * **§4.1's own example, in its own words.** "For instance, a calendar
     * object resource can contain one VEVENT component and one VTIMEZONE
     * component, but it cannot contain one VEVENT component and one VTODO
     * component. Instead, the VEVENT and VTODO components would have to be
     * stored in separate calendar object resources in the same collection."
     */
    public function testTwoKindsOfComponentAreNotOneResource(): void
    {
        // One `UID` between them, so that nothing but the rule about kinds can
        // refuse this object. §4.1 forbids the two kinds whatever their UIDs.
        $this->expectRefusal('valid-calendar-object-resource', 'holds one type of component, not VEVENT and VTODO');

        CalendarObject::mustBeOneResource($this->objectOf(
            "BEGIN:VEVENT\r\nUID:1@example.com\r\nDTSTAMP:20041210T183904Z\r\nEND:VEVENT\r\n"
            . "BEGIN:VTODO\r\nUID:1@example.com\r\nDTSTAMP:20041210T183904Z\r\nEND:VTODO\r\n",
        ));
    }

    /**
     * And the exception the same sentence makes: an event and the zone it
     * needs are one resource.
     */
    public function testAnEventAndItsTimeZoneAreOneResource(): void
    {
        CalendarObject::mustBeOneResource($this->objectOf(
            self::ZONE . "BEGIN:VEVENT\r\nUID:1@example.com\r\nDTSTAMP:20041210T183904Z\r\n"
            . "DTSTART;TZID=US-Eastern:20041207T120000\r\nEND:VEVENT\r\n",
        ));

        $this->expectNotToPerformAssertions();
    }

    /**
     * **§4.1: "Calendar object resources contained in calendar collections
     * MUST NOT specify the iCalendar METHOD property."**
     *
     * The property belongs to iTIP (RFC 5546 §1.4), where an object is a
     * message between calendar users rather than something a collection
     * keeps. §5.3.2.1 names it as an example of what
     * `CALDAV:valid-calendar-object-resource` refuses.
     */
    public function testAnObjectThatSpecifiesAMethodIsNoResource(): void
    {
        $this->expectRefusal('valid-calendar-object-resource', 'specifies no METHOD property');

        CalendarObject::mustBeOneResource($this->objectOf(
            "BEGIN:VEVENT\r\nUID:1@example.com\r\nDTSTAMP:20041210T183904Z\r\nEND:VEVENT\r\n",
            "METHOD:REQUEST\r\n",
        ));
    }

    /**
     * **§4.1: "Calendar components in a calendar collection that have
     * different UID property values MUST be stored in separate calendar
     * object resources."**
     */
    public function testTwoUidsAreNotOneResource(): void
    {
        $this->expectRefusal('valid-calendar-object-resource', 'different UIDs belong in separate');

        CalendarObject::mustBeOneResource($this->objectOf(
            "BEGIN:VEVENT\r\nUID:1@example.com\r\nDTSTAMP:20041210T183904Z\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:2@example.com\r\nDTSTAMP:20041210T183904Z\r\nEND:VEVENT\r\n",
        ));
    }

    /**
     * **And the other half of the same rule**: "Calendar components with the
     * same UID property value, in a given calendar collection, MUST be
     * contained in the same calendar object resource. This ensures that all
     * components in a recurrence 'set' are contained in the same calendar
     * object resource."
     *
     * This is §4.1's own example, the master and the one overridden instance.
     */
    public function testAMasterAndItsOverrideAreOneResource(): void
    {
        CalendarObject::mustBeOneResource($this->objectOf(
            "BEGIN:VEVENT\r\nUID:2@example.com\r\nDTSTAMP:20041210T183838Z\r\n"
            . "DTSTART:20041206T120000Z\r\nRRULE:FREQ=WEEKLY\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:2@example.com\r\nRECURRENCE-ID:20041213T120000Z\r\n"
            . "DTSTAMP:20041210T183838Z\r\nDTSTART:20041213T130000Z\r\nEND:VEVENT\r\n",
        ));

        $this->expectNotToPerformAssertions();
    }

    /**
     * **An object of overridden instances alone is a resource**, which §4.1
     * says in as many words: "It is possible for a calendar object resource to
     * just contain components that represent 'overridden' instances (ones that
     * modify the behavior of a regular instance, and thus include a
     * RECURRENCE-ID property) without also including the 'master' recurring
     * component."
     */
    public function testOverriddenInstancesAloneAreOneResource(): void
    {
        CalendarObject::mustBeOneResource($this->objectOf(
            "BEGIN:VEVENT\r\nUID:2@example.com\r\nRECURRENCE-ID:20041213T120000Z\r\n"
            . "DTSTAMP:20041210T183838Z\r\nDTSTART:20041213T130000Z\r\nEND:VEVENT\r\n",
        ));

        $this->expectNotToPerformAssertions();
    }

    /**
     * **Exactly one means not none** (R-CAL-07). RFC 5545 §3.6.1 lists `uid`
     * among the properties of a `VEVENT` that "are REQUIRED, but MUST NOT
     * occur more than once", and §4.1's rule about which components may share
     * a resource has nothing to hold on a component that has no `UID` at all.
     */
    public function testAComponentWithoutAUidIsNoResource(): void
    {
        $this->expectRefusal('valid-calendar-object-resource', 'A VEVENT in a calendar carries a UID');

        CalendarObject::mustBeOneResource($this->objectOf(
            "begin:vevent\r\ndtstamp:20041210T183904Z\r\nsummary:Nameless\r\nend:vevent\r\n",
        ));
    }

    /**
     * **A resource of nothing but time zones needs no `UID`**, because a
     * `VTIMEZONE` has none: RFC 5545 §3.6.5 gives it a `TZID` instead. §5.2.3
     * licenses such a resource where a server supports one.
     */
    public function testAnObjectOfNothingButTimeZonesIsOneResource(): void
    {
        CalendarObject::mustBeOneResource($this->objectOf(self::ZONE));

        $this->expectNotToPerformAssertions();
    }

    /**
     * **§4.1 excepts "VTIMEZONE components, which MUST be specified for each
     * unique TZID parameter value specified in the iCalendar object"**, and
     * RFC 5545 §3.2.19 says the same sentence: "An individual 'VTIMEZONE'
     * calendar component MUST be specified for each unique 'TZID' parameter
     * value specified in the iCalendar object."
     *
     * So a time named in a zone the object does not carry is a time nobody can
     * place.
     */
    public function testATimeZoneNamedButNotCarriedIsNoResource(): void
    {
        $this->expectRefusal('valid-calendar-object-resource', 'names the time zone "US-Eastern"');

        CalendarObject::mustBeOneResource($this->objectOf(
            "BEGIN:VEVENT\r\nUID:1@example.com\r\nDTSTAMP:20041210T183904Z\r\n"
            . "DTSTART;TZID=US-Eastern:20041207T120000\r\nEND:VEVENT\r\n",
        ));
    }

    /**
     * **A solidus is no exemption.** RFC 5545 §3.2.19 reads "The presence of
     * the SOLIDUS character as a prefix, indicates that this 'TZID' represents
     * a unique ID in a globally defined time zone registry (when such registry
     * is defined)" — and the parenthesis is the point: the memos here define
     * no such registry. The grammar puts the prefix inside the parameter value
     * (`tzidparam = "TZID" "=" [tzidprefix] paramtext`), so it is one of the
     * "unique TZID parameter value[s]" the sentence above asks for a component
     * for.
     */
    public function testATimeZoneFromARegistryIsStillCarried(): void
    {
        $this->expectRefusal('valid-calendar-object-resource', 'names the time zone "/US-Eastern"');

        CalendarObject::mustBeOneResource($this->objectOf(
            "BEGIN:VEVENT\r\nUID:1@example.com\r\nDTSTAMP:20041210T183904Z\r\n"
            . "DTSTART;TZID=/US-Eastern:20041207T120000\r\nEND:VEVENT\r\n",
        ));
    }

    /**
     * And a floating or UTC time names no zone, so it needs none — §3.2.19
     * asks for the parameter only "when the value is neither a UTC or a
     * 'floating' time".
     */
    public function testATimeThatNamesNoZoneNeedsNone(): void
    {
        CalendarObject::mustBeOneResource($this->objectOf(
            "BEGIN:VEVENT\r\nUID:1@example.com\r\nDTSTAMP:20041210T183904Z\r\n"
            . "DTSTART:20041207T120000Z\r\nDTEND:20041207T130000\r\nEND:VEVENT\r\n",
        ));

        $this->expectNotToPerformAssertions();
    }

    /**
     * **Every component is asked, not just the ones before the first zone.**
     * A `VTIMEZONE` comes first in almost every object a client sends, so a
     * walk that stopped at it would ask nothing of the event behind it.
     */
    public function testAnEventBehindItsTimeZoneIsStillAsked(): void
    {
        $this->expectRefusal('valid-calendar-object-resource', 'A VEVENT in a calendar carries a UID');

        CalendarObject::mustBeOneResource($this->objectOf(
            self::ZONE . "BEGIN:VEVENT\r\nDTSTAMP:20041210T183904Z\r\nSUMMARY:Nameless\r\nEND:VEVENT\r\n",
        ));
    }

    /**
     * **A time zone component without a `TZID` carries no zone**, whatever
     * else it holds. RFC 5545 §3.6.5 makes the property mandatory, so such a
     * component is invalid iCalendar — and a guard that read it as carrying
     * something would let an object name a zone nobody can resolve.
     */
    public function testAZoneWithoutATzidCarriesNothing(): void
    {
        $this->expectRefusal('valid-calendar-object-resource', 'names the time zone "US-Eastern"');

        CalendarObject::mustBeOneResource($this->objectOf(
            "BEGIN:VTIMEZONE\r\nBEGIN:STANDARD\r\nDTSTART:19671029T020000\r\n"
            . "TZOFFSETFROM:-0400\r\nTZOFFSETTO:-0500\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\n"
            . "BEGIN:VEVENT\r\nUID:1@example.com\r\nDTSTAMP:20041210T183904Z\r\n"
            . "DTSTART;TZID=US-Eastern:20041207T120000\r\nEND:VEVENT\r\n",
        ));
    }

    /**
     * **Every zone the object names is asked for, not just the first.** §4.1
     * says "each unique TZID parameter value", so carrying one of two is
     * carrying neither as far as the second is concerned.
     */
    public function testEveryZoneItNamesIsAskedForNotJustTheFirst(): void
    {
        $this->expectRefusal('valid-calendar-object-resource', 'names the time zone "Europe/Berlin"');

        CalendarObject::mustBeOneResource($this->objectOf(
            self::ZONE . "BEGIN:VEVENT\r\nUID:1@example.com\r\nDTSTAMP:20041210T183904Z\r\n"
            . "DTSTART;TZID=US-Eastern:20041207T120000\r\n"
            . "DTEND;TZID=Europe/Berlin:20041207T190000\r\nEND:VEVENT\r\n",
        ));
    }

    /**
     * An object with no component at all holds no kind — which is a thing to
     * say rather than a thing to crash on.
     */
    public function testAnEmptyCalendarHoldsNoKind(): void
    {
        $object = CalendarObject::in("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//x//y//EN\r\nEND:VCALENDAR\r\n");

        self::assertNotNull($object);
        self::assertSame([], CalendarObject::kindsIn($object));
    }
    /**
     * One calendar object built around the components a case is about.
     */
    private function objectOf(string $components, string $properties = ''): Component
    {
        $text = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\n"
            . $properties . $components . "END:VCALENDAR\r\n";

        // `fail()` rather than an assertion, so that a case which expects no
        // assertions at all is not made to perform one by its own fixture.
        return CalendarObject::in($text) ?? self::fail('The case built something that is no calendar object.');
    }

    /**
     * **A refusal says what is wrong, and then which precondition that broke.**
     * Asking for the precondition alone would let half a message pass for a
     * whole one, and the half that explains is the half somebody reads in a
     * log.
     *
     * @param string $because The explanation, which comes first
     */
    private function expectRefusal(string $precondition, string $because): void
    {
        $this->expectException(Forbidden::class);
        $this->expectExceptionMessageMatches(
            '/' . preg_quote($because, '/') . '.*' . preg_quote($precondition, '/') . '/s',
        );
    }
}
