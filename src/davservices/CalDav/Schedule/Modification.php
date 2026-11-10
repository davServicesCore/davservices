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
 * What an organizer's change tells each calendar user (RFC 6638 §3.2.1.2).
 *
 * > When an "Organizer" modifies a scheduling object resource, the server MUST
 * > inspect each "ATTENDEE" property in both the original and modified
 * > iCalendar data **on a per-instance basis** to determine whether to send a
 * > scheduling message.
 * >
 * >  +---------------+-----------------------------------------------+
 * >  |               |                   Modified                    |
 * >  |               +-----------+-----------+-----------+-----------+
 * >  |               | <Removed> | SERVER    | CLIENT    | NONE      |
 * >  |               |           | (default) |           |           |
 * >  +===+===========+===========+===========+===========+===========+
 * >  |   | <Absent>  |  --       | REQUEST / | --        | --        |
 * >  | O |           |           | ADD       |           |           |
 * >  | r +-----------+-----------+-----------+-----------+-----------+
 * >  | i | SERVER    |  CANCEL   | REQUEST   | CANCEL    | CANCEL    |
 * >  | g | (default) |           |           |           |           |
 * >  | i +-----------+-----------+-----------+-----------+-----------+
 * >  | n | CLIENT    |  --       | REQUEST / | --        | --        |
 * >  | a |           |           | ADD       |           |           |
 * >  | l +-----------+-----------+-----------+-----------+-----------+
 * >  |   | NONE      |  --       | REQUEST / | --        | --        |
 * >  |   |           |           | ADD       |           |           |
 * >  +---+-----------+-----------+-----------+-----------+-----------+
 *
 * ## Sixteen cells and two sentences
 *
 * Read down the columns rather than across the rows and the table says itself
 * twice over. **A `REQUEST` goes wherever the modification has this server
 * deliver**, which is the whole `SERVER` column and nothing else. **A `CANCEL`
 * goes wherever the original had this server deliver and the modification does
 * not**, which is the three remaining cells of the `SERVER` row. Everything
 * else is a change between two states neither of which this server was
 * delivering for, and the table leaves all nine of those empty.
 *
 * That is why the code below is two conditions rather than sixteen branches.
 * Each of the sixteen is a case of its own in the test list all the same,
 * because the two sentences are a reading of the table and a reading can be
 * wrong.
 *
 * ## `REQUEST`, never `ADD`
 *
 * Four cells offer a choice, and this server takes the same one every time.
 * RFC 5546 §3.2.4 states the saving and the risk in two sentences: an `ADD`
 * lets the organizer "add one or more new instances to an existing 'VEVENT'
 * using a single iTIP message without having to send the entire 'VEVENT' with
 * all the existing instance data, as it would have to do if the 'REQUEST'
 * method were used" — and "If an 'Attendee' implementation does not support the
 * 'ADD' method, it should respond with a 'REQUEST-STATUS' value of 3.14 and ask
 * for a 'REFRESH'."
 *
 * So the `REQUEST` is the message that always arrives, and the `ADD` is an
 * optimisation that may not. Its own constraints settle it: an `ADD` carries
 * exactly one component with `RECURRENCE-ID | 0`, which is no use for the thing
 * a per-instance comparison turns up most — a change to one overridden
 * instance.
 *
 * ## What an instance is here
 *
 * The ones the data names: the master component, and each component carrying a
 * `RECURRENCE-ID`. An instance that exists only because of an `RRULE`, with no
 * override of its own, carries the master's attendee list, so the master's row
 * speaks for it.
 *
 * Two consequences are worth stating, and both are what the data says rather
 * than a simplification:
 *
 * - **An override the modification drops does not take its instance with it.**
 *   The `RRULE` still generates it and the master governs it again, so an
 *   attendee the override had left out is invited back.
 * - **An `EXDATE` added, or an `RRULE` shortened, changes no `ATTENDEE`
 *   property at all**, so every cell reads `REQUEST` and the modified data
 *   travels — which is exactly how the attendee learns the instance is gone.
 *
 * Expanding the recurrence rule to enumerate instances would be a different
 * operation from the one §3.2.1.2 describes, which is a comparison of
 * "the original and modified iCalendar data".
 *
 * @see SchedulingMessage for the two messages this decides between
 */
final class Modification
{
    private const ATTENDEE = 'ATTENDEE';

    private const PARTSTAT = 'PARTSTAT';

    /**
     * The one answer §3.2.1 lets an organizer write for somebody else: RFC
     * 5545 §3.2.12 makes it the state an invitation starts in.
     */
    private const NEEDS_ACTION = 'NEEDS-ACTION';

    private const RECURRENCE_ID = 'RECURRENCE-ID';

    private const VTIMEZONE = 'VTIMEZONE';

    /**
     * What each calendar user either side names is to be told.
     *
     * A record per calendar user, and only for those something goes to: an
     * attendee whose every cell is empty is left out rather than returned to be
     * skipped.
     *
     * The four parts of one are the four things the caller has to do with it:
     *
     * 1. **the address**, carried rather than taken from the key, because an
     *    address that looks like a number would come back from a key as one;
     * 2. **whether a `REQUEST` goes**, which is the modified resource whole —
     *    RFC 5546 §3.2.2 has that message express "the entire series",
     *    instance-specific information and all;
     * 3. **the instances a `CANCEL` is to name**: the component of the original
     *    that governed the instance, with the `RECURRENCE-ID` property that
     *    names it, or null for RFC 5546 §3.2.5's complete range. The original,
     *    because it is the only side that still has the attendee on it, and
     *    §3.2.5 asks for them: `ATTENDEE | 0+ | MUST include some or all
     *    Attendees being removed from the event.`
     * 4. **the `ATTENDEE` properties to stamp**, which §3.2.1.2 puts "in the
     *    scheduling object resource being modified" — so every property of the
     *    modified resource naming that calendar user, which is what §7.3 asks
     *    for: "on any 'ATTENDEE' properties corresponding to calendar users who
     *    were sent a scheduling message". An attendee the modification removed
     *    has none, which is the same section's MUST NOT satisfied by there
     *    being nothing to write on.
     *
     * **Two cancellations of one range are one.** Where the attendee is off the
     * master and off nothing is kept, the complete range covers the overridden
     * instances with it — RFC 5546 §4.4.4 shows that message cancelling "the
     * entire recurring event and any exceptions" in one component with no
     * `RECURRENCE-ID`. And where something *is* kept, the complete range cannot
     * be sent at all: it would say the attendee is off an instance they are
     * still invited to, and §3.2.5 offers no third form. That removal travels
     * in the `REQUEST`, where the modified data no longer lists them.
     *
     * @param Component|null $original The `VCALENDAR` as it stands, or null
     *                                 where there is none — a creation, which
     *                                 is this table's `<Absent>` row and
     *                                 §3.2.1.1's whole table
     * @param Component $modified The `VCALENDAR` that is about to replace it
     *
     * @return array<array-key, array{string, bool, list<array{Component, ?Property}>, list<Property>}>
     */
    public static function whatEachCalendarUserIsTold(?Component $original, Component $modified): array
    {
        $found = [];

        foreach (self::instancesBetween($original, $modified) as [$was, $is, $named]) {
            foreach (self::calendarUsersIn($was, $is) as $address) {
                $found[$address] ??= [$address, false, [], null];

                // The `SERVER` column, all four cells of it.
                if (self::agentIn($is, $address) === ScheduleAgent::Server) {
                    $found[$address][1] = true;

                    continue;
                }

                // And the rest of the `SERVER` row, all three of those.
                if ($was === null || self::agentIn($was, $address) !== ScheduleAgent::Server) {
                    continue;
                }

                if ($named === null) {
                    $found[$address][3] = $was;

                    continue;
                }

                $found[$address][2][] = [$was, $named];
            }
        }

        return self::told($modified, $found);
    }

    /**
     * The calendar users whose answer this change sets, which §3.2.1 refuses.
     *
     * > The server SHOULD reject any attempt to **set** the "PARTSTAT"
     * > iCalendar property parameter value of the "ATTENDEE" iCalendar property
     * > of other users in the calendar object resource to a value other than
     * > "NEEDS-ACTION" if the "SCHEDULE-AGENT" property parameter value is not
     * > present or set to the value "SERVER".
     *
     * **An answer the resource already carried is nobody's attempt to set
     * one**, and that is what makes this a comparison rather than a reading.
     * §4.2 has the server write the attendees' answers onto the organizer's own
     * copy, so a client that reads the resource, moves the meeting and writes it
     * back sends every one of those answers back with it. A server that read
     * that as an organizer answering for somebody else would refuse every
     * second modification ever made. §3.2.1.2 says it in its own words, of what
     * is to be refused: "when the 'Organizer' attempts to **change** the
     * iCalendar data in a manner that is forbidden".
     *
     * **Per instance, the same instances as the table's**, which costs one case
     * and buys a commoner one. A client that splits an instance off a series
     * copies the attendee list into the new component, answers and all, and
     * there is nothing in the original to carry them over from — unless the
     * master that governed that instance is read as the original for it, which
     * is what happens here. The case it costs is the mirror: dropping an
     * override that held a different answer leaves the master's answer standing
     * for that instance, which is an answer the attendee never gave for it, and
     * so is refused.
     *
     * **Two attendees are none of its business**: §7.1's `CLIENT` and `NONE`
     * are the condition the sentence states, and the owner's own participation
     * is not that "of other users" — which this cannot know, so the caller
     * leaves the owner's own addresses out. Comparison without regard to case,
     * RFC 5545 §3.5 making an enumerated parameter value case-insensitive.
     *
     * @return list<string> Each such calendar user once, in the order they
     *                      appear
     */
    public static function whoseAnswerItSets(?Component $original, Component $modified): array
    {
        $set = [];

        foreach (self::instancesBetween($original, $modified) as [$was, $is, ]) {
            foreach (self::attendeesIn($is) as $attendee) {
                if (ScheduleAgent::on($attendee) !== ScheduleAgent::Server) {
                    continue;
                }

                $answer = $attendee->parameter(self::PARTSTAT)?->value();

                if ($answer === null || strcasecmp($answer, self::NEEDS_ACTION) === 0) {
                    continue;
                }

                if (self::answered($was, $attendee->value(), $answer)) {
                    continue;
                }

                $set[$attendee->value()] = $attendee->value();
            }
        }

        return array_values($set);
    }

    /**
     * The records, with the two cancellations of one range folded into one.
     *
     * @param array<array-key, array{string, bool, list<array{Component, ?Property}>, ?Component}> $found
     *
     * @return array<array-key, array{string, bool, list<array{Component, ?Property}>, list<Property>}>
     */
    private static function told(Component $modified, array $found): array
    {
        $told = [];

        foreach ($found as $key => [$address, $requested, $instances, $range]) {
            if ($range !== null && !$requested) {
                $instances = [[$range, null]];
            }

            if (!$requested && $instances === []) {
                continue;
            }

            $told[$key] = [$address, $requested, $instances, self::propertiesIn($modified, $address)];
        }

        return $told;
    }

    /**
     * Every instance the change touches, as the two components that govern it
     * and the property that names it.
     *
     * The master comes first and is named by nothing, which is RFC 5546
     * §3.2.5's own shape: "To cancel the complete range of a recurring event,
     * the 'UID' property value for the event MUST be specified and a
     * 'RECURRENCE-ID' MUST NOT be specified in the 'CANCEL' method."
     *
     * Then each instance either side names, with the master standing in for the
     * side that does not name it — the fallback that makes this a comparison of
     * instances rather than of components.
     *
     * **The name is the one on the component the cancellation will be built
     * from**, which is the original's: its own `RECURRENCE-ID` where it
     * overrode the instance, and the modification's where the master governed
     * it and so carries none. A `RANGE` parameter travels with it for that
     * reason — RFC 5545 §3.8.4.4 lets one say "this and all after", and the
     * scope of the component being cancelled is the scope of the cancellation.
     *
     * @return list<array{?Component, ?Component, ?Property}>
     */
    private static function instancesBetween(?Component $original, Component $modified): array
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
     * The component the whole recurrence set starts from, or null where the
     * object names none.
     *
     * The first without a `RECURRENCE-ID`, that property being what RFC 5545
     * §3.8.4.4 makes an instance out of a component.
     */
    private static function theMasterOf(?Component $object): ?Component
    {
        foreach (self::componentsOf($object) as $component) {
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
     * the cancellation has to name the same moment.
     *
     * @return array<string, array{Component, Property}>
     */
    private static function theOverridesOf(?Component $object): array
    {
        $overrides = [];

        foreach (self::componentsOf($object) as $component) {
            $named = $component->property(self::RECURRENCE_ID);

            if ($named !== null) {
                $overrides[$named->value()] = [$component, $named];
            }
        }

        return $overrides;
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
     * be sent an email" — and a reminder the organizer set is nobody's
     * invitation.
     *
     * @return list<Component>
     */
    private static function componentsOf(?Component $object): array
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
     * Every calendar user named on either side of this instance, each once, in
     * the order they appear.
     *
     * @return list<string>
     */
    private static function calendarUsersIn(?Component $was, ?Component $is): array
    {
        $found = [];

        foreach ([$was, $is] as $component) {
            foreach (self::attendeesIn($component) as $attendee) {
                $found[$attendee->value()] = $attendee->value();
            }
        }

        return array_values($found);
    }

    /**
     * The component's own `ATTENDEE` properties, or none where there is no
     * component.
     *
     * @return list<Property>
     */
    private static function attendeesIn(?Component $component): array
    {
        return $component?->properties(self::ATTENDEE) ?? [];
    }

    /**
     * What §7.1 says about delivering to that calendar user here, or null where
     * this instance does not name them — the table's `<Absent>` and
     * `<Removed>`.
     *
     * **The first property naming them answers for all of them**, which is what
     * §7.1 allows: "The parameter value MUST be the same on each 'ATTENDEE'
     * property whose values match in a scheduling object resource." A resource
     * that breaks that rule is read by the sentence it broke.
     */
    private static function agentIn(?Component $component, string $address): ?ScheduleAgent
    {
        foreach (self::attendeesIn($component) as $attendee) {
            if ($attendee->value() === $address) {
                return ScheduleAgent::on($attendee);
            }
        }

        return null;
    }

    /**
     * Whether this instance already said that about that calendar user.
     */
    private static function answered(?Component $component, string $address, string $answer): bool
    {
        foreach (self::attendeesIn($component) as $attendee) {
            if ($attendee->value() !== $address) {
                continue;
            }

            $before = $attendee->parameter(self::PARTSTAT)?->value();

            return $before !== null && strcasecmp($before, $answer) === 0;
        }

        return false;
    }

    /**
     * Every `ATTENDEE` property of that calendar user's in the object.
     *
     * @return list<Property>
     */
    private static function propertiesIn(Component $object, string $address): array
    {
        $found = [];

        foreach (self::componentsOf($object) as $component) {
            foreach ($component->properties(self::ATTENDEE) as $attendee) {
                if ($attendee->value() === $address) {
                    $found[] = $attendee;
                }
            }
        }

        return $found;
    }
}
