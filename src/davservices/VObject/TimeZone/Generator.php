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
use DavServices\VObject\Property;
use InvalidArgumentException;

/**
 * Writes the `VTIMEZONE` a span of time needs (RFC 5545 §3.6.5, R-TZ-05).
 *
 *     $written = Generator::covering($zone, $from, $until);   // Component
 *
 * > R-TZ-05: Es MUSS eine Funktion geben, die für einen Zeitraum eine
 * > minimale, gültige `VTIMEZONE` erzeugt.
 *
 * **The span is the question, not a detail.** §3.6.5 publishes a definition of
 * exactly this shape — two `DTSTART`s and no rule — and names its limit in the
 * same breath: "Note that this is only suitable for a recurring event that
 * starts on or later than March 11, 2007 … and ends no later than March 9,
 * 2008 at 01:59:59 EST." A definition without a rule covers the time it
 * describes and no more, so what it has to describe must be said.
 *
 * ## What is written
 *
 * **One observance for the state at the start of the span**, and one for every
 * change of offset inside it. The first carries the same offset on both sides,
 * which is how "from here on this is the offset" is written down; the others
 * carry the offset before the change and the offset after it.
 *
 * **The state at the start rather than the last change before it**, which the
 * memo's example happens to use: a change before the span is an assertion
 * about time nobody asked about, and a zone that has never changed has none to
 * write. This way the span is covered by construction, and every zone gets the
 * one observance §3.6.5 requires — "One of 'standardc' or 'daylightc' MUST
 * occur".
 *
 * **And no `RRULE`.** A rule would say the zone goes on changing as it does
 * now; no zone database knows that, and several governments have disproved it
 * at a few weeks' notice. What is written here is what the database says about
 * the span asked for.
 *
 * ## The onset is written in the offset before it
 *
 * > "TZOFFSETFROM" is combined with "DTSTART" to define the effective onset
 * > for the time zone sub-component definition.
 *
 * So a change at an instant is written as that instant **plus `TZOFFSETFROM`**
 * — the offset that was in force before it. Writing it in `TZOFFSETTO` instead
 * is the same mistake as reading it that way, and it puts every change an hour
 * out: Berlin's spring change at 01:00 UTC is `20240331T020000`, which is the
 * last hour of winter time, and not `20240331T030000`, which is an hour nobody
 * had.
 *
 * {@see Definition} reads back what this writes, which is what the tests use:
 * the generated definition is asked for its offsets and held against the zone
 * database it came from.
 */
final class Generator
{
    /**
     * The `VTIMEZONE` that covers a span of time in a zone.
     *
     * @throws InvalidArgumentException If the span ends before it begins
     */
    public static function covering(
        DateTimeZone $zone,
        DateTimeImmutable $from,
        DateTimeImmutable $until,
    ): Component {
        if ($until < $from) {
            throw new InvalidArgumentException(sprintf(
                'A VTIMEZONE is written for a span, and this span ends before it begins: %s to %s.',
                $from->format('c'),
                $until->format('c'),
            ));
        }

        $written = new Component('VTIMEZONE');

        // **The name the zone gives itself**, whatever that is: §3.2.19
        // defines no naming convention — "This document does not define a
        // naming convention for time zone identifiers" — and PHP answers a
        // couple of the database's own names with a fixed offset instead
        // (`GMT+0` comes back as `+00:00`). What makes that safe is the
        // definition written beside it: a reader that cannot place the name
        // evaluates the observances, which is R-TZ-02.
        $written->add(new Property('TZID', $zone->getName()));

        $before = $zone->getOffset($from);

        foreach (self::statesAcross($zone, $from, $until) as $state) {
            $written->add(self::observance($state['ts'], $before, $state['offset'], $state['isdst']));
            $before = $state['offset'];
        }

        return $written;
    }

    /**
     * The state at the start of the span, and every change of offset in it.
     *
     * **Found by asking the zone, not by asking for a list of changes.**
     * `DateTimeZone::getTransitions()` is the obvious way and returns `false`
     * for eleven of the database's own zones — `CET`, `EET`, `EST`, `GMT`,
     * `GMT+0`, `GMT-0`, `HST`, `MET`, `MST`, `UCT` and `WET` — which a
     * generator that trusted it would answer with no observance at all, where
     * §3.6.5 requires one. `DateTimeZone::getOffset()` answers for every zone
     * there is, so the changes are looked for with that: by the day, and then
     * narrowed to the second.
     *
     * **The last window is closed on the end of the span** rather than a day
     * past it, so that a span of two hours holding a change finds it too.
     *
     * @return non-empty-list<array{ts: int, offset: int, isdst: bool}>
     */
    private static function statesAcross(
        DateTimeZone $zone,
        DateTimeImmutable $from,
        DateTimeImmutable $until,
    ): array {
        $begin = $from->getTimestamp();
        $end = $until->getTimestamp();
        $before = $zone->getOffset($from);
        $states = [self::state($zone, $begin, $before)];

        for ($probe = $begin; $probe < $end;) {
            $next = min($probe + 86400, $end);
            $offset = $zone->getOffset(new DateTimeImmutable('@' . $next));

            if ($offset !== $before) {
                $onset = self::onsetBetween($zone, $probe, $next, $before);
                $states[] = self::state($zone, $onset, $offset);
                $before = $offset;
            }

            $probe = $next;
        }

        return $states;
    }

    /**
     * The first second at which the zone no longer keeps the offset it kept at
     * the start of this window.
     *
     * There is one such second in the window, the caller having found that the
     * offset differs at its end.
     */
    private static function onsetBetween(DateTimeZone $zone, int $low, int $high, int $before): int
    {
        while ($high - $low > 1) {
            $middle = intdiv($low + $high, 2);

            if ($zone->getOffset(new DateTimeImmutable('@' . $middle)) === $before) {
                $low = $middle;
            } else {
                $high = $middle;
            }
        }

        return $high;
    }

    /**
     * One state of the zone: when it is in force, and what it is.
     *
     * **Whether summer time is in force is asked of PHP** rather than worked
     * out from the offsets: `I` is its own answer, and two zones can keep the
     * same offset with and without summer time.
     *
     * @return array{ts: int, offset: int, isdst: bool}
     */
    private static function state(DateTimeZone $zone, int $moment, int $offset): array
    {
        return [
            'ts' => $moment,
            'offset' => $offset,
            'isdst' => (new DateTimeImmutable('@' . $moment))->setTimezone($zone)->format('I') === '1',
        ];
    }

    /**
     * One observance: when it begins, in whose offset that is written, and the
     * offset it brings.
     */
    private static function observance(int $onset, int $before, int $offset, bool $daylight): Component
    {
        $observance = new Component($daylight ? 'DAYLIGHT' : 'STANDARD');

        // §3.6.5: "DTSTART in this usage MUST be specified as a date with a
        // local time value", and the local time of an onset is its instant
        // taken in the offset that was in force before it.
        $observance->add(new Property(
            'DTSTART',
            (new DateTimeImmutable('@' . ($onset + $before)))->format('Ymd\THis'),
        ));
        $observance->add(new Property('TZOFFSETFROM', self::written($before)));
        $observance->add(new Property('TZOFFSETTO', self::written($offset)));

        return $observance;
    }

    /**
     * An offset as §3.3.14 writes one.
     *
     * The seconds are written only where there are any, the grammar making
     * them optional — `time-numzone = ("+" / "-") time-hour time-minute
     * [time-second]` — and the database keeps them for the local mean times of
     * the nineteenth century: Berlin was `+00:53:28` until 1893.
     */
    private static function written(int $seconds): string
    {
        $sign = $seconds < 0 ? '-' : '+';
        $size = abs($seconds);
        $written = sprintf('%s%02d%02d', $sign, intdiv($size, 3600), intdiv($size % 3600, 60));

        if ($size % 60 === 0) {
            return $written;
        }

        return $written . sprintf('%02d', $size % 60);
    }
}
