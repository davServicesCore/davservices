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
use DavServices\VObject\Parameter;
use DavServices\VObject\Property;

/**
 * What an attendee's change tells the organizer (RFC 6638 §3.2.2.3).
 *
 * > The server will inspect the changes by comparing the new scheduling object
 * > resource with the existing scheduling object resource.
 * >
 * > If the "Attendee" changes one or more "PARTSTAT" iCalendar property values
 * > on any component, or adds an overridden component with a changed
 * > "PARTSTAT" property, then the server MUST deliver an iTIP "REPLY"
 * > scheduling message to the "Organizer" to indicate the new participation
 * > status of the "Attendee".
 * >
 * > If the "Attendee" adds an "EXDATE" property value to effectively remove a
 * > recurrence instance, the server MUST deliver an iTIP "REPLY" scheduling
 * > message to the "Organizer" to indicate that the "Attendee" has declined
 * > the instance.
 *
 * ## Two sentences, one comparison
 *
 * Both ask the same thing of each instance — what does this calendar user now
 * say about it, and did they say that before — and the second is the first with
 * the answer read off the absence of the instance rather than off a parameter.
 * An `EXDATE` added is the attendee declining, which the section says in as
 * many words, so it travels in the same message as `PARTSTAT=DECLINED` against
 * the instance it names.
 *
 * The instances are {@see Instances::between()}'s, which is what makes the
 * middle clause of the first sentence fall out: an overridden component the
 * modification adds is compared against the master that governed the instance
 * until then, so a `PARTSTAT` that differs from the master's is "a changed
 * 'PARTSTAT' property" and one that agrees with it is not.
 *
 * ## A creation is the comparison with nothing to compare against
 *
 * §3.2.2.2 names no message of its own. It says the server "will attempt to
 * process changes to the resource using the normal rules for attendee
 * scheduling object resources", and then that "'SCHEDULE-STATUS' iCalendar
 * property parameters are added or changed on 'ORGANIZER' iCalendar properties
 * in the scheduling object resource being created as described in Section 7.3,
 * with the value set as described in Section 3.2.9" — a delivery status, which
 * is only ever written where something was delivered.
 *
 * So a creation is read here as §3.2.1.2's `<Absent>` row reads one on the
 * organizer's side: the same rules, with nothing on the side they compare
 * against. The answer an attendee stores an invitation with is their answer —
 * §3.2.2.2's own case is a message that "can get delivered directly to the
 * client (e.g., via email)" — and RFC 5545 §3.2.12 says what an absent one is:
 * "NEEDS-ACTION […] is the default value". Storing an invitation with no answer
 * in it therefore answers nothing and sends nothing.
 *
 * ## Only this calendar user's own answer
 *
 * §3.2.2.1's first allowance is "change their own 'PARTSTAT' iCalendar property
 * parameter value", and RFC 5546 §3.2.3 has `ATTENDEE | 1 | MUST be the address
 * of the Attendee replying`. Somebody else's answer sitting on an attendee's
 * copy is the organizer's server's own doing — §4.2 writes it there — so it is
 * no part of what this calendar user is telling anybody.
 *
 * @see SchedulingMessage::replying() for the message this is turned into
 */
final class Answer
{
    private const ATTENDEE = 'ATTENDEE';

    private const PARTSTAT = 'PARTSTAT';

    private const EXDATE = 'EXDATE';

    private const RECURRENCE_ID = 'RECURRENCE-ID';

    /**
     * What RFC 5545 §3.2.12 makes of an answer nobody gave: "NEEDS-ACTION […]
     * is the default value".
     */
    private const NEEDS_ACTION = 'NEEDS-ACTION';

    /**
     * What §3.2.2.3 has an instance excluded say: "the 'Attendee' has declined
     * the instance".
     */
    private const DECLINED = 'DECLINED';

    /**
     * Every instance this change has something to say about, as the component
     * the reply is built from, the property naming the instance, and the
     * `ATTENDEE` property the reply carries.
     *
     * The three parts are the three a `REPLY` is made of (RFC 5546 §3.2.3):
     *
     * 1. **the component that governs the instance**, for the `UID`,
     *    `DTSTAMP`, `ORGANIZER` and `SEQUENCE` its constraints ask for;
     * 2. **the property naming the instance**, or null for the series itself —
     *    `RECURRENCE-ID | 0 or 1 | Only if referring to an instance of a
     *    recurring calendar component. Otherwise, it MUST NOT be present.`;
     * 3. **the one `ATTENDEE` property**, which is the attendee's own as they
     *    wrote it. What else is on it is the invitation as the organizer
     *    composed it — `CN`, `ROLE`, `RSVP` — and a server that sent a bare
     *    address would be answering in words of its own.
     *
     * **An instance excluded is declined and not also answered.** §3.2.2.1
     * allows both halves of that change in one breath — "add 'EXDATE'
     * iCalendar properties and possibly remove components for overridden
     * recurrence instances" — so an attendee who declines an instance they had
     * an override for leaves two changes behind, and only one of them is true
     * of the instance afterwards. Two components naming one instance would
     * leave the organizer's server, which reads them in whatever order they
     * arrive, to pick between two contradictory answers.
     *
     * The records are therefore held against the instance they are about, and
     * the exclusions go in last: one key, and the answer that is still true of
     * it. Which instance a record is about is all the order there is here —
     * RFC 5545 §3.6 imposes none on the components of an object, so the order
     * they come back in is nobody's promise.
     *
     * @param Component|null $original The `VCALENDAR` as it stands, or null
     *                                 where there is none — §3.2.2.2's
     *                                 creation
     * @param Component $modified The `VCALENDAR` that is about to replace it
     * @param list<string> $addresses The calendar user addresses of the owner
     *                                of the collection it is in (§2.4.1)
     *
     * @return list<array{Component, ?Property, Property}>
     */
    public static function whatTheOrganizerIsTold(?Component $original, Component $modified, array $addresses): array
    {
        $declined = self::declinedBetween($original, $modified, $addresses);
        $told = [];

        foreach (Instances::between($original, $modified) as [$was, $is, $named]) {
            $attendee = $is === null ? null : self::theirs($is, $addresses);

            if ($is === null || $attendee === null) {
                continue;
            }

            if (strcasecmp(self::answerOn($was, $addresses), self::answerOf($attendee)) === 0) {
                continue;
            }

            $told[$named?->value() ?? ''] = [$is, $named, $attendee];
        }

        // Last, and keyed by the same instance, so that an instance both
        // excluded and answered is left declined.
        foreach ($declined as $key => $one) {
            $told[$key] = $one;
        }

        return array_values($told);
    }

    /**
     * The instances this change excludes that the original did not, by the
     * value that names them.
     *
     * **Of the resource rather than of a component.** §3.2.2.3 asks about an
     * attendee who "adds an 'EXDATE' property value", and a value already
     * excluded somewhere in the original is no addition wherever it now
     * stands — which is also what keeps an override dropped from reporting the
     * master's old exclusions as new ones.
     *
     * **An addition is taken at its word.** "To effectively remove a
     * recurrence instance" would mean expanding the recurrence rule to see
     * whether the value hits an instance it generates, and that is not the
     * comparison this section describes. RFC 5545 §3.8.5.1 makes the values of
     * an `EXDATE` the start times of the instances left out of the recurrence
     * set, so a value added is the data saying an instance is gone.
     *
     * @param list<string> $addresses
     *
     * @return array<string, array{Component, ?Property, Property}>
     */
    private static function declinedBetween(?Component $original, Component $modified, array $addresses): array
    {
        $before = self::exclusionsIn($original);
        $declined = [];

        foreach (Instances::of($modified) as $component) {
            $attendee = self::theirs($component, $addresses);

            if ($attendee === null) {
                continue;
            }

            foreach ($component->properties(self::EXDATE) as $property) {
                foreach (self::valuesOf($property) as $value) {
                    if (!isset($before[$value])) {
                        $declined[$value] = [$component, self::naming($value, $property), self::declining($attendee)];
                    }
                }
            }
        }

        return $declined;
    }

    /**
     * Every instance the object leaves out, by the value that names it.
     *
     * Each value stands as its own key and as its own entry, which is what
     * makes this a set: there is nothing in it to read but the names, and
     * `isset()` on the key is the whole question asked of it.
     *
     * @return array<string, string>
     */
    private static function exclusionsIn(?Component $object): array
    {
        $exclusions = [];

        foreach (Instances::of($object) as $component) {
            foreach ($component->properties(self::EXDATE) as $property) {
                foreach (self::valuesOf($property) as $value) {
                    $exclusions[$value] = $value;
                }
            }
        }

        return $exclusions;
    }

    /**
     * The values one property names.
     *
     * RFC 5545 §3.8.5.1 gives `EXDATE` a list of them — "exdtval *("," exdtval)"
     * — and each one leaves out an instance of its own.
     *
     * @return list<string>
     */
    private static function valuesOf(Property $property): array
    {
        return explode(',', $property->value());
    }

    /**
     * The property that names the instance an excluded value stands for.
     *
     * RFC 5545 §3.8.5.1 has the values of an `EXDATE` be the start times of
     * the instances left out, and §3.8.4.4 asks the same of a
     * `RECURRENCE-ID`: "The value MUST be the same as the value type of the
     * 'DTSTART' property". Both mean one moment only together with their
     * `TZID`, and which type a value is can be written out as a `VALUE`
     * parameter in either — so the parameters travel with the value rather
     * than the value alone.
     */
    private static function naming(string $value, Property $exclusion): Property
    {
        return new Property(self::RECURRENCE_ID, $value, $exclusion->parameters());
    }

    /**
     * The same `ATTENDEE` property, saying the instance is declined.
     *
     * Built because there is nothing to carry: the instance the answer was on
     * is gone, so what travels is the property of the component that governs
     * the instance now, with the one parameter the exclusion changed.
     */
    private static function declining(Property $attendee): Property
    {
        $parameters = [];

        foreach ($attendee->parameters() as $parameter) {
            if (!$parameter->named(self::PARTSTAT)) {
                $parameters[] = $parameter;
            }
        }

        $parameters[] = new Parameter(self::PARTSTAT, [self::DECLINED]);

        return new Property($attendee->name(), $attendee->value(), $parameters, $attendee->group());
    }

    /**
     * What that calendar user says about this instance, or §3.2.12's default
     * where the component does not name them at all.
     *
     * An instance the original did not have them on is an instance they had
     * not answered, which is the state RFC 5545 §3.2.12 gives a name to.
     *
     * @param list<string> $addresses
     */
    private static function answerOn(?Component $component, array $addresses): string
    {
        $attendee = $component === null ? null : self::theirs($component, $addresses);

        return $attendee === null ? self::NEEDS_ACTION : self::answerOf($attendee);
    }

    /**
     * The answer one `ATTENDEE` property carries, or §3.2.12's default where
     * it carries none.
     */
    private static function answerOf(Property $attendee): string
    {
        return $attendee->parameter(self::PARTSTAT)?->value() ?? self::NEEDS_ACTION;
    }

    /**
     * That calendar user's own `ATTENDEE` property on this component, or null
     * where it does not name them.
     *
     * Any of the addresses, §2.4.1 making `CALDAV:calendar-user-address-set`
     * a set of "the URI(s) […] associated with the calendar user": which one
     * the organizer wrote into the property is the organizer's choice and not
     * a different person.
     *
     * **The first property naming them answers for all of them**, which is the
     * reading §7.1 asks for of its own parameter — "The parameter value MUST be
     * the same on each 'ATTENDEE' property whose values match in a scheduling
     * object resource" — and the only one left for a list that names one
     * calendar user twice with two answers.
     *
     * @param list<string> $addresses
     */
    private static function theirs(Component $component, array $addresses): ?Property
    {
        foreach ($component->properties(self::ATTENDEE) as $attendee) {
            if (in_array($attendee->value(), $addresses, true)) {
                return $attendee;
            }
        }

        return null;
    }
}
