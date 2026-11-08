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

    private const METHOD = 'METHOD';

    /**
     * What a resource in a scheduling Inbox collection is called here.
     *
     * Nothing in RFC 6638 says: §2.2 describes what an Inbox may hold and §4
     * what goes into one, and neither names a member. `.ics` is this library's
     * own, as `.xml` is for a notification.
     */
    private const SUFFIX = '.ics';

    private function __construct(private readonly string $text)
    {
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
        $message = self::copyOf($object);

        $message[self::METHOD] = new Property(self::METHOD, self::REQUEST);

        // RFC 5545 §3.5: "This memo imposes no ordering of properties within
        // an iCalendar object." The method goes in front for whoever reads the
        // file, which is where RFC 5546's own examples have it.
        $message->moveFirst(self::METHOD);

        return new self((new Serializer())->write($message));
    }

    /**
     * The message as the bytes of a calendar object resource.
     */
    public function text(): string
    {
        return $this->text;
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
