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
use DateTimeZone;
use DavServices\VObject\Component;
use DavServices\VObject\Property;
use DavServices\VObject\Reader;
use DavServices\VObject\Recur\Zone;
use DavServices\VObject\TimeZone\Named;
use DavServices\VObject\TimeZone\Zones;
use DavServices\VObject\Value\DateTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for finding the zone a component's `TZID` means, and for the two
 * answers a wall clock can fail to have. RFC 5545 §3.2.19 and §3.6.5.
 * R-TZ-01, R-TZ-02, R-RRULE-05.
 *
 * ## Two ways to a zone, and the memo's own reason for their order
 *
 * A `TZID` parameter names a zone the system may know, and §3.2.19 requires
 * the object to carry a definition for it: "An individual 'VTIMEZONE'
 * calendar component MUST be specified for each unique 'TZID' parameter value
 * specified in the iCalendar object." So there are usually **two** answers
 * available, and R-TZ-01 and R-TZ-02 put the name first: the definition is
 * what a reader falls back to when nothing names the zone.
 *
 * **§3.6.5 argues for that order itself.** Its second published example is a
 * `VTIMEZONE` that is deliberately valid for one year only — "suitable for a
 * recurring event that starts on or later than March 11, 2007 … and ends no
 * later than March 9, 2008 at 01:59:59 EST". A reader that preferred the
 * supplied definition to a zone it knows by name would answer such an object
 * wrongly for every date outside that window, and objects like it are
 * everywhere. A named zone knows the whole history; a supplied one knows what
 * its writer needed.
 *
 * ## The two answers that are not a moment
 *
 * Both ways must agree about them, so the list below asks the same questions
 * of a zone found by name and of the same zone supplied as a definition:
 *
 * - a local time that **does not exist** has no moment (§3.3.10's "nonexistent
 *   local time"), and
 * - one that **happens twice** comes to the earlier moment.
 */
#[CoversClass(Zones::class)]
#[CoversClass(Named::class)]
final class ZonesTest extends TestCase
{
    /**
     * **A component with no `TZID` has no zone to find.** Its value is
     * floating or in UTC, and §3.2.19 says what floating means: "The use of
     * local time in a DATE-TIME or TIME value without the 'TZID' property
     * parameter is to be interpreted as floating time, regardless of the
     * existence of 'VTIMEZONE' calendar components in the iCalendar object."
     */
    public function testAComponentWithoutATimeZoneIdentifierHasNoZone(): void
    {
        self::assertNull(self::zoneOf('DTSTART:20240331T023000'));
        self::assertNull(self::zoneOf('DTSTART:20240331T003000Z'));
    }

    /**
     * **A component with no `DTSTART` at all has no zone either**, and asking
     * is not an error. A `VTODO` need not carry one — §3.6.2 makes `DTSTART`
     * optional there — and a reader that fell over such a component would
     * refuse an object the memo allows.
     */
    public function testAComponentWithoutAStartHasNoZone(): void
    {
        $object = new Component('VCALENDAR');
        $todo = new Component('VTODO');
        $todo->add(new Property('UID', 'nostart@example.com'));
        $object->add($todo);

        self::assertNull(Zones::of($object, $todo));
    }

    /**
     * **A `VTIMEZONE` without a `TZID` defines nothing, and defines it
     * quietly.** §3.6.5 requires the property — `VObject\Validator` reports a
     * component that leaves it out — but a reader looking for a definition by
     * name must not fall over one; it simply does not match, and the name is
     * all that is left.
     */
    public function testADefinitionWithoutANameMatchesNothing(): void
    {
        $nameless = implode("\r\n", [
            'BEGIN:VTIMEZONE',
            'BEGIN:STANDARD',
            'DTSTART:19700101T000000',
            'TZOFFSETFROM:+0100',
            'TZOFFSETTO:+0100',
            'END:STANDARD',
            'END:VTIMEZONE',
        ]) . "\r\n";

        self::assertNull(self::zoneOf('DTSTART;TZID=Nowhere/Special:20240701T120000', $nameless));

        // And a name the system knows still answers beside it.
        $zone = self::zoneOf('DTSTART;TZID=Europe/Berlin:20240701T120000', $nameless);

        self::assertNotNull($zone);
        self::assertSame(
            '2024-07-01T10:00:00+00:00',
            $zone->momentOf(DateTime::decode('20240701T120000'))?->format('c'),
        );
    }

    /**
     * **A name the system knows is the ordinary case.**
     */
    public function testANameTheSystemKnowsIsAZone(): void
    {
        $zone = self::zoneOf('DTSTART;TZID=Europe/Berlin:20240701T120000');

        self::assertNotNull($zone);
        self::assertSame(
            '2024-07-01T10:00:00+00:00',
            $zone->momentOf(DateTime::decode('20240701T120000'))?->format('c'),
        );
    }

    /**
     * **The name comes before the definition the object carries**, which is
     * R-TZ-01's order and §3.6.5's own warning about its second example: a
     * supplied definition is written for what its writer needed, a named zone
     * knows the whole history.
     *
     * Here the object carries a `VTIMEZONE` for `Europe/Berlin` that keeps
     * `+0100` all year. The name wins, so July is `+0200` all the same.
     */
    public function testTheNameComesBeforeTheDefinitionTheObjectCarries(): void
    {
        $zone = self::zoneOf(
            'DTSTART;TZID=Europe/Berlin:20240701T120000',
            self::aZoneThatNeverChanges('Europe/Berlin'),
        );

        self::assertNotNull($zone);
        self::assertSame(
            '2024-07-01T10:00:00+00:00',
            $zone->momentOf(DateTime::decode('20240701T120000'))?->format('c'),
        );
    }

    /**
     * **And the definition answers for a zone nobody knows by name**, which is
     * R-TZ-02: "Ohne Zuordnung MUSS die `VTIMEZONE` selbst ausgewertet
     * werden." `Fictitious` is §3.6.5's own name for a zone no database has.
     */
    public function testTheDefinitionAnswersForAZoneNobodyKnows(): void
    {
        $zone = self::zoneOf('DTSTART;TZID=Fictitious:19900701T120000', self::fictitious());

        self::assertNotNull($zone);
        self::assertSame(
            '1990-07-01T16:00:00+00:00',
            $zone->momentOf(DateTime::decode('19900701T120000'))?->format('c'),
        );
    }

    /**
     * **A definition is matched by its exact name.** §3.2.19: "The value of
     * the 'TZID' property parameter will be equal to the value of the 'TZID'
     * property for the matching time zone definition" — and §3.1 makes
     * property values case-sensitive, so a definition in another case is a
     * definition of something else.
     */
    public function testADefinitionIsMatchedByItsExactName(): void
    {
        self::assertNull(self::zoneOf('DTSTART;TZID=fictitious:19900701T120000', self::fictitious()));
    }

    /**
     * **A zone nothing names and nothing defines is null, never UTC**
     * (R-TZ-02). The caller is told it does not know, and can decide; a caller
     * handed UTC would never find out.
     */
    public function testAZoneNothingNamesAndNothingDefinesIsNull(): void
    {
        self::assertNull(self::zoneOf('DTSTART;TZID=Nowhere/Special:20240701T120000'));
        self::assertNull(self::zoneOf('DTSTART;TZID=Nowhere/Special:20240701T120000', self::fictitious()));
    }

    /**
     * **A local time that does not exist has no moment** — §3.3.10's
     * "nonexistent local time (e.g., 1:30 AM on a day where the local time is
     * moved forward by an hour at 1:00 AM)" — and **both ways of reaching the
     * zone must say so**.
     *
     * Central Europe moves its clocks forward at 02:00 on 31.03.2024, so
     * nobody there had half past two that morning.
     *
     * @param non-empty-string $written
     */
    #[DataProvider('bothWaysToTheSameZone')]
    public function testALocalTimeThatDoesNotExistHasNoMoment(string $written, ?string $definition): void
    {
        $zone = self::zoneOf($written, $definition);

        self::assertNotNull($zone);
        self::assertNull($zone->momentOf(DateTime::decode('20240331T023000')));

        // The two ends of the missing hour are there.
        self::assertSame(
            '2024-03-31T00:59:59+00:00',
            $zone->momentOf(DateTime::decode('20240331T015959'))?->format('c'),
        );
        self::assertSame(
            '2024-03-31T01:00:00+00:00',
            $zone->momentOf(DateTime::decode('20240331T030000'))?->format('c'),
        );
    }

    /**
     * **West of Greenwich the answer lies the other way round**, and that is
     * not a detail: a wall clock read as if it were UTC comes *before* the
     * moment it names there, so the offset that applies is the one in force a
     * day **later**, not a day earlier.
     *
     * Three o'clock on the morning New York moves its clocks forward is the
     * first hour of summer time: 07:00 UTC. A reader that only looked back
     * would see standard time either side of the wall clock, find no offset
     * that agrees with itself, and call an hour that happened nonexistent.
     *
     * **A mutation run found this**, not a test: the probe a day ahead could
     * be deleted and every test here still passed, because they were all east
     * of Greenwich.
     */
    public function testWestOfGreenwichTheOffsetComesFromTheOtherSide(): void
    {
        $zone = self::zoneOf('DTSTART;TZID=America/New_York:20240310T030000');

        self::assertNotNull($zone);
        self::assertSame(
            '2024-03-10T07:00:00+00:00',
            $zone->momentOf(DateTime::decode('20240310T030000'))?->format('c'),
        );

        // And the hour before it did not happen at all.
        self::assertNull($zone->momentOf(DateTime::decode('20240310T023000')));

        // While the repeated hour in November still comes to the earlier
        // moment, which is 01:30 in summer time and not in standard time.
        self::assertSame(
            '2024-11-03T05:30:00+00:00',
            $zone->momentOf(DateTime::decode('20241103T013000'))?->format('c'),
        );
    }

    /**
     * **And a local time that happens twice comes to the earlier moment**, by
     * either way to the zone. The clocks go back at 03:00 on 27.10.2024, so
     * half past two is shown once in summer time and again an hour later.
     *
     * @param non-empty-string $written
     */
    #[DataProvider('bothWaysToTheSameZone')]
    public function testALocalTimeThatHappensTwiceComesToTheEarlierMoment(string $written, ?string $definition): void
    {
        $zone = self::zoneOf($written, $definition);

        self::assertNotNull($zone);
        self::assertSame(
            '2024-10-27T00:30:00+00:00',
            $zone->momentOf(DateTime::decode('20241027T023000'))?->format('c'),
        );
    }

    /**
     * The same zone twice: once by a name the system knows, once as a
     * definition the object carries under a name it does not.
     *
     * @return iterable<string, array{string, string|null}>
     */
    public static function bothWaysToTheSameZone(): iterable
    {
        yield 'by name' => ['DTSTART;TZID=Europe/Berlin:20240101T000000', null];

        yield 'by the definition the object carries' => [
            'DTSTART;TZID=Example/Berlin:20240101T000000',
            self::centralEurope('Example/Berlin'),
        ];
    }

    /**
     * **A value that is already an instant keeps it**, whichever way the zone
     * was found: a trailing `Z` has fixed it, and a zone has nothing to add.
     */
    public function testAValueAlreadyInUtcKeepsItsMoment(): void
    {
        $zone = self::zoneOf('DTSTART;TZID=Europe/Berlin:20240701T120000');

        self::assertNotNull($zone);
        self::assertSame(
            '2024-07-01T12:00:00+00:00',
            $zone->momentOf(DateTime::decode('20240701T120000Z'))?->format('c'),
        );
    }

    /**
     * **A named zone answers the same as the database it comes from**, for
     * every moment that is neither missing nor repeated. A year of wall clocks
     * at three-hourly steps, which crosses both changes.
     */
    public function testANamedZoneAgreesWithTheDatabase(): void
    {
        $zone = self::zoneOf('DTSTART;TZID=Europe/Berlin:20240101T000000');
        $berlin = new DateTimeZone('Europe/Berlin');
        $moment = new DateTimeImmutable('2024-01-01T00:00:00Z');
        $checked = 0;

        self::assertNotNull($zone);

        while ($moment->getTimestamp() < (new DateTimeImmutable('2025-01-01T00:00:00Z'))->getTimestamp()) {
            $wall = $moment->setTimestamp($moment->getTimestamp() + $berlin->getOffset($moment));
            $found = $zone->momentOf(DateTime::decode($wall->format('Ymd\THis')));

            self::assertNotNull($found, $wall->format('c'));
            self::assertLessThanOrEqual($moment->getTimestamp(), $found->getTimestamp(), $wall->format('c'));
            self::assertSame(
                $wall->format('Ymd\THis'),
                $found->setTimestamp($found->getTimestamp() + $berlin->getOffset($found))->format('Ymd\THis'),
                $wall->format('c'),
            );

            ++$checked;
            $moment = $moment->setTimestamp($moment->getTimestamp() + 3 * 3600);
        }

        self::assertGreaterThan(2900, $checked);
    }

    /**
     * The zone a component's `DTSTART` means, in the object it travels in.
     */
    private static function zoneOf(string $start, ?string $definition = null): ?Zone
    {
        $text = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//davServices//Tests//EN\r\n"
            . ($definition ?? '')
            . "BEGIN:VEVENT\r\nUID:across@example.com\r\n" . $start . "\r\nEND:VEVENT\r\n"
            . "END:VCALENDAR\r\n";

        $objects = iterator_to_array((new Reader($text))->objects(), false);
        $object = $objects[0] ?? null;

        self::assertInstanceOf(Component::class, $object);

        $event = $object->component('VEVENT');

        self::assertInstanceOf(Component::class, $event);

        return Zones::of($object, $event);
    }

    /**
     * A `VTIMEZONE` that keeps one offset all year, under whatever name.
     */
    private static function aZoneThatNeverChanges(string $tzid): string
    {
        return implode("\r\n", [
            'BEGIN:VTIMEZONE',
            'TZID:' . $tzid,
            'BEGIN:STANDARD',
            'DTSTART:19700101T000000',
            'TZOFFSETFROM:+0100',
            'TZOFFSETTO:+0100',
            'END:STANDARD',
            'END:VTIMEZONE',
        ]) . "\r\n";
    }

    /**
     * Central Europe's own rules, written out: forward on the last Sunday in
     * March, back on the last Sunday in October.
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

    /**
     * §3.6.5's fourth published example, the zone no database has.
     */
    private static function fictitious(): string
    {
        return implode("\r\n", [
            'BEGIN:VTIMEZONE',
            'TZID:Fictitious',
            'LAST-MODIFIED:19870101T000000Z',
            'BEGIN:STANDARD',
            'DTSTART:19671029T020000',
            'RRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=10',
            'TZOFFSETFROM:-0400',
            'TZOFFSETTO:-0500',
            'TZNAME:EST',
            'END:STANDARD',
            'BEGIN:DAYLIGHT',
            'DTSTART:19870405T020000',
            'RRULE:FREQ=YEARLY;BYDAY=1SU;BYMONTH=4;UNTIL=19980404T070000Z',
            'TZOFFSETFROM:-0500',
            'TZOFFSETTO:-0400',
            'TZNAME:EDT',
            'END:DAYLIGHT',
            'END:VTIMEZONE',
        ]) . "\r\n";
    }
}
