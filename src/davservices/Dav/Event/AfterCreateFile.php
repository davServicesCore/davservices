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
 * Raised once a file has been created.
 *
 * What a plugin does here is bookkeeping of its own: an index to update, a
 * notification to queue, a synchronisation token to move on. The write has
 * happened, and nothing said here can undo it.
 */
final class AfterCreateFile extends Event
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * The path that was written.
     */
    public function path(): string
    {
        return $this->path;
    }
}
