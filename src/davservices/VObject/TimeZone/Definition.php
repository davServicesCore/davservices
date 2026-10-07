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

namespace DavServices\VObject\TimeZone;

use DateTimeImmutable;
use DateTimeZone;
use DavServices\VObject\Component;
use DavServices\VObject\ParseError;
use DavServices\VObject\Recur\Iterator;
use DavServices\VObject\Value\DateTime;

/**
 * The zone a `VTIMEZONE` defines by itself (RFC 5545 §3.6.5, R-TZ-02).
 *
 *     $definition = Definition::of($vtimezone);          // ?Definition
 *     $offset = $definition->offsetAt($moment);          // seconds from UTC
 *     $moment = $definition->momentOf($localValue);      // ?DateTimeImmutable
 *
 * **The definition travels in the object, and that is the point.** R-TZ-02:
 * "Ohne Zuordnung MUSS die `VTIMEZONE` selbst ausgewertet werden." A server
 * that only ever asks its own zone database cannot read an object whose zone
 * it has not got — and {@see Resolver}, which does the asking, answers such a
 * name with null on purpose rather than quietly reading it as UTC. **This is
 * what happens next**, and between the two there is no case left in which a
 * zone silently becomes UTC.
 *
 * **The two are asked in R-TZ-01's order** — the name first, this second — and
 * they stay two entry points, because what they answer with is not the same
 * kind of thing: a name comes to a `DateTimeZone`, which knows a zone's whole
 * history, while a definition answers about the moments its own observances
 * cover. A common type for both belongs where something needs to hold either,
 * which is the expansion of a rule with a `TZID` (R-RRULE-05), and it is left
 * to that chunk rather than guessed at here.
 *
 * ## The one sentence this class comes from
 *
 * > The offset to apply at any given time is found by locating the observance
 * > that has the last onset date and time before the time in question, and
 * > using the offset value from that observance.
 *
 * So the sub-components are **one set of onsets**, not two series that take
 * turns: "For a given time zone, there may be multiple unique definitions of
 * the observances over a period of time. … The collection of these
 * sub-components is used to describe the time zone for a given period of
 * time." The memo's own New York example has seven of them, overlapping and
 * succeeding one another, and the answer at any moment is the offset of the
 * latest onset that has happened. {@see Observance} holds one of them and
 * knows where its own onsets fall.
 *
 * **Before every onset** there is no observance in force, and the definition
 * still answers: the earliest observance says which offset "is in use when the
 * onset … begins", and that is the offset before it. It is also why §3.6.5
 * says of its `DTSTART`-only example that it is "only suitable for a recurring
 * event that starts on or later than March 11, 2007".
 *
 * ## Reading a local time back
 *
 * {@see self::momentOf()} is the inverse, and it is the question every
 * `DTSTART;TZID=…` asks. Two answers there are not a single moment, and the
 * memo settles neither:
 *
 * - **A local time that does not exist has none.** §3.3.10 names it — "an
 *   invalid date (e.g., February 30) or nonexistent local time (e.g., 1:30 AM
 *   on a day where the local time is moved forward by an hour at 1:00 AM)" —
 *   and null is how a caller is told, so that it can do what the memo asks and
 *   ignore the instance.
 * - **A local time that happens twice comes to the earlier moment**, which is
 *   the one the clock showed first. The other reading would understand a
 *   written time as belonging to an observance that had not begun when that
 *   time was on the clock.
 *
 * **A `DATE` cannot be asked at all**, which is why the parameter is a
 * `DATE-TIME`: R-TZ-04 forbids turning an all-day value into an instant, and a
 * type is a better place to say so than a refusal at run time.
 */
final class Definition
{
    /**
     * @param non-empty-list<Observance> $observances
     */
    private function __construct(private readonly array $observances)
    {
    }

    /**
     * The zone a `VTIMEZONE` defines, or null where it defines none.
     *
     * **Null means there is nothing to evaluate**: §3.6.5 requires at least
     * one observance — "One of 'standardc' or 'daylightc' MUST occur" — and a
     * component without one is a thing {@see \DavServices\VObject\Validator}
     * reports rather than something this class can guess at.
     *
     * @param int $iterations The hard limit each observance's expansion is
     *                        given (R-CAL-08)
     *
     * @throws ParseError If an observance leaves out one of the three
     *                    properties §3.6.5 makes mandatory, or names a moment
     *                    where the memo requires a local time
     */
    public static function of(Component $zone, int $iterations = Iterator::ITERATIONS): ?self
    {
        $observances = [];

        foreach ([...$zone->components('STANDARD'), ...$zone->components('DAYLIGHT')] as $observance) {
            $observances[] = Observance::of($observance, $iterations);
        }

        if ($observances === []) {
            return null;
        }

        return new self($observances);
    }

    /**
     * The offset from UTC this definition applies at a moment, in seconds.
     *
     * @throws \DavServices\VObject\Recur\TooManyIterations If reaching the
     *                                                      moment passes an
     *                                                      expansion's limit
     */
    public function offsetAt(DateTimeImmutable $moment): int
    {
        $observance = $this->inForceAt($moment);

        if ($observance === null) {
            return $this->earliest()->offsetBefore();
        }

        return $observance->offset();
    }

    /**
     * The customary name of the zone at a moment, or null where none is given.
     *
     * Null is also the answer before every onset: a `TZNAME` belongs to an
     * observance, and before the first one none is in force.
     *
     * @throws \DavServices\VObject\Recur\TooManyIterations If reaching the
     *                                                      moment passes an
     *                                                      expansion's limit
     */
    public function nameAt(DateTimeImmutable $moment): ?string
    {
        return $this->inForceAt($moment)?->name();
    }

    /**
     * The moment a local `DATE-TIME` names in this zone, or null where the
     * local time does not exist.
     *
     * A value that is already an instant — FORM #2, the one the trailing `Z`
     * fixes — keeps it, there being nothing a zone can add to a moment.
     *
     * @throws \DavServices\VObject\Recur\TooManyIterations If reaching the
     *                                                      moment passes an
     *                                                      expansion's limit
     */
    public function momentOf(DateTime $local): ?DateTimeImmutable
    {
        $wall = new DateTimeImmutable($local->encode(), new DateTimeZone('UTC'));

        if ($local->isUtc()) {
            return $wall;
        }

        // A larger offset puts the same wall clock at an earlier moment, so
        // the first offset that agrees with itself is the earliest moment the
        // local time can mean — which is the reading set out in the class.
        $offsets = $this->offsets();
        rsort($offsets);

        foreach ($offsets as $offset) {
            $candidate = $wall->setTimestamp($wall->getTimestamp() - $offset);

            if ($this->offsetAt($candidate) === $offset) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The observance with the last onset at or before a moment, or null where
     * none of them had begun.
     *
     * **Two observances beginning at the very same instant say two things at
     * once**, and §3.6.5 gives no rule for it; nothing here claims one, so
     * which of them wins is not a promise this class makes.
     */
    private function inForceAt(DateTimeImmutable $moment): ?Observance
    {
        $found = null;
        $latest = null;

        foreach ($this->observances as $observance) {
            $onset = $observance->lastOnsetUpTo($moment);

            if ($onset === null) {
                continue;
            }

            if ($latest !== null && $onset <= $latest) {
                continue;
            }

            $latest = $onset;
            $found = $observance;
        }

        return $found;
    }

    /**
     * The observance that begins before all the others.
     */
    private function earliest(): Observance
    {
        $earliest = $this->observances[0];

        foreach ($this->observances as $observance) {
            if ($observance->first() < $earliest->first()) {
                $earliest = $observance;
            }
        }

        return $earliest;
    }

    /**
     * Every offset this definition ever keeps, each of them once.
     *
     * Both halves of every observance count: a `TZOFFSETTO` is an offset the
     * zone keeps after an onset, and a `TZOFFSETFROM` one it kept before it —
     * which is the only offset there is before the first onset of all.
     *
     * @return list<int>
     */
    private function offsets(): array
    {
        $offsets = [];

        foreach ($this->observances as $observance) {
            $offsets[$observance->offset()] = $observance->offset();
            $offsets[$observance->offsetBefore()] = $observance->offsetBefore();
        }

        return array_values($offsets);
    }
}
