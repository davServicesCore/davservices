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
use DavServices\Exception\NotFound;

/**
 * A collection that lives in an array, for the tests of this layer.
 *
 * It counts how often it has been asked for a member, which is how the tests
 * tell a cached lookup from one that went to the backend.
 */
final class MemoryCollection implements ICollection
{
    /** How often `child()` has gone looking, cache or no cache. */
    public int $lookups = 0;

    /** Whether this collection can say what it just created is tagged as. */
    private bool $tellsItsEtag = false;

    /** @var array<string, INode> */
    private array $members = [];

    public function __construct(private readonly string $name)
    {
    }

    public function add(INode $node): self
    {
        $this->members[$node->name()] = $node;

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
        $this->members = [];
    }

    public function children(): array
    {
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

        $this->members[$name] = $file;

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

    public function createCollection(string $name): void
    {
        if (isset($this->members[$name])) {
            throw new Conflict(sprintf('"%s" is already there.', $name));
        }

        $this->members[$name] = new self($name);
    }
}
