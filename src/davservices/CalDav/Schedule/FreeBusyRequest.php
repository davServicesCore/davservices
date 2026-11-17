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

namespace DavServices\CalDav\Schedule;

use DateTimeImmutable;
use DavServices\CalDav\TimeRange;
use DavServices\Exception\BadRequest;
use DavServices\VObject\Component;
use DavServices\VObject\Mode;
use DavServices\VObject\Property;
use DavServices\VObject\Reader;
use DavServices\VObject\Value\DateTime;
use Throwable;

/**
 * The body of a busy time request (RFC 6638 §5), read and held to iTIP.
 *
 * §5 says what the body is in one sentence and points at iTIP for the rest:
 *
 * > To accomplish this, the request body MUST contain a "VFREEBUSY" calendar
 * > component with the "METHOD" iCalendar property set to the value "REQUEST"
 * > as specified in Section 3.3.2 of iTIP [RFC5546].
 *
 * And §5.2.1 turns the whole of that section into a precondition with a name:
 *
 * > Purpose: (precondition) -- The resource submitted in the POST request
 * > MUST obey all the restrictions specified in Section 3.3.2 of iTIP
 * > [RFC5546].
 *
 * **"All the restrictions" is a table**, so the table is what this class is.
 * A server that checked only the two names §5 says in prose would take a body
 * carrying a `DURATION`, or none carrying a `DTEND`, and would then have to
 * invent what the client meant by it.
 *
 * ## A `0` is as much a restriction as a `1`
 *
 * §3.3.2 gives `FREEBUSY | 0`, `DURATION | 0`, `REQUEST-STATUS | 0` and
 * `URL | 0`. Each says something: a `FREEBUSY` in a request is the answer's
 * property arriving in the question, and a `DURATION` would state the range a
 * second way beside `DTEND | 1`, leaving two statements to disagree. Refusing
 * them is not strictness for its own sake — it is the difference between a
 * server that answers what was asked and one that guesses.
 *
 * ## Both times are UTC, and that excludes two kinds of value
 *
 * The table's comment on `DTSTART` and `DTEND` is "DateTime values must be in
 * UTC", and RFC 5545 §3.8.2.2 says it again of the property itself: "Within
 * the 'VFREEBUSY' calendar component, this property defines the end date and
 * time for the free or busy time information. The time MUST be specified in
 * the UTC time format."
 *
 * So a date without a time is refused — RFC 5545 §3.6.4 allows
 * `DTSTART;VALUE=DATE` in other components, but a *DateTime* value is what
 * this table asks for — and so is a time in a named zone or a floating one,
 * which would leave the range meaning one thing to the client and another to
 * the server.
 *
 * **And the end is after the start**, which is the other MUST of §3.8.2.2:
 * "The value MUST be later in time than the value of the 'DTSTART' property."
 * It is checked here rather than in {@see TimeRange} so that the client is
 * told with §5.2.1's precondition, which is what makes the answer actionable.
 *
 * ## What it does not decide
 *
 * **Whether the organizer may ask.** §3.3.2's comment on `ORGANIZER` is "MUST
 * be the request originator's address", and whether the address is one of the
 * Outbox owner's is §5.2.2's own precondition with its own status — `403`
 * rather than `400`. That is a question about the resource and the person
 * asking, and this class has neither.
 *
 * **Whether a recipient exists.** §5 has the answer carry one
 * `CALDAV:response` per calendar user "with each such element in turn
 * containing elements that indicate ... the scheduling status for that
 * calendar user", so an address nobody is reachable at is reported in the
 * answer rather than refused in the request. Appendix B.5 shows it:
 * `3.7;Invalid calendar user` beside the two that worked.
 */
final class FreeBusyRequest
{
    /**
     * §5.2.1's precondition, used with `400` as that section says.
     */
    public const PRECONDITION = '{urn:ietf:params:xml:ns:caldav}valid-scheduling-message';

    private const FREEBUSY = 'VFREEBUSY';

    private const METHOD = 'METHOD';

    private const REQUEST = 'REQUEST';

    private const ATTENDEE = 'ATTENDEE';

    private const ORGANIZER = 'ORGANIZER';

    private const UID = 'UID';

    private const DTSTART = 'DTSTART';

    private const DTEND = 'DTEND';

    private const DTSTAMP = 'DTSTAMP';

    /**
     * What it gives `0`, inside the `VFREEBUSY` and beside it.
     *
     * @var list<string>
     */
    private const NONE_AT_ALL = ['FREEBUSY', 'DURATION', 'REQUEST-STATUS', 'URL'];

    /**
     * And the components it gives `0` — the alarm inside, the other four
     * beside.
     *
     * @var list<string>
     */
    private const NO_COMPONENT = ['VALARM', 'VEVENT', 'VTODO', 'VJOURNAL', 'VTIMEZONE'];

    /**
     * **Values, not the component.** Everything §5's answer needs of the
     * request is read while the table is being held to — the one `UID`, the
     * one `ORGANIZER`, the attendees and the two ends — so there is nothing
     * left to look up a second time. A request that kept its component would
     * also let whoever holds it change the thing that had just been checked.
     *
     * @param list<string> $recipients
     */
    private function __construct(
        private readonly string $uid,
        private readonly string $organizer,
        private readonly array $recipients,
        private readonly TimeRange $range,
    ) {
    }

    /**
     * The request this body is, or a refusal naming §5.2.1.
     *
     * @throws BadRequest If the body is no iCalendar object, or breaks any
     *                    row of RFC 5546 §3.3.2's table
     */
    public static function of(string $body): self
    {
        $calendar = self::theObjectIn($body);

        self::mustSayItIsARequest($calendar);

        $asked = self::theOneFreeBusyIn($calendar);

        self::mustHaveNoneOfTheForbidden($calendar, $asked);
        self::mustHaveWhatTheTableRequires($asked);

        // Each of these is the row of the table and the reading of it at
        // once. Checking first and reading afterwards would mean a second
        // pass that cannot see what the first proved — and a null test no
        // input could ever reach.
        $uid = self::theOneCalled($asked, self::UID)->value();
        $organizer = self::theOneCalled($asked, self::ORGANIZER)->value();
        $recipients = [];

        self::theOneCalled($asked, self::DTSTAMP);

        foreach ($asked->properties(self::ATTENDEE) as $attendee) {
            $recipients[] = $attendee->value();
        }

        return new self($uid, $organizer, $recipients, self::theRangeOf($asked));
    }

    /**
     * Who is asking — `ORGANIZER | 1`, "MUST be the request originator's
     * address".
     */
    public function organizer(): string
    {
        return $this->organizer;
    }

    /**
     * Whom they are asking about — `ATTENDEE | 1+`, "the calendar user
     * addresses of the 'Calendar Users' whose freebusy is being requested".
     *
     * **In the order they were written, duplicates and all.** §5 has one
     * `CALDAV:response` "for each calendar user", and a server that silently
     * folded two identical `ATTENDEE` properties into one would answer a
     * different question from the one asked — while one that refused them
     * would be adding a row to §3.3.2's table.
     *
     * @return list<string>
     */
    public function recipients(): array
    {
        return $this->recipients;
    }

    /**
     * The range to report over, from `DTSTART` and `DTEND`.
     */
    public function range(): TimeRange
    {
        return $this->range;
    }

    /**
     * The `UID` of the request, which the reply carries again.
     *
     * Appendix B.5 writes it: the answer's `VFREEBUSY` has the same `UID` as
     * the question, and that is how a client pairs the two. RFC 5546 §3.3.3
     * gives `UID | 1` for the reply as well, so there is one to carry.
     */
    public function uid(): string
    {
        return $this->uid;
    }

    /**
     * The one calendar object in the body.
     *
     * **Read strictly.** {@see Mode::Lenient} exists for data already in a
     * store, which a server may not refuse to hand back; this body has just
     * arrived, and §5.2.1 is the sentence that lets it be refused.
     *
     * @throws BadRequest If there is no object, or more than one
     */
    private static function theObjectIn(string $body): Component
    {
        $found = [];

        try {
            foreach ((new Reader($body, Mode::Strict))->objects() as $object) {
                $found[] = $object;
            }
        } catch (Throwable $broken) {
            throw new BadRequest(
                sprintf('A busy time request is an iCalendar object (RFC 6638 §5): %s', $broken->getMessage()),
                self::PRECONDITION,
                $broken,
            );
        }

        if (count($found) !== 1) {
            throw new BadRequest(
                sprintf(
                    'A busy time request is one iCalendar object (RFC 6638 §5), and this body carries %d.',
                    count($found),
                ),
                self::PRECONDITION,
            );
        }

        return $found[0];
    }

    /**
     * `METHOD | 1`, "MUST be REQUEST".
     *
     * @throws BadRequest If it is missing, doubled, or says anything else
     */
    private static function mustSayItIsARequest(Component $calendar): void
    {
        $methods = $calendar->properties(self::METHOD);

        if (count($methods) !== 1) {
            throw new BadRequest(
                sprintf(
                    'RFC 5546 §3.3.2 gives a busy time request METHOD | 1, and this object carries %d.',
                    count($methods),
                ),
                self::PRECONDITION,
            );
        }

        if (strcasecmp($methods[0]->value(), self::REQUEST) !== 0) {
            throw new BadRequest(
                sprintf(
                    'RFC 5546 §3.3.2: the METHOD of a busy time request "MUST be REQUEST", and this one is "%s".',
                    $methods[0]->value(),
                ),
                self::PRECONDITION,
            );
        }
    }

    /**
     * `VFREEBUSY | 1`.
     *
     * @throws BadRequest If there is none, or more than one
     */
    private static function theOneFreeBusyIn(Component $calendar): Component
    {
        $found = $calendar->components(self::FREEBUSY);

        if (count($found) !== 1) {
            throw new BadRequest(
                sprintf(
                    'RFC 5546 §3.3.2 gives a busy time request VFREEBUSY | 1, and this object carries %d.',
                    count($found),
                ),
                self::PRECONDITION,
            );
        }

        return $found[0];
    }

    /**
     * Every row the table gives a `0`.
     *
     * The four properties are looked for **inside** the `VFREEBUSY`, which is
     * where the table puts them, and the four other components beside it;
     * `VALARM` is the one the table indents under `VFREEBUSY`, so that is
     * where it is refused.
     *
     * @throws BadRequest If any of them is there
     */
    private static function mustHaveNoneOfTheForbidden(Component $calendar, Component $asked): void
    {
        foreach (self::NONE_AT_ALL as $name) {
            if ($asked->property($name) !== null) {
                throw new BadRequest(
                    sprintf('RFC 5546 §3.3.2 gives a busy time request %s | 0, and this one carries it.', $name),
                    self::PRECONDITION,
                );
            }
        }

        foreach (self::NO_COMPONENT as $name) {
            $where = $name === 'VALARM' ? $asked : $calendar;

            if ($where->component($name) !== null) {
                throw new BadRequest(
                    sprintf('RFC 5546 §3.3.2 gives a busy time request %s | 0, and this one carries one.', $name),
                    self::PRECONDITION,
                );
            }
        }
    }

    /**
     * The one property of that name, or a refusal.
     *
     * **This is a row of the table and the reading of it in one.** The five
     * rows of exactly `1` are each read where they are checked, so that
     * nothing afterwards has to ask again whether a property it has already
     * been promised is there.
     *
     * @throws BadRequest If there is none of that name, or more than one
     */
    private static function theOneCalled(Component $asked, string $name): Property
    {
        $found = $asked->properties($name);

        if (count($found) !== 1) {
            throw new BadRequest(
                sprintf(
                    'RFC 5546 §3.3.2 gives a busy time request %s | 1, and this one carries %d.',
                    $name,
                    count($found),
                ),
                self::PRECONDITION,
            );
        }

        return $found[0];
    }

    /**
     * The row of `1+` and the row of `0 or 1`.
     *
     * @throws BadRequest If a count is not the one the table gives
     */
    private static function mustHaveWhatTheTableRequires(Component $asked): void
    {
        if ($asked->properties(self::ATTENDEE) === []) {
            throw new BadRequest(
                'RFC 5546 §3.3.2 gives a busy time request ATTENDEE | 1+, and this one names nobody to ask about.',
                self::PRECONDITION,
            );
        }

        $contacts = count($asked->properties('CONTACT'));

        if ($contacts > 1) {
            throw new BadRequest(
                sprintf(
                    'RFC 5546 §3.3.2 gives a busy time request CONTACT | 0 or 1, and this one carries %d.',
                    $contacts,
                ),
                self::PRECONDITION,
            );
        }
    }

    /**
     * The two ends, each a "date with UTC time", the end after the start.
     *
     * @throws BadRequest If either is not UTC, or the end is not later
     */
    private static function theRangeOf(Component $asked): TimeRange
    {
        $from = self::theUtcMomentOf($asked, self::DTSTART);
        $until = self::theUtcMomentOf($asked, self::DTEND);

        // RFC 5545 §3.8.2.2, of a `DTEND` in a `VFREEBUSY`: "The value MUST be
        // later in time than the value of the 'DTSTART' property."
        if ($until <= $from) {
            throw new BadRequest(
                'RFC 5545 §3.8.2.2: the DTEND of a VFREEBUSY "MUST be later in time than the value of the DTSTART property".',
                self::PRECONDITION,
            );
        }

        return TimeRange::between($from, $until);
    }

    /**
     * One end of the range, refused unless it is a date with UTC time.
     *
     * @throws BadRequest If the value is a date, a floating time, or a time
     *                    in a named zone
     */
    private static function theUtcMomentOf(Component $asked, string $name): DateTimeImmutable
    {
        $property = self::theOneCalled($asked, $name);
        $moment = null;

        try {
            $moment = DateTime::decode($property->value())->instant();
        } catch (Throwable) {
            // A value that is no date-time at all — `VALUE=DATE` among them —
            // is the same refusal as one that is not UTC, and the table says
            // it in one comment for both.
            $moment = null;
        }

        if ($moment === null) {
            throw new BadRequest(
                sprintf(
                    'RFC 5546 §3.3.2 has the %s of a busy time request be a DateTime value "in UTC", and this one is "%s".',
                    $name,
                    $property->value(),
                ),
                self::PRECONDITION,
            );
        }

        return $moment;
    }
}
