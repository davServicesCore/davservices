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

namespace DavServices\CardDav;

use DateTimeImmutable;
use DavServices\Dav\ICollection;
use DavServices\Dav\INode;
use DavServices\Dav\IProperties;
use DavServices\Dav\IResourceType;

/**
 * A collection that is an address book (RFC 6352 §5.2).
 *
 * > "An address book collection MUST report the DAV:collection and
 * > CARDDAV:addressbook XML elements in the value of the DAV:resourcetype
 * > property."
 *
 * **That sentence is the whole of what makes a collection an address book**,
 * and it is why this wraps a collection rather than being one. Where the cards
 * are kept is the application's business — a directory on disc, a table, a
 * directory service — and a library that insisted on storage of its own would
 * be useful only to applications that had none. So an address book here is any
 * collection, said to be one:
 *
 *     $home->add(AddressBook::of($yourCollection));
 *
 * Everything but the resource type is the collection underneath. Answering any
 * of it here would be answering for data this does not hold.
 *
 * ## What may be inside, and what may not
 *
 * §5.2 puts two restrictions on the contents, and the second is the one with
 * teeth:
 *
 * - "Address book collections MUST only contain address object resources and
 *   collections that are **not** address book collections."
 * - "Collections contained in address book collections MUST NOT contain
 *   address book collections **at any depth**."
 *
 * So **a plain collection inside an address book is allowed** — the memo says
 * so in as many words, and refusing it would be stricter than the
 * specification this cites. What is not allowed is an address book, anywhere
 * below one.
 *
 * **This class does the half of that a node can see**, by not being an
 * {@see \DavServices\Dav\IExtendedCollection}: an extended `MKCOL` is how an
 * address book is made (§6.3.1), so a collection that offered it inside an
 * address book would offer exactly what §5.2 forbids. Leaving the interface
 * off makes {@see \DavServices\Dav\Method\MkCol} answer with RFC 5689's own
 * `DAV:valid-resourcetype` and name the property it refused, where
 * implementing it in order to throw would turn that into a bare `403` with
 * nothing in it a client can read.
 *
 * The other half — whether a path sits below an address book at all — is a
 * question about where something is rather than what it is, and a node knows
 * its name and nothing about where it hangs. {@see \DavServices\Plugin\CardDav}
 * answers it.
 */
class AddressBook implements ICollection, IResourceType
{
    /**
     * What §5.2 requires in `DAV:resourcetype`, beside `DAV:collection` —
     * which the server adds for every collection by itself.
     */
    public const ADDRESSBOOK = '{urn:ietf:params:xml:ns:carddav}addressbook';

    /**
     * Protected on purpose: {@see of()} is the only way in, because it is the
     * one place that can choose correctly between this class and the one that
     * also keeps properties.
     */
    protected function __construct(private readonly ICollection $collection)
    {
    }

    /**
     * An address book over this collection.
     *
     * **It hands back something that keeps properties only where there is a
     * store behind it that does**, and that is the one decision here which is
     * easy to get wrong in a way that looks right. {@see DeadProperties}
     * steps aside for *any* node implementing `IProperties` — its own first
     * two lines — because the contract is that such a node keeps all of its
     * own properties. A wrapper that claimed the interface regardless would
     * therefore take dead property storage away from every address book over
     * a store that keeps none, silently, and
     * `CARDDAV:addressbook-description` is exactly such a property: §6.2.1
     * says it "SHOULD NOT be protected so that users can specify a
     * description", so somebody has to be able to keep it.
     */
    public static function of(ICollection $collection): self
    {
        if ($collection instanceof IProperties) {
            return new AddressBookKeepingItsOwn($collection);
        }

        return new self($collection);
    }

    /**
     * The collection the cards are really in.
     *
     * Read by a plugin that was handed a node and needs the store behind it,
     * so that it does not have to unwrap one by hand.
     */
    public function collection(): ICollection
    {
        return $this->collection;
    }

    /**
     * An address book, and whatever else the collection underneath already
     * was.
     *
     * A resource type is a list rather than a label (RFC 4918 §15.9), so a
     * collection that is both an address book and something an application
     * invented says both. Replacing the list would quietly take the other
     * half away.
     *
     * @return list<string>
     */
    public function resourceTypes(): array
    {
        if (!$this->collection instanceof IResourceType) {
            return [self::ADDRESSBOOK];
        }

        return [self::ADDRESSBOOK, ...$this->collection->resourceTypes()];
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
     * Removes the collection the cards are in; being an address book adds no
     * reason to keep it.
     */
    public function delete(): void
    {
        $this->collection->delete();
    }

    /**
     * The cards and collections the store holds.
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
     * Puts a card in, and hands back whatever the store tagged it with.
     *
     * The tag is passed on rather than swallowed: without it every write
     * looks untagged and sends a client into a `GET` it did not need.
     */
    public function createFile(string $name, mixed $content = null): ?string
    {
        return $this->collection->createFile($name, $content);
    }

    /**
     * A plain collection, which §5.2 allows inside an address book.
     */
    public function createCollection(string $name): void
    {
        $this->collection->createCollection($name);
    }
}
