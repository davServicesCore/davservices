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
 * Raised once a node has been removed, and only for one that really went.
 *
 * What a plugin does here is its own bookkeeping: the dead properties it kept
 * for that path, the locks rooted on it, an index entry, a synchronisation
 * token to move on. The removal has happened, and nothing said here can undo
 * it — which is why a listener that wants to prevent one listens for
 * {@see BeforeUnbind} instead.
 */
final class AfterUnbind extends Event
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * The path that was removed.
     */
    public function path(): string
    {
        return $this->path;
    }
}
