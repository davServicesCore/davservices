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
 * A calendar that may be shared, and knows with whom it already is
 * (R-CAL-20).
 *
 * **There is no RFC.** The source is `caldav-sharing`, "Shared and Published
 * Calendars in CalDAV", C. Daboo and E. York, Apple, 19 September 2012 — a
 * Calendar Server extension that never became an IETF document, fetched by
 * `docs/referenzen/download-extensions.sh`.
 *
 * ## Not implementing it is an answer
 *
 * §5.5.2 makes the absence of §5.2.3's property mean something definite:
 *
 * > When not present on a calendar collection, sharing or publishing of that
 * > calendar is not allowed.
 *
 * So a calendar over a store that does not implement this is one that cannot
 * be shared, and that is the right default: §5.5.2 exists because "clients
 * need a way to determine which calendar could be shared so they can enable
 * or disable sharing options on a per-calendar basis", and a library that
 * announced sharing on every calendar would have clients offer it everywhere.
 *
 * ## Why a capability and not a backend
 *
 * The division the rest of the library keeps: what a *node* can tell about
 * itself is an interface here ({@see \DavServices\Dav\IResourceType},
 * `IQuota`, {@see INotification}), and what a *store* does is a
 * `Backend\I…Backend`. Whom a calendar is shared with is something only that
 * calendar knows, and nothing in this library asks it to be anywhere in
 * particular.
 *
 * ## One fact, not two
 *
 * {@see Calendar} works `CS:shared-owner` out from {@see sharees()} rather
 * than keeping it beside the list, which is how §5.3.1's MUST — "The server
 * MUST update the DAV:resourcetype property … to ensure it contains a
 * CS:shared-owner XML element" and the same sentence again for removing it —
 * is kept by something other than remembering to. Two facts about one thing
 * can drift apart; a derived one cannot.
 *
 * The cost is that {@see sharees()} is asked whenever `DAV:resourcetype` is,
 * which is nearly every `PROPFIND`. A store for which the list is expensive
 * should keep it cheap to ask for.
 *
 * ## Publishing is named here and defined nowhere
 *
 * {@see CAN_BE_PUBLISHED} exists because §5.2.3's grammar admits it and
 * §5.5.2 says what it means. **What no section of this document says is how a
 * calendar is published** — there is no request for it, although §5.5.2 speaks
 * of "requests to enable sharing or publishing". A store may therefore name
 * the mode where it has a way of its own; this library has nothing to offer
 * it, and nothing to take away either.
 */
interface IShareable
{
    /**
     * §6.3: this calendar can be shared with other users.
     */
    public const CAN_BE_SHARED = '{http://calendarserver.org/ns/}can-be-shared';

    /**
     * §6.4: this calendar can be published to anyone.
     */
    public const CAN_BE_PUBLISHED = '{http://calendarserver.org/ns/}can-be-published';

    /**
     * Which of the two modes are allowed on this calendar (§5.2.3).
     *
     * An empty list is a calendar that neither can be shared nor published,
     * and §5.5.2 reads each mode's absence that way one by one: "when not
     * present, the calendar collection cannot be shared", and the same of
     * publishing.
     *
     * @return list<string> {@see CAN_BE_SHARED}, {@see CAN_BE_PUBLISHED}, or
     *                      both. Order does not matter: §5.2.3's grammar is a
     *                      sequence, and {@see \DavServices\Plugin\Sharing}
     *                      puts them in it
     */
    public function allowedSharingModes(): array;

    /**
     * Whom this calendar is shared with, and how it stands with each (§5.2.2).
     *
     * An empty list is a calendar that is not shared, which is what §5.2.2
     * asks for: the property is "present on a calendar collection resource
     * that **has been shared** by the owner".
     *
     * A sharee who declined stays in the list — §5.4.2 has a refusal "update
     * the sharee's status", not remove the entry — so this is not a list of
     * people who have the calendar, but of people who were asked.
     *
     * @return list<Sharee>
     */
    public function sharees(): array;
}
