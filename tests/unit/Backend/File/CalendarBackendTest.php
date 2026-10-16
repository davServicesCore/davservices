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

namespace DavServices\Tests\Unit\Backend\File;

use DavServices\Backend\File\CalendarBackend;
use DavServices\Backend\ICalendarBackend;
use DavServices\Dav\Calendars\CalendarObjectInfo;
use DavServices\Exception\NotFound;
use DavServices\Tests\Unit\Backend\CalendarBackendContract;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * The contract, run against the store that keeps its objects in files, plus
 * what only a file store can get wrong — and the narrowing R-CAL-16 asks for,
 * which this is the implementation that does.
 */
#[CoversClass(CalendarBackend::class)]
final class CalendarBackendTest extends CalendarBackendContract
{
    private string $directory = '';

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir() . '/davservices-calendars-' . bin2hex(random_bytes(8));

        foreach (['', '/bernard', '/bernard/work', '/bernard/home', '/cyrus', '/cyrus/work'] as $part) {
            if (!mkdir($directory . $part) && !is_dir($directory . $part)) {
                self::fail(sprintf('The test could not make "%s".', $directory . $part));
            }
        }

        $this->directory = $directory;
    }

    protected function tearDown(): void
    {
        self::removeEverythingUnder($this->directory);
    }

    /**
     * A directory this library made up is a directory nobody meant to have.
     * The application says where its data goes, and says it once.
     */
    public function testRefusesADirectoryThatIsNotThere(): void
    {
        $this->expectException(RuntimeException::class);

        new CalendarBackend($this->directory . '/nowhere');
    }

    /**
     * **An object is a file a person can read.** That is the whole reason this
     * backend exists, so it is asserted rather than assumed.
     */
    public function testAnObjectIsAFileAPersonCanRead(): void
    {
        $this->backend();

        self::assertSame(
            self::object('meeting'),
            file_get_contents($this->directory . '/bernard/work/meeting.ics'),
        );
    }

    /**
     * **Every file in a calendar is taken to be an object**, suffix or none.
     * §4.2 has a calendar hold "calendar object resources and collections that
     * are not calendar collections", so a file in here is meant to be one —
     * and a store that filtered by `.ics` would hide an object a client called
     * `meeting`.
     */
    public function testEveryFileInACalendarIsTakenToBeAnObject(): void
    {
        $backend = $this->backend();

        $backend->put($this->principal(), 'work', 'plain', self::object('plain'));

        $names = array_map(
            static fn (CalendarObjectInfo $object): string => $object->name(),
            $backend->objects($this->principal(), 'work'),
        );

        sort($names);

        self::assertSame(['meeting.ics', 'plain'], $names);
    }

    /**
     * And a file beside the calendars is not a calendar, because a calendar is
     * a directory here.
     */
    public function testAFileBesideTheCalendarsIsNotOne(): void
    {
        file_put_contents($this->directory . '/bernard/notes.txt', 'This is a note.');

        $names = array_map(
            static fn (object $calendar): string => $calendar->name(),
            $this->backend()->calendars($this->principal()),
        );

        sort($names);

        self::assertSame(['home', 'work'], $names);
    }

    /**
     * A principal the store has no directory for has no calendars, which is
     * the ordinary state of an account nobody has provisioned.
     */
    public function testAPrincipalWithNoDirectoryHasNoCalendars(): void
    {
        self::assertSame([], $this->backend()->calendars('nobody'));
    }

    /**
     * **A principal cannot be used to walk out of the store.** The name comes
     * from the authentication layer, but a store that trusted it to be one
     * segment would be one typo away from serving another account's
     * calendars.
     */
    public function testAPrincipalCannotBeUsedToWalkOutOfTheStore(): void
    {
        self::assertSame([], $this->backend()->calendars('../bernard'));
    }

    /**
     * **And cannot be used to reach a calendar outside it either.**
     *
     * This is the case the guard exists for, and the obvious one is not
     * enough: asking for a principal called `../escaped` fails anyway,
     * because nothing of that name is there. The guard only matters when the
     * traversal would land somewhere real — so this puts a calendar next to
     * the store and shows it stays out of reach.
     */
    public function testAPrincipalCannotBeUsedToWalkIntoACalendarOutsideTheStore(): void
    {
        $outside = $this->directory . '-outside';

        if (!mkdir($outside) || !mkdir($outside . '/work')) {
            self::fail('The test could not put a directory beside the store.');
        }

        file_put_contents($outside . '/work/secret.ics', self::object('secret'));

        try {
            $this->expectException(NotFound::class);

            $this->backend()->objects('../' . basename($outside), 'work');
        } finally {
            self::removeEverythingUnder($outside);
        }
    }

    /**
     * **The entity tag a write answers with describes the object that was
     * just written**, not the one that was there before.
     *
     * PHP caches `stat()` per path within a request, so a `put()` over an
     * object something had already looked at would otherwise report the old
     * length — and a client would be handed an entity tag for data it never
     * received. Writing beside the place and moving in is what keeps that
     * honest, because `rename()` invalidates the cache for the path it moves
     * onto.
     */
    public function testTheEntityTagOfAWriteDescribesWhatWasJustWritten(): void
    {
        $backend = $this->backend();
        $longer = self::object('meeting', 'A meeting with a much longer summary than before');

        // Reads the object's stat into PHP's cache, as a `PROPFIND` before a
        // `PUT` in the same request would.
        $backend->object($this->principal(), 'work', 'meeting.ics');

        $answered = $backend->put($this->principal(), 'work', 'meeting.ics', $longer);

        clearstatcache();

        $fresh = $backend->object($this->principal(), 'work', 'meeting.ics');

        self::assertNotNull($fresh);
        self::assertSame(strlen($longer), $fresh->size());
        self::assertSame($fresh->etag(), $answered);
    }

    /**
     * **The `UID` and the kind are answered as unknown**, because the only way
     * this store could know them is by reading the object — the one thing the
     * listing exists to avoid.
     */
    public function testTheUidAndTheKindAreAnsweredAsUnknown(): void
    {
        $object = $this->backend()->object($this->principal(), 'work', 'meeting.ics');

        self::assertNotNull($object);
        self::assertNull($object->uid());
        self::assertNull($object->kind());
    }

    /**
     * **A write leaves no part file behind**, because the file is written
     * beside its place and moved in.
     */
    public function testAWriteLeavesNoPartFileBehind(): void
    {
        $backend = $this->backend();

        $backend->put($this->principal(), 'work', 'review.ics', self::object('review'));

        $left = glob($this->directory . '/bernard/work/*.part');

        self::assertSame([], $left === false ? [] : $left);
    }

    /**
     * A write that cannot be made is said out loud rather than passed off as
     * done: a `PUT` over the name of a directory is what a client produces,
     * and `rename` refuses it rather than emptying it.
     */
    public function testAWriteThatCannotBeMadeIsSaidOutLoud(): void
    {
        $backend = $this->backend();

        if (!mkdir($this->directory . '/bernard/work/folder')) {
            self::fail('The test could not make the directory it needs.');
        }

        $this->expectException(RuntimeException::class);

        $backend->put($this->principal(), 'work', 'folder', self::object('folder'));
    }

    /**
     * And it leaves nothing behind either.
     */
    public function testAFailedWriteLeavesNoPartFileBehind(): void
    {
        $backend = $this->backend();

        if (!mkdir($this->directory . '/bernard/work/folder')) {
            self::fail('The test could not make the directory it needs.');
        }

        try {
            $backend->put($this->principal(), 'work', 'folder', self::object('folder'));
        } catch (RuntimeException) {
            // The refusal is the case above; this one is about the leftovers.
        }

        $left = glob($this->directory . '/bernard/work/*.part');

        self::assertSame([], $left === false ? [] : $left);
    }

    /**
     * Asking for something outside the calendar finds no object rather than
     * being refused: on the way *out* there is nothing to protect but the
     * answer, and `../../etc/passwd` is not an object in this calendar.
     */
    public function testAskingForSomethingOutsideTheCalendarFindsNoObject(): void
    {
        $backend = $this->backend();

        self::assertNull($backend->object($this->principal(), 'work', '../meeting.ics'));
        self::assertNull($backend->body($this->principal(), 'work', '../meeting.ics'));
    }

    /**
     * **A range answers every object that may overlap**, not the first of
     * them. §9.9 asks for "the calendar object resources that […] intersect",
     * plural, and a store that stopped at one would hide a client's day.
     */
    public function testARangeAnswersEveryObjectThatMayOverlap(): void
    {
        $backend = $this->backend();

        $backend->put($this->principal(), 'work', 'review.ics', self::object('review'));

        $found = array_map(
            static fn (CalendarObjectInfo $info): string => $info->name(),
            $backend->objectsInRange(
                $this->principal(),
                'work',
                self::moment('20260101T000000Z'),
                self::moment('20260201T000000Z'),
            ),
        );

        sort($found);

        self::assertSame(['meeting.ics', 'review.ics'], $found);
    }

    /**
     * **The two ends of the range are not the same kind of end**, and these
     * are the boundaries that say so.
     *
     * §9.9: "The 'start' attribute specifies the inclusive start of the time
     * range, and the 'end' attribute specifies the non-inclusive end." So a
     * range that ends exactly where the object begins misses it — its own
     * condition for the shortest event is "end > DTSTART", and `12:00 > 12:00`
     * is false.
     *
     * A range that begins exactly where the store's reckoning of the object
     * ends still catches it, because that reckoning carries §9.9's longest
     * default duration as grace. The store errs wide on purpose; the class
     * says why.
     */
    public function testTheEndsOfTheRangeAreNotTheSameKindOfEnd(): void
    {
        $backend = $this->backend();
        $named = static fn (CalendarObjectInfo $info): string => $info->name();

        // Ends exactly at DTSTART, so it misses: "end > DTSTART" is false.
        self::assertSame([], array_map($named, $backend->objectsInRange(
            $this->principal(),
            'work',
            self::moment('20260105T110000Z'),
            self::moment('20260105T120000Z'),
        )));

        // Begins exactly where the object's span ends — DTEND plus the day of
        // grace — and the inclusive start keeps it in.
        self::assertSame(['meeting.ics'], array_map($named, $backend->objectsInRange(
            $this->principal(),
            'work',
            self::moment('20260106T130000Z'),
            self::moment('20260106T140000Z'),
        )));
    }

    /**
     * **This is the store that narrows** (R-CAL-16). A range the object cannot
     * be in leaves it out, which is the whole point of the cut: the caller
     * gets a handful rather than a year.
     *
     * @param string $from The inclusive start, or an empty string for
     *                     "-infinity"
     * @param string $until The non-inclusive end, or an empty string for
     *                      "+infinity"
     */
    #[DataProvider('rangesThatMissIt')]
    public function testARangeTheObjectCannotBeInLeavesItOut(string $from, string $until): void
    {
        $found = $this->backend()->objectsInRange(
            $this->principal(),
            'work',
            self::moment($from),
            self::moment($until),
        );

        self::assertSame([], $found);
    }

    /**
     * The fixture's object runs from 12:00 to 13:00 UTC on 5.1.2026, and the
     * store gives what it reads a day's grace at the end (§9.9's longest
     * default duration), so a range has to miss it by more than that.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function rangesThatMissIt(): iterable
    {
        yield 'long before it' => ['19700101T000000Z', '19700102T000000Z'];

        yield 'the day before it' => ['20260103T000000Z', '20260104T000000Z'];

        yield 'well after it' => ['20270101T000000Z', '20270102T000000Z'];

        yield 'open at the start, ending before it' => ['', '20260101T000000Z'];

        yield 'open at the end, starting after it' => ['20270101T000000Z', ''];
    }

    /**
     * **Anything the store cannot bound stays in the answer**, which is the
     * rule §9.9 forces: "Time range tests MUST consider every recurrence
     * instance when testing the time range condition; if any one instance
     * matches, then the test returns true." A store that answered too little
     * would make the server drop a client's appointments.
     *
     * @param string $name What to call the object the case adds
     * @param string $object The object itself
     */
    #[DataProvider('objectsItCannotBound')]
    public function testWhatItCannotBoundStaysInTheAnswer(string $name, string $object): void
    {
        $backend = $this->backend();

        $backend->put($this->principal(), 'work', $name, $object);

        $found = array_map(
            static fn (CalendarObjectInfo $info): string => $info->name(),
            $backend->objectsInRange(
                $this->principal(),
                'work',
                self::moment('19700101T000000Z'),
                self::moment('19700102T000000Z'),
            ),
        );

        self::assertSame([$name], $found);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function objectsItCannotBound(): iterable
    {
        // A series runs until its rule says otherwise, and this one never
        // does.
        yield 'a series with no end' => [
            'daily.ics',
            str_replace("DTEND:20260105T130000Z\r\n", "RRULE:FREQ=DAILY\r\n", self::object('daily')),
        ];

        // §3.8.5.2's RDATE adds instances the text does not otherwise show.
        yield 'extra dates' => [
            'extra.ics',
            str_replace("DTEND:", "RDATE:20300101T120000Z\r\nDTEND:", self::object('extra')),
        ];

        // Nothing in it to bound by.
        yield 'no moment at all' => [
            'bare.ics',
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//x//y//EN\r\n"
                . "BEGIN:VJOURNAL\r\nUID:bare@example.com\r\nSUMMARY:A thought\r\nEND:VJOURNAL\r\nEND:VCALENDAR\r\n",
        ];

        // A date this library cannot read is a date it will not narrow by.
        yield 'a date it cannot read' => [
            'broken.ics',
            str_replace('DTSTART:20260105T120000Z', 'DTSTART:not-a-date', self::object('broken')),
        ];

        // Text that is no iCalendar at all. §4.2 says a calendar holds
        // objects, but a store does not validate — `Plugin\CalDav` does, and
        // a file somebody dropped in by hand is still a file in the listing.
        yield 'no calendar at all' => ['note.txt', 'This is a note, not a calendar.'];
    }

    /**
     * And a to-do bounded by its `DUE` alone is narrowed like anything else:
     * §3.8.2.3 gives `DUE` the DATE-TIME type, and §9.9's table uses it where
     * a to-do has no `DTSTART`.
     */
    public function testAToDoIsBoundedByItsDueDate(): void
    {
        $backend = $this->backend();
        $todo = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//x//y//EN\r\n"
            . "BEGIN:VTODO\r\nUID:todo@example.com\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DUE:20260110T090000Z\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";

        $backend->put($this->principal(), 'work', 'todo.ics', $todo);

        $named = static fn (CalendarObjectInfo $info): string => $info->name();

        self::assertSame(['todo.ics'], array_map($named, $backend->objectsInRange(
            $this->principal(),
            'work',
            self::moment('20260109T000000Z'),
            self::moment('20260111T000000Z'),
        )));
        self::assertSame([], array_map($named, $backend->objectsInRange(
            $this->principal(),
            'work',
            self::moment('20260101T000000Z'),
            self::moment('20260102T000000Z'),
        )));
    }

    /**
     * **An all-day event is read as a date** (§3.3.4: a DATE is eight digits),
     * which is the other half of how the store tells the two values apart.
     */
    public function testAnAllDayEventIsNarrowedByItsDate(): void
    {
        $backend = $this->backend();
        $allDay = str_replace(
            ["DTSTART:20260105T120000Z\r\n", "DTEND:20260105T130000Z\r\n"],
            ["DTSTART;VALUE=DATE:20260105\r\n", ''],
            self::object('allday'),
        );

        $backend->put($this->principal(), 'work', 'allday.ics', $allDay);

        $named = static fn (CalendarObjectInfo $info): string => $info->name();

        // The fixture's own event is on that day too, so the case asks that
        // the all-day one is there rather than that it is alone.
        self::assertContains('allday.ics', array_map($named, $backend->objectsInRange(
            $this->principal(),
            'work',
            self::moment('20260105T000000Z'),
            self::moment('20260106T000000Z'),
        )));
        self::assertSame([], array_map($named, $backend->objectsInRange(
            $this->principal(),
            'work',
            self::moment('20260201T000000Z'),
            self::moment('20260202T000000Z'),
        )));
    }

    protected function backend(): ICalendarBackend
    {
        $backend = new CalendarBackend($this->directory);

        if (!is_file($this->directory . '/bernard/work/meeting.ics')) {
            $backend->put('bernard', 'work', 'meeting.ics', self::object('meeting'));
        }

        return $backend;
    }

    protected function secondPrincipal(): string
    {
        return 'cyrus';
    }

    /**
     * Everything under a directory, and then the directory.
     */
    private static function removeEverythingUnder(string $directory): void
    {
        $entries = glob($directory . '/*');

        foreach ($entries === false ? [] : $entries as $entry) {
            is_dir($entry) ? self::removeEverythingUnder($entry) : unlink($entry);
        }

        rmdir($directory);
    }
}
