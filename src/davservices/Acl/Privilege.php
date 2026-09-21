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
     * @param list<Privilege> $aggregates What holding this one also holds
     */
    public function __construct(private readonly string $name, array $aggregates = [])
    {
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
        return new self('{DAV:}all', [
            new self('{DAV:}read'),
            new self('{DAV:}write', [
                new self('{DAV:}write-content'),
                new self('{DAV:}write-properties'),
                new self('{DAV:}bind'),
                new self('{DAV:}unbind'),
            ]),
            new self('{DAV:}unlock'),
            new self('{DAV:}read-acl'),
            new self('{DAV:}read-current-user-privilege-set'),
            new self('{DAV:}write-acl'),
        ]);
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

    private function hanging(string $parent, self $privilege): self
    {
        if ($this->name === $parent) {
            return new self($this->name, [...$this->aggregates, $privilege]);
        }

        return new self($this->name, array_map(
            static fn (self $child): self => $child->hanging($parent, $privilege),
            $this->aggregates,
        ));
    }
}
