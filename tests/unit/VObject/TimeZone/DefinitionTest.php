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
use DavServices\VObject\ParseError;
use DavServices\VObject\Reader;
use DavServices\VObject\Recur\TooManyIterations;
use DavServices\VObject\TimeZone\Definition;
use DavServices\VObject\TimeZone\Observance;
use DavServices\VObject\Value\DateTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for evaluating the `VTIMEZONE` a calendar object carries. RFC 5545
 * §3.6.5. R-TZ-02, second half.
 *
 * **The definition is in the object, and that is the point.** R-TZ-02: "Ohne
 * Zuordnung MUSS die `VTIMEZONE` selbst ausgewertet werden" — a server that
 * only ever asks its own zone database cannot read an object whose zone it has
 * not got, and {@see \DavServices\VObject\TimeZone\Resolver} answers such a
 * name with null on purpose. This is what happens next.
 *
 * ## The one sentence the whole class comes from
 *
 * > The offset to apply at any given time is found by locating the observance
 * > that has the last onset date and time before the time in question, and
 * > using the offset value from that observance.
 *
 * So the `STANDARD` and `DAYLIGHT` sub-components are **one set of onsets**,
 * not two series that alternate — "For a given time zone, there may be
 * multiple unique definitions of the observances over a period of time" — and
 * the answer is the offset of the latest onset that has happened.
 *
 * ## Where the onsets come from, and in whose offset they are measured
 *
 * > The mandatory "DTSTART" property gives the effective onset date and local
 * > time … "DTSTART" in this usage MUST be specified as a date with a local
 * > time value.
 *
 * > "TZOFFSETFROM" is combined with "DTSTART" to define the effective onset
 * > for the time zone sub-component definition.
 *
 * > The "DTSTART" and the "TZOFFSETFROM" properties MUST be used when
 * > generating the onset DATE-TIME values (instances) from the "RRULE".
 *
 * > "RDATE" in this usage MUST be specified as a date with local time value,
 * > relative to the UTC offset specified in the "TZOFFSETFROM" property.
 *
 * Four sentences for one arithmetic: an onset's moment is its local time taken
 * **in the offset that was in use before it**, `DTSTART − TZOFFSETFROM`.
 * Taking it in `TZOFFSETTO` instead is the mistake these tests are built to
 * catch, and it moves every change of offset by the difference of the two.
 *
 * ## The examples are the memo's own
 *
 * All **five** `VTIMEZONE` definitions §3.6.5 publishes are here verbatim —
 * three of New York and two of `Fictitious`, the second of those being easy to
 * read past — and **`Fictitious` is the sharpest of them**: no zone database
 * has that name, so an answer about it can only have come from the definition
 * itself.
 */
#[CoversClass(Definition::class)]
#[CoversClass(Observance::class)]
final class DefinitionTest extends TestCase
{
    /**
     * **The offset before the first onset is the one the definition says was
     * in use.** §3.6.5 on `TZOFFSETFROM`: "gives the UTC offset that is in use
     * when the onset of this time zone observance begins" — so for any moment
     * before every onset, the earliest onset's own `TZOFFSETFROM` is the
     * answer the definition gives, and nothing has to be invented.
     *
     * It is also why the memo says of its second example that it is "only
     * suitable for a recurring event that starts on or later than March 11,
     * 2007": before that, this is all one can say.
     */
    public function testTheOffsetBeforeTheFirstOnsetIsTheOneItSaysWasInUse(): void
    {
        $definition = self::definitionOf(self::newYorkWithDtstartOnly());

        self::assertSame(-5 * 3600, self::offsetAt($definition, '2000-01-01T00:00:00Z'));
        self::assertSame(-5 * 3600, self::offsetAt($definition, '2007-03-11T06:59:59Z'));
    }

    /**
     * **An onset takes effect at its own moment.** "The mandatory 'DTSTART'
     * property gives the **effective** onset date and local time", so the
     * second it names already belongs to the new observance; the second
     * before it does not.
     */
    public function testAnOnsetTakesEffectAtItsOwnMoment(): void
    {
        $definition = self::definitionOf(self::newYorkWithRules());

        self::assertSame(-5 * 3600, self::offsetAt($definition, '2024-03-10T06:59:59Z'));
        self::assertSame(-4 * 3600, self::offsetAt($definition, '2024-03-10T07:00:00Z'));
    }

    /**
     * **The onset is the local time taken in the offset before it**, which is
     * `DTSTART − TZOFFSETFROM`.
     *
     * The definition here changes at 02:00 local from `+0100` to `+0200`, so
     * the onset is 01:00 UTC. Taking the local time in `TZOFFSETTO` instead
     * would put it at midnight UTC, and the hour between the two is what these
     * three probes stand in.
     */
    public function testTheOnsetIsTheLocalTimeTakenInTheOffsetBeforeIt(): void
    {
        $definition = self::definitionOf(self::easternEurope());

        self::assertSame(3600, self::offsetAt($definition, '2024-03-31T00:30:00Z'));
        self::assertSame(3600, self::offsetAt($definition, '2024-03-31T00:59:59Z'));
        self::assertSame(2 * 3600, self::offsetAt($definition, '2024-03-31T01:00:00Z'));
    }

    /**
     * **The sub-components are one set of onsets, not two series.** The memo's
     * full New York definition carries seven of them, five `DAYLIGHT` and two
     * `STANDARD`, whose rules overlap and succeed one another; the answer at
     * any moment is the latest onset that has happened, whichever
     * sub-component it came from.
     *
     * @param non-empty-string $moment
     */
    #[DataProvider('theHistoryOfNewYork')]
    public function testTheSubComponentsAreOneSetOfOnsets(string $moment, int $offset): void
    {
        self::assertSame(
            $offset,
            self::offsetAt(self::definitionOf(self::newYorkSince1967()), $moment),
        );
    }

    /**
     * The probes are the memo's own rules read as history: the year the United
     * States began daylight time in January, the year it began in February,
     * and the three rules that followed.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function theHistoryOfNewYork(): iterable
    {
        yield 'before every onset, the first one says -0500' => ['1967-01-01T00:00:00Z', -5 * 3600];

        yield 'the first onset, 30.04.1967 02:00 local' => ['1967-04-30T07:00:00Z', -4 * 3600];

        yield 'the winter after it' => ['1967-12-01T12:00:00Z', -5 * 3600];

        // DTSTART:19740106T020000 — the year daylight time began in January.
        yield 'January 1974, by a DTSTART of its own' => ['1974-02-01T12:00:00Z', -4 * 3600];

        yield 'the winter between the two odd years' => ['1975-01-01T12:00:00Z', -5 * 3600];

        // RDATE:19750223T020000 — and the year it began in February.
        yield 'February 1975, by an RDATE' => ['1975-03-01T12:00:00Z', -4 * 3600];

        yield 'the last Sunday in April 1980' => ['1980-04-27T07:00:00Z', -4 * 3600];

        yield 'the first Sunday in April 1990' => ['1990-04-01T07:00:00Z', -4 * 3600];

        yield 'the first Sunday in April 1990, a second before' => ['1990-04-01T06:59:59Z', -5 * 3600];

        yield 'the second Sunday in March 2024' => ['2024-03-10T07:00:00Z', -4 * 3600];

        yield 'the first Sunday in November 2024' => ['2024-11-03T06:00:00Z', -5 * 3600];
    }

    /**
     * **A zone that changes once and never again is a definition too**, and
     * the one observance answers both halves of time: its `TZOFFSETFROM`
     * before its onset, its `TZOFFSETTO` from the onset on. Plenty of real
     * zones look like this, and the memo asks only for one sub-component.
     */
    public function testAZoneWithOneObservanceAnswersBothHalvesOfTime(): void
    {
        $definition = self::definitionOf(self::changedOnce());

        self::assertSame(0, self::offsetAt($definition, '1960-01-01T12:00:00Z'));
        self::assertSame(0, self::offsetAt($definition, '1969-12-31T23:59:59Z'));
        self::assertSame(19800, self::offsetAt($definition, '1970-01-01T00:00:00Z'));
        self::assertSame(19800, self::offsetAt($definition, '2024-07-01T12:00:00Z'));
        self::assertSame('IST', $definition->nameAt(new DateTimeImmutable('2024-07-01T12:00:00Z')));
    }

    /**
     * **Every value of every `RDATE` counts.** §3.8.5.2 makes the value "a
     * COMMA-separated list of date-time values" and §3.6.5 lets the property
     * "occur more than once", so the onsets of an observance are all of them
     * together — here one from the `DTSTART`, two from a list and one from a
     * second property.
     *
     * The last probe is the one that would catch a list read as far as its
     * first value: with the autumn onsets exhausted, the definition stays in
     * daylight, and a lost `RDATE` would show up as an autumn that never came.
     *
     * @param non-empty-string $moment
     */
    #[DataProvider('theYearsOfTheListedOnsets')]
    public function testEveryValueOfEveryRdateCounts(string $moment, int $offset): void
    {
        self::assertSame(
            $offset,
            self::offsetAt(self::definitionOf(self::listedOnsets()), $moment),
        );
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function theYearsOfTheListedOnsets(): iterable
    {
        yield 'the summer of 2020, by the rule' => ['2020-06-01T12:00:00Z', 2 * 3600];

        yield 'the winter of 2020, by the DTSTART' => ['2020-12-01T12:00:00Z', 3600];

        yield 'the winter of 2021, by the first value of the list' => ['2021-12-01T12:00:00Z', 3600];

        yield 'the winter of 2022, by the second value' => ['2022-12-01T12:00:00Z', 3600];

        yield 'the winter of 2023, by the second property' => ['2023-12-01T12:00:00Z', 3600];

        // Nothing is left to end the summer of 2024, so it does not end.
        yield 'the winter of 2024, with the onsets exhausted' => ['2024-12-01T12:00:00Z', 2 * 3600];
    }

    /**
     * **Two rules in one observance are both read.** §3.6.5 says `RRULE`
     * "SHOULD NOT occur more than once" — which is not a prohibition, and the
     * sentence that decides what to do with a second one is the other:
     * "The collection of these sub-components is used to describe the time
     * zone." Every onset counts, however it was written.
     */
    public function testTwoRulesInOneObservanceAreBothRead(): void
    {
        $definition = self::definitionOf(self::twoRulesInOneObservance());

        self::assertSame(2 * 3600, self::offsetAt($definition, '2021-03-15T12:00:00Z'));
        self::assertSame(3600, self::offsetAt($definition, '2021-04-15T12:00:00Z'));
        self::assertSame(2 * 3600, self::offsetAt($definition, '2021-07-15T12:00:00Z'));
    }

    /**
     * **An `RDATE` names an onset of its own**, and it is read on the same
     * clock as a `DTSTART`: "relative to the UTC offset specified in the
     * 'TZOFFSETFROM' property". `RDATE:19750223T020000` with `TZOFFSETFROM:
     * -0500` is 07:00 UTC, and the second before it still belongs to winter.
     */
    public function testAnRdateNamesAnOnsetOfItsOwn(): void
    {
        $definition = self::definitionOf(self::newYorkSince1967());

        self::assertSame(-5 * 3600, self::offsetAt($definition, '1975-02-23T06:59:59Z'));
        self::assertSame(-4 * 3600, self::offsetAt($definition, '1975-02-23T07:00:00Z'));
    }

    /**
     * **An `UNTIL` here is in UTC, and that is an exception.** §3.6.5: "the
     * UNTIL DATE-TIME will be equal to the last instance generated by the
     * recurrence pattern). It MUST be specified in UTC time." The expansion
     * runs on the wall clock — {@see \DavServices\VObject\Recur\Iterator} sets
     * out why, and §3.8.5.3's published answers require it — so for this one
     * usage the bound has to be converted before the expansion sees it.
     *
     * **The two readings differ east of Greenwich.** The bound here names the
     * 2025 onset, which is 02:00 local in `+0100` and so 01:00 UTC: as an
     * instant it is the last instance and is kept. Compared as a wall clock,
     * 02:00 would fall past 01:00 and that onset would be lost — **a whole
     * summer at the wrong offset**, which is what the probe in July 2025 is
     * for. The bound has to be a later onset than the `DTSTART`, because a
     * `DTSTART` is an onset in its own right and would survive either reading.
     */
    public function testAnUntilInUtcIsReadAsTheInstantItIs(): void
    {
        $definition = self::definitionOf(self::easternEuropeBoundedInUtc());

        self::assertSame(2 * 3600, self::offsetAt($definition, '2024-04-01T12:00:00Z'));
        self::assertSame(3600, self::offsetAt($definition, '2024-10-28T12:00:00Z'));

        // The onset the bound itself names, kept because it is the bound.
        self::assertSame(3600, self::offsetAt($definition, '2025-03-30T00:59:59Z'));
        self::assertSame(2 * 3600, self::offsetAt($definition, '2025-03-30T01:00:00Z'));
        self::assertSame(2 * 3600, self::offsetAt($definition, '2025-07-01T12:00:00Z'));

        // And the year after it there is no daylight onset at all.
        self::assertSame(3600, self::offsetAt($definition, '2026-07-01T12:00:00Z'));
    }

    /**
     * **A bound written without the `Z` is read as it stands**, not refused.
     * §3.6.5 requires UTC here and a value without the `Z` breaks that — but
     * it is then written on the very clock the expansion compares on, so its
     * meaning is not in doubt, and a reader that refused it would lose a
     * definition it could read. The same bound in both spellings, so the two
     * must answer alike.
     */
    public function testABoundWrittenOnTheLocalClockIsReadAsItStands(): void
    {
        $local = self::definitionOf(self::easternEuropeBoundedLocally());
        $utc = self::definitionOf(self::easternEuropeBoundedInUtc());

        foreach ([
            '2024-04-01T12:00:00Z',
            '2024-10-28T12:00:00Z',
            '2025-07-01T12:00:00Z',
            '2026-07-01T12:00:00Z',
        ] as $moment) {
            self::assertSame(
                self::offsetAt($utc, $moment),
                self::offsetAt($local, $moment),
                $moment,
            );
        }
    }

    /**
     * **A name is the observance's own where it gives one**, `TZNAME` being
     * "the customary name for the time zone" and optional.
     */
    public function testTheNameIsTheObservancesOwn(): void
    {
        $definition = self::definitionOf(self::newYorkSince1967());

        self::assertSame('EST', $definition->nameAt(new DateTimeImmutable('2024-01-01T12:00:00Z')));
        self::assertSame('EDT', $definition->nameAt(new DateTimeImmutable('2024-07-01T12:00:00Z')));
    }

    /**
     * **And null where none is given**, rather than a name made up from the
     * offset.
     */
    public function testANameIsNullWhereTheObservanceGivesNone(): void
    {
        $definition = self::definitionOf(self::easternEurope());

        self::assertNull($definition->nameAt(new DateTimeImmutable('2024-07-01T12:00:00Z')));
    }

    /**
     * **And null before every onset**, where the definition still has an
     * offset to give but no observance is in force. A `TZNAME` belongs to an
     * observance, so there is no name to give, and inventing one from the
     * offset would be a name nobody wrote.
     */
    public function testANameBeforeEveryOnsetIsNull(): void
    {
        $definition = self::definitionOf(self::newYorkWithRules());

        self::assertSame(-5 * 3600, self::offsetAt($definition, '2000-01-01T12:00:00Z'));
        self::assertNull($definition->nameAt(new DateTimeImmutable('2000-01-01T12:00:00Z')));
    }

    /**
     * **`Fictitious` is read from the definition alone**, which is the whole
     * of R-TZ-02: no zone database has that name, so an answer about it cannot
     * have come from one. The memo introduces it as "a set of rules for a
     * fictitious time zone where the Daylight Time rule has an effective end
     * date (i.e., after that date, Daylight Time is no longer observed)".
     *
     * @param non-empty-string $moment
     */
    #[DataProvider('theFictitiousYears')]
    public function testTheFictitiousZoneIsReadFromItsDefinitionAlone(string $moment, int $offset): void
    {
        self::assertSame(
            $offset,
            self::offsetAt(self::definitionOf(self::fictitious()), $moment),
        );
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function theFictitiousYears(): iterable
    {
        yield 'the summer of 1990, daylight observed' => ['1990-07-01T12:00:00Z', -4 * 3600];

        yield 'the winter of 1990' => ['1990-12-01T12:00:00Z', -5 * 3600];

        yield 'the summer of 1997, the last one' => ['1997-07-01T12:00:00Z', -4 * 3600];

        yield 'the summer of 1998, after the rule ended' => ['1998-07-01T12:00:00Z', -5 * 3600];

        yield 'the summer of 2024, still no daylight' => ['2024-07-01T12:00:00Z', -5 * 3600];
    }

    /**
     * **And the memo's fifth definition is the same zone with the gap filled**
     * — "There is a second Daylight Time rule that picks up where the other
     * left off" — which is the case that tells a merged set of onsets from two
     * alternating series. The first daylight rule ends in 1997 and the second
     * begins in 1999, **so 1998 has no daylight at all**: a year that is in
     * the memo's own data and in no prose.
     *
     * @param non-empty-string $moment
     */
    #[DataProvider('theFictitiousYearsPickedUp')]
    public function testASecondRulePicksUpWhereTheFirstLeftOff(string $moment, int $offset): void
    {
        self::assertSame(
            $offset,
            self::offsetAt(self::definitionOf(self::fictitiousPickedUp()), $moment),
        );
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function theFictitiousYearsPickedUp(): iterable
    {
        yield 'the summer of 1997, the last of the first rule' => ['1997-07-01T12:00:00Z', -4 * 3600];

        yield 'the summer of 1998, the year in between' => ['1998-07-01T12:00:00Z', -5 * 3600];

        yield 'the summer of 1999, the first of the second rule' => ['1999-07-01T12:00:00Z', -4 * 3600];

        yield 'the winter of 1999' => ['1999-12-01T12:00:00Z', -5 * 3600];

        yield 'the summer of 2024, the second rule still running' => ['2024-07-01T12:00:00Z', -4 * 3600];
    }

    /**
     * **The whole published history of New York agrees with the zone
     * database**, transition for transition, from the first onset the
     * definition names to the end of 2030.
     *
     * This is the test that would catch an arithmetic that is merely plausible:
     * every moment at which `America/New_York` changes its offset is probed on
     * both sides, and the definition has to change at exactly the same
     * instants and to exactly the same offsets. **The database is the witness
     * here, not the authority** — where the two disagreed it would be the
     * memo's example that decides, and a disagreement is a finding either way.
     */
    public function testThePublishedHistoryAgreesWithTheZoneDatabase(): void
    {
        $definition = self::definitionOf(self::newYorkSince1967());
        $zone = new DateTimeZone('America/New_York');

        $first = (new DateTimeImmutable('1967-04-30T07:00:00Z'))->getTimestamp();
        $last = (new DateTimeImmutable('2031-01-01T00:00:00Z'))->getTimestamp();
        $changes = 0;

        foreach ($zone->getTransitions($first, $last) as $transition) {
            $moment = new DateTimeImmutable('@' . $transition['ts']);
            $before = $moment->modify('-1 second');

            self::assertSame(
                $transition['offset'],
                $definition->offsetAt($moment),
                'from ' . $moment->format('c'),
            );
            self::assertSame(
                $zone->getOffset($before),
                $definition->offsetAt($before),
                'until ' . $before->format('c'),
            );

            ++$changes;
        }

        // A sanity check on the witness: sixty-three years of a zone that
        // changes twice a year is not a handful of transitions.
        self::assertGreaterThan(100, $changes);
    }

    /**
     * **A local time comes to the moment the definition makes of it**, which
     * is the question every `DTSTART;TZID=…` asks.
     */
    public function testALocalTimeComesToTheMomentTheDefinitionMakesOfIt(): void
    {
        $definition = self::definitionOf(self::newYorkWithRules());

        self::assertSame(
            '2024-07-01T16:00:00+00:00',
            self::momentOf($definition, '20240701T120000'),
        );
        self::assertSame(
            '2024-01-01T17:00:00+00:00',
            self::momentOf($definition, '20240101T120000'),
        );
    }

    /**
     * **A local time that does not exist has no moment**, which is the half of
     * §3.3.10's sentence about ignoring an instance that has waited for a
     * zone since P4-08a: "an invalid date (e.g., February 30) or **nonexistent
     * local time** (e.g., 1:30 AM on a day where the local time is moved
     * forward by an hour at 1:00 AM)".
     *
     * New York moves its clocks forward at 02:00 local on 10.03.2024, so
     * 02:30 that morning is a time nobody there had. **Null says so**, and a
     * caller that is told can do what the memo asks and ignore the instance;
     * one handed a moment anyway would silently keep an appointment that never
     * happened.
     */
    public function testALocalTimeThatDoesNotExistHasNoMoment(): void
    {
        $definition = self::definitionOf(self::newYorkWithRules());

        self::assertNull(self::momentOf($definition, '20240310T023000'));
        self::assertNull(self::momentOf($definition, '20240310T025959'));

        // The two ends of the hour exist: 01:59:59 is EST, 03:00 is EDT.
        self::assertSame('2024-03-10T06:59:59+00:00', self::momentOf($definition, '20240310T015959'));
        self::assertSame('2024-03-10T07:00:00+00:00', self::momentOf($definition, '20240310T030000'));
    }

    /**
     * **A local time that happens twice comes to the earlier moment.** When
     * the clocks go back, 01:30 is shown once in daylight time and again an
     * hour later in standard time, and §3.6.5 says nothing about which one a
     * reader means.
     *
     * **The earlier is taken**, because it is the one the clock showed first:
     * reading it as the later moment would mean understanding a written time
     * as belonging to an observance that had not begun when that time was on
     * the clock. The decision is here rather than in a comment because a test
     * is where a decision can be found.
     */
    public function testALocalTimeThatHappensTwiceComesToTheEarlierMoment(): void
    {
        $definition = self::definitionOf(self::newYorkWithRules());

        // 01:30 in EDT is 05:30 UTC; the same wall clock in EST is 06:30.
        self::assertSame('2024-11-03T05:30:00+00:00', self::momentOf($definition, '20241103T013000'));
    }

    /**
     * **A value that is already an instant keeps it.** A `DATE-TIME` with a
     * trailing `Z` names a moment outright (§3.3.5 FORM #2), and no zone
     * definition has anything to add to it.
     */
    public function testAValueAlreadyInUtcKeepsItsMoment(): void
    {
        $definition = self::definitionOf(self::newYorkWithRules());

        self::assertSame('2024-07-01T12:00:00+00:00', self::momentOf($definition, '20240701T120000Z'));
    }

    /**
     * **A component with no observance defines no zone**, and says so with
     * null. §3.6.5 requires at least one — "One of 'standardc' or 'daylightc'
     * MUST occur" — and a `VTIMEZONE` without one is a thing the validator
     * reports; here it is simply nothing to evaluate.
     */
    public function testAComponentWithNoObservanceDefinesNoZone(): void
    {
        $zone = new Component('VTIMEZONE');

        self::assertNull(Definition::of($zone));
    }

    /**
     * **An observance that cannot be read is refused, not guessed at.** Each
     * of these breaks one of §3.6.5's own MUSTs, and none of them can be
     * repaired without inventing a number that moves appointments.
     *
     * @param non-empty-string $observance
     */
    #[DataProvider('theUnreadableObservances')]
    public function testAnObservanceThatCannotBeReadIsRefused(string $observance, string $expected): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches($expected);

        Definition::of(self::zoneIn(self::wrap('BEGIN:VTIMEZONE' . "\r\n"
            . 'TZID:Example' . "\r\n"
            . $observance . "\r\n"
            . 'END:VTIMEZONE')));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function theUnreadableObservances(): iterable
    {
        yield 'no DTSTART' => [
            "BEGIN:STANDARD\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\nEND:STANDARD",
            '/no DTSTART/',
        ];

        yield 'no TZOFFSETFROM' => [
            "BEGIN:STANDARD\r\nDTSTART:20241027T030000\r\nTZOFFSETTO:+0100\r\nEND:STANDARD",
            '/no TZOFFSETFROM/',
        ];

        yield 'no TZOFFSETTO' => [
            "BEGIN:STANDARD\r\nDTSTART:20241027T030000\r\nTZOFFSETFROM:+0200\r\nEND:STANDARD",
            '/no TZOFFSETTO/',
        ];

        // "DTSTART in this usage MUST be specified as a date with a local time
        // value" — a Z makes it an instant, and the onset arithmetic would
        // then apply TZOFFSETFROM to a moment that already has one.
        yield 'a DTSTART in UTC' => [
            "BEGIN:STANDARD\r\nDTSTART:20241027T010000Z\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\nEND:STANDARD",
            '/local time/',
        ];

        // "RDATE in this usage MUST be specified as a date with local time
        // value, relative to the UTC offset specified in the TZOFFSETFROM
        // property" — so an instant is no onset here either.
        yield 'an RDATE in UTC' => [
            "BEGIN:STANDARD\r\nDTSTART:20241027T030000\r\nRDATE:20251026T010000Z\r\n"
                . "TZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\nEND:STANDARD",
            '/local time/',
        ];

        // And a DATE is no date and time at all, which the value type says
        // before §3.6.5 has to: a day is not an onset.
        yield 'a DTSTART that is a DATE' => [
            "BEGIN:STANDARD\r\nDTSTART;VALUE=DATE:20241027\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\nEND:STANDARD",
            '/no date and time/',
        ];
    }

    /**
     * **The hard limit holds here too** (R-RRULE-04, R-CAL-08). An observance
     * whose rule has no end can be asked about any moment, and the further
     * away it is the more onsets have to be generated to reach it; the limit
     * is the one the expansion already carries, and passing it is a refusal
     * rather than an answer from a half-expanded set.
     */
    public function testTheHardLimitHoldsForAFarAwayMoment(): void
    {
        $definition = self::definitionOf(self::newYorkWithRules(), 20);

        $this->expectException(TooManyIterations::class);

        $definition->offsetAt(new DateTimeImmutable('2300-07-01T12:00:00Z'));
    }

    /**
     * **A definition answers about a moment before its own onsets**, so the
     * limit is not reached by asking about the past.
     */
    public function testAMomentBeforeEveryOnsetNeedsNoExpansion(): void
    {
        $definition = self::definitionOf(self::newYorkWithRules(), 2);

        self::assertSame(-5 * 3600, self::offsetAt($definition, '1900-01-01T12:00:00Z'));
    }

    /**
     * The definition a published example comes to.
     */
    private static function definitionOf(string $text, ?int $iterations = null): Definition
    {
        $zone = self::zoneIn($text);
        $definition = $iterations === null ? Definition::of($zone) : Definition::of($zone, $iterations);

        self::assertNotNull($definition);

        return $definition;
    }

    /**
     * The `VTIMEZONE` of an object, read the way a server reads one.
     */
    private static function zoneIn(string $text): Component
    {
        $objects = iterator_to_array((new Reader(self::wrap($text)))->objects(), false);
        $calendar = $objects[0] ?? null;

        self::assertInstanceOf(Component::class, $calendar);

        $zone = $calendar->component('VTIMEZONE');

        self::assertInstanceOf(Component::class, $zone);

        return $zone;
    }

    /**
     * A `VTIMEZONE` in the object it travels in, unless it is one already.
     */
    private static function wrap(string $text): string
    {
        if (str_starts_with($text, 'BEGIN:VCALENDAR')) {
            return $text . "\r\n";
        }

        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//davServices//Tests//EN\r\n"
            . $text . "\r\nEND:VCALENDAR\r\n";
    }

    /**
     * The offset the definition applies at a moment, by its written form.
     */
    private static function offsetAt(Definition $definition, string $moment): int
    {
        return $definition->offsetAt(new DateTimeImmutable($moment));
    }

    /**
     * The moment a written `DATE-TIME` comes to in the definition, or null.
     */
    private static function momentOf(Definition $definition, string $value): ?string
    {
        return $definition->momentOf(DateTime::decode($value))?->format('c');
    }

    /**
     * §3.6.5's first example: "all the time zone rules for New York City since
     * April 30, 1967 at 03:00:00 EDT", verbatim.
     */
    private static function newYorkSince1967(): string
    {
        return self::lines([
            'BEGIN:VTIMEZONE',
            'TZID:America/New_York',
            'LAST-MODIFIED:20050809T050000Z',
            'BEGIN:DAYLIGHT',
            'DTSTART:19670430T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=4;BYDAY=-1SU;UNTIL=19730429T070000Z',
            'TZOFFSETFROM:-0500',
            'TZOFFSETTO:-0400',
            'TZNAME:EDT',
            'END:DAYLIGHT',
            'BEGIN:STANDARD',
            'DTSTART:19671029T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU;UNTIL=20061029T060000Z',
            'TZOFFSETFROM:-0400',
            'TZOFFSETTO:-0500',
            'TZNAME:EST',
            'END:STANDARD',
            'BEGIN:DAYLIGHT',
            'DTSTART:19740106T020000',
            'RDATE:19750223T020000',
            'TZOFFSETFROM:-0500',
            'TZOFFSETTO:-0400',
            'TZNAME:EDT',
            'END:DAYLIGHT',
            'BEGIN:DAYLIGHT',
            'DTSTART:19760425T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=4;BYDAY=-1SU;UNTIL=19860427T070000Z',
            'TZOFFSETFROM:-0500',
            'TZOFFSETTO:-0400',
            'TZNAME:EDT',
            'END:DAYLIGHT',
            'BEGIN:DAYLIGHT',
            'DTSTART:19870405T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=4;BYDAY=1SU;UNTIL=20060402T070000Z',
            'TZOFFSETFROM:-0500',
            'TZOFFSETTO:-0400',
            'TZNAME:EDT',
            'END:DAYLIGHT',
            'BEGIN:DAYLIGHT',
            'DTSTART:20070311T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=2SU',
            'TZOFFSETFROM:-0500',
            'TZOFFSETTO:-0400',
            'TZNAME:EDT',
            'END:DAYLIGHT',
            'BEGIN:STANDARD',
            'DTSTART:20071104T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=11;BYDAY=1SU',
            'TZOFFSETFROM:-0400',
            'TZOFFSETTO:-0500',
            'TZNAME:EST',
            'END:STANDARD',
            'END:VTIMEZONE',
        ]);
    }

    /**
     * §3.6.5's second example, "using only the 'DTSTART' property", verbatim.
     */
    private static function newYorkWithDtstartOnly(): string
    {
        return self::lines([
            'BEGIN:VTIMEZONE',
            'TZID:America/New_York',
            'LAST-MODIFIED:20050809T050000Z',
            'BEGIN:STANDARD',
            'DTSTART:20071104T020000',
            'TZOFFSETFROM:-0400',
            'TZOFFSETTO:-0500',
            'TZNAME:EST',
            'END:STANDARD',
            'BEGIN:DAYLIGHT',
            'DTSTART:20070311T020000',
            'TZOFFSETFROM:-0500',
            'TZOFFSETTO:-0400',
            'TZNAME:EDT',
            'END:DAYLIGHT',
            'END:VTIMEZONE',
        ]);
    }

    /**
     * §3.6.5's third example, "the current time zone rules for New York City
     * using a 'RRULE' recurrence pattern", verbatim.
     */
    private static function newYorkWithRules(): string
    {
        return self::lines([
            'BEGIN:VTIMEZONE',
            'TZID:America/New_York',
            'LAST-MODIFIED:20050809T050000Z',
            'TZURL:http://zones.example.com/tz/America-New_York.ics',
            'BEGIN:STANDARD',
            'DTSTART:20071104T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=11;BYDAY=1SU',
            'TZOFFSETFROM:-0400',
            'TZOFFSETTO:-0500',
            'TZNAME:EST',
            'END:STANDARD',
            'BEGIN:DAYLIGHT',
            'DTSTART:20070311T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=2SU',
            'TZOFFSETFROM:-0500',
            'TZOFFSETTO:-0400',
            'TZNAME:EDT',
            'END:DAYLIGHT',
            'END:VTIMEZONE',
        ]);
    }

    /**
     * §3.6.5's fourth example, "a set of rules for a fictitious time zone
     * where the Daylight Time rule has an effective end date", verbatim.
     */
    private static function fictitious(): string
    {
        return self::lines([
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
        ]);
    }

    /**
     * §3.6.5's fifth example, "a set of rules for a fictitious time zone where
     * the first Daylight Time rule has an effective end date. There is a
     * second Daylight Time rule that picks up where the other left off",
     * verbatim.
     */
    private static function fictitiousPickedUp(): string
    {
        return self::lines([
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
            'BEGIN:DAYLIGHT',
            'DTSTART:19990424T020000',
            'RRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=4',
            'TZOFFSETFROM:-0500',
            'TZOFFSETTO:-0400',
            'TZNAME:EDT',
            'END:DAYLIGHT',
            'END:VTIMEZONE',
        ]);
    }

    /**
     * A zone that took a new offset once and has kept it since — one
     * observance, no rule, and the two offsets of its single onset.
     */
    private static function changedOnce(): string
    {
        return self::lines([
            'BEGIN:VTIMEZONE',
            'TZID:Example/ChangedOnce',
            'BEGIN:STANDARD',
            'DTSTART:19700101T000000',
            'TZOFFSETFROM:+0000',
            'TZOFFSETTO:+0530',
            'TZNAME:IST',
            'END:STANDARD',
            'END:VTIMEZONE',
        ]);
    }

    /**
     * A zone whose summers end by listed dates rather than by a rule: one
     * onset in the `DTSTART`, two in a list and one in a second `RDATE`.
     */
    private static function listedOnsets(): string
    {
        return self::lines([
            'BEGIN:VTIMEZONE',
            'TZID:Example/ListedOnsets',
            'BEGIN:STANDARD',
            'DTSTART:20201101T030000',
            'RDATE:20211101T030000,20221101T030000',
            'RDATE:20231101T030000',
            'TZOFFSETFROM:+0200',
            'TZOFFSETTO:+0100',
            'END:STANDARD',
            'BEGIN:DAYLIGHT',
            'DTSTART:20200301T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=3;BYMONTHDAY=1',
            'TZOFFSETFROM:+0100',
            'TZOFFSETTO:+0200',
            'END:DAYLIGHT',
            'END:VTIMEZONE',
        ]);
    }

    /**
     * A zone whose daylight observance carries two rules, which §3.6.5 says
     * SHOULD NOT happen and does not forbid: summer begins on 1 March, is
     * interrupted on 1 April, and begins again on 1 June.
     */
    private static function twoRulesInOneObservance(): string
    {
        return self::lines([
            'BEGIN:VTIMEZONE',
            'TZID:Example/TwoRules',
            'BEGIN:STANDARD',
            'DTSTART:20200401T030000',
            'RRULE:FREQ=YEARLY;BYMONTH=4;BYMONTHDAY=1',
            'TZOFFSETFROM:+0200',
            'TZOFFSETTO:+0100',
            'END:STANDARD',
            'BEGIN:DAYLIGHT',
            'DTSTART:20200301T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=3;BYMONTHDAY=1',
            'RRULE:FREQ=YEARLY;BYMONTH=6;BYMONTHDAY=1',
            'TZOFFSETFROM:+0100',
            'TZOFFSETTO:+0200',
            'END:DAYLIGHT',
            'END:VTIMEZONE',
        ]);
    }

    /**
     * A zone east of Greenwich, where an onset's two offsets are both positive
     * and the arithmetic cannot hide a sign error. No `TZNAME`, so the name of
     * a nameless observance can be asked.
     */
    private static function easternEurope(): string
    {
        return self::lines([
            'BEGIN:VTIMEZONE',
            'TZID:Example/Berlin',
            'BEGIN:DAYLIGHT',
            'DTSTART:20240331T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU',
            'TZOFFSETFROM:+0100',
            'TZOFFSETTO:+0200',
            'END:DAYLIGHT',
            'BEGIN:STANDARD',
            'DTSTART:20241027T030000',
            'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU',
            'TZOFFSETFROM:+0200',
            'TZOFFSETTO:+0100',
            'END:STANDARD',
            'END:VTIMEZONE',
        ]);
    }

    /**
     * The same zone with the daylight rule bounded at its last onset, written
     * in UTC as §3.6.5 requires: the last Sunday in March 2025 is the 30th,
     * and 02:00 local in `+0100` is 01:00 UTC.
     */
    private static function easternEuropeBoundedInUtc(): string
    {
        return self::lines([
            'BEGIN:VTIMEZONE',
            'TZID:Example/Berlin',
            'BEGIN:DAYLIGHT',
            'DTSTART:20240331T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU;UNTIL=20250330T010000Z',
            'TZOFFSETFROM:+0100',
            'TZOFFSETTO:+0200',
            'END:DAYLIGHT',
            'BEGIN:STANDARD',
            'DTSTART:20241027T030000',
            'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU',
            'TZOFFSETFROM:+0200',
            'TZOFFSETTO:+0100',
            'END:STANDARD',
            'END:VTIMEZONE',
        ]);
    }

    /**
     * And the same bound written on the local clock instead, which §3.6.5 does
     * not allow and some writers produce: 02:00 local is the onset's own wall
     * clock, so it names the same last onset as `20240331T010000Z` does.
     */
    private static function easternEuropeBoundedLocally(): string
    {
        return str_replace(
            'BYMONTH=3;BYDAY=-1SU;UNTIL=20250330T010000Z',
            'UNTIL=20250330T020000;BYMONTH=3;BYDAY=-1SU',
            self::easternEuropeBoundedInUtc(),
        );
    }

    /**
     * @param list<string> $lines
     */
    private static function lines(array $lines): string
    {
        return implode("\r\n", $lines);
    }
}
