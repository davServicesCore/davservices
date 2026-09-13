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

namespace DavServices\Dav\Event;

use DavServices\Event\Event;

/**
 * Raised once a member has appeared at a path, whatever put it there — the
 * `afterBind` extension point of R-ARC-04.
 *
 * The counterpart of {@see AfterUnbind}: between the two, a plugin that keeps
 * its own record of what exists hears about every change to the shape of the
 * tree without knowing which method made it.
 */
final class AfterBind extends Event
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * The path a member has appeared at.
     */
    public function path(): string
    {
        return $this->path;
    }
}
