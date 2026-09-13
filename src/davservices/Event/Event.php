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

namespace DavServices\Event;

/**
 * What every event of this library is built on.
 *
 * An event is an object rather than an array or a string with a payload
 * (R-ARC-03): its properties are named and typed, a listener can be given the
 * class it listens for, and adding something to an event later is a change a
 * static analyser can follow through every plugin.
 *
 * Subclasses carry whatever the occasion is about — a request, a node, a path
 * — and they may let a listener change it. That is the point: an event is how
 * a plugin takes part in an operation, not merely how it is told of one.
 */
abstract class Event implements IEvent
{
    private bool $stopped = false;

    /**
     * Declares the matter settled: no listener behind this one runs.
     */
    public function stop(): void
    {
        $this->stopped = true;
    }

    /**
     * Has a listener declared the matter settled?
     */
    public function isStopped(): bool
    {
        return $this->stopped;
    }
}
