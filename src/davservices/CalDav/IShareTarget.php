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
 * A calendar whose sharee list a client may change (R-CAL-20).
 *
 * §5.3.2 of `caldav-sharing` names it in those words: the sharer "is able to
 * manipulate the sharee list by issuing a POST request **targeted at** the
 * calendar collection resource", which is the same shape
 * {@see \DavServices\Dav\ICopyTarget} and `IMoveTarget` have — a node that
 * takes an operation on itself.
 *
 * ## It is an {@see IShareable} as well, and has to be
 *
 * A sharee list that can be changed and not read would leave §5.2.2
 * unanswerable: that section has `CS:invite` "present on a calendar collection
 * resource that has been shared by the owner", so a calendar somebody can
 * share is a calendar that has to say with whom. Extending says it once
 * instead of leaving every caller to ask twice.
 *
 * ## Why it is still separate from {@see IShareable}
 *
 * Because §5.5.2 makes them separate:
 *
 * > Servers MAY support calendar sharing on a per-calendar basis - e.g., they
 * > could treat some calendars as always private (cannot be shared) or always
 * > public (**always shared**).
 *
 * An "always shared" calendar has a sharee list worth reading and nobody's to
 * change. Implementing `IShareable` alone says exactly that, and a single
 * interface would have forced such a store to carry methods documented as
 * never reached.
 *
 * ## Why not a `Backend\ISharingBackend`
 *
 * `UMSETZUNG.md` named one, and the plan names target classes as a sketch
 * rather than a contract — the same decision P7-06 recorded when it built
 * {@see INotification} instead of a notification backend.
 *
 * The reason here is "one fact, not two". A backend addressed by path would
 * hold the sharee list a second time, beside the one `IShareable::sharees()`
 * answers, and two holdings of one list can disagree: a client would read one
 * and be granted by the other. The thing that answers who a calendar is
 * shared with is the thing that should change it.
 *
 * ## How a store refuses
 *
 * **By leaving its list alone.** {@see \DavServices\Plugin\Sharing} works out
 * what to notify whom about by comparing the list before and after, so a
 * change that did not happen sends nothing — a store that declines quietly
 * cannot cause a notification about something that is not so.
 *
 * A store that knows the address is nobody may instead keep the sharee with
 * {@see InviteStatus::Invalid}, which §6.10 defines for exactly that — "the
 * corresponding sharee is not a valid calendar user known to the server" —
 * and which §6.15's grammar leaves out of a notification, there being nobody
 * to send one to. And a store that wants to refuse out loud throws; its
 * failure is the client's answer.
 */
interface IShareTarget extends IShareable
{
    /**
     * Gives this sharee the access they are named with (§5.3.2's `CS:set`).
     *
     * > For each CS:set element, the server MUST add the specified sharee
     * > access to the calendar.
     *
     * **Adding and changing are one operation**, §6.27 saying so: "Used to add
     * or modify sharee access to a shared calendar." A sharee already on the
     * list arrives with the state they are already in, so that an update does
     * not ask somebody to accept again what they are already using (§6.8).
     */
    public function shareWith(Sharee $sharee): void;

    /**
     * Takes this sharee's access away (§5.3.2's `CS:remove`).
     *
     * > For each CS:remove element the server MUST remove the specified
     * > sharee access from the shared calendar.
     *
     * All of it, §6.28 being explicit: "All access to the shared calendar is
     * removed for the sharee." An address that is on no list is nothing to do.
     *
     * @param string $href The sharee's address as §5.3.2 allows it — a
     *                     principal-URL, a calendar user address or an email
     *                     address — compared as it stands
     */
    public function stopSharingWith(string $href): void;
}
