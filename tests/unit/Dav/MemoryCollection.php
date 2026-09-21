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

namespace DavServices\Tests\Unit\Dav;

use DateTimeImmutable;
use DavServices\Dav\ICollection;
use DavServices\Dav\INode;
use DavServices\Exception\Conflict;
use DavServices\Exception\Forbidden;
use DavServices\Exception\IHttpFailure;
use DavServices\Exception\NotFound;

/**
 * A collection that lives in an array, for the tests of this layer.
 *
 * Not final, because the extended collection of RFC 5689 is the same thing
 * with one more way of creating a member; see {@see MemoryExtendedCollection}.
 *
 * It counts how often it has been asked for a member, which is how the tests
 * tell a cached lookup from one that went to the backend. It can also be told
 * to refuse — a backend that will not list what it holds, or will not let it
 * go, is the case the interesting answers are made of.
 */
class MemoryCollection implements ICollection, IMember
{
    /** How often `child()` has gone looking, cache or no cache. */
    public int $lookups = 0;

    /** Whether this collection can say what it just created is tagged as. */
    private bool $tellsItsEtag = false;

    /** Whether it answers a request for a collection with something else. */
    private bool $makesSomethingElse = false;

    /** Set where listing the members is to be refused. */
    private ?IHttpFailure $listingRefusal = null;

    /** Set where being deleted is to be refused. */
    private ?IHttpFailure $deletionRefusal = null;

    private ?MemoryCollection $parent = null;

    /** @var array<string, INode> */
    private array $members = [];

    public function __construct(private readonly string $name)
    {
    }

    /**
     * Takes any node, not only one of this test's own: a real collection out
     * of the library — a principal collection, later a calendar home — has to
     * be mountable in a tree a test put together, or nothing above it could
     * be tried at all. Only a member that wants to know its parent is told.
     */
    public function add(INode $node): self
    {
        if ($node instanceof IMember) {
            $node->attachTo($this);
        }

        $this->members[$node->name()] = $node;

        return $this;
    }

    public function attachTo(MemoryCollection $parent): void
    {
        $this->parent = $parent;
    }

    /**
     * Drops a member, which is what a node's own `delete()` comes back to.
     */
    public function remove(string $name): void
    {
        unset($this->members[$name]);
    }

    /**
     * Refuses to say what it holds.
     */
    public function refuseListing(?IHttpFailure $refusal = null): self
    {
        $this->listingRefusal = $refusal ?? new Forbidden(sprintf('"%s" is not to be listed.', $this->name));

        return $this;
    }

    /**
     * Refuses to go away.
     */
    public function refuseDeletion(?IHttpFailure $refusal = null): self
    {
        $this->deletionRefusal = $refusal ?? new Forbidden(sprintf('"%s" is not to be deleted.', $this->name));

        return $this;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function lastModified(): ?DateTimeImmutable
    {
        return null;
    }

    public function delete(): void
    {
        if ($this->deletionRefusal !== null) {
            throw $this->deletionRefusal;
        }

        $this->members = [];
        $this->parent?->remove($this->name);
    }

    public function children(): array
    {
        if ($this->listingRefusal !== null) {
            throw $this->listingRefusal;
        }

        return array_values($this->members);
    }

    public function child(string $name): INode
    {
        $this->lookups++;

        return $this->members[$name] ?? throw new NotFound(sprintf('No member "%s" here.', $name));
    }

    public function hasChild(string $name): bool
    {
        return isset($this->members[$name]);
    }

    public function createFile(string $name, mixed $content = null): ?string
    {
        if (isset($this->members[$name])) {
            throw new Conflict(sprintf('"%s" is already there.', $name));
        }

        $file = new MemoryFile($name);

        if ($content !== null) {
            $file->put($content);
        }

        $this->add($file);

        return $this->tellsItsEtag ? $file->etag() : null;
    }

    /**
     * Whether this collection reports the entity tag of what it created.
     *
     * A backend that can say saves the server a second question; one that
     * cannot is the ordinary case too, and both have to be answered.
     */
    public function tellsItsEtag(bool $tells = true): self
    {
        $this->tellsItsEtag = $tells;

        return $this;
    }

    /**
     * Answers `createCollection()` with something that is no collection, as a
     * backend does that has misunderstood its own contract.
     */
    public function makeSomethingElse(): self
    {
        $this->makesSomethingElse = true;

        return $this;
    }

    public function createCollection(string $name): void
    {
        if (isset($this->members[$name])) {
            throw new Conflict(sprintf('"%s" is already there.', $name));
        }

        $this->add($this->makesSomethingElse ? new MemoryFile($name) : new self($name));
    }
}
