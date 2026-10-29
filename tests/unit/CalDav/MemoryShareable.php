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

use DavServices\CalDav\IShareable;
use DavServices\CalDav\Sharee;
use DavServices\Tests\Unit\Dav\MemoryCollection;

/**
 * A collection a calendar can be made of that knows about sharing.
 *
 * **And nothing else about itself**, which is the ordinary shape: a store that
 * keeps events has no resource type of its own to declare, and
 * {@see \DavServices\CalDav\Calendar} takes a shorter way through for one.
 * {@see TypedShareable} is the other shape, and the two are kept apart because
 * a mutation run on 29.10.2026 showed that shorter way able to return the
 * first of its list rather than the list — which is where a derived
 * `CS:shared-owner` would go missing.
 */
class MemoryShareable extends MemoryCollection implements IShareable
{
    /** @var list<Sharee> */
    private readonly array $sharees;

    /** @var list<string> */
    private readonly array $modes;

    /**
     * @param list<Sharee> $sharees Whom this calendar is shared with
     * @param list<string> $modes What §5.2.3 allows on it
     */
    public function __construct(string $name, array $sharees = [], array $modes = [])
    {
        parent::__construct($name);

        $this->sharees = $sharees;
        $this->modes = $modes;
    }

    /**
     * @return list<string>
     */
    public function allowedSharingModes(): array
    {
        return $this->modes;
    }

    /**
     * @return list<Sharee>
     */
    public function sharees(): array
    {
        return $this->sharees;
    }
}
