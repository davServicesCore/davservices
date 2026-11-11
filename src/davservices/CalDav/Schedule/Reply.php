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
use DavServices\VObject\Recur\Override;

/**
 * What a reply does to the organizer's own copy (RFC 6638 §4.2).
 *
 * > The server MUST then update the "PARTSTAT" iCalendar property parameter
 * > value of each "ATTENDEE" iCalendar property in the scheduling object
 * > resource to match the changes indicated in the reply (taking into account
 * > the fact that an "Attendee" could have created a new overridden iCalendar
 * > component to indicate different participation status on one or more
 * > instances of a recurring event).
 * >
 * > The server MUST also update or add the "SCHEDULE-STATUS" property
 * > parameter on each matching "ATTENDEE" iCalendar property and set its value
 * > to that of the "REQUEST-STATUS" property in the reply, or to "2.0" if
 * > "REQUEST-STATUS" is not present (also taking into account recurrence
 * > instances).
 *
 * The other direction of §4.1, and the end of the exchange {@see Answer}
 * begins: an attendee answered, the `REPLY` was delivered, and this is where
 * the organizer's event comes to say who is coming.
 *
 * ## One reply answers for one calendar user
 *
 * RFC 5546 §3.2.3's constraints have `ATTENDEE | 1 | MUST be the address of
 * the Attendee replying`, and that row is a fence rather than a formality: a
 * server that read a second `ATTENDEE` would let one calendar user set
 * another's answer on somebody else's resource. So the first of them answers
 * for the component, and anything else in it is passed over.
 *
 * **Who really sent the message is not a question this can ask.** A reply is
 * taken at its word about which calendar user answered, which is what §4's own
 * model provides for: §6's scheduling privileges are what decide whether a
 * message may be delivered at all, and they are not here yet.
 *
 * ## The parenthesis is the work
 *
 * A reply carrying a `RECURRENCE-ID` is about one instance, and the
 * organizer's copy need not have a component for it — the series may be a bare
 * `RRULE`. There would then be nowhere to write one answer that does not
 * answer for every instance, so the component is made: {@see Override} builds
 * the instance as the series already describes it, and the answer goes on
 * that.
 *
 * **And only where it reaches somebody.** §3.2.6 has the other case — "an
 * 'Attendee' could be excluded from one instance of a recurring event" — so a
 * created component whose attendee list does not name the calendar user
 * replying is no answer to record, and the resource keeps the shape it had.
 *
 * ## What this does not do
 *
 * **§4.2's last SHOULD**: "The server SHOULD send scheduling messages to all
 * the other 'Attendees' indicating the change in participation status of the
 * 'Attendee' replying, subject to the recurrence requirements of Section
 * 3.2.6." The subordinate clause is why it waits: §3.2.6 is a MUST — "Servers
 * MUST ensure that 'Attendees' only get information about recurrence instances
 * that explicitly include them as an 'Attendee'" — and sending those messages
 * before it is built would keep a SHOULD by breaking a MUST.
 *
 * **RFC 5546 §2.1.5's out-of-order replies.** That section asks an organizer's
 * server to "persist the 'SEQUENCE' and 'DTSTAMP' property values associated
 * with the 'Attendee's' last response, so that any earlier responses from an
 * 'Attendee' that are received out of order […] can be correctly discarded" —
 * and iCalendar gives a server no property of its own to keep either in. §4.2
 * asks for neither, and the answer that arrives last is the one the resource
 * carries.
 */
final class Reply
{
    private const ATTENDEE = 'ATTENDEE';

    private const PARTSTAT = 'PARTSTAT';

    private const RECURRENCE_ID = 'RECURRENCE-ID';

    /**
     * How an attendee reports on the message they received (RFC 5546 §3.6),
     * and so where §4.2 reads the status it writes.
     */
    private const REQUEST_STATUS = 'REQUEST-STATUS';

    /**
     * What RFC 5545 §3.2.12 makes of an answer nobody gave: "NEEDS-ACTION […]
     * is the default value".
     */
    private const NEEDS_ACTION = 'NEEDS-ACTION';

    /**
     * What §4.2 asks for where the reply reports nothing: "or to '2.0' if
     * 'REQUEST-STATUS' is not present".
     */
    private const SUCCESS = '2.0';

    /**
     * Writes what this reply says onto the resource, and says whether anything
     * was written.
     *
     * The resource comes first because it is the one that changes — the same
     * shape {@see \DavServices\CalDav\Expander::appliedTo()} has.
     *
     * **Nothing is written where nothing matched**, and the answer says so:
     * §4.2 describes an update to one or more `ATTENDEE` properties, so a
     * resource that holds none of them is one the message has nothing to say
     * about. The caller is the one that knows what a resource unchanged is
     * worth — {@see \DavServices\Plugin\Scheduling} stores it again only where
     * it differs.
     *
     * The components the message's own are {@see Instances::of()}'s, for the
     * reason that class gives: a `VTIMEZONE` may hold neither of the
     * properties read here (RFC 5545 §3.6.5), and a reply may carry one — RFC
     * 5546 §3.2.3 has `VTIMEZONE | 0 or 1 | MUST be present if any date/time
     * refers to a timezone`.
     *
     * @param Component $object The organizer's own scheduling object resource,
     *                          which is changed in place
     * @param Component $message The `REPLY` as it arrived, which is left
     *                           exactly as it was
     */
    public static function appliedTo(Component $object, Component $message): bool
    {
        $written = false;

        foreach (Instances::of($message) as $component) {
            $answer = $component->property(self::ATTENDEE);

            // RFC 5546 §3.2.3 has `ATTENDEE | 1`, so a component without one
            // answers nothing — and the next one is read all the same, the
            // constraints having `VEVENT | 1+`.
            if ($answer !== null && self::answered($object, $component, $answer)) {
                $written = true;
            }
        }

        return $written;
    }

    /**
     * Writes what one component of the reply says, and says whether it reached
     * anything.
     *
     * Three cases, which are the three §4.2 describes: the reply is about the
     * series, or about an instance the resource has a component for, or about
     * one it has not — the parenthesis.
     *
     * **A component made for an instance is added only once it has been
     * written on.** Otherwise the answer reached nobody, and a resource that
     * gained a component saying exactly what the series already said would
     * have been changed by a message that had nothing to tell it.
     *
     * @param Component $saying The component of the reply
     * @param Property $answer The one `ATTENDEE` property it answers with
     */
    private static function answered(Component $object, Component $saying, Property $answer): bool
    {
        $status = new Parameter(DeliveryStatus::NAME, [self::reportedIn($saying)]);
        $named = $saying->property(self::RECURRENCE_ID);

        if ($named === null) {
            return self::writtenOnto(Instances::theMasterOf($object), $answer, $status);
        }

        $override = Instances::theOverrideOf($object, $named->value());

        if ($override !== null) {
            return self::writtenOnto($override, $answer, $status);
        }

        $master = Instances::theMasterOf($object);
        $created = $master === null ? null : Override::of($master, $named);

        if ($created === null || !self::writtenOnto($created, $answer, $status)) {
            return false;
        }

        $object->add($created);

        return true;
    }

    /**
     * Writes the answer on every property of that component naming the
     * calendar user who gave it, and says whether there was one.
     *
     * **Every one of them**, which is §4.2's own word — "each 'ATTENDEE'
     * iCalendar property in the scheduling object resource". A component that
     * names one calendar user twice is data this server did not write, and one
     * of the two left saying something else would leave the answer ambiguous.
     *
     * **The address is matched as it is written**, the reading
     * {@see SchedulingObject} gives: RFC 5545 §3.1 makes a property value
     * case-sensitive, and whether two spellings name one calendar user is a
     * question only a directory can answer.
     *
     * **And the rest of the property is left standing.** §4.2 names two
     * parameters; `CN`, `ROLE` and `RSVP` are the invitation as the organizer
     * composed it, and a server that rebuilt the property would be taking the
     * invitation apart to record the answer to it.
     *
     * @param Component|null $instance The component the answer is about, or
     *                                 null where the resource has none
     */
    private static function writtenOnto(?Component $instance, Property $answer, Parameter $status): bool
    {
        $said = new Parameter(
            self::PARTSTAT,
            [$answer->parameter(self::PARTSTAT)?->value() ?? self::NEEDS_ACTION],
        );
        $written = false;

        foreach ($instance?->properties(self::ATTENDEE) ?? [] as $attendee) {
            if ($attendee->value() === $answer->value()) {
                $attendee[self::PARTSTAT] = $said;
                $attendee[DeliveryStatus::NAME] = $status;
                $written = true;
            }
        }

        return $written;
    }

    /**
     * The status codes one component of the reply reports, as the one value
     * §7.3 writes them in.
     *
     * > The server MUST also update or add the "SCHEDULE-STATUS" property
     * > parameter […] and set its value to that of the "REQUEST-STATUS"
     * > property in the reply, or to "2.0" if "REQUEST-STATUS" is not present
     * > […]. If there are multiple "REQUEST-STATUS" properties in the reply,
     * > the "SCHEDULE-STATUS" property parameter value is set to a
     * > comma-separated list of status codes, one from each "REQUEST-STATUS"
     * > property.
     *
     * **The code and not the property's whole value.** §7.3's grammar takes
     * `statcode`, which RFC 5545 §3.8.8.3 makes the first of the three parts
     * of a `REQUEST-STATUS`: "statcode ';' statdesc [';' extdata]". The
     * description is text for a person to read, and §3.2.9 says what the codes
     * are for.
     *
     * **A list is one value and not several**, which is §7.3's grammar again:
     *
     *     schedulestatusparam = "SCHEDULE-STATUS" "="
     *                         ( statcode
     *                        / DQUOTE statcode *("," statcode) DQUOTE)
     *
     * So the commas are inside a quoted string, which is what RFC 5545 §3.1
     * makes of any parameter value holding one — and §7.3's own example has it
     * written out: `ATTENDEE;SCHEDULE-STATUS="2.0,2.4":mailto:cyrus@example.com`.
     */
    private static function reportedIn(Component $component): string
    {
        $codes = [];

        foreach ($component->properties(self::REQUEST_STATUS) as $property) {
            $codes[] = explode(';', $property->value())[0];
        }

        return $codes === [] ? self::SUCCESS : implode(',', $codes);
    }
}
