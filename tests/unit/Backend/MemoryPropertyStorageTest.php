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

namespace DavServices\Tests\Unit\Backend;

use DavServices\Backend\IPropertyStorageBackend;

/**
 * The contract, run against the storage that lives in an array.
 */
final class MemoryPropertyStorageTest extends PropertyStorageContract
{
    protected function storage(): IPropertyStorageBackend
    {
        return new MemoryPropertyStorage();
    }
}
