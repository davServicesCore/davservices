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

namespace DavServices\CalDav;

/**
 * How a sharee answered the invitation to a shared calendar (R-CAL-20).
 *
 * **Four answers and exactly one of them**, from the grammar of §6.5 of
 * `caldav-sharing`:
 *
 *     <!ELEMENT user (DAV:href, common-name?, (invite-noresponse |
 *                     invite-accepted | invite-declined | invite-invalid),
 *                     access, summary?)>
 *
 * **The fourth is not an answer at all**, and that is why it belongs in the
 * same list: §6.10 has `CS:invite-invalid` say "that the corresponding sharee
 * is not a valid calendar user known to the server". The sharer put down an
 * address, nobody is behind it, and the entry stays on the calendar saying so
 * — which is the only way the sharer finds out.
 *
 * Each case carries its element name, so that a store naming a state and the
 * document carrying it cannot come apart.
 *
 * **The same four elements mean something else in a notification** (§6.6 to
 * §6.9, each with two readings: one "when used in a CS:user element", one
 * "when used in a CS:invite-notification element"). This enum is the first
 * reading. The second belongs with the notification that carries it.
 */
enum InviteStatus: string
{
    /**
     * Asked, and has not answered (§6.6).
     *
     * > this element is used to indicate that the sharee has never replied to
     * > the corresponding sharing invite.
     */
    case NoResponse = '{http://calendarserver.org/ns/}invite-noresponse';

    /**
     * Said yes (§6.8), and so has a copy of the calendar in their own home.
     */
    case Accepted = '{http://calendarserver.org/ns/}invite-accepted';

    /**
     * Said no (§6.9), **and is still a sharee**: §5.4.2 has a refusal "update
     * the sharee's status in the sharer's calendar's CS:invite property", not
     * take the entry away. Only the sharer's `CS:remove` (§6.28) does that.
     */
    case Declined = '{http://calendarserver.org/ns/}invite-declined';

    /**
     * Nobody the server knows (§6.10).
     *
     * > this element is used to indicate that the corresponding sharee is not
     * > a valid calendar user known to the server.
     */
    case Invalid = '{http://calendarserver.org/ns/}invite-invalid';
}
