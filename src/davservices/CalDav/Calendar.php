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
 * A collection that is a calendar (RFC 4791 §4.2).
 *
 * > "A calendar collection MUST report the DAV:collection and CALDAV:calendar
 * > XML elements in the value of the DAV:resourcetype property."
 *
 * **That sentence is the whole of what makes a collection a calendar**, and it
 * is why this wraps a collection rather than being one. Where the events are
 * kept is the application's business — a directory on disc, a table, a
 * groupware store — and a library that insisted on storage of its own would be
 * useful only to applications that had none. So a calendar here is any
 * collection, said to be one:
 *
 *     $home->add(Calendar::of($yourCollection));
 *
 * Everything but the resource type is the collection underneath. Answering any
 * of it here would be answering for data this does not hold.
 *
 * ## What may be inside, and what may not
 *
 * §4.2 puts two restrictions on the contents, and the second is the one with
 * teeth:
 *
 * - "Calendar collections MUST only contain calendar object resources and
 *   collections that are **not** calendar collections, i.e., the only
 *   'top-level' non-collection resources allowed in a calendar collection are
 *   calendar object resources."
 * - "Collections contained in calendar collections MUST NOT contain calendar
 *   collections **at any depth**."
 *
 * So **a plain collection inside a calendar is allowed** — the memo says so in
 * as many words, and refusing it would be stricter than the specification this
 * cites. What is not allowed is a calendar, anywhere below one.
 *
 * **This class does the half of that a node can see**, by not being an
 * {@see \DavServices\Dav\IExtendedCollection}: an extended `MKCOL` is one of
 * the two ways a calendar is made, so a collection that offered it inside a
 * calendar would offer exactly what §4.2 forbids. Leaving the interface off
 * makes {@see \DavServices\Dav\Method\MkCol} answer with RFC 5689's own
 * `DAV:valid-resourcetype` and name the property it refused, where
 * implementing it in order to throw would turn that into a bare `403` with
 * nothing in it a client can read.
 *
 * The other half — whether a path sits below a calendar at all, which is what
 * `MKCALENDAR` has to ask — is a question about where something is rather than
 * what it is, and a node knows its name and nothing about where it hangs.
 * {@see \DavServices\Plugin\CalDav} answers it.
 */
class Calendar implements ICollection, IResourceType
{
    /**
     * What §4.2 requires in `DAV:resourcetype`, beside `DAV:collection` —
     * which the server adds for every collection by itself.
     */
    public const CALENDAR = '{urn:ietf:params:xml:ns:caldav}calendar';

    /**
     * What §5.2.1 of `caldav-sharing` adds once a calendar has a sharee
     * (R-CAL-20).
     *
     * > Calendar collections that are shared have addition elements listed in
     * > their DAV:resourcetype property in addition to DAV:collection and
     * > CALDAV:calendar.
     * >
     * > o CS:shared-owner (Section 6.1): used to indicate that the calendar is
     * > owned by the current user and is being shared by them.
     *
     * **It is derived from {@see IShareable::sharees()}** rather than asked of
     * the store, which is how §5.3.1's MUST is kept without anything having to
     * remember it: "The server MUST update the DAV:resourcetype property on
     * the calendar collection to ensure it contains a CS:shared-owner XML
     * element", and the same sentence again, inverted, for the last sharee
     * going away. Two facts about one thing can drift apart, and a client
     * reading a calendar that says it is private over a list of people it was
     * shared with has no way to tell which half is true.
     *
     * A calendar over a store that is no {@see IShareable} therefore never
     * carries it, and neither does one whose sharee list is empty.
     */
    public const SHARED_OWNER = '{http://calendarserver.org/ns/}shared-owner';

    /**
     * Protected on purpose: {@see of()} is the only way in, because it is the
     * one place that can choose correctly between this class and the one that
     * also keeps properties.
     */
    protected function __construct(private readonly ICollection $collection)
    {
    }

    /**
     * A calendar over this collection.
     *
     * **It hands back something that keeps properties only where there is a
     * store behind it that does.** {@see \DavServices\Plugin\DeadProperties}
     * steps aside for *any* node implementing `IProperties` — its own first
     * two lines — because the contract is that such a node keeps all of its
     * own properties. A wrapper that claimed the interface regardless would
     * therefore take dead property storage away from every calendar over a
     * store that keeps none, silently, and `CALDAV:calendar-description` is
     * exactly such a property: §5.2.1 describes it as one a user provides, so
     * somebody has to be able to keep it.
     */
    public static function of(ICollection $collection): self
    {
        if ($collection instanceof IProperties) {
            return new CalendarKeepingItsOwn($collection);
        }

        return new self($collection);
    }

    /**
     * The collection the events are really in.
     *
     * Read by a plugin that was handed a node and needs the store behind it,
     * so that it does not have to unwrap one by hand.
     */
    public function collection(): ICollection
    {
        return $this->collection;
    }

    /**
     * A calendar, whether it is shared, and whatever else the collection
     * underneath already was.
     *
     * A resource type is a list rather than a label (RFC 4918 §15.9), so a
     * collection that is both a calendar and something an application invented
     * says both. Replacing the list would quietly take the other half away.
     *
     * **`CS:shared-owner` is worked out rather than stored**, for the reason
     * {@see self::SHARED_OWNER} gives.
     *
     * @return list<string>
     */
    public function resourceTypes(): array
    {
        $types = [self::CALENDAR];

        if ($this->collection instanceof IShareable && $this->collection->sharees() !== []) {
            $types[] = self::SHARED_OWNER;
        }

        if (!$this->collection instanceof IResourceType) {
            return $types;
        }

        return [...$types, ...$this->collection->resourceTypes()];
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
     * Removes the collection the events are in; being a calendar adds no
     * reason to keep it.
     */
    public function delete(): void
    {
        $this->collection->delete();
    }

    /**
     * The calendar objects and collections the store holds.
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
     * Puts a calendar object in, and hands back whatever the store tagged it
     * with.
     *
     * The tag is passed on rather than swallowed: without it every write looks
     * untagged and sends a client into a `GET` it did not need.
     */
    public function createFile(string $name, mixed $content = null): ?string
    {
        return $this->collection->createFile($name, $content);
    }

    /**
     * A plain collection, which §4.2(a) allows inside a calendar.
     */
    public function createCollection(string $name): void
    {
        $this->collection->createCollection($name);
    }
}
