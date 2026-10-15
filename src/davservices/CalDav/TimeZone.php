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

use DavServices\VObject\Component;
use DavServices\VObject\ParseError;
use DavServices\VObject\Reader;
use DavServices\VObject\TimeZone\Definition;

/**
 * What `CALDAV:calendar-timezone` may hold (RFC 4791 §5.2.2, §5.3.1).
 *
 * §5.2.2 defines the property:
 *
 *     <!ELEMENT calendar-timezone (#PCDATA)>
 *     PCDATA value: an iCalendar object with exactly one VTIMEZONE component.
 *
 * and §5.3.1 makes it a precondition of `MKCALENDAR`:
 *
 * > (CALDAV:valid-calendar-data): The time zone specified in the
 * > CALDAV:calendar-timezone property MUST be a valid iCalendar object
 * > containing a single valid VTIMEZONE component.
 *
 * ## What "valid" is taken to mean, and why
 *
 * **Usable for the purpose the property has.** §5.2.2 says what that is: "the
 * time zone the server should rely on to resolve 'date' values and 'date with
 * local time' values (i.e., floating time) to 'date with UTC time' values. The
 * server will require this information to determine if a calendar component
 * scheduled with 'date' values or 'date with local time' values overlaps a
 * CALDAV:time-range specified in a CALDAV:calendar-query REPORT."
 *
 * So the test is whether this server can resolve a moment with it, which is
 * what {@see Definition} answers — rather than a grammar written again here.
 * A `VTIMEZONE` with no observance defines nothing (RFC 5545 §3.6.5: "One of
 * 'standardc' or 'daylightc' MUST occur"), and one whose observance leaves out
 * `DTSTART`, `TZOFFSETFROM` or `TZOFFSETTO` defines nothing either — §3.6.5
 * makes all three mandatory. Both come back from here as "no time zone", which
 * is the answer `CALDAV:valid-calendar-data` needs.
 *
 * `TZID` is asked for separately because §3.6.5 makes it REQUIRED and a zone
 * nobody can name is one no calendar object can point at.
 *
 * ## What is deliberately not refused
 *
 * **Another component beside the time zone.** §5.2.2 asks for "an iCalendar
 * object with exactly one VTIMEZONE component" and says nothing about what
 * else the object may carry, so a `PRODID` and a stray `VEVENT` from a
 * client's own exporter are no reason to refuse a calendar. Being stricter
 * than the text would refuse requests the memo admits.
 */
final class TimeZone
{
    private const VCALENDAR = 'VCALENDAR';

    private const VTIMEZONE = 'VTIMEZONE';

    private const TZID = 'TZID';

    /**
     * The one `VTIMEZONE` this text holds, or null where it holds no valid
     * one.
     */
    public static function in(string $text): ?Component
    {
        $calendar = self::oneObjectIn($text);

        if ($calendar === null || $calendar->name() !== self::VCALENDAR) {
            return null;
        }

        $zones = $calendar->components(self::VTIMEZONE);

        if (count($zones) !== 1) {
            return null;
        }

        $zone = $zones[0];

        if ($zone->property(self::TZID) === null) {
            return null;
        }

        return self::resolvable($zone) ? $zone : null;
    }

    /**
     * The one iCalendar object in the text, or null where there is not
     * exactly one.
     */
    private static function oneObjectIn(string $text): ?Component
    {
        $found = null;

        try {
            foreach ((new Reader($text))->objects() as $object) {
                if ($found !== null) {
                    // "an iCalendar object", singular. A second one is read
                    // far enough to know it is there and no further.
                    return null;
                }

                $found = $object;
            }
        } catch (ParseError) {
            return null;
        }

        return $found;
    }

    /**
     * Whether a moment can be resolved with this zone at all.
     */
    private static function resolvable(Component $zone): bool
    {
        try {
            return Definition::of($zone) !== null;
        } catch (ParseError) {
            // An observance missing one of §3.6.5's three mandatory
            // properties: nothing to resolve a moment with.
            return false;
        }
    }
}
