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

use DavServices\Acl\Privilege;

/**
 * The eight privileges of RFC 6638 §6, as the two branches §6.3 prescribes.
 *
 * > All the scheduling privileges MUST be non-abstract and MUST appear in the
 * > DAV:supported-privilege-set property of scheduling Outbox and Inbox
 * > collections on which they are defined.
 *
 * **Two branches, not one tree**, because each of §6.1 and §6.2 ends with the
 * same sentence about the other kind of collection:
 *
 * > The privileges defined in this section are ignored if applied to a
 * > resource other than a scheduling Inbox collection.
 *
 * A privilege that is ignored where it is applied is not, in RFC 3744 §5.3's
 * words, among "the privileges defined for the resource" — and §5.3 describes
 * exactly who is misled by listing it anyway: an administrative client that
 * "would list the supported privileges in a dialog box, and allow the user to
 * choose non-abstract privileges to apply in an ACE". Offering a calendar
 * user a right the server will then disregard is worse than offering none.
 *
 * **§6.3 is not read as requiring both branches everywhere `DAV:all` is.** It
 * prescribes the aggregation — what granting `DAV:all` comes to — and RFC 3744
 * §5.3 says of its own example tree that it "is not normative (except that it
 * reflects the normative aggregation rules given in Section 3.12)". The rule
 * binds the **place** a privilege appears in, not that it appears at all. The
 * sentence that decides appearing is §6's first, and it names the collections:
 * "on which they are defined".
 *
 * **Which collection gets which branch is the collection's answer**, through
 * {@see \DavServices\Acl\IPrivileges} — the same way `DAV:resourcetype` is the
 * node's. This class cannot be asked by {@see \DavServices\Plugin\Acl}
 * directly: access control is a layer **below** CalDAV, for the reason
 * {@see Privilege} gives of `CALDAV:read-free-busy`.
 *
 * ## None of the eight is abstract, and that is against §5.3's example
 *
 * RFC 3744 §5.3 marks `DAV:all` abstract in its example and says what follows:
 * "An abstract privilege MUST NOT be used in an ACE for that resource. Servers
 * MUST fail an attempt to set an abstract privilege." §6 says the opposite of
 * all eight, the two aggregates included, so a deployment that grants
 * `CALDAV:schedule-deliver` in a single entry has to be obeyed.
 *
 * ## Two of the eight guard something this library does not do yet
 *
 * Appendix A gives each privilege the methods it controls, and
 * `CALDAV:schedule-query-freebusy` and `CALDAV:schedule-send-freebusy` are
 * starred in one row only — `VFREEBUSY` with `REQUEST`, which is the `POST` of
 * §5. **Until that exists they appear and guard nothing**, which is said here
 * rather than left for somebody to find: §6's MUST is about all eight, and
 * leaving two out to keep the tree honest about enforcement would break the
 * sentence that is actually written.
 *
 * `CALDAV:schedule-query-freebusy` is **not** the privilege of the
 * `CALDAV:free-busy-query` report either. That report is governed by
 * `CALDAV:read-free-busy` (RFC 4791 §6.1.1), which this library already has,
 * and Appendix A's `VFREEBUSY` row is about an iTIP request through a
 * scheduling Outbox instead.
 */
final class Privileges
{
    /**
     * §6.1.1, an aggregate of the three below it.
     */
    public const DELIVER = '{urn:ietf:params:xml:ns:caldav}schedule-deliver';

    /**
     * §6.1.2: "controls the processing and delivery of scheduling messages
     * coming from an 'Organizer'".
     */
    public const DELIVER_INVITE = '{urn:ietf:params:xml:ns:caldav}schedule-deliver-invite';

    /**
     * §6.1.3: "controls the processing and delivery of scheduling messages
     * coming from an 'Attendee'".
     */
    public const DELIVER_REPLY = '{urn:ietf:params:xml:ns:caldav}schedule-deliver-reply';

    /**
     * §6.1.4: "controls freebusy requests targeted at the owner of the
     * scheduling Inbox collection".
     */
    public const QUERY_FREEBUSY = '{urn:ietf:params:xml:ns:caldav}schedule-query-freebusy';

    /**
     * §6.2.1, an aggregate of the three below it.
     */
    public const SEND = '{urn:ietf:params:xml:ns:caldav}schedule-send';

    /**
     * §6.2.2: "controls the sending of scheduling messages by 'Organizers'".
     */
    public const SEND_INVITE = '{urn:ietf:params:xml:ns:caldav}schedule-send-invite';

    /**
     * §6.2.3: "controls the sending of scheduling messages by 'Attendees'".
     */
    public const SEND_REPLY = '{urn:ietf:params:xml:ns:caldav}schedule-send-reply';

    /**
     * §6.2.4: "controls the use of the POST method to submit scheduling
     * messages that specify the scheduling method 'REQUEST' with a
     * 'VFREEBUSY' calendar component".
     */
    public const SEND_FREEBUSY = '{urn:ietf:params:xml:ns:caldav}schedule-send-freebusy';

    /**
     * What a scheduling Inbox collection defines beyond what this server
     * defines everywhere (§6.1).
     *
     * @param Privilege $everywhere The tree of RFC 3744 §3 as this server
     *                              granted it, which the branch is hung into
     *                              rather than replacing — a node that
     *                              answered only its own would take every
     *                              standard privilege off its own report
     */
    public static function anInboxDefines(Privilege $everywhere): Privilege
    {
        return $everywhere->with('{DAV:}all', new Privilege(self::DELIVER, 'have scheduling messages delivered here', [
            new Privilege(self::DELIVER_INVITE, 'have a request from an organizer delivered to the owner of this Inbox'),
            new Privilege(self::DELIVER_REPLY, 'have a reply from an attendee delivered to the owner of this Inbox'),
            new Privilege(self::QUERY_FREEBUSY, 'ask when the owner of this Inbox is busy'),
        ]));
    }

    /**
     * What a scheduling Outbox collection defines beyond what this server
     * defines everywhere (§6.2).
     *
     * @param Privilege $everywhere As {@see self::anInboxDefines()}
     */
    public static function anOutboxDefines(Privilege $everywhere): Privilege
    {
        return $everywhere->with('{DAV:}all', new Privilege(self::SEND, 'send scheduling messages as the owner of this Outbox', [
            new Privilege(self::SEND_INVITE, 'send a request as the organizer, on behalf of the owner of this Outbox'),
            new Privilege(self::SEND_REPLY, 'send a reply as an attendee, on behalf of the owner of this Outbox'),
            new Privilege(self::SEND_FREEBUSY, 'ask other calendar users when they are busy, on behalf of the owner of this Outbox'),
        ]));
    }
}
