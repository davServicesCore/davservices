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
 * Raised once a `MOVE` has happened, the `afterMove` extension point of
 * R-ARC-04.
 *
 * This is where what was kept about the old path is carried to the new one:
 * the dead properties of RFC 4918 §9.9.2, an entry in an index, a
 * synchronisation token. The move has happened, and nothing said here can undo
 * it.
 */
final class AfterMove extends Event
{
    public function __construct(
        private readonly string $from,
        private readonly string $to,
    ) {
    }

    /**
     * The path it was coming from.
     */
    public function from(): string
    {
        return $this->from;
    }

    /**
     * The path it was going to.
     */
    public function to(): string
    {
        return $this->to;
    }
}
