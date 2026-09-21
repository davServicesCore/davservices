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
use DavServices\Dav\INode;
use DavServices\Dav\IResourceType;

/**
 * A node that says what kind of resource it is.
 *
 * The smallest thing that can stand in for a principal, a calendar or an
 * address book while the property that reports them is being tested.
 */
final class TypedNode implements INode, IResourceType
{
    /** @var list<string> */
    private readonly array $kinds;

    public function __construct(private readonly string $name, string ...$kinds)
    {
        $this->kinds = array_values($kinds);
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
    }

    /**
     * @return list<string>
     */
    public function resourceTypes(): array
    {
        return $this->kinds;
    }
}
