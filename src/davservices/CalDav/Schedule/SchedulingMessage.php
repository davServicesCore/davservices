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

use DavServices\CalDav\CalendarObject;
use DavServices\VObject\Component;
use DavServices\VObject\Property;
use DavServices\VObject\Serializer;

/**
 * The message an organizer's change sends to an attendee (RFC 6638 §4,
 * RFC 5546 §3.2).
 *
 * RFC 6638 §1.1 calls it "a calendar object that describes a scheduling
 * operation such as schedule, reschedule, reply, or cancel", and §3.2.1.1's
 * table says which operation a creation is: an `ATTENDEE` whose
 * `SCHEDULE-AGENT` is `SERVER` is sent the iTIP method `REQUEST`.
 *
 * ## The stored object, plus one property and minus two parameters
 *
 * RFC 5546 §3.2 asks a `REQUEST` for the `METHOD` property that names it, and
 * everything else the client wrote stays: RFC 5545 §3.6 keeps `PRODID` and
 * `VERSION` REQUIRED in a `VCALENDAR`, and §3.6.5 makes a `TZID` a reference
 * to a `VTIMEZONE` that has to be in the same object, so a message built from
 * less than the whole object would name times nobody could resolve.
 *
 * **Two parameters may not travel**, each a MUST NOT in its own section —
 * §7.1 of `SCHEDULE-AGENT` and §7.3 of `SCHEDULE-STATUS`, in the same words:
 *
 * > Servers MUST NOT include this parameter in any scheduling messages sent as
 * > the result of a scheduling operation.
 *
 * They are the two the server reads and writes for itself, about its own side
 * of the exchange rather than about the event. Everything else stays, because
 * `CN`, `ROLE`, `PARTSTAT` and `RSVP` are what the invitation *is*.
 *
 * ## Built from a copy, and that is the point
 *
 * The resource this is built from is about to be stored with §7.3's
 * `SCHEDULE-STATUS` written on it ({@see \DavServices\Plugin\Scheduling}), and
 * a message built by taking two parameters off in place would have taken them
 * off the client's own object. The copy is made by building new properties
 * that leave the two out, so there is no ordering between "strip" and "store"
 * to get wrong.
 *
 * A {@see \DavServices\VObject\Parameter} is read-only through and through, so
 * the ones that travel are shared rather than rebuilt.
 */
final class SchedulingMessage
{
    /**
     * The iTIP method §3.2.1.1's table names for a creation.
     */
    public const REQUEST = 'REQUEST';

    /**
     * The iTIP method §3.2.1.3's table names for a removal.
     */
    public const CANCEL = 'CANCEL';

    private const METHOD = 'METHOD';

    private const STATUS = 'STATUS';

    private const CANCELLED = 'CANCELLED';

    private const SEQUENCE = 'SEQUENCE';

    private const VTIMEZONE = 'VTIMEZONE';

    private const VALARM = 'VALARM';

    private const UID = 'UID';

    private const DTSTAMP = 'DTSTAMP';

    private const ORGANIZER = 'ORGANIZER';

    private const ATTENDEE = 'ATTENDEE';

    /**
     * How an attendee reports on a message they received (RFC 5546 §3.6), and
     * so a property no message an organizer sends may carry: both constraint
     * tables put it at `0`.
     */
    private const REQUEST_STATUS = 'REQUEST-STATUS';

    /**
     * What a resource in a scheduling Inbox collection is called here.
     *
     * Nothing in RFC 6638 says: §2.2 describes what an Inbox may hold and §4
     * what goes into one, and neither names a member. `.ics` is this library's
     * own, as `.xml` is for a notification.
     */
    private const SUFFIX = '.ics';

    /**
     * @param Component $message The message itself, kept so that what the
     *                           recipient is to keep can be told from it
     *                           without reading the bytes back
     * @param bool $forTheirCalendar Whether this message is one the recipient
     *                               keeps a copy of at all. Two of the three
     *                               are; {@see self::uninviting()} says why
     *                               the third is not
     */
    private function __construct(
        private readonly Component $message,
        private readonly string $text,
        private readonly bool $forTheirCalendar,
    ) {
    }

    /**
     * The `REQUEST` that tells an attendee about this object.
     *
     * @param Component $object The `VCALENDAR` of the organizer's scheduling
     *                          object resource, as
     *                          {@see \DavServices\CalDav\CalendarObject::in()}
     *                          reads it. It is left exactly as it was
     */
    public static function requesting(Component $object): self
    {
        return self::saying(self::copyOf($object), self::REQUEST, true);
    }

    /**
     * The `CANCEL` that tells an attendee the event is off.
     *
     * §3.2.1.3's table names it for a removal, and RFC 5546 §3.2.5 says what
     * one is: "used to send a cancellation notice of an existing event request
     * to the affected 'Attendees'". Two of its rules are kept here and nowhere
     * else, because nothing else knows that this is a cancellation:
     *
     * - **The whole event is cancelled, so it says so.** §3.2.5's constraints
     *   have `STATUS` at "0 or 1" with the condition attached: "MUST be set to
     *   CANCELLED to cancel the entire event. If uninviting specific
     *   Attendees, then MUST NOT be included." This message is the first of
     *   those; uninviting one attendee while the event stands is §3.2.1.2's
     *   `<Removed>` column, and a different message.
     * - **The revision moves on.** RFC 5546 §2.1.4: "The 'SEQUENCE' property
     *   value MUST be incremented each time the 'Organizer' uses the 'ADD' or
     *   'CANCEL' methods." RFC 5545 §3.8.7.4 is where it starts: "When a
     *   calendar component is created, its sequence number is 0." Without the
     *   increment an attendee's client has no way to tell the cancellation
     *   from the invitation it already has.
     *
     * Both go on every component the resource holds — a recurring event and
     * its overridden instances are one event going together — and on no
     * `VTIMEZONE`: RFC 5545 §3.6.5 lists what one may hold, and neither
     * property is among them.
     *
     * **And the alarms stay behind**, which is the one row of §3.2.5's
     * constraints a message built from a stored resource breaks by itself:
     * `VALARM | 0`. RFC 5545 §3.6.1 lets an event hold as many as it likes, so
     * a perfectly good calendar object resource makes an invalid `CANCEL` the
     * moment it is copied whole. A `REQUEST` keeps them, §3.2.2's constraints
     * having `VALARM | 0+`: a reminder the organizer set is part of the
     * invitation, and a cancellation is no occasion for one.
     *
     * @param Component $object The `VCALENDAR` of the organizer's scheduling
     *                          object resource, as it stands before it goes.
     *                          It is left exactly as it was
     */
    public static function cancelling(Component $object): self
    {
        $message = self::copyOf($object);

        foreach ($message->components() as $component) {
            // Compared without regard to case, RFC 5545 §3.1 making component
            // names case-insensitive.
            if (strcasecmp($component->name(), self::VTIMEZONE) === 0) {
                continue;
            }

            unset($component[self::VALARM]);

            $component[self::STATUS] = new Property(self::STATUS, self::CANCELLED);
            $component[self::SEQUENCE] = new Property(self::SEQUENCE, self::theNextRevisionOf($component));
        }

        return self::saying($message, self::CANCEL, true);
    }

    /**
     * The `CANCEL` that tells one calendar user they are off the list, while
     * the event itself goes on.
     *
     * §3.2.1.2's `<Removed>` column sends this, and RFC 5546 §4.2.10 is the
     * example of one — "Removing Attendees" — with the note that tells the two
     * cancellations apart:
     *
     * > Note that in the example below, the "STATUS" property is omitted. This
     * > is used when the meeting itself is cancelled and not when the intent is
     * > to remove an "Attendee" from the event.
     *
     * So this message is built to that example rather than out of the resource:
     *
     * - **No `STATUS`**, which §3.2.5's constraints make the condition it is:
     *   "MUST be set to CANCELLED to cancel the entire event. If uninviting
     *   specific Attendees, then MUST NOT be included."
     * - **Only the calendar user it goes to**, which is what §3.2.5 asks for —
     *   `ATTENDEE | 0+ | MUST include some or all Attendees being removed from
     *   the event` — and nobody else: who is still coming is no part of a
     *   message about somebody who is not.
     * - **What the constraints make REQUIRED and nothing more**: `UID`,
     *   `DTSTAMP`, `ORGANIZER` and `SEQUENCE`, plus the `RECURRENCE-ID` that
     *   names the instance where one instance is meant. A property the resource
     *   never carried is not invented — RFC 5545 §3.6.1 makes `DTSTAMP`
     *   REQUIRED in a `VEVENT`, so a resource without one was never a valid
     *   calendar object resource, and a server that filled the gap would be
     *   sending a time it made up.
     * - **The revision moves on**, §2.1.4 asking that of the method rather
     *   than of the reason: "each time the 'Organizer' uses the 'ADD' or
     *   'CANCEL' methods".
     *
     * And the zones travel, because `VTIMEZONE | 0+ | MUST be present if any
     * date/time refers to a timezone` and a `RECURRENCE-ID` that names a zone
     * refers to one.
     *
     * **The recipient keeps nothing of this one**, which is what sets it apart
     * from the other two. §4 names the two things the processing does —
     * "creating or updating the corresponding scheduling object resources" —
     * and an uninvitation is neither. There is nothing to create, and updating
     * their copy "to reflect the changes sent by the 'Organizer'" (§4.1) would
     * put this message's own shape in place of their event. Removing it is not
     * among the two either, and would throw away what §3.2.2.1 lets an attendee
     * keep there. So the Inbox notice is the whole of it, which is what §4 puts
     * one there for: "an indicator to the client that a scheduling operation
     * has taken place".
     *
     * @param Component $object The `VCALENDAR` the instances were read from,
     *                          for the `PRODID` and `VERSION` RFC 5545 §3.6
     *                          makes REQUIRED and the zones its times name. It
     *                          is left exactly as it was
     * @param list<array{Component, ?Property}> $instances What to cancel: the
     *                                                     component that
     *                                                     governed the
     *                                                     instance, and the
     *                                                     property naming it,
     *                                                     or null for §3.2.5's
     *                                                     complete range
     * @param string $address The calendar user this goes to
     */
    public static function uninviting(Component $object, array $instances, string $address): self
    {
        $message = new Component($object->name());

        foreach ($object->properties() as $property) {
            $message->add(self::withoutTheServersOwn($property));
        }

        foreach ($object->components(self::VTIMEZONE) as $zone) {
            $message->add(self::copyOf($zone));
        }

        foreach ($instances as [$instance, $named]) {
            $message->add(self::uninvitingFrom($instance, $named, $address));
        }

        return self::saying($message, self::CANCEL, false);
    }

    /**
     * The message as the bytes of a calendar object resource.
     */
    public function text(): string
    {
        return $this->text;
    }

    /**
     * The message this object becomes under that iTIP method.
     *
     * RFC 5546 §3.2 makes `METHOD` the property that says which message this
     * is, and every one of its constraint tables asks for exactly one.
     *
     * RFC 5545 §3.5: "This memo imposes no ordering of properties within an
     * iCalendar object." The method goes in front for whoever reads the file,
     * which is where RFC 5546's own examples have it.
     */
    private static function saying(Component $message, string $method, bool $forTheirCalendar): self
    {
        $message[self::METHOD] = new Property(self::METHOD, $method);
        $message->moveFirst(self::METHOD);

        return new self($message, (new Serializer())->write($message), $forTheirCalendar);
    }

    /**
     * The one component that says that calendar user is off this instance.
     */
    private static function uninvitingFrom(Component $instance, ?Property $named, string $address): Component
    {
        $component = new Component($instance->name());

        foreach ([self::UID, self::DTSTAMP, self::ORGANIZER] as $name) {
            $property = $instance->property($name);

            if ($property !== null) {
                $component->add(self::withoutTheServersOwn($property));
            }
        }

        // Null is §3.2.5's complete range, which that section describes by
        // what it does not carry: "a 'RECURRENCE-ID' MUST NOT be specified".
        if ($named !== null) {
            $component->add(self::withoutTheServersOwn($named));
        }

        $component->add(new Property(self::SEQUENCE, self::theNextRevisionOf($instance)));

        foreach ($instance->properties(self::ATTENDEE) as $attendee) {
            if ($attendee->value() === $address) {
                $component->add(self::withoutTheServersOwn($attendee));
            }
        }

        return $component;
    }

    /**
     * The revision after this component's, as RFC 5546 §2.1.4 asks for it.
     *
     * RFC 5545 §3.8.7.4 is where the count starts — "When a calendar component
     * is created, its sequence number is 0" — so a component that carried none
     * leaves with a `1`. A value nobody can read counts as none, which is the
     * only answer left: §3.8.7.4 gives the property an INTEGER value, and a
     * server that passed something else through would be sending the client's
     * own invalid data back out under its own name.
     */
    private static function theNextRevisionOf(Component $component): string
    {
        return (string) (((int) ($component->property(self::SEQUENCE)?->value() ?? '0')) + 1);
    }

    /**
     * Which event this message is about, or null where it names none.
     *
     * **This is what decides whether the recipient already has it.** RFC 5546
     * §3.2.2: "If the 'UID' property value in the 'REQUEST' is not found on the
     * recipient's calendar, then the 'REQUEST' is for a new 'VEVENT' calendar
     * component. If the 'UID' property value is found on the recipient's
     * calendar, then the 'REQUEST' is for a rescheduling, an update, or a
     * reconfirmation of the 'VEVENT' calendar component."
     */
    public function uid(): ?string
    {
        return CalendarObject::uidIn($this->message);
    }

    /**
     * The bytes the recipient keeps: the message without its `METHOD`.
     *
     * RFC 4791 §4.1: "Calendar object resources contained in calendar
     * collections MUST NOT specify the iCalendar METHOD property." The property
     * belongs to a message in transit — RFC 5546 §1.4 — and §4.1 of RFC 6638
     * has the server make "a new scheduling object resource" out of one, which
     * is a resource in a calendar collection like any other.
     *
     * Everything else stays, because that is the event. Taken from a copy for
     * the reason the message itself is: the message is sent as it stands, and a
     * `METHOD` taken off in place would have taken it off what was sent.
     *
     * **Null where the recipient keeps nothing**, which is the one message of
     * the three that leaves their calendar alone: {@see self::uninviting()}
     * says why §4's "creating or updating" is neither of the two things an
     * uninvitation could ask for.
     */
    public function whatTheRecipientKeeps(): ?string
    {
        if (!$this->forTheirCalendar) {
            return null;
        }

        $kept = self::copyOf($this->message);

        unset($kept[self::METHOD]);

        return (new Serializer())->write($kept);
    }

    /**
     * What to call it where it is delivered.
     *
     * **Derived from the message rather than from the event**, which answers
     * the one question a name has to answer here: whether this message is
     * already in that Inbox. The same message is one message — two calendars
     * of one owner can hold one event — and a message that differs anywhere is
     * another one for the calendar user to acknowledge, which is §4's model:
     * "Scheduling messages are typically removed from the scheduling Inbox
     * collection by the client once the calendar user has acknowledged the
     * change."
     *
     * A digest is also the one name that is usable whatever the `UID` holds:
     * RFC 5545 §3.8.4.7 lets that be any text at all, slashes included.
     */
    public function name(): string
    {
        return hash('sha256', $this->text) . self::SUFFIX;
    }

    /**
     * The same component again, without the two parameters §7.1 and §7.3
     * keep out of a message, and without the one property no method an
     * organizer sends may carry.
     *
     * Down through the sub-components, because RFC 5545 §3.6.6 lets a
     * `VALARM` carry an `ATTENDEE` of its own.
     *
     * `REQUEST-STATUS` is at `0` in RFC 5546 §3.2.2's constraints and in
     * §3.2.5's alike, and for the same reason: RFC 5546 §3.6 makes it how an
     * attendee reports on a message they received. One left lying on an
     * organizer's resource — §4.2 is where it would have come from — would have
     * the server sending an attendee their own old answer back out.
     */
    private static function copyOf(Component $component): Component
    {
        $copy = new Component($component->name());

        foreach ($component->children() as $child) {
            if ($child instanceof Component) {
                $copy->add(self::copyOf($child));

                continue;
            }

            // Compared without regard to case, RFC 5545 §3.1 making property
            // names case-insensitive.
            if (strcasecmp($child->name(), self::REQUEST_STATUS) !== 0) {
                $copy->add(self::withoutTheServersOwn($child));
            }
        }

        return $copy;
    }

    /**
     * The same property again, without those two parameters.
     */
    private static function withoutTheServersOwn(Property $property): Property
    {
        $parameters = [];

        foreach ($property->parameters() as $parameter) {
            if ($parameter->named(ScheduleAgent::NAME) || $parameter->named(DeliveryStatus::NAME)) {
                continue;
            }

            $parameters[] = $parameter;
        }

        return new Property($property->name(), $property->value(), $parameters, $property->group());
    }
}
