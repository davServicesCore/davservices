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

use DavServices\Dav\Calendars\SubscriptionInfo;
use DavServices\Exception\Conflict;
use DavServices\Exception\Forbidden;
use DavServices\Exception\NotFound;

/**
 * Where a user's calendar subscriptions are kept (R-CAL-04, R-BE-01).
 *
 * A subscription is a calendar somebody reads and does not own: the public
 * holidays, a team's feed, a fixture list. The server keeps it so that all of a
 * user's devices see the same set — {@see \DavServices\CalDav\Subscription} is
 * the collection it becomes, and this says where the record lives. The two
 * never meet: a backend interface may not reach up into the layer that uses it.
 *
 * **What the convention is, and what was checked to establish it**, is written
 * down in {@see \DavServices\CalDav\Subscription}: no RFC defines
 * subscriptions, no document in Apple's own extension directory does either,
 * and the one IETF attempt expired in 2022 naming different elements.
 *
 * ## Why this store writes where the calendar store does not
 *
 * {@see ICalendarBackend} reads calendars and writes their *objects*. A
 * calendar's contents are the data, so making the calendar itself is a
 * collection's business ({@see \DavServices\Dav\IExtendedCollection}), and that
 * interface has no `createCalendar()` for the same reason.
 *
 * **A subscription has no contents of its own.** It is a name, a feed and a
 * display name; the record *is* the datum, and the objects a server may cache
 * from the feed are a copy of somebody else's. So the store that keeps a
 * subscription is also the one that makes and unmakes it.
 *
 * ## Nothing here fetches anything
 *
 * This interface hands out what a user subscribed to. **It does not go and get
 * it**, and neither does anything else in this library: there is no outbound
 * HTTP client here and no runtime dependencies at all (R-ARC-01). A library
 * that followed a URL a client had named would also be a library that could be
 * asked to reach inside the network it runs in.
 *
 * So refreshing a subscription is an application's job — a scheduled task that
 * reads this, fetches, and writes the objects into whatever collection the
 * subscription wraps. The cut is here so that the two halves can be built
 * apart.
 *
 * ## What an implementation owes (R-BE-03)
 *
 * The same rules as every other store here, because they are rules about
 * stores:
 *
 * - **A missing thing is `null` or an empty list.** A principal with no
 *   subscriptions is the ordinary state — most people have none — and a store
 *   that threw would make "none yet" indistinguishable from "something is
 *   broken".
 * - **A missing thing that was *named* is `NotFound`.** Changing or removing
 *   one that is not there is a mistake the caller has to hear about, where
 *   answering nothing would let a client believe it had unsubscribed from
 *   something it still gets.
 * - **A member name is one path segment**, and anything else is `Forbidden`.
 * - **A path that merely begins the same way is a different path.**
 *   `holidays2` does not lie inside `holidays`.
 * - **No order is promised.** A store answers in whatever order its index has.
 * - **A subscription is named inside a principal's home, not globally.** Two
 *   people both subscribing to the holidays and both calling it `holidays` is
 *   the ordinary case rather than the exotic one, and a store keyed on the name
 *   alone would serve one of them the other's feed.
 *
 * `tests/unit/Backend/SubscriptionBackendContract.php` is the executable form
 * of all of that (R-BE-04), and every implementation that ships here runs it.
 *
 * **The reference implementations are not for production** (R-BE-05).
 */
interface ISubscriptionBackend
{
    /**
     * Every subscription a principal has.
     *
     * @param string $principal The principal's name, as
     *                          {@see IPrincipalBackend} names it
     *
     * @return list<SubscriptionInfo> Empty where the principal has none
     */
    public function subscriptions(string $principal): array;

    /**
     * One subscription by name, or null where the principal has none of that
     * name.
     *
     * @param string $principal Whose subscription it is
     * @param string $name Its member name inside that principal's home
     */
    public function subscription(string $principal, string $name): ?SubscriptionInfo;

    /**
     * Keeps a new subscription.
     *
     * The feed is kept **exactly as it arrived**. A store that normalised the
     * URL — dropped a trailing solidus, turned `webcal:` into `https:` — would
     * hand a client back something it did not subscribe to, and
     * `{http://calendarserver.org/ns/}source` is an href a client compares.
     *
     * @param string $principal Whose subscription it is to be
     * @param SubscriptionInfo $subscription What to keep, its name being the
     *                                       member name it is to have
     *
     * @throws Conflict If that principal already has one of that name
     * @throws Forbidden If the name is not a single path segment
     */
    public function create(string $principal, SubscriptionInfo $subscription): void;

    /**
     * Changes a subscription that is there.
     *
     * **Which is what a `PROPPATCH` of the feed comes to**: a published
     * calendar moves, and a client that had to delete and re-create the
     * subscription would lose everything else set on it.
     *
     * @param string $principal Whose subscription it is
     * @param SubscriptionInfo $subscription What it is to be, its name saying
     *                                       which one is meant
     *
     * @throws NotFound If that principal has none of that name
     * @throws Forbidden If the name is not a single path segment
     */
    public function update(string $principal, SubscriptionInfo $subscription): void;

    /**
     * Forgets a subscription.
     *
     * **What is at the far end of the feed is untouched.** This server never
     * owned it, and unsubscribing is not deleting somebody else's calendar.
     *
     * @param string $principal Whose subscription it is
     * @param string $name Its member name inside that principal's home
     *
     * @throws NotFound If that principal has none of that name
     * @throws Forbidden If the name is not a single path segment
     */
    public function remove(string $principal, string $name): void;
}
