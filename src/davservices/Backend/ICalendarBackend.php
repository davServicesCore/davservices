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

namespace DavServices\Backend;

use DateTimeImmutable;
use DavServices\Dav\Calendars\CalendarInfo;
use DavServices\Dav\Calendars\CalendarObjectInfo;
use DavServices\Exception\Forbidden;
use DavServices\Exception\NotFound;

/**
 * Where the calendars and calendar objects of RFC 4791 come from (R-BE-01).
 *
 * A directory, a database, whatever the application already has. The protocol
 * does not care: `CalDav\Calendar` makes a collection a calendar, this says
 * where its contents live, and the two never meet — a backend interface may
 * not reach up into the layer that uses it.
 *
 * ## Two requirements shape it
 *
 * > R-BE-02: Die Schnitte MÜSSEN N+1-Abfragen vermeidbar machen: Batch-Methoden
 * > für Multiget und für gemeinsame Metadaten bei `PROPFIND Depth: 1`.
 *
 * So there are pairs rather than single methods. {@see objects()} answers the
 * metadata of a whole calendar in one call and {@see object()} answers one;
 * {@see bodies()} answers many objects in one call and {@see body()} answers
 * one. The batched half of each pair is the one the protocol reaches for:
 * `PROPFIND Depth: 1` wants every entity tag at once, and
 * `CALDAV:calendar-multiget` (§7.9) wants a month of events at once.
 *
 * > R-CAL-16: `time-range` MUSS im Backend vorgefiltert werden. Vollständige
 * > In-Memory-Expansion DARF NICHT der Regelfall sein.
 *
 * That is what {@see objectsInRange()} is for, and it is the one cut here that
 * is not simply the card interface with new nouns. A calendar is the one
 * collection a client asks a *question* of rather than listing: "what is in
 * March", not "what is there". A store with an index can answer that from the
 * index; without this method it could not be asked at all, and the server
 * would have to read every object of every calendar to answer a view of one
 * week.
 *
 * ## What `objectsInRange()` promises, and what it does not
 *
 * **A store may answer too much. It must never answer too little.**
 *
 * That asymmetry is not a convenience, it is what §9.9 makes of the question:
 *
 * > Time range tests MUST consider every recurrence instance when testing the
 * > time range condition; if any one instance matches, then the test returns
 * > true. Testing recurrence instances requires the server to infer an
 * > effective value for DTSTART, DTEND, DURATION, and DUE properties for an
 * > instance based on the recurrence patterns and any overrides.
 *
 * A store that keeps the first instance's `DTSTART` in a column cannot know
 * whether the five hundredth instance of a weekly series falls in March — not
 * without doing the expansion this method exists to avoid. So the answer is a
 * **superset**: everything that might overlap. The caller applies §9.9's own
 * table to what comes back, which it has to do anyway, because §9.9 puts the
 * duty on the server: "the server MUST only return the calendar object
 * resources that […] have a component or property whose value intersects a
 * specified time range."
 *
 * A store with no index at all therefore has a correct implementation
 * available in one line — answer everything, as {@see objects()} does. It is
 * slow rather than wrong, which is the right way round: a backend that guessed
 * and answered too little would make the server drop a client's events, and
 * nothing further up could detect it.
 *
 * ## What an implementation owes (R-BE-03)
 *
 * Every method below says its preconditions, what it answers, and what it
 * throws. The same rules run through all of them as through
 * {@see ICardBackend}, because they are rules about stores rather than about
 * calendars:
 *
 * - **A missing *thing* is `null` or an empty list; a missing *container* is
 *   `NotFound`.** A calendar with nothing in it is a perfectly good calendar,
 *   and a store that threw would make "nothing here yet" indistinguishable
 *   from "something is broken". A calendar nobody made is a `404`, because the
 *   server must not report a collection that is not there.
 * - **A member name is one path segment.** A store refuses anything that could
 *   reach outside the calendar it is writing to, with `Forbidden`.
 * - **A path that merely begins the same way is a different path.** `work2`
 *   does not lie inside `work`; a store that thought otherwise would hand out
 *   somebody else's appointments.
 * - **No order is promised.** A store answers in whatever order its index has;
 *   `PROPFIND` does not ask for one and sorting a thousand members to satisfy
 *   nobody would be work thrown away. A caller that wants an order applies
 *   one.
 * - **A calendar is named inside a principal's home, not globally.** Every
 *   method is told whose it is, because two people both calling a calendar
 *   `work` is the ordinary case rather than the exotic one — and a store keyed
 *   on the name alone would serve one of them the other's appointments.
 *
 * `tests/unit/Backend/CalendarBackendContract.php` is the executable form of
 * all of that (R-BE-04), and every implementation that ships here runs it.
 *
 * **The reference implementations are not for production** (R-BE-05).
 */
interface ICalendarBackend
{
    /**
     * The calendars this principal owns.
     *
     * What `CALDAV:calendar-home-set` (§6.2.1) points at the collection of,
     * and what a `PROPFIND Depth: 1` on that collection lists.
     *
     * **A principal the store knows nothing about has none**, which is not an
     * error: an account whose calendars have not been provisioned yet is an
     * ordinary state, and §5.3.1 makes `MKCALENDAR` support only RECOMMENDED
     * precisely because "some calendar stores only support one calendar per
     * user (or principal), and those are typically pre-created for each
     * account".
     *
     * @param string $principal The principal's name, as
     *                          `Acl\PrincipalCollection` holds it
     *
     * @return list<CalendarInfo> Empty where the principal owns none
     */
    public function calendars(string $principal): array;

    /**
     * The metadata of every object in a calendar, in one call.
     *
     * **This is the `PROPFIND Depth: 1` half of R-BE-02.** It answers without
     * the bodies, because that is what the listing needs and all it needs.
     *
     * @param string $principal Whose calendar it is
     * @param string $calendar The calendar's member name inside the home
     *
     * @throws NotFound If there is no such calendar
     *
     * @return list<CalendarObjectInfo> Empty where the calendar holds nothing
     */
    public function objects(string $principal, string $calendar): array;

    /**
     * The metadata of one object, or null where there is none of that name.
     *
     * @throws NotFound If there is no such calendar
     */
    public function object(string $principal, string $calendar, string $name): ?CalendarObjectInfo;

    /**
     * The objects that may overlap a time range (R-CAL-16).
     *
     * **A superset is a correct answer; a subset never is.** The reasoning is
     * in the class above: §9.9 requires every recurrence instance to be
     * considered, which a store cannot do from an index, so this narrows and
     * the caller decides. A store with nothing to narrow by answers everything
     * {@see objects()} would.
     *
     * **The range is two moments in UTC**, which is what §9.9 asks for —
     * "Both attributes MUST be specified as 'date with UTC time' value" — and
     * it arrives as two of them rather than as a `CalDav\TimeRange` because
     * `Backend` lies below `CalDav` and may not reach up for it.
     *
     * `$from` is **inclusive** and `$until` is **not**, as §9.9 defines the
     * attributes it comes from: "The 'start' attribute specifies the inclusive
     * start of the time range, and the 'end' attribute specifies the
     * non-inclusive end of the time range."
     *
     * **Either end may be open**, and null is how: §9.9 says "Time ranges open
     * at one end can be specified by including only one attribute […] If
     * either the 'start' or 'end' attribute is not specified in the
     * CALDAV:time-range XML element, assume '-infinity' and '+infinity' as
     * their value". Both open at once is not a range the memo allows — "at
     * least one attribute MUST always be present" — and a caller that asks for
     * it is asking for {@see objects()}, which is what a store answers.
     *
     * @param DateTimeImmutable|null $from The inclusive start, or null for
     *                                     "-infinity"
     * @param DateTimeImmutable|null $until The non-inclusive end, or null for
     *                                      "+infinity"
     *
     * @throws NotFound If there is no such calendar
     *
     * @return list<CalendarObjectInfo> Every object that may overlap, and
     *                                  possibly more
     */
    public function objectsInRange(
        string $principal,
        string $calendar,
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $until,
    ): array;

    /**
     * The objects themselves, by member name, in one call.
     *
     * **This is the multiget half of R-BE-02.** §7.9's
     * `CALDAV:calendar-multiget` names the resources it wants in one request,
     * and a store asked for them one at a time would turn one request into
     * forty.
     *
     * **A name the calendar does not hold is left out** rather than answered
     * with null, because §7.9 has the report answer each href on its own
     * account: a `404` for one of forty is a `propstat` and not a failure of
     * the request, and a caller comparing what it asked for against what came
     * back knows which were missing.
     *
     * @param list<string> $names The member names wanted
     *
     * @throws NotFound If there is no such calendar
     *
     * @return array<string, string> The objects by member name, missing ones
     *                               left out
     */
    public function bodies(string $principal, string $calendar, array $names): array;

    /**
     * One object, or null where there is none of that name.
     *
     * @throws NotFound If there is no such calendar
     */
    public function body(string $principal, string $calendar, string $name): ?string;

    /**
     * Stores an object, making it or replacing it, and says what it now has.
     *
     * **The entity tag comes back**, because §5.3.4 asks for it by name:
     * "Servers SHOULD return a strong entity tag (ETag header) in a PUT
     * response when the stored calendar object resource is equivalent by octet
     * equality to the calendar object resource submitted in the body of the
     * PUT request." A store that stored and said nothing would make the server
     * read the object back to answer its own request.
     *
     * **Null means the stored bytes are not the submitted bytes**, and in that
     * case saying nothing is not a shortcoming but the rule: §5.3.4 goes on,
     * "In the case where the data stored by a server as a result of a PUT
     * request is not equivalent by octet equality to the submitted calendar
     * object resource, the behavior of the ETag response header is not
     * specified here, with the exception that a strong entity tag MUST NOT be
     * returned in the response." So a store that normalises what it keeps
     * answers null, and the caller answers without an `ETag` rather than
     * inventing one.
     *
     * None of that touches `DAV:getetag`, which §5.3.4 opens by making a MUST:
     * "The DAV:getetag property MUST be defined and set to a strong entity tag
     * on all calendar object resources." A store answers that from
     * {@see object()}, whatever it said here.
     *
     * @param string $object The iCalendar object, exactly as it is to be kept.
     *                       **A store does not validate it**: whether it is
     *                       one object, of a kind this calendar takes, within
     *                       every limit, is §5.3.2.1's business and
     *                       `Plugin\CalDav` has already asked it. A store that
     *                       asked again would be answering a different
     *                       question, in a layer that cannot see the calendar's
     *                       properties
     *
     * @throws NotFound If there is no such calendar
     * @throws Forbidden If the name is not one this store will keep
     *
     * @return string|null The entity tag it now has, or null where the store
     *                     keeps none
     */
    public function put(string $principal, string $calendar, string $name, string $object): ?string;

    /**
     * Takes an object out.
     *
     * **An object that is not there is `NotFound` rather than nothing done.**
     * A `DELETE` on a resource that has gone is a `404` (RFC 4918 §9.6), and a
     * store that shrugged would have the server answer `204` for an
     * appointment it never had.
     *
     * @param string $principal The principal who owns it
     * @param string $calendar Its name inside that principal's home
     * @param string $name The member name inside it
     *
     * @throws NotFound If there is no calendar of that name, or no object of
     *                  that name in it
     * @throws Forbidden If the name is not one this store will keep
     */
    public function remove(string $principal, string $calendar, string $name): void;
}
