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
use DavServices\Dav\Locks\LockInfo;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The contract, run against the storage that lives in an array.
 *
 * The file and database backends of P3-02 inherit the same list, which is what
 * the contract is for.
 *
 * **{@see LockInfo} is named here on purpose.** Asking a backend which locks
 * hold a path runs `LockInfo::covers()`, and coverage is attributed by
 * `CoversClass`: without this, every branch this list takes through that
 * method is measured and then thrown away. The merged report then holds one
 * map of the method with hits and several without, and comes to a branch that
 * no shard ever missed — which is how a chunk that touched nothing but time
 * zones once turned the gate red. `UsesClass` does not do it: that keeps the
 * class out of the attribution, which is the very thing that hurts.
 */
#[CoversClass(LockInfo::class)]
final class MemoryLockBackendTest extends LockBackendContract
{
    protected function backend(): ILockBackend
    {
        return new MemoryLockBackend();
    }
}
