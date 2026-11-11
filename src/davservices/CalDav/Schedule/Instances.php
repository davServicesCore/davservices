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

namespace DavServices\CalDav\Schedule;

use DavServices\VObject\Component;
use DavServices\VObject\Property;

/**
 * Two revisions of one resource, paired up instance by instance.
 *
 * RFC 6638 asks for the same comparison twice, once of each side of an
 * invitation:
 *
 * > When an "Organizer" modifies a scheduling object resource, the server MUST
 * > inspect each "ATTENDEE" property in both the original and modified
 * > iCalendar data **on a per-instance basis** to determine whether to send a
 * > scheduling message. (§3.2.1.2)
 *
 * > The server will inspect the changes by comparing the new scheduling object
 * > resource with the existing scheduling object resource. If the "Attendee"
 * > changes one or more "PARTSTAT" iCalendar property values **on any
 * > component**, or **adds an overridden component** with a changed "PARTSTAT"
 * > property, then the server MUST deliver an iTIP "REPLY" […] (§3.2.2.3)
 *
 * What the two sides make of a pair differs — {@see Modification} reads §7.1's
 * `SCHEDULE-AGENT` and {@see Answer} reads `PARTSTAT` — so the pairing is all
 * they share, and it lives here rather than in either of them.
 *
 * ## What an instance is here
 *
 * The ones the data names: the master component, and each component carrying a
 * `RECURRENCE-ID`. An instance that exists only because of an `RRULE`, with no
 * override of its own, is governed by the master, so the master's row speaks
 * for it. Expanding the recurrence rule to enumerate instances would be a
 * different operation from the one either section describes, both of them
 * asking for a comparison of the iCalendar data.
 *
 * **The master stands in for the side that does not name an instance**, and
 * that fallback is what makes this a comparison of instances rather than of
 * components. It reads both ways round: an override the modification adds is
 * compared against the master that governed the instance before — §3.2.2.3's
 * "adds an overridden component" — and an override the modification drops does
 * not take its instance away, the `RRULE` still generating it and the master
 * governing it again.
 */
final class Instances
{
    private const RECURRENCE_ID = 'RECURRENCE-ID';

    private const VTIMEZONE = 'VTIMEZONE';

    /**
     * Every instance either revision names, as what governed it before, what
     * governs it after, and the property that names it.
     *
     * The master comes first and is named by nothing, which is the shape both
     * callers need of it: RFC 5546 §3.2.5 describes the complete range of a
     * recurring event as the message where "a 'RECURRENCE-ID' MUST NOT be
     * specified", and §3.2.3's constraints say the same of a `REPLY` about the
     * series — `RECURRENCE-ID | 0 or 1 | Only if referring to an instance of a
     * recurring calendar component. Otherwise, it MUST NOT be present.`
     *
     * Then one row per instance either side names, in the order the original
     * names them and the modification's own after those.
     *
     * **The name is the original's where both sides have one**, because the
     * messages built from a pair are built about the instance as it stood. RFC
     * 5545 §3.8.4.4 lets a `RECURRENCE-ID` carry a `RANGE` that widens what it
     * refers to, so which of the two properties travels is a question with an
     * answer rather than a detail.
     *
     * @param Component|null $original The `VCALENDAR` as it stands, or null
     *                                 where there is none — a creation, which
     *                                 §3.2.1.2 calls its `<Absent>` row
     * @param Component $modified The `VCALENDAR` that is about to replace it
     *
     * @return list<array{?Component, ?Component, ?Property}>
     */
    public static function between(?Component $original, Component $modified): array
    {
        $masterBefore = self::theMasterOf($original);
        $masterAfter = self::theMasterOf($modified);
        $before = self::theOverridesOf($original);
        $after = self::theOverridesOf($modified);
        $instances = [[$masterBefore, $masterAfter, null]];
        $named = [];

        foreach ($before as $key => [, $property]) {
            $named[$key] = $property;
        }

        foreach ($after as $key => [, $property]) {
            $named[$key] ??= $property;
        }

        foreach ($named as $key => $property) {
            $instances[] = [
                $before[$key][0] ?? $masterBefore,
                $after[$key][0] ?? $masterAfter,
                $property,
            ];
        }

        return $instances;
    }

    /**
     * The component overriding the instance that value names, or null where
     * none does.
     *
     * **The other half of what a reply about one instance asks** (RFC 6638
     * §4.2): the instance it names may have a component of its own on the
     * organizer's copy, and where it has none the parenthesis of that section
     * applies — the server takes into account "the fact that an 'Attendee'
     * could have created a new overridden iCalendar component".
     *
     * The value is compared as written, as it is throughout this pairing: RFC
     * 5546 §2.1.5 makes `UID` and `RECURRENCE-ID` "the primary key for
     * referencing" an instance, and a key is matched rather than interpreted.
     */
    public static function theOverrideOf(?Component $object, string $named): ?Component
    {
        return self::theOverridesOf($object)[$named][0] ?? null;
    }

    /**
     * The components an instance can be, which is all of them but the zones.
     *
     * RFC 5545 §3.6.5 lists what a `VTIMEZONE` may hold, and neither
     * `RECURRENCE-ID` nor `ATTENDEE` is among them. The same reading
     * {@see SchedulingObject::in()} makes of §3.1's "all the calendar
     * components".
     *
     * Only the object's own components, never what is inside them: a `VALARM`
     * has attendees of its own — RFC 5545 §3.6.6 makes them "the attendees… to
     * be sent an email" — and a reminder is nobody's invitation.
     *
     * @return list<Component>
     */
    public static function of(?Component $object): array
    {
        $components = [];

        foreach ($object?->components() ?? [] as $component) {
            // Compared without regard to case, RFC 5545 §3.1 making component
            // names case-insensitive.
            if (strcasecmp($component->name(), self::VTIMEZONE) !== 0) {
                $components[] = $component;
            }
        }

        return $components;
    }

    /**
     * The component the whole recurrence set starts from, or null where the
     * object names none.
     *
     * The first without a `RECURRENCE-ID`, that property being what RFC 5545
     * §3.8.4.4 makes an instance out of a component — and not the first
     * component, §3.6 imposing no ordering on them.
     *
     * **Asked for in its own right by {@see Reply::appliedTo()}**,
     * where a reply naming no instance is a reply about the series: RFC 5546
     * §3.2.3 has `RECURRENCE-ID | 0 or 1 | Only if referring to an instance of
     * a recurring calendar component.`
     */
    public static function theMasterOf(?Component $object): ?Component
    {
        foreach (self::of($object) as $component) {
            if ($component->property(self::RECURRENCE_ID) === null) {
                return $component;
            }
        }

        return null;
    }

    /**
     * The overridden instances, by the value that names them, each with the
     * property that names it.
     *
     * The property travels and not just its value, because a `RECURRENCE-ID`
     * means one moment only together with its `TZID` (RFC 5545 §3.8.4.4), and
     * a message about the instance has to name the same moment.
     *
     * @return array<string, array{Component, Property}>
     */
    private static function theOverridesOf(?Component $object): array
    {
        $overrides = [];

        foreach (self::of($object) as $component) {
            $named = $component->property(self::RECURRENCE_ID);

            if ($named !== null) {
                $overrides[$named->value()] = [$component, $named];
            }
        }

        return $overrides;
    }
}
