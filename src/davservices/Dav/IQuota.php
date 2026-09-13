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

namespace DavServices\Dav;

/**
 * A collection that can say how much room there is.
 *
 * RFC 4331 asks for two properties, and clients use them for more than a
 * display: macOS refuses to begin a copy it believes will not fit.
 */
interface IQuota
{
    /**
     * How many bytes are already used below this collection.
     */
    public function quotaUsedBytes(): int;

    /**
     * How many more bytes may be written, or null where there is no limit.
     *
     * Null is not the same as zero, and a backend without a quota must say
     * null rather than an invented ceiling.
     */
    public function quotaAvailableBytes(): ?int;
}
