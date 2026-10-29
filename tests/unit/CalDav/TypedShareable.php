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

namespace DavServices\Tests\Unit\CalDav;

use DavServices\CalDav\Sharee;
use DavServices\Dav\IResourceType;

/**
 * A shareable collection that is also something of its own.
 *
 * The counterpart to {@see MemoryShareable}: here
 * {@see \DavServices\CalDav\Calendar} has two lists to put together rather
 * than one to hand on, and the derived `CS:shared-owner` has to find its place
 * between them.
 */
final class TypedShareable extends MemoryShareable implements IResourceType
{
    /** @var list<string> */
    private readonly array $kinds;

    /**
     * @param list<Sharee> $sharees Whom this calendar is shared with
     * @param list<string> $modes What §5.2.3 allows on it
     */
    public function __construct(string $name, array $sharees = [], array $modes = [], string ...$kinds)
    {
        parent::__construct($name, $sharees, $modes);

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
