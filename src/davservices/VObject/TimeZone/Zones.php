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

use DavServices\VObject\Component;
use DavServices\VObject\ParseError;
use DavServices\VObject\Recur\Iterator;
use DavServices\VObject\Recur\Zone;

/**
 * The zone a component's `TZID` means (RFC 5545 §3.2.19, R-TZ-01, R-TZ-02).
 *
 *     $zone = Zones::of($calendar, $event);    // ?Zone
 *
 * **There are usually two answers available**, and this is where they are put
 * in order. A `TZID` parameter names a zone the system may know, and §3.2.19
 * requires the object to carry a definition for the same name: "An individual
 * 'VTIMEZONE' calendar component MUST be specified for each unique 'TZID'
 * parameter value specified in the iCalendar object."
 *
 * **The name comes first** (R-TZ-01), and the supplied definition is what a
 * reader falls back to (R-TZ-02: "Ohne Zuordnung MUSS die `VTIMEZONE` selbst
 * ausgewertet werden").
 *
 * ## §3.6.5 argues for that order itself
 *
 * Its second published example is a `VTIMEZONE` deliberately valid for one
 * year only — "suitable for a recurring event that starts on or later than
 * March 11, 2007 … and ends no later than March 9, 2008 at 01:59:59 EST" —
 * and objects like it are everywhere, because a writer only has to describe
 * the span it writes about. **A reader that preferred the supplied definition
 * would answer every date outside that span wrongly**, while a named zone
 * knows the whole history. So: a definition is read where a name fails, which
 * is exactly what R-TZ-02 says.
 *
 * The name is looked for the way {@see Resolver} looks for one, so a
 * definition that carries an `X-LIC-LOCATION` beside a name nothing defines
 * still comes to a named zone.
 *
 * ## Null is an answer, and never UTC
 *
 * A component with no `TZID` has no zone to find: §3.2.19 says its value is
 * floating — "The use of local time in a DATE-TIME or TIME value without the
 * 'TZID' property parameter is to be interpreted as floating time, regardless
 * of the existence of 'VTIMEZONE' calendar components in the iCalendar
 * object." And a `TZID` that nothing names and nothing defines is null too,
 * never UTC, which is the other half of R-TZ-02.
 */
final class Zones
{
    /**
     * The zone this component's `DTSTART` is written in, or null where it is
     * floating, in UTC, or in a zone nothing here can read.
     *
     * @param int $iterations The hard limit a supplied definition's expansion
     *                        is given (R-CAL-08)
     *
     * @throws ParseError If a matching definition cannot be read
     */
    public static function of(
        Component $object,
        Component $component,
        int $iterations = Iterator::ITERATIONS,
    ): ?Zone {
        $tzid = $component->property('DTSTART')?->parameter('TZID')?->value();

        if ($tzid === null) {
            return null;
        }

        $definition = self::theDefinitionIn($object, $tzid);
        $named = $definition === null ? Resolver::forName($tzid) : Resolver::resolve($definition);

        if ($named !== null) {
            return Named::of($named);
        }

        if ($definition === null) {
            return null;
        }

        return Definition::of($definition, $iterations);
    }

    /**
     * The `VTIMEZONE` of the object that defines this name, or null.
     *
     * **The name is matched exactly.** §3.2.19: "The value of the 'TZID'
     * property parameter will be equal to the value of the 'TZID' property
     * for the matching time zone definition" — and §3.1 makes property values
     * case-sensitive, so a definition written in another case defines
     * something else.
     */
    private static function theDefinitionIn(Component $object, string $tzid): ?Component
    {
        foreach ($object->components('VTIMEZONE') as $zone) {
            if ($zone->property('TZID')?->value() === $tzid) {
                return $zone;
            }
        }

        return null;
    }
}
