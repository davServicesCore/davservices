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

namespace DavServices\Tests\Unit\VObject\TimeZone;

use DateTimeImmutable;
use DavServices\VObject\Component;
use DavServices\VObject\Reader;
use DavServices\VObject\Recur\ExpandedSet;
use DavServices\VObject\TimeZone\Zones;
use DavServices\VObject\Value\Date;
use DavServices\VObject\Value\DateTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for the last of R-RRULE-05's edge cases: **recurrences across a
 * change of offset**. RFC 5545 §3.3.10 and §3.8.5.3.
 *
 * The other four — the 29th of February yearly, the 31st monthly, `UNTIL`
 * with and without a zone, `COUNT` with `EXDATE` — were settled in P4-10 and
 * are checked against the memo's own published answers. This one needed a
 * zone, and since P4-11a and P4-11b there is one.
 *
 * ## Two things happen at a change of offset, and only one of them is a rule
 *
 * **The wall clock is kept.** "Every Monday at nine" means nine o'clock on the
 * clock on the wall, before and after the clocks move; as instants, two such
 * Mondays are then 167 or 169 hours apart rather than 168. That falls out of
 * the arithmetic the expansion already does — it counts on the wall clock,
 * which §3.8.5.3's published answers require — and needs no zone at all. The
 * tests below state it anyway, because "every 168 hours" is a reading someone
 * will eventually mistake for it.
 *
 * **And the hour that does not exist is passed over.** §3.3.10: "Recurrence
 * rules may generate recurrence instances with an invalid date (e.g., February
 * 30) or nonexistent local time (e.g., 1:30 AM on a day where the local time
 * is moved forward by an hour at 1:00 AM). Such recurrence instances MUST be
 * ignored and MUST NOT be counted as part of the recurrence set." That one
 * needs the zone, and it is the whole reason this chunk waited.
 *
 * ## Both ways to the zone must answer alike
 *
 * A `TZID` can be a name the system knows or a `VTIMEZONE` the object carries,
 * and {@see Zones} puts them in R-TZ-01's order. **The same series written
 * both ways must come out as the same instants**, or an object would mean
 * different things depending on how its zone happened to be found — so every
 * case here is asked twice.
 */
#[CoversClass(Zones::class)]
final class AcrossOffsetsTest extends TestCase
{
    /**
     * **A weekly series keeps its wall clock across both changes.** Nine in
     * the morning stays nine in the morning, in March and in October.
     *
     * @param non-empty-string $tzid
     */
    #[DataProvider('bothWaysToCentralEurope')]
    public function testAWeeklySeriesKeepsItsWallClock(string $tzid, ?string $definition): void
    {
        $instances = self::expand($tzid, $definition, '20240325T090000', 'FREQ=WEEKLY;COUNT=5');

        self::assertSame([
            '20240325T090000',
            '20240401T090000',
            '20240408T090000',
            '20240415T090000',
            '20240422T090000',
        ], $instances);
    }

    /**
     * **And the instants move by an hour where the wall clock does not.** The
     * week that holds the change is 167 hours long in spring and 169 in
     * autumn; every other week is 168.
     *
     * This is the difference between "every Monday at nine" and "every 168
     * hours", and the memo's answer is the first: the expansion counts on the
     * wall clock.
     *
     * @param non-empty-string $tzid
     */
    #[DataProvider('bothWaysToCentralEurope')]
    public function testTheInstantsMoveByAnHourWhereTheWallClockDoesNot(string $tzid, ?string $definition): void
    {
        self::assertSame(
            [167, 168],
            self::hoursBetween(self::moments($tzid, $definition, '20240325T090000', 'FREQ=WEEKLY;COUNT=3')),
        );

        self::assertSame(
            [168, 169],
            self::hoursBetween(self::moments($tzid, $definition, '20241014T090000', 'FREQ=WEEKLY;COUNT=3')),
        );
    }

    /**
     * **A daily series passes over the hour that does not exist**, and does
     * not count it: `COUNT=4` from the 30th of March comes to four mornings
     * that happened, the 31st not being one of them.
     *
     * @param non-empty-string $tzid
     */
    #[DataProvider('bothWaysToCentralEurope')]
    public function testADailySeriesPassesOverTheHourThatDoesNotExist(string $tzid, ?string $definition): void
    {
        self::assertSame([
            '20240330T023000',
            '20240401T023000',
            '20240402T023000',
            '20240403T023000',
        ], self::expand($tzid, $definition, '20240330T023000', 'FREQ=DAILY;COUNT=4'));
    }

    /**
     * **An hour that happens twice happens once**, and is not passed over: the
     * clocks go back at 03:00 on the 27th of October, so half past two that
     * morning is shown twice — which is one instance of the series, at the
     * earlier of the two moments.
     *
     * @param non-empty-string $tzid
     */
    #[DataProvider('bothWaysToCentralEurope')]
    public function testAnHourThatHappensTwiceIsOneInstance(string $tzid, ?string $definition): void
    {
        self::assertSame([
            '20241026T023000',
            '20241027T023000',
            '20241028T023000',
        ], self::expand($tzid, $definition, '20241026T023000', 'FREQ=DAILY;COUNT=3'));

        self::assertSame(
            ['2024-10-26T00:30:00+00:00', '2024-10-27T00:30:00+00:00', '2024-10-28T01:30:00+00:00'],
            self::moments($tzid, $definition, '20241026T023000', 'FREQ=DAILY;COUNT=3'),
        );
    }

    /**
     * **A whole-day series is untouched by any of this**, R-TZ-04 forbidding
     * an all-day value to become an instant at all: the days of a change of
     * offset are days like the others.
     *
     * @param non-empty-string $tzid
     */
    #[DataProvider('bothWaysToCentralEurope')]
    public function testAWholeDaySeriesIsUntouched(string $tzid, ?string $definition): void
    {
        self::assertSame(
            ['20240330', '20240331', '20240401'],
            self::expand($tzid, $definition, '20240330', 'FREQ=DAILY;COUNT=3', 'DATE'),
        );
    }

    /**
     * **A floating series is untouched too**, and that is the other half of
     * the same point: without a `TZID` there is no zone to ask, so the missing
     * hour is an hour like any other.
     */
    public function testAFloatingSeriesIsUntouched(): void
    {
        self::assertSame([
            '20240330T023000',
            '20240331T023000',
            '20240401T023000',
        ], self::expand(null, null, '20240330T023000', 'FREQ=DAILY;COUNT=3'));
    }

    /**
     * The same zone twice: the name the system knows, and a `VTIMEZONE` with
     * Central Europe's own rules under a name no database has.
     *
     * @return iterable<string, array{string, string|null}>
     */
    public static function bothWaysToCentralEurope(): iterable
    {
        yield 'by name' => ['Europe/Berlin', null];

        yield 'by the definition the object carries' => ['Example/Berlin', self::centralEurope('Example/Berlin')];
    }

    /**
     * The instances of such a series, as they are written.
     *
     * @return list<string>
     */
    private static function expand(
        ?string $tzid,
        ?string $definition,
        string $start,
        string $rule,
        ?string $type = null,
    ): array {
        $instances = [];

        foreach (self::setOf($tzid, $definition, $start, $rule, $type)->instances() as $instance) {
            $instances[] = $instance->encode();
        }

        return $instances;
    }

    /**
     * The moments of such a series, which is what a caller holding a zone
     * keeps.
     *
     * @return list<string>
     */
    private static function moments(?string $tzid, ?string $definition, string $start, string $rule): array
    {
        $object = self::objectOf($tzid, $definition, $start, $rule, null);
        $event = $object->component('VEVENT');

        self::assertInstanceOf(Component::class, $event);

        $zone = Zones::of($object, $event);

        self::assertNotNull($zone);

        $moments = [];

        foreach (ExpandedSet::of($event, 10000, $zone)->instances() as $instance) {
            self::assertInstanceOf(DateTime::class, $instance);

            $moment = $zone->momentOf($instance);

            // Every instance of these series has a moment: the one that would
            // not is passed over before it ever reaches a caller.
            self::assertNotNull($moment, $instance->encode());

            $moments[] = $moment->format('c');
        }

        return $moments;
    }

    /**
     * The whole hours between one moment and the next.
     *
     * @param list<string> $moments
     *
     * @return list<int>
     */
    private static function hoursBetween(array $moments): array
    {
        $hours = [];

        foreach ($moments as $index => $moment) {
            $previous = $index === 0 ? null : $moments[$index - 1] ?? null;

            if ($previous === null) {
                continue;
            }

            $hours[] = intdiv(
                (new DateTimeImmutable($moment))->getTimestamp() - (new DateTimeImmutable($previous))->getTimestamp(),
                3600,
            );
        }

        sort($hours);

        return array_values(array_unique($hours));
    }

    /**
     * The recurrence set of such a series, the zone found the way a server
     * finds one.
     */
    private static function setOf(
        ?string $tzid,
        ?string $definition,
        string $start,
        string $rule,
        ?string $type,
    ): ExpandedSet {
        $object = self::objectOf($tzid, $definition, $start, $rule, $type);
        $event = $object->component('VEVENT');

        self::assertInstanceOf(Component::class, $event);

        return ExpandedSet::of($event, 10000, Zones::of($object, $event));
    }

    /**
     * The object such a series travels in.
     */
    private static function objectOf(
        ?string $tzid,
        ?string $definition,
        string $start,
        string $rule,
        ?string $type,
    ): Component {
        $parameters = ($type === null ? '' : ';VALUE=' . $type)
            . ($tzid === null ? '' : ';TZID=' . $tzid);

        $text = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//davServices//Tests//EN\r\n"
            . ($definition ?? '')
            . "BEGIN:VEVENT\r\nUID:across@example.com\r\n"
            . 'DTSTART' . $parameters . ':' . $start . "\r\n"
            . 'RRULE:' . $rule . "\r\n"
            . "END:VEVENT\r\nEND:VCALENDAR\r\n";

        $objects = iterator_to_array((new Reader($text))->objects(), false);
        $object = $objects[0] ?? null;

        self::assertInstanceOf(Component::class, $object);

        return $object;
    }

    /**
     * Central Europe's own rules, written out: forward on the last Sunday in
     * March at 02:00, back on the last Sunday in October at 03:00.
     */
    private static function centralEurope(string $tzid): string
    {
        return implode("\r\n", [
            'BEGIN:VTIMEZONE',
            'TZID:' . $tzid,
            'BEGIN:DAYLIGHT',
            'DTSTART:19810329T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU',
            'TZOFFSETFROM:+0100',
            'TZOFFSETTO:+0200',
            'TZNAME:CEST',
            'END:DAYLIGHT',
            'BEGIN:STANDARD',
            'DTSTART:19961027T030000',
            'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU',
            'TZOFFSETFROM:+0200',
            'TZOFFSETTO:+0100',
            'TZNAME:CET',
            'END:STANDARD',
            'END:VTIMEZONE',
        ]) . "\r\n";
    }
}
