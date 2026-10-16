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

namespace DavServices\CalDav;

use DateTimeImmutable;
use DateTimeZone;
use DavServices\Exception\Forbidden;
use DavServices\Exception\PayloadTooLarge;
use DavServices\Exception\UnsupportedMediaType;
use DavServices\VObject\Component;
use DavServices\VObject\ParseError;
use DavServices\VObject\Property;
use DavServices\VObject\Recur\Iterator;
use DavServices\VObject\Recur\ObjectSet;
use DavServices\VObject\Recur\TooManyIterations;
use DavServices\VObject\Value\Date;
use DavServices\VObject\Value\DateTime;

/**
 * What a calendar collection will take, and the preconditions for what it will
 * not (RFC 4791 §5.2, §5.3.2.1).
 *
 * **Each limit is a property and a precondition in one.** §5.2.5 to §5.2.9
 * define the properties, and each one says the same thing about breaking them:
 * "MUST result in an error, with the CALDAV:… precondition (Section 5.3.2.1)
 * being violated". That is R-CAL-09 in the memo's own words — a limit reached
 * is a refusal that names itself, never an abort — and R-CAL-08 makes every
 * value configurable, with the ones below as the defaults the requirements
 * fix.
 *
 * ## The comparisons are not all the same shape
 *
 * §5.3.2.1 spells out each test: `max-resource-size` is "less than or equal
 * to", `min-date-time` "greater than or equal to", `max-instances` and
 * `max-attendees-per-instance` "less than or equal to" — and `max-date-time`
 * is "**less than**".
 *
 * **But §5.2.7 calls that same value "the *inclusive* latest date that the
 * server is willing to accept"**, and §5.2.6 calls its counterpart "the
 * earliest *inclusive* date". The two sections disagree about that one
 * boundary, and this library follows §5.2.7: the property is what a client
 * reads before deciding what to send, and a server that refused the value its
 * own property called acceptable would break the promise the client acted on.
 *
 * ## Which values count as a date
 *
 * §5.3.2.1 asks about "all of its iCalendar DATE or DATE-TIME property
 * values", and that sentence draws the line by itself:
 *
 * - a property whose `VALUE` parameter says `DATE` or `DATE-TIME` is one,
 *   whatever its name — which is how `TRIGGER` joins the walk (RFC 5545
 *   §3.8.6.3 makes it a `DURATION` unless the parameter says otherwise);
 * - a property whose `VALUE` parameter says anything else is **not** one, so a
 *   `PERIOD` is left alone — `FREEBUSY` (§3.8.2.6) carries nothing but periods
 *   and is therefore outside what this precondition reaches;
 * - and otherwise the ten properties RFC 5545 gives a DATE or DATE-TIME value
 *   by default.
 *
 * **Recurrence instances need no expanding for this.** §5.2.6 and §5.2.7 both
 * say so: "servers MUST accept recurring components that specify instances
 * beyond this limit, provided none of those instances have been overridden. In
 * that case, the server MAY simply ignore those instances outside of the
 * acceptable range." An overridden instance is a component of its own with its
 * own explicit values, so walking the explicit values covers exactly what the
 * memo asks and nothing more.
 */
final class Limits
{
    /** §5.2.4, §5.3.2.1: the media type is one the collection takes. */
    public const SUPPORTED_DATA = '{urn:ietf:params:xml:ns:caldav}supported-calendar-data';

    /**
     * §5.3.2.1: the kind of component is one the collection takes.
     *
     * The precondition's name is the **singular** one, not the property's —
     * `supported-calendar-component` against
     * `supported-calendar-component-set`.
     */
    public const SUPPORTED_COMPONENT = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component';

    public const MAX_RESOURCE_SIZE = '{urn:ietf:params:xml:ns:caldav}max-resource-size';

    public const MIN_DATE_TIME = '{urn:ietf:params:xml:ns:caldav}min-date-time';

    public const MAX_DATE_TIME = '{urn:ietf:params:xml:ns:caldav}max-date-time';

    public const MAX_INSTANCES = '{urn:ietf:params:xml:ns:caldav}max-instances';

    public const MAX_ATTENDEES = '{urn:ietf:params:xml:ns:caldav}max-attendees-per-instance';

    /** The largest calendar object this server will keep: 10 MB. */
    public const SIZE = 10_485_760;

    public const EARLIEST = '19000101T000000Z';

    public const LATEST = '20991231T235959Z';

    public const INSTANCES = 5_000;

    public const ATTENDEES = 250;

    /**
     * The kinds of component a calendar takes unless it says otherwise.
     *
     * §4.1 names these four as the components a calendar object resource is
     * about. `VTIMEZONE` is deliberately not among them: §5.2.3 keeps that tag
     * for a collection that takes objects of nothing but zones, and
     * {@see CalendarObject::kindsIn()} answers accordingly.
     *
     * @var list<string>
     */
    public const KINDS = ['VEVENT', 'VTODO', 'VJOURNAL', 'VFREEBUSY'];

    /** §5.2.4: iCalendar 2.0, which is what silence means. */
    public const VERSION = '2.0';

    /** §5.2.4: the media type, which is what silence means. */
    public const MEDIA_TYPE = 'text/calendar';

    /**
     * The component whose own dates are local by rule (RFC 5545 §3.6.5), and
     * so have no UTC reading to compare against a UTC limit.
     */
    private const TIME_ZONE = 'VTIMEZONE';

    /**
     * The properties RFC 5545 gives a DATE or DATE-TIME value by default.
     *
     * `DTSTART` §3.8.2.4, `DTEND` §3.8.2.2, `DUE` §3.8.2.3, `COMPLETED`
     * §3.8.2.1, `DTSTAMP` §3.8.7.2, `CREATED` §3.8.7.1, `LAST-MODIFIED`
     * §3.8.7.3, `RECURRENCE-ID` §3.8.4.4, `EXDATE` §3.8.5.1, `RDATE` §3.8.5.2.
     *
     * `UNTIL` is not here because it is not a property: §3.3.10 makes it a part
     * of a recurrence rule's value, and §5.3.2.1 asks about property values.
     *
     * @var list<string>
     */
    private const DATES = [
        'DTSTART', 'DTEND', 'DUE', 'COMPLETED',
        'DTSTAMP', 'CREATED', 'LAST-MODIFIED',
        'RECURRENCE-ID', 'EXDATE', 'RDATE',
    ];

    private readonly DateTimeImmutable $earliestMoment;

    private readonly DateTimeImmutable $latestMoment;

    /**
     * @param int $maxResourceSize §5.2.5, in octets
     * @param string $earliest §5.2.6, an iCalendar DATE-TIME in UTC
     * @param string $latest §5.2.7, an iCalendar DATE-TIME in UTC
     * @param int $maxInstances §5.2.8
     * @param int $maxAttendees §5.2.9, per instance
     * @param list<string> $kinds §5.2.3, the kinds a calendar takes unless one
     *                            of them says otherwise
     *
     * @throws ParseError If a date limit is no iCalendar DATE-TIME. A
     *                    misconfigured server says so when it is put together
     *                    rather than at the first request it cannot answer
     */
    public function __construct(
        private readonly int $maxResourceSize = self::SIZE,
        private readonly string $earliest = self::EARLIEST,
        private readonly string $latest = self::LATEST,
        private readonly int $maxInstances = self::INSTANCES,
        private readonly int $maxAttendees = self::ATTENDEES,
        private readonly array $kinds = self::KINDS,
    ) {
        $this->earliestMoment = DateTime::decode($earliest)->in(new DateTimeZone('UTC'));
        $this->latestMoment = DateTime::decode($latest)->in(new DateTimeZone('UTC'));
    }

    /**
     * The largest object this server will keep, as §5.2.5 publishes it.
     */
    public function maxResourceSize(): int
    {
        return $this->maxResourceSize;
    }

    /**
     * The earliest date it will keep, as §5.2.6 publishes it.
     */
    public function earliest(): string
    {
        return $this->earliest;
    }

    /**
     * The latest date it will keep, as §5.2.7 publishes it.
     */
    public function latest(): string
    {
        return $this->latest;
    }

    /**
     * How many instances one object may generate, as §5.2.8 publishes it.
     */
    public function maxInstances(): int
    {
        return $this->maxInstances;
    }

    /**
     * How many attendees one instance may carry, as §5.2.9 publishes it.
     */
    public function maxAttendees(): int
    {
        return $this->maxAttendees;
    }

    /**
     * The kinds of component this server takes, as §5.2.3 publishes them.
     *
     * @return list<string>
     */
    public function kinds(): array
    {
        return $this->kinds;
    }

    /**
     * Holds an object to every limit, cheapest question first.
     *
     * @param string $bytes The resource as it arrived, which is what §5.2.5
     *                      measures
     * @param list<string> $kinds The kinds the collection it is going into
     *                            takes, which §5.2.3 lets a client narrow per
     *                            calendar
     *
     * @throws PayloadTooLarge If it is larger than §5.2.5 allows
     * @throws UnsupportedMediaType If it is not the iCalendar version §5.2.4
     *                              names
     * @throws Forbidden If a kind, a date, an instance count or an attendee
     *                   count is outside what the collection takes
     */
    public function mustTake(string $bytes, Component $object, array $kinds): void
    {
        if (strlen($bytes) > $this->maxResourceSize) {
            throw new PayloadTooLarge(
                sprintf(
                    'A calendar object is at most %d octets here: the CALDAV:max-resource-size precondition of RFC 4791 §5.3.2.1.',
                    $this->maxResourceSize,
                ),
                self::MAX_RESOURCE_SIZE,
            );
        }

        $this->mustBeTheVersionItKeeps($object);
        $this->mustBeAKindItKeeps($object, $kinds);
        $this->mustNotCrowdAnInstance($object);
        $this->mustBeWithinTheDates($object);
        $this->mustNotGenerateTooMany($object);
    }

    /**
     * §5.2.4: "In the absence of this property, the server MUST only accept
     * data with the media type 'text/calendar' and iCalendar version 2.0."
     *
     * RFC 5545 §3.6 makes `VERSION` mandatory in a `VCALENDAR`, so an object
     * without one is not version 2.0 either.
     *
     * @throws UnsupportedMediaType If it is another version, or none
     */
    private function mustBeTheVersionItKeeps(Component $object): void
    {
        if ($object->property('VERSION')?->value() === self::VERSION) {
            return;
        }

        throw new UnsupportedMediaType(
            sprintf(
                'This server keeps %s version %s: the CALDAV:supported-calendar-data precondition of RFC 4791 §5.3.2.1.',
                self::MEDIA_TYPE,
                self::VERSION,
            ),
            self::SUPPORTED_DATA,
        );
    }

    /**
     * §5.3.2.1: the resource "MUST contain a type of calendar component that
     * is supported in the targeted calendar collection".
     *
     * @param list<string> $kinds
     *
     * @throws Forbidden If it holds a kind the collection does not take
     */
    private function mustBeAKindItKeeps(Component $object, array $kinds): void
    {
        foreach (CalendarObject::kindsIn($object) as $kind) {
            if (in_array($kind, $kinds, true)) {
                continue;
            }

            throw new Forbidden(
                sprintf(
                    'This calendar does not take %s components: the CALDAV:supported-calendar-component precondition of RFC 4791 §5.3.2.1.',
                    $kind,
                ),
                self::SUPPORTED_COMPONENT,
            );
        }
    }

    /**
     * §5.3.2.1: "MUST have a number of ATTENDEE properties on any one instance
     * less than or equal to the value of the
     * CALDAV:max-attendees-per-instance property value".
     *
     * **"On any one instance"**, so the count is per component: two components
     * with two attendees each are not four.
     *
     * @throws Forbidden If one component carries more than the limit
     */
    private function mustNotCrowdAnInstance(Component $object): void
    {
        foreach ($object->components() as $component) {
            if (count($component->properties('ATTENDEE')) <= $this->maxAttendees) {
                continue;
            }

            throw new Forbidden(
                sprintf(
                    'An instance carries at most %d ATTENDEE properties here: the CALDAV:max-attendees-per-instance precondition of RFC 4791 §5.3.2.1.',
                    $this->maxAttendees,
                ),
                self::MAX_ATTENDEES,
            );
        }
    }

    /**
     * §5.3.2.1's two date limits, over every explicit value in the object.
     *
     * @throws Forbidden If a value lies outside the range
     */
    private function mustBeWithinTheDates(Component $object): void
    {
        foreach ($object->components() as $component) {
            $this->mustBeWithinTheDatesInside($component);
        }
    }

    /**
     * Holds one component, and everything inside it, to the two dates.
     *
     * **It descends**, because §5.3.2.1 says "all of its iCalendar DATE or
     * DATE-TIME property values": a `VALARM`'s `TRIGGER` is one of them where
     * the `VALUE` parameter makes it one, and it sits a component deeper than
     * the walk used to reach.
     *
     * **Except into a `VTIMEZONE`'s observances.** RFC 5545 §3.6.5: "The
     * mandatory 'DTSTART' property gives the effective onset date and local
     * time for the time zone sub-component definition. 'DTSTART' in this
     * usage MUST be specified as a date with a local time value." Both limits
     * are UTC — §5.2.6 and §5.2.7 each say so in their first sentence — and
     * the offset that would turn that local time into a UTC one is the very
     * thing the component is defining. There is no value to compare against a
     * UTC limit, so no comparison is made.
     *
     * Real traffic makes that more than a nicety: Exchange writes its
     * observances with `DTSTART:16010101T020000`, which the 1900 floor would
     * refuse over a value that says nothing about when anything happens. The
     * time zone's **own** properties are held to the limits like any others —
     * `LAST-MODIFIED` is a UTC value.
     */
    private function mustBeWithinTheDatesInside(Component $component): void
    {
        foreach ($component->properties() as $property) {
            if (!self::carriesADate($property)) {
                continue;
            }

            foreach (self::momentsIn($property) as $moment) {
                $this->mustBeInRange($moment);
            }
        }

        if (strtoupper($component->name()) === self::TIME_ZONE) {
            return;
        }

        foreach ($component->components() as $inside) {
            $this->mustBeWithinTheDatesInside($inside);
        }
    }

    /**
     * @throws Forbidden If the moment lies outside the range
     */
    private function mustBeInRange(DateTimeImmutable $moment): void
    {
        if ($moment < $this->earliestMoment) {
            throw new Forbidden(
                sprintf(
                    'This server keeps nothing earlier than %s: the CALDAV:min-date-time precondition of RFC 4791 §5.3.2.1.',
                    $this->earliest,
                ),
                self::MIN_DATE_TIME,
            );
        }

        if ($moment > $this->latestMoment) {
            throw new Forbidden(
                sprintf(
                    'This server keeps nothing later than %s: the CALDAV:max-date-time precondition of RFC 4791 §5.3.2.1.',
                    $this->latest,
                ),
                self::MAX_DATE_TIME,
            );
        }
    }

    /**
     * §5.3.2.1: "MUST generate a number of recurring instances less than or
     * equal to the value of the CALDAV:max-instances property value".
     *
     * **A series this server cannot finish expanding is one it will not
     * keep.** R-CAL-08 fixes a hard iteration limit, and a rule that spins
     * without yielding — `FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=30` names a day
     * that never comes — reaches it. There is no way to learn that such a rule
     * generates nothing except by giving up on it, and `max-instances` is the
     * precondition closest to what happened.
     *
     * @throws Forbidden If an object generates more instances than the limit
     */
    private function mustNotGenerateTooMany(Component $object): void
    {
        foreach (self::uidsIn($object) as $uid) {
            try {
                $this->mustNotRunOn($object, $uid);
            } catch (ParseError) {
                // A series this library cannot read is invalid iCalendar,
                // which `CALDAV:valid-calendar-data` is for; naming a number
                // here would send a client looking at the wrong limit.
                continue;
            }
        }
    }

    /**
     * @throws Forbidden If this series generates more instances than the limit
     * @throws ParseError If the series cannot be read
     */
    private function mustNotRunOn(Component $object, string $uid): void
    {
        $counted = 0;

        try {
            foreach (ObjectSet::of($object, $uid, Iterator::ITERATIONS)->instances() as $ignored) {
                ++$counted;

                if ($counted > $this->maxInstances) {
                    throw $this->tooMany();
                }
            }
        } catch (TooManyIterations) {
            throw $this->tooMany();
        }
    }

    private function tooMany(): Forbidden
    {
        return new Forbidden(
            sprintf(
                'A calendar object generates at most %d instances here: the CALDAV:max-instances precondition of RFC 4791 §5.3.2.1.',
                $this->maxInstances,
            ),
            self::MAX_INSTANCES,
        );
    }

    /**
     * The `UID` values the object's components carry, each once.
     *
     * §4.1 has every component of one recurrence set in one resource, so one
     * `UID` is one series — and a component without a `UID` is not one at all,
     * which is `CALDAV:valid-calendar-object-resource`'s to refuse.
     *
     * @return list<string>
     */
    private static function uidsIn(Component $object): array
    {
        $uids = [];

        foreach ($object->components() as $component) {
            $uid = $component->property('UID')?->value();

            if ($uid === null || $uid === '' || in_array($uid, $uids, true)) {
                continue;
            }

            $uids[] = $uid;
        }

        return $uids;
    }

    /**
     * Whether this property's value is a DATE or a DATE-TIME.
     *
     * The `VALUE` parameter decides where it is there (RFC 5545 §3.2.20), and
     * that is what brings a `TRIGGER` into the walk and keeps a `PERIOD` out of
     * it. Otherwise the property's own default type decides.
     */
    private static function carriesADate(Property $property): bool
    {
        $said = strtoupper($property->parameter('VALUE')?->value() ?? '');

        if ($said !== '') {
            return $said === 'DATE' || $said === 'DATE-TIME';
        }

        return in_array(strtoupper($property->name()), self::DATES, true);
    }

    /**
     * The moments one property names, in UTC.
     *
     * A value may be a list — `EXDATE` and `RDATE` are (§3.8.5.1, §3.8.5.2) —
     * and a value this library cannot read is left to
     * `CALDAV:valid-calendar-data` rather than counted as out of range.
     *
     * **A value without a zone is read as UTC.** The limits are centuries
     * wide, so an offset can only matter within hours of a boundary, and
     * resolving a `TZID` would mean resolving the zone the object carries
     * before the object has been accepted.
     *
     * @return list<DateTimeImmutable>
     */
    private static function momentsIn(Property $property): array
    {
        $moments = [];

        foreach (explode(',', $property->value()) as $piece) {
            $moment = self::momentOf($piece);

            if ($moment !== null) {
                $moments[] = $moment;
            }
        }

        return $moments;
    }

    /**
     * One iCalendar DATE or DATE-TIME as a moment, or null where it is
     * neither.
     *
     * The length tells them apart, which is the grammar's own answer: a DATE
     * is eight digits (§3.3.4) and a DATE-TIME is a date, a `T` and a time
     * (§3.3.5).
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
}
