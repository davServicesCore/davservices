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

namespace DavServices\Tests\Unit\VObject\TimeZone;

use DateTimeImmutable;
use DateTimeZone;
use DavServices\VObject\TimeZone\Aliases;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for the catalogue of other systems' zone names. R-TZ-01.
 *
 * **The names are Windows' own**, the 143 keys under
 * `HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Time Zones`, which is
 * what every Microsoft calendaring product writes into a `TZID`. The
 * catalogue was built from that registry and is checked against it: each key
 * there carries a `Display` value naming the offset the zone keeps, so every
 * line of the table can be held against the system that invented the name.
 *
 * **That check needs Windows**, so it lives with the chunk's notes rather
 * than here — and what it found on 07.10.2026 was nothing: 143 names, none
 * missing, none Windows does not know, and every zone keeping the offset
 * Windows states for it.
 *
 * What is asked here is what can be asked anywhere: that every zone the table
 * names is one the time zone database has. **A catalogue naming a zone that
 * does not exist is worse than a catalogue without the entry**, because a
 * resolver would then fail where it promised to answer.
 */
#[CoversClass(Aliases::class)]
final class AliasesTest extends TestCase
{
    /**
     * **Every zone the catalogue names is one the database has.**
     */
    public function testEveryZoneNamedIsOneTheDatabaseHas(): void
    {
        $known = DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC);

        foreach (Aliases::all() as $windows => $iana) {
            self::assertContains($iana, $known, $windows);
        }
    }

    /**
     * **And every one of them keeps an offset a civil clock could keep.** The
     * inhabited world runs from twelve hours behind UTC to fourteen ahead —
     * Baker Island to Kiritimati — so an entry outside that is a mistake
     * whatever else it looks like.
     */
    public function testEveryZoneNamedKeepsACivilOffset(): void
    {
        $winter = new DateTimeImmutable('2024-01-15T12:00:00Z');

        foreach (Aliases::all() as $windows => $iana) {
            $offset = (new DateTimeZone($iana))->getOffset($winter);

            self::assertGreaterThanOrEqual(-12 * 3600, $offset, $windows);
            self::assertLessThanOrEqual(14 * 3600, $offset, $windows);
        }
    }

    /**
     * **The catalogue holds the whole of Windows' list**, which was 143 zones
     * when it was read. The number is asserted so that an entry cannot be
     * lost in an edit without a test saying so.
     */
    public function testTheCatalogueHoldsTheWholeList(): void
    {
        self::assertCount(143, Aliases::all());
    }

    /**
     * **Two names of the same zone are both kept.** `Russia Time Zone 11` and
     * `Kamchatka Standard Time` are both Windows' names for Kamchatka, the
     * second marked "veraltet" in its own display name — and data written by
     * an older system carries the older name.
     */
    public function testTwoNamesOfOneZoneAreBothKept(): void
    {
        self::assertSame('Asia/Kamchatka', Aliases::iana('Kamchatka Standard Time'));
        self::assertSame('Asia/Kamchatka', Aliases::iana('Russia Time Zone 11'));
    }

    /**
     * **A fixed-offset Windows zone keeps POSIX's inverted sign.**
     * `Etc/GMT+12` is twelve hours *behind* UTC, which is what Windows calls
     * `Dateline Standard Time` and writes as `(UTC-12:00)`. Getting that sign
     * the wrong way round is a day's error, so both directions are here.
     */
    public function testAFixedOffsetZoneKeepsTheInvertedSign(): void
    {
        $winter = new DateTimeImmutable('2024-01-15T12:00:00Z');

        self::assertSame(
            -12 * 3600,
            (new DateTimeZone((string) Aliases::iana('Dateline Standard Time')))->getOffset($winter),
        );
        self::assertSame(
            12 * 3600,
            (new DateTimeZone((string) Aliases::iana('UTC+12')))->getOffset($winter),
        );
    }

    /**
     * **A name the catalogue has not got is a miss, not a guess.**
     */
    public function testANameTheCatalogueHasNotGotIsAMiss(): void
    {
        self::assertNull(Aliases::iana('Nowhere Standard Time'));
        self::assertNull(Aliases::iana('Europe/Berlin'));
    }
}
