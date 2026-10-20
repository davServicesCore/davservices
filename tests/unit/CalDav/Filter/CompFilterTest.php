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
 * A condition on a component, and on everything inside it (RFC 4791 §9.7.1).
 *
 *     <!ELEMENT comp-filter (is-not-defined | (time-range?,
 *                            prop-filter*, comp-filter*))>
 *     <!ATTLIST comp-filter name CDATA #REQUIRED>
 *
 * **The scope is what makes this one recursive**, and §9.7.1 says it in two
 * sentences: "The scope of the CALDAV:comp-filter XML element is the calendar
 * object when used as a child of the CALDAV:filter XML element. The scope of
 * the CALDAV:comp-filter XML element is the enclosing calendar component when
 * used as a child of another CALDAV:comp-filter XML element."
 */
#[CoversClass(CompFilter::class)]
final class CompFilterTest extends TestCase
{
    private const MEETING = "BEGIN:VEVENT\r\nUID:1@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
        . "DTSTART:20260105T120000Z\r\nDTEND:20260105T130000Z\r\nSUMMARY:A Meeting\r\nEND:VEVENT\r\n";

    /**
     * **The outermost filter is about the object itself.** §9.7.1's first
     * sentence puts its scope at the calendar object, so the name a client
     * sends there is `VCALENDAR` and the question is whether the object is
     * one.
     */
    public function testTheOutermostFilterIsAboutTheObject(): void
    {
        self::assertTrue($this->holds($this->filter('VCALENDAR'), self::MEETING));
        self::assertFalse($this->holds($this->filter('VTODO'), self::MEETING));
    }

    /**
     * **§9.7.1: "The CALDAV:comp-filter XML element is empty and the calendar
     * object or calendar component type specified by the 'name' attribute
     * exists in the current scope".**
     */
    public function testAnEmptyNestedFilterAsksWhetherTheKindIsThere(): void
    {
        self::assertTrue($this->holds($this->filter('VCALENDAR', $this->comp('VEVENT')), self::MEETING));
        self::assertFalse($this->holds($this->filter('VCALENDAR', $this->comp('VTODO')), self::MEETING));
    }

    /**
     * **§9.7.1: "contains a CALDAV:is-not-defined XML element and the calendar
     * object or calendar component type specified by the 'name' attribute does
     * not exist in the current scope".**
     */
    public function testIsNotDefinedAsksTheOtherWayRound(): void
    {
        $noToDo = $this->filter('VCALENDAR', $this->comp('VTODO', new Element(ParamFilter::NOT_DEFINED)));
        $noEvent = $this->filter('VCALENDAR', $this->comp('VEVENT', new Element(ParamFilter::NOT_DEFINED)));

        self::assertTrue($this->holds($noToDo, self::MEETING));
        self::assertFalse($this->holds($noEvent, self::MEETING));
    }

    /**
     * **It nests as deep as a client likes** (R-CAL-10 asks for "beliebig
     * verschachtelt"), and the scope moves down with it: a `VALARM` filter
     * inside a `VEVENT` filter is about the alarms of that event.
     */
    public function testItNestsAsDeepAsAClientLikes(): void
    {
        $withAlarm = str_replace(
            "END:VEVENT\r\n",
            "BEGIN:VALARM\r\nACTION:DISPLAY\r\nDESCRIPTION:x\r\nTRIGGER:-PT15M\r\nEND:VALARM\r\nEND:VEVENT\r\n",
            self::MEETING,
        );
        $filter = $this->filter('VCALENDAR', $this->comp('VEVENT', $this->comp('VALARM')));

        self::assertTrue($this->holds($filter, $withAlarm));
        self::assertFalse($this->holds($filter, self::MEETING));
    }

    /**
     * **§9.7.1: "only contains CALDAV:prop-filter and CALDAV:comp-filter child
     * XML elements that all match the targeted calendar component".**
     *
     * All of them: RFC 4791 gives a `comp-filter` no `test` attribute, so
     * there is no asking for any of several.
     */
    public function testEveryChildFilterHasToMatch(): void
    {
        $both = $this->filter(
            'VCALENDAR',
            $this->comp('VEVENT', $this->propFilter('SUMMARY'), $this->propFilter('DTSTART')),
        );
        $oneMissing = $this->filter(
            'VCALENDAR',
            $this->comp('VEVENT', $this->propFilter('SUMMARY'), $this->propFilter('LOCATION')),
        );

        self::assertTrue($this->holds($both, self::MEETING));
        self::assertFalse($this->holds($oneMissing, self::MEETING));
    }

    /**
     * **§9.7.1: "contains a CALDAV:time-range XML element and at least one
     * recurrence instance in the targeted calendar component is scheduled to
     * overlap the specified time range".**
     */
    public function testATimeRangeAsksWhenTheComponentIs(): void
    {
        $inside = $this->filter(
            'VCALENDAR',
            $this->comp('VEVENT', $this->range('20260105T120000Z', '20260105T130000Z')),
        );
        $elsewhere = $this->filter(
            'VCALENDAR',
            $this->comp('VEVENT', $this->range('20260201T000000Z', '20260202T000000Z')),
        );

        self::assertTrue($this->holds($inside, self::MEETING));
        self::assertFalse($this->holds($elsewhere, self::MEETING));
    }

    /**
     * **"At least one recurrence instance" is the whole of that sentence.** A
     * weekly meeting whose first instance is in January is in a range in March
     * because one of its instances is — and a filter that asked only the
     * component as written would answer that a client's recurring appointment
     * does not exist.
     */
    public function testOneInstanceOfASeriesIsEnough(): void
    {
        $weekly = str_replace("SUMMARY:", "RRULE:FREQ=WEEKLY\r\nSUMMARY:", self::MEETING);
        $inMarch = $this->filter(
            'VCALENDAR',
            $this->comp('VEVENT', $this->range('20260302T000000Z', '20260303T000000Z')),
        );

        // 5 January 2026 is a Monday, so the weekly series falls on 2 March.
        self::assertTrue($this->holds($inMarch, $weekly));
    }

    /**
     * And a range no instance reaches is still a no: the series is weekly on a
     * Monday, and this asks about a Tuesday.
     */
    public function testARangeNoInstanceReachesIsStillANo(): void
    {
        $weekly = str_replace("SUMMARY:", "RRULE:FREQ=WEEKLY\r\nSUMMARY:", self::MEETING);
        $onATuesday = $this->filter(
            'VCALENDAR',
            $this->comp('VEVENT', $this->range('20260303T000000Z', '20260304T000000Z')),
        );

        self::assertFalse($this->holds($onATuesday, $weekly));
    }

    /**
     * **An overridden instance is asked where it is**, not where the rule says
     * it would have been. §4.1 keeps a master and its overrides in one
     * resource, and an override that was moved into the range puts the object
     * in the range.
     */
    public function testAnOverriddenInstanceIsAskedWhereItIs(): void
    {
        $moved = str_replace("SUMMARY:", "RRULE:FREQ=WEEKLY\r\nSUMMARY:", self::MEETING)
            . "BEGIN:VEVENT\r\nUID:1@example.com\r\nRECURRENCE-ID:20260112T120000Z\r\n"
            . "DTSTAMP:20260101T000000Z\r\nDTSTART:20260601T090000Z\r\nDTEND:20260601T100000Z\r\n"
            . "SUMMARY:A Meeting, moved\r\nEND:VEVENT\r\n";
        $inJune = $this->filter(
            'VCALENDAR',
            $this->comp('VEVENT', $this->range('20260601T000000Z', '20260602T000000Z')),
        );

        self::assertTrue($this->holds($inJune, $moved));
    }

    /**
     * **A time range and the other children are asked together**, which
     * §9.7.1's third bullet spells out: the instance overlaps "and all
     * specified CALDAV:prop-filter and CALDAV:comp-filter child XML elements
     * also match the targeted calendar component".
     */
    public function testATimeRangeAndTheOtherChildrenAreAskedTogether(): void
    {
        $matching = $this->filter(
            'VCALENDAR',
            $this->comp(
                'VEVENT',
                $this->range('20260105T120000Z', '20260105T130000Z'),
                $this->propFilter('SUMMARY', $this->match('meeting')),
            ),
        );
        $wrongSummary = $this->filter(
            'VCALENDAR',
            $this->comp(
                'VEVENT',
                $this->range('20260105T120000Z', '20260105T130000Z'),
                $this->propFilter('SUMMARY', $this->match('gathering')),
            ),
        );

        self::assertTrue($this->holds($matching, self::MEETING));
        self::assertFalse($this->holds($wrongSummary, self::MEETING));
    }

    /**
     * **One component has to satisfy the whole condition.** Two events, one
     * with the summary and one in the range, do not between them satisfy a
     * filter that asks for both — §9.7.1 asks about "the targeted calendar
     * component", singular.
     */
    public function testOneComponentHasToSatisfyTheWholeCondition(): void
    {
        $two = self::MEETING . "BEGIN:VEVENT\r\nUID:2@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART:20260201T090000Z\r\nDTEND:20260201T100000Z\r\nSUMMARY:A gathering\r\nEND:VEVENT\r\n";
        $crossed = $this->filter(
            'VCALENDAR',
            $this->comp(
                'VEVENT',
                $this->range('20260201T000000Z', '20260202T000000Z'),
                $this->propFilter('SUMMARY', $this->match('A Meeting')),
            ),
        );

        self::assertFalse($this->holds($crossed, $two));
    }

    /**
     * The name is read whatever its case (RFC 5545 §3.1).
     */
    public function testTheNameIsReadWhateverItsCase(): void
    {
        self::assertTrue($this->holds($this->filter('vcalendar', $this->comp('vevent')), self::MEETING));
    }

    /**
     * §9.7.1 makes the attribute `#REQUIRED`.
     */
    public function testAFilterWithoutANameIsRefused(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('name');

        CompFilter::of(new Element(CompFilter::NAME));
    }

    /**
     * **And a nested one without a name is the DTD's refusal, not §7.8's.**
     * Which component a filter is about is what decides whether it can be
     * nested at all, so a filter that names none is unanswerable for the
     * plainer reason — `#REQUIRED` — and `400` says so where `403` with a
     * precondition would tell a client its nesting was impossible.
     */
    public function testANestedFilterWithoutANameIsRefused(): void
    {
        $element = new Element(CompFilter::NAME, ['name' => 'VEVENT']);

        $element->append(new Element(CompFilter::NAME));

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('name');

        CompFilter::of($element);
    }

    /**
     * `is-not-defined` is a choice against everything else.
     */
    public function testIsNotDefinedBesideAnythingElseIsRefused(): void
    {
        $element = new Element(CompFilter::NAME, ['name' => 'VEVENT']);

        $element->append(new Element(ParamFilter::NOT_DEFINED));
        $element->append($this->propFilter('SUMMARY'));

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('is-not-defined');

        CompFilter::of($element);
    }

    /**
     * One time range at most: the DTD writes `time-range?`.
     */
    public function testTwoTimeRangesAreRefused(): void
    {
        $element = new Element(CompFilter::NAME, ['name' => 'VEVENT']);

        $element->append($this->range('20260101T000000Z', null));
        $element->append($this->range('20260201T000000Z', null));

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('one time-range');

        CompFilter::of($element);
    }

    /**
     * And the order the DTD writes is the order it means: `(time-range?,
     * prop-filter*, comp-filter*)`.
     */
    public function testATimeRangeAfterAPropFilterIsRefused(): void
    {
        $element = new Element(CompFilter::NAME, ['name' => 'VEVENT']);

        $element->append($this->propFilter('SUMMARY'));
        $element->append($this->range('20260101T000000Z', null));

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('before');

        CompFilter::of($element);
    }

    public function testAPropFilterAfterACompFilterIsRefused(): void
    {
        $element = new Element(CompFilter::NAME, ['name' => 'VEVENT']);

        $element->append($this->comp('VALARM'));
        $element->append($this->propFilter('SUMMARY'));

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('before');

        CompFilter::of($element);
    }

    /**
     * **A component with no `UID` has no series to ask for, so it is asked as
     * it stands.** RFC 5545 §3.6.1 makes `UID` mandatory in a `VEVENT` and
     * §4.1 says the same of a calendar object resource, so such a component is
     * invalid iCalendar — and a filter that fell over on one would answer
     * nothing about the rest of a calendar that holds it.
     *
     * The range is asked of the component itself, which is why the answer is
     * the ordinary one of §9.9's first table rather than nothing.
     */
    public function testAComponentWithoutAUidIsAskedAsItStands(): void
    {
        $event = "BEGIN:VEVENT\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260105T120000Z\r\n"
            . "DTEND:20260105T130000Z\r\nSUMMARY:Nameless\r\nEND:VEVENT\r\n";

        self::assertTrue($this->holds(
            $this->filter('VCALENDAR', $this->comp('VEVENT', $this->range('20260105T120000Z', '20260105T130000Z'))),
            $event,
        ));
        self::assertFalse($this->holds(
            $this->filter('VCALENDAR', $this->comp('VEVENT', $this->range('20260201T000000Z', '20260202T000000Z'))),
            $event,
        ));
    }

    /**
     * **And a series whose own start names no moment is asked as it stands
     * too.** RFC 5545 §3.3.10 is the case: an instance at "nonexistent local
     * time (e.g., 1:30 AM on a day where the local time is moved forward by an
     * hour at 1:00 AM)" is no moment at all — and here it is the master's own
     * `DTSTART` that falls in such a gap, so there is no instant to measure
     * the other instances against.
     *
     * **The answer would be the same with one.** §9.9's table for a `VEVENT`
     * reads its `DTSTART`, so a component whose start is no moment overlaps
     * nothing however the range is moved. What is left is therefore not a
     * worse answer but the same one, reached without arithmetic on nothing.
     *
     * Finding such a series would mean writing its instances out with their
     * own resolved starts, which is what `CALDAV:expand` does (§9.6.5,
     * R-CAL-14).
     */
    public function testASeriesWhoseOwnStartIsNoMomentIsAskedAsItStands(): void
    {
        // Half past two on 8 March 2026 in New York: the clocks go forward at
        // two, so that wall clock never reads half past two that morning.
        $series = "BEGIN:VEVENT\r\nUID:1@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART;TZID=America/New_York:20260308T023000\r\n"
            . "DTEND;TZID=America/New_York:20260308T033000\r\n"
            . "RRULE:FREQ=DAILY;COUNT=3\r\nSUMMARY:Half past two\r\nEND:VEVENT\r\n";

        self::assertFalse($this->holds(
            $this->filter('VCALENDAR', $this->comp('VEVENT', $this->range('20260309T063000Z', '20260309T073000Z'))),
            $series,
        ));
    }

    /**
     * **An instance at a local time that does not exist is no instance.**
     * RFC 5545 §3.3.10: such an instance "MUST be ignored and MUST NOT be
     * counted as part of the recurrence set" — so a range that only it could
     * have overlapped matches nothing, and the component is asked as it stands
     * instead.
     *
     * Here the master is sound — half past two on 7 March 2026 in New York is
     * a time that comes — and it is the next morning's instance that falls in
     * the hour the clocks skip.
     */
    public function testAnInstanceAtALocalTimeThatDoesNotExistMatchesNothing(): void
    {
        $series = "BEGIN:VEVENT\r\nUID:1@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART;TZID=America/New_York:20260307T023000\r\n"
            . "DTEND;TZID=America/New_York:20260307T033000\r\n"
            . "RRULE:FREQ=DAILY;COUNT=3\r\nSUMMARY:Half past two\r\nEND:VEVENT\r\n";

        // Half past two on the 8th would have been 07:30 UTC, had it come.
        self::assertFalse($this->holds(
            $this->filter('VCALENDAR', $this->comp('VEVENT', $this->range('20260308T073000Z', '20260308T083000Z'))),
            $series,
        ));

        // And the instances that do come are found, or the case above would
        // pass on a series nothing matches at all.
        self::assertTrue($this->holds(
            $this->filter('VCALENDAR', $this->comp('VEVENT', $this->range('20260307T073000Z', '20260307T083000Z'))),
            $series,
        ));
    }

    /**
     * **§7.8's other example of an invalid filter**, and it is the first one
     * the memo gives: "a CALDAV:filter cannot nest a <C:comp name='VEVENT'>
     * element in a <C:comp name='VTODO'> element."
     *
     * RFC 5545 §3.6 is what makes it impossible rather than merely unlikely:
     * `eventc` holds `alarmc` and nothing else that begins and ends, and only
     * `icalbody` holds the five components a calendar is made of. So the
     * nesting describes a component that cannot exist, and "no matches" would
     * be an answer to a question nobody can ask.
     *
     * @param string $outer The enclosing component
     * @param string $inner The one nested in it
     */
    #[DataProvider('nestingsICalendarRulesOut')]
    public function testACompFilterNestedWhereThatComponentCannotOccurIsRefused(
        string $outer,
        string $inner,
    ): void {
        $element = new Element(CompFilter::NAME, ['name' => $outer]);

        $element->append($this->comp($inner));

        try {
            CompFilter::of($element);
        } catch (Forbidden $refusal) {
            self::assertSame(Filter::VALID, $refusal->errorElement());
            self::assertSame(403, $refusal->status());
            self::assertStringContainsString($inner, $refusal->getMessage());
            self::assertStringContainsString($outer, $refusal->getMessage());

            return;
        }

        self::fail(sprintf('A %s was taken inside a %s.', $inner, $outer));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nestingsICalendarRulesOut(): iterable
    {
        yield 'the memo\'s own example' => ['VTODO', 'VEVENT'];
        yield 'a calendar inside an event' => ['VEVENT', 'VCALENDAR'];
        yield 'an event inside an event' => ['VEVENT', 'VEVENT'];
        yield 'an alarm inside a journal entry' => ['VJOURNAL', 'VALARM'];
        yield 'an alarm inside an alarm' => ['VALARM', 'VALARM'];
        yield 'an observance outside its time zone' => ['VEVENT', 'STANDARD'];
        yield 'a time zone inside an event' => ['VEVENT', 'VTIMEZONE'];
        yield 'something inside free/busy time' => ['VFREEBUSY', 'VEVENT'];

        // RFC 5234 §2.3 again: the grammar's own spelling is case-insensitive,
        // so a quietly written filter nests the same impossible component.
        yield 'in the case a client wrote it in' => ['vtodo', 'vevent'];
    }

    /**
     * And every nesting §3.6 does write is taken, or the refusal above would
     * have closed the door on the filters §9.7.1 exists for.
     *
     * @param string $outer The enclosing component
     * @param string $inner The one nested in it
     */
    #[DataProvider('nestingsICalendarWrites')]
    public function testACompFilterNestedWhereThatComponentOccursIsTaken(string $outer, string $inner): void
    {
        $element = new Element(CompFilter::NAME, ['name' => $outer]);

        $element->append($this->comp($inner));

        CompFilter::of($element);

        $this->expectNotToPerformAssertions();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nestingsICalendarWrites(): iterable
    {
        foreach (['VEVENT', 'VTODO', 'VJOURNAL', 'VFREEBUSY', 'VTIMEZONE'] as $kind) {
            yield $kind . ' in a calendar' => ['VCALENDAR', $kind];
        }

        yield 'an alarm in an event' => ['VEVENT', 'VALARM'];
        yield 'an alarm in a to-do' => ['VTODO', 'VALARM'];
        yield 'a standard observance in a time zone' => ['VTIMEZONE', 'STANDARD'];
        yield 'a daylight observance in a time zone' => ['VTIMEZONE', 'DAYLIGHT'];
    }

    /**
     * **A component no memo here knows is not judged.** RFC 5545 §3.4 makes
     * `iana-comp` and `x-comp` part of a calendar object, and §3.6 does not
     * say what either holds — so a server has nothing to rule out, and a
     * refusal would be this library guessing at somebody else's extension.
     *
     * @param string $outer The enclosing component
     * @param string $inner The one nested in it
     */
    #[DataProvider('nestingsNothingHereKnows')]
    public function testACompFilterAboutAComponentNothingHereKnowsIsTaken(string $outer, string $inner): void
    {
        $element = new Element(CompFilter::NAME, ['name' => $outer]);

        $element->append($this->comp($inner));

        CompFilter::of($element);

        $this->expectNotToPerformAssertions();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nestingsNothingHereKnows(): iterable
    {
        yield 'an extension in a calendar' => ['VCALENDAR', 'X-WORKOUT'];
        yield 'an extension in an event' => ['VEVENT', 'X-WORKOUT'];
        yield 'an event in an extension' => ['X-WORKOUT', 'VEVENT'];
        yield 'an extension in an extension' => ['X-WORKOUT', 'X-SET'];
        yield 'a component a later memo may define' => ['VEVENT', 'PARTICIPANT'];
    }

    /**
     * A child the DTD does not name is refused rather than ignored.
     */
    public function testAChildTheDtdDoesNotNameIsRefused(): void
    {
        $element = new Element(CompFilter::NAME, ['name' => 'VEVENT']);

        $element->append($this->match('anything'));

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('text-match');

        CompFilter::of($element);
    }

    /**
     * **A series this library cannot expand is asked as it stands.** An
     * `RRULE` nobody can read is invalid iCalendar, which
     * `CALDAV:valid-calendar-data` refuses on the way in — and a filter that
     * fell over on one such object in a calendar would answer nothing about
     * the rest.
     */
    public function testASeriesItCannotExpandIsAskedAsItStands(): void
    {
        $broken = str_replace("SUMMARY:", "RRULE:FREQ=NEVERLY\r\nSUMMARY:", self::MEETING);
        $filter = $this->filter(
            'VCALENDAR',
            $this->comp('VEVENT', $this->range('20260105T120000Z', '20260105T130000Z')),
        );

        self::assertTrue($this->holds($filter, $broken));
    }

    /**
     * The outermost filter, the one §9.7.1 scopes to the calendar object.
     */
    private function filter(string $name, Element ...$children): CompFilter
    {
        return CompFilter::of($this->comp($name, ...$children));
    }

    /**
     * A filter inside another one, which is an element rather than a filter of
     * its own: §9.7.1 scopes it to the enclosing component, and the enclosing
     * filter is what reads it.
     */
    private function comp(string $name, Element ...$children): Element
    {
        $element = new Element(CompFilter::NAME, ['name' => $name]);

        foreach ($children as $child) {
            $element->append($child);
        }

        return $element;
    }

    private function propFilter(string $name, ?Element $value = null): Element
    {
        $element = new Element(PropFilter::NAME, ['name' => $name]);

        if ($value !== null) {
            $element->append($value);
        }

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

    private function holds(CompFilter $filter, string $components): bool
    {
        $object = $this->objectOf($components);

        return $filter->holdsTheObject($object, new Moments($object, new DateTimeZone('UTC')));
    }

    private function objectOf(string $components): Component
    {
        $text = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\n"
            . $components . "END:VCALENDAR\r\n";

        return CalendarObject::in($text) ?? self::fail('The case built something that is no calendar object.');
    }
}
