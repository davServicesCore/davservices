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
 * A collection that is a scheduling Outbox (RFC 6638 §2.1).
 *
 * > A scheduling Outbox collection is used as the target for busy time
 * > information requests, and to manage privileges that apply to outgoing
 * > scheduling requests.
 * >
 * > A scheduling Outbox collection MUST report the DAV:collection and
 * > CALDAV:schedule-outbox XML elements in the value of the DAV:resourcetype
 * > property.
 *
 * It wraps a collection rather than being one, and the application is the one
 * that makes it — §2: "It is the server's responsibility to create these
 * collection resources, and clients have no way to create or delete them."
 *
 *     $home->add(Outbox::of($yourCollection));
 *
 * ## Nothing goes inside one
 *
 * > The use of child resources in a scheduling Outbox collection is reserved
 * > for future revisions or extensions of this specification.
 *
 * **That is not a prohibition, and it says something stronger than one.** What
 * a child resource of an Outbox *means* is reserved for somebody else to
 * define, so a server that let a client make one would be letting it create a
 * resource with no agreed meaning — and would then have to live with whatever
 * the next revision decides such a resource is.
 *
 * Which is why both a file and a collection are refused here, where
 * {@see Inbox} refuses only collections: §2.2 names the one and allows the
 * other, because an Inbox is *for* calendar object resources. An Outbox is for
 * none: §5's busy-time request arrives as a `POST` on the collection itself
 * and leaves nothing behind.
 */
class Outbox implements ICollection, IPrivileges, IResourceType
{
    /**
     * What §2.1 requires in `DAV:resourcetype`, beside `DAV:collection`.
     */
    public const OUTBOX = '{urn:ietf:params:xml:ns:caldav}schedule-outbox';

    /**
     * Protected on purpose: {@see of()} is the only way in, because it is the
     * one place that can choose correctly between this class and the one that
     * also keeps properties.
     */
    protected function __construct(private readonly ICollection $collection)
    {
    }

    /**
     * An Outbox over this collection.
     *
     * It keeps properties only where there is a store behind it that does, for
     * the reason {@see OutboxKeepingItsOwn} gives.
     */
    public static function of(ICollection $collection): self
    {
        if ($collection instanceof IProperties) {
            return new OutboxKeepingItsOwn($collection);
        }

        return new self($collection);
    }

    /**
     * The collection underneath.
     *
     * Read by a plugin that was handed a node and needs the store behind it.
     */
    public function collection(): ICollection
    {
        return $this->collection;
    }

    /**
     * An Outbox, and whatever else the collection underneath already was.
     *
     * @return list<string>
     */
    public function resourceTypes(): array
    {
        if (!$this->collection instanceof IResourceType) {
            return [self::OUTBOX];
        }

        return [self::OUTBOX, ...$this->collection->resourceTypes()];
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
     * §2 keeps a *client* from deleting one, which is
     * {@see \DavServices\Plugin\Scheduling}'s to enforce.
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
     * Refuses, because §2.1 reserves what such a resource would mean.
     *
     * @throws Forbidden Always
     */
    public function createFile(string $name, mixed $content = null): ?string
    {
        throw new Forbidden(sprintf(
            'RFC 6638 §2.1 reserves what a child of a scheduling Outbox means, so "%s" was not made.',
            $name,
        ));
    }

    /**
     * Refuses, for the same reason.
     *
     * @throws Forbidden Always
     */
    public function createCollection(string $name): void
    {
        throw new Forbidden(sprintf(
            'RFC 6638 §2.1 reserves what a child of a scheduling Outbox means, so "%s" was not made.',
            $name,
        ));
    }

    /**
     * The four privileges RFC 6638 §6.2 defines for a scheduling Outbox.
     *
     * > These privileges determine which calendar users are allowed to send
     * > scheduling messages on behalf of the calendar user who "owns" the
     * > scheduling Outbox collection. This allows calendar users to choose
     * > other calendar users who can act on their behalf (e.g., assistants
     * > working on behalf of their boss).
     *
     * Said here for the reason {@see Inbox::privilegesDefinedHere()} gives:
     * §6.2 ends with the same sentence about every other kind of resource.
     */
    public function privilegesDefinedHere(Privilege $everywhere): Privilege
    {
        return Privileges::anOutboxDefines($everywhere);
    }
}
