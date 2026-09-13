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
 * Raised before a `COPY`.
 *
 * The same seam as {@see BeforeMove} and for the same reasons, for the
 * operation that leaves the original where it is. A listener refuses by
 * throwing.
 */
final class BeforeCopy extends Event
{
    public function __construct(
        private readonly string $from,
        private readonly string $to,
    ) {
    }

    /**
     * The path it is coming from.
     */
    public function from(): string
    {
        return $this->from;
    }

    /**
     * The path it is going to.
     */
    public function to(): string
    {
        return $this->to;
    }
}
