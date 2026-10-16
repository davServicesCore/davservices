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

namespace DavServices\Backend\File;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use DavServices\Backend\ICalendarBackend;
use DavServices\Dav\Calendars\CalendarInfo;
use DavServices\Dav\Calendars\CalendarObjectInfo;
use DavServices\Exception\Forbidden;
use DavServices\Exception\NotFound;
use DavServices\VObject\ParseError;
use DavServices\VObject\Reader;
use DavServices\VObject\Value\Date;
use DavServices\VObject\Value\DateTime;
use FilesystemIterator;
use RuntimeException;

/**
 * Calendars in a directory, one directory per calendar and one file per
 * object (R-BE-05).
 *
 *     <root>/<principal>/<calendar>/<member name>
 *
 * The simple store: nothing to set up but a directory the web server may
 * write to, and every object is a file a person can read with `cat`. A
 * calendar is made by making a directory, which is what §5.3.1 means when it
 * says a store may have calendars "typically pre-created for each account".
 *
 * **The file is named exactly what the client called the member**, suffix and
 * all, because the member name is the client's to choose and RFC 4791 asks
 * nothing of it. Every file in a calendar's directory is therefore taken to be
 * an object, which §4.2 licenses: a calendar "MUST only contain calendar
 * object resources and collections that are not calendar collections".
 *
 *     $calendars = new CalendarBackend('/var/lib/davservices/calendars');
 *
 * ## A `PROPFIND Depth: 1` reads no object
 *
 * R-BE-02's first case is the listing, and this answers it from the directory
 * entry alone: the size is `filesize()`, the time is `filemtime()`, and the
 * entity tag is made of those two. **No object is opened.** That is why the
 * tag is not a hash of the contents, which would be the obvious choice and
 * would make a listing of a year of events read a file per event.
 *
 * The price is named rather than hidden: two writes inside one second that
 * leave the length unchanged are one entity tag. A store that matters uses a
 * revision its database already keeps; see the warning below.
 *
 * ## The `UID` and the kind are answered as unknown, honestly
 *
 * {@see CalendarObjectInfo::uid()} and {@see CalendarObjectInfo::kind()} are
 * null here, because the only way this store could answer them is by reading
 * the object — the very thing the listing avoids. A server built on this
 * therefore reads the objects to answer `CALDAV:no-uid-conflict`, and that is
 * a cost of this backend rather than of the protocol: a store with a column
 * for the `UID` answers both questions at once.
 *
 * ## A range *does* read, and that is the honest answer (R-CAL-16)
 *
 * > R-CAL-16: `time-range` MUSS im Backend vorgefiltert werden. Vollständige
 * > In-Memory-Expansion DARF NICHT der Regelfall sein.
 *
 * A directory has no index of when anything is, so {@see objectsInRange()}
 * opens the objects. **That is still the prefiltering the requirement asks
 * for**, and the distinction is worth being exact about: what must not happen
 * is the *caller* pulling every object of every calendar into memory and
 * expanding every recurrence to answer a view of one week. Here the store
 * reads its own files once and answers names, and the caller gets a handful
 * instead of a year.
 *
 * A store with an index does the same narrowing without reading anything.
 * Reading is this backend's price, like the `UID` above.
 *
 * **What it narrows by is deliberately crude**: the earliest and latest
 * moments it can read out of the object, with a day's grace at the end.
 * §9.9's table — which properties bound which kind of component, and what the
 * duration of an event with neither `DTEND` nor `DURATION` is — belongs to
 * the layer that answers the report, not to a store. A store that tried to
 * apply it would be a second implementation of the same table, in a layer
 * that cannot see the time zone the calendar resolves floating times
 * against.
 *
 * So anything it cannot bound is **in** the answer: an object it cannot read,
 * one with no readable moment, and every object that recurs. §9.9 is why that
 * is the right way to be wrong:
 *
 * > Time range tests MUST consider every recurrence instance when testing the
 * > time range condition; if any one instance matches, then the test returns
 * > true.
 *
 * A store that answers too much is slow. One that answers too little makes
 * the server drop a client's appointments, and nothing further up could
 * detect it.
 *
 * **This is a reference backend (R-BE-05) and is not to be run in
 * production.** It knows nothing of two requests writing to one object at the
 * same moment, nothing of a filesystem that reorders a rename, its entity tag
 * is as exact as the clock, and a range costs a read per object.
 */
final class CalendarBackend implements ICalendarBackend
{
    /**
     * The grace at the end of what an object is taken to span.
     *
     * §9.9 gives an event with neither `DTEND` nor `DURATION` a duration of
     * "1 day (+P1D) when the DTSTART is a DATE value, and 0 seconds when the
     * DTSTART is a DATE-TIME value". Taking the longer of the two for every
     * object means never having to tell them apart here, and errs the way the
     * class above says to err.
     */
    private const GRACE = 'P1D';

    /**
     * The properties this store bounds an object by.
     *
     * Every one of them is a DATE or DATE-TIME by default in RFC 5545 §3.8,
     * and between them they cover what the kinds of §4.1 are scheduled with:
     * `DTSTART` and `DTEND` an event or a free/busy span (§3.8.2.4, §3.8.2.2),
     * `DUE` a to-do (§3.8.2.3), `RECURRENCE-ID` the instance an override
     * stands for (§3.8.4.4).
     *
     * @var list<string>
     */
    private const BOUNDS = ['DTSTART', 'DTEND', 'DUE', 'RECURRENCE-ID'];

    /**
     * The properties that make an object's span unknowable from its own text.
     *
     * A series runs until its rule says otherwise, and §9.9 asks for every
     * instance to be considered — so an object carrying either of these is in
     * every answer, whatever its first instance looks like.
     *
     * @var list<string>
     */
    private const RECURS = ['RRULE', 'RDATE'];

    /**
     * @param string $directory Where the calendars go; it has to exist,
     *                          because creating directories on a guess is how
     *                          a typo ends up with a calendar store in a web
     *                          root
     *
     * @throws RuntimeException If it is no directory
     */
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory)) {
            throw new RuntimeException(sprintf('"%s" is no directory.', $directory));
        }
    }

    /**
     * The calendars of a principal, which are the directories under its own.
     *
     * @return list<CalendarInfo>
     */
    public function calendars(string $principal): array
    {
        if (!self::isASegment($principal) || !is_dir($this->directory . '/' . $principal)) {
            return [];
        }

        $calendars = [];

        foreach (self::entriesIn($this->directory . '/' . $principal) as $entry) {
            if (is_dir($entry)) {
                $calendars[] = new CalendarInfo(basename($entry));
            }
        }

        return $calendars;
    }

    /**
     * The metadata of every object, from the directory entries alone.
     *
     * @throws NotFound If there is no such calendar
     *
     * @return list<CalendarObjectInfo>
     */
    public function objects(string $principal, string $calendar): array
    {
        $objects = [];

        foreach (self::entriesIn($this->where($principal, $calendar)) as $entry) {
            // **Every file is taken to be an object**, rather than only those
            // with a suffix. A client chooses the member name, and RFC 4791
            // asks nothing of it; §4.2 says a calendar "MUST only contain
            // calendar object resources and collections that are not calendar
            // collections", so a file in here is meant to be an object. A
            // store that filtered by `.ics` would hide one a client called
            // `meeting`.
            if (is_file($entry)) {
                $objects[] = self::describe($entry);
            }
        }

        return $objects;
    }

    /**
     * The metadata of one object, or null where there is none of that name.
     *
     * @throws NotFound If there is no such calendar
     */
    public function object(string $principal, string $calendar, string $name): ?CalendarObjectInfo
    {
        $location = $this->fileFor($principal, $calendar, $name);

        return $location !== null && is_file($location) ? self::describe($location) : null;
    }

    /**
     * The objects that may overlap a range, narrowed by reading them.
     *
     * The reasoning, and what it costs, are on the class above.
     *
     * @throws NotFound If there is no such calendar
     *
     * @return list<CalendarObjectInfo>
     */
    public function objectsInRange(
        string $principal,
        string $calendar,
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $until,
    ): array {
        $found = [];

        foreach ($this->objects($principal, $calendar) as $object) {
            $body = $this->body($principal, $calendar, $object->name());

            if ($body === null || self::mayOverlap($body, $from, $until)) {
                $found[] = $object;
            }
        }

        return $found;
    }

    /**
     * The objects themselves, in one pass over the names asked for.
     *
     * @param list<string> $names
     *
     * @throws NotFound If there is no such calendar
     *
     * @return array<string, string>
     */
    public function bodies(string $principal, string $calendar, array $names): array
    {
        $this->where($principal, $calendar);
        $bodies = [];

        foreach ($names as $name) {
            $object = $this->body($principal, $calendar, $name);

            if ($object !== null) {
                $bodies[$name] = $object;
            }
        }

        return $bodies;
    }

    /**
     * One object as it was stored, or null where there is none of that name.
     *
     * @throws NotFound If there is no such calendar
     */
    public function body(string $principal, string $calendar, string $name): ?string
    {
        $location = $this->fileFor($principal, $calendar, $name);

        if ($location === null || !is_file($location)) {
            return null;
        }

        $object = @file_get_contents($location);

        // @codeCoverageIgnoreStart
        // `is_file()` said yes a moment ago, so a false here is a disc that
        // has gone away under the request. The difference between "no such
        // object" and "this store is broken" is one a client is owed, which is
        // why it is not folded into the null above.
        if ($object === false) {
            throw new RuntimeException(sprintf('"%s" cannot be read.', $location));
        }
        // @codeCoverageIgnoreEnd

        return $object;
    }

    /**
     * Writes an object, replacing one of that name if it is there.
     *
     * **The file is written beside its place and moved in**, so that a write
     * cut in half leaves the old object rather than half of the new one. A
     * client that read it in between gets one of the two, not a fragment.
     *
     * **Narrower than the interface on purpose**: {@see ICalendarBackend::put()}
     * allows null, for a store that normalises what it keeps and therefore may
     * not answer a strong entity tag. This one writes the bytes it was given,
     * byte for byte, so it always has a tag to give.
     *
     * @throws NotFound If there is no such calendar
     * @throws Forbidden If the name is not one this store will take
     *
     * @return string The strong entity tag the object now has
     */
    public function put(string $principal, string $calendar, string $name, string $object): string
    {
        $inside = $this->where($principal, $calendar);

        if (!self::isASegment($name)) {
            throw new Forbidden(sprintf('"%s" is not a name this store takes.', $name));
        }

        $location = $inside . '/' . $name;
        $beside = $location . '.' . bin2hex(random_bytes(8)) . '.part';

        // Both are asked quietly, because both answers are handled right here
        // and a warning beside the exception would say the same thing twice.
        $written = @file_put_contents($beside, $object);

        // @codeCoverageIgnoreStart
        // A fresh random name inside a directory the store has just confirmed
        // is a directory: a false here is the filesystem failing under the
        // request, not a case a test can arrange.
        if ($written === false) {
            @unlink($beside);

            throw new RuntimeException(sprintf('"%s" cannot be written.', $location));
        }
        // @codeCoverageIgnoreEnd

        // The commonest failure of the two, and one a client produces by
        // `PUT`ting an object over the name of a collection: `rename` refuses
        // that rather than emptying it.
        if (!@rename($beside, $location)) {
            @unlink($beside);

            throw new RuntimeException(sprintf('"%s" cannot be written.', $location));
        }

        return self::describe($location)->etag();
    }

    /**
     * Takes an object out.
     *
     * @throws NotFound If there is no such calendar, or no such object
     */
    public function remove(string $principal, string $calendar, string $name): void
    {
        $location = $this->fileFor($principal, $calendar, $name);

        if ($location === null || !is_file($location)) {
            throw new NotFound(sprintf('There is no "%s" in "%s".', $name, $calendar));
        }

        // @codeCoverageIgnoreStart
        // The file was there a moment ago and the directory is the store's
        // own, so a false here is the filesystem refusing something it had
        // just allowed.
        if (!@unlink($location)) {
            throw new RuntimeException(sprintf('"%s" cannot be removed.', $location));
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Whether an object may overlap a range, read out of its own text.
     *
     * **Generous by rule.** Anything this cannot bound is a yes: text it
     * cannot read, an object with no readable moment in it, and anything that
     * recurs. See the class above for why that is the right way to be wrong.
     */
    private static function mayOverlap(string $body, ?DateTimeImmutable $from, ?DateTimeImmutable $until): bool
    {
        $moments = self::momentsIn($body);

        if ($moments === null) {
            return true;
        }

        $starts = min($moments);
        $ends = max($moments)->add(new DateInterval(self::GRACE));

        // §9.9 reads "(start <= DTSTART AND end > DTSTART)" for the shortest
        // kind of event, so the start of the range is inclusive and its end is
        // not. The comparison on the object's own end is the loose one of the
        // two, which is what keeps a zero-length event that begins exactly at
        // `$from` in the answer.
        return ($until === null || $starts < $until) && ($from === null || $ends >= $from);
    }

    /**
     * Every moment this store can read out of an object, or null where it
     * cannot say.
     *
     * @return non-empty-list<DateTimeImmutable>|null Null where it cannot say,
     *                                                never an empty list — a
     *                                                span of no moments is
     *                                                exactly the case it
     *                                                cannot say anything about
     */
    private static function momentsIn(string $body): ?array
    {
        try {
            $read = (new Reader($body))->objects();
            $moments = [];

            foreach ($read as $object) {
                foreach ($object->components() as $component) {
                    foreach (self::RECURS as $property) {
                        if ($component->property($property) !== null) {
                            return null;
                        }
                    }

                    foreach (self::BOUNDS as $property) {
                        $written = $component->property($property)?->value();

                        if ($written === null) {
                            continue;
                        }

                        $moment = self::momentOf($written);

                        // **A moment it cannot read is a span it cannot
                        // know.** Such an object is invalid iCalendar —
                        // `CALDAV:valid-calendar-data` is what refuses it on
                        // the way in — and narrowing by the half that happens
                        // to parse would be guessing about the half that does
                        // not.
                        if ($moment === null) {
                            return null;
                        }

                        $moments[] = $moment;
                    }
                }
            }

            return $moments === [] ? null : $moments;
        } catch (ParseError) {
            return null;
        }
    }

    /**
     * One iCalendar DATE or DATE-TIME as a moment, or null where it is
     * neither.
     *
     * The length tells them apart, which is the grammar's own answer: a DATE
     * is eight digits (RFC 5545 §3.3.4) and a DATE-TIME is a date, a `T` and
     * a time (§3.3.5).
     */
    private static function momentOf(string $raw): ?DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');

        try {
            return strlen($raw) === 8
                ? Date::decode($raw)->at($utc)
                : DateTime::decode($raw)->in($utc);
        } catch (ParseError) {
            return null;
        }
    }

    /**
     * Where a calendar that is there keeps its objects.
     *
     * @throws NotFound If it is not there
     */
    private function where(string $principal, string $calendar): string
    {
        if (self::isASegment($principal) && self::isASegment($calendar)) {
            $inside = $this->directory . '/' . $principal . '/' . $calendar;

            if (is_dir($inside)) {
                return $inside;
            }
        }

        throw new NotFound(sprintf('There is no calendar "%s" of "%s".', $calendar, $principal));
    }

    /**
     * The file an object of that name would be in, or null where the name is
     * one this store would not have written.
     *
     * A name that could reach outside is answered as "no such object" rather
     * than refused: on the way *out* there is nothing to protect but the
     * answer, and a reader asking for `../../etc/passwd` is asking for
     * something that is not an object in this calendar.
     *
     * @throws NotFound If there is no such calendar
     */
    private function fileFor(string $principal, string $calendar, string $name): ?string
    {
        $inside = $this->where($principal, $calendar);

        return self::isASegment($name) ? $inside . '/' . $name : null;
    }

    /**
     * What is known about an object without opening it.
     *
     * The entity tag is the length and the time it changed, which is what a
     * directory entry already holds — see the note on the class about what
     * that buys and what it costs.
     */
    private static function describe(string $location): CalendarObjectInfo
    {
        $size = @filesize($location);
        $changed = @filemtime($location);

        // @codeCoverageIgnoreStart
        // The entry came out of the directory listing, so a false here is an
        // object that was deleted between the listing and this line — a race
        // with another request rather than anything a test can arrange.
        if ($size === false || $changed === false) {
            throw new RuntimeException(sprintf('"%s" cannot be looked at.', $location));
        }
        // @codeCoverageIgnoreEnd

        return new CalendarObjectInfo(
            basename($location),
            sprintf('"%x-%x"', $changed, $size),
            $size,
            // Reading the object is the one thing the listing is here to
            // avoid, so this store says it does not know rather than guessing.
            null,
            null,
            (new DateTimeImmutable())->setTimestamp($changed),
        );
    }

    /**
     * Whether a name is one segment and could not reach outside its
     * directory.
     */
    private static function isASegment(string $name): bool
    {
        if ($name === '' || $name === '.' || $name === '..') {
            return false;
        }

        return strpbrk($name, "/\\\0") === false;
    }

    /**
     * The entries of a directory, in whatever order it hands them over.
     *
     * {@see ICalendarBackend} promises no order, and sorting a thousand
     * members to satisfy nobody would be work thrown away.
     *
     * @return list<string>
     */
    private static function entriesIn(string $directory): array
    {
        $entries = [];

        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
            $entries[] = (string) $entry;
        }

        return $entries;
    }
}
