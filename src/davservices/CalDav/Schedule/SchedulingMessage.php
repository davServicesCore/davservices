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
 * The message one side's change sends to the other (RFC 6638 §4,
 * RFC 5546 §3.2).
 *
 * RFC 6638 §1.1 calls it "a calendar object that describes a scheduling
 * operation such as schedule, reschedule, reply, or cancel". Which operation a
 * change is, is read from a table in each case: §3.2.1.1 and §3.2.1.2 for the
 * organizer's three, §3.2.2.3 for the attendee's one.
 *
 * **The four are not four variations of one message.** Each of RFC 5546 §3.2's
 * methods carries its own table of constraints, and they disagree in rows that
 * matter: a `REQUEST` keeps the alarms a `CANCEL` and a `REPLY` may not carry,
 * a `CANCEL` moves the revision on where a `REPLY` echoes it, and a
 * `REQUEST-STATUS` is forbidden in two of them and is the attendee's own
 * report in the third. So the two that are the resource are built by copying
 * it, and the two that are about one calendar user are built property by
 * property.
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

    /**
     * The iTIP method §3.2.2.3 names for an attendee's answer, in both of the
     * sentences that ask for one.
     */
    public const REPLY = 'REPLY';

    private const METHOD = 'METHOD';

    private const STATUS = 'STATUS';

    private const CANCELLED = 'CANCELLED';

    private const SEQUENCE = 'SEQUENCE';

    private const VTIMEZONE = 'VTIMEZONE';

    private const TZID = 'TZID';

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
     * @param string $method The iTIP method it carries, which is what decides
     *                       which half of §4 the receiving server does with it
     * @param bool $forTheirCalendar Whether this message is one the recipient
     *                               keeps a copy of at all. Two of the four
     *                               are; {@see self::uninviting()} and
     *                               {@see self::replying()} say why the others
     *                               are not
     */
    private function __construct(
        private readonly Component $message,
        private readonly string $method,
        private readonly string $text,
        private readonly bool $forTheirCalendar,
    ) {
    }

    /**
     * The `REQUEST` that tells an attendee about this object.
     *
     * RFC 5546 §3.2.2 has it express "the entire series", and §3.2.6 of RFC
     * 6638 says whose series: the object this is given is the one calendar user
     * it goes to may be told about ({@see Restriction}), which for an attendee
     * invited to one instance is a single overridden component.
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
     * **And the object is the one calendar user's to see**, as a `REQUEST`'s
     * is: §3.2.6 of RFC 6638 restricts every message delivered to an attendee
     * to the instances that name them ({@see Restriction}), so a cancellation
     * of a whole series reaches somebody who was on one instance of it as
     * §3.2.5's second form — one component, with the `RECURRENCE-ID` of the
     * instance they knew.
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
     * The `REPLY` that tells the organizer what this calendar user answered.
     *
     * §3.2.2.3 asks for it twice — once for an answer changed and once for an
     * instance excluded — and RFC 5546 §3.2.3 says what one is: "used to
     * respond (e.g., accept or decline) to a 'REQUEST'". Its constraints are
     * narrower than either of the organizer's messages, and three of the rows
     * are the whole of this method:
     *
     * - **`ATTENDEE | 1 | MUST be the address of the Attendee replying.`** One,
     *   and the one answering. Who else is coming is the organizer's own list,
     *   which came from the organizer; sending it back would be answering for
     *   them.
     * - **`SEQUENCE | 0 or 1 | If non-zero, MUST be the sequence number of the
     *   original REQUEST. MAY be present if 0.`** So the revision is echoed and
     *   not moved on, which is the opposite of what a `CANCEL` does
     *   ({@see self::cancelling()}) and for the same reason read the other way:
     *   §2.1.4 gives the increment to the organizer, and an answer is about the
     *   revision it answers. The attendee's copy carries the number the request
     *   arrived with, so it is there to be echoed — and where it is not, none is
     *   sent rather than a `0` the server wrote itself.
     * - **`VALARM | 0`**, as in a `CANCEL`: a reminder on the attendee's copy
     *   is theirs, and an answer is no occasion to send anybody one.
     *
     * **Built rather than copied**, which is what makes the rest of that table
     * true at no cost: every optional property is one that "MUST NOT be changed
     * from those of the original request", and a message carrying none of them
     * has changed none. `REQUEST-STATUS` is left out for a reason of its own —
     * §4.2 reads its absence, "or to '2.0' if 'REQUEST-STATUS' is not present",
     * so one copied off the attendee's resource would answer a message of the
     * organizer's that nobody asked about.
     *
     * **And the zones travel only where something refers to them.** §3.2.3 has
     * `VTIMEZONE | 0 or 1 | MUST be present if any date/time refers to a
     * timezone`, and a reply refers to at most one: its `DTSTAMP` is UTC by
     * RFC 5545 §3.8.7.2, so the only times left are the `RECURRENCE-ID`s, and
     * §3.8.4.4 ties those to the `DTSTART` they override. A zone sent with
     * nothing referring to it would be the one thing that row counts.
     *
     * **The organizer keeps nothing of it**, as the recipient of an
     * uninvitation keeps nothing: §4.2 has the processing "update the
     * 'PARTSTAT' iCalendar property parameter value of each 'ATTENDEE'
     * iCalendar property in the scheduling object resource to match the
     * changes indicated in the reply", which is neither of §4's "creating or
     * updating the corresponding scheduling object resources" — there is
     * nothing to create, and the resource to update is the organizer's event
     * rather than this message's shape.
     *
     * @param Component $object The `VCALENDAR` of the attendee's scheduling
     *                          object resource, for the `PRODID` and `VERSION`
     *                          RFC 5545 §3.6 makes REQUIRED and the zones its
     *                          `RECURRENCE-ID`s name. It is left exactly as it
     *                          was
     * @param list<array{Component, ?Property, Property}> $instances What is
     *                                                               answered,
     *                                                               as
     *                                                               {@see Answer}
     *                                                               reads it
     */
    public static function replying(Component $object, array $instances): self
    {
        $message = new Component($object->name());

        foreach ($object->properties() as $property) {
            $message->add(self::withoutTheServersOwn($property));
        }

        $zone = self::theZoneOf($object, $instances);

        if ($zone !== null) {
            $message->add(self::copyOf($zone));
        }

        foreach ($instances as [$instance, $named, $attendee]) {
            $message->add(self::replyingAbout($instance, $named, $attendee));
        }

        return self::saying($message, self::REPLY, false);
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

        return new self($message, $method, (new Serializer())->write($message), $forTheirCalendar);
    }

    /**
     * A component carrying the three properties that say which event a message
     * about one instance is about.
     *
     * `UID`, `DTSTAMP` and `ORGANIZER` are REQUIRED in every one of RFC 5546's
     * constraint tables, §3.2.3's as much as §3.2.5's, and the first of them is
     * what ties a message to the event it is about: `UID | 1 | MUST be the UID
     * of the original REQUEST.`
     *
     * **A property the resource never carried is not invented.** RFC 5545
     * §3.6.1 makes `DTSTAMP` REQUIRED in a `VEVENT`, so a resource without one
     * was never a valid calendar object resource, and a server that filled the
     * gap would be sending a time it made up.
     */
    private static function namingTheEventOf(Component $instance): Component
    {
        $component = new Component($instance->name());

        foreach ([self::UID, self::DTSTAMP, self::ORGANIZER] as $name) {
            $property = $instance->property($name);

            if ($property !== null) {
                $component->add(self::withoutTheServersOwn($property));
            }
        }

        return $component;
    }

    /**
     * The one zone a reply can carry, or null where its instances name none.
     *
     * **§3.2.3's row is a type here rather than a count to keep to**:
     * `VTIMEZONE | 0 or 1 | MUST be present if any date/time refers to a
     * timezone`. One is all a reply can need — its `DTSTAMP` is a UTC value by
     * RFC 5545 §3.8.7.2, so a `RECURRENCE-ID` is the only time in it that can
     * name a zone, and §3.8.4.4 ties each of those to the `DTSTART` it
     * overrides. The first instance that names a zone therefore names it for
     * the message.
     *
     * @param list<array{Component, ?Property, Property}> $instances
     */
    private static function theZoneOf(Component $object, array $instances): ?Component
    {
        foreach ($instances as [, $property, ]) {
            $named = $property?->parameter(self::TZID)?->value();

            if ($named !== null) {
                return self::theZoneCalled($named, $object);
            }
        }

        return null;
    }

    /**
     * The object's own zone of that name, or null where it holds none.
     *
     * Which is data RFC 5545 §3.6.5 does not allow — a `TZID` is "a reference
     * to the unique time zone identifier" of a component that has to be in the
     * same object — and `CALDAV:valid-calendar-data` is what refuses it, in
     * another plugin on the same request. Asked rather than assumed, because a
     * message built while such a resource is still in the tree may not take
     * the server down.
     */
    private static function theZoneCalled(string $named, Component $object): ?Component
    {
        foreach ($object->components(self::VTIMEZONE) as $zone) {
            if ($zone->property(self::TZID)?->value() === $named) {
                return $zone;
            }
        }

        return null;
    }

    /**
     * The one component that carries this calendar user's answer about this
     * instance.
     */
    private static function replyingAbout(Component $instance, ?Property $named, Property $attendee): Component
    {
        $component = self::namingTheEventOf($instance);

        // Null is the series itself, which §3.2.3 describes by what it does
        // not carry: `RECURRENCE-ID | 0 or 1 | Only if referring to an
        // instance of a recurring calendar component.`
        if ($named !== null) {
            $component->add(self::withoutTheServersOwn($named));
        }

        // Echoed where there is one, §3.2.3 asking for "the sequence number
        // of the original REQUEST" and allowing none at all.
        $revision = $instance->property(self::SEQUENCE);

        if ($revision !== null) {
            $component->add(self::withoutTheServersOwn($revision));
        }

        $component->add(self::withoutTheServersOwn($attendee));

        return $component;
    }

    /**
     * The one component that says that calendar user is off this instance.
     */
    private static function uninvitingFrom(Component $instance, ?Property $named, string $address): Component
    {
        $component = self::namingTheEventOf($instance);

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
     * The reply the receiving server is to process (§4.2), or null where this
     * message asks for no such thing.
     *
     * §4 has two halves, and the `METHOD` is what says which one a message
     * arriving is: §4.1 is "for a scheduling message sent by an 'Organizer'"
     * and §4.2 "for a scheduling message reply sent by an 'Attendee'". RFC
     * 5546 §1.4 makes the property the one that says so — and §3.2's table of
     * methods puts `REPLY` on the attendee's side of the exchange.
     *
     * So this and {@see self::whatTheRecipientKeeps()} are the two halves of
     * §4 asked of one message, and no message answers both: what §4.2
     * describes is an update of two parameters on a resource the recipient
     * already has, which is neither of §4's "creating or updating the
     * corresponding scheduling object resources".
     *
     * **The message itself**, which the processing reads and leaves as it
     * stands ({@see Reply::appliedTo()}). A copy would be a copy of the bytes
     * that were sent, and the one property §4.2 reads that an organizer's own
     * message may not carry — `REQUEST-STATUS` — is the one
     * {@see self::copyOf()} leaves out.
     */
    public function whatTheOrganizerProcesses(): ?Component
    {
        return $this->method === self::REPLY ? $this->message : null;
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
