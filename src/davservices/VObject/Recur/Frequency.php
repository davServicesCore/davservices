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
 * How often a recurrence rule repeats (RFC 5545 §3.3.10).
 *
 *     freq = "SECONDLY" / "MINUTELY" / "HOURLY" / "DAILY"
 *          / "WEEKLY" / "MONTHLY" / "YEARLY"
 *
 * **This is the one rule part that must be there.** §3.3.10: "The FREQ rule
 * part identifies the type of recurrence rule. This rule part MUST be
 * specified in the recurrence rule."
 *
 * The seven are exhaustive and closed, which is what makes them an enum
 * rather than a string: a rule that named an eighth would be a rule this
 * memo has no meaning for.
 */
enum Frequency: string
{
    case Secondly = 'SECONDLY';

    case Minutely = 'MINUTELY';

    case Hourly = 'HOURLY';

    case Daily = 'DAILY';

    case Weekly = 'WEEKLY';

    case Monthly = 'MONTHLY';

    case Yearly = 'YEARLY';
}
