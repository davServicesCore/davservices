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
 * A sharee's copy of somebody else's calendar (R-CAL-20).
 *
 * The other side of {@see IShareable}, and §5.2.1 of `caldav-sharing` puts
 * them side by side as opposites: `CS:shared-owner` is for a calendar "owned
 * by the current user and is being shared by them", `CS:shared` for one "owned
 * by **another** user and is being shared to the current user".
 *
 * §6.2 says where such a calendar lives — it "appears in the sharee's calendar
 * home collection" — and §5.4.1 says where it comes from: "the URI of the
 * shared calendar in the sharee's calendar home **created by the invite
 * acceptance**". So nothing is one of these until somebody has accepted an
 * invitation, which is why this arrives with the acceptance rather than with
 * the properties of P7-05a.
 *
 * ## One answer, three uses
 *
 * The one thing it has to know is where the original is, and that one answer
 * does three jobs:
 *
 * - it **is** §5.2.4's `CS:shared-url`, "the URL of the sharer's calendar
 *   being shared";
 * - it says **who the sharer is**, because the calendar it names carries the
 *   `DAV:owner` that §5.5.5 makes "a reference to a principal-URL of the
 *   sharer (owner)" — so §5.2.2's `CS:organizer` needs nothing stored
 *   anywhere;
 * - and it is how §5.4.2's refusal finds the list to correct: "the server MUST
 *   remove the shared calendar from the sharee's calendar home and
 *   automatically update the sharee's status in the sharer's calendar's
 *   CS:invite property".
 *
 * **One fact, so there is nothing to keep in step.** A copy that stored the
 * sharer's name beside the URL could disagree with the calendar it points at.
 *
 * ## What the copy holds is the application's business
 *
 * Nothing here says where the events come from. A sharee's copy is a view on
 * somebody else's store, and how that is arranged — a wrapper, a database
 * view, a second mapping of the same rows — is exactly the kind of thing a
 * library that holds no data cannot do for an application. §5.4.2's other
 * half rests on it: a `DELETE` of the copy "does not delete data", which only
 * a store that knows the copy is a view can honour.
 */
interface ISharedWithMe
{
    /**
     * Where the sharer's own calendar is, as a URL (§5.2.4).
     *
     * On this server, since §5.3.2 only ever shares between principals it
     * hosts: a URL this server cannot resolve to one of its own calendars
     * leaves {@see \DavServices\Plugin\Sharing} nothing to answer §5.2.2 with
     * and nobody to correct when the copy goes away.
     */
    public function sharedUrl(): string;
}
