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
 * Raised before a node is removed, and once for every node that is to go.
 *
 * A listener refuses by throwing, and the refusal is reported exactly as the
 * backend's own would be: that node stays, and so does everything above it.
 * This is the seam the lock plugin of P3 keeps a locked member from going
 * through, and where an application refuses a removal of its own accord.
 *
 * Once per node rather than once per request, because a listener told only
 * about the collection learns nothing about the one file inside it that may
 * not go — and a `DELETE` of a hundred members is a hundred separate
 * questions of whether that member may go.
 */
final class BeforeUnbind extends Event
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * The path that is about to be removed.
     */
    public function path(): string
    {
        return $this->path;
    }
}
