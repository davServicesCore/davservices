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

use DavServices\Sync\IRevisions;

/**
 * A store that says every collection is at the same change.
 *
 * **Enough for the cases that are not about the seam.** What a report does
 * with a revision is the same whichever collection it came from, so a case
 * about the `Depth` header or about §6.1's body has no reason to describe a
 * history — and one that is about the state hands in a different number.
 *
 * {@see none()} is the other half: a collection this server tracks nothing
 * for, which RFC 6578 §4 has no property for and §3.2's report refuses.
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
}
