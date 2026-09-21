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

use DavServices\Dav\IResourceType;

/**
 * A collection that is also something else — a principal collection, a
 * calendar — so that the two halves of `DAV:resourcetype` can be told apart.
 */
final class TypedCollection extends MemoryCollection implements IResourceType
{
    /** @var list<string> */
    private readonly array $kinds;

    public function __construct(string $name, string ...$kinds)
    {
        parent::__construct($name);

        $this->kinds = array_values($kinds);
    }

    /**
     * @return list<string>
     */
    public function resourceTypes(): array
    {
        return $this->kinds;
    }
}
