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
use DavServices\VObject\Component;
use DavServices\VObject\Parameter;
use DavServices\VObject\ParseError;
use DavServices\VObject\Property;
use DavServices\VObject\Recur\Iterator;
use DavServices\VObject\Value\Duration;
use DavServices\VObject\Value\Period;

/**
 * The busy time a set of calendar objects comes to (RFC 4791 §7.10).
 *
 * **One `VFREEBUSY` is the whole answer of a free/busy query**, and this is
 * where it is worked out: objects are handed in one at a time by whoever is
 * walking a collection, and the component comes out at the end.
 *
 *     $busy = new FreeBusy($range);
 *
 *     foreach ($objects as $path => [$object, $moments]) {
 *         $busy->consider($object, $moments);
 *     }
 *
 *     $calendar->add($busy->component($now));
 *
 * {@see Report\FreeBusyQuery} is that walker for §7.10's report. It is kept
 * apart from this because the two answer different questions: which resources
 * a request reaches is a matter of the protocol — a `Depth` header, a
 * Request-URI, who may see what — while what their busy time **is** belongs to
 * iCalendar, and RFC 6638 §4 asks for the second of those over a set of
 * attendees rather than a collection.
 *
 * ## What is considered, and what each component comes to
 *
 * §7.10: "Only VEVENT components without a TRANSP property or with the TRANSP
 * property set to OPAQUE, and VFREEBUSY components SHOULD be considered in
 * generating the free busy time information."
 *
 * So a to-do and a journal entry say nothing here, and neither does an event a
 * client marked as not taking up its time — RFC 5545 §3.8.2.7 is explicit
 * about what `TRANSPARENT` is for: "making them invisible to free/busy time
 * searches".
 *
 * **An event's type comes from §7.10's own table:**
 *
 *     +---------------------------++------------------+
 *     |          VEVENT           ||    VFREEBUSY     |
 *     +-------------+-------------++------------------+
 *     | TRANSP      | STATUS      || FBTYPE           |
 *     +=============+=============++==================+
 *     |             | CONFIRMED   || BUSY             |
 *     |             | (default)   ||                  |
 *     | OPAQUE      +-------------++------------------+
 *     | (default)   | CANCELLED   || FREE             |
 *     |             +-------------++------------------+
 *     |             | TENTATIVE   || BUSY-TENTATIVE   |
 *     |             +-------------++------------------+
 *     |             | x-name      || BUSY or          |
 *     |             |             || x-name           |
 *     +-------------+-------------++------------------+
 *     |             | CONFIRMED   ||                  |
 *     | TRANSPARENT | CANCELLED   || FREE             |
 *     |             | TENTATIVE   || (not considered) |
 *     |             | x-name      ||                  |
 *     +-------------+-------------++------------------+
 *
 * **Every row that comes to `FREE` comes to nothing at all**, because §7.10
 * says what the report is for: "This report only returns busy time
 * information. Free time information can be inferred from the returned busy
 * time information." A cancelled appointment is therefore not reported as free
 * — it is not reported.
 *
 * **The x-name row offers two answers and RFC 5545 §3.2.9 settles which is
 * better:** "Applications MUST treat x-name and iana-token values they don't
 * recognize the same way as they would the BUSY value." A status this server
 * has never heard of, written out as an `FBTYPE`, would say `BUSY` to every
 * client that read it and nothing more — so `BUSY` is what is written.
 *
 * **A `VFREEBUSY` brings its own types**, the table being "in the case of
 * VEVENT components" and nothing else. Of §3.2.9's values only `FREE` is left
 * out; the rest are busy time, including one this server does not know, by the
 * same sentence.
 *
 * ## Where the periods come from
 *
 * **Every instance of a series counts.** §9.9 makes that a MUST for every
 * report that filters by time — "Time range tests MUST consider every
 * recurrence instance when testing the time range condition" — so the
 * recurrence set is written out and each instance asked in turn.
 * {@see Expander} is where this library does that, held to the same range, so
 * that what a `calendar-query` finds and what a free/busy query reports cannot
 * drift apart. An instance somebody moved or called off is read from the
 * component overriding it, which falls out of the expansion rather than being
 * a case of its own.
 *
 * **A series too long to write out is considered as the component it comes
 * from.** That is what the expansion answers when R-RRULE-04's hard limit is
 * passed, and the memo's own cap on what a store may keep — §5.2.8's
 * `CALDAV:max-instances` — means a resource that got in through this library
 * cannot be one of these.
 *
 * **How long an event lasts is §9.9's table**, the same one `time-range` is
 * read with: a `DTEND` where there is one, else `DTSTART` plus the `DURATION`,
 * else "1 day (+P1D) when the DTSTART is a DATE value, and 0 seconds when the
 * DTSTART is a DATE-TIME value". The arithmetic is deliberately the arithmetic
 * of {@see TimeRange}, down to a `DURATION` nobody can read falling through to
 * the default — an event that section says overlaps the range and this one
 * found no period in would be a disagreement between two readings of one
 * table.
 *
 * **And a stretch of no length is no period.** RFC 5545 §3.3.9 carries a MUST
 * for each of the two forms a `PERIOD` has — "The start MUST be before the
 * end" and "a period of time consisting of a start and positive duration of
 * time" — so a busy time of nothing cannot be written at all. A timed event
 * with neither end nor length is one of those, and so is the invalid pair
 * §9.9 warns of, "the value of the DTEND property MUST be later in time than
 * the value of the DTSTART property".
 *
 * ## What the answer looks like
 *
 * **A period is cut to the window it is reported in.** RFC 5545 §3.6.4 has the
 * `DTSTART` and `DTEND` of a published `VFREEBUSY` "specify an inclusive time
 * window that surrounds the busy time information", so a period reaching past
 * either edge would contradict the window it sits in. An edge §9.9 left open
 * cuts nothing, and the property that would name it is not written: both are
 * optional in §3.6.4.
 *
 * **Periods of one type are brought together.** §7.10: "Duplicate busy time
 * periods with the same FBTYPE parameter value SHOULD NOT be specified in the
 * returned VFREEBUSY component. Servers SHOULD coalesce consecutive or
 * overlapping busy time periods of the same type. Busy time periods with
 * different FBTYPE parameter values MAY overlap." So two of a kind that touch
 * become one and two of different kinds stay two.
 *
 * **They are written earliest first**, which §3.8.2.6 asks for: "'FREEBUSY'
 * properties within the 'VFREEBUSY' calendar component SHOULD be sorted in
 * ascending order, based on start time and then end time, with the earliest
 * periods first." §7.10.1's own example reads that way too.
 *
 * **One property per period.** §3.8.2.6 allows a list — `fbvalue = period
 * *("," period)` — under a MUST that comes with it: "In such cases, the
 * 'FREEBUSY' property values MUST all be of the same 'FBTYPE' property
 * parameter type". A property carrying one value cannot break that, and
 * writing one each is what keeps the ascending order above readable across
 * types. It is also the shape §7.10.1 writes.
 *
 * **A period of type `BUSY` carries no parameter**, §3.2.9 making it the
 * default: "If not specified on a property that allows this parameter, the
 * default is BUSY." Saying it would say what silence already means, and
 * §7.10.1's example leaves it out.
 */
final class FreeBusy
{
    public const KIND = 'VFREEBUSY';

    private const EVENT = 'VEVENT';

    private const PERIODS = 'FREEBUSY';

    private const TYPE = 'FBTYPE';

    /** §3.2.9's default, and the one value written without the parameter. */
    private const BUSY = 'BUSY';

    private const BUSY_TENTATIVE = 'BUSY-TENTATIVE';

    /** The one type of §3.2.9's that is not busy time, so not reported. */
    private const FREE = 'FREE';

    private const TRANSPARENCY = 'TRANSP';

    /** §3.8.2.7's default, and the only value §7.10 considers. */
    private const OPAQUE = 'OPAQUE';

    private const STATUS = 'STATUS';

    private const CANCELLED = 'CANCELLED';

    private const TENTATIVE = 'TENTATIVE';

    private const START = 'DTSTART';

    private const END = 'DTEND';

    private const DURATION = 'DURATION';

    private const STAMP = 'DTSTAMP';

    private const NAME = 'UID';

    /** §9.9's effective duration of an event whose start names a day. */
    private const A_DAY = 'P1D';

    private const UTC = 'UTC';

    /** The one form §3.8.2.6 admits: "The date and time MUST be a UTC time". */
    private const MOMENT = 'Ymd\THis\Z';

    private readonly Expander $expander;

    /**
     * Every busy stretch found so far, as a type and two moments.
     *
     * @var list<array{string, DateTimeImmutable, DateTimeImmutable}>
     */
    private array $periods = [];

    /**
     * @param TimeRange $range What was asked about, which is both the window
     *                         the answer covers and the cut every period is
     *                         held to
     * @param int $iterations The hard limit an expansion is given
     *                        (R-RRULE-04), which a caller measuring something
     *                        smaller than a calendar can narrow
     */
    public function __construct(
        private readonly TimeRange $range,
        int $iterations = Iterator::ITERATIONS,
    ) {
        $this->expander = Expander::overTheRange($range, $iterations);
    }

    /**
     * Takes one calendar object resource into account.
     *
     * @param Moments $moments What moment each of the object's properties
     *                         names, which §7.3 settles the floating ones of
     */
    public function consider(Component $object, Moments $moments): void
    {
        foreach ($this->expander->appliedTo($object, $moments)->components() as $component) {
            $kind = strtoupper($component->name());

            if ($kind === self::EVENT) {
                $this->considerAnEvent($component, $moments);

                continue;
            }

            if ($kind === self::KIND) {
                $this->considerWhatIsPublished($component);
            }
        }
    }

    /**
     * The one `VFREEBUSY` all of it comes to.
     *
     * @param DateTimeImmutable $now What the answer says it was made at.
     *                               RFC 5545 §3.6.4 makes `DTSTAMP` REQUIRED
     *                               and §3.8.7.2 has it name "the date and
     *                               time that the instance of the iCalendar
     *                               object was created"
     */
    public function component(DateTimeImmutable $now): Component
    {
        $written = new Component(self::KIND);

        $written->add(new Property(self::STAMP, self::moment($now)));

        // §3.6.4 makes `UID` REQUIRED beside it, and §3.8.4.7 asks of the
        // value that it "MUST be a globally unique identifier". §7.10.1's own
        // example carries none, and an example is no definition — an answer
        // this server writes is iCalendar a reader can validate.
        $written->add(new Property(self::NAME, bin2hex(random_bytes(16))));

        $from = $this->range->from();

        if ($from !== null) {
            $written->add(new Property(self::START, self::moment($from)));
        }

        $until = $this->range->until();

        if ($until !== null) {
            $written->add(new Property(self::END, self::moment($until)));
        }

        foreach ($this->coalesced() as [$type, $begins, $finishes]) {
            $written->add(new Property(
                self::PERIODS,
                self::moment($begins) . '/' . self::moment($finishes),
                $type === self::BUSY ? [] : [new Parameter(self::TYPE, [$type])],
            ));
        }

        return $written;
    }

    /**
     * Takes one event into account, by §7.10's table.
     */
    private function considerAnEvent(Component $component, Moments $moments): void
    {
        $transparency = $component->property(self::TRANSPARENCY)?->value() ?? self::OPAQUE;

        // §7.10 considers an event "without a TRANSP property or with the
        // TRANSP property set to OPAQUE", so a `TRANSPARENT` one is passed
        // over — and so is a third value, which §3.8.2.7's `transvalue` does
        // not admit at all.
        if (strcasecmp($transparency, self::OPAQUE) !== 0) {
            return;
        }

        $status = $component->property(self::STATUS)?->value() ?? '';

        // | OPAQUE | CANCELLED || FREE |, and free time is not reported.
        if (strcasecmp($status, self::CANCELLED) === 0) {
            return;
        }

        $start = $component->property(self::START);
        $begins = $moments->of($start);

        // @codeCoverageIgnoreStart
        // **An event without a start covers nothing**, every row of §9.9's
        // table reading one: "as specified in [RFC2445], the DTSTART property
        // is REQUIRED in the VEVENT component".
        //
        // **And no such event reaches this, because the expansion already
        // asked that table.** Every component {@see Expander} hands over got
        // there through `TimeRange::overlaps()`, and its row for a `VEVENT`
        // begins by reading a start it can resolve — so an event without one
        // was dropped before the type was ever worked out. The guard is for
        // the reader of the types rather than for any input, and
        // {@see Expander::written()} carries the same one for the same reason.
        if ($start === null || $begins === null) {
            return;
        }
        // @codeCoverageIgnoreEnd

        $this->keep(
            strcasecmp($status, self::TENTATIVE) === 0 ? self::BUSY_TENTATIVE : self::BUSY,
            $begins,
            self::endOf($component, $start, $begins, $moments),
        );
    }

    /**
     * Takes the periods a `VFREEBUSY` publishes into account, each with the
     * type it was published under.
     */
    private function considerWhatIsPublished(Component $component): void
    {
        foreach ($component->properties(self::PERIODS) as $property) {
            $type = strtoupper($property->parameter(self::TYPE)?->value() ?? self::BUSY);

            if ($type === self::FREE) {
                continue;
            }

            // §3.8.2.6: `fbvalue = period *("," period)`. A period this
            // library cannot read is left out rather than thrown over — an
            // answer that fell over on one malformed value would say nothing
            // about the rest of the calendar.
            foreach (explode(',', $property->value()) as $written) {
                try {
                    $this->keep($type, ...Period::decode($written)->instants());
                } catch (ParseError) {
                    continue;
                }
            }
        }
    }

    /**
     * Keeps one busy stretch, cut to the window it is reported in.
     *
     * A stretch left with no length by the cut is dropped, as one that had
     * none to begin with is: §3.3.9 has no period of nothing.
     */
    private function keep(string $type, DateTimeImmutable $begins, DateTimeImmutable $finishes): void
    {
        $from = $this->range->from();
        $until = $this->range->until();

        // The later of where the stretch begins and where the window does, and
        // the earlier of the two ends. An edge §9.9 left open cuts nothing.
        $start = max($begins, $from ?? $begins);
        $end = min($finishes, $until ?? $finishes);

        if ($end <= $start) {
            return;
        }

        $this->periods[] = [$type, $start, $end];
    }

    /**
     * The periods, brought together per type and written earliest first.
     *
     * @return list<array{string, DateTimeImmutable, DateTimeImmutable}>
     */
    private function coalesced(): array
    {
        $byType = [];

        foreach ($this->periods as [$type, $begins, $finishes]) {
            $byType[$type][] = [$begins, $finishes];
        }

        $written = [];

        foreach ($byType as $type => $periods) {
            foreach (self::merged($periods) as [$begins, $finishes]) {
                $written[] = [$type, $begins, $finishes];
            }
        }

        // §3.8.2.6: "sorted in ascending order, based on start time and then
        // end time, with the earliest periods first". The type decides nothing
        // but the order of two that are otherwise the same stretch, so that an
        // answer does not depend on which object was read first.
        usort(
            $written,
            static fn (array $one, array $other): int
                => [$one[1], $one[2], $one[0]] <=> [$other[1], $other[2], $other[0]],
        );

        return $written;
    }

    /**
     * One type's periods, with the consecutive and the overlapping ones joined.
     *
     * @param list<array{DateTimeImmutable, DateTimeImmutable}> $periods
     *
     * @return list<array{DateTimeImmutable, DateTimeImmutable}>
     */
    private static function merged(array $periods): array
    {
        usort($periods, static fn (array $one, array $other): int => $one[0] <=> $other[0]);

        $joined = [];

        foreach ($periods as [$begins, $finishes]) {
            $open = array_key_last($joined);

            // "consecutive or overlapping": one that starts where the open
            // stretch ends is consecutive, and one that starts before it is
            // overlapping. The stretches arrive in order of their starts, so
            // only the last of them can be reached.
            if ($open === null || $begins > $joined[$open][1]) {
                $joined[] = [$begins, $finishes];

                continue;
            }

            $joined[$open][1] = max($joined[$open][1], $finishes);
        }

        return $joined;
    }

    /**
     * Where one event reaches, by §9.9's own order: its `DTEND`, else its
     * `DURATION` from the start, else the duration that section gives an
     * event carrying neither.
     *
     * **RFC 5545 §3.6.1 forbids an event from carrying both** — "'dtend' and
     * 'duration' MUST NOT occur in the same 'eventprop'" — so one that does
     * is no iCalendar a store should hold, and §9.9's order ("if DTEND is not
     * present in a VEVENT, but DURATION is") is the nearest thing to an
     * answer the memo offers for it.
     */
    private static function endOf(
        Component $component,
        Property $start,
        DateTimeImmutable $begins,
        Moments $moments,
    ): DateTimeImmutable {
        return $moments->of($component->property(self::END))
            ?? self::reachOf($component, $begins)
            ?? self::theDefaultEndOf($start, $begins);
    }

    /**
     * Where a component's own `DURATION` reaches, or null where it has none
     * this library can read.
     *
     * §9.9: "if DTEND is not present in a VEVENT, but DURATION is, then the
     * test should instead operate on the 'effective' DTEND, i.e.,
     * DTSTART+DURATION".
     */
    private static function reachOf(Component $component, DateTimeImmutable $begins): ?DateTimeImmutable
    {
        $written = $component->property(self::DURATION)?->value();

        if ($written === null) {
            return null;
        }

        try {
            return $begins->add(Duration::decode($written)->toDateInterval());
        } catch (ParseError) {
            return null;
        }
    }

    /**
     * §9.9's last two rows for an event: "The duration of a VEVENT component
     * with no DTEND and DURATION properties is 1 day (+P1D) when the DTSTART
     * is a DATE value, and 0 seconds when the DTSTART is a DATE-TIME value."
     *
     * Nothing is no period, which is what the caller's own test for a stretch
     * of no length then answers.
     */
    private static function theDefaultEndOf(Property $start, DateTimeImmutable $begins): DateTimeImmutable
    {
        return Moments::isADate($start) ? $begins->add(new DateInterval(self::A_DAY)) : $begins;
    }

    /**
     * One moment as §3.8.2.6's "date with UTC time".
     */
    private static function moment(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone(self::UTC))->format(self::MOMENT);
    }
}
