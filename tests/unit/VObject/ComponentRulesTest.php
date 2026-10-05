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
use DavServices\VObject\Finding;
use DavServices\VObject\Property;
use DavServices\VObject\Severity;
use DavServices\VObject\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for what a calendar component may hold, from RFC 5545 §3.6.
 *
 * **This is the gap the conformance check of P4-07 found.** §3.8.5.3 says of
 * `RRULE` that "it SHOULD NOT be specified more than once", and nothing in
 * this library said so — because the validator looked at the outermost object
 * and at nothing inside it. §3.6 gives every component its own list, and the
 * grammar comments are the whole specification:
 *
 *     eventprop  = *(
 *                ; The following are REQUIRED,
 *                ; but MUST NOT occur more than once.
 *                dtstamp / uid /
 *                …
 *                ; The following is OPTIONAL,
 *                ; but SHOULD NOT occur more than once.
 *                rrule /
 *
 * So there are four kinds of rule, and the memo names each of them: a
 * property that must be there once, one that may be there once, one that
 * should not be there twice, and one that may be there as often as it likes.
 * The first three are worth a finding; the fourth is silence.
 *
 * ## What is not reported, and why the grammar forbids reporting it
 *
 * **A property this memo has never heard of is not an error.** Every one of
 * these lists ends in `x-prop / iana-prop`, so any name at all matches the
 * component's grammar. A `DTEND` inside a `VJOURNAL` is wrong — §3.8.2.2's
 * Conformance clause says DTEND "can be specified in 'VEVENT' or 'VFREEBUSY'
 * calendar components" and nowhere else — but that is **the property's own**
 * clause rather than the component's grammar, and reading all forty of them
 * is a chunk of its own. Until then, a property out of place is read and not
 * judged, which is the same line the reading chunks held.
 *
 * ## `VALARM` is not here
 *
 * `alarmc = "BEGIN" ":" "VALARM" CRLF (audioprop / dispprop / emailprop)` —
 * its table is chosen by the **value** of `ACTION`, which is a different
 * mechanism from the seven here, and §3.8.6.1 admits actions this memo does
 * not define (`actionvalue = "AUDIO" / "DISPLAY" / "EMAIL" / iana-token /
 * x-name`). What to ask of those needs its own decision, so it gets its own
 * chunk rather than a guess here.
 */
#[CoversClass(Validator::class)]
final class ComponentRulesTest extends TestCase
{
    /**
     * A calendar with one sound component of each kind says nothing at all.
     *
     * @param non-empty-string $name
     */
    #[DataProvider('everyComponentThatIsRight')]
    public function testAComponentThatIsRightSaysNothing(string $name, Component $component): void
    {
        self::assertSame([], $this->findingsIn($component), $name);
    }

    /**
     * @return iterable<string, array{string, Component}>
     */
    public static function everyComponentThatIsRight(): iterable
    {
        yield 'an event' => ['VEVENT', self::sound('VEVENT', ['DTSTAMP', 'UID', 'DTSTART'])];

        yield 'a to-do' => ['VTODO', self::sound('VTODO', ['DTSTAMP', 'UID'])];

        yield 'a journal entry' => ['VJOURNAL', self::sound('VJOURNAL', ['DTSTAMP', 'UID'])];

        yield 'free and busy time' => ['VFREEBUSY', self::sound('VFREEBUSY', ['DTSTAMP', 'UID'])];

        yield 'a time zone' => ['VTIMEZONE', self::timeZone()];
    }

    /**
     * **"The following are REQUIRED, but MUST NOT occur more than once."**
     * Missing is an error rather than a repair: a `UID` nobody wrote is a
     * `UID` nobody can match again, and a `DTSTAMP` invented now would say
     * the object was written now.
     *
     * @param non-empty-string $name
     * @param non-empty-string $missing
     */
    #[DataProvider('whatEveryComponentMustHave')]
    public function testARequiredPropertyThatIsMissingIsAnError(string $name, string $missing): void
    {
        $component = self::sound($name, self::required($name));

        unset($component[$missing]);

        self::assertContains(
            [Severity::Error, 'VCALENDAR/' . $name . '/' . $missing],
            $this->findingsIn($component),
        );
    }

    /**
     * And a required property is required once, so twice is once too many.
     *
     * @param non-empty-string $name
     * @param non-empty-string $twice
     */
    #[DataProvider('whatEveryComponentMustHave')]
    public function testARequiredPropertyTwiceIsAnError(string $name, string $twice): void
    {
        $component = self::sound($name, self::required($name));

        $component->add(new Property($twice, 'x'));

        self::assertContains(
            [Severity::Error, 'VCALENDAR/' . $name . '/' . $twice],
            $this->findingsIn($component),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function whatEveryComponentMustHave(): iterable
    {
        foreach (['VEVENT', 'VTODO', 'VJOURNAL', 'VFREEBUSY'] as $name) {
            yield $name . ' needs a DTSTAMP' => [$name, 'DTSTAMP'];

            yield $name . ' needs a UID' => [$name, 'UID'];
        }

        yield 'a time zone needs a TZID' => ['VTIMEZONE', 'TZID'];

        foreach (['STANDARD', 'DAYLIGHT'] as $name) {
            yield $name . ' needs a DTSTART' => [$name, 'DTSTART'];

            yield $name . ' needs a TZOFFSETTO' => [$name, 'TZOFFSETTO'];

            yield $name . ' needs a TZOFFSETFROM' => [$name, 'TZOFFSETFROM'];
        }
    }

    /**
     * **"The following are OPTIONAL, but MUST NOT occur more than once."**
     * Which of the two was meant is not something anybody else can say, so it
     * is an error and not a repair.
     *
     * @param non-empty-string $name
     * @param non-empty-string $property
     */
    #[DataProvider('whatMayAppearOnlyOnce')]
    public function testAnOptionalPropertyTwiceIsAnError(string $name, string $property): void
    {
        $component = self::sound($name, self::required($name));

        $component->add(new Property($property, 'x'));
        $component->add(new Property($property, 'y'));

        self::assertContains(
            [Severity::Error, 'VCALENDAR/' . $name . '/' . $property],
            $this->findingsIn($component),
        );
    }

    /**
     * **The lists differ from component to component**, which is the whole
     * reason each one has its own: `CONTACT` may appear as often as it likes
     * in a `VEVENT` and only once in a `VFREEBUSY`, and `DESCRIPTION` the
     * other way about between `VEVENT` and `VJOURNAL`.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function whatMayAppearOnlyOnce(): iterable
    {
        yield 'an event has one SUMMARY' => ['VEVENT', 'SUMMARY'];

        yield 'an event has one TRANSP' => ['VEVENT', 'TRANSP'];

        yield 'an event has one RECURRENCE-ID' => ['VEVENT', 'RECURRENCE-ID'];

        yield 'a to-do has one PERCENT-COMPLETE' => ['VTODO', 'PERCENT-COMPLETE'];

        yield 'a to-do has one COMPLETED' => ['VTODO', 'COMPLETED'];

        yield 'a journal entry has one STATUS' => ['VJOURNAL', 'STATUS'];

        yield 'free and busy time has one CONTACT' => ['VFREEBUSY', 'CONTACT'];

        yield 'free and busy time has one DTEND' => ['VFREEBUSY', 'DTEND'];

        yield 'a time zone has one TZURL' => ['VTIMEZONE', 'TZURL'];

        yield 'a time zone has one LAST-MODIFIED' => ['VTIMEZONE', 'LAST-MODIFIED'];
    }

    /**
     * **And what may appear as often as it likes is left alone**, which is
     * the half a test list forgets. A meeting has as many `ATTENDEE`
     * properties as it has attendees.
     *
     * @param non-empty-string $name
     * @param non-empty-string $property
     */
    #[DataProvider('whatMayAppearAsOftenAsItLikes')]
    public function testAPropertyThatMayRepeatIsLeftAlone(string $name, string $property): void
    {
        $component = self::sound($name, self::required($name));

        $component->add(new Property($property, 'x'));
        $component->add(new Property($property, 'y'));

        self::assertSame([], $this->findingsIn($component));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function whatMayAppearAsOftenAsItLikes(): iterable
    {
        yield 'an event has many ATTENDEEs' => ['VEVENT', 'ATTENDEE'];

        yield 'an event has many CONTACTs' => ['VEVENT', 'CONTACT'];

        yield 'an event has many EXDATEs' => ['VEVENT', 'EXDATE'];

        yield 'a journal entry has many DESCRIPTIONs' => ['VJOURNAL', 'DESCRIPTION'];

        yield 'free and busy time has many FREEBUSYs' => ['VFREEBUSY', 'FREEBUSY'];

        yield 'a to-do has many RESOURCES' => ['VTODO', 'RESOURCES'];
    }

    /**
     * **"The following is OPTIONAL, but SHOULD NOT occur more than once."**
     * A SHOULD NOT is a warning and not an error — nothing is broken, but
     * §3.8.5.3 says what follows: "The recurrence set generated with multiple
     * 'RRULE' properties is undefined."
     *
     * @param non-empty-string $name
     */
    #[DataProvider('whatShouldNotAppearTwice')]
    public function testASecondRecurrenceRuleIsAWarning(string $name): void
    {
        $component = self::sound($name, self::required($name));

        $component->add(new Property('RRULE', 'FREQ=DAILY'));
        $component->add(new Property('RRULE', 'FREQ=WEEKLY'));

        self::assertContains(
            [Severity::Warning, 'VCALENDAR/' . $name . '/RRULE'],
            $this->findingsIn($component),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function whatShouldNotAppearTwice(): iterable
    {
        yield 'in an event' => ['VEVENT'];

        yield 'in a to-do' => ['VTODO'];

        yield 'in a journal entry' => ['VJOURNAL'];

        yield 'in the standard time of a time zone' => ['STANDARD'];

        yield 'in the daylight saving time of one' => ['DAYLIGHT'];
    }

    /**
     * And one recurrence rule is what nearly every recurring event has, so it
     * says nothing.
     */
    public function testOneRecurrenceRuleSaysNothing(): void
    {
        $event = self::sound('VEVENT', ['DTSTAMP', 'UID', 'DTSTART']);

        $event->add(new Property('RRULE', 'FREQ=DAILY'));

        self::assertSame([], $this->findingsIn($event));
    }

    /**
     * **"Either 'dtend' or 'duration' MAY appear in a 'eventprop', but
     * 'dtend' and 'duration' MUST NOT occur in the same 'eventprop'."** An
     * event that says both says when it ends twice, and which of the two was
     * meant is the author's to say.
     */
    public function testAnEventWithBothAnEndAndALengthIsAnError(): void
    {
        $event = self::sound('VEVENT', ['DTSTAMP', 'UID', 'DTSTART']);

        $event->add(new Property('DTEND', '19970715T040000Z'));
        $event->add(new Property('DURATION', 'PT1H'));

        self::assertContains([Severity::Error, 'VCALENDAR/VEVENT'], $this->findingsIn($event));
    }

    /**
     * And either one on its own is the ordinary case.
     *
     * @param non-empty-string $property
     */
    #[DataProvider('oneWayOrTheOtherOfSayingWhenItEnds')]
    public function testAnEventWithOneOfThemSaysNothing(string $property, string $value): void
    {
        $event = self::sound('VEVENT', ['DTSTAMP', 'UID', 'DTSTART']);

        $event->add(new Property($property, $value));

        self::assertSame([], $this->findingsIn($event));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function oneWayOrTheOtherOfSayingWhenItEnds(): iterable
    {
        yield 'an end' => ['DTEND', '19970715T040000Z'];

        yield 'a length' => ['DURATION', 'PT1H'];
    }

    /**
     * **The same for a to-do, in its own words**: "Either 'due' or 'duration'
     * MAY appear in a 'todoprop', but 'due' and 'duration' MUST NOT occur in
     * the same 'todoprop'."
     */
    public function testATodoWithBothADueDateAndALengthIsAnError(): void
    {
        $todo = self::sound('VTODO', ['DTSTAMP', 'UID']);

        $todo->add(new Property('DTSTART', '19970715T040000Z'));
        $todo->add(new Property('DUE', '19970716T040000Z'));
        $todo->add(new Property('DURATION', 'PT1H'));

        self::assertContains([Severity::Error, 'VCALENDAR/VTODO'], $this->findingsIn($todo));
    }

    /**
     * **And a to-do has one rule the event has not**: "If 'duration' appear
     * in a 'todoprop', then 'dtstart' MUST also appear in the same
     * 'todoprop'." A length with nothing to measure it from says nothing.
     */
    public function testATodoWithALengthAndNoStartIsAnError(): void
    {
        $todo = self::sound('VTODO', ['DTSTAMP', 'UID']);

        $todo->add(new Property('DURATION', 'PT1H'));

        self::assertContains([Severity::Error, 'VCALENDAR/VTODO/DURATION'], $this->findingsIn($todo));
    }

    /**
     * And with a start it is sound.
     */
    public function testATodoWithALengthAndAStartSaysNothing(): void
    {
        $todo = self::sound('VTODO', ['DTSTAMP', 'UID']);

        $todo->add(new Property('DTSTART', '19970715T040000Z'));
        $todo->add(new Property('DURATION', 'PT1H'));

        self::assertSame([], $this->findingsIn($todo));
    }

    /**
     * **"One of 'standardc' or 'daylightc' MUST occur and each MAY occur more
     * than once."** A time zone with neither describes no offset at all.
     */
    public function testATimeZoneWithNeitherKindOfTimeIsAnError(): void
    {
        $zone = new Component('VTIMEZONE');

        $zone->add(new Property('TZID', 'Europe/Berlin'));

        self::assertContains([Severity::Error, 'VCALENDAR/VTIMEZONE'], $this->findingsIn($zone));
    }

    /**
     * And each of them may occur more than once, which is how a zone whose
     * rules changed over the years is written.
     */
    public function testATimeZoneMayHaveSeveralOfEach(): void
    {
        $zone = self::timeZone();

        $zone->add(self::sound('STANDARD', ['DTSTART', 'TZOFFSETTO', 'TZOFFSETFROM']));
        $zone->add(self::sound('DAYLIGHT', ['DTSTART', 'TZOFFSETTO', 'TZOFFSETFROM']));

        self::assertSame([], $this->findingsIn($zone));
    }

    /**
     * **"The following is REQUIRED if the component appears in an iCalendar
     * object that doesn't specify the 'METHOD' property; otherwise, it is
     * OPTIONAL; in any case, it MUST NOT occur more than once."** The only
     * rule in §3.6 that depends on the object around the component — which is
     * why it can be checked at all: a validator sees the whole object.
     */
    public function testAnEventWithoutAStartIsAnErrorWhereTheObjectNamesNoMethod(): void
    {
        $event = self::sound('VEVENT', ['DTSTAMP', 'UID']);

        self::assertContains([Severity::Error, 'VCALENDAR/VEVENT/DTSTART'], $this->findingsIn($event));
    }

    /**
     * And where the object does name one, the same event is sound: a `REPLY`
     * to an invitation carries no start of its own.
     */
    public function testTheSameEventIsSoundWhereTheObjectNamesAMethod(): void
    {
        $calendar = self::calendarAround(self::sound('VEVENT', ['DTSTAMP', 'UID']));

        $calendar->add(new Property('METHOD', 'REPLY'));

        self::assertSame([], self::placesIn((new Validator())->check($calendar)));
    }

    /**
     * **`calprops` is a table of the same shape**, and two of its entries
     * belong here: "calscale / method — OPTIONAL, but MUST NOT occur more
     * than once." The calendar's own `PRODID` and `VERSION` are
     * {@see ValidatorTest}'s subject, because a missing `VERSION` is a repair
     * rather than an error and no table says that.
     *
     * @param non-empty-string $property
     */
    #[DataProvider('whatACalendarMayHaveOnlyOnce')]
    public function testACalendarPropertyTwiceIsAnError(string $property): void
    {
        $calendar = self::calendarAround(self::sound('VEVENT', ['DTSTAMP', 'UID', 'DTSTART']));

        $calendar->add(new Property($property, 'x'));
        $calendar->add(new Property($property, 'y'));

        self::assertContains(
            [Severity::Error, 'VCALENDAR/' . $property],
            self::placesIn((new Validator())->check($calendar)),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function whatACalendarMayHaveOnlyOnce(): iterable
    {
        yield 'a calendar has one CALSCALE' => ['CALSCALE'];

        yield 'a calendar has one METHOD' => ['METHOD'];
    }

    /**
     * **A component and the ones inside it are all reported**, and the outer
     * one is not lost when the inner ones have something to say. A time zone
     * whose rules changed over the years has several of each inside it.
     */
    public function testAComponentAndTheOnesInsideItAreAllReported(): void
    {
        $zone = new Component('VTIMEZONE');

        $zone->add(self::sound('STANDARD', ['TZOFFSETTO', 'TZOFFSETFROM']));
        $zone->add(self::sound('DAYLIGHT', ['TZOFFSETTO', 'TZOFFSETFROM']));

        self::assertSame(
            [
                [Severity::Error, 'VCALENDAR/VTIMEZONE/TZID'],
                [Severity::Error, 'VCALENDAR/VTIMEZONE/STANDARD/DTSTART'],
                [Severity::Error, 'VCALENDAR/VTIMEZONE/DAYLIGHT/DTSTART'],
            ],
            $this->findingsIn($zone),
        );
    }

    /**
     * **And a component that breaks two of the prose rules at once is told
     * both.** An event with a `DTEND` and a `DURATION` and no `DTSTART`
     * breaks §3.6.1 twice over, and hearing about one of them would send
     * somebody back for the other.
     */
    public function testAComponentWrongInTwoWaysIsToldBoth(): void
    {
        $event = self::sound('VEVENT', ['DTSTAMP', 'UID']);

        $event->add(new Property('DTEND', '19970715T040000Z'));
        $event->add(new Property('DURATION', 'PT1H'));

        self::assertSame(
            [
                [Severity::Error, 'VCALENDAR/VEVENT'],
                [Severity::Error, 'VCALENDAR/VEVENT/DTSTART'],
            ],
            $this->findingsIn($event),
        );
    }

    /**
     * **A component nobody has heard of is left alone**, because every one of
     * these lists ends in `x-prop / iana-prop` and §3.6 says so in prose as
     * well: "Applications MUST ignore x-comp and iana-comp values they don't
     * recognize."
     */
    public function testAComponentNobodyHasHeardOfIsLeftAlone(): void
    {
        $box = new Component('X-BOX');

        $box->add(new Property('X-WHAT', 'anything at all'));

        self::assertSame([], $this->findingsIn($box));
    }

    /**
     * **And a property out of place is read and not judged.** `DTEND` belongs
     * to a `VEVENT` or a `VFREEBUSY` by §3.8.2.2's own Conformance clause,
     * and this says nothing about one in a `VJOURNAL` — because every
     * component's grammar ends in `iana-prop`, and where a property may stand
     * is written in the property's clause rather than the component's. Reading
     * all forty of those is a chunk of its own.
     */
    public function testAPropertyOutOfPlaceIsNotJudgedYet(): void
    {
        $journal = self::sound('VJOURNAL', ['DTSTAMP', 'UID']);

        $journal->add(new Property('DTEND', '19970715T040000Z'));

        self::assertSame([], $this->findingsIn($journal));
    }

    /**
     * **Everything wrong is reported, not the first thing**, and the rules of
     * a component and of the object around it come out together.
     */
    public function testEverythingWrongInAComponentIsReported(): void
    {
        $event = new Component('VEVENT');

        $event->add(new Property('SUMMARY', 'a meeting nobody can match again'));

        self::assertSame(
            [
                [Severity::Error, 'VCALENDAR/VEVENT/DTSTAMP'],
                [Severity::Error, 'VCALENDAR/VEVENT/UID'],
                [Severity::Error, 'VCALENDAR/VEVENT/DTSTART'],
            ],
            $this->findingsIn($event),
        );
    }

    /**
     * **An empty component is told both things**, and in that order: the
     * structure of the object is read before any component's rules are
     * applied, which is what puts the emptiness first.
     */
    public function testAnEmptyComponentIsToldBothThings(): void
    {
        self::assertSame(
            [
                [Severity::Error, 'VCALENDAR/VTODO'],
                [Severity::Error, 'VCALENDAR/VTODO/DTSTAMP'],
                [Severity::Error, 'VCALENDAR/VTODO/UID'],
            ],
            $this->findingsIn(new Component('VTODO')),
        );
    }

    /**
     * And the rules reach a component inside a component: the times of a time
     * zone are where the deepest of them live.
     */
    public function testTheRulesReachAComponentInsideAComponent(): void
    {
        $zone = new Component('VTIMEZONE');
        $standard = new Component('STANDARD');

        $standard->add(new Property('TZOFFSETTO', '-0500'));
        $zone->add(new Property('TZID', 'America/New_York'));
        $zone->add($standard);

        self::assertContains(
            [Severity::Error, 'VCALENDAR/VTIMEZONE/STANDARD/DTSTART'],
            $this->findingsIn($zone),
        );
    }

    /**
     * What the validator said about a component, as severity and place.
     *
     * @return list<array{Severity, string}>
     */
    private function findingsIn(Component $component): array
    {
        return self::placesIn((new Validator())->check(self::calendarAround($component)));
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<array{Severity, string}>
     */
    private static function placesIn(array $findings): array
    {
        $places = [];

        foreach ($findings as $finding) {
            $places[] = [$finding->severity(), $finding->where()];
        }

        return $places;
    }

    /**
     * A calendar that is sound in itself, so that anything said is said about
     * the component inside it.
     */
    private static function calendarAround(Component $component): Component
    {
        $calendar = new Component('VCALENDAR');

        $calendar->add(new Property('VERSION', '2.0'));
        $calendar->add(new Property('PRODID', '-//davServices//EN'));
        $calendar->add($component);

        return $calendar;
    }

    /**
     * A component with exactly the properties §3.6 requires of it, and
     * nothing else.
     *
     * @param non-empty-string $name
     * @param list<string> $properties
     */
    private static function sound(string $name, array $properties): Component
    {
        $component = new Component($name);

        foreach ($properties as $property) {
            $component->add(new Property($property, 'x'));
        }

        return $component;
    }

    /**
     * What §3.6 requires of each component, by name.
     *
     * @param non-empty-string $name
     *
     * @return list<string>
     */
    private static function required(string $name): array
    {
        if ($name === 'VEVENT') {
            return ['DTSTAMP', 'UID', 'DTSTART'];
        }

        if ($name === 'VTIMEZONE') {
            return ['TZID'];
        }

        if ($name === 'STANDARD' || $name === 'DAYLIGHT') {
            return ['DTSTART', 'TZOFFSETTO', 'TZOFFSETFROM'];
        }

        return ['DTSTAMP', 'UID'];
    }

    /**
     * A time zone with the one standard time §3.6 asks for.
     */
    private static function timeZone(): Component
    {
        $zone = new Component('VTIMEZONE');

        $zone->add(new Property('TZID', 'Europe/Berlin'));
        $zone->add(self::sound('STANDARD', ['DTSTART', 'TZOFFSETTO', 'TZOFFSETFROM']));

        return $zone;
    }
}
