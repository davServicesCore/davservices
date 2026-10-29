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

use DateTimeImmutable;
use DavServices\Sync\Change;
use DavServices\Sync\IJournalBackend;

/**
 * A change history that lives in an array.
 *
 * **It narrows by nothing**, which is the half of
 * {@see IJournalBackend::since()} the contract has to permit: a store with no
 * index on the path answers everything since the revision, and
 * {@see \DavServices\Sync\Journal} applies §3.5's rules to it. Slow rather
 * than wrong, which is the right way round — and having one store here that
 * narrows nothing is what shows the cut is a cut rather than one
 * implementation's habit.
 */
final class MemoryJournalBackend implements IJournalBackend
{
    /** @var list<array{int, string, Change, DateTimeImmutable}> */
    private array $entries = [];

    private int $latest = 0;

    private int $cleanedUpTo = 0;

    public function append(string $member, Change $change, DateTimeImmutable $at): int
    {
        $this->entries[] = [++$this->latest, $member, $change, $at];

        return $this->latest;
    }

    /**
     * @return list<array{int, string, Change}>
     */
    public function since(int $revision, string $under): array
    {
        $found = [];

        foreach ($this->entries as [$at, $member, $change]) {
            if ($at > $revision) {
                $found[] = [$at, $member, $change];
            }
        }

        return $found;
    }

    public function forget(DateTimeImmutable $before): void
    {
        $kept = [];

        foreach ($this->entries as $entry) {
            if ($entry[3] >= $before) {
                $kept[] = $entry;

                continue;
            }

            $this->cleanedUpTo = max($this->cleanedUpTo, $entry[0]);
        }

        $this->entries = $kept;
    }

    public function cleanedUpTo(): int
    {
        return $this->cleanedUpTo;
    }

    public function latest(): int
    {
        return $this->latest;
    }
}
