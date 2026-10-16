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
use DavServices\VObject\Component;
use DavServices\VObject\ParseError;
use DavServices\VObject\Property;
use DavServices\VObject\TimeZone\Named;
use DavServices\VObject\TimeZone\Resolver;
use DavServices\VObject\TimeZone\Zones;
use DavServices\VObject\Value\Date;
use DavServices\VObject\Value\DateTime;

/**
 * What moment a dated property names, read in UTC.
 *
 * **The question RFC 4791 §9.9 asks of every property in its tables**, and
 * §7.3 is what makes it a question at all. An iCalendar value may be any of
 * three things (RFC 5545 §3.3.5): a UTC time, which needs nobody's help; a
 * local time in a zone a `TZID` names, which needs that zone; or a floating
 * one, which is no instant until somebody picks a zone for it.
 *
 * ## Who picks the zone, and why it is not this class
 *
 * §7.3 says, for `calendar-query`:
 *
 * > CalDAV servers MUST rely on the value of the CALDAV:timezone XML element,
 * > if specified as part of the request body, to perform the proper conversion
 * > of "floating date" and "floating time" values to date with UTC time
 * > values. If the CALDAV:timezone XML element is not specified in the request
 * > body, CalDAV servers MUST rely on the value of the CALDAV:calendar-timezone
 * > property, if defined, or else the CalDAV servers MAY rely on the time zone
 * > of their choice.
 *
 * Three sources in order, and which one applies is a question about the
 * request and the collection. So the zone arrives already chosen, and this
 * uses it rather than deciding.
 *
 * ## An all-day value becomes an instant here, and only here
 *
 * R-TZ-04 keeps that conversion out of the `VObject` layer —
 * {@see \DavServices\VObject\Recur\Zone::momentOf()} refuses a `DATE`
 * outright — because a date is not a moment and a reader that pretended
 * otherwise would be answering a question nobody asked. §7.3 is where the
 * question is asked: a "floating date" is converted "in the processing of
 * calendaring REPORT requests", with the zone above. So the conversion lives
 * in the CalDAV layer, which is the layer that has a zone to do it with.
 *
 * ## Null is an answer
 *
 * A property that is not there, and a value this library cannot read, both
 * name no moment. §9.9's tables ask "has the DTSTART property?" of every row,
 * so absence is the ordinary case rather than the odd one — and a filter that
 * threw on one unreadable object in a calendar would answer nothing about the
 * other thousand.
 */
final readonly class Moments
{
    /**
     * @param Component $object The calendar object, for the `VTIMEZONE`
     *                          components it carries
     * @param DateTimeZone $floating The zone a floating value is read in,
     *                               chosen as §7.3 says
     */
    public function __construct(
        private Component $object,
        private DateTimeZone $floating,
    ) {
    }

    /**
     * The moment this property names, in UTC, or null where it names none.
     *
     * The answer is always in UTC, which is the one thing §9.9's tables are
     * written against: "Both attributes MUST be specified as 'date with UTC
     * time' value."
     */
    public function of(?Property $property): ?DateTimeImmutable
    {
        if ($property === null) {
            return null;
        }

        $written = $property->value();

        try {
            $moment = self::isADate($property)
                ? Date::decode($written)->at($this->floating)
                : $this->instantOf($property, DateTime::decode($written));
        } catch (ParseError) {
            return null;
        }

        // **Answered in UTC, whichever road the value came by.** Comparing is
        // indifferent to the zone an object carries, but §9.9's own tables are
        // written against "date with UTC time" values, and an answer that
        // sometimes carried a local zone would make every reader of it decide
        // again.
        return $moment?->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Whether this property's value is a `DATE` rather than a `DATE-TIME`.
     *
     * **The `VALUE` parameter decides where it is there** (§3.2.20), and the
     * length decides where it is not: §3.3.4 gives a DATE the form
     * `date-fullyear date-month date-mday`, which is eight digits, and clients
     * leave the parameter out.
     */
    public static function isADate(Property $property): bool
    {
        $said = strtoupper($property->parameter('VALUE')?->value() ?? '');

        if ($said !== '') {
            return $said === 'DATE';
        }

        return strlen($property->value()) === 8;
    }

    /**
     * One `DATE-TIME` as an instant: itself where it is UTC, in the zone its
     * `TZID` names where that can be read, and in the chosen zone otherwise.
     *
     * **A `TZID` nothing names and nothing carries leaves the value
     * floating.** That is what {@see Zones} answers — null, "never UTC" — and
     * §9.9 still has to compare the value against a range, so the chosen zone
     * is what is left. Reading it as UTC instead would move an appointment by
     * hours without saying so.
     */
    private function instantOf(Property $property, DateTime $written): ?DateTimeImmutable
    {
        $instant = $written->instant();

        if ($instant !== null) {
            return $instant;
        }

        $zone = $this->zoneOf($property) ?? Named::of($this->floating);

        return $zone->momentOf($written);
    }

    /**
     * The zone this property's own `TZID` names, or null where it names none
     * this library can read.
     *
     * **Per property, not per component.** §9.9 compares `DTEND` and `DUE`
     * beside `DTSTART`, and §3.2.19 puts the parameter on each of them
     * separately — so a `DTEND` in another zone than its `DTSTART` is a thing
     * a client may write, and {@see Zones::of()} answers for the `DTSTART`
     * alone.
     */
    private function zoneOf(Property $property): ?\DavServices\VObject\Recur\Zone
    {
        $tzid = $property->parameter('TZID')?->value();

        if ($tzid === null || $tzid === '') {
            return null;
        }

        $named = Resolver::forName($tzid);

        if ($named !== null) {
            return Named::of($named);
        }

        return self::carriedBy($this->object, $tzid);
    }

    /**
     * The zone one of the object's own `VTIMEZONE` components defines, or
     * null.
     *
     * The name is matched exactly, as §3.2.19 asks: "The value of the 'TZID'
     * property parameter will be equal to the value of the 'TZID' property for
     * the matching time zone definition", and §3.1 makes property values
     * case-sensitive.
     */
    private static function carriedBy(Component $object, string $tzid): ?\DavServices\VObject\Recur\Zone
    {
        foreach ($object->components('VTIMEZONE') as $zone) {
            if ($zone->property('TZID')?->value() !== $tzid) {
                continue;
            }

            $named = Resolver::resolve($zone);

            if ($named !== null) {
                return Named::of($named);
            }

            return \DavServices\VObject\TimeZone\Definition::of($zone);
        }

        return null;
    }
}
