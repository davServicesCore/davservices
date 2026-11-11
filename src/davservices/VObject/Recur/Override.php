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

namespace DavServices\VObject\Recur;

use DateTimeImmutable;
use DateTimeZone;
use DavServices\VObject\Component;
use DavServices\VObject\ParseError;
use DavServices\VObject\Property;
use DavServices\VObject\Value\Date;
use DavServices\VObject\Value\DateTime;

/**
 * The component that overrides one instance of a series (RFC 5545 §3.8.4.4).
 *
 * RFC 4791 §4.1 gives the two words this is between: the **master** "defines
 * the recurrence 'set' and does not contain any RECURRENCE-ID property", and an
 * **overridden** instance "modif[ies] the behavior of a regular instance, and
 * thus include[s] a RECURRENCE-ID property".
 *
 * This makes the second out of the first, for an instance nothing has
 * overridden yet — a component that says exactly what the series already said
 * about that instance, and that a caller can then change one thing in.
 *
 * ## Where the instance is, and how long it lasts
 *
 * §3.8.4.4 says the first: "The property value is the original value of the
 * 'DTSTART' property of the recurrence instance." So the identifier *is* that
 * start, as long as nothing has moved the instance — and nothing has, because
 * the component that moved it would be the one overriding it, and there is
 * none.
 *
 * §3.8.5.3 says the second:
 *
 * > If the duration of the recurring component is specified with the "DTEND"
 * > or "DUE" property, then the computed end time or due time of each instance
 * > is the same **nominal** duration as the initial instance.
 *
 * **Nominal, so the arithmetic is wall clock** — the frame §3.3.10 counts a
 * recurrence in, and the same reading {@see ObjectSet} makes of a series it
 * shifts. An hour-long instance is an hour long on the day the clocks change
 * too, and a component that says its length as a `DURATION` has nothing to
 * move.
 *
 * ## The recurrence properties stay with the series
 *
 * One instance is not a series of its own: §3.8.4.4 has the overriding
 * component modify "the behavior of a regular instance" rather than define a
 * set of its own, and the set it belongs to is the master's. `RRULE`, `RDATE`
 * and `EXDATE` therefore stay behind — and `EXRULE` with them, withdrawn in
 * §3.8.5.2 though an object written years ago may carry one.
 *
 * ## Nothing is shared with the master
 *
 * What comes back is the caller's to change, which is what it is for, and a
 * {@see Property} is mutable in its parameters. So every property is built
 * again rather than handed over: a `PARTSTAT` written on the instance that
 * reached the series would answer for every instance of it.
 *
 * A {@see \DavServices\VObject\Parameter} is read-only through and through, so
 * the ones that travel are shared rather than rebuilt.
 */
final class Override
{
    /**
     * The four properties that define a recurrence set (§3.8.5), `EXRULE`
     * among them for the objects that still carry one.
     *
     * @var list<string>
     */
    private const RECURRENCE = ['RRULE', 'RDATE', 'EXDATE', 'EXRULE'];

    private const START = 'DTSTART';

    /**
     * The two properties §3.8.5.3 names as the ones that move with the start:
     * "the computed end time or due time of each instance".
     *
     * @var list<string>
     */
    private const ENDS = ['DTEND', 'DUE'];

    private const RECURRENCE_ID = 'RECURRENCE-ID';

    private const UTC = 'UTC';

    /**
     * The master as the instance that property names, or null where it names
     * no instance of it.
     *
     * Null is one answer for several readings that all come to the same thing:
     * a master with no `DTSTART` is no recurrence set — §3.8.5.3 has the set
     * "starting with the 'DTSTART' property value" — a value this library
     * cannot read is no moment, and an identifier of the other value type is
     * no instance of this series: §3.8.4.4 has it "MUST be the same as the
     * 'DTSTART' property contained within the recurring component", and a day
     * is not a moment.
     *
     * **The identifier is written under the name §3.8.4.4 gives it**, whatever
     * the caller read the instance's name off: RFC 6638 §3.2.2.3 has an
     * attendee name an instance by adding "an 'EXDATE' property value", and
     * RFC 5545 §3.8.5.1 makes those values the same starts this property
     * names.
     *
     * @param Component $master The component that defines the set, which is
     *                          left exactly as it was
     * @param Property $named The property whose value names the instance
     */
    public static function of(Component $master, Property $named): ?Component
    {
        $start = $master->property(self::START);

        if ($start === null) {
            return null;
        }

        try {
            $was = self::valueOf($start);
            $is = self::valueOf($named);

            // §3.8.4.4: "The value type of this property MUST be the same as
            // the 'DTSTART' property contained within the recurring
            // component."
            if ($was instanceof Date !== $is instanceof Date) {
                return null;
            }

            $written = new Component($master->name());

            foreach ($master->properties() as $property) {
                $copy = self::theInstancesOwn($property, $is, self::wallClockOf($is) - self::wallClockOf($was));

                if ($copy !== null) {
                    $written->add($copy);
                }
            }
        } catch (ParseError) {
            return null;
        }

        $written->add(new Property(self::RECURRENCE_ID, $is->encode(), $named->parameters()));

        foreach ($master->components() as $inner) {
            $written->add($inner->copy());
        }

        return $written;
    }

    /**
     * What the instance makes of one of the master's properties, or null where
     * it is one the series keeps.
     *
     * The names are compared without regard to case, RFC 5545 §3.1 making a
     * property name case-insensitive.
     *
     * @param Date|DateTime $start Where this instance begins
     * @param int $moved How far that is from where the master begins, in
     *                   seconds of wall clock
     *
     * @throws ParseError If an end is no value this library can read
     */
    private static function theInstancesOwn(Property $property, Date|DateTime $start, int $moved): ?Property
    {
        $name = strtoupper($property->name());

        // The identifier the caller asked for is the one that is written, so a
        // master that carried one of its own does not bring it: a component
        // naming two instances would be two components.
        if (in_array($name, self::RECURRENCE, true) || $name === self::RECURRENCE_ID) {
            return null;
        }

        if ($name === self::START) {
            return new Property(
                $property->name(),
                $start->encode(),
                $property->parameters(),
                $property->group(),
            );
        }

        return in_array($name, self::ENDS, true)
            ? self::shifted($property, $moved)
            : $property->copy();
    }

    /**
     * The property with its value moved by that many seconds of wall clock.
     *
     * @throws ParseError If the value is none this library can read
     */
    private static function shifted(Property $property, int $moved): Property
    {
        $value = self::valueOf($property);
        $at = (new DateTimeImmutable('@' . (self::wallClockOf($value) + $moved)))
            ->setTimezone(new DateTimeZone(self::UTC));

        return new Property(
            $property->name(),
            $value instanceof Date
                ? $at->format('Ymd')
                : $at->format('Ymd\THis') . ($value->isUtc() ? 'Z' : ''),
            $property->parameters(),
            $property->group(),
        );
    }

    /**
     * The property's value as a day or a moment.
     *
     * §3.8.4.4 of `RECURRENCE-ID` and §3.8.2.4 of `DTSTART` say the same
     * thing: "The default value type is DATE-TIME. The value type can be set
     * to a DATE value type." The same reading {@see ObjectSet} makes, and §3.1
     * makes the parameter's value case-insensitive.
     *
     * @throws ParseError If it is neither
     */
    private static function valueOf(Property $property): Date|DateTime
    {
        $type = $property->parameter('VALUE')?->value();

        return $type !== null && strcasecmp($type, 'DATE') === 0
            ? Date::decode($property->value())
            : DateTime::decode($property->value());
    }

    /**
     * The wall clock of a value as a number of seconds, read as if it were
     * UTC.
     *
     * **The arithmetic of a recurrence runs on the wall clock** — §3.3.10 and
     * §3.8.5.3 both put it there — so the gap between two values is the gap
     * between the clocks they show, whatever offsets they carry. Reading both
     * the same way is what makes the difference that gap.
     */
    private static function wallClockOf(Date|DateTime $value): int
    {
        $utc = new DateTimeZone(self::UTC);

        return $value instanceof Date
            ? $value->at($utc)->getTimestamp()
            : $value->in($utc)->getTimestamp();
    }
}
