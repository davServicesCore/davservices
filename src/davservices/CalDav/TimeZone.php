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

use DateTimeZone;
use DavServices\VObject\Component;
use DavServices\VObject\ParseError;
use DavServices\VObject\Reader;
use DavServices\VObject\Recur\Zone;
use DavServices\VObject\TimeZone\Definition;
use DavServices\VObject\TimeZone\Resolver;

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
    /**
     * The precondition a time zone nobody can use violates.
     *
     * **Named here because this is the class that decides it**, and three
     * callers ask: §5.3.1 for the property a `MKCALENDAR` sets, §5.3.2.1 for a
     * `PROPPATCH` that sets it afterwards, and §7.8 for the `CALDAV:timezone`
     * of a `calendar-query` — "The time zone specified in the REPORT request
     * MUST be a valid iCalendar object containing a single valid VTIMEZONE
     * component", which is §5.3.1's sentence again.
     */
    public const VALID = '{urn:ietf:params:xml:ns:caldav}valid-calendar-data';

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

        // Without regard to case, for the reason {@see CalendarObject::in()}
        // gives: RFC 5234 §2.3 makes the grammar's own spelling
        // case-insensitive, and §5.2.10 asks for "a valid iCalendar object"
        // rather than a loud one.
        if ($calendar === null || strcasecmp($calendar->name(), self::VCALENDAR) !== 0) {
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

        return self::usable($zone) === null ? null : $zone;
    }

    /**
     * The zone this text's one `VTIMEZONE` stands for, or null where it stands
     * for none this server can use.
     *
     * **What a caller needs who has a moment to resolve**, rather than the
     * component it was written as: §7.3 makes a `CALDAV:timezone` the first
     * source a `calendar-query` reads, and §9.8 has the server "rely on the
     * specified VTIMEZONE component instead of the CALDAV:calendar-timezone
     * property of the calendar collection […] to resolve 'date' values and
     * 'date with local time' values (i.e., floating time) to 'date with UTC
     * time' values".
     *
     * Null for the same texts {@see in()} refuses, so a caller has one thing
     * to check and the precondition is raised in one place.
     */
    public static function zoneIn(string $text): DateTimeZone|Zone|null
    {
        $zone = self::in($text);

        return $zone === null ? null : self::usable($zone);
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
     * What a moment can be resolved with here, or null where nothing can.
     *
     * **The catalogue first.** A zone the database has carries every rule it
     * has ever had, where a `VTIMEZONE` carries the observances a client chose
     * to write out — and §3.2.19 makes the `TZID` a name "specified in the
     * 'TZID' property of the matching time zone definition", which is the
     * name this looks up.
     *
     * **The observances where nothing names it.** §5.2.2's own example calls
     * its zone `US-Eastern`, which is in no catalogue, and the component says
     * what it means regardless — so a reader that insisted on a name would
     * refuse the memo's own spelling.
     */
    private static function usable(Component $zone): DateTimeZone|Zone|null
    {
        $named = Resolver::resolve($zone);

        if ($named !== null) {
            return $named;
        }

        try {
            return Definition::of($zone);
        } catch (ParseError) {
            // An observance missing one of §3.6.5's three mandatory
            // properties: nothing to resolve a moment with.
            return null;
        }
    }
}
