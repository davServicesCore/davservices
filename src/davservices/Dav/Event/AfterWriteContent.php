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
 * Raised once the content of a file has been replaced.
 *
 * What a plugin does here is bookkeeping of its own: an index to update, a
 * notification to queue, a synchronisation token to move on. The write has
 * happened, and nothing said here can undo it.
 */
final class AfterWriteContent extends Event
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
