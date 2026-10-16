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

/**
 * What is known about one calendar collection (RFC 4791 §4.2).
 *
 * Five things, and each answers a question the memo asks by name.
 *
 * **The name** is the member name inside the principal's calendar home, which
 * is the last segment of the collection's URL. §5.3.1 lets a server choose it
 * freely, so it need not read as anything in particular — which is why the
 * next one exists.
 *
 * **The display name** is `DAV:displayname`. §5.2's examples put one on every
 * calendar, and a client has nothing else to show a user where the segment is
 * a number.
 *
 * **The description** is `CALDAV:calendar-description` (§5.2.1), "a
 * description of the calendar collection that is suitable for presentation to
 * a user". §5.2.1 says it "MAY be protected", and this library leaves it
 * writable — so somebody has to keep it, and a calendar store is the obvious
 * somebody.
 *
 * **The time zone** is `CALDAV:calendar-timezone` (§5.2.2), which "SHOULD be
 * defined on all calendar collections": the zone a server resolves a floating
 * time against. It is an iCalendar object holding exactly one `VTIMEZONE`, as
 * the memo's own definition says, and it is kept here rather than reduced to a
 * `TZID` because that is what §5.2.2 defines the property to hold.
 *
 * **The kinds** are `CALDAV:supported-calendar-component-set` (§5.2.3), which
 * a client may narrow when it makes the calendar: "clients can initialize the
 * value of this property when creating a new calendar collection with
 * MKCALENDAR". A calendar that narrowed its kinds has to remember it between
 * one request and the next, and the store is where that lives.
 *
 * **Null means not set**, everywhere here, and never "empty". A `PROPFIND` for
 * a property nobody has set is a `404`; a server answering an empty string
 * instead would be claiming the user named their calendar nothing at all. For
 * the kinds, §5.2.3 says what the absence means in as many words: "In the
 * absence of this property, the server MUST accept all component types."
 *
 * It lives in `Dav\Calendars` rather than beside the interface that hands it
 * out, because a backend interface may not reach up into the layer that uses
 * it — the same reason {@see \DavServices\Dav\Cards\AddressBookInfo} and
 * {@see \DavServices\Dav\Acl\PrincipalInfo} live where they do.
 */
final class CalendarInfo
{
    /**
     * @param string $name The member name inside the calendar home
     * @param string|null $displayName `DAV:displayname`, or null where none is
     *                                 set
     * @param string|null $description `CALDAV:calendar-description` (§5.2.1),
     *                                 or null where none is set
     * @param string|null $timeZone `CALDAV:calendar-timezone` (§5.2.2) as the
     *                              iCalendar object the property holds, or
     *                              null where none is set
     * @param list<string>|null $kinds The component kinds this calendar takes
     *                                 (§5.2.3), upper-case as RFC 5545 §3.6
     *                                 names them, or **null where the calendar
     *                                 never narrowed them** — which §5.2.3
     *                                 reads as every kind the server takes
     */
    public function __construct(
        private readonly string $name,
        private readonly ?string $displayName = null,
        private readonly ?string $description = null,
        private readonly ?string $timeZone = null,
        private readonly ?array $kinds = null,
    ) {
    }

    /**
     * The member name inside the calendar home.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * `DAV:displayname`, or null where none is set.
     */
    public function displayName(): ?string
    {
        return $this->displayName;
    }

    /**
     * `CALDAV:calendar-description`, or null where none is set.
     */
    public function description(): ?string
    {
        return $this->description;
    }

    /**
     * `CALDAV:calendar-timezone` as the iCalendar object it holds, or null
     * where none is set.
     */
    public function timeZone(): ?string
    {
        return $this->timeZone;
    }

    /**
     * The kinds this calendar takes, or null where it never narrowed them.
     *
     * @return list<string>|null
     */
    public function kinds(): ?array
    {
        return $this->kinds;
    }
}
