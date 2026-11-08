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
use DavServices\Dav\Event\BeforeCreateFile;
use DavServices\Dav\Event\BeforeUnbind;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\ICollection;
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
 * - and **§3.2.1.1's Create**: an organizer's new event reaches the Inbox of
 *   everybody on the attendee list, and the stored resource says what came of
 *   each delivery ({@see self::deliverOnCreate()}).
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
 * **No `calendar-auto-schedule` token**, although §3.2.1.1 is answered now.
 * §2 puts the token on "any resource that supports any scheduling operations,
 * properties, privileges, or methods", and on those words this plugin has
 * earned it. It is withheld all the same, because of what the token is *read
 * for*: a client that sees it stops sending invitations itself and leaves that
 * to the server. With Create alone that would mean the invitation arriving and
 * every later **change** and every **cancellation** going missing in silence —
 * §3.2.1.2 and §3.2.1.3 are not here yet. What goes missing would be somebody's
 * meeting rather than a feature. The token goes in when all three operations
 * are.
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
        $events->on(BeforeCreateFile::class, $this->deliverOnCreate(...));
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
        $principal = $this->calendarUsers?->principalFor($address);

        if ($principal === null) {
            return DeliveryStatus::NoSuchCalendarUser;
        }

        $inbox = $this->inboxFor($principal);

        if ($inbox === null) {
            return DeliveryStatus::NoWayToDeliver;
        }

        if ($inbox->hasChild($message->name())) {
            return DeliveryStatus::Delivered;
        }

        try {
            $inbox->createFile($message->name(), $message->text());
        } catch (IHttpFailure) {
            return DeliveryStatus::NotCompleted;
        }

        return DeliveryStatus::Delivered;
    }

    /**
     * The scheduling Inbox collection of the principal this URL names, or null
     * where there is none to deliver to.
     *
     * **Written into the store rather than through a request**, which is §4's
     * own arrangement: "Privileges on calendars containing any matching
     * scheduling object resource are not considered in this case (i.e., a
     * schedule message from another user can cause modifications to resources
     * in calendar collections that the other user would not normally have read
     * or write access to)." What §4 does ask to be taken into account are the
     * privileges on the Inbox itself, and those are §6's `schedule-deliver`,
     * which is not defined yet — until it is, an application that will not
     * take a message refuses it from the store, which is §3.2.9's `5.1`.
     *
     * Null wherever the way there breaks: an address this server cannot
     * resolve, a principal that is not there, one with no Inbox — §2.2.1's
     * "not enabled for reception of scheduling messages on the server" — or a
     * store that publishes the resource type on something that is no
     * collection.
     */
    private function inboxFor(string $url): ?ICollection
    {
        try {
            $path = $this->server->pathOf($url);
        } catch (DavException | MalformedPath) {
            return null;
        }

        if (!$this->server->tree()->exists($path)) {
            return null;
        }

        $node = $this->server->tree()->node($path);

        if (!$node instanceof Principal) {
            return null;
        }

        $where = $this->find(Inbox::INBOX, $path, $node);

        if ($where === null) {
            return null;
        }

        $inbox = $this->server->tree()->node($where);

        return $inbox instanceof ICollection ? $inbox : null;
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
        $owner = self::hrefsIn(
            Answers::about($this->server->events(), $path, $node, PropFindForm::Named, [self::OWNER])
                ->byStatus()[200][self::OWNER] ?? null,
        )[0] ?? null;

        if ($owner === null) {
            return [];
        }

        try {
            $where = $this->server->pathOf($owner);
        } catch (DavException | MalformedPath) {
            return [];
        }

        if (!$this->server->tree()->exists($where)) {
            return [];
        }

        $who = $this->server->tree()->node($where);

        return self::hrefsIn(
            Answers::about($this->server->events(), $where, $who, PropFindForm::Named, [self::ADDRESS_SET])
                ->byStatus()[200][self::ADDRESS_SET] ?? null,
        );
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
        $homes = Answers::about(
            $this->server->events(),
            $principal,
            $node,
            PropFindForm::Named,
            [self::CALENDAR_HOME_SET],
        )->byStatus()[200][self::CALENDAR_HOME_SET] ?? null;

        foreach (self::hrefsIn($homes) as $home) {
            $found = $this->findIn($kind, $home);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
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
     * The first child of this home whose resource type carries that kind.
     */
    private function findIn(string $kind, string $home): ?string
    {
        try {
            $path = $this->server->pathOf($home);
        } catch (MalformedPath) {
            return null;
        }

        if (!$this->server->tree()->exists($path)) {
            return null;
        }

        $node = $this->server->tree()->node($path);

        if (!$node instanceof ICollection) {
            return null;
        }

        foreach ($node->children() as $child) {
            if (self::isOfKind($child, $kind)) {
                return Path::join($path, $child->name());
            }
        }

        return null;
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
    }

    /**
     * Whether this node says it is of that kind.
     */
    private static function isOfKind(INode $node, string $kind): bool
    {
        return $node instanceof IResourceType && in_array($kind, $node->resourceTypes(), true);
    }
}
