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
use DavServices\VObject\Recur\Zone;
use DavServices\VObject\Value\DateTime;

/**
 * A zone the system knows by name, answering the one question the expansion
 * asks of a zone ({@see Zone}).
 *
 * **`DateTimeZone` knows a zone's whole history**, which is what makes a named
 * zone the first way in (R-TZ-01) and a supplied `VTIMEZONE` the fallback
 * (R-TZ-02). What it does not do is say that a wall clock names no moment:
 * `new DateTimeImmutable('2024-03-31 02:30', $berlin)` answers half past
 * three, **inventing an hour nobody had**. §3.3.10 asks for that instance to
 * be ignored instead, so the moment is worked out here rather than asked for.
 *
 * ## How a wall clock is turned into a moment
 *
 * An offset is less than a day from UTC, so the moment a wall clock names lies
 * within a day of that same wall clock read as UTC. **The candidates are
 * therefore the offsets the zone keeps within a day either side**, and each
 * one is tried against itself: a candidate moment is the wall clock minus the
 * offset, and it is the right one only where the zone really keeps that offset
 * at that moment.
 *
 * **The largest offset is tried first**, because it puts the same wall clock
 * at the earliest moment — which is how {@see Zone}'s rule about a repeated
 * hour falls out of the arithmetic rather than being bolted on to it.
 *
 * A zone that changed its offset twice within two days would hide a candidate
 * from this, and no zone has ever done so; a `VTIMEZONE` that claims it is
 * read by {@see Definition}, which works from the declared offsets themselves
 * and needs no window at all.
 */
final class Named implements Zone
{
    private function __construct(private readonly DateTimeZone $zone)
    {
    }

    /**
     * The zone of that name, as the expansion asks about one.
     */
    public static function of(DateTimeZone $zone): self
    {
        return new self($zone);
    }

    /**
     * The moment a local `DATE-TIME` names in this zone, or null where that
     * local time does not exist.
     *
     * **Worked out rather than asked for**, `DateTimeImmutable` answering a
     * wall clock in the missing hour with one that exists instead.
     */
    public function momentOf(DateTime $local): ?DateTimeImmutable
    {
        $wall = new DateTimeImmutable($local->encode(), new DateTimeZone('UTC'));

        if ($local->isUtc()) {
            return $wall;
        }

        foreach ($this->offsetsAround($wall) as $offset) {
            $candidate = $wall->setTimestamp($wall->getTimestamp() - $offset);

            if ($this->zone->getOffset($candidate) === $offset) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Every offset this zone keeps within a day of that moment, largest first.
     *
     * @return list<int>
     */
    private function offsetsAround(DateTimeImmutable $wall): array
    {
        $offsets = [];

        // **Probed forwards first, on purpose.** Where the clocks go back, the
        // larger offset is the one in force *before* the change, so probing
        // backwards first would hand back a list that happens to be in the
        // right order and leave the sorting below doing nothing — and the
        // order is the whole of the rule about a repeated hour. This way the
        // sort is the reason the answer is right, not the probing.
        foreach ([86400, 0, -86400] as $shift) {
            $probe = $wall->setTimestamp($wall->getTimestamp() + $shift);
            $offsets[$this->zone->getOffset($probe)] = $this->zone->getOffset($probe);
        }

        $candidates = array_values($offsets);
        rsort($candidates);

        return $candidates;
    }
}
