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

namespace DavServices\Dav\Calendars;

/**
 * What a store knows about one calendar subscription (R-CAL-04).
 *
 * **The feed is not optional, and the type says so.** That is the only rule
 * anything states about a subscription: the lapsed
 * `draft-ietf-calext-serverside-subscriptions-02` made its own href "MUST be
 * defined on any collection which has a resource-type containing a
 * DAV:subscription element", and the convention this library follows agrees
 * implicitly — a collection that followed nothing would not be a subscription.
 * {@see \DavServices\CalDav\Subscription} is where the rest of the convention,
 * and what was checked to establish it, is written down.
 *
 * **The name** is the member name inside the principal's home, which is the
 * last segment of the collection's URL. A server may choose it freely, which is
 * why the next one exists.
 *
 * **The display name** is `DAV:displayname`. A client has nothing else to show
 * a user where the segment is a number, and OS X sets one whenever it creates a
 * subscription.
 *
 * **Null means not set**, and never "empty". A `PROPFIND` for a property nobody
 * set is a `404`; a server answering an empty string instead would be claiming
 * the user named their subscription nothing at all.
 *
 * ## What is deliberately not here
 *
 * The observed creation request carries more than these three — the namespace
 * of the extension has `subscribed-strip-alarms`, `subscribed-strip-attachments`
 * and `subscribed-strip-todos`, and Apple's *other* namespace carries
 * `{http://apple.com/ns/ical/}refreshrate`. **None of them is modelled**, for
 * two reasons that hold together: nothing anywhere says what their values look
 * like — the request writes the three flags as empty elements, which cannot
 * express "no" — and a client sets them and reads them back, which is what dead
 * property storage already does for `{http://calendarserver.org/ns/}calendar-color`
 * and every other property this library does not model. Inventing a boolean
 * where the only evidence is an empty element would be guessing in public.
 *
 * It lives in `Dav\Calendars` beside {@see CalendarInfo} rather than next to
 * the interface that hands it out, because a backend interface may not reach up
 * into the layer that uses it.
 */
final readonly class SubscriptionInfo
{
    /**
     * @param string $name The member name inside the principal's home
     * @param string $source The feed this follows, as
     *                       `{http://calendarserver.org/ns/}source` carries it
     *                       in a `DAV:href`. **Kept exactly as it arrived**: a
     *                       store that normalised it would hand a client
     *                       something it did not subscribe to
     * @param string|null $displayName `DAV:displayname`, or null where none is
     *                                 set
     */
    public function __construct(
        private string $name,
        private string $source,
        private ?string $displayName = null,
    ) {
    }

    /**
     * The member name inside the principal's home.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * The feed this subscription follows.
     */
    public function source(): string
    {
        return $this->source;
    }

    /**
     * `DAV:displayname`, or null where none is set.
     */
    public function displayName(): ?string
    {
        return $this->displayName;
    }
}
