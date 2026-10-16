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
use DavServices\Backend\ICalendarBackend;
use DavServices\Dav\Calendars\CalendarInfo;
use DavServices\Dav\Calendars\CalendarObjectInfo;
use DavServices\Exception\Forbidden;
use DavServices\Exception\NotFound;

/**
 * A calendar store that lives in an array, for the tests of the layers above.
 *
 * It exists for two reasons. The CalDAV reports still to come need a store
 * they can set up in a line, without a directory and without cleaning one up.
 * And a contract suite with one implementation proves nothing: two make the
 * difference between "this is what a calendar store does" and "this is what
 * that one class happens to do".
 *
 * **It counts how often a body was read**, which is how a test can show that
 * {@see objects()} answers a `PROPFIND Depth: 1` without touching a single
 * object — the promise R-BE-02 is made of.
 *
 * **And it keeps no index, so a range gets everything** (R-CAL-16). That is
 * the conformant answer for a store with nothing to narrow by, and the
 * contract has to allow it: §9.9 leaves the final test to the caller, so a
 * store that answers too much is slow rather than wrong.
 * {@see \DavServices\Backend\File\CalendarBackend} is the one that narrows,
 * and having both under one contract is what shows the cut is a cut and not a
 * single implementation's habit.
 */
final class MemoryCalendarBackend implements ICalendarBackend
{
    /** How often an object's bytes have been handed out. */
    public int $bodyReads = 0;

    /** How often a range was asked for, so a caller can show it was used. */
    public int $rangeQueries = 0;

    /** @var array<string, array<string, CalendarInfo>> */
    private array $calendars = [];

    /** @var array<string, array<string, array<string, string>>> */
    private array $objects = [];

    /**
     * Puts a calendar there, as provisioning would.
     */
    public function provision(string $principal, CalendarInfo $calendar): self
    {
        $this->calendars[$principal][$calendar->name()] = $calendar;
        $this->objects[$principal][$calendar->name()] ??= [];

        return $this;
    }

    public function calendars(string $principal): array
    {
        return array_values($this->calendars[$principal] ?? []);
    }

    public function objects(string $principal, string $calendar): array
    {
        $objects = [];

        foreach ($this->inside($principal, $calendar) as $name => $object) {
            $objects[] = $this->describe($name, $object);
        }

        return $objects;
    }

    public function object(string $principal, string $calendar, string $name): ?CalendarObjectInfo
    {
        $object = $this->inside($principal, $calendar)[$name] ?? null;

        return $object === null ? null : $this->describe($name, $object);
    }

    /**
     * Everything, because this store keeps no index of when anything is.
     *
     * The reasoning is in the class above, and in
     * {@see ICalendarBackend::objectsInRange()}: a superset is a correct
     * answer, a subset never is.
     */
    public function objectsInRange(
        string $principal,
        string $calendar,
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $until,
    ): array {
        ++$this->rangeQueries;

        return $this->objects($principal, $calendar);
    }

    public function bodies(string $principal, string $calendar, array $names): array
    {
        $inside = $this->inside($principal, $calendar);
        $bodies = [];

        foreach ($names as $name) {
            if (isset($inside[$name])) {
                ++$this->bodyReads;
                $bodies[$name] = $inside[$name];
            }
        }

        return $bodies;
    }

    public function body(string $principal, string $calendar, string $name): ?string
    {
        $object = $this->inside($principal, $calendar)[$name] ?? null;

        if ($object !== null) {
            ++$this->bodyReads;
        }

        return $object;
    }

    public function put(string $principal, string $calendar, string $name, string $object): string
    {
        $this->inside($principal, $calendar);

        if ($name === '' || $name === '.' || $name === '..' || strpbrk($name, "/\\\0") !== false) {
            throw new Forbidden(sprintf('"%s" is not a name this store takes.', $name));
        }

        $this->objects[$principal][$calendar][$name] = $object;

        return self::etagOf($object);
    }

    public function remove(string $principal, string $calendar, string $name): void
    {
        if (!isset($this->inside($principal, $calendar)[$name])) {
            throw new NotFound(sprintf('There is no "%s" in "%s".', $name, $calendar));
        }

        unset($this->objects[$principal][$calendar][$name]);
    }

    /**
     * The objects of a calendar that is there.
     *
     * @throws NotFound If it is not
     *
     * @return array<string, string>
     */
    private function inside(string $principal, string $calendar): array
    {
        if (!isset($this->objects[$principal][$calendar])) {
            throw new NotFound(sprintf('There is no calendar "%s" of "%s".', $calendar, $principal));
        }

        return $this->objects[$principal][$calendar];
    }

    /**
     * What is known about an object without handing its bytes out — so the
     * counter stays where it is.
     */
    private function describe(string $name, string $object): CalendarObjectInfo
    {
        return new CalendarObjectInfo(
            $name,
            self::etagOf($object),
            strlen($object),
            self::lineIn($object, 'UID:'),
            self::kindIn($object),
            new DateTimeImmutable('2026-01-01 00:00:00'),
        );
    }

    private static function etagOf(string $object): string
    {
        return '"' . substr(hash('sha256', $object), 0, 32) . '"';
    }

    /**
     * The one kind the object holds, which §4.1 makes one per resource — kept
     * beside the object so that `CALDAV:comp-filter` can be answered from the
     * index.
     */
    private static function kindIn(string $object): ?string
    {
        foreach (['VEVENT', 'VTODO', 'VJOURNAL', 'VFREEBUSY'] as $kind) {
            if (str_contains($object, 'BEGIN:' . $kind)) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * The value of one property, so that §5.3.2.1's `no-uid-conflict` can be
     * answered for a whole calendar without reading any of them.
     */
    private static function lineIn(string $object, string $property): ?string
    {
        foreach (explode("\r\n", $object) as $line) {
            if (str_starts_with($line, $property)) {
                return substr($line, strlen($property));
            }
        }

        return null;
    }
}
