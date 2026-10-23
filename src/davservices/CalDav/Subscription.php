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

use DateTimeImmutable;
use DavServices\Dav\ICollection;
use DavServices\Dav\INode;
use DavServices\Dav\IProperties;
use DavServices\Dav\IResourceType;

/**
 * A collection that is a subscription to somebody else's calendar (R-CAL-04).
 *
 * **A subscription is a calendar a user reads and does not own**: the public
 * holidays, a team's shared feed, a sports fixture list. The server keeps the
 * subscription so that every one of a user's devices sees the same set rather
 * than each being told separately, which is the whole reason it is a collection
 * on the server at all.
 *
 *     $home->add(Subscription::of($yourCollection));
 *
 * ## There is no specification, and that was checked rather than assumed
 *
 * **No RFC defines this**, and neither does any of Apple's own extension
 * documents, although the namespace is theirs:
 * `apple/ccs-calendarserver/doc/Extensions/` holds eleven — `caldav-ctag`,
 * `caldav-notifications`, `caldav-privatecomments`, `caldav-privateevents`,
 * `caldav-proxy`, `caldav-pubsubdiscovery`, `caldav-recursplit`,
 * `caldav-schedulingchanges`, `caldav-sharing`, `calendarserver-bulk-change`
 * and `icalendar-maskuids` — and not one is about subscriptions.
 *
 * **The one attempt at a specification lapsed.**
 * `draft-ietf-calext-serverside-subscriptions-02` (7 March 2022, intended
 * Standards Track) expired on 8 September 2022 without becoming an RFC, and it
 * names the elements differently — `DAV:subscription` in the resource type,
 * `DAV:subscription-href` for the URL. Nothing speaks it. Following it would
 * mean conforming to a document that expired in favour of a convention that
 * did not, and answering elements no client asks for.
 *
 * **So the convention decides, and it is written down here** because it is
 * written down nowhere else. Two observations carry all of it:
 *
 * - OS X creates a subscription with an extended `MKCOL` (10.7, 10.8) or a
 *   `MKCALENDAR` (10.9.2 onwards) whose `DAV:resourcetype` holds
 *   `DAV:collection` **and** `{http://calendarserver.org/ns/}subscribed`, with
 *   `{http://calendarserver.org/ns/}source` carrying a `DAV:href` to the feed.
 * - sabre/dav maps its subscriptions to that same resource type and reads
 *   `…}source` as an href.
 *
 * That the requirement spells it `CS:subscribed` with `CS:source` is therefore
 * not an arbitrary choice: it is the only spelling anything speaks.
 *
 * ## It is not a calendar collection
 *
 * The resource type says so by not holding `CALDAV:calendar`, and that is the
 * point of R-CAL-04's "own collection type". RFC 4791 §4.2 makes that element
 * the one that says a collection holds calendar object resources, and §7.8 to
 * §7.10 have the three reports search them. A collection claiming both would
 * invite a client to run a `calendar-query` over objects that are somebody
 * else's and live at the far end of a URL.
 *
 * ## What it holds, and who may write it
 *
 * Everything but the resource type is the collection underneath, as with
 * {@see Calendar}: whether a subscription's objects are cached on this server
 * and where is the application's business. **A write therefore goes through
 * rather than being refused here** — a server that caches a feed has to write
 * the fetched objects somewhere, and that somewhere is this collection, so a
 * wrapper that refused every write would make a cached subscription impossible
 * to build. Whether a *client* may write is a question about access rather than
 * about what the collection is, and {@see \DavServices\Plugin\Acl} answers it
 * where it answers every other write.
 *
 * **And nothing here fetches the feed.** This library has no outbound HTTP
 * client and no runtime dependencies (R-ARC-01), so what fills a subscription
 * is a store's business — {@see \DavServices\Backend\ISubscriptionBackend} is
 * the cut it is asked across. A library that reached out to a URL a client
 * named would also be a library that could be asked to reach inside the
 * network it runs in.
 *
 * ## `…}source` is kept, not computed
 *
 * Nothing about the feed's URL can be worked out from this server's state — a
 * user typed it — so it is a property the store keeps, like
 * `CALDAV:calendar-description` (§5.2.1: a value "suitable for presentation to
 * a user"). That is also why {@see of()} is careful about `IProperties`: a
 * subscription with nowhere to keep that property no longer says what it is
 * subscribed to.
 */
class Subscription implements ICollection, IResourceType
{
    /**
     * What makes a collection a subscription, beside `DAV:collection` — which
     * the server adds for every collection by itself.
     */
    public const SUBSCRIBED = '{http://calendarserver.org/ns/}subscribed';

    /**
     * The property holding a `DAV:href` to the feed.
     *
     * Named here because the name is the whole of the agreement between this
     * server and a client, and a server that spelled it differently would keep
     * a collection no client could follow.
     */
    public const SOURCE = '{http://calendarserver.org/ns/}source';

    /**
     * Protected on purpose: {@see of()} is the only way in, because it is the
     * one place that can choose correctly between this class and the one that
     * also keeps properties.
     */
    protected function __construct(private readonly ICollection $collection)
    {
    }

    /**
     * A subscription over this collection.
     *
     * **It hands back something that keeps properties only where there is a
     * store behind it that does.** {@see \DavServices\Plugin\DeadProperties}
     * steps aside for *any* node implementing `IProperties` — its own first two
     * lines — because the contract is that such a node keeps all of its own
     * properties. A wrapper that claimed the interface regardless would
     * therefore take dead property storage away from every subscription over a
     * store that keeps none, silently, and {@see self::SOURCE} is exactly such
     * a property.
     */
    public static function of(ICollection $collection): self
    {
        if ($collection instanceof IProperties) {
            return new SubscriptionKeepingItsOwn($collection);
        }

        return new self($collection);
    }

    /**
     * The collection the cached objects are really in.
     *
     * Read by a plugin that was handed a node and needs the store behind it,
     * so that it does not have to unwrap one by hand.
     */
    public function collection(): ICollection
    {
        return $this->collection;
    }

    /**
     * A subscription, and whatever else the collection underneath already was.
     *
     * A resource type is a list rather than a label (RFC 4918 §15.9), so a
     * collection that is both a subscription and something an application
     * invented says both. Replacing the list would quietly take the other half
     * away.
     *
     * @return list<string>
     */
    public function resourceTypes(): array
    {
        if (!$this->collection instanceof IResourceType) {
            return [self::SUBSCRIBED];
        }

        return [self::SUBSCRIBED, ...$this->collection->resourceTypes()];
    }

    /**
     * The name the store gave it, which is the last segment of its URL.
     */
    public function name(): string
    {
        return $this->collection->name();
    }

    /**
     * When the store last saw it change, or null where it does not keep that.
     */
    public function lastModified(): ?DateTimeImmutable
    {
        return $this->collection->lastModified();
    }

    /**
     * Removes the collection the cached objects are in; being a subscription
     * adds no reason to keep it.
     *
     * **What is at the far end of the feed is untouched.** This server never
     * owned it, and unsubscribing is not deleting somebody else's calendar.
     */
    public function delete(): void
    {
        $this->collection->delete();
    }

    /**
     * The cached objects and collections the store holds.
     *
     * @return list<INode>
     */
    public function children(): array
    {
        return $this->collection->children();
    }

    /**
     * One member by name, as the store raised it: a member that is not there
     * is the store's `NotFound` rather than something made up here.
     */
    public function child(string $name): INode
    {
        return $this->collection->child($name);
    }

    /**
     * Whether the store holds a member of that name.
     */
    public function hasChild(string $name): bool
    {
        return $this->collection->hasChild($name);
    }

    /**
     * Puts an object in, and hands back whatever the store tagged it with.
     *
     * The tag is passed on rather than swallowed: without it every write looks
     * untagged and sends a client into a `GET` it did not need.
     */
    public function createFile(string $name, mixed $content = null): ?string
    {
        return $this->collection->createFile($name, $content);
    }

    /**
     * Makes a plain collection inside it, if the store will.
     *
     * Nothing in the convention provides for one, and nothing in it forbids one
     * either — so this is the store's decision, as it is for a calendar, and a
     * wrapper that refused would be stricter than anything it could cite.
     */
    public function createCollection(string $name): void
    {
        $this->collection->createCollection($name);
    }
}
