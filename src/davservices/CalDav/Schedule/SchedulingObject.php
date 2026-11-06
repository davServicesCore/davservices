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

/**
 * Whose scheduling object resource this is, if it is one (RFC 6638 §3.1).
 *
 * **The question every other question in §3 rests on.** §3 opens with what
 * hangs on it: "When a calendar object resource is created, modified, or
 * removed from a calendar collection, the server examines the calendar data
 * and checks to see whether the data represents a scheduling object resource.
 * If it does, the server will automatically attempt to deliver a scheduling
 * message to the appropriate calendar users."
 *
 * So a wrong answer here is either an invitation nobody asked for or one that
 * never arrives, and §3.1 gives two sentences to decide it by:
 *
 * > A calendar object resource is considered to be a valid **organizer**
 * > scheduling object resource if the "ORGANIZER" iCalendar property is
 * > present and set in all the calendar components to a value that matches one
 * > of the calendar user addresses of the owner of the calendar collection.
 * >
 * > A calendar object resource is considered to be a valid **attendee**
 * > scheduling object resource if the "ORGANIZER" iCalendar property is
 * > present and set in all the calendar components to the same value and
 * > doesn't match one of the calendar user addresses of the owner of the
 * > calendar collection, and if at least one of the "ATTENDEE" iCalendar
 * > property values matches one of the calendar user addresses of the owner of
 * > the calendar collection.
 *
 * ## The two sentences are not each other's mirror
 *
 * The first asks each component's `ORGANIZER` to match one of the owner's
 * addresses; the second asks for one value **the same in every component**
 * which is none of them. So a resource whose components disagree about who
 * organises it can be the organizer's — every value is still theirs — and can
 * never be an attendee's, because a reply has one address to go to.
 *
 * And the first is asked first, which §3.2.1 needs: "The 'Organizer' of a
 * calendar component can also be an 'Attendee' of that calendar component."
 * Such a resource is the organizer's, whatever its attendee list says.
 *
 * ## The comparison is case-sensitive, and the memo says which way
 *
 * RFC 5545 §3.1: "All names of properties, property parameters, enumerated
 * property values and property parameter values are case-insensitive.
 * **However, all other property values are case-sensitive, unless otherwise
 * stated.**" A `CAL-ADDRESS` is none of the three case-insensitive kinds, and
 * neither RFC 5545 §3.8.4.3 nor RFC 6638 §3.1 states otherwise.
 *
 * The cost of that is worth knowing: a client that writes `MAILTO:` where the
 * address set says `mailto:` has made a resource this server will not
 * schedule, and nobody is told. **The alternative is worse**, because it is a
 * server deciding for itself that two different values name one person — and
 * of all the things to decide by guesswork, who is invited to what is not one.
 *
 * ## What a `VTIMEZONE` has to do with it
 *
 * Nothing, and that is a reading rather than an omission. §3.1 says "all the
 * calendar components", and a `VTIMEZONE` can carry no `ORGANIZER`: RFC 5545
 * §3.6.5 lists what it may hold and that is not among them. A sentence read to
 * include one would leave nothing in the world a scheduling object resource.
 * The same reading {@see \DavServices\CalDav\CalendarObject::kindsIn()}
 * already makes of RFC 4791 §5.2.3.
 *
 * ## Where the addresses come from
 *
 * `CALDAV:calendar-user-address-set` (§2.4.1) of the owner of the calendar
 * collection — which is `DAV:owner` of that collection, a property this
 * library leaves to the store for the reason {@see \DavServices\Plugin\Acl}
 * gives. They arrive here as a list because that is what the property holds
 * (`<!ELEMENT calendar-user-address-set (DAV:href*)>`), and an empty one is
 * §2.4.1's own answer: "If not present, then the associated calendar user is
 * not enabled for scheduling on the server."
 */
enum SchedulingObject
{
    /**
     * The owner of the collection organises it (§3.1's first sentence).
     */
    case Organizers;

    /**
     * Somebody else organises it and the owner is invited (the second).
     */
    case Attendees;

    private const ORGANIZER = 'ORGANIZER';

    private const ATTENDEE = 'ATTENDEE';

    private const VTIMEZONE = 'VTIMEZONE';

    /**
     * Which of the two this resource is, or null where it is neither.
     *
     * @param Component $object The `VCALENDAR` of one calendar object
     *                          resource, as
     *                          {@see \DavServices\CalDav\CalendarObject::in()}
     *                          reads it
     * @param list<string> $addresses The calendar user addresses of the owner
     *                                of the collection it is in (§2.4.1)
     */
    public static function in(Component $object, array $addresses): ?self
    {
        $components = self::componentsOf($object);

        // Nothing to carry an `ORGANIZER` is nothing with one, and §3.1 wants
        // the property "present". A sentence read as vacuously true over an
        // empty list would make an empty calendar the owner's.
        if ($components === []) {
            return null;
        }

        $organizers = [];

        foreach ($components as $component) {
            $organizer = $component->property(self::ORGANIZER)?->value();

            if ($organizer === null) {
                return null;
            }

            $organizers[] = $organizer;
        }

        if (self::allTheOwners($organizers, $addresses)) {
            return self::Organizers;
        }

        // §3.1's second sentence wants one value "the same in every
        // component", because a `REPLY` has one address to go to.
        if (count(array_unique($organizers)) > 1) {
            return null;
        }

        return self::oneAttendeeIsTheOwner($components, $addresses) ? self::Attendees : null;
    }

    /**
     * The components §3.1 asks about: the ones that could carry an
     * `ORGANIZER`.
     *
     * @return list<Component>
     */
    private static function componentsOf(Component $object): array
    {
        $components = [];

        foreach ($object->components() as $component) {
            // Compared without regard to case, RFC 5545 §3.1 making component
            // names case-insensitive.
            if (strcasecmp($component->name(), self::VTIMEZONE) !== 0) {
                $components[] = $component;
            }
        }

        return $components;
    }

    /**
     * Whether every one of these addresses is one of the owner's.
     *
     * @param list<string> $organizers
     * @param list<string> $addresses
     */
    private static function allTheOwners(array $organizers, array $addresses): bool
    {
        foreach ($organizers as $organizer) {
            if (!in_array($organizer, $addresses, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether any component invites the owner.
     *
     * §3.1 asks for "at least one of the 'ATTENDEE' iCalendar property
     * values", of the resource rather than of a component — so somebody
     * invited to one instance of a series is an attendee of the resource.
     *
     * @param list<Component> $components
     * @param list<string> $addresses
     */
    private static function oneAttendeeIsTheOwner(array $components, array $addresses): bool
    {
        foreach ($components as $component) {
            foreach ($component->properties(self::ATTENDEE) as $attendee) {
                if (in_array($attendee->value(), $addresses, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
