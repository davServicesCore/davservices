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

use DateTimeImmutable;
use DavServices\Acl\IPrivileges;
use DavServices\Acl\Privilege;
use DavServices\Dav\ICollection;
use DavServices\Dav\INode;
use DavServices\Dav\IProperties;
use DavServices\Dav\IResourceType;
use DavServices\Exception\Forbidden;

/**
 * A collection that is a scheduling Inbox (RFC 6638 §2.2).
 *
 * > A scheduling Inbox collection contains copies of incoming scheduling
 * > messages. These can be requests sent by an "Organizer", or replies sent by
 * > an "Attendee" in response to a request. The scheduling Inbox collection is
 * > also used to manage scheduling privileges.
 * >
 * > A scheduling Inbox collection MUST report the DAV:collection and
 * > CALDAV:schedule-inbox XML elements in the value of the DAV:resourcetype
 * > property.
 *
 * **It wraps a collection rather than being one**, for the reason
 * {@see \DavServices\CalDav\Calendar} gives: where the messages are kept is
 * the application's business, and a library that insisted on storage of its
 * own would be useful only to applications that had none.
 *
 *     $home->add(Inbox::of($yourCollection));
 *
 * **And the application is the one that makes it.** §2: "It is the server's
 * responsibility to create these collection resources, and clients have no way
 * to create or delete them." Nothing here creates one, and
 * {@see \DavServices\Plugin\Scheduling} is what keeps a client from doing so.
 *
 * ## One MUST NOT is this class's to keep
 *
 * > Scheduling Inbox collections MUST only contain calendar object resources
 * > that obey the restrictions specified in iTIP [RFC5546]. Consequently,
 * > scheduling Inbox collections MUST NOT contain any types of collection
 * > resources.
 *
 * A collection inside an Inbox is refused here rather than left to the store,
 * because the memo forbids it outright and a store that allowed one would be
 * breaking a MUST this library could see coming. **Files are a different
 * matter**: an Inbox is *for* calendar object resources, so those go to the
 * store like anywhere else — and whether a message obeys iTIP is a question
 * about iCalendar data, which {@see \DavServices\Plugin\CalDav} asks of
 * everything written into a calendar.
 *
 * **What is not restricted here** is the other half of §2.2: "Restrictions
 * defined in Section 4.1 of CalDAV 'calendar-access' on calendar object
 * resources contained in calendar collections (e.g., Unique Identifier ('UID')
 * uniqueness) do not apply to calendar object resources contained in a
 * scheduling Inbox collection." That rule lives where the uniqueness check
 * does, which is not here: an Inbox is no calendar collection, so the check
 * never looks at one.
 */
class Inbox implements ICollection, IPrivileges, IResourceType
{
    /**
     * What §2.2 requires in `DAV:resourcetype`, beside `DAV:collection` —
     * which the server adds for every collection by itself.
     */
    public const INBOX = '{urn:ietf:params:xml:ns:caldav}schedule-inbox';

    /**
     * Protected on purpose: {@see of()} is the only way in, because it is the
     * one place that can choose correctly between this class and the one that
     * also keeps properties.
     */
    protected function __construct(private readonly ICollection $collection)
    {
    }

    /**
     * An Inbox over this collection.
     *
     * **It hands back something that keeps properties only where there is a
     * store behind it that does.** §2.2 lists eight properties of RFC 4791
     * that "MAY also be defined on scheduling Inbox collections", and
     * {@see \DavServices\Plugin\DeadProperties} steps aside for *any* node
     * implementing `IProperties` — so a wrapper that claimed the interface
     * regardless would take dead property storage away from every Inbox over a
     * store that keeps none.
     */
    public static function of(ICollection $collection): self
    {
        if ($collection instanceof IProperties) {
            return new InboxKeepingItsOwn($collection);
        }

        return new self($collection);
    }

    /**
     * The collection the messages are really in.
     *
     * Read by a plugin that was handed a node and needs the store behind it,
     * so that it does not have to unwrap one by hand.
     */
    public function collection(): ICollection
    {
        return $this->collection;
    }

    /**
     * An Inbox, and whatever else the collection underneath already was.
     *
     * A resource type is a list rather than a label (RFC 4918 §15.9), so
     * replacing it would quietly take the other half away.
     *
     * @return list<string>
     */
    public function resourceTypes(): array
    {
        if (!$this->collection instanceof IResourceType) {
            return [self::INBOX];
        }

        return [self::INBOX, ...$this->collection->resourceTypes()];
    }

    /**
     * The name the store gave it, which is the last segment of its URL.
     */
    public function name(): string
    {
        return $this->collection->name();
    }

    /**
     * When the store says it last changed, where it says at all.
     */
    public function lastModified(): ?DateTimeImmutable
    {
        return $this->collection->lastModified();
    }

    /**
     * Deletes the collection underneath, if the store will.
     *
     * §2 keeps a *client* from deleting one, and that is
     * {@see \DavServices\Plugin\Scheduling}'s to enforce — a deployment taking
     * a user away takes their Inbox with them, and nothing here should stand
     * in the way of that.
     */
    public function delete(): void
    {
        $this->collection->delete();
    }

    /**
     * What is in it, which is whatever the store has.
     *
     * @return list<INode>
     */
    public function children(): array
    {
        return $this->collection->children();
    }

    /**
     * One member of it, or the store's own `NotFound` where there is none.
     */
    public function child(string $name): INode
    {
        return $this->collection->child($name);
    }

    /**
     * Whether the store has a member of that name.
     */
    public function hasChild(string $name): bool
    {
        return $this->collection->hasChild($name);
    }

    /**
     * Writes a scheduling message, which is what an Inbox is for.
     *
     * The entity tag is passed on rather than swallowed: without it every
     * write looks untagged and sends a client into a `GET` it did not need.
     */
    public function createFile(string $name, mixed $content = null): ?string
    {
        return $this->collection->createFile($name, $content);
    }

    /**
     * Refuses, because §2.2 does.
     *
     * > scheduling Inbox collections MUST NOT contain any types of collection
     * > resources.
     *
     * The store is not asked at all. RFC 4918 §9.3.1 gives `403` for a
     * `MKCOL` where "the server does not allow the creation of collections at
     * the given location", which is this case in that memo's own words.
     *
     * @throws Forbidden Always
     */
    public function createCollection(string $name): void
    {
        throw new Forbidden(sprintf(
            'RFC 6638 §2.2: a scheduling Inbox may contain no collection, so "%s" was not made.',
            $name,
        ));
    }

    /**
     * The four privileges RFC 6638 §6.1 defines for a scheduling Inbox.
     *
     * > These privileges determine whether delivery of scheduling messages
     * > from a calendar user is allowed by the calendar user who "owns" the
     * > scheduling Inbox collection. This allows calendar users to choose
     * > which other calendar users can schedule with them.
     *
     * **It is this collection that says so**, and not whoever reports the
     * property, because §6.1 closes with "The privileges defined in this
     * section are ignored if applied to a resource other than a scheduling
     * Inbox collection" — and a right that would be disregarded is not, in
     * RFC 3744 §5.3's words, among "the privileges defined for the resource".
     */
    public function privilegesDefinedHere(Privilege $everywhere): Privilege
    {
        return Privileges::anInboxDefines($everywhere);
    }
}
