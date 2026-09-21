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

namespace DavServices\Acl;

use InvalidArgumentException;

/**
 * One privilege, and what it aggregates (RFC 3744 §3).
 *
 * **A privilege is a tree, and the tree is the whole of the meaning.**
 * `DAV:write` is not so much a permission of its own as a name for four
 * others; a server that granted it without granting `DAV:bind` would let a
 * client change a file it may not create. Aggregation is what makes an access
 * control list short enough for a person to write and read.
 *
 * Two things about it matter everywhere else.
 *
 * **It is transitive.** `DAV:all` reaches `DAV:write-content` through
 * `DAV:write`, and nothing that checks access should have to walk that
 * itself — a check that stopped at the first level would grant far less than
 * whoever wrote the list intended.
 *
 * **It can be extended.** `CALDAV:read-free-busy` hangs under `DAV:read`
 * (RFC 4791 §6.1.1), and CalDAV is a layer **above** this one, so this tree
 * cannot name it:
 *
 *     $tree = Privilege::standard()->with('{DAV:}read', new Privilege(CalDav::FREE_BUSY));
 *
 * A tree that could not grow would force every extension's privileges into
 * the core, which is the opposite of what R-ARC-02 asks.
 *
 * The order a tree is walked in is the order `DAV:supported-privilege-set`
 * is written in, so it is fixed rather than left to chance: a privilege
 * before what it aggregates.
 */
final class Privilege
{
    /** @var list<Privilege> */
    private readonly array $aggregates;

    /**
     * @param string $name As `{namespace}localname`
     * @param string $description What this privilege allows, for a person to
     *                            read. RFC 3744 §5.3 requires it, and it
     *                            belongs to the privilege rather than to
     *                            whoever writes the report: an extension that
     *                            brings a privilege brings the sentence that
     *                            explains it, or it brings a hole in a
     *                            required element
     * @param list<Privilege> $aggregates What holding this one also holds
     * @param bool $isAbstract Whether it may **not** be put in an access
     *                         control entry (§5.3), so that only the ones it
     *                         aggregates can be granted
     * @param string $language What language the description is in. The DTD
     *                         requires the attribute, so a server that always
     *                         wrote `en` would be labelling German text as
     *                         English
     */
    public function __construct(
        private readonly string $name,
        private readonly string $description,
        array $aggregates = [],
        private readonly bool $isAbstract = false,
        private readonly string $language = 'en',
    ) {
        $this->aggregates = $aggregates;
    }

    /**
     * The hierarchy of RFC 3744 §3, which is what this server grants before
     * any extension adds to it.
     *
     * `DAV:unlock` is among them although R-ACL-02 does not spell it out:
     * §3.5 gives it, and taking somebody else's lock away is plainly not the
     * same act as writing — a server whose privileges could not say so would
     * have to choose between granting too much and refusing a lock nobody can
     * clear.
     */
    public static function standard(): self
    {
        return new self('{DAV:}all', 'anything this server can be asked to do', [
            new self('{DAV:}read', 'read the content and the properties of this resource'),
            new self('{DAV:}write', 'change this resource, and what is in it if it is a collection', [
                new self('{DAV:}write-content', 'change the content of this resource'),
                new self('{DAV:}write-properties', 'change the properties of this resource'),
                new self('{DAV:}bind', 'create a member in this collection'),
                new self('{DAV:}unbind', 'remove a member from this collection'),
            ]),
            new self('{DAV:}unlock', 'release a write lock somebody else took'),
            new self('{DAV:}read-acl', 'read the access control list of this resource'),
            new self(
                '{DAV:}read-current-user-privilege-set',
                'read which privileges the person asking holds here',
            ),
            new self('{DAV:}write-acl', 'change the access control list of this resource'),
        ]);
    }

    /**
     * What this privilege allows, for a person to read (RFC 3744 §5.3).
     */
    public function description(): string
    {
        return $this->description;
    }

    /**
     * What language that description is in, which the DTD requires to be
     * said.
     */
    public function language(): string
    {
        return $this->language;
    }

    /**
     * May this privilege **not** be put in an access control entry?
     *
     * **Nothing in the standard tree is abstract, and that is a decision.**
     * `DAV:write` is a privilege RFC 3744 §3.2 defines in its own right, and
     * an administrator who means to grant it should be able to write it down
     * rather than list its four. The flag is here for a deployment or an
     * extension that needs one.
     */
    public function isAbstract(): bool
    {
        return $this->isAbstract;
    }

    /**
     * The name this privilege is known by.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * What holding this one also holds, one level down.
     *
     * @return list<Privilege>
     */
    public function aggregates(): array
    {
        return $this->aggregates;
    }

    /**
     * Does holding this privilege hold that one — however deep it sits?
     */
    public function contains(string $name): bool
    {
        return in_array($name, $this->flattened(), true);
    }

    /**
     * This privilege and everything it reaches, itself first.
     *
     * The order is the one `DAV:supported-privilege-set` is written in, and a
     * client reading that is being shown the shape of what this server
     * grants.
     *
     * @return list<string>
     */
    public function flattened(): array
    {
        $names = [$this->name];

        foreach ($this->aggregates as $child) {
            foreach ($child->flattened() as $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * The privilege of that name, anywhere in this tree, or null.
     */
    public function find(string $name): ?self
    {
        if ($this->name === $name) {
            return $this;
        }

        foreach ($this->aggregates as $child) {
            $found = $child->find($name);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * The same tree with one more privilege hung under a parent — which is
     * how an extension brings its own (RFC 4791 §6.1.1).
     *
     * The tree it was made from is left alone, because nothing in this
     * library hands out an object that changes under whoever is holding it.
     *
     * @throws InvalidArgumentException If no privilege of that parent name is
     *                                  in the tree, which is a mistake in the
     *                                  code that hung it rather than
     *                                  something to keep quiet about
     */
    public function with(string $parent, self $privilege): self
    {
        if ($this->find($parent) === null) {
            throw new InvalidArgumentException(sprintf('No privilege "%s" is in this tree.', $parent));
        }

        return $this->hanging($parent, $privilege);
    }

    /**
     * The same privilege, aggregating something else — everything about it
     * but its children kept, because only the children are being changed.
     *
     * @param list<Privilege> $aggregates
     */
    private function holding(array $aggregates): self
    {
        return new self($this->name, $this->description, $aggregates, $this->isAbstract, $this->language);
    }

    private function hanging(string $parent, self $privilege): self
    {
        if ($this->name === $parent) {
            return $this->holding([...$this->aggregates, $privilege]);
        }

        return $this->holding(array_map(
            static fn (self $child): self => $child->hanging($parent, $privilege),
            $this->aggregates,
        ));
    }
}
