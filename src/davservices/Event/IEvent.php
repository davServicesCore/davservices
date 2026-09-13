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
 * What the emitter needs of an event: whether the chain is to go on.
 *
 * The event is the result object of R-ARC-03. A listener that has dealt with
 * the matter stops it, and nothing registered behind that listener runs — this
 * is how a plugin replaces the default handling of a method rather than merely
 * running before it.
 *
 * `Event` implements this and is what an event class normally extends; the
 * interface is here so that a class which already has a parent can still be
 * one (R-ARC-06).
 */
interface IEvent
{
    /**
     * Has a listener declared the matter settled?
     */
    public function isStopped(): bool;
}
