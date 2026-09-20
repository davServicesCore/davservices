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

use DateTimeImmutable;
use DavServices\Backend\ILockBackend;
use DavServices\Dav\Locks\LockInfo;
use DavServices\Uri\Path;

/**
 * A lock storage that lives in an array.
 *
 * It is a test double and nothing else. **A lock that only one process can see
 * is not a lock**, and every request to a real server is as likely as not a
 * process of its own — which is why the library ships no such backend, and why
 * the file and database ones of P3-02 are the real answer to R-LOCK-05.
 *
 * Here it earns its place twice: it runs the contract, which is what makes the
 * contract worth writing, and it lets the tests of the lock plugin say
 * something about the plugin rather than about a filesystem.
 */
final class MemoryLockBackend implements ILockBackend
{
    /** @var array<string, LockInfo> Keyed by token, which is unique by design. */
    private array $locks = [];

    public function locksOn(string $path, DateTimeImmutable $now): array
    {
        return $this->matching(
            static fn (LockInfo $lock): bool => $lock->covers($path),
            $now,
        );
    }

    public function locksBelow(string $path, DateTimeImmutable $now): array
    {
        return $this->matching(
            static fn (LockInfo $lock): bool => $lock->root() !== $path && Path::isBelow($lock->root(), $path),
            $now,
        );
    }

    public function set(LockInfo $lock): void
    {
        $this->locks[$lock->token()] = $lock;
    }

    public function remove(LockInfo $lock): void
    {
        unset($this->locks[$lock->token()]);
    }

    /**
     * What matches and has not run out — and the ones that have are dropped
     * while we are here, as R-LOCK-03 asks.
     *
     * @param callable(LockInfo): bool $matches
     *
     * @return list<LockInfo>
     */
    private function matching(callable $matches, DateTimeImmutable $now): array
    {
        $found = [];

        foreach ($this->locks as $token => $lock) {
            if ($lock->hasExpired($now)) {
                unset($this->locks[$token]);

                continue;
            }

            if ($matches($lock)) {
                $found[] = $lock;
            }
        }

        return $found;
    }
}
