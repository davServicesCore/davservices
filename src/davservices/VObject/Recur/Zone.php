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

namespace DavServices\VObject\Recur;

use DateTimeImmutable;
use DavServices\VObject\Value\DateTime;

/**
 * What the expansion needs of a time zone: the moment a wall clock names.
 *
 * **One question, because that is all the expansion asks.** The arithmetic of
 * a recurrence runs on the wall clock — {@see Iterator} sets out why, and
 * §3.8.5.3's own published answers require it — so a zone is needed for
 * exactly two things, and they are the same question twice:
 *
 * - **whether an instance exists at all.** §3.3.10: "Recurrence rules may
 *   generate recurrence instances with an invalid date (e.g., February 30) or
 *   **nonexistent local time** (e.g., 1:30 AM on a day where the local time is
 *   moved forward by an hour at 1:00 AM). Such recurrence instances MUST be
 *   ignored and MUST NOT be counted as part of the recurrence set." A null
 *   answer is how this says so, and {@see Iterator} then drops the instance
 *   without counting it.
 * - **which moment a caller should keep**, once it holds an instance and wants
 *   an instant rather than a wall clock.
 *
 * ## Why this lives here and not beside its implementations
 *
 * The implementations are in `VObject\TimeZone`, and that namespace already
 * depends on this one: a `VTIMEZONE`'s observances are expanded with
 * {@see ExpandedSet}. **An interface declared there and used here would make
 * the two depend on each other**, so the port is declared where it is needed
 * and the adapters reach across. The engine stays ignorant of names,
 * catalogues and `VTIMEZONE` components; it only ever asks this.
 *
 * ## What every implementation owes
 *
 * Two answers are not a single moment, and §3.6.5 settles neither, so the
 * contract does — otherwise the same object would expand differently
 * depending on how its zone happened to be found:
 *
 * - **a local time that does not exist has no moment**, and the answer is
 *   null rather than the nearest moment that does exist;
 * - **a local time that happens twice comes to the earlier moment**, the one
 *   the clock showed first.
 *
 * A value that is already an instant — FORM #2, the one a trailing `Z` fixes —
 * keeps it, there being nothing a zone can add to a moment.
 */
interface Zone
{
    /**
     * The moment a local `DATE-TIME` names in this zone, or null where that
     * local time does not exist.
     *
     * **A `DATE` cannot be asked**, which is why the parameter is a
     * `DATE-TIME`: R-TZ-04 forbids turning an all-day value into an instant.
     */
    public function momentOf(DateTime $local): ?DateTimeImmutable;
}
