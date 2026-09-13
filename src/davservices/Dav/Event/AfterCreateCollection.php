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
 * Raised once a collection has been created.
 *
 * What a plugin does here is bookkeeping of its own: an entry in an index, a
 * synchronisation token to move on, a default set of properties to write. The
 * collection is there, and nothing said here can undo it — a listener that
 * wants to prevent one listens for {@see BeforeCreateCollection} instead.
 */
final class AfterCreateCollection extends Event
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * The path that was created.
     */
    public function path(): string
    {
        return $this->path;
    }
}
