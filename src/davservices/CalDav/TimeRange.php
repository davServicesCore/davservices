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

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use DavServices\Exception\BadRequest;
use DavServices\VObject\Component;
use DavServices\VObject\ParseError;
use DavServices\VObject\Property;
use DavServices\VObject\Value\DateTime;
use DavServices\VObject\Value\Duration;
use DavServices\VObject\Value\Period;
use DavServices\Xml\Element;

/**
 * `CALDAV:time-range`, as RFC 4791 §9.9 defines it (R-CAL-15).
 *
 * > The CALDAV:time-range XML element specifies that for a given calendaring
 * > REPORT request, the server MUST only return the calendar object resources
 * > that, depending on the context, have a component or property whose value
 * > intersects a specified time range.
 *
 * ## The two ends are not the same kind of end
 *
 * > The "start" attribute specifies the inclusive start of the time range, and
 * > the "end" attribute specifies the non-inclusive end of the time range.
 *
 * That asymmetry is the easiest thing here to get wrong, so it is said once,
 * in the four methods the tables are written with: {@see startsAtOrBefore()},
 * {@see startsBefore()}, {@see endsAfter()} and {@see endsAtOrAfter()}. Every
 * row below is then a transcription rather than a translation.
 *
 * ## An open end is an end
 *
 * > Time ranges open at one end can be specified by including only one
 * > attribute; however, at least one attribute MUST always be present in the
 * > CALDAV:time-range element. If either the "start" or "end" attribute is not
 * > specified in the CALDAV:time-range XML element, assume "-infinity" and
 * > "+infinity" as their value, respectively.
 *
 * So null is infinity, and every comparison against it is true.
 *
 * ## A table per kind, and nothing for the rest
 *
 * §9.9 gives a table for `VEVENT`, `VTODO`, `VJOURNAL`, `VFREEBUSY` and
 * `VALARM`, and closes the subject: "The semantic of CALDAV:time-range is not
 * defined for any other calendar components and properties." A kind the memo
 * says nothing about therefore overlaps nothing — answering otherwise would
 * return resources a client cannot predict.
 *
 * **For a property the filter refuses outright rather than answering.** §9.9's
 * last table names seven, and §7.8 makes a range nested anywhere else a
 * `CALDAV:valid-filter` violation in those words: "a CALDAV:filter cannot nest
 * a <C:time-range start='...' end='...'> element in a <C:prop
 * name='SUMMARY'> element". So {@see isDefinedFor()} is asked where a
 * `prop-filter` is read ({@see \DavServices\CalDav\Filter\PropFilter::of()}),
 * and by the time a value is compared the question is only whether it falls in
 * the range.
 *
 * ## Recurrence is not this class's business
 *
 * > Time range tests MUST consider every recurrence instance when testing the
 * > time range condition; if any one instance matches, then the test returns
 * > true.
 *
 * That is a sentence about which components to ask, not about how to ask one.
 * This answers for the component it is handed; the report expands a series and
 * asks for each instance, which is where §9.9's own parenthesis — "(for each
 * recurring instance)" in §5.3.2.1 — puts the duty.
 */
final readonly class TimeRange
{
    public const NAME = '{urn:ietf:params:xml:ns:caldav}time-range';

    /** §9.9's effective duration for a value that names a day and no time. */
    private const A_DAY = 'P1D';

    /**
     * The seven properties §9.9's last table is about.
     *
     * "The calendar properties COMPLETED, CREATED, DTEND, DTSTAMP, DTSTART,
     * DUE, and LAST-MODIFIED overlap a given time range if the following
     * condition holds" — and of everything else the same section says: "The
     * semantic of CALDAV:time-range is not defined for any other calendar
     * components and properties."
     *
     * @var list<string>
     */
    private const DATED = ['COMPLETED', 'CREATED', 'DTEND', 'DTSTAMP', 'DTSTART', 'DUE', 'LAST-MODIFIED'];

    private function __construct(
        private ?DateTimeImmutable $from,
        private ?DateTimeImmutable $until,
    ) {
    }

    /**
     * One range from the element that carries it.
     *
     * @throws BadRequest If neither end is there, if an end is no "date with
     *                    UTC time", or if the end is not after the start
     */
    public static function of(Element $element): self
    {
        $from = self::momentOf($element, 'start');
        $until = self::momentOf($element, 'end');

        if ($from === null && $until === null) {
            throw new BadRequest(
                'A CALDAV:time-range carries at least one of "start" and "end": RFC 4791 §9.9.',
            );
        }

        // §9.9: "If both 'start' and 'end' are present, the value of the 'end'
        // attribute MUST be greater than the value of the 'start' attribute."
        if ($from !== null && $until !== null && $until <= $from) {
            throw new BadRequest(
                'The "end" of a CALDAV:time-range is greater than its "start": RFC 4791 §9.9.',
            );
        }

        return new self($from, $until);
    }

    /**
     * One range whose both ends are there, as §9.6.5 to §9.6.7 want them.
     *
     * Those three elements limit what a report returns rather than asking a
     * question about it, and each makes its attributes `#REQUIRED`:
     *
     *     <!ATTLIST limit-recurrence-set start CDATA #REQUIRED
     *                                    end   CDATA #REQUIRED>
     *
     * **That is a different thing from §9.9's own element**, which reads a
     * missing attribute as "any time in the past" or "any time in the future":
     * a filter open at one end is an ordinary thing to ask, while a limit open
     * at one end limits nothing. Everything else about the two ends is the
     * same question, which §9.6.6 says itself — "The server MUST use the same
     * logic as defined for CALDAV:time-range" — so the rest is {@see of()}.
     *
     * @throws BadRequest If an attribute is missing, is no "date with UTC
     *                    time", or the end is not after the start
     */
    public static function ofBothEnds(Element $element): self
    {
        foreach (['start', 'end'] as $attribute) {
            if ($element->attribute($attribute) === null) {
                throw new BadRequest(sprintf(
                    'A limit on what comes back names both ends: RFC 4791 §9.6.5 to §9.6.7 make "%s" #REQUIRED.',
                    $attribute,
                ));
            }
        }

        return self::of($element);
    }

    /**
     * The inclusive start, or null for "-infinity".
     */
    public function from(): ?DateTimeImmutable
    {
        return $this->from;
    }

    /**
     * The non-inclusive end, or null for "+infinity".
     */
    public function until(): ?DateTimeImmutable
    {
        return $this->until;
    }

    /**
     * §9.9's last table, for one moment.
     *
     * > The calendar properties COMPLETED, CREATED, DTEND, DTSTAMP, DTSTART,
     * > DUE, and LAST-MODIFIED overlap a given time range if the following
     * > condition holds: (start <= date-time) AND (end > date-time)
     *
     * Null is no moment and no overlap, because every row of every table asks
     * first whether the property is there at all.
     */
    public function holds(?DateTimeImmutable $moment): bool
    {
        if ($moment === null) {
            return false;
        }

        return $this->startsAtOrBefore($moment) && $this->endsAfter($moment);
    }

    /**
     * The same range, moved along by so many seconds.
     *
     * **This is how one instance of a series is asked about** without writing
     * the instance out. §9.7.1 asks whether "at least one recurrence instance
     * in the targeted calendar component is scheduled to overlap the specified
     * time range", and an instance is its component shifted: asking whether a
     * range reaches an instance Δ after its component is the same question as
     * asking whether the range moved back by Δ reaches the component. So
     * {@see \DavServices\CalDav\Filter\CompFilter} moves the range and the
     * tables above are used exactly as they stand.
     *
     * **Seconds on a UTC instant, not an interval on a local one.** A shift
     * expressed as a `DateInterval` added to a local time would land in a
     * different offset across a change of season and move the range by an hour
     * nobody asked for; the ends of a range are "date with UTC time" values
     * (§9.9) and arithmetic on them is exact.
     *
     * An open end stays open: there is nothing to move about infinity.
     */
    public function moved(int $seconds): self
    {
        if ($seconds === 0) {
            return $this;
        }

        $by = new DateInterval('PT' . abs($seconds) . 'S');

        return $seconds > 0
            ? new self($this->from?->add($by), $this->until?->add($by))
            : new self($this->from?->sub($by), $this->until?->sub($by));
    }

    /**
     * Whether §9.9's last table is about this property at all.
     *
     * **Asked from outside as well as in**, which is why it is not written
     * into the method above: §7.8 makes a range nested in a `prop-filter` that
     * names another property a `CALDAV:valid-filter` violation, in those words
     * — "a CALDAV:filter cannot nest a <C:time-range start='...' end='...'>
     * element in a <C:prop name='SUMMARY'> element" — and the report that
     * refuses such a filter asks the list rather than keeping a second copy of
     * it.
     *
     * Without regard to case: RFC 5545 §3.1 writes the names in capitals and
     * RFC 5234 §2.3 makes the grammar's own spelling case-insensitive, so a
     * client that sent `dtstart` named `DTSTART`.
     */
    public static function isDefinedFor(string $property): bool
    {
        return in_array(strtoupper($property), self::DATED, true);
    }

    /**
     * Whether one period of a `FREEBUSY` value intersects the range.
     *
     * **§9.6.7 limits a value by this question**: "the server MUST only return
     * the FREEBUSY property values of a VFREEBUSY component that intersects a
     * specified time range", and "The server MUST use the same logic as
     * defined for CALDAV:time-range". That logic is §9.9's row for a component
     * with no `DTSTART`: `(start < freebusy-period-end) AND (end >
     * freebusy-period-start)`, so a period that merely touches an end of the
     * range does not intersect it.
     *
     * **A period this library cannot read does not intersect anything.** §9.6.7
     * asks for "only" the values that do, and one that cannot be read cannot
     * be shown to — the same answer {@see periodsIn()} gives it when a whole
     * component is judged.
     *
     * @param string $written One period, as a `FREEBUSY` value carries it
     *                        (RFC 5545 §3.8.2.6: `fbvalue = period *("," period)`)
     */
    public function intersectsThePeriod(string $written): bool
    {
        $period = self::periodOf($written);

        if ($period === null) {
            return false;
        }

        [$begins, $finishes] = $period;

        return $this->startsBefore($finishes) && $this->endsAfter($begins);
    }

    /**
     * Whether this component overlaps the range, by §9.9's table for its kind.
     *
     * @param Moments $moments What moment each of its properties names
     * @param Component|null $within The component a `VALARM` sits in, whose
     *                               start or end a relative trigger counts
     *                               from (RFC 5545 §3.8.6.3)
     */
    public function overlaps(Component $component, Moments $moments, ?Component $within = null): bool
    {
        $kind = strtoupper($component->name());

        if ($kind === 'VEVENT') {
            return $this->overlapsAnEvent($component, $moments);
        }

        if ($kind === 'VTODO') {
            return $this->overlapsAToDo($component, $moments);
        }

        if ($kind === 'VJOURNAL') {
            return $this->overlapsAJournalEntry($component, $moments);
        }

        if ($kind === 'VFREEBUSY') {
            return $this->overlapsFreeBusy($component, $moments);
        }

        if ($kind === 'VALARM') {
            return $this->overlapsAnAlarm($component, $moments, $within);
        }

        return false;
    }

    /**
     * §9.9's `VEVENT` table.
     *
     * | DTEND | DURATION | > 0 | DTSTART is DATE-TIME | Condition |
     * |---|---|---|---|---|
     * | Y | N | N | * | `(start <  DTEND AND end > DTSTART)` |
     * | N | Y | Y | * | `(start <  DTSTART+DURATION AND end > DTSTART)` |
     * | N | Y | N | * | `(start <= DTSTART AND end > DTSTART)` |
     * | N | N | N | Y | `(start <= DTSTART AND end > DTSTART)` |
     * | N | N | N | N | `(start <  DTSTART+P1D AND end > DTSTART)` |
     *
     * **An event without a `DTSTART` overlaps nothing**, because every row of
     * the table reads one: "as specified in [RFC2445], the DTSTART property is
     * REQUIRED in the VEVENT component", so an event without one is invalid
     * iCalendar and the table says nothing about it.
     */
    private function overlapsAnEvent(Component $component, Moments $moments): bool
    {
        $written = $component->property('DTSTART');
        $starts = $moments->of($written);

        if ($written === null || $starts === null) {
            return false;
        }

        $ends = $moments->of($component->property('DTEND'));

        if ($ends !== null) {
            return $this->startsBefore($ends) && $this->endsAfter($starts);
        }

        $reach = self::reachOf($component, $starts);

        if ($reach !== null) {
            return $reach > $starts
                ? $this->startsBefore($reach) && $this->endsAfter($starts)
                : $this->startsAtOrBefore($starts) && $this->endsAfter($starts);
        }

        return $this->coversAMomentOrADay($written, $starts);
    }

    /**
     * §9.9's `VTODO` table, in its own order.
     *
     * | DTSTART | DURATION | DUE | COMPLETED | CREATED | Condition |
     * |---|---|---|---|---|---|
     * | Y | Y | N | * | * | `(start <= DTSTART+DURATION) AND ((end > DTSTART) OR (end >= DTSTART+DURATION))` |
     * | Y | N | Y | * | * | `((start < DUE) OR (start <= DTSTART)) AND ((end > DTSTART) OR (end >= DUE))` |
     * | Y | N | N | * | * | `(start <= DTSTART) AND (end > DTSTART)` |
     * | N | N | Y | * | * | `(start < DUE) AND (end >= DUE)` |
     * | N | N | N | Y | Y | `((start <= CREATED) OR (start <= COMPLETED)) AND ((end >= CREATED) OR (end >= COMPLETED))` |
     * | N | N | N | Y | N | `(start <= COMPLETED) AND (end >= COMPLETED)` |
     * | N | N | N | N | Y | `(end > CREATED)` |
     * | N | N | N | N | N | `TRUE` |
     *
     * **Two combinations are missing from the table, and RFC 5545 §3.6.2 says
     * why:** "Either 'due' or 'duration' MAY appear in a 'todo' component, but
     * 'due' and 'duration' MUST NOT occur in the same 'todo'. Furthermore, if
     * 'duration' appear in a 'todo', then 'dtstart' MUST also appear." So a
     * `DUE` beside a `DURATION`, and a `DURATION` without a `DTSTART`, are
     * invalid iCalendar. Each is judged by the row that describes the
     * properties it does have, which is the nearest thing to an answer the
     * memo offers.
     */
    private function overlapsAToDo(Component $component, Moments $moments): bool
    {
        $starts = $moments->of($component->property('DTSTART'));
        $due = $moments->of($component->property('DUE'));

        if ($starts !== null) {
            $reach = self::reachOf($component, $starts);

            if ($reach !== null) {
                return $this->startsAtOrBefore($reach)
                    && ($this->endsAfter($starts) || $this->endsAtOrAfter($reach));
            }

            if ($due !== null) {
                return ($this->startsBefore($due) || $this->startsAtOrBefore($starts))
                    && ($this->endsAfter($starts) || $this->endsAtOrAfter($due));
            }

            return $this->startsAtOrBefore($starts) && $this->endsAfter($starts);
        }

        if ($due !== null) {
            return $this->startsBefore($due) && $this->endsAtOrAfter($due);
        }

        return $this->overlapsWhatIsLeftOfAToDo($component, $moments);
    }

    /**
     * The four rows of §9.9's `VTODO` table for a to-do that is scheduled for
     * no time at all.
     */
    private function overlapsWhatIsLeftOfAToDo(Component $component, Moments $moments): bool
    {
        $completed = $moments->of($component->property('COMPLETED'));
        $created = $moments->of($component->property('CREATED'));

        if ($completed !== null && $created !== null) {
            return ($this->startsAtOrBefore($created) || $this->startsAtOrBefore($completed))
                && ($this->endsAtOrAfter($created) || $this->endsAtOrAfter($completed));
        }

        if ($completed !== null) {
            return $this->startsAtOrBefore($completed) && $this->endsAtOrAfter($completed);
        }

        if ($created !== null) {
            return $this->endsAfter($created);
        }

        // "| N | N | N | N | N | TRUE |" — a to-do with no date in it at all
        // is in every range, because there is nothing to put it outside one.
        return true;
    }

    /**
     * §9.9's `VJOURNAL` table.
     *
     * | DTSTART | DATE-TIME | Condition |
     * |---|---|---|
     * | Y | Y | `(start <= DTSTART) AND (end > DTSTART)` |
     * | Y | N | `(start <  DTSTART+P1D) AND (end > DTSTART)` |
     * | N | * | `FALSE` |
     *
     * "The effective 'duration' of a VJOURNAL component is 1 day (+P1D) when
     * the DTSTART is a DATE value, and 0 seconds when the DTSTART is a
     * DATE-TIME value."
     */
    private function overlapsAJournalEntry(Component $component, Moments $moments): bool
    {
        $written = $component->property('DTSTART');
        $starts = $moments->of($written);

        if ($written === null || $starts === null) {
            return false;
        }

        return $this->coversAMomentOrADay($written, $starts);
    }

    /**
     * §9.9's `VFREEBUSY` table.
     *
     * | DTSTART and DTEND | FREEBUSY | Condition |
     * |---|---|---|
     * | Y | * | `(start <= DTEND) AND (end > DTSTART)` |
     * | N | Y | `(start < freebusy-period-end) AND (end > freebusy-period-start)` |
     * | N | N | `FALSE` |
     *
     * "Any DURATION property is ignored, as it has a special meaning when used
     * in a VFREEBUSY component." And of the periods: "each period in each
     * FREEBUSY property is compared against the time range, irrespective of
     * the type of free busy information (free, busy, busy-tentative,
     * busy-unavailable) represented by the property."
     */
    private function overlapsFreeBusy(Component $component, Moments $moments): bool
    {
        $starts = $moments->of($component->property('DTSTART'));
        $ends = $moments->of($component->property('DTEND'));

        if ($starts !== null && $ends !== null) {
            return $this->startsAtOrBefore($ends) && $this->endsAfter($starts);
        }

        foreach (self::periodsIn($component) as [$begins, $finishes]) {
            if ($this->startsBefore($finishes) && $this->endsAfter($begins)) {
                return true;
            }
        }

        return false;
    }

    /**
     * §9.9 on `VALARM`: "(start <= trigger-time) AND (end > trigger-time)".
     *
     * "A VALARM component can be defined such that it triggers repeatedly.
     * Such a VALARM component is said to overlap a given time range if at
     * least one of its triggers overlaps the time range."
     */
    private function overlapsAnAlarm(Component $component, Moments $moments, ?Component $within): bool
    {
        foreach (self::triggersOf($component, $moments, $within) as $trigger) {
            if ($this->holds($trigger)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every moment this alarm goes off.
     *
     * **A trigger is a duration by default** (RFC 5545 §3.8.6.3: "Value Type:
     * DURATION … The value type can be set to DATE-TIME"), counted from the
     * component the alarm sits in — from its start unless `RELATED=END` says
     * otherwise, which is the same section's default: "the 'RELATED' parameter
     * … the default is START".
     *
     * So a relative trigger with nothing around it goes off nowhere: there is
     * no start to count from, and inventing one would put an alarm in a range
     * at random.
     *
     * `REPEAT` and `DURATION` add the rest (§3.8.6.2, §3.8.5.1): "This
     * property defines the number of times the alarm should be repeated, after
     * the initial trigger."
     *
     * @return list<DateTimeImmutable>
     */
    private static function triggersOf(Component $component, Moments $moments, ?Component $within): array
    {
        $trigger = $component->property('TRIGGER');

        if ($trigger === null) {
            return [];
        }

        $first = self::firstTriggerOf($trigger, $moments, $within);

        if ($first === null) {
            return [];
        }

        $triggers = [$first];
        $step = self::durationOf($component);
        $times = (int) ($component->property('REPEAT')?->value() ?? '0');

        for ($at = 0; $step !== null && $at < $times; ++$at) {
            $first = $first->add($step);
            $triggers[] = $first;
        }

        return $triggers;
    }

    /**
     * The moment an alarm first goes off, or null where nothing says.
     */
    private static function firstTriggerOf(Property $trigger, Moments $moments, ?Component $within): ?DateTimeImmutable
    {
        if (strtoupper($trigger->parameter('VALUE')?->value() ?? '') === 'DATE-TIME') {
            return $moments->of($trigger);
        }

        if ($within === null) {
            return null;
        }

        $relative = strtoupper($trigger->parameter('RELATED')?->value() ?? 'START');
        $anchor = $relative === 'END'
            ? self::endOf($within, $moments)
            : $moments->of($within->property('DTSTART'));

        if ($anchor === null) {
            return null;
        }

        try {
            return $anchor->add(Duration::decode($trigger->value())->toDateInterval());
        } catch (ParseError) {
            return null;
        }
    }

    /**
     * Where a component the alarm hangs in ends: its `DTEND`, its `DUE`, or
     * its start plus its own duration.
     *
     * The three are what RFC 5545 gives a component to say so with — §3.8.2.2
     * for an event, §3.8.2.3 for a to-do, §3.8.2.5 for either — and §9.9's own
     * note says to work on the effective one: "if DTEND is not present in a
     * VEVENT, but DURATION is, then the test should instead operate on the
     * 'effective' DTEND, i.e., DTSTART+DURATION".
     */
    private static function endOf(Component $within, Moments $moments): ?DateTimeImmutable
    {
        $ends = $moments->of($within->property('DTEND')) ?? $moments->of($within->property('DUE'));

        if ($ends !== null) {
            return $ends;
        }

        $starts = $moments->of($within->property('DTSTART'));

        return $starts === null ? null : self::reachOf($within, $starts);
    }

    /**
     * Where a component's own `DURATION` reaches, or null where it has none
     * this library can read.
     */
    private static function reachOf(Component $component, DateTimeImmutable $starts): ?DateTimeImmutable
    {
        $duration = self::durationOf($component);

        return $duration === null ? null : $starts->add($duration);
    }

    /**
     * One component's `DURATION`, or null where it has none to read.
     */
    private static function durationOf(Component $component): ?DateInterval
    {
        $written = $component->property('DURATION')?->value();

        if ($written === null) {
            return null;
        }

        try {
            return Duration::decode($written)->toDateInterval();
        } catch (ParseError) {
            return null;
        }
    }

    /**
     * The two rows §9.9 writes twice: a value that names a moment covers that
     * moment, and one that names a day covers the day.
     *
     * The wording is the `VJOURNAL` table's, and the `VEVENT` table's last two
     * rows say the same: the effective duration is "1 day (+P1D) when the
     * DTSTART is a DATE value, and 0 seconds when the DTSTART is a DATE-TIME
     * value".
     */
    private function coversAMomentOrADay(Property $written, DateTimeImmutable $starts): bool
    {
        if (!Moments::isADate($written)) {
            return $this->startsAtOrBefore($starts) && $this->endsAfter($starts);
        }

        return $this->startsBefore($starts->add(new DateInterval(self::A_DAY)))
            && $this->endsAfter($starts);
    }

    /**
     * Every period of every `FREEBUSY` property, as a start and an end.
     *
     * A value is a list — §3.8.2.6: `fbvalue = period *("," period)` — and a
     * period this library cannot read is left out rather than thrown over: a
     * filter that fell over on one malformed property would answer nothing
     * about the rest of the calendar.
     *
     * @return list<array{DateTimeImmutable, DateTimeImmutable}>
     */
    private static function periodsIn(Component $component): array
    {
        $periods = [];

        foreach ($component->properties('FREEBUSY') as $property) {
            foreach (explode(',', $property->value()) as $written) {
                $period = self::periodOf($written);

                if ($period !== null) {
                    $periods[] = $period;
                }
            }
        }

        return $periods;
    }

    /**
     * One period as a start and an end, or null where it cannot be read.
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}|null
     */
    private static function periodOf(string $written): ?array
    {
        try {
            $period = Period::decode($written);
        } catch (ParseError) {
            return null;
        }

        $utc = new DateTimeZone('UTC');
        $begins = $period->start()->in($utc);
        $duration = $period->duration();

        if ($duration !== null) {
            return [$begins, $begins->add($duration->toDateInterval())];
        }

        $until = $period->end();

        // @codeCoverageIgnoreStart
        // **A period has one or the other, and the type system does not say
        // so.** {@see Period} is built from a `DateTime|Duration`, so a period
        // with neither cannot exist — but its two accessors are separately
        // nullable, and nothing declares them exclusive. The guard is for the
        // reader of the types rather than for any input.
        if ($until === null) {
            return null;
        }
        // @codeCoverageIgnoreEnd

        return [$begins, $until->in($utc)];
    }

    /**
     * One attribute as a moment, or null where it is not there.
     *
     * §9.9: "Both attributes MUST be specified as 'date with UTC time' value."
     * So the one form is the only form, and a floating time, a date, or a
     * local time in a named zone is a request this server cannot read rather
     * than one it guesses at.
     *
     * @throws BadRequest If the attribute is there and is no "date with UTC
     *                    time"
     */
    private static function momentOf(Element $element, string $attribute): ?DateTimeImmutable
    {
        $written = $element->attribute($attribute);

        if ($written === null) {
            return null;
        }

        try {
            $read = DateTime::decode($written);
        } catch (ParseError) {
            $read = null;
        }

        $moment = $read !== null && $read->isUtc() ? $read->instant() : null;

        if ($moment === null) {
            throw new BadRequest(sprintf(
                'The "%s" of a CALDAV:time-range is an iCalendar date with UTC time: RFC 4791 §9.9.',
                $attribute,
            ));
        }

        return $moment;
    }

    /**
     * `start <= X`, with "-infinity" for an open start.
     */
    private function startsAtOrBefore(DateTimeImmutable $moment): bool
    {
        return $this->from === null || $this->from <= $moment;
    }

    /**
     * `start < X`.
     */
    private function startsBefore(DateTimeImmutable $moment): bool
    {
        return $this->from === null || $this->from < $moment;
    }

    /**
     * `end > X`, with "+infinity" for an open end.
     */
    private function endsAfter(DateTimeImmutable $moment): bool
    {
        return $this->until === null || $this->until > $moment;
    }

    /**
     * `end >= X`.
     */
    private function endsAtOrAfter(DateTimeImmutable $moment): bool
    {
        return $this->until === null || $this->until >= $moment;
    }
}
