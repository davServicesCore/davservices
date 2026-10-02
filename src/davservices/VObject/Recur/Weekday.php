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

/**
 * A day of the week, as a recurrence rule spells one (RFC 5545 §3.3.10).
 *
 *     weekday = "SU" / "MO" / "TU" / "WE" / "TH" / "FR" / "SA"
 *     ;Corresponding to SUNDAY, MONDAY, TUESDAY, WEDNESDAY, THURSDAY,
 *     ;FRIDAY, and SATURDAY days of the week.
 *
 * **It is used for two different things**, and the grammar keeps them apart:
 * `WKST` takes a bare `weekday` and nothing else, while `BYDAY` takes a
 * `weekdaynum`, which is a weekday with an optional ordinal in front of it.
 * That is why {@see WeekdayNumber} exists and why `WKST=1MO` is no rule.
 *
 * The cases are named in full because `TU` and `TH` are the sort of pair that
 * is read wrongly once and then never noticed again; the two-letter form the
 * file carries is the enum's value.
 */
enum Weekday: string
{
    case Sunday = 'SU';

    case Monday = 'MO';

    case Tuesday = 'TU';

    case Wednesday = 'WE';

    case Thursday = 'TH';

    case Friday = 'FR';

    case Saturday = 'SA';
}
