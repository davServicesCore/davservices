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
use DavServices\Dav\IResourceType;
use DavServices\Tests\Unit\Dav\MemoryCollection;

/**
 * A collection a calendar can be made of that knows about sharing.
 *
 * It is also an {@see IResourceType} because a store is usually both — it says
 * what it is *and* whom it is shared with — and because the two lists have to
 * be told apart where {@see \DavServices\CalDav\Calendar} puts them together.
 */
final class MemoryShareable extends MemoryCollection implements IResourceType, IShareable
{
    /** @var list<Sharee> */
    private readonly array $sharees;

    /** @var list<string> */
    private readonly array $modes;

    /** @var list<string> */
    private readonly array $kinds;

    /**
     * @param list<Sharee> $sharees Whom this calendar is shared with
     * @param list<string> $modes What §5.2.3 allows on it
     * @param list<string> $kinds What it was before any of this
     */
    public function __construct(string $name, array $sharees = [], array $modes = [], array $kinds = [])
    {
        parent::__construct($name);

        $this->sharees = $sharees;
        $this->modes = $modes;
        $this->kinds = $kinds;
    }

    /**
     * @return list<string>
     */
    public function resourceTypes(): array
    {
        return $this->kinds;
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
