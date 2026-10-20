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

use DateTimeImmutable;
use DateTimeZone;
use DavServices\CalDav\CalendarObject;
use DavServices\CalDav\Moments;
use DavServices\CalDav\TimeZone;
use DavServices\VObject\Component;
use DavServices\VObject\TimeZone\Definition;
use DavServices\VObject\Value\DateTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What moment a dated property names, read in UTC.
 *
 * The question RFC 4791 §9.9 asks of every property in its tables, and §7.3 is
 * what makes it a question at all: a value may be a UTC time, a local time in
 * a named zone, or a floating one that is no instant until somebody picks a
 * zone for it.
 */
#[CoversClass(Moments::class)]
final class MomentsTest extends TestCase
{
    /**
     * A zone no name on earth fits: five hours and thirty-seven minutes is an
     * offset nothing in the database uses, so nothing can be matched to it.
     */
    private const OLYMPUS = "BEGIN:VTIMEZONE\r\nTZID:Mars/Olympus\r\nBEGIN:STANDARD\r\n"
        . "DTSTART:19671029T020000\r\nTZOFFSETFROM:+0537\r\nTZOFFSETTO:+0537\r\n"
        . "END:STANDARD\r\nEND:VTIMEZONE\r\n";

    /** §5.2.2's example, shortened to the one observance it needs. */
    private const EASTERN = "BEGIN:VTIMEZONE\r\nTZID:US-Eastern\r\nBEGIN:STANDARD\r\n"
        . "DTSTART:19671029T020000\r\nTZOFFSETFROM:-0400\r\nTZOFFSETTO:-0500\r\n"
        . "END:STANDARD\r\nEND:VTIMEZONE\r\n";

    /**
     * **A UTC value is the moment it says.** RFC 5545 §3.3.5's third form —
     * "UTC TIME: The date with UTC time, or absolute time" — needs nobody's
     * help.
     */
    public function testAUtcValueIsTheMomentItSays(): void
    {
        self::assertSame(
            '2026-01-05T12:00:00+00:00',
            $this->momentOf("DTSTART:20260105T120000Z\r\n")?->format('c'),
        );
    }

    /**
     * **A floating value is read in the zone the caller picked**, which is
     * §7.3's own answer: "If the CALDAV:timezone XML element is not specified
     * in the request body, CalDAV servers MUST rely on the value of the
     * CALDAV:calendar-timezone property, if defined, or else the CalDAV
     * servers MAY rely on the time zone of their choice."
     *
     * Which of those three it is, is the caller's business; this takes the one
     * it was handed.
     */
    public function testAFloatingValueIsReadInTheZoneTheCallerPicked(): void
    {
        self::assertSame(
            '2026-01-05T11:00:00+00:00',
            $this->momentOf("DTSTART:20260105T120000\r\n", 'Europe/Berlin')?->format('c'),
        );
    }

    /**
     * **A value in a zone the object carries is read in that zone.**
     * §3.2.19: "This property parameter specifies a text value that uniquely
     * identifies the 'VTIMEZONE' calendar component to be used when evaluating
     * the time portion of the property."
     */
    public function testAValueInACarriedZoneIsReadInIt(): void
    {
        self::assertSame(
            '2026-01-05T17:00:00+00:00',
            $this->momentOf("DTSTART;TZID=US-Eastern:20260105T120000\r\n", 'UTC', self::EASTERN)?->format('c'),
        );
    }

    /**
     * **A zone nothing names and nothing carries leaves the value floating.**
     * That is what {@see \DavServices\VObject\TimeZone\Zones} answers — null,
     * "never UTC" — and §9.9 still has to compare the value against a range,
     * so the caller's zone is what is left. Guessing UTC instead would move an
     * appointment by hours without saying so.
     */
    public function testAZoneNobodyKnowsLeavesTheValueFloating(): void
    {
        self::assertSame(
            '2026-01-05T11:00:00+00:00',
            $this->momentOf("DTSTART;TZID=Mars/Olympus:20260105T120000\r\n", 'Europe/Berlin')?->format('c'),
        );
    }

    /**
     * **An all-day value is midnight in that zone.** RFC 5545 §3.3.4 gives a
     * DATE no time at all, and §7.3 calls it a "floating date" — so it becomes
     * an instant the same way a floating time does, which is the conversion
     * R-TZ-04 keeps out of the VObject layer and §7.3 puts here.
     */
    public function testAnAllDayValueIsMidnightInThatZone(): void
    {
        self::assertSame(
            '2026-01-04T23:00:00+00:00',
            $this->momentOf("DTSTART;VALUE=DATE:20260105\r\n", 'Europe/Berlin')?->format('c'),
        );
    }

    /**
     * **Eight digits are a date, parameter or none.** §3.3.4's own form is
     * `date-fullyear date-month date-mday`, so the length says what the value
     * is even where a writer left the `VALUE` parameter out — which clients
     * do.
     */
    public function testEightDigitsAreADateEvenWithoutTheParameter(): void
    {
        self::assertSame(
            '2026-01-04T23:00:00+00:00',
            $this->momentOf("DTSTART:20260105\r\n", 'Europe/Berlin')?->format('c'),
        );
    }

    /**
     * **And the parameter decides where the two disagree** (§3.2.20): a
     * `VALUE=DATE-TIME` on a value that is eight digits is a value this
     * library cannot read, rather than a date it guessed at.
     */
    public function testTheParameterDecidesWhereTheyDisagree(): void
    {
        self::assertNull($this->momentOf("DTSTART;VALUE=DATE-TIME:20260105\r\n"));
    }

    /**
     * A value it cannot read names no moment, and says so rather than
     * throwing: §9.9 is a filter, and a filter that fell over on one bad
     * object in a calendar would answer nothing about the other thousand.
     */
    public function testAValueItCannotReadNamesNoMoment(): void
    {
        self::assertNull($this->momentOf("DTSTART:not-a-date\r\n"));
    }

    /**
     * **A local time that does not exist names no moment.** The night a zone
     * springs forward has an hour nobody can point at — Berlin goes from
     * 02:00 to 03:00 on 29 March 2026 — and
     * {@see \DavServices\VObject\Recur\Zone::momentOf()} says so by answering
     * null rather than picking one of the two sides.
     *
     * §9.9 then has nothing to compare, which is the honest answer: an
     * appointment at a time that did not happen is in no range.
     */
    public function testALocalTimeThatDoesNotExistNamesNoMoment(): void
    {
        self::assertNull($this->momentOf("DTSTART:20260329T023000\r\n", 'Europe/Berlin'));
    }

    /**
     * **A `VALUE` parameter is read whatever its case.** RFC 5545 §3.1: "The
     * property name, parameter names and enumerated parameter values are
     * case-insensitive" — and `DATE` is an enumerated value of `VALUE`
     * (§3.2.20).
     */
    public function testTheValueParameterIsReadWhateverItsCase(): void
    {
        $object = $this->objectOf(
            "BEGIN:VEVENT\r\nUID:1@example.com\r\ndtstart;value=date:20260105\r\nEND:VEVENT\r\n",
        );
        $start = $object->component('VEVENT')?->property('DTSTART');

        self::assertNotNull($start);
        self::assertTrue(Moments::isADate($start));
    }

    /**
     * **A time zone component without a `TZID` defines nothing**, and the walk
     * past it must not fall over: RFC 5545 §3.6.5 makes the property
     * mandatory, so such a component is invalid iCalendar — and a store may
     * hold one.
     */
    public function testAZoneWithoutATzidIsWalkedPast(): void
    {
        $nameless = "BEGIN:VTIMEZONE\r\nBEGIN:STANDARD\r\nDTSTART:19671029T020000\r\n"
            . "TZOFFSETFROM:-0400\r\nTZOFFSETTO:-0500\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\n";

        self::assertSame(
            '2026-01-05T11:00:00+00:00',
            $this->momentOf(
                "DTSTART;TZID=US-Eastern:20260105T120000\r\n",
                'Europe/Berlin',
                $nameless,
            )?->format('c'),
        );
    }

    /**
     * And a property that is not there names none either, which is what every
     * row of §9.9's tables asks first.
     */
    public function testAPropertyThatIsNotThereNamesNoMoment(): void
    {
        $object = $this->objectOf("BEGIN:VEVENT\r\nUID:1@example.com\r\nEND:VEVENT\r\n");
        $moments = new Moments($object, new DateTimeZone('UTC'));

        self::assertNull($moments->of($object->component('VEVENT')?->property('DTEND')));
    }

    /**
     * **A date and a time are told apart for the callers that need it**, which
     * is §9.9's own question: the duration of an event or a journal entry with
     * nothing but a `DTSTART` is "1 day (+P1D) when the DTSTART is a DATE
     * value, and 0 seconds when the DTSTART is a DATE-TIME value".
     */
    public function testItSaysWhetherAValueIsADate(): void
    {
        $object = $this->objectOf(
            "BEGIN:VEVENT\r\nUID:1@example.com\r\nDTSTART;VALUE=DATE:20260105\r\n"
            . "DTEND:20260106T120000Z\r\nEND:VEVENT\r\n",
        );
        $event = $object->component('VEVENT');

        self::assertNotNull($event);

        $start = $event->property('DTSTART');
        $end = $event->property('DTEND');

        self::assertNotNull($start);
        self::assertNotNull($end);
        self::assertTrue(Moments::isADate($start));
        self::assertFalse(Moments::isADate($end));
    }

    /**
     * **A name the system knows needs no definition carried with it.**
     * R-TZ-02 prefers it: a named zone knows the whole history, while a
     * definition only describes the span its writer wrote about. So an object
     * that names `Europe/Berlin` is read in Berlin whether it carries a
     * `VTIMEZONE` or not.
     */
    public function testANameTheSystemKnowsNeedsNoDefinition(): void
    {
        self::assertSame(
            '2026-01-05T11:00:00+00:00',
            $this->momentOf("DTSTART;TZID=Europe/Berlin:20260105T120000\r\n")?->format('c'),
        );
    }

    /**
     * **A definition may name a zone its `TZID` does not.** libical writes an
     * `X-LIC-LOCATION` beside a `TZID` it knows is not IANA's, and Outlook
     * writes names like "Customized Time Zone" — so the definition is where
     * the real name is, and {@see \DavServices\VObject\TimeZone\Resolver}
     * looks there before it gives up.
     */
    public function testADefinitionMayNameAZoneItsTzidDoesNot(): void
    {
        $located = "BEGIN:VTIMEZONE\r\nTZID:Customized Time Zone\r\nX-LIC-LOCATION:Europe/Berlin\r\n"
            . "BEGIN:STANDARD\r\nDTSTART:19671029T020000\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\n"
            . "END:STANDARD\r\nEND:VTIMEZONE\r\n";

        self::assertSame(
            '2026-01-05T11:00:00+00:00',
            $this->momentOf("DTSTART;TZID=Customized Time Zone:20260105T120000\r\n", 'UTC', $located)?->format('c'),
        );
    }

    /**
     * **A `TZID` that says nothing names nothing.** §3.2.19's form is
     * `tzidparam = "TZID" "=" [tzidprefix] paramtext`, and `paramtext` is one
     * character or more — so an empty one is no identifier, and the value is
     * floating like any other without a parameter.
     */
    public function testAnEmptyZoneNamesNothing(): void
    {
        self::assertSame(
            '2026-01-05T11:00:00+00:00',
            $this->momentOf("DTSTART;TZID=:20260105T120000\r\n", 'Europe/Berlin')?->format('c'),
        );
    }

    /**
     * **The right definition of several is the one that names it**, matched
     * exactly: §3.2.19 has it that "The value of the 'TZID' property parameter
     * will be equal to the value of the 'TZID' property for the matching time
     * zone definition", and §3.1 makes property values case-sensitive.
     */
    public function testTheRightDefinitionOfSeveralIsTheOneThatNamesIt(): void
    {
        self::assertSame(
            '2026-01-05T17:00:00+00:00',
            $this->momentOf(
                "DTSTART;TZID=US-Eastern:20260105T120000\r\n",
                'UTC',
                self::OLYMPUS . self::EASTERN,
            )?->format('c'),
        );
    }

    /**
     * **A definition nothing can be named after is read as it stands.**
     * R-TZ-02 prefers a named zone, because "a named zone knows the whole
     * history" while a definition only describes the span its writer wrote
     * about — but where no name fits, the definition is all there is, and
     * reading it is better than reading the value as floating.
     *
     * The offset here fits no zone on earth, which is what makes the case:
     * nothing can be matched to it.
     */
    public function testADefinitionNothingCanBeNamedAfterIsReadAsItStands(): void
    {
        self::assertSame(
            '2026-01-05T06:23:00+00:00',
            $this->momentOf("DTSTART;TZID=Mars/Olympus:20260105T120000\r\n", 'UTC', self::OLYMPUS)?->format('c'),
        );
    }

    /**
     * **A zone the request supplies need not be one PHP can name.** §7.3 makes
     * the `CALDAV:timezone` element the first source a report reads, and §9.8
     * has the server "rely on the specified VTIMEZONE component" — so the
     * element's own observances are the zone, whatever its `TZID` is called.
     * §5.2.2's example calls it `US-Eastern`, which is no name in the IANA
     * database, so a reader that insisted on a nameable zone would refuse the
     * memo's own spelling.
     *
     * The answer comes back in UTC, as every answer here does.
     */
    public function testAFloatingValueIsReadInAZoneNothingCanName(): void
    {
        self::assertSame(
            '2026-01-05T06:23:00+00:00',
            $this->momentInTheZone("DTSTART:20260105T120000\r\n", self::OLYMPUS)?->format('c'),
        );
    }

    /**
     * And an all-day value in such a zone is its midnight, which is the
     * conversion §7.3 asks for in the same breath: "to perform the proper
     * conversion of 'floating date' and 'floating time' values to date with
     * UTC time values".
     */
    public function testAnAllDayValueIsMidnightInAZoneNothingCanName(): void
    {
        self::assertSame(
            '2026-01-04T18:23:00+00:00',
            $this->momentInTheZone("DTSTART;VALUE=DATE:20260105\r\n", self::OLYMPUS)?->format('c'),
        );
    }

    /**
     * **And a value that carries its own `TZID` still wins**, because §3.2.19
     * puts the zone on the property: the zone chosen for the report is for
     * what floats, and a value that names a zone is not floating.
     */
    public function testAValueThatNamesItsOwnZoneIsNotReadInTheChosenOne(): void
    {
        self::assertSame(
            '2026-01-05T17:00:00+00:00',
            $this->momentInTheZone(
                "DTSTART;TZID=US-Eastern:20260105T120000\r\n",
                self::OLYMPUS,
                self::EASTERN,
            )?->format('c'),
        );
    }

    /**
     * **One instance of a series, read like the component's own start.** A
     * recurrence is a wall clock repeated — RFC 5545 §3.3.10 and §3.8.5.3 both
     * put the arithmetic there — so the value changes and the zone does not:
     * §3.2.19 puts the `TZID` on the property, and the instance is the same
     * property with another value.
     *
     * Asked for by {@see \DavServices\CalDav\Filter\CompFilter}, which needs
     * the instant an instance begins in order to ask §9.9's tables about it
     * without writing the instance out.
     */
    public function testAnInstanceIsReadLikeTheComponentsOwnStart(): void
    {
        self::assertSame(
            '2026-01-12T17:00:00+00:00',
            $this->instanceAt(
                "DTSTART;TZID=US-Eastern:20260105T120000\r\n",
                '20260112T120000',
                self::EASTERN,
            )?->format('c'),
        );
    }

    /**
     * **A component that says nowhere it begins puts no instance anywhere.**
     * RFC 5545 §3.8.5.1 computes a series "from the DTSTART property", so
     * without one there is no property to write an instance like — and null is
     * the answer every other reading here gives to a question with no value
     * behind it.
     */
    public function testAComponentWithNoStartPutsNoInstanceAnywhere(): void
    {
        self::assertNull($this->instanceAt("SUMMARY:Some day\r\n", '20260112T120000'));
    }

    /**
     * **And an instance at a local time that does not exist is no moment.**
     * §3.3.10: "Recurrence rules may generate recurrence instances with an
     * invalid date (e.g., February 30) or nonexistent local time (e.g., 1:30
     * AM on a day where the local time is moved forward by an hour at 1:00
     * AM). Such recurrence instances MUST be ignored and MUST NOT be counted
     * as part of the recurrence set."
     *
     * The clocks in New York go forward at two on 8 March 2026, so half past
     * two never comes that morning.
     */
    public function testAnInstanceAtALocalTimeThatDoesNotExistIsNoMoment(): void
    {
        self::assertNull($this->instanceAt(
            "DTSTART;TZID=America/New_York:20260307T023000\r\n",
            '20260308T023000',
        ));
    }

    /**
     * The moment one instance of a series begins, given the component's own
     * start and where the series put that instance.
     *
     * @param string $line The component's `DTSTART`, or something else where
     *                     the case is about there being none
     * @param string $start Where the instance begins, as the series put it
     * @param string $carried What the object carries beside the event
     */
    private function instanceAt(string $line, string $start, string $carried = ''): ?DateTimeImmutable
    {
        $object = $this->objectOf(
            $carried . "BEGIN:VEVENT\r\nUID:1@example.com\r\n" . $line . "END:VEVENT\r\n",
        );
        $event = $object->component('VEVENT') ?? self::fail('The case built an object with no event in it.');

        return (new Moments($object, new DateTimeZone('UTC')))->ofTheInstance($event, DateTime::decode($start));
    }

    /**
     * The moment a property names when what floats is read in a `VTIMEZONE`'s
     * own observances rather than in a named zone.
     *
     * @param string $line The property the case is about
     * @param string $chosen The `VTIMEZONE` the report is to rely on
     * @param string $carried What the object carries beside the event
     */
    private function momentInTheZone(string $line, string $chosen, string $carried = ''): ?DateTimeImmutable
    {
        $object = $this->objectOf(
            $carried . "BEGIN:VEVENT\r\nUID:1@example.com\r\n" . $line . "END:VEVENT\r\n",
        );
        $definition = Definition::of(
            TimeZone::in($this->wrapping($chosen)) ?? self::fail('The case built something that is no time zone.'),
        ) ?? self::fail('The case built a time zone nothing can be resolved with.');

        return (new Moments($object, $definition))->of($object->component('VEVENT')?->property('DTSTART'));
    }

    private function momentOf(string $line, string $zone = 'UTC', string $carried = ''): ?DateTimeImmutable
    {
        $object = $this->objectOf(
            $carried . "BEGIN:VEVENT\r\nUID:1@example.com\r\n" . $line . "END:VEVENT\r\n",
        );
        $moments = new Moments($object, new DateTimeZone($zone));

        return $moments->of($object->component('VEVENT')?->property('DTSTART'));
    }

    private function objectOf(string $components): Component
    {
        return CalendarObject::in($this->wrapping($components))
            ?? self::fail('The case built something that is no calendar object.');
    }

    private function wrapping(string $components): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\n"
            . $components . "END:VCALENDAR\r\n";
    }
}
