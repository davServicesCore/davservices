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
 * Raised once a `COPY` has happened.
 *
 * RFC 4918 §9.8.2 has the dead properties of the source duplicated onto the
 * copy, and this is where that is done. A copy that arrived without the colour
 * and the name of what it was copied from is not a copy in any sense a client
 * would recognise.
 */
final class AfterCopy extends Event
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
