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

namespace DavServices\Dav\Calendars;

use DateTimeImmutable;

/**
 * What is known about one calendar object resource without reading it
 * (RFC 4791 §4.1).
 *
 * **The body is deliberately not here, and that absence is the design.**
 * R-BE-02 asks that the cuts make N+1 queries avoidable, and names
 * `PROPFIND Depth: 1` first: a client listing a year of events wants their
 * entity tags and their sizes, and wants them in one question. A metadata
 * object carrying the body would make the cheap question as dear as the
 * expensive one, and no amount of care further up could undo that.
 *
 * So this is everything `PROPFIND` can answer from, and
 * {@see \DavServices\Backend\ICalendarBackend::bodies()} is the separate,
 * batched way to the objects themselves.
 *
 * **The `UID` is in here although it is inside the object**, which is the one
 * thing a store has to read an object to learn. It is here because §5.3.2.1
 * asks that a `UID` not already be in use in the calendar —
 * `CALDAV:no-uid-conflict` — and a server that had to fetch every body to
 * answer that would make a single `PUT` cost the whole collection. A store
 * keeps it beside the object for the same reason it keeps the entity tag.
 *
 * **The kind is in here for the same reason.** §4.1 has it that a calendar
 * object resource "MUST NOT contain more than one type of calendar component",
 * so one kind describes the whole object — and `CALDAV:comp-filter` (§9.7.1)
 * is the filter clients send most, which a store can answer from its index
 * instead of reading every object to find out what it is.
 *
 * It lives in `Dav\Calendars` rather than beside the interface that hands it
 * out, because a backend interface may not reach up into the layer that uses
 * it — the same reason {@see \DavServices\Dav\Cards\CardInfo} and
 * {@see \DavServices\Dav\Locks\LockInfo} live where they do.
 */
final class CalendarObjectInfo
{
    /**
     * @param string $name The member name inside the calendar, which is the
     *                     last segment of the object's URL
     * @param string $etag The entity tag, quoted as RFC 9110 §8.8.3 writes
     *                     one. §5.3.4 makes it a MUST rather than a nicety:
     *                     "The DAV:getetag property MUST be defined and set to
     *                     a strong entity tag on all calendar object
     *                     resources" — so a store has one for every object it
     *                     holds, and this is where it says what it is
     * @param int $size The object in octets, which `DAV:getcontentlength`
     *                  answers with and `CALDAV:max-resource-size` (§5.2.5) is
     *                  measured against
     * @param string|null $uid The iCalendar `UID`, or null where the store
     *                         does not keep it. **Null is not "no UID"**:
     *                         RFC 5545 §3.8.4.7 makes it mandatory in every
     *                         component that has one, and a store answering
     *                         null is saying it cannot tell without reading,
     *                         which is a limit of the store rather than of the
     *                         object
     * @param string|null $kind The one component kind the object holds
     *                          (§4.1), upper-case as RFC 5545 §3.6 names it,
     *                          or null where the store cannot tell without
     *                          reading
     * @param DateTimeImmutable|null $lastModified When the store last saw it
     *                                             change, or null where it
     *                                             does not keep that
     */
    public function __construct(
        private readonly string $name,
        private readonly string $etag,
        private readonly int $size,
        private readonly ?string $uid = null,
        private readonly ?string $kind = null,
        private readonly ?DateTimeImmutable $lastModified = null,
    ) {
    }

    /**
     * The member name inside the calendar.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * The entity tag, as it goes into an `ETag` header.
     */
    public function etag(): string
    {
        return $this->etag;
    }

    /**
     * The object's length in octets.
     */
    public function size(): int
    {
        return $this->size;
    }

    /**
     * The iCalendar `UID`, or null where the store cannot tell without
     * reading.
     */
    public function uid(): ?string
    {
        return $this->uid;
    }

    /**
     * The one component kind the object holds, or null where the store cannot
     * tell without reading.
     */
    public function kind(): ?string
    {
        return $this->kind;
    }

    /**
     * When the store last saw it change, or null where it does not keep that.
     */
    public function lastModified(): ?DateTimeImmutable
    {
        return $this->lastModified;
    }
}
