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

namespace DavServices\Tests\Unit\Dav;

use DavServices\Dav\IQuota;

/**
 * A collection that keeps an account of what it holds (RFC 4331).
 *
 * Separate from the plain one because most collections have no quota, and a
 * server that reported one for every collection would be telling clients
 * about a limit nobody set.
 */
final class MemoryQuotaCollection extends MemoryCollection implements IQuota
{
    public function __construct(
        string $name,
        private readonly int $used = 0,
        private readonly ?int $available = null,
    ) {
        parent::__construct($name);
    }

    public function quotaUsedBytes(): int
    {
        return $this->used;
    }

    public function quotaAvailableBytes(): ?int
    {
        return $this->available;
    }
}
