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

use DavServices\Backend\ILockBackend;

/**
 * The contract, run against the storage that lives in an array.
 *
 * The file and database backends of P3-02 inherit the same list, which is what
 * the contract is for.
 */
final class MemoryLockBackendTest extends LockBackendContract
{
    protected function backend(): ILockBackend
    {
        return new MemoryLockBackend();
    }
}
