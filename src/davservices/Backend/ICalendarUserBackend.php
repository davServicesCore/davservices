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

namespace DavServices\Backend;

/**
 * Which calendar addresses belong to whom (RFC 6638 §2.4.1).
 *
 * **The seam to an application's own idea of who is reachable at what**, the
 * way {@see IPrincipalBackend} is the seam to its idea of who exists. Almost
 * nobody keeps that in a WebDAV server either: it is in a directory, a
 * database, an identity provider — and §2.4.1 says what the server needs it
 * for:
 *
 * > This property is needed to map calendar user addresses in iCalendar data
 * > to principal resources and their associated scheduling Inbox and Outbox
 * > collections.
 *
 * ## Two directions, one table
 *
 * That sentence asks for the map **from an address**, while §2.4.1's property
 * answers **from a principal**, and both are the same list read two ways. They
 * are one interface because two seams holding one list can disagree: a server
 * that answered `mailto:bernard@example.com` as Bernard's address and then
 * found nobody at that address would be contradicting itself inside one
 * request.
 *
 * Before this interface the forward direction was a callable on
 * {@see \DavServices\Plugin\CalDav}. It is here now because the reverse
 * direction has arrived and had nowhere to live that could not drift from it.
 *
 * ## Who asks, and why it is not only scheduling
 *
 * - **{@see \DavServices\Plugin\CalDav}** answers §2.4.1's property from
 *   {@see addressesOf()}.
 * - **{@see \DavServices\Plugin\Sharing}** resolves a sharee by
 *   {@see principalFor()}: `caldav-sharing` §5.3.2 lets a sharer name somebody
 *   by "a calendar user address or email address", and without this a sharee
 *   so named could never answer the invitation — §5.4.1's reply is matched to
 *   whoever is asking, and an address that resolves to nobody matches nobody.
 * - **Scheduling** will ask it of every `ATTENDEE` of a scheduling object
 *   resource, to find the Inbox a message goes to (§4).
 *
 * ## Nothing is a proper answer
 *
 * An empty list is §2.4.1's own: "If not present, then the associated calendar
 * user is not enabled for scheduling on the server." And null from
 * {@see principalFor()} is §5.3.2 of `caldav-sharing` read the same way: "the
 * sharee might not be a user on the same server - though in that case how
 * invitations are sent or access enabled is out of scope for this
 * specification."
 *
 * So a deployment that wires none of this is not broken; it is a deployment
 * whose calendar users have no addresses, and every caller treats that as the
 * answer rather than as a failure.
 */
interface ICalendarUserBackend
{
    /**
     * The calendar addresses of this principal, as §2.4.1's property holds
     * them.
     *
     * Asked only where somebody asked for the property or for a scheduling
     * decision, because an application's answer may well go looking in a
     * directory — a `PROPFIND` for `DAV:displayname` has no business costing
     * that.
     *
     * @param string $principal The member name inside the principal
     *                          collection, as {@see IPrincipalBackend} takes
     *                          it
     *
     * @return list<string> Each address as it is to be published, which
     *                      §2.4.1 holds as `DAV:href*` — "mailto:…", a
     *                      "urn:uuid:…", or the URL of the principal itself
     *                      where the user "has no well-defined identifier"
     */
    public function addressesOf(string $principal): array;

    /**
     * Which principal is reachable at this address, or null where none is.
     *
     * **The answer is a URL and not a name**, which is what the callers need
     * and what this library can use without knowing where a deployment mounts
     * its principals: a name would have to be joined to a path only
     * {@see \DavServices\Plugin\Principals} knows. §2.4.1 puts addresses and
     * principal URLs in one space anyway — "the URI of his principal resource
     * can be used" as an address — so answering in that space costs nothing.
     *
     * @param string $address One calendar user address, compared however the
     *                        directory compares addresses. **That is the
     *                        application's decision and not this library's**:
     *                        RFC 5545 §3.1 makes a property value
     *                        case-sensitive, which is what
     *                        {@see \DavServices\CalDav\Schedule\SchedulingObject}
     *                        holds itself to when it compares two values out
     *                        of one document — but whether two spellings name
     *                        one person is a question about people, and only
     *                        something that knows them can answer it
     *
     * @return string|null The principal's URL, as this server publishes it
     */
    public function principalFor(string $address): ?string;
}
