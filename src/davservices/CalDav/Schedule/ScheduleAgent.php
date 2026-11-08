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

use DavServices\VObject\Property;

/**
 * Who is to deliver the scheduling messages for this property (RFC 6638 §7.1).
 *
 * **The first question every table in §3 asks**, because it decides whether
 * anybody is told at all:
 *
 * > scheduleagentparam = "SCHEDULE-AGENT" "="
 * >                  ("SERVER"      ; The server handles scheduling
 * >                 / "CLIENT"      ; The client handles scheduling
 * >                 / "NONE"        ; No scheduling
 * >                 / x-name        ; Experimental type
 * >                 / iana-token)   ; Other IANA-registered type
 *
 * §7.1 then says what the three mean: "When the value 'SERVER' is specified,
 * or the parameter is absent, then it is the server's responsibility to send a
 * scheduling message as part of a scheduling operation. When the value
 * 'CLIENT' is specified, that indicates that the client is handling scheduling
 * messages with the calendar user itself. When 'NONE' is specified, no
 * scheduling messages are being sent to the calendar user."
 *
 * ## Both ends of the grammar are a MUST
 *
 * **Absent means the server.** "In the absence of this parameter, the value
 * 'SERVER' MUST be used for the default behavior" — so the ordinary
 * invitation, an `ATTENDEE` with nothing on it, is this server's to deliver.
 *
 * **And anything it does not know means nobody.** "Servers and clients MUST
 * treat x-name and iana-token values they do not recognize the same way as
 * they would the 'NONE' value." A value of nothing, or a list where §7.1's
 * grammar has one value, is read the same way for the same sentence: of the
 * two directions to be wrong in, the one where nobody is told can be put right
 * by a client, and the other has already sent somebody's invitation on the
 * strength of a parameter nobody understood.
 *
 * {@see None} is kept apart from {@see Client} although §3.2.1.1's table
 * treats them alike, because §7.1 gives them different meanings and the enum
 * is the parameter rather than any one table.
 *
 * ## Where it is read, and where it may not be written
 *
 * §7.1 allows the parameter "on 'ORGANIZER' or 'ATTENDEE' iCalendar
 * properties", so this is asked of a property rather than of an attendee.
 *
 * And it is read only off what a client stored: "Servers MUST NOT include this
 * parameter in any scheduling messages sent as the result of a scheduling
 * operation", which is {@see SchedulingMessage}'s business.
 */
enum ScheduleAgent
{
    /**
     * This server delivers the scheduling messages (§7.1's default).
     */
    case Server;

    /**
     * The client is handling them with the calendar user itself.
     */
    case Client;

    /**
     * Nobody is to be told — and what §7.1 makes of a value it cannot read.
     */
    case None;

    /**
     * §7.1's parameter name, which is also the one
     * {@see SchedulingMessage} has to leave out of a message.
     */
    public const NAME = 'SCHEDULE-AGENT';

    private const SERVER = 'SERVER';

    private const CLIENT = 'CLIENT';

    /**
     * What this property says about who delivers for it.
     *
     * @param Property $property An `ORGANIZER` or an `ATTENDEE`, as §7.1
     *                           allows the parameter on either
     */
    public static function on(Property $property): self
    {
        $parameter = $property->parameter(self::NAME);

        if ($parameter === null) {
            return self::Server;
        }

        $values = $parameter->values();

        // §7.1's grammar has one value. Nothing, or a list, is not a value
        // this server recognises, and its sentence about those is `NONE`.
        if (count($values) !== 1) {
            return self::None;
        }

        // Compared without regard to case, RFC 5545 §3.5 making an enumerated
        // parameter value case-insensitive — and all three of §7.1's are
        // enumerated.
        if (strcasecmp($values[0], self::SERVER) === 0) {
            return self::Server;
        }

        if (strcasecmp($values[0], self::CLIENT) === 0) {
            return self::Client;
        }

        // Which leaves `NONE` and everything this server does not know, which
        // §7.1 reads the same way.
        return self::None;
    }
}
