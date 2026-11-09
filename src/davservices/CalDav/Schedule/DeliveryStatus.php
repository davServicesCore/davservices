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

/**
 * What came of delivering a scheduling message (RFC 6638 §3.2.9).
 *
 * §3.2.9 has two kinds of status, and this is the first: "a 'delivery' status
 * that indicates whether the scheduling message from the 'Organizer' to the
 * 'Attendee' was delivered or not, or what the current status of delivery is".
 * It is carried by §7.3's `SCHEDULE-STATUS` parameter, and §3.2.1.1 makes
 * writing it a MUST of its own:
 *
 * > The server MUST add a "SCHEDULE-STATUS" iCalendar property parameter (see
 * > Section 7.3) to the "ATTENDEE" iCalendar property in the scheduling object
 * > resource being created, and set its value as described in Section 3.2.9.
 *
 * ## Four of the table's codes, because four are the answers this server has
 *
 * §3.2.9 tabulates eleven. The ones here are the ones a local delivery can
 * end in, each with the sentence that picks it:
 *
 * - **{@see Delivered}** — "The scheduling message has been successfully
 *   delivered." A message written into the calendar user's own scheduling
 *   Inbox collection on this server has been, with nothing left to find out.
 * - **{@see NoSuchCalendarUser}** — "the server did not recognize the calendar
 *   user address as a valid calendar user", which is what
 *   {@see \DavServices\Backend\ICalendarUserBackend::principalFor()} answering
 *   nothing says.
 * - **{@see NotCompleted}** — "the server could not complete delivery of the
 *   message. This is likely a temporary failure, and the originator can try to
 *   send the message again at a later time." A store that refused the write.
 * - **{@see NoWayToDeliver}** — "the server was not able to find a way to
 *   deliver the message", which covers §2.2.1's own sentence about a calendar
 *   user with no scheduling Inbox collection: "If not present, then the
 *   associated calendar user is not enabled for reception of scheduling
 *   messages on the server."
 * - **{@see Rejected}** — "not delivered and was rejected because scheduling
 *   with that recipient is not allowed". §4.3 uses that very verb for a
 *   calendar user with no calendar to keep the event in: "If there is no valid
 *   calendar collection, then the server MUST **reject** the attempt to deliver
 *   the scheduling message to the 'Attendee'." Of the two permanent failures
 *   this one tells the organizer not to try again, where `5.2` would have them
 *   check the address — and the address was right.
 *
 * **The others are somebody else's to report.** `1.0` and `1.1` belong to a
 * server that hands a message to something slower than itself — §3.2.9.2 names
 * iMIP, "store and forward style scheduling protocols" — and this one either
 * has written the resource or has not. `3.8` is "insufficient privileges",
 * which needs §6's scheduling privileges to be there to consult. And the 2.x
 * codes are §3.2.9's other kind, "a 'reply' status corresponding to the
 * 'Attendee's' own 'REQUEST-STATUS'", which arrives with §4.2.
 *
 * ## Where it may not be written
 *
 * §3.2.1.1: "Servers MUST NOT set the 'SCHEDULE-STATUS' property parameter on
 * the 'ATTENDEE' property of 'Attendees' for which it did not attempt to
 * deliver a scheduling message." So an attendee this server was told to leave
 * alone — §7.1's `CLIENT` or `NONE` — gets none of these, and neither does the
 * organizer's own `ATTENDEE` property: §3.2.1 forbids a message there, and no
 * message means no status.
 */
enum DeliveryStatus: string
{
    /**
     * §3.2.9.3: "The iTIP message has been sent and delivered."
     */
    case Delivered = '1.2';

    /**
     * §3.2.9's `3.7`: no calendar user is reachable at that address.
     */
    case NoSuchCalendarUser = '3.7';

    /**
     * §3.2.9's `5.1`: the server could not complete the delivery, and the
     * message may be worth sending again later.
     */
    case NotCompleted = '5.1';

    /**
     * §3.2.9's `5.2`: there is no way to deliver to that calendar user.
     */
    case NoWayToDeliver = '5.2';

    /**
     * §3.2.9's `5.3`: the message was rejected, scheduling with that
     * recipient not being allowed — which is also what §4.3 asks for where a
     * calendar user has no calendar at all to keep an event in.
     */
    case Rejected = '5.3';

    /**
     * §7.3's parameter name, which is where one of these values goes — and
     * the one {@see SchedulingMessage} has to leave out of a message.
     */
    public const NAME = 'SCHEDULE-STATUS';
}
