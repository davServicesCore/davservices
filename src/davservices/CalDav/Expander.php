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

namespace DavServices\CalDav;

use DateTimeImmutable;
use DateTimeZone;
use DavServices\Exception\BadRequest;
use DavServices\VObject\Component;
use DavServices\VObject\ParseError;
use DavServices\VObject\Property;
use DavServices\VObject\Recur\Instance;
use DavServices\VObject\Recur\Iterator;
use DavServices\VObject\Recur\ObjectSet;
use DavServices\VObject\Recur\TooManyIterations;
use DavServices\VObject\Value\Date;
use DavServices\VObject\Value\DateTime;
use DavServices\Xml\Element;
use Generator;

/**
 * A recurrence set written out as instances (RFC 4791 §9.6.5).
 *
 *     <!ELEMENT expand EMPTY>
 *     <!ATTLIST expand start CDATA #REQUIRED
 *                      end   CDATA #REQUIRED>
 *
 * > The CALDAV:expand XML element specifies that for a given calendaring
 * > REPORT request, the server MUST expand the recurrence set into calendar
 * > components that define exactly one recurrence instance, and MUST return
 * > only those whose scheduled time intersect a specified time range.
 *
 * **Why a client asks for it**: a month view wants the appointments of that
 * month, not a rule it would have to expand itself — which is R-CAL-14, and
 * the reason the work belongs on the server at all.
 *
 * ## What the memo asks of every component that comes back
 *
 * > Recurring components, other than the initial instance, MUST include a
 * > RECURRENCE-ID property indicating which instance they refer to.
 *
 * So the first instance of a set carries none — its `DTSTART` already names it
 * — and every other one says which it is, with RFC 5545 §3.8.4.4's value: "the
 * original value of the 'DTSTART' property of the recurrence instance".
 *
 * > The returned calendar components MUST NOT use recurrence properties (i.e.,
 * > EXDATE, EXRULE, RDATE, and RRULE) and MUST NOT have reference to or
 * > include VTIMEZONE components. Date and local time with reference to time
 * > zone information MUST be converted into date with UTC time.
 *
 * All four recurrence properties go, `EXRULE` among them though RFC 5545
 * §3.8.5.2 withdrew it, because an object a client wrote years ago may carry
 * one. **The reference to a time zone is the `TZID` parameter** (§3.2.19), so
 * a value carrying one comes back as an instant with the parameter gone — and
 * then the definitions themselves can go, which is the other half of the same
 * sentence.
 *
 * **Two kinds of value are deliberately left as they are.** A `DATE` carries
 * no reference, §3.2.19 forbidding one — "This parameter MUST NOT be applied
 * to DATE properties" — and turning one into an instant would make an all-day
 * appointment a timed one. A floating value references nothing either, and
 * fixing it to an instant would move an appointment somebody wrote to be the
 * same wall clock everywhere (R-TZ-03).
 *
 * ## How an instance is written
 *
 * The component of the instance — the master, or the one overriding it — with
 * its start set to where the instance begins and its end moved by the same
 * wall clock. RFC 5545 §3.8.5.3 is what makes that the right arithmetic: where
 * "the duration of the recurring component is specified with the DTEND […]
 * property, then the […] duration of each instance is the same as the nominal
 * duration of the initial instance". A component that says its length as a
 * `DURATION` has nothing to move.
 *
 * An override brings its own start and end, so for it the move is nothing —
 * which falls out of the arithmetic rather than being a case of its own.
 *
 * ## Where the walk stops, and why it does not stop there
 *
 * A series may run for ever, so the instances are taken lazily and the walk
 * leaves off once the identifiers reach the end of the range: an instance the
 * series puts past the range cannot be in it. **Except where something moved
 * it back**, and §9.6.5 asks for the instances "whose scheduled time
 * intersect" the range — so the overriding components that sit past the end
 * are asked after the walk, each about where it actually is. They are
 * components of the object, so there are finitely many and they need no
 * expansion.
 *
 * **What is left open, and it is one shift away:** an override that both lies
 * past the end *and* carries `RANGE=THISANDFUTURE` moves the instances after
 * it, and one of those could land back inside the range. Finding it would mean
 * expanding past the end for as long as the memo allows, which is for ever.
 *
 * ## What cannot be expanded is asked as it stands
 *
 * A component with no `UID` is no series — §4.1 makes the property mandatory,
 * so that is invalid iCalendar a store may nonetheless hold — and a series
 * past R-RRULE-04's hard limit cannot be written out at all. Both come back
 * as they stand, held to the range like everything else.
 *
 * **For the second of those, what comes back keeps an `RRULE` §9.6.5 would
 * have gone**, and that is the honest choice: the alternative is to say
 * nothing about an object a client can see. The memo's own cap on what a store
 * may keep (§5.2.8's `CALDAV:max-instances`, enforced on the way in by
 * {@see Limits}) means a resource that got in through this library cannot be
 * one of these.
 */
final readonly class Expander
{
    public const NAME = '{urn:ietf:params:xml:ns:caldav}expand';

    /**
     * The four §9.6.5 names, which no component that comes back may carry.
     *
     * @var list<string>
     */
    private const RECURRENCE = ['RRULE', 'RDATE', 'EXDATE', 'EXRULE'];

    private const START = 'DTSTART';

    /** The two properties that say where a component ends (§3.8.2.2, §3.8.2.3). */
    private const ENDS = ['DTEND', 'DUE'];

    private const RECURRENCE_ID = 'RECURRENCE-ID';

    private const TIME_ZONE = 'VTIMEZONE';

    private const UTC = 'UTC';

    private function __construct(private TimeRange $range, private int $iterations)
    {
    }

    /**
     * Reads the element.
     *
     * @param int $iterations The hard limit the expansion is given
     *                        (R-RRULE-04), which a caller measuring something
     *                        smaller than a calendar can narrow
     *
     * @throws BadRequest If an attribute is missing, is no "date with UTC
     *                    time", or the end is not after the start
     */
    public static function of(Element $element, int $iterations = Iterator::ITERATIONS): self
    {
        return self::overTheRange(TimeRange::ofBothEnds($element), $iterations);
    }

    /**
     * The same expansion, over a range that was settled somewhere else.
     *
     * **Not every caller has a `CALDAV:expand` to read.** §9.9's own sentence
     * — "Time range tests MUST consider every recurrence instance when testing
     * the time range condition" — is asked by more than that element, and
     * §7.10's free/busy report is one of those askers: its body is
     * `(time-range)` and nothing else, so the range it works over is already
     * in hand and §9.6.5's `#REQUIRED` attributes are not its rule.
     *
     * @param int $iterations The hard limit the expansion is given
     *                        (R-RRULE-04)
     */
    public static function overTheRange(TimeRange $range, int $iterations = Iterator::ITERATIONS): self
    {
        return new self($range, $iterations);
    }

    /**
     * The object with its recurrence sets written out as instances.
     *
     * @param Moments $moments What moment each of the object's properties
     *                         names, which is what the range is measured with
     *                         and what resolves a `TZID` (§7.3 settles the
     *                         zone)
     */
    public function appliedTo(Component $object, Moments $moments): Component
    {
        $written = new Component($object->name());

        foreach ($object->properties() as $property) {
            $written->add(self::inUtc($property, $moments));
        }

        $expanded = [];

        foreach ($object->components() as $component) {
            // §9.6.5: the components that come back "MUST NOT have reference
            // to or include VTIMEZONE components", and the references are gone
            // with the parameters that carried them.
            if (strcasecmp($component->name(), self::TIME_ZONE) === 0) {
                continue;
            }

            $uid = $component->property('UID')?->value();

            if ($uid === null || $uid === '') {
                $this->keepAsItStands($written, $component, $moments);

                continue;
            }

            if (in_array($uid, $expanded, true)) {
                continue;
            }

            $expanded[] = $uid;

            $this->keepTheInstances($written, $object, $component, $uid, $moments);
        }

        return $written;
    }

    /**
     * Writes out the series this `UID` names, or the component as it stands
     * where it cannot be expanded.
     */
    private function keepTheInstances(
        Component $written,
        Component $object,
        Component $component,
        string $uid,
        Moments $moments,
    ): void {
        try {
            $set = ObjectSet::of($object, $uid, $this->iterations);
            $instances = [];

            // **Nothing is written until the whole set is in hand.** A series
            // that passes the hard limit passes it part of the way through,
            // and half an expansion beside the component it came from would be
            // the same instance twice over.
            foreach ($this->instancesIn($set->instances(), $object, $uid, $moments) as $instance) {
                $instances[] = $instance;
            }
        } catch (ParseError | TooManyIterations) {
            $this->keepAsItStands($written, $component, $moments);

            return;
        }

        foreach ($instances as $instance) {
            $written->add($instance);
        }
    }

    /**
     * Every instance of a set that the range reaches, written out.
     *
     * @param Generator<int, Instance> $instances In identifier order, taken
     *                                            one at a time
     *
     * @return Generator<int, Component>
     */
    private function instancesIn(Generator $instances, Component $object, string $uid, Moments $moments): Generator
    {
        $stopped = null;

        foreach ($instances as $instance) {
            $identifier = $instance->recurrenceId()->encode();
            $at = $moments->ofTheInstance($instance->component(), $instance->recurrenceId());

            // An instance the series puts at or past the end of the range
            // cannot be in it, and a series may run for ever — so this is
            // where the walk leaves off. **The question is about instants**:
            // a series in a zone ahead of UTC shows a wall clock past the end
            // while the instance it names is still inside, and one behind UTC
            // shows the other way round.
            if ($at !== null && !$this->range->reachesPast($at)) {
                $stopped = $identifier;

                break;
            }

            $component = $this->written($instance, $moments);

            if ($component !== null) {
                yield $component;
            }
        }

        if ($stopped === null) {
            return;
        }

        yield from $this->theOverridesBeyond($object, $uid, $stopped, $moments);
    }

    /**
     * The overriding components the walk stopped before, each asked about
     * where it actually is.
     *
     * §9.6.5 asks for the instances "whose scheduled time intersect" the
     * range, and an override is scheduled where its own component says — which
     * may be inside a range its identifier lies past.
     *
     * @return Generator<int, Component>
     */
    private function theOverridesBeyond(
        Component $object,
        string $uid,
        string $stopped,
        Moments $moments,
    ): Generator {
        foreach ($object->components() as $component) {
            $identifier = $component->property(self::RECURRENCE_ID);

            if ($identifier === null || $component->property('UID')?->value() !== $uid) {
                continue;
            }

            if (strcmp($identifier->value(), $stopped) < 0) {
                continue;
            }

            $written = self::withoutTheRecurrence($component, $moments);

            if ($this->range->overlaps($written, $moments)) {
                yield $written;
            }
        }
    }

    /**
     * One instance as a component of its own, or null where the range does not
     * reach it.
     */
    private function written(Instance $instance, Moments $moments): ?Component
    {
        $component = $instance->component();
        $start = $component->property(self::START);

        // @codeCoverageIgnoreStart
        // **The component of an instance has a start, and the type system
        // does not say so.** `ObjectSet` refuses a master without a `DTSTART`
        // (RFC 5545 §3.8.5.1) and an overriding component without one, and
        // that refusal is caught where the series is expanded — so an instance
        // that got this far has one. The guard is for the reader of the types
        // rather than for any input.
        if ($start === null) {
            return null;
        }
        // @codeCoverageIgnoreEnd

        $moved = self::wallClockOf($instance->start()) - self::wallClockOf(self::valueOf($start));
        $written = self::withoutTheRecurrence($component, $moments, $moved);

        self::sayWhichInstanceItIs($written, $component, $instance, $moments);

        return $this->range->overlaps($written, $moments) ? $written : null;
    }

    /**
     * The component without the four recurrence properties, with its times
     * moved and its zones resolved.
     *
     * @param int $moved How far the instance lies from the component's own
     *                   start, in seconds of wall clock
     *
     * @throws ParseError If a start or an end is no value this library can
     *                    read
     */
    private static function withoutTheRecurrence(Component $component, Moments $moments, int $moved = 0): Component
    {
        $written = new Component($component->name());

        foreach ($component->properties() as $property) {
            if (self::isRecurrence($property)) {
                continue;
            }

            $written->add(self::inUtc(self::shifted($property, $moved), $moments));
        }

        foreach ($component->components() as $inner) {
            $written->add(self::withoutTheRecurrence($inner, $moments));
        }

        return $written;
    }

    /**
     * Adds the `RECURRENCE-ID` §9.6.5 asks of every instance but the first.
     *
     * A component that carries one already — an override does, §4.1 making it
     * what tells the two apart — keeps its own, which {@see
     * withoutTheRecurrence()} has already written out.
     */
    private static function sayWhichInstanceItIs(
        Component $written,
        Component $component,
        Instance $instance,
        Moments $moments,
    ): void {
        if ($component->property(self::RECURRENCE_ID) !== null) {
            return;
        }

        $start = $component->property(self::START);
        $identifier = $instance->recurrenceId()->encode();

        // The initial instance is the one the component's own start names, and
        // §9.6.5 asks for the property on every other.
        if ($start === null || $start->value() === $identifier) {
            return;
        }

        $written->add(self::inUtc(
            new Property(self::RECURRENCE_ID, $identifier, $start->parameters()),
            $moments,
        ));
    }

    /**
     * Keeps a component the way it is, where the range reaches it.
     */
    private function keepAsItStands(Component $written, Component $component, Moments $moments): void
    {
        if ($this->range->overlaps($component, $moments)) {
            $written->add($component);
        }
    }

    /**
     * The property with its value moved by that many seconds of wall clock,
     * or the property itself where there is nothing to move.
     *
     * **Only the start and the end move.** They are what an instance is: the
     * other dated properties of a component say when it was written or
     * finished (§3.8.7.2, §3.8.2.1), which is the same for every instance of
     * it.
     *
     * An override is written with a move of nothing, and the arithmetic says
     * so rather than being skipped for it: a value re-read and written back is
     * the value it was.
     */
    private static function shifted(Property $property, int $moved): Property
    {
        if (!self::saysWhenItHappens($property)) {
            return $property;
        }

        $value = self::valueOf($property);
        $at = (new DateTimeImmutable('@' . (self::wallClockOf($value) + $moved)))
            ->setTimezone(new DateTimeZone(self::UTC));

        return new Property(
            $property->name(),
            $value instanceof Date
                ? $at->format('Ymd')
                : $at->format('Ymd\THis') . ($value->isUtc() ? 'Z' : ''),
            $property->parameters(),
        );
    }

    /**
     * The property as an instant in UTC, where it names a zone to resolve it
     * in, and the property itself where it does not.
     *
     * **The `TZID` parameter is the reference §9.6.5 asks to be gone**
     * (RFC 5545 §3.2.19), so resolving the value is only half of it: the
     * parameter goes with it, and the answer then points at no `VTIMEZONE`.
     */
    private static function inUtc(Property $property, Moments $moments): Property
    {
        if ($property->parameter('TZID') === null) {
            return $property;
        }

        $at = $moments->of($property);

        if ($at === null) {
            return $property;
        }

        $kept = [];

        foreach ($property->parameters() as $parameter) {
            if (!$parameter->named('TZID')) {
                $kept[] = $parameter;
            }
        }

        return new Property($property->name(), $at->format('Ymd\THis\Z'), $kept);
    }

    /**
     * Whether this property is one of §9.6.5's four.
     */
    private static function isRecurrence(Property $property): bool
    {
        return in_array(strtoupper($property->name()), self::RECURRENCE, true);
    }

    /**
     * Whether this property says when the component happens, which is what an
     * instance moves.
     */
    private static function saysWhenItHappens(Property $property): bool
    {
        $name = strtoupper($property->name());

        return $name === self::START || in_array($name, self::ENDS, true);
    }

    /**
     * The property's value as a date or a date and time.
     *
     * **A value nobody can read is a series nobody can expand**, and the
     * `ParseError` says so where the expansion is asked for: `ObjectSet`
     * validates the starts of a set, and an end nobody validated — a `DTEND`
     * of nonsense — turns up here. What happens then is what happens to a
     * series that cannot be read at all: the component is asked as it stands.
     *
     * @throws ParseError If it is neither
     */
    private static function valueOf(Property $property): Date|DateTime
    {
        return Moments::isADate($property)
            ? Date::decode($property->value())
            : DateTime::decode($property->value());
    }

    /**
     * The wall clock of a value as a number of seconds, read as if it were
     * UTC.
     *
     * **The arithmetic of a recurrence runs on the wall clock** — RFC 5545
     * §3.3.10 and §3.8.5.3 both put it there — so the gap between two values
     * is the gap between the clocks they show, whatever offsets they carry.
     * Reading both the same way is what makes the difference that gap.
     */
    private static function wallClockOf(Date|DateTime $value): int
    {
        $utc = new DateTimeZone(self::UTC);

        return $value instanceof Date
            ? $value->at($utc)->getTimestamp()
            : $value->in($utc)->getTimestamp();
    }
}
