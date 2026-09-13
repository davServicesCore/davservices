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

/**
 * A node that is neither a file nor a collection.
 *
 * The interfaces allow it — `INode` is the whole contract — and a backend may
 * well have one: something addressable that holds neither content nor members.
 * There is no making a second one of it, and the tests use it to say so.
 */
final class PlainNode implements IMember
{
    private ?MemoryCollection $parent = null;

    public function __construct(private readonly string $name)
    {
    }

    public function attachTo(MemoryCollection $parent): void
    {
        $this->parent = $parent;
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
        $this->parent?->remove($this->name);
    }
}
