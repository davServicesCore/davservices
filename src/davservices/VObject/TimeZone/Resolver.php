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

use DateTimeZone;
use DavServices\VObject\Component;
use Exception;

/**
 * Names the zone a `VTIMEZONE` means (RFC 5545 §3.2.19 and §3.6.5, R-TZ-01).
 *
 *     $zone = Resolver::resolve($vtimezone);   // ?DateTimeZone
 *     $zone = Resolver::forName($tzid);        // the parameter alone
 *
 * **A `TZID` is whatever the system that wrote it calls a zone.** §3.2.19 says
 * almost nothing about the name, and nothing at all about it being IANA's —
 * only this: "The presence of the SOLIDUS character as a prefix, indicates
 * that this 'TZID' represents a unique ID in a globally defined time zone
 * registry (when such registry is defined)."
 *
 * So R-TZ-01 asks for four ways in, and they are tried in its order:
 *
 * 1. **the `TZID` itself**, which is an IANA name in most data, and may carry
 *    a registry prefix in the rest;
 * 2. **`X-LIC-LOCATION`**, which libical writes beside a name it knew was not
 *    IANA's;
 * 3. **`X-MICROSOFT-CDO-TZID`** — *not read here*, see below;
 * 4. **{@see Aliases}**, the names other calendaring systems use.
 *
 * The catalogue comes last because it answers from a name alone, where the
 * first two carry a statement about the zone.
 *
 * ## A miss is an answer
 *
 * **Nothing here ever returns UTC for a zone it could not name.** R-TZ-02:
 * "Ein stiller Rückfall auf UTC DARF NICHT ohne Protokolleintrag erfolgen" —
 * and a library has no log, so it has something better than one. It says it
 * does not know, and the caller has to decide. A caller handed UTC would
 * never find out that an appointment had moved by an hour.
 *
 * **Evaluating the `VTIMEZONE` itself** where nothing names it is R-TZ-02's
 * other half, and the chunk after this one. Until then a null is the whole
 * answer, and it is an honest one.
 *
 * ## `X-MICROSOFT-CDO-TZID` is not read
 *
 * It carries a **number**, and the table that turns one into a zone is
 * Microsoft's old zone *index*, defined in MS-OXOCAL. The Windows registry —
 * where {@see Aliases} comes from, and where it can be checked — has not
 * carried an `Index` value for many versions, so the table has no source here
 * and is **named as missing rather than guessed at**. An index read wrongly
 * does not fail; it puts an appointment in the wrong country.
 */
final class Resolver
{
    /**
     * The zone a `VTIMEZONE` names, or null where nothing it carries names
     * one.
     *
     * **Null is deliberate**, and never UTC: see the class above.
     */
    public static function resolve(Component $zone): ?DateTimeZone
    {
        $named = $zone->property('TZID')?->value();

        if ($named !== null) {
            $found = self::zoneNamed($named);

            if ($found !== null) {
                return $found;
            }
        }

        // What libical writes beside a `TZID` it knew was not IANA's. It
        // holds a location and nothing else, so it is asked as a plain name.
        $location = $zone->property('X-LIC-LOCATION')?->value();

        if ($location !== null) {
            $found = self::zoneNamed($location);

            if ($found !== null) {
                return $found;
            }
        }

        if ($named === null) {
            return null;
        }

        return self::inTheCatalogue($named);
    }

    /**
     * The zone a `TZID` names on its own, or null.
     *
     * A caller usually holds the parameter rather than the component it
     * belongs to — a `DTSTART` carries the one and the object the other.
     */
    public static function forName(string $tzid): ?DateTimeZone
    {
        return self::zoneNamed($tzid) ?? self::inTheCatalogue($tzid);
    }

    /**
     * The zone the catalogue gives a name, or null where it has not got it.
     */
    private static function inTheCatalogue(string $name): ?DateTimeZone
    {
        $iana = Aliases::iana($name);

        if ($iana === null) {
            return null;
        }

        return self::known($iana);
    }

    /**
     * The zone a name stands for, the registry prefix §3.2.19 allows taken
     * off where there is one.
     */
    private static function zoneNamed(string $name): ?DateTimeZone
    {
        $found = self::known($name);

        if ($found !== null) {
            return $found;
        }

        // "The presence of the SOLIDUS character as a prefix, indicates that
        // this 'TZID' represents a unique ID in a globally defined time zone
        // registry (when such registry is defined)." The registry everybody
        // means is IANA's, written after a prefix of the writer's own:
        // `/mozilla.org/20070129_1/Europe/Berlin`.
        if (!str_starts_with($name, '/')) {
            return null;
        }

        $parts = explode('/', $name);
        $count = count($parts);

        // The longest trailing name first, so that a zone of three segments
        // is found whole — `America/Indiana/Indianapolis` and not `Indiana`.
        for ($from = 1; $from < $count; ++$from) {
            $found = self::known(implode('/', array_slice($parts, $from)));

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * The zone of that name where the time zone database has one.
     *
     * **Two questions, and neither answers the other.** Can a zone be built
     * from this name at all, and is the name one the database lists?
     *
     * Building is not enough, because `new DateTimeZone` also answers for
     * things that are no identifier: `CEST` builds and keeps `+02:00` *in
     * January*, so a `TZID` of `CEST` taken at face value is a silent hour's
     * error every winter. Hence the list, and it is the one `ALL_WITH_BC`
     * gives so that a name the database has since retired — `Asia/Calcutta`
     * for `Asia/Kolkata`, `Europe/Kiev` for `Europe/Kyiv` — still names its
     * zone: data written before a rename is not wrong.
     *
     * **And being listed is not enough either.** PHP enumerates
     * `/usr/share/zoneinfo`, and Ubuntu 26 lists `leapseconds` — a data file
     * rather than a zone, which `DateTimeZone` then refuses. Letting that
     * through would turn one unreadable `TZID` into a `500`, where every
     * other name this class cannot place answers null.
     *
     * The building comes first only because that order leaves no branch this
     * library cannot reach: a miss is a miss on every platform, where a name
     * that is listed and unbuildable is one that exists on some.
     *
     * **Which retired names a platform carries is the platform's business.**
     * Debian and Ubuntu moved the time zone database's backward-compatibility
     * names into a separate `tzdata-legacy` package, and their PHP reads
     * `/usr/share/zoneinfo` rather than a copy of its own, so the package
     * decides what `DateTimeZone` knows. A build that carries them lists 597
     * names, 178 of which are backward-only; a build without the package
     * lists fewer, and how many fewer is that build's business. Nothing here
     * assumes either: this answers for every name the database carries, and
     * null for the rest.
     *
     * **And a name is a shortcut rather than the mechanism.** RFC 5545 §3.6.5
     * has the definition travel with the data — "An individual 'VTIMEZONE'
     * calendar component MUST be specified for each unique 'TZID' parameter
     * value specified in the iCalendar object", which §3.2.19 repeats — so an
     * object that keeps that MUST is read correctly whatever this answers,
     * {@see Zones} putting the name first and the definition second (R-TZ-01,
     * R-TZ-02). A catalogue of the retired names, in the way
     * {@see Aliases} catalogues Microsoft's, would make the shortcut
     * platform-independent; of the 178 names a build with them carries, 25
     * are kept by no current zone at all, so it is a table to be written
     * carefully rather than quickly.
     */
    private static function known(string $name): ?DateTimeZone
    {
        try {
            $found = new DateTimeZone($name);
        } catch (Exception) {
            return null;
        }

        if (!in_array($name, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            return null;
        }

        return $found;
    }
}
