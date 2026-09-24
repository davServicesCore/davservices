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

namespace DavServices\Tests\Unit\VObject;

use DavServices\VObject\Component;
use DavServices\VObject\Property;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 5545 §3.4 to §3.6 and RFC 6350 §6, and from
 * R-VOBJ-07.
 *
 * **A component is properties and components, in the order they were
 * written.** RFC 5545 §3.6: "The body of the iCalendar object consists of a
 * sequence of calendar properties and one or more calendar components. The
 * calendar properties are attributes that apply to the calendar object as a
 * whole. The calendar components are collections of properties that express a
 * particular calendar semantic."
 *
 * ## Any name at all
 *
 * The grammar of §3.6 ends on `iana-comp` and `x-comp`:
 *
 *     iana-comp = "BEGIN" ":" iana-token CRLF 1*contentline "END" ":" iana-token CRLF
 *
 * So **a component may be called anything**, and a model with a list of known
 * names would refuse `VAVAILABILITY` (RFC 7953), `VPOLL`, or whatever is
 * registered next week. Nothing here knows what a `VEVENT` is; that is what
 * CalDAV is for.
 *
 * ## Order is kept although nothing requires it
 *
 * RFC 5545 §3.5: "This memo imposes no ordering of properties within an
 * iCalendar object." So the order carries no meaning — and it is kept all the
 * same, because a file that comes back with its lines shuffled is a file
 * somebody has to read again to see that nothing changed. R-VOBJ-05 wants a
 * round trip, and a round trip that reorders is one people do not trust.
 *
 * ## Case
 *
 * RFC 5545 §3.5: "Property names, parameter names, and enumerated parameter
 * values are case-insensitive." Looking one up honours that; the spelling it
 * was written with is kept.
 *
 * ## Why it can be changed
 *
 * A calendar object is a document people edit: an attendee accepts, a summary
 * is corrected, an alarm is added. An immutable tree would mean rebuilding a
 * whole calendar to change one line — so this is mutable, unlike the request
 * and response of the HTTP layer, which describe one moment and are done.
 */
#[CoversClass(Component::class)]
final class ComponentTest extends TestCase
{
    /**
     * A component has a name and, to begin with, nothing in it.
     */
    public function testHasANameAndNothingElseToBeginWith(): void
    {
        $component = new Component('VCALENDAR');

        self::assertSame('VCALENDAR', $component->name());
        self::assertSame([], $component->children());
    }

    /**
     * **Properties and components go in the same sequence**, because that is
     * what §3.6 describes and because the order they were written in is the
     * order they have to come back out in.
     */
    public function testKeepsPropertiesAndComponentsInTheOrderTheyWereAdded(): void
    {
        $component = $this->calendar();

        self::assertSame(
            ['VERSION', 'PRODID', 'VEVENT'],
            array_map(static fn (object $child): string => $child->name(), $component->children()),
        );
    }

    /**
     * RFC 5545 §3.6: a component may be called anything, `iana-comp` and
     * `x-comp` say so. A model that knew the names would turn away every
     * extension there will ever be.
     */
    public function testAComponentMayBeCalledAnything(): void
    {
        $component = new Component('VCALENDAR');

        $component->add(new Component('X-WILDLY-UNKNOWN'));

        self::assertSame('X-WILDLY-UNKNOWN', $component->component('X-WILDLY-UNKNOWN')?->name());
    }

    /**
     * One property, by name.
     */
    public function testAPropertyIsFoundByName(): void
    {
        self::assertSame('2.0', $this->calendar()->property('VERSION')?->value());
    }

    /**
     * **RFC 5545 §3.5: names are case-insensitive**, and the example it gives
     * is `DUE`, `due` and `Due`.
     */
    public function testAPropertyIsFoundWhateverItsSpelling(): void
    {
        self::assertSame('2.0', $this->calendar()->property('version')?->value());
    }

    /**
     * And one that is not there is null — an absent property and one whose
     * value is empty are different things.
     */
    public function testAPropertyThatIsNotThereIsNull(): void
    {
        self::assertNull($this->calendar()->property('METHOD'));
    }

    /**
     * **All of them, where a property may appear more than once.** An event
     * has as many `ATTENDEE` properties as it has attendees (RFC 5545 §3.6.1),
     * and a model answering with the first would hide the meeting.
     */
    public function testEveryPropertyOfAName(): void
    {
        $event = $this->event();

        self::assertSame(
            ['mailto:ada@example.com', 'mailto:bob@example.com'],
            array_map(static fn (Property $property): string => $property->value(), $event->properties('ATTENDEE')),
        );
    }

    /**
     * And all of them together, where no name is given.
     */
    public function testEveryPropertyAtOnce(): void
    {
        self::assertCount(4, $this->event()->properties());
    }

    /**
     * **A component is not a property, whatever it is called.** Nothing in
     * either grammar stops an extension from naming a component the way
     * somebody else named a property, and a caller asking for the properties
     * of a calendar must not be handed its events.
     */
    public function testAComponentIsNeverAnsweredAsAProperty(): void
    {
        $calendar = $this->calendar();

        self::assertSame([], $calendar->properties('VEVENT'));
        self::assertCount(2, $calendar->properties(), 'the calendar has two of its own and one event');
    }

    /**
     * A component, by name and in full.
     */
    public function testComponentsAreFoundTheSameWay(): void
    {
        $calendar = $this->calendar();

        $calendar->add(new Component('VEVENT'));

        self::assertCount(2, $calendar->components('VEVENT'));
        self::assertCount(2, $calendar->components());
    }

    /**
     * **Only the children, never the grandchildren.** A `VALARM` inside a
     * `VEVENT` is not a component of the calendar, and a search that reached
     * through would answer that a calendar has an alarm — which is not a
     * thing calendars have.
     */
    public function testWhatIsInsideAChildIsNotAChild(): void
    {
        $calendar = $this->calendar();
        $event = $calendar->component('VEVENT');

        self::assertInstanceOf(Component::class, $event);
        $event->add(new Component('VALARM'));

        self::assertNull($calendar->component('VALARM'));
        self::assertSame('VALARM', $event->component('VALARM')?->name());
    }

    /**
     * **R-VOBJ-07: ergonomic access.** `$calendar['VEVENT']` is what a caller
     * writes, and it answers the first child of that name — property or
     * component, whichever it is. One rule, and no guessing about which of
     * the two somebody meant.
     */
    public function testTheFirstChildOfANameIsReachedByArrayAccess(): void
    {
        $calendar = $this->calendar();

        self::assertInstanceOf(Component::class, $calendar['VEVENT']);
        self::assertInstanceOf(Property::class, $calendar['version']);
    }

    /**
     * A name nothing is called answers null rather than raising, because
     * `$calendar['METHOD']` is a question and null is its answer.
     */
    public function testANameNothingIsCalledAnswersNull(): void
    {
        self::assertNull($this->calendar()['METHOD']);
    }

    /**
     * `isset()` says whether there is one, without fetching it.
     */
    public function testWhetherAChildIsThereIsAskedWithIsset(): void
    {
        $calendar = $this->calendar();

        self::assertTrue(isset($calendar['vevent']));
        self::assertFalse(isset($calendar['VTODO']));
    }

    /**
     * **`$component[] = $child` appends**, which is what the empty brackets
     * mean everywhere else in PHP and reads better than `add()` in a run of
     * them.
     */
    public function testTheEmptyBracketsAppend(): void
    {
        $calendar = new Component('VCALENDAR');

        $calendar[] = new Property('VERSION', '2.0');

        self::assertSame('2.0', $calendar->property('VERSION')?->value());
    }

    /**
     * **Setting by name replaces in place.** The one that was there makes way
     * for the new one **where it stood**, and any others of that name go — so
     * correcting a property does not move it to the end of the file. That is
     * what R-VOBJ-05's round trip is worth: a diff that shows the one line
     * somebody changed.
     */
    public function testSettingByNameReplacesInPlace(): void
    {
        $calendar = $this->calendar();

        $calendar['VERSION'] = new Property('VERSION', '2.1');

        self::assertSame(
            ['VERSION', 'PRODID', 'VEVENT'],
            array_map(static fn (object $child): string => $child->name(), $calendar->children()),
        );
        self::assertSame('2.1', $calendar->property('VERSION')?->value());
    }

    /**
     * And where there were several of that name, the rest go with it: that is
     * what replacing means.
     */
    public function testSettingByNameLeavesNoOthersOfThatName(): void
    {
        $event = $this->event();

        $event['ATTENDEE'] = new Property('ATTENDEE', 'mailto:cleo@example.com');

        self::assertCount(1, $event->properties('ATTENDEE'));
    }

    /**
     * A name nothing is called yet is simply added.
     */
    public function testSettingANameNothingIsCalledAddsIt(): void
    {
        $calendar = $this->calendar();

        $calendar['METHOD'] = new Property('METHOD', 'REQUEST');

        self::assertSame('REQUEST', $calendar->property('METHOD')?->value());
    }

    /**
     * `unset()` takes away every child of that name.
     */
    public function testUnsetTakesAwayEveryChildOfThatName(): void
    {
        $event = $this->event();

        unset($event['attendee']);

        self::assertSame([], $event->properties('ATTENDEE'));
        self::assertCount(2, $event->children(), 'and leaves the rest where they were');
    }

    /**
     * Taking away a name nothing is called is not an error, the same as
     * everywhere else in PHP.
     */
    public function testUnsettingANameNothingIsCalledDoesNothing(): void
    {
        $calendar = $this->calendar();

        unset($calendar['METHOD']);

        self::assertCount(3, $calendar->children());
    }

    /**
     * **Iterating gives every child in order**, properties and components
     * alike, which is what walking an object means.
     */
    public function testIteratingGivesEveryChildInOrder(): void
    {
        $names = [];

        foreach ($this->calendar() as $child) {
            $names[] = $child->name();
        }

        self::assertSame(['VERSION', 'PRODID', 'VEVENT'], $names);
    }

    /**
     * **And one walk does not disturb another.** A calendar is walked while
     * its events are walked inside it — which is what every serialiser and
     * every validator does — so the component must not carry a cursor of its
     * own.
     */
    public function testOneWalkDoesNotDisturbAnother(): void
    {
        $calendar = $this->calendar();
        $seen = [];

        foreach ($calendar as $outer) {
            foreach ($calendar as $inner) {
                $seen[] = $outer->name() . '/' . $inner->name();
            }
        }

        self::assertSame([
            'VERSION/VERSION', 'VERSION/PRODID', 'VERSION/VEVENT',
            'PRODID/VERSION', 'PRODID/PRODID', 'PRODID/VEVENT',
            'VEVENT/VERSION', 'VEVENT/PRODID', 'VEVENT/VEVENT',
        ], $seen);
    }

    /**
     * A calendar as RFC 5545 §3.4's own example has it: two properties of the
     * calendar itself, then an event.
     */
    private function calendar(): Component
    {
        $calendar = new Component('VCALENDAR');

        $calendar->add(new Property('VERSION', '2.0'));
        $calendar->add(new Property('PRODID', '-//hacksw/handcal//NONSGML v1.0//EN'));
        $calendar->add($this->event());

        return $calendar;
    }

    /**
     * An event with two attendees, because a meeting is where one property
     * appearing twice stops being a special case.
     */
    private function event(): Component
    {
        $event = new Component('VEVENT');

        $event->add(new Property('UID', '19970610T172345Z-AF23B2@example.com'));
        $event->add(new Property('SUMMARY', 'Bastille Day Party'));
        $event->add(new Property('ATTENDEE', 'mailto:ada@example.com'));
        $event->add(new Property('ATTENDEE', 'mailto:bob@example.com'));

        return $event;
    }
}
