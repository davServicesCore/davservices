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

namespace DavServices\VObject\Value;

use DateTimeImmutable;
use DateTimeZone;
use DavServices\VObject\ParseError;

/**
 * The `DATE-TIME` value type: a day and a time of day.
 *
 *     date-time = date "T" time
 *
 * **This is where R-TZ-03 lives**, and RFC 5545 §3.3.5 is unusually explicit
 * about why. It names three forms and rules a fourth out:
 *
 * > "The form of date and time with UTC offset MUST NOT be used. For example,
 * > the following is not valid for a DATE-TIME value: `19980119T230000-0800`"
 *
 * - **FORM #1, local time**: "said to be 'floating' and are not bound to any
 *   time zone in particular … the same hour, minute, and second value
 *   regardless of which time zone is currently being observed".
 * - **FORM #2, UTC**: "identified by a LATIN CAPITAL LETTER Z suffix
 *   character".
 * - **FORM #3, local time with a time zone reference**: form #1 plus a `TZID`
 *   parameter — and a parameter belongs to the property, not to the value.
 *
 * **So this tells UTC from local and no more**, which is exactly as much as
 * the value says.
 *
 * ## Why nothing here simply hands over a moment
 *
 * §3.3.5: "The use of local time in a DATE-TIME value without the 'TZID'
 * property parameter is to be interpreted as floating time, **regardless of
 * the existence of 'VTIMEZONE' calendar components** in the iCalendar
 * object."
 *
 * A floating time has no instant until a zone is named. {@see self::instant()}
 * therefore answers null for anything that is not UTC, and {@see self::in()}
 * is where the zone gets named by a caller who can be held to it. Reading a
 * floating time as UTC is the fault that moves every appointment in a file by
 * the offset of wherever the server happens to stand.
 */
final class DateTime
{
    private const SEPARATOR = 'T';

    private function __construct(
        private readonly Date $date,
        private readonly Time $time,
    ) {
    }

    /**
     * The day and time a raw value names.
     *
     * @throws ParseError If it is no date-time, which includes the offset
     *                    form §3.3.5 forbids: there is no `T` in
     *                    `19980119T230000-0800` for the offset to hide behind,
     *                    so the time half is simply no time
     */
    public static function decode(string $raw): self
    {
        $at = strpos($raw, self::SEPARATOR);

        if ($at === false) {
            throw new ParseError(sprintf('"%s" is no date and time: there is no T between them.', $raw));
        }

        return new self(Date::decode(substr($raw, 0, $at)), Time::decode(substr($raw, $at + 1)));
    }

    /**
     * The day half.
     */
    public function date(): Date
    {
        return $this->date;
    }

    /**
     * The time half.
     */
    public function time(): Time
    {
        return $this->time;
    }

    /**
     * Whether this is FORM #2, the one the `Z` fixes to UTC.
     *
     * Anything else is local time — floating unless the property carries a
     * `TZID`, which this value cannot see.
     */
    public function isUtc(): bool
    {
        return $this->time->isUtc();
    }

    /**
     * The moment this names, or null where it names none.
     *
     * **Null is the honest answer for a local time** (R-TZ-03): it is the
     * same wall clock everywhere, and which instant that is depends on a zone
     * nobody has named yet. Use {@see self::in()} and name one.
     */
    public function instant(): ?DateTimeImmutable
    {
        return $this->isUtc() ? $this->in(new DateTimeZone('UTC')) : null;
    }

    /**
     * This value read in a named zone.
     *
     * A local value keeps its wall clock and takes the zone's offset, which
     * is what FORM #1 and FORM #3 both mean. A UTC value keeps its instant
     * and is merely said differently, because the `Z` has already fixed it.
     */
    public function in(DateTimeZone $zone): DateTimeImmutable
    {
        $wall = sprintf(
            '%04d-%02d-%02d %02d:%02d:%02d',
            $this->date->year(),
            $this->date->month(),
            $this->date->day(),
            $this->time->hour(),
            $this->time->minute(),
            $this->time->second(),
        );

        if (!$this->isUtc()) {
            return new DateTimeImmutable($wall, $zone);
        }

        return (new DateTimeImmutable($wall, new DateTimeZone('UTC')))->setTimezone($zone);
    }

    /**
     * The raw value, as the grammar has it.
     */
    public function encode(): string
    {
        return $this->date->encode() . self::SEPARATOR . $this->time->encode();
    }
}
