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

namespace DavServices\Tests\Unit\Sync;

use DavServices\Event\EventEmitter;
use DavServices\Sync\Changes;
use DavServices\Sync\IRevisions;

/**
 * A store that says every collection is at the same change and keeps no
 * history at all.
 *
 * **Enough for the cases that are not about the seam.** What a report does
 * with a revision is the same whichever collection it came from, so a case
 * about the `Depth` header or about §6.1's body has no reason to describe a
 * history — and one that is about the state hands in a different number.
 *
 * It is also the server RFC 6578 §3.2 describes among its reasons for
 * invalidating a token — one "unable to maintain all of the change data for a
 * collection" — so every token it is handed is refused and §3.1 has the client
 * synchronise in full. {@see \DavServices\Sync\Journal} is the other half,
 * and the cases about an answered token use that.
 *
 * {@see none()} is the third: a collection this server tracks nothing for,
 * which §4 has no property for and §3.2's report refuses outright.
 */
final class FixedRevisions implements IRevisions
{
    private function __construct(private readonly ?int $revision)
    {
    }

    /**
     * Every collection at this change.
     */
    public static function at(int $revision): self
    {
        return new self($revision);
    }

    /**
     * A server that tracks no synchronisation state at all.
     */
    public static function none(): self
    {
        return new self(null);
    }

    public function revisionOf(string $collection): ?int
    {
        return $this->revision;
    }

    /**
     * Nothing is remembered, which §3.2 permits and §3.2's precondition is the
     * honest answer to.
     */
    public function changesSince(
        int $revision,
        string $collection,
        int $depth,
        int $atMost = self::EVERYTHING,
    ): ?Changes {
        return null;
    }

    /**
     * And a store that remembers nothing has nothing to hear.
     */
    public function keepTrack(EventEmitter $events): void
    {
    }
}
