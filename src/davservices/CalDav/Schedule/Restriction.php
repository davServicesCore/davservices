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
 * What one calendar user may be told about a series (RFC 6638 §3.2.6).
 *
 * > Servers MUST ensure that "Attendees" only get information about recurrence
 * > instances that explicitly include them as an "Attendee", when delivering
 * > scheduling messages for recurring calendar components.
 *
 * The section works both of its cases out, and the two are the whole of what
 * this does.
 *
 * ## Invited to one instance: that instance, and nothing else
 *
 * > For example, if an "Attendee" is invited to only a single instance of a
 * > recurring event, the organizer scheduling object resource will contain an
 * > overridden instance in the form of a separate calendar component. […] Any
 * > scheduling messages delivered to the "Attendee" will only contain
 * > information about this overridden instance.
 *
 * ## Excluded from one instance: the master, with an `EXDATE`
 *
 * > As another example, an "Attendee" could be excluded from one instance of a
 * > recurring event. […] Any scheduling messages delivered to the "Attendee"
 * > will not specify the overridden instance but rather will include an
 * > "EXDATE" property in the "master" component that defines the recurrence
 * > set.
 *
 * **The `EXDATE` is the half that cannot be left out.** Dropping the overridden
 * component alone would not keep the instance from the attendee: the `RRULE` in
 * the master generates it again, and they would be invited to the very instance
 * the organizer took them off.
 *
 * ## A filter on the object, not on the message
 *
 * The two messages this applies to are the ones built out of the whole resource
 * — {@see SchedulingMessage::requesting()} and
 * {@see SchedulingMessage::cancelling()} — so the restriction is made of the
 * resource before either is built. The other two are already about one calendar
 * user and one set of instances: {@see SchedulingMessage::uninviting()} carries
 * the instances it was given and that attendee's own property, and
 * {@see SchedulingMessage::replying()} goes to the organizer, whom §3.2.6 says
 * nothing about.
 *
 * **It restricts the instances and nothing else.** Who else is coming stays:
 * RFC 5546 §3.2.2 has a `REQUEST` carry `ATTENDEE | 1+` for the components it
 * carries, and an invitation that hid the other guests would be a different
 * message from the one the organizer wrote.
 *
 * ## Where a range is not read
 *
 * An overriding component may carry `RANGE=THISANDFUTURE` (RFC 5545 §3.2.13),
 * and then it governs the instances after it as well. **What is excluded here
 * is still the one instance its identifier names**: an `EXDATE` has nowhere to
 * put the parameter — §3.2.13 gives it to "a property that specifies a
 * recurrence identifier" alone — and naming the rest would mean expanding the
 * recurrence set, §3.8.4.4 having subsequent instances "determined by their
 * 'RECURRENCE-ID' value" while ones "defined in separate components are not
 * impacted". That is a reading of the range, and §3.2.6 describes components.
 */
final class Restriction
{
    private const ATTENDEE = 'ATTENDEE';

    private const RECURRENCE_ID = 'RECURRENCE-ID';

    private const EXDATE = 'EXDATE';

    private const VTIMEZONE = 'VTIMEZONE';

    /**
     * The parameter RFC 5545 §3.2.13 gives to a recurrence identifier alone,
     * and so the one an exclusion cannot carry over.
     */
    private const RANGE = 'RANGE';

    /**
     * The object as that calendar user may be told about it.
     *
     * Every component naming them, the zones whatever is kept may refer to,
     * and the object's own properties — RFC 5545 §3.6 making `PRODID` and
     * `VERSION` REQUIRED in a `VCALENDAR`, so what comes back is a calendar
     * object and not a heap of components.
     *
     * **Nothing is shared with the resource**, because the `EXDATE` written
     * here belongs to one calendar user's message: a master handed over rather
     * than copied would have the organizer excluding an instance from their own
     * event.
     *
     * **A calendar user the object does not name is told about no instance at
     * all**, which is the same rule read the other way — there is no component
     * that "explicitly include[s] them as an 'Attendee'". Nothing asks that of
     * this: both callers take the address off the resource itself.
     *
     * @param Component $object The organizer's `VCALENDAR`, left exactly as it
     *                          was
     * @param string $address The calendar user the message is for
     */
    public static function whatTheyMayBeTold(Component $object, string $address): Component
    {
        $told = new Component($object->name());

        foreach ($object->properties() as $property) {
            $told->add($property->copy());
        }

        foreach ($object->components(self::VTIMEZONE) as $zone) {
            $told->add($zone->copy());
        }

        $series = [];
        $excluded = [];

        foreach (Instances::of($object) as $component) {
            $named = $component->property(self::RECURRENCE_ID);

            if (!self::names($component, $address)) {
                if ($named !== null) {
                    $excluded[] = $named;
                }

                continue;
            }

            $kept = $component->copy();

            if ($named === null) {
                $series[] = $kept;
            }

            $told->add($kept);
        }

        // Onto the components that define a recurrence set, and onto nothing
        // else: without one no series travels, and there is nothing for an
        // `RRULE` to put back. RFC 4791 §4.1 makes that one component — a
        // resource with two is data no client should have written, and the
        // exclusion goes on each rather than on a choice between them.
        foreach ($series as $master) {
            foreach ($excluded as $named) {
                $master->add(self::excluding($named));
            }
        }

        return $told;
    }

    /**
     * Whether this component names that calendar user as an attendee.
     *
     * Its own properties only. RFC 5545 §3.6.6 makes a `VALARM`'s attendees
     * "the attendees… to be sent an email", so a reminder the organizer set is
     * nobody's invitation — the same reading {@see Instances} makes wherever it
     * takes an object apart.
     *
     * The address is compared as written, for the reason
     * {@see SchedulingObject} gives: RFC 5545 §3.1 makes a property value
     * case-sensitive, and whether two spellings name one calendar user is a
     * question only a directory can answer.
     */
    private static function names(Component $component, string $address): bool
    {
        foreach ($component->properties(self::ATTENDEE) as $attendee) {
            if ($attendee->value() === $address) {
                return true;
            }
        }

        return false;
    }

    /**
     * The property that leaves the instance that one names out of the series.
     *
     * RFC 5545 §3.8.5.1 makes the values of an `EXDATE` the start times of the
     * instances left out of the recurrence set, and §3.8.4.4 asks the same of a
     * `RECURRENCE-ID`: "The value MUST be the same as the value type of the
     * 'DTSTART' property". Both mean one moment only together with their
     * `TZID`, and which type a value is can be written out as a `VALUE`
     * parameter in either — so the parameters travel with the value rather than
     * the value alone.
     *
     * **Except `RANGE`**, which §3.2.13 gives to "a property that specifies a
     * recurrence identifier" and §3.8.5.1's `exdtval` has nowhere to put.
     */
    private static function excluding(Property $named): Property
    {
        $parameters = [];

        foreach ($named->parameters() as $parameter) {
            if (!$parameter->named(self::RANGE)) {
                $parameters[] = $parameter;
            }
        }

        return new Property(self::EXDATE, $named->value(), $parameters);
    }
}
