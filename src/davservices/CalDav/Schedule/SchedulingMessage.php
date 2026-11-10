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
     */
    private function __construct(
        private readonly Component $message,
        private readonly string $text,
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
        return self::saying(self::copyOf($object), self::REQUEST);
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

            $component[self::STATUS] = new Property(self::STATUS, self::CANCELLED);
            $component[self::SEQUENCE] = new Property(
                self::SEQUENCE,
                (string) (((int) ($component->property(self::SEQUENCE)?->value() ?? '0')) + 1),
            );
        }

        return self::saying($message, self::CANCEL);
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
    private static function saying(Component $message, string $method): self
    {
        $message[self::METHOD] = new Property(self::METHOD, $method);
        $message->moveFirst(self::METHOD);

        return new self($message, (new Serializer())->write($message));
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
     */
    public function whatTheRecipientKeeps(): string
    {
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
     * keep out of a message.
     *
     * Down through the sub-components, because RFC 5545 §3.6.6 lets a
     * `VALARM` carry an `ATTENDEE` of its own.
     */
    private static function copyOf(Component $component): Component
    {
        $copy = new Component($component->name());

        foreach ($component->children() as $child) {
            $copy->add($child instanceof Component ? self::copyOf($child) : self::withoutTheServersOwn($child));
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
