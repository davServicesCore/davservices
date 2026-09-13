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
 * Raised before a `MOVE`, the `beforeMove` extension point of R-ARC-04.
 *
 * A listener refuses by throwing, and the refusal becomes the answer: a lock
 * on either end, a policy that will not have calendars moved out of the
 * account they belong to.
 *
 * A move is raised as a move rather than as a removal and a creation, because
 * the two are not the same thing. What a client moves keeps its identity, and
 * a plugin told it was deleted here and created there would throw away exactly
 * what a move preserves — its dead properties, its locks, its place in
 * somebody's index.
 */
final class BeforeMove extends Event
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
