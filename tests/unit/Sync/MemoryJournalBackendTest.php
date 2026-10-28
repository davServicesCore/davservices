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

use DavServices\Sync\Change;
use DavServices\Sync\IJournalBackend;

/**
 * The contract, run against the history that lives in an array.
 *
 * And the one thing the contract cannot say from outside: **this store narrows
 * by nothing**. `$under` is a hint that an implementation may ignore, so no
 * case up there may ask that anything is absent — and a promise nobody
 * exercises is one that quietly stops being true. Here it is exercised, which
 * is what shows the latitude in {@see IJournalBackend::since()} is a cut in
 * the interface rather than a sentence in a DocBlock.
 */
final class MemoryJournalBackendTest extends JournalBackendContract
{
    /**
     * **Everything since the revision, wherever it happened.** A store with no
     * index on the path has nothing to narrow by, and
     * {@see \DavServices\Sync\Journal} applies RFC 6578 §3.5's rules to
     * whatever comes back — slow rather than wrong, which is the right way
     * round.
     */
    public function testItAnswersWhatHappenedElsewhereToo(): void
    {
        $entries = $this->backend();

        $entries->append('calendars/private/dentist.ics', Change::Changed, $this->at(self::MOMENT));

        self::assertSame(
            ['calendars/private/dentist.ics'],
            array_column($entries->since(0, self::COLLECTION), 1),
        );
    }

    protected function backend(): IJournalBackend
    {
        return new MemoryJournalBackend();
    }
}
