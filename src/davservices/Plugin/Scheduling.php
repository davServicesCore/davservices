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

namespace DavServices\Plugin;

use DavServices\Acl\Principal;
use DavServices\Backend\ICalendarUserBackend;
use DavServices\CalDav\Calendar;
use DavServices\CalDav\CalendarObject;
use DavServices\CalDav\Schedule\DeliveryStatus;
use DavServices\CalDav\Schedule\Inbox;
use DavServices\CalDav\Schedule\Outbox;
use DavServices\CalDav\Schedule\ScheduleAgent;
use DavServices\CalDav\Schedule\SchedulingMessage;
use DavServices\CalDav\Schedule\SchedulingObject;
use DavServices\Dav\Event\AfterUnbind;
use DavServices\Dav\Event\BeforeCreateFile;
use DavServices\Dav\Event\BeforeUnbind;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\ICollection;
use DavServices\Dav\IFile;
use DavServices\Dav\INode;
use DavServices\Dav\IResourceType;
use DavServices\Dav\Property\Answers;
use DavServices\Dav\PropFindForm;
use DavServices\Dav\PropFindResult;
use DavServices\Dav\Server;
use DavServices\Exception\DavException;
use DavServices\Exception\Forbidden;
use DavServices\Exception\IHttpFailure;
use DavServices\Uri\MalformedPath;
use DavServices\Uri\Path;
use DavServices\VObject\Component;
use DavServices\VObject\Parameter;
use DavServices\VObject\Property;
use DavServices\VObject\Serializer;
use DavServices\Xml\Element;

/**
 * Where a calendar user's scheduling collections are, and what an organizer's
 * new event sends (R-CAL-30, R-CAL-31, R-CAL-32).
 *
 *     $scheduling = new Scheduling($server, $calendarUsers);
 *
 *     $scheduling->register();
 *
 * RFC 6638, "CalDAV Scheduling", June 2012 — and after three chunks on a
 * vendor document with no RFC, this one has conformance clauses, named
 * preconditions and an IANA registration again.
 *
 * ## It answers two properties, guards two collections, and delivers
 *
 * - **`CALDAV:schedule-inbox-URL`** (§2.2.1) and
 *   **`CALDAV:schedule-outbox-URL`** (§2.1.1) on a principal, each holding a
 *   `DAV:href` to the collection of that name.
 * - §2's "clients have no way to create or delete them", which is a `DELETE`
 *   refused.
 * - **§3.2.1.1's Create**: an organizer's new event reaches the Inbox of
 *   everybody on the attendee list, and the stored resource says what came of
 *   each delivery ({@see self::deliverOnCreate()}).
 * - **§3.2.1.3's Remove**: and their removal of it sends the cancellation
 *   ({@see self::readWhatIsGoing()}), which §3.2.3.2 asks for of a `DELETE` of
 *   the resource and of a `DELETE` of the whole calendar collection alike.
 * - and **§4's processing** of what it delivers: the message becomes an event
 *   on a calendar of the recipient's own before it appears in their Inbox.
 *
 * Both properties are withheld from `allprop`, both sections asking for that
 * in the same words.
 *
 * **The delivery is local, and not behind a seam yet.** R-CAL-33 asks for a
 * replaceable transport and R-CAL-36 for one cut so that iMIP can be added
 * without touching the scheduling core; {@see self::deliver()} is the body
 * that will become it, and the point where a second transport attaches is
 * already named — {@see DeliveryStatus::NoSuchCalendarUser} is every address
 * this server cannot resolve for itself.
 *
 * ## The collections are looked for, not named
 *
 * §2.1.1's description ends: "If not present, then the associated calendar
 * user is not enabled for the sending of scheduling messages on the server."
 * The same sentence `caldav-sharing` §4.1.1 has about a notification
 * collection, and it settles the same question: the property is answered only
 * where the collection really is, because one naming a collection that is not
 * there would send a client to a `404` for ever.
 *
 * **And RFC 6638 says nowhere what they are called.** §2 says only that
 * creating them "is the server's responsibility", so there is no name to
 * assume — this plugin reads the principal's `CALDAV:calendar-home-set`, which
 * is where this server itself says that user's calendars are, and looks there
 * for a child whose `DAV:resourcetype` carries the one it wants. No
 * configuration, and nothing to keep in step with a deployment's layout.
 *
 * A deployment that keeps them somewhere else answers the property from its
 * own store — §2.1.1 makes it "MAY be protected", so a stored value is
 * conforming — and this plugin finding nothing leaves that answer standing.
 *
 * **The cost is a listing of the home** for each of the two properties, and
 * only when a client names one: `wasNamed()` is what keeps that off every
 * `PROPFIND`.
 *
 * ## Five things this deliberately does not do
 *
 * **No `calendar-auto-schedule` token**, although §3.2.1.1 and §3.2.1.3 are
 * answered now. §2 puts the token on "any resource that supports any
 * scheduling operations, properties, privileges, or methods", and on those
 * words this plugin has earned it twice over. It is withheld all the same,
 * because of what the token is *read for*: a client that sees it stops sending
 * invitations itself and leaves that to the server. An invitation would arrive
 * and a cancellation would arrive, and every **change** in between would go
 * missing in silence — §3.2.1.2 is not here yet, and a meeting moved to another
 * day that nobody is told about is worse than one nobody was invited to. The
 * token goes in when all three operations are there.
 *
 * **The price of waiting is named too**: a client that keeps sending its own
 * invitations while this server also delivers makes two messages out of one.
 * §7.1 is the brake the document provides for that — `SCHEDULE-AGENT=CLIENT`
 * on an attendee, and nothing is sent — and of the two ways to be wrong, an
 * invitation too many is the one a calendar user can see and tick off.
 *
 * **No automatic processing of what it delivers.** §4 asks for that in a MUST
 * of its own — "by creating or updating the corresponding scheduling object
 * resources on calendars owned by the owner of the scheduling Inbox
 * collection" — and that is §4.1 with §4.3's default calendar, a piece of work
 * in its own right. Until it is here a message waits in the Inbox, which is
 * what §4 has it there for ("an indicator to the client that a scheduling
 * operation has taken place"), and no copy appears in the attendee's calendar.
 *
 * **No `CALDAV:calendar-user-address-set`**, although §2.4.1 is this memo's
 * too. It is answered by {@see CalDav}, and it stays there: it is the map from
 * a calendar user address to a principal, which §5.4.1 of `caldav-sharing`
 * needs as much as scheduling does. It belongs to the plugin every CalDAV
 * deployment has rather than to the one only a scheduling deployment has.
 *
 * **No `CALDAV:calendar-user-type`** (§2.4.2). It is a MAY, its value is "the
 * same as the iCalendar CUTYPE property parameter", and §2.4 says of both of
 * its properties that a server "MAY allow them to be written by appropriate
 * users" — so it is a value somebody sets, and a plugin that answered it would
 * make it unwritable. The same reading {@see CalDav} makes of
 * `CALDAV:calendar-description`.
 *
 * **No guard for §2.1's and §2.2's "MUST NOT be a child (at any depth) of a
 * calendar collection resource".** There is no request to guard: a client
 * cannot make one of these collections at all, because nothing registers
 * their resource types with {@see \DavServices\Dav\Method\MkCol}, which
 * refuses an unknown one by name with `DAV:valid-resourcetype`. Where an
 * application puts them is the application's to get right, and that sentence
 * is what it has to go on.
 */
final class Scheduling
{
    /**
     * §2.2.1's property, which says where a principal's Inbox is.
     */
    public const INBOX_URL = '{urn:ietf:params:xml:ns:caldav}schedule-inbox-URL';

    /**
     * §2.1.1's property, which says where their Outbox is.
     */
    public const OUTBOX_URL = '{urn:ietf:params:xml:ns:caldav}schedule-outbox-URL';

    /**
     * RFC 4791 §6.2.1's property, which is where this server says a
     * principal's calendars are — and therefore where its scheduling
     * collections are looked for.
     */
    private const CALENDAR_HOME_SET = '{urn:ietf:params:xml:ns:caldav}calendar-home-set';

    /**
     * §2.4.1's property, which is where the owner of a calendar collection
     * says who they are in iCalendar data — and therefore which resources are
     * theirs to send (§3.1).
     */
    private const ADDRESS_SET = '{urn:ietf:params:xml:ns:caldav}calendar-user-address-set';

    /**
     * §9.2's property on an Inbox, which §4.3 reads to decide where a new
     * scheduling object resource goes.
     */
    private const DEFAULT_CALENDAR = '{urn:ietf:params:xml:ns:caldav}schedule-default-calendar-URL';

    /**
     * §4.3.1.1's precondition, "Apply to: DELETE", "Use with: 403 Forbidden".
     */
    private const DEFAULT_CALENDAR_NEEDED = '{urn:ietf:params:xml:ns:caldav}default-calendar-needed';

    private const OWNER = '{DAV:}owner';

    private const HREF = '{DAV:}href';

    private const ATTENDEE = 'ATTENDEE';

    private const PARTSTAT = 'PARTSTAT';

    /**
     * The answer §3.2.1 lets an organizer write for somebody else, which is
     * the one that answers nothing: RFC 5545 §3.2.12 makes it the state an
     * invitation starts in.
     */
    private const NEEDS_ACTION = 'NEEDS-ACTION';

    /**
     * §3.2.4.3's precondition, "Use with: 403 Forbidden".
     */
    private const ALLOWED_ORGANIZER_CHANGE =
        '{urn:ietf:params:xml:ns:caldav}allowed-organizer-scheduling-object-change';

    /**
     * What a removal is to tell whom, between the two events that make one.
     *
     * Keyed by path, because a `DELETE` of a calendar collection takes its
     * members apart one at a time and several removals can be in flight at
     * once. An entry whose removal was refused is never collected, and goes
     * when the request does.
     *
     * @var array<string, array{SchedulingMessage, Component, list<string>}>
     */
    private array $goingWith = [];

    /**
     * @param ICalendarUserBackend|null $calendarUsers Who is reachable at
     *                                                 which calendar user
     *                                                 address (§2.4.1). With
     *                                                 none, no attendee can
     *                                                 be found and §3.2.9's
     *                                                 `3.7` is every answer
     */
    public function __construct(
        private readonly Server $server,
        private readonly ?ICalendarUserBackend $calendarUsers = null,
    ) {
    }

    /**
     * Switches the two properties on, the guard on the two collections, and
     * §3.2.1.1's delivery.
     *
     * **The delivery hangs off {@see BeforeCreateFile} and could hang
     * nowhere else.** §3.2.1.1 says what a creation does to the resource being
     * created — "This will result in the created calendar object resource
     * differing from the calendar data sent in the HTTP request" — so the
     * stamp has to be written before the write rather than after it. An
     * `After…` listener would have to store the resource twice and would leave
     * a moment where it says nothing about a message already sent.
     *
     * **Two listeners for one removal**, because §3.2.1.3 needs the resource
     * read while it is still there and the cancellation sent only once it is
     * gone. {@see self::readWhatIsGoing()} says why that order is the only
     * safe one.
     *
     * **No method and no report.** §2.3 extends two reports and §5 adds a
     * `POST` to the Outbox; both are still to come, and both are about reading
     * an Inbox or asking an Outbox for free time rather than about delivering.
     *
     * **And §2.2's other MUST is kept for one half only** — "Scheduling Inbox
     * collections MUST only contain calendar object resources that obey the
     * restrictions specified in iTIP [RFC5546]". What this plugin puts there
     * obeys it, {@see SchedulingMessage} building the message to RFC 5546
     * §3.2. What a client writes into an Inbox itself is not checked: §6's
     * scheduling privileges are what are meant to keep it out, and the
     * restrictions iTIP puts on each `METHOD` are a table of RFC 5546's own.
     * Half of that table enforced would promise more than it keeps.
     */
    public function register(): void
    {
        $events = $this->server->events();

        $events->on(PropertiesRequested::class, $this->describe(...));
        $events->on(BeforeUnbind::class, $this->guardTheCollections(...));
        $events->on(BeforeUnbind::class, $this->readWhatIsGoing(...));
        $events->on(AfterUnbind::class, $this->cancelOnRemove(...));
        $events->on(BeforeCreateFile::class, $this->deliverOnCreate(...));
    }

    /**
     * §3.2.1.3: what the removal of this resource will have to tell whom.
     *
     * **Read here and sent after**, which is the one safe order and the reason
     * this operation takes two listeners where the other took one. The
     * resource can only be read while it is still there, and the cancellation
     * may only go out once it really is gone: a store that refused the
     * deletion would otherwise have left every attendee told that an event
     * they can still see is off, and no second message would ever put that
     * right. §3.2.1.3 orders the two nowhere, so the order is free to be the
     * careful one.
     *
     * Nothing is refused here. The guard that does refuse a deletion is
     * {@see self::guardTheCollections()}, registered before this.
     */
    private function readWhatIsGoing(BeforeUnbind $event): void
    {
        $path = $event->path();
        $calendar = $this->calendarOver($path);

        if ($calendar === null) {
            return;
        }

        $node = $this->server->tree()->node($path);

        // A collection going away takes its members with it, and each of them
        // arrives here as its own removal — which is §3.2.3.2's second
        // sentence, about a `DELETE` of a whole calendar collection.
        if (!$node instanceof IFile) {
            return;
        }

        $object = CalendarObject::in($node->get());

        if ($object === null) {
            return;
        }

        [$where, $collection] = $calendar;
        $addresses = $this->addressesOfTheOwnerOf($where, $collection);

        if (SchedulingObject::in($object, $addresses) !== SchedulingObject::Organizers) {
            return;
        }

        $this->goingWith[$path] = [SchedulingMessage::cancelling($object), $object, $addresses];
    }

    /**
     * §3.2.1.3: and now that it is gone, the attendees are told.
     *
     * The table is §3.2.1.1's with one word changed — `SERVER` sends a
     * `CANCEL` where it sent a `REQUEST`, `CLIENT` and `NONE` send nothing —
     * and §3.2.1's MUST NOT about the organizer's own `ATTENDEE` property
     * holds here too.
     *
     * **Nothing is written back**, which is the one way this differs from the
     * other two operations: §3.2.1.1 and §3.2.1.2 have the server record what
     * came of each delivery in the resource, and §3.2.1.3 asks for no such
     * thing — there is no resource left to record it in. What {@see
     * self::deliver()} answers is read and let go.
     */
    private function cancelOnRemove(AfterUnbind $event): void
    {
        $going = $this->goingWith[$event->path()] ?? null;

        if ($going === null) {
            return;
        }

        unset($this->goingWith[$event->path()]);

        [$message, $object, $addresses] = $going;

        foreach (self::attendeesOf($object) as [$address, $agent, ]) {
            if (in_array($address, $addresses, true) || $agent !== ScheduleAgent::Server) {
                continue;
            }

            $this->deliver($message, $address);
        }
    }

    /**
     * §3.2.1.1: an organizer creating a scheduling object resource tells the
     * attendees.
     *
     * §3.2.3.1's table says this is the request that does it — "None →
     * Scheduling object resource: Create" — and §3.2.1.1 says what Create
     * means:
     *
     * > When an "Organizer" creates a scheduling object resource, the server
     * > MUST inspect each "ATTENDEE" property to determine whether to send a
     * > scheduling message.
     *
     * The order below is the order the sections are read in, and each step
     * answers a question the next one would otherwise have to guess at:
     *
     * 1. is this a calendar collection's member at all (§3.1),
     * 2. is it one calendar object resource (RFC 4791 §5.3.2.1),
     * 3. is it the owner's to send (§3.1's first sentence),
     * 4. does it answer for anybody else (§3.2.1's SHOULD),
     * 5. and then, for each calendar user on the attendee list, §7.1's table.
     *
     * **A body that is no calendar object is left alone**, and left to
     * {@see CalDav}: refusing it is that plugin's
     * `CALDAV:valid-calendar-data` precondition, and two plugins refusing one
     * request in their own words would answer it twice.
     *
     * **One thing here refuses the write, and a failed delivery is not it.**
     * §3.2.1's SHOULD about somebody else's `PARTSTAT` is a refusal; §3.2.9
     * exists so that a delivery that failed is reported on the resource
     * instead, and an organizer's event is not lost because one attendee's
     * Inbox would not take a message.
     */
    private function deliverOnCreate(BeforeCreateFile $event): void
    {
        $calendar = $this->calendarOver($event->path());

        if ($calendar === null) {
            return;
        }

        // Read once and put back at once: a body that arrives as a stream is
        // gone after it has been looked at, and a listener that looked
        // without putting anything back would store an empty file.
        $bytes = $event->content();

        if (!is_string($bytes)) {
            $bytes = (string) stream_get_contents($bytes);

            $event->writeInstead($bytes);
        }

        $object = CalendarObject::in($bytes);

        if ($object === null) {
            return;
        }

        [$where, $node] = $calendar;
        $addresses = $this->addressesOfTheOwnerOf($where, $node);

        // §3.2.1 is about the organizer's own copy. An attendee's is §3.2.2's,
        // and anything else is nobody's to send.
        if (SchedulingObject::in($object, $addresses) !== SchedulingObject::Organizers) {
            return;
        }

        $attendees = self::attendeesOf($object);

        // Before anything is sent: an invitation delivered and then refused
        // would be a message about a resource that was never stored.
        self::mustLeaveTheirAnswersAlone($attendees, $addresses);

        if ($this->tellTheAttendeesOf($object, $attendees, $addresses)) {
            $event->writeInstead((new Serializer())->write($object));
        }
    }

    /**
     * §3.2.1: an organizer does not answer for their attendees.
     *
     * > The server SHOULD reject any attempt to set the "PARTSTAT" iCalendar
     * > property parameter value of the "ATTENDEE" iCalendar property of other
     * > users in the calendar object resource to a value other than
     * > "NEEDS-ACTION" if the "SCHEDULE-AGENT" property parameter value is not
     * > present or set to the value "SERVER".
     *
     * §3.2.4.3 is the code to refuse it with, and names this very case as what
     * it is for: "For instance, servers MAY prevent the 'Organizer' from
     * setting the 'PARTSTAT' property parameter to a value other than
     * 'NEEDS-ACTION'".
     *
     * **Two attendees are none of its business**, and both exceptions are in
     * the sentence: the owner's own participation is not that "of other
     * users", and §7.1's `CLIENT` or `NONE` is the condition it states — a
     * client handling its own invitations knows answers this server never saw,
     * which §7.3 says in its own words: "the client MAY add, change, or remove
     * this parameter to indicate the last scheduling message status it
     * received".
     *
     * `PARTSTAT` is compared without regard to case, RFC 5545 §3.5 making an
     * enumerated parameter value case-insensitive.
     *
     * @param array<array-key, array{string, ScheduleAgent, list<Property>}> $attendees
     * @param list<string> $addresses The owner's own addresses (§2.4.1)
     *
     * @throws Forbidden If the resource answers for somebody else
     */
    private static function mustLeaveTheirAnswersAlone(array $attendees, array $addresses): void
    {
        foreach ($attendees as [$address, $agent, $properties]) {
            if ($agent !== ScheduleAgent::Server || in_array($address, $addresses, true)) {
                continue;
            }

            foreach ($properties as $property) {
                $answer = $property->parameter(self::PARTSTAT)?->value();

                if ($answer === null || strcasecmp($answer, self::NEEDS_ACTION) === 0) {
                    continue;
                }

                throw new Forbidden(
                    sprintf(
                        'An organizer does not set the PARTSTAT of "%s" (RFC 6638 §3.2.1): the CALDAV:allowed-organizer-scheduling-object-change precondition of §3.2.4.3.',
                        $address,
                    ),
                    self::ALLOWED_ORGANIZER_CHANGE,
                );
            }
        }
    }

    /**
     * Sends what §3.2.1.1's table asks for, and writes back what came of it.
     *
     * **Each calendar user is told once and stamped everywhere.** §3.2.1.1
     * inspects "each 'ATTENDEE' property" while the message is the whole
     * resource, so an attendee named in an overridden instance as well as in
     * the master gets one message — and §7.3 asks for the parameter "on any
     * 'ATTENDEE' properties corresponding to calendar users who were sent a
     * scheduling message", which is both of them.
     *
     * **Two attendees get nothing and keep nothing.** §7.1's `CLIENT` and
     * `NONE` are the table's two empty rows, and the owner themselves is
     * §3.2.1's MUST NOT: "The 'Organizer' of a calendar component can also be
     * an 'Attendee' of that calendar component. In such cases, the server MUST
     * NOT send a scheduling message to the 'Attendee' that matches the
     * 'Organizer'." What matches there is a calendar user rather than a
     * property value — §1.2: «"Organizer" refers to a role of a calendar
     * user» — so every address of the owner's is the owner.
     *
     * Neither is stamped, which is the MUST NOT that fences the MUST in:
     * "Servers MUST NOT set the 'SCHEDULE-STATUS' property parameter on the
     * 'ATTENDEE' property of 'Attendees' for which it did not attempt to
     * deliver a scheduling message."
     *
     * @param array<array-key, array{string, ScheduleAgent, list<Property>}> $attendees
     * @param list<string> $addresses The owner's own addresses (§2.4.1)
     *
     * @return bool Whether anything was written on the resource
     */
    private function tellTheAttendeesOf(Component $object, array $attendees, array $addresses): bool
    {
        // Built before anything is written on the resource, because §7.3 keeps
        // its parameter out of a message and the resource is about to carry
        // one. An ordering nothing states and everything depends on.
        $message = SchedulingMessage::requesting($object);
        $stamped = false;

        foreach ($attendees as [$address, $agent, $properties]) {
            if (in_array($address, $addresses, true)) {
                continue;
            }

            if ($agent !== ScheduleAgent::Server) {
                continue;
            }

            $status = new Parameter(DeliveryStatus::NAME, [$this->deliver($message, $address)->value]);

            foreach ($properties as $property) {
                $property[DeliveryStatus::NAME] = $status;
                $stamped = true;
            }
        }

        return $stamped;
    }

    /**
     * Every calendar user on the attendee list: their address, what §7.1 says
     * about telling them, and each of the properties that names them.
     *
     * **One entry per calendar user and not per property**, because the
     * message is the whole resource: an attendee named in an overridden
     * instance as well as in the master is one person to tell and two
     * properties to stamp.
     *
     * **And §7.1 is asked once of them**, which that section is what allows:
     * "The parameter value MUST be the same on each 'ATTENDEE' property whose
     * values match in a scheduling object resource." So the first of them
     * answers for all, and a resource that breaks that rule is read by the
     * sentence it broke.
     *
     * **The components the object itself holds**, which is where §3.1 looks
     * for an `ATTENDEE` as well. A `VALARM` has attendees of its own — RFC
     * 5545 §3.6.6 makes them "the attendees… to be sent an email" — and
     * inviting somebody to an event because the organizer set a reminder would
     * be a stranger's invitation out of nowhere. A `VTIMEZONE` needs no
     * skipping: RFC 5545 §3.6.5 lists what one may hold, and `ATTENDEE` is not
     * among them.
     *
     * The addresses are told apart as written, for the reason
     * {@see SchedulingObject} gives: RFC 5545 §3.1 makes a property value
     * case-sensitive, and whether two spellings name one person is a question
     * only a directory can answer.
     *
     * The records are keyed by the address, which nothing reads: it travels in
     * the record itself, and a caller that took it from the key would find a
     * number wherever an address looked like one.
     *
     * @return array<array-key, array{string, ScheduleAgent, list<Property>}>
     */
    private static function attendeesOf(Component $object): array
    {
        $found = [];

        foreach ($object->components() as $component) {
            foreach ($component->properties(self::ATTENDEE) as $attendee) {
                $address = $attendee->value();

                $found[$address] ??= [$address, ScheduleAgent::on($attendee), []];
                $found[$address][2][] = $attendee;
            }
        }

        return $found;
    }

    /**
     * Puts the message where that calendar user will find it, and says what
     * came of it (§3.2.9).
     *
     * The way there is §2.4.1's own: "This property is needed to map calendar
     * user addresses in iCalendar data to principal resources and their
     * associated scheduling Inbox and Outbox collections." So an address
     * becomes a principal, a principal becomes the Inbox §2.2.1 names, and
     * each step that leads nowhere has a code of its own.
     *
     * **A message that is already there is delivered.** Its name is a digest
     * of itself ({@see SchedulingMessage::name()}), so the same message
     * arriving twice — two calendars of one owner holding one event — leaves
     * the calendar user one thing to acknowledge rather than two.
     *
     * **And a store that refuses is not the organizer's fault.** §3.2.9's
     * `5.1` is for exactly that: "the server could not complete delivery of
     * the message. This is likely a temporary failure, and the originator can
     * try to send the message again at a later time."
     */
    private function deliver(SchedulingMessage $message, string $address): DeliveryStatus
    {
        $url = $this->calendarUsers?->principalFor($address);

        if ($url === null) {
            return DeliveryStatus::NoSuchCalendarUser;
        }

        $who = $this->principalAt($url);

        if ($who === null) {
            return DeliveryStatus::NoWayToDeliver;
        }

        [$principal, $node] = $who;
        $inbox = $this->collectionsOf(Inbox::INBOX, $principal, $node)[0] ?? null;

        if ($inbox === null) {
            return DeliveryStatus::NoWayToDeliver;
        }

        [$where, $collection] = $inbox;

        // **The same message again has been processed already.** A second copy
        // would overwrite whatever the calendar user has made of the first —
        // their answer, most of all — and §4 has the Inbox hold an indicator
        // rather than a log.
        if ($collection->hasChild($message->name())) {
            return DeliveryStatus::Delivered;
        }

        $failed = $this->copyForTheRecipient($message, $principal, $node, $where);

        // §4.1: "the scheduling message MUST only appear in the 'Attendee's'
        // scheduling Inbox collection once all automatic processing has been
        // done." A notice without the event behind it would send a client
        // looking for something that is not there.
        if ($failed !== null) {
            return $failed;
        }

        try {
            $collection->createFile($message->name(), $message->text());
        } catch (IHttpFailure) {
            return DeliveryStatus::NotCompleted;
        }

        return DeliveryStatus::Delivered;
    }

    /**
     * §4: the event itself, on a calendar of the recipient's own.
     *
     * > Servers MUST automatically process incoming scheduling messages using
     * > the rules defined by [RFC5546], by creating or updating the
     * > corresponding scheduling object resources on calendars owned by the
     * > owner of the scheduling Inbox collection.
     *
     * **New or not is decided by the `UID`**, which is RFC 5546 §3.2.2 — the
     * rules §4 sends us to: "If the 'UID' property value in the 'REQUEST' is
     * not found on the recipient's calendar, then the 'REQUEST' is for a new
     * 'VEVENT' calendar component. If the 'UID' property value is found on the
     * recipient's calendar, then the 'REQUEST' is for a rescheduling, an
     * update, or a reconfirmation of the 'VEVENT' calendar component."
     *
     * That is the `UID` **alone**, with nothing asked about whose resource it
     * is. RFC 5545 §3.8.4.7 makes it "the persistent, globally unique
     * identifier", so two resources carrying one are one event, and a client
     * that reuses one has said as much. The alternative — holding the match to
     * {@see SchedulingObject::Attendees} as well — would leave a second
     * resource with that `UID` in the same collection, which RFC 4791 §4.1
     * forbids outright.
     *
     * **An update keeps the resource's name**, so that a client watching that
     * URL sees it change rather than losing one and gaining another. What goes
     * with it is the attendee's own `PARTSTAT`: RFC 5546 §3.2.2.2 lets an
     * organizer's `REQUEST` carry participation status, and the merge that
     * would keep an answer through an inconsequential change is §3.2.10's
     * `CALDAV:schedule-tag`, which is not here yet.
     *
     * @return DeliveryStatus|null What came of it, or null where it was done
     */
    private function copyForTheRecipient(
        SchedulingMessage $message,
        string $principal,
        Principal $node,
        string $inbox,
    ): ?DeliveryStatus {
        $calendars = $this->collectionsOf(Calendar::CALENDAR, $principal, $node);
        $uid = $message->uid();
        $kept = $message->whatTheRecipientKeeps();

        // Whatever the recipient's store refuses — a listing, a read, a write
        // — is one answer: §3.2.9's `5.1`, "the server could not complete
        // delivery of the message". The organizer's own event is not lost over
        // somebody else's calendar.
        try {
            $theirs = $uid === null ? null : self::theirCopyOf($uid, $calendars);

            if ($theirs !== null) {
                $theirs->put($kept);

                return null;
            }

            $where = $this->whereANewOneGoes($calendars, $inbox, $node);

            // §4.3: "If there is no valid calendar collection, then the server
            // MUST reject the attempt to deliver the scheduling message to the
            // 'Attendee'."
            if ($where === null) {
                return DeliveryStatus::Rejected;
            }

            $where->createFile($message->name(), $kept);
        } catch (IHttpFailure) {
            return DeliveryStatus::NotCompleted;
        }

        return null;
    }

    /**
     * The resource on one of these calendars that holds that `UID`, or null
     * where none does.
     *
     * Every calendar of theirs is asked, because §4.1 looks for a resource
     * "belonging to the 'Attendee'" rather than one in any particular
     * collection — and RFC 4791 §4.1 makes a `UID` unique only "in the scope
     * of the calendar collection in which they are stored", so the one copy
     * can be in any of them.
     *
     * **What is no calendar object resource is walked past**, not tripped over:
     * §4.2 lets a calendar hold "collections that are not calendar
     * collections", and a member this server cannot read as one object carries
     * no `UID` to match.
     *
     * @param list<array{string, ICollection}> $calendars
     */
    private static function theirCopyOf(string $uid, array $calendars): ?IFile
    {
        foreach ($calendars as [, $calendar]) {
            foreach ($calendar->children() as $child) {
                if (!$child instanceof IFile) {
                    continue;
                }

                $object = CalendarObject::in($child->get());

                if ($object !== null && CalendarObject::uidIn($object) === $uid) {
                    return $child;
                }
            }
        }

        return null;
    }

    /**
     * Which of these calendars a new copy goes into, or null where there is
     * none to go into.
     *
     * §4.3 asks for the default calendar where there is one — "Servers SHOULD
     * create new scheduling object resources in the default calendar
     * collection, if the CALDAV:schedule-default-calendar-URL WebDAV property
     * is set" — and leaves the choice open where there is not. §9.2: "When
     * there is no default calendar, the server is free to choose the calendar
     * in which a new scheduling object resource is created."
     *
     * **The first of their calendars is the choice this server makes**, which
     * is the only one it can make without a rule the memo does not state — the
     * same reading {@see self::find()} makes of §2.1.1's single `DAV:href`.
     *
     * And nothing at all is §4.3's rejection: "A calendar user that is an
     * 'Attendee' in a scheduling operation MUST have at least one valid
     * calendar collection available."
     *
     * @param list<array{string, ICollection}> $calendars
     */
    private function whereANewOneGoes(array $calendars, string $inbox, Principal $node): ?ICollection
    {
        $first = $calendars[0] ?? null;

        if ($first === null) {
            return null;
        }

        $named = $this->defaultCalendarAt($inbox, $node);

        foreach ($calendars as [$path, $calendar]) {
            if ($path === $named) {
                return $calendar;
            }
        }

        // **A default this server cannot find among their calendars is passed
        // over**, which is what §4.3 holds the property to: "the server MUST
        // ensure that any new value for that property refers to a valid
        // calendar collection belonging to the owner of the scheduling Inbox
        // collection". What a store answers is read rather than trusted, and
        // delivering into somebody else's calendar on the strength of a
        // property would be the worst way to honour a SHOULD.
        return $first[1];
    }

    /**
     * The path §9.2's property names on this Inbox, or null where it names
     * none.
     *
     * > If present, it contains zero or one DAV:href XML elements. When a
     * > DAV:href element is present, its value indicates a URL to a calendar
     * > collection that is used as the default calendar. When no DAV:href
     * > element is present, it indicates that there is no default calendar. In
     * > the absence of this property, there is no default calendar.
     *
     * So all three of those come back as null here, and the caller cannot tell
     * them apart — nor needs to, §9.2 making them one answer.
     *
     * **Read through {@see Answers} rather than from the node**, for the reason
     * {@see self::addressesOfTheOwnerOf()} gives: that is the one way this
     * server finds out what it says about a property. The property is nobody's
     * to answer here — §9.2 makes it "MAY be defined" and "MAY be protected",
     * which is a deployment's decision — and a deployment that keeps a default
     * calendar says so from its own store.
     */
    private function defaultCalendarAt(string $inbox, Principal $node): ?string
    {
        $href = self::hrefsIn(
            Answers::about(
                $this->server->events(),
                $inbox,
                $this->server->tree()->node($inbox),
                PropFindForm::Named,
                [self::DEFAULT_CALENDAR],
            )->byStatus()[200][self::DEFAULT_CALENDAR] ?? null,
        )[0] ?? null;

        return $href === null ? null : $this->pathOf($href);
    }

    /**
     * The path inside this tree that an href names, or null where it names
     * none.
     *
     * **One resolution for every href this plugin reads**, and they all come
     * from somewhere this server does not control: a store's `DAV:owner`, a
     * directory's principal URL, another plugin's `calendar-home-set`, a
     * default calendar somebody configured. Two of them failing in two
     * different ways is how a server ends up more trusting of one than of the
     * other.
     *
     * Both refusals are the same answer here. {@see Path::normalise()} throws
     * where the target climbs above the root or decodes into a name that
     * cannot be addressed; {@see Server::pathOf()} throws where it lies
     * outside what this server is mounted at. Neither names anything this
     * server can reach.
     */
    private function pathOf(string $href): ?string
    {
        try {
            return $this->server->pathOf($href);
        } catch (DavException | MalformedPath) {
            return null;
        }
    }

    /**
     * The principal this URL names, with its path, or null where it names
     * none.
     *
     * **Both, from one resolution**, which is what the callers need: one that
     * had the node and wanted the path would have to resolve the URL again,
     * and two resolutions of one URL are two chances to disagree about whose
     * it is. The same shape {@see Sharing::principalAt()} has.
     *
     * Null wherever that leads nowhere: a URL this server cannot address, a
     * path outside its tree, nothing there, or something there that is no
     * principal. All four are a directory answering with something this server
     * cannot deliver to, and the caller treats them alike.
     *
     * @return array{string, Principal}|null
     */
    private function principalAt(string $url): ?array
    {
        $path = $this->pathOf($url);

        if ($path === null || !$this->server->tree()->exists($path)) {
            return null;
        }

        $node = $this->server->tree()->node($path);

        return $node instanceof Principal ? [$path, $node] : null;
    }

    /**
     * The calendar collection this path lies in, with its own path, or null
     * where it lies in none.
     *
     * **The walk goes up rather than one step**, because RFC 4791 §4.2 lets a
     * calendar collection hold "collections that are not calendar
     * collections": a resource in such a folder is still "contained in a
     * calendar collection" as §3.1 puts it, and the owner whose addresses
     * decide everything is the owner of the calendar rather than of the
     * folder. The same walk {@see CalDav} makes for §4.2's sake.
     *
     * Nothing here asks whether a path is there. **A creation has a parent
     * collection and is not the root**, both of which
     * {@see \DavServices\Dav\Method\Put} settled before raising the event, and
     * everything above a path that is there is there too.
     *
     * @return array{string, Calendar}|null
     */
    private function calendarOver(string $path): ?array
    {
        [$parent] = Path::split($path);

        while ($parent !== '') {
            $node = $this->server->tree()->node($parent);

            if ($node instanceof Calendar) {
                return [$parent, $node];
            }

            [$parent] = Path::split($parent);
        }

        return null;
    }

    /**
     * The calendar user addresses of the owner of this collection (§2.4.1),
     * or none where this server names none.
     *
     * Two properties, each read through {@see Answers} rather than off the
     * node, because that is the one way this server finds out what it says
     * about a property: it is where a dead property store is reached and where
     * access control gets the first word. The same reading
     * {@see Sharing::sharerOf()} makes of §5.5.5.
     *
     * An empty answer is §2.4.1's own: "If not present, then the associated
     * calendar user is not enabled for scheduling on the server." A calendar
     * whose owner has no addresses holds no scheduling object resources, which
     * is what {@see SchedulingObject::in()} makes of an empty list.
     *
     * @return list<string>
     */
    private function addressesOfTheOwnerOf(string $path, INode $node): array
    {
        $owner = $this->ownerAt($path, $node);

        if ($owner === null) {
            return [];
        }

        [$where, $who] = $owner;

        return self::hrefsIn(
            Answers::about($this->server->events(), $where, $who, PropFindForm::Named, [self::ADDRESS_SET])
                ->byStatus()[200][self::ADDRESS_SET] ?? null,
        );
    }

    /**
     * The principal `DAV:owner` names on this node, with its path, or null
     * where it names none.
     *
     * Read through {@see Answers} rather than from the node, because that is
     * the one way this server finds out what it says about a property: it is
     * where a dead property store is reached, and where access control gets
     * the first word. A plugin that asked the node directly would read a value
     * this server would not hand over. The same reading
     * {@see Sharing::sharerOf()} makes of §5.5.5.
     *
     * **It has to be a principal**, which is what the property is for: RFC
     * 3744 §5.1 has `DAV:owner` "identif[y] the principal resource". An href
     * naming anything else has named no owner, and whose a calendar is decides
     * both who may send from it (§3.1) and whose Inbox says which of their
     * calendars is the default one (§4.3).
     *
     * @return array{string, Principal}|null
     */
    private function ownerAt(string $path, INode $node): ?array
    {
        $href = self::hrefsIn(
            Answers::about($this->server->events(), $path, $node, PropFindForm::Named, [self::OWNER])
                ->byStatus()[200][self::OWNER] ?? null,
        )[0] ?? null;

        return $href === null ? null : $this->principalAt($href);
    }

    /**
     * Answers whichever of the two was asked for by name.
     */
    private function describe(PropertiesRequested $event): void
    {
        $node = $event->node();

        if (!$node instanceof Principal) {
            return;
        }

        $result = $event->result();

        $this->nameTheCollection($result, $node, self::INBOX_URL, Inbox::INBOX);
        $this->nameTheCollection($result, $node, self::OUTBOX_URL, Outbox::OUTBOX);
    }

    /**
     * §2.1.1 and §2.2.1, where the principal has such a collection.
     *
     * **Asked for by name only**, both sections' PROPFIND behaviour being
     * "This property SHOULD NOT be returned by a PROPFIND DAV:allprop
     * request" — the same sentence {@see PropFindResult::wasNamed()} exists
     * for, and the thing that keeps the home from being listed on every
     * request.
     *
     * The collection is named with its trailing slash, which RFC 4918 §5.2
     * asks for wherever a server produces the URL of a collection.
     */
    private function nameTheCollection(
        PropFindResult $result,
        Principal $node,
        string $property,
        string $kind,
    ): void {
        if (!$result->wasNamed($property)) {
            return;
        }

        $where = $this->find($kind, $result->path(), $node);

        if ($where === null) {
            return;
        }

        $url = new Element($property);
        $href = new Element(self::HREF);

        $href->appendText($this->server->href($where) . '/');
        $url->append($href);

        $result->set($property, $url);
    }

    /**
     * The path of the collection of this kind in the principal's calendar
     * home, or null where there is none.
     *
     * Each home is asked in the order the property names them, and the first
     * such collection is the answer: RFC 4791 §6.2.1 allows more than one home
     * — "There can be multiple" — while §2.1.1 and §2.2.1 each hold a single
     * `DAV:href`, so the question is which one, and the first is the only
     * answer this server can give without a rule the memo does not state.
     */
    private function find(string $kind, string $principal, Principal $node): ?string
    {
        return $this->collectionsOf($kind, $principal, $node)[0][0] ?? null;
    }

    /**
     * Every collection of this kind in the principal's calendar homes, with
     * its path, in the order the homes name them.
     *
     * One walk for three questions: §2.1.1's Outbox, §2.2.1's Inbox, and
     * §4.3's calendars. The first two want the first of them and the third
     * wants all — §4.1 looking for a resource "belonging to the 'Attendee'"
     * wherever it is — so the walk answers in full and
     * {@see self::find()} takes the first.
     *
     * **A collection, and not merely something that says it is one.** §2.1,
     * §2.2 and RFC 4791 §4.2 each call these collections, so a store that
     * publishes one of their resource types on anything else has published
     * nothing this server can use: nowhere to deliver to, and nothing to list.
     *
     * The nodes come back with the paths, because every caller needs both and
     * fetching the node again from a path is a second lookup that can disagree
     * with the first.
     *
     * @return list<array{string, ICollection}>
     */
    private function collectionsOf(string $kind, string $principal, Principal $node): array
    {
        $homes = Answers::about(
            $this->server->events(),
            $principal,
            $node,
            PropFindForm::Named,
            [self::CALENDAR_HOME_SET],
        )->byStatus()[200][self::CALENDAR_HOME_SET] ?? null;

        $found = [];

        foreach (self::hrefsIn($homes) as $home) {
            $found = [...$found, ...$this->theOnesIn($kind, $home)];
        }

        return $found;
    }

    /**
     * Every `DAV:href` such a property holds, in order.
     *
     * Three properties are read this way — §2.4.1's addresses, RFC 4791
     * §6.2.1's calendar homes and `DAV:owner` — and all three hold hrefs and
     * nothing else. **What is in them is read rather than assumed**, because
     * whatever answered is another plugin or a store: an element of some other
     * name is not an href, and one that arrives pretty-printed carries its own
     * indentation.
     *
     * @return list<string>
     */
    private static function hrefsIn(mixed $property): array
    {
        if (!$property instanceof Element) {
            return [];
        }

        $hrefs = [];

        foreach ($property->children() as $child) {
            if ($child->name() === self::HREF) {
                $hrefs[] = trim($child->text());
            }
        }

        return $hrefs;
    }

    /**
     * The children of this home whose resource type carries that kind.
     *
     * @return list<array{string, ICollection}>
     */
    private function theOnesIn(string $kind, string $home): array
    {
        $path = $this->pathOf($home);

        if ($path === null || !$this->server->tree()->exists($path)) {
            return [];
        }

        $node = $this->server->tree()->node($path);

        if (!$node instanceof ICollection) {
            return [];
        }

        $found = [];

        foreach ($node->children() as $child) {
            if ($child instanceof ICollection && self::isOfKind($child, $kind)) {
                $found[] = [Path::join($path, $child->name()), $child];
            }
        }

        return $found;
    }

    /**
     * §2: a client may not delete either collection.
     *
     * > It is the server's responsibility to create these collection
     * > resources, and clients have no way to create or delete them.
     *
     * **A user who may empty their own calendar home would otherwise be able
     * to take their own Inbox away** and stop every invitation ever sent to
     * them, with one request and no way back. Creating one needs no guard:
     * nothing registers these resource types with
     * {@see \DavServices\Dav\Method\MkCol}, which refuses an unknown one by
     * name.
     *
     * This is a client's `DELETE` and not an application's `delete()`: a
     * deployment that takes a user away takes their collections with them, and
     * {@see Inbox::delete()} passes that straight to the store.
     *
     * @throws Forbidden If the path holds an Inbox or an Outbox
     */
    private function guardTheCollections(BeforeUnbind $event): void
    {
        $node = $this->server->tree()->node($event->path());

        foreach ([Inbox::INBOX, Outbox::OUTBOX] as $kind) {
            if (self::isOfKind($node, $kind)) {
                throw new Forbidden(
                    'RFC 6638 §2: a scheduling collection is the server to create and nobody to delete.',
                );
            }
        }

        $this->guardTheDefaultCalendar($event->path(), $node);
    }

    /**
     * §4.3: "Servers MUST reject any attempt to delete the default calendar
     * collection."
     *
     * **Without this a calendar user could take away the collection every
     * invitation to them is filed in**, with one request and no way back — and
     * §4.3's other MUST would then refuse every delivery to them, because "a
     * calendar user that is an 'Attendee' in a scheduling operation MUST have
     * at least one valid calendar collection available".
     *
     * §4.3.1.1 is the code to say it with, and it is worth more than the
     * `403`: "The client attempted to delete the calendar collection currently
     * referenced by the CALDAV:schedule-default-calendar-URL property."
     *
     * The guard asks §9.2's property on the Inbox of whoever owns the
     * calendar, so a deployment that keeps no default calendar — which §9.2
     * allows, the property being "MAY be defined" — has nothing refused.
     *
     * @throws Forbidden If the path holds somebody's default calendar
     */
    private function guardTheDefaultCalendar(string $path, INode $node): void
    {
        if (!$node instanceof Calendar) {
            return;
        }

        $owner = $this->ownerAt($path, $node);

        if ($owner === null) {
            return;
        }

        [$principal, $who] = $owner;
        $inbox = $this->find(Inbox::INBOX, $principal, $who);

        if ($inbox === null || $this->defaultCalendarAt($inbox, $who) !== $path) {
            return;
        }

        throw new Forbidden(
            'RFC 6638 §4.3: the default calendar collection is not to be deleted: '
                . 'the CALDAV:default-calendar-needed precondition of §4.3.1.1.',
            self::DEFAULT_CALENDAR_NEEDED,
        );
    }

    /**
     * Whether this node says it is of that kind.
     */
    private static function isOfKind(INode $node, string $kind): bool
    {
        return $node instanceof IResourceType && in_array($kind, $node->resourceTypes(), true);
    }
}
