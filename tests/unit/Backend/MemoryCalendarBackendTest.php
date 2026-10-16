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

use DavServices\Backend\ICalendarBackend;
use DavServices\Dav\Calendars\CalendarInfo;
use DavServices\Dav\Calendars\CalendarObjectInfo;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The contract, run against the store that lives in an array.
 *
 * And the things the contract cannot say from outside. **`objects()` answers a
 * `PROPFIND Depth: 1` without reading a single object** — the promise R-BE-02
 * is made of, which only a counting store can show kept. **And a store with no
 * index answers a range with everything**, which is the half of R-CAL-16 the
 * contract has to permit rather than require.
 */
#[CoversClass(CalendarObjectInfo::class)]
#[CoversClass(CalendarInfo::class)]
final class MemoryCalendarBackendTest extends CalendarBackendContract
{
    public function testTheListingReadsNoObjectAtAll(): void
    {
        $backend = $this->backend();

        self::assertInstanceOf(MemoryCalendarBackend::class, $backend);

        $backend->put($this->principal(), 'work', 'review.ics', self::object('review'));
        $backend->objects($this->principal(), 'work');
        $backend->object($this->principal(), 'work', 'meeting.ics');

        self::assertSame(0, $backend->bodyReads);
    }

    /**
     * **And asking for many bodies is one pass over the names**, which is the
     * other half: a multiget of forty events reads forty, not sixteen hundred.
     */
    public function testAskingForManyBodiesReadsEachOnce(): void
    {
        $backend = $this->backend();

        self::assertInstanceOf(MemoryCalendarBackend::class, $backend);

        $backend->put($this->principal(), 'work', 'review.ics', self::object('review'));
        $backend->bodies($this->principal(), 'work', ['meeting.ics', 'review.ics', 'gone.ics']);

        self::assertSame(2, $backend->bodyReads);
    }

    /**
     * **A store with no index answers a range with everything, and reads
     * nothing to do it.**
     *
     * That is conformant, and the reason it has to be is §9.9: the final test
     * belongs to the caller — "the server MUST only return the calendar object
     * resources that […] intersect" — so a store that narrows by nothing has
     * answered the question it was asked. It is slow rather than wrong, which
     * is the right way round: a store that guessed and answered too little
     * would make the server drop a client's events, and nothing further up
     * could detect it.
     */
    public function testAStoreWithNoIndexAnswersARangeWithEverything(): void
    {
        $backend = $this->backend();

        self::assertInstanceOf(MemoryCalendarBackend::class, $backend);

        $backend->put($this->principal(), 'work', 'review.ics', self::object('review'));

        $longAgo = $backend->objectsInRange(
            $this->principal(),
            'work',
            self::moment('19700101T000000Z'),
            self::moment('19700102T000000Z'),
        );

        self::assertSame(
            ['meeting.ics', 'review.ics'],
            array_map(static fn (CalendarObjectInfo $object): string => $object->name(), $longAgo),
        );
        self::assertSame(1, $backend->rangeQueries);
        self::assertSame(0, $backend->bodyReads);
    }

    protected function backend(): ICalendarBackend
    {
        $backend = new MemoryCalendarBackend();

        $backend->provision($this->principal(), new CalendarInfo(
            'work',
            'Work',
            'Appointments',
            self::TIME_ZONE,
            ['VEVENT'],
        ));
        $backend->provision($this->principal(), new CalendarInfo('home'));
        $backend->provision('cyrus', new CalendarInfo('work', 'Work'));
        $backend->put($this->principal(), 'work', 'meeting.ics', self::object('meeting'));

        return $backend;
    }

    protected function secondPrincipal(): string
    {
        return 'cyrus';
    }

    /** §5.2.2's own example, shortened to the one observance it needs. */
    private const TIME_ZONE = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\n"
        . "BEGIN:VTIMEZONE\r\nTZID:US-Eastern\r\nBEGIN:STANDARD\r\nDTSTART:19671029T020000\r\n"
        . "TZOFFSETFROM:-0400\r\nTZOFFSETTO:-0500\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\nEND:VCALENDAR\r\n";
}
