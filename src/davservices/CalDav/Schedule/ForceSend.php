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

/**
 * What a calendar user asked to have sent anyway (RFC 6638 §3.2.7).
 *
 * > The iCalendar property parameter "SCHEDULE-FORCE-SEND", defined in
 * > Section 7.2, can be used by a calendar user to force the server to send a
 * > scheduling message to an "Attendee" or the "Organizer" in a situation where
 * > the server would not normally send a scheduling message. For instance, an
 * > "Organizer" could use this property parameter to request an "Attendee" that
 * > previously declined an invitation to reconsider his participation status
 * > without being forced to modify the event.
 *
 * §7.2 gives it a grammar of two names and a door:
 *
 *     scheduleforcesendparam = "SCHEDULE-FORCE-SEND" "="
 *                            ("REQUEST"    ; Force a "REQUEST"
 *                           / "REPLY"      ; Force a "REPLY"
 *                           / iana-token)
 *
 * ## It is read and taken away in one breath
 *
 * §7.2: "Servers MUST NOT preserve this property parameter in scheduling
 * object resources, nor include it in any scheduling messages sent as the
 * result of a scheduling operation." Two artefacts, two sentences, and
 * {@see self::takenOutOf()} answers the first by doing what it is named for:
 * there is no reading it later, because the parameter does not survive the
 * request that brought it.
 *
 * The second is {@see SchedulingMessage}'s, which leaves the parameter out of
 * every property it copies — the same answer it gives §7.1 and §7.3. **Not an
 * ordering to keep but a property of the builder**: a message cannot carry it
 * whether or not the resource has been cleaned yet.
 *
 * ## One of the two directions is already the server's behaviour
 *
 * **A `REQUEST` forced on an `ATTENDEE` asks for what happens anyway.**
 * §3.2.1.2's table has one cell for an attendee this server delivers for —
 * `SERVER` in both columns — and that cell is `REQUEST`, with no condition on
 * it; the server inspects the two revisions "to determine whether to send a
 * scheduling message" and the answer for such an attendee is always yes. So
 * there is no "situation where the server would not normally send" to put
 * right, and {@see Modification::whatEachCalendarUserIsTold()} needs nothing
 * from here.
 *
 * **A `REPLY` forced on an `ORGANIZER` asks for something that would not
 * happen.** §3.2.2.3's two MUSTs are conditional — a `PARTSTAT` changed, or an
 * `EXDATE` arrived — so an attendee storing their copy again unchanged tells
 * the organizer nothing, and §7.2 is what lets them say it anyway: "An
 * 'Attendee' MAY specify this parameter on the 'ORGANIZER' with the value
 * 'REPLY' to force a 'REPLY' scheduling message to be sent to the
 * 'Organizer'." {@see self::asksForAReplyTo()} is that question, and the
 * answer the message then carries is the one §3.2.2.2 would have sent: every
 * instance as though it were being answered for the first time.
 *
 * ## What counts as a value this server can read
 *
 * §7.2 names one method for each of the two properties — `REQUEST` for an
 * `ATTENDEE`, `REPLY` for an `ORGANIZER` — and those are the two this server
 * can act on. Everything else on one of those properties is a value it cannot:
 * an unregistered token, a registered one naming a method it does not send,
 * the right method on the wrong property, nothing at all, or a list where the
 * grammar has one value.
 *
 * Which is what §7.2's last MUST is for:
 *
 * > Servers MUST set the "SCHEDULE-STATUS" parameter of the "ATTENDEE" or
 * > "ORGANIZER" to 2.3 (i.e., "Success; invalid property parameter ignored";
 * > see Section 3.6 of [RFC5546]) when the "SCHEDULE-FORCE-SEND" parameter is
 * > set to an iana-token value they do not recognize.
 *
 * **The sentence names the clearest case and 2.3 says the rest.** RFC 5546
 * §3.6.4 has it of "a property parameter" in general, so a `REPLY` asked of an
 * `ATTENDEE` — which this server has no way to send — is reported in the same
 * words as a token nobody has registered. The alternative was silence about a
 * parameter the client can see it wrote, which is what 2.3 exists to prevent.
 *
 * ## Where §7.2 and §7.3 disagree, and which wins
 *
 * §7.3 fences the parameter 2.3 goes in: "Servers MUST only add or change this
 * property parameter on any 'ATTENDEE' properties corresponding to calendar
 * users who were sent a scheduling message via a scheduling operation." An
 * attendee who stores their copy again with an unreadable `SCHEDULE-FORCE-SEND`
 * is told nothing and sends nothing, so §7.3 would have the server write
 * nothing — and §7.2 says it MUST write 2.3.
 *
 * **§7.2 wins where it speaks**, because it is the sentence about this
 * parameter and this case, while §7.3's is the general fence that keeps a
 * server from stamping calendar users it never contacted. And the two are not
 * at odds about what is being said: 2.3 claims no delivery. It answers what
 * the client wrote, which is why §7.2 puts it there.
 *
 * ## Two properties this does not reach
 *
 * **One whose `SCHEDULE-AGENT` is not this server's.** §7.2 allows the
 * parameter "on 'ATTENDEE' and 'ORGANIZER' properties on which the
 * 'SCHEDULE-AGENT' property parameter is set to the value 'SERVER' or is not
 * specified", so on any other property it was never permissible. It is taken
 * away all the same — the MUST NOT names the resource and not a kind of
 * property — and nothing is written back: §7.1 puts that property outside this
 * server's scheduling altogether, and a 2.3 on it would be the server
 * reporting on a message it was never going to send.
 *
 * **And one the parameter has no meaning on at all**, an `ATTENDEE` inside a
 * `VALARM` among them: RFC 5545 §3.6.6 makes that an address an alarm is
 * emailed to rather than a participant, and §7.2 allows the parameter on
 * neither. Taken away, and nothing read off it.
 */
final class ForceSend
{
    /**
     * §7.2's parameter name, which is also the one
     * {@see SchedulingMessage} has to leave out of a message.
     */
    public const NAME = 'SCHEDULE-FORCE-SEND';

    /**
     * RFC 5546 §3.6.4's code, which §7.2 asks for by number: "Success;
     * invalid property parameter ignored".
     */
    public const IGNORED = '2.3';

    private const ATTENDEE = 'ATTENDEE';

    private const ORGANIZER = 'ORGANIZER';

    private const REQUEST = 'REQUEST';

    private const REPLY = 'REPLY';

    /**
     * **Three lists of addresses and nothing else.** A map whose values mean
     * nothing is a map that cannot be got wrong: `isset()` answers the same
     * for `true` and for `false`, so the value would be a thing no test could
     * hold. What there is to say is said by **which** list an address is in.
     *
     * @param bool $found Whether the parameter was anywhere in the object,
     *                    which is what makes the resource differ from what
     *                    arrived
     * @param list<string> $replies The organizers a `REPLY` was asked of
     * @param list<string> $attendees The attendees whose value this server
     *                                cannot read
     * @param list<string> $organizers And the organizers whose value it cannot
     *                                 read
     */
    private function __construct(
        private readonly bool $found,
        private readonly array $replies,
        private readonly array $attendees,
        private readonly array $organizers,
    ) {
    }

    /**
     * What the object asked for, with the parameter gone from it.
     *
     * The asking is read off the components §3.1 is about, which
     * {@see Instances::of()} is, and the taking away reaches the whole object:
     * §7.2's MUST NOT is written of a scheduling object resource rather than
     * of a component, so a parameter somewhere it means nothing is still one
     * the resource may not keep.
     */
    public static function takenOutOf(Component $object): self
    {
        $replies = [];
        $attendees = [];
        $organizers = [];

        foreach (Instances::of($object) as $component) {
            foreach ($component->properties() as $property) {
                $parameter = $property->parameter(self::NAME);

                if ($parameter === null || ScheduleAgent::on($property) !== ScheduleAgent::Server) {
                    continue;
                }

                // Capitals whatever the client wrote, RFC 5545 §3.5 making a
                // property name case-insensitive. A property §7.2 names
                // neither of falls through both arms and is read for nothing.
                switch (strtoupper($property->name())) {
                    case self::ATTENDEE:
                        if (!self::carries($parameter, self::REQUEST)) {
                            $attendees[] = $property->value();
                        }

                        break;

                    case self::ORGANIZER:
                        if (self::carries($parameter, self::REPLY)) {
                            $replies[] = $property->value();

                            break;
                        }

                        $organizers[] = $property->value();

                        break;
                }
            }
        }

        return new self(self::strippedFrom($object), $replies, $attendees, $organizers);
    }

    /**
     * Whether the parameter was anywhere in the object, and therefore whether
     * the resource has to be stored as taking it away left it.
     */
    public function wasThere(): bool
    {
        return $this->found;
    }

    /**
     * Whether that organizer asked for a `REPLY` this server would not
     * otherwise send (§3.2.7).
     */
    public function asksForAReplyTo(string $organizer): bool
    {
        return in_array($organizer, $this->replies, true);
    }

    /**
     * Whether the `ATTENDEE` properties naming that calendar user carried a
     * value this server could not read, which is §7.2's 2.3.
     */
    public function wasIgnoredOnTheAttendee(string $address): bool
    {
        return in_array($address, $this->attendees, true);
    }

    /**
     * And the same question of the `ORGANIZER` properties naming them.
     */
    public function wasIgnoredOnTheOrganizer(string $address): bool
    {
        return in_array($address, $this->organizers, true);
    }

    /**
     * Whether the parameter holds that one method.
     *
     * **The parameter rather than the property**, because the caller has
     * already had to read it to know it is there: asking twice would be a
     * question whose second answer cannot differ from the first.
     *
     * One value, §7.2's grammar having one — and compared without regard to
     * case, RFC 5545 §3.5 making an enumerated parameter value
     * case-insensitive, which both of §7.2's named alternatives are.
     */
    private static function carries(Parameter $parameter, string $method): bool
    {
        $values = $parameter->values();

        return count($values) === 1 && strcasecmp($values[0], $method) === 0;
    }

    /**
     * Takes the parameter off every property of the component and of
     * everything inside it, and says whether it was anywhere.
     */
    private static function strippedFrom(Component $component): bool
    {
        $found = false;

        foreach ($component->properties() as $property) {
            if ($property->parameter(self::NAME) !== null) {
                unset($property[self::NAME]);
                $found = true;
            }
        }

        foreach ($component->children() as $child) {
            if ($child instanceof Component && self::strippedFrom($child)) {
                $found = true;
            }
        }

        return $found;
    }
}
