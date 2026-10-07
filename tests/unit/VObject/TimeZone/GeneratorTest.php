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
use DavServices\VObject\TimeZone\Definition;
use DavServices\VObject\TimeZone\Generator;
use DavServices\VObject\Validator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for writing a `VTIMEZONE` for a span of time. RFC 5545 §3.6.5.
 * R-TZ-05.
 *
 * > R-TZ-05: Es MUSS eine Funktion geben, die für einen Zeitraum eine
 * > minimale, gültige `VTIMEZONE` erzeugt.
 *
 * **Reading one has been possible since P4-11b**; this is the way back. And
 * because reading is tested, the test for writing can be a property rather
 * than a transcript: **what is written is read back with {@see Definition} and
 * held against the zone database**, moment for moment across the span. A
 * definition that merely looks plausible does not survive that.
 *
 * ## What "minimal" means, the memo says itself
 *
 * §3.6.5's second published example is exactly this — a definition of two
 * `DTSTART`s and no rule — and the memo names its limit in the same breath:
 *
 * > Note that this is only suitable for a recurring event that starts on or
 * > later than March 11, 2007 at 03:00:00 EDT (i.e., the earliest effective
 * > transition date and time) and ends no later than March 9, 2008 at
 * > 01:59:59 EST (i.e., latest valid date and time for EST in this scenario).
 *
 * So a generated definition carries **one observance per change of offset in
 * the span, and the last change before it**, and **no `RRULE` at all**:
 * nothing is claimed about a future the zone database has not been told about
 * yet. The span asked for is covered, and the memo's own warning about this
 * shape is the reason the span is a parameter rather than an afterthought.
 *
 * ## And the onset arithmetic is P4-11b's, backwards
 *
 * > "TZOFFSETFROM" is combined with "DTSTART" to define the effective onset
 * > for the time zone sub-component definition.
 *
 * A change at an instant is therefore written as that instant **plus the
 * offset that was in force before it**. Writing it in `TZOFFSETTO` instead —
 * the offset the observance brings — moves every change by the difference of
 * the two, which is the same mistake as reading it that way and just as
 * invisible.
 */
#[CoversClass(Generator::class)]
final class GeneratorTest extends TestCase
{
    /**
     * **The span asked for is covered, to the second.** The definition is
     * written, read back with {@see Definition}, and asked for the offset at
     * both ends of the span and at every change of offset inside it — against
     * the zone database it was written from.
     *
     * This is the test the whole chunk stands on, and it is why the zones
     * below are a list: a northern and a southern hemisphere, a zone whose
     * change is half an hour, one that has not changed since 1945, and one
     * that never changes at all.
     *
     * @param non-empty-string $name
     */
    #[DataProvider('zonesOfEveryShape')]
    public function testTheSpanAskedForIsCovered(string $name, string $from, string $until): void
    {
        $zone = new DateTimeZone($name);
        $start = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($until);

        $definition = Definition::of(Generator::covering($zone, $start, $end));

        self::assertNotNull($definition);

        foreach (self::momentsAcross($zone, $start, $end) as $moment) {
            self::assertSame(
                $zone->getOffset($moment),
                $definition->offsetAt($moment),
                $name . ' at ' . $moment->format('c'),
            );
        }
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function zonesOfEveryShape(): iterable
    {
        yield 'Berlin, a year with both changes' => ['Europe/Berlin', '2024-01-01T00:00:00Z', '2025-01-01T00:00:00Z'];

        yield 'New York, west of Greenwich' => ['America/New_York', '2024-01-01T00:00:00Z', '2025-01-01T00:00:00Z'];

        yield 'Lord Howe, changing by half an hour' => ['Australia/Lord_Howe', '2024-01-01T00:00:00Z', '2025-01-01T00:00:00Z'];

        yield 'Kolkata, unchanged since 1945' => ['Asia/Kolkata', '2024-01-01T00:00:00Z', '2025-01-01T00:00:00Z'];

        yield 'UTC, which never changes' => ['UTC', '2024-01-01T00:00:00Z', '2025-01-01T00:00:00Z'];

        yield 'a span inside one observance' => ['Europe/Berlin', '2024-06-01T00:00:00Z', '2024-08-01T00:00:00Z'];

        yield 'a span of a single second' => ['Europe/Berlin', '2024-03-31T01:00:00Z', '2024-03-31T01:00:01Z'];

        yield 'a span across a decade' => ['Europe/Berlin', '2015-01-01T00:00:00Z', '2025-01-01T00:00:00Z'];

        // Two hours holding a change: a generator looking by the day only
        // would step straight over it.
        yield 'two hours holding a change' => ['Europe/Berlin', '2024-03-31T00:30:00Z', '2024-03-31T02:30:00Z'];

        yield 'two hours holding a change back' => ['Europe/Berlin', '2024-10-27T00:30:00Z', '2024-10-27T02:30:00Z'];
    }

    /**
     * **And what is written is a valid component.** §3.6.5's structural
     * requirements are `VObject\Validator`'s subject, so the generated
     * definition is simply handed to it: `TZID` once, at least one observance,
     * and `DTSTART`, `TZOFFSETFROM` and `TZOFFSETTO` in each of them.
     */
    public function testWhatIsWrittenIsAValidComponent(): void
    {
        $calendar = new Component('VCALENDAR');
        $calendar->add(new Property('VERSION', '2.0'));
        $calendar->add(new Property('PRODID', '-//davServices//Tests//EN'));
        $calendar->add(Generator::covering(
            new DateTimeZone('Europe/Berlin'),
            new DateTimeImmutable('2024-01-01T00:00:00Z'),
            new DateTimeImmutable('2025-01-01T00:00:00Z'),
        ));

        self::assertSame([], (new Validator())->check($calendar));
    }

    /**
     * **One observance for the state at the start of the span, and one per
     * change inside it.** Berlin changes twice in 2024, so a definition for
     * that year carries three: the winter time it is already keeping on New
     * Year's Day, the change into summer time, and the change out of it.
     *
     * **The first is the state, not a change** — it carries the same offset on
     * both sides. Writing the last change *before* the span instead, as
     * §3.6.5's own example happens to, would assert something about time
     * nobody asked about, and a zone that has never changed has no such change
     * to write.
     */
    public function testOneObservanceForTheStateAndOnePerChange(): void
    {
        $written = Generator::covering(
            new DateTimeZone('Europe/Berlin'),
            new DateTimeImmutable('2024-01-01T00:00:00Z'),
            new DateTimeImmutable('2025-01-01T00:00:00Z'),
        );

        self::assertCount(3, $written->components());
        self::assertSame(['20240101T010000', '20241027T030000'], self::onsetsOf($written, 'STANDARD'));
        self::assertSame(['20240331T020000'], self::onsetsOf($written, 'DAYLIGHT'));

        $state = $written->component('STANDARD');

        self::assertInstanceOf(Component::class, $state);
        self::assertSame('+0100', $state->property('TZOFFSETFROM')?->value());
        self::assertSame('+0100', $state->property('TZOFFSETTO')?->value());
    }

    /**
     * **A change into summer time is a `DAYLIGHT`, one out of it a
     * `STANDARD`.** The two sub-components are not decorative: §3.6.5 calls
     * them "either a Standard Time or a Daylight Saving Time observance", and
     * a reader telling a zone's summer from its winter has nothing else to go
     * by.
     *
     * The span here begins in summer, so the state at its start is the
     * `DAYLIGHT` one and the only change is out of it.
     */
    public function testTheKindOfAnObservanceFollowsTheOffsetItBrings(): void
    {
        $written = Generator::covering(
            new DateTimeZone('Europe/Berlin'),
            new DateTimeImmutable('2024-06-01T00:00:00Z'),
            new DateTimeImmutable('2024-12-01T00:00:00Z'),
        );

        self::assertSame(['20240601T020000'], self::onsetsOf($written, 'DAYLIGHT'));
        self::assertSame(['20241027T030000'], self::onsetsOf($written, 'STANDARD'));
    }

    /**
     * **An offset with seconds is written with seconds**, the grammar making
     * them optional rather than absent: `time-numzone = ("+" / "-") time-hour
     * time-minute [time-second]`. The database keeps the local mean times of
     * the nineteenth century, and Berlin's was `+00:53:28` until 1893 — a
     * definition that rounded it would move every appointment of that span by
     * half a minute.
     */
    public function testAnOffsetWithSecondsIsWrittenWithSeconds(): void
    {
        $written = Generator::covering(
            new DateTimeZone('Europe/Berlin'),
            new DateTimeImmutable('1890-01-01T00:00:00Z'),
            new DateTimeImmutable('1891-01-01T00:00:00Z'),
        );
        $state = $written->component('STANDARD');

        self::assertInstanceOf(Component::class, $state);
        self::assertSame('+005328', $state->property('TZOFFSETTO')?->value());

        // And read back it is the same zone again.
        $definition = Definition::of($written);

        self::assertNotNull($definition);
        self::assertSame(
            (new DateTimeZone('Europe/Berlin'))->getOffset(new DateTimeImmutable('1890-07-01T12:00:00Z')),
            $definition->offsetAt(new DateTimeImmutable('1890-07-01T12:00:00Z')),
        );
    }

    /**
     * **The onset is written in the offset that was in force before it.**
     * Berlin changes at 01:00 UTC on the 31st of March 2024, and the offset
     * before it is `+0100`, so the onset reads `20240331T020000`.
     *
     * **Written in `TZOFFSETTO` it would read `20240331T030000`**, which is
     * the hour that does not exist — and a reader following §3.6.5 would then
     * put the change an hour late for ever after.
     */
    public function testTheOnsetIsWrittenInTheOffsetBeforeIt(): void
    {
        $written = Generator::covering(
            new DateTimeZone('Europe/Berlin'),
            new DateTimeImmutable('2024-03-01T00:00:00Z'),
            new DateTimeImmutable('2024-04-01T00:00:00Z'),
        );

        $daylight = $written->component('DAYLIGHT');

        self::assertInstanceOf(Component::class, $daylight);
        self::assertSame('20240331T020000', $daylight->property('DTSTART')?->value());
        self::assertSame('+0100', $daylight->property('TZOFFSETFROM')?->value());
        self::assertSame('+0200', $daylight->property('TZOFFSETTO')?->value());
    }

    /**
     * **A zone that never changes still gets an observance**, §3.6.5 asking
     * for at least one: "One of 'standardc' or 'daylightc' MUST occur". It
     * keeps the same offset on both sides, which is what a zone that does not
     * change looks like written down.
     */
    public function testAZoneThatNeverChangesStillGetsAnObservance(): void
    {
        $written = Generator::covering(
            new DateTimeZone('Asia/Kolkata'),
            new DateTimeImmutable('2024-01-01T00:00:00Z'),
            new DateTimeImmutable('2025-01-01T00:00:00Z'),
        );
        $standard = $written->component('STANDARD');

        self::assertCount(1, $written->components());
        self::assertInstanceOf(Component::class, $standard);
        self::assertSame('+0530', $standard->property('TZOFFSETFROM')?->value());
        self::assertSame('+0530', $standard->property('TZOFFSETTO')?->value());
    }

    /**
     * **Half an hour is written as half an hour.** Lord Howe Island moves its
     * clocks by thirty minutes, which is the case where an offset handled as
     * whole hours looks right everywhere else and is wrong there.
     */
    public function testAChangeOfHalfAnHourIsWrittenAsItIs(): void
    {
        $written = Generator::covering(
            new DateTimeZone('Australia/Lord_Howe'),
            new DateTimeImmutable('2024-09-01T00:00:00Z'),
            new DateTimeImmutable('2024-11-01T00:00:00Z'),
        );

        $daylight = $written->component('DAYLIGHT');

        self::assertInstanceOf(Component::class, $daylight);
        self::assertSame('+1030', $daylight->property('TZOFFSETFROM')?->value());
        self::assertSame('+1100', $daylight->property('TZOFFSETTO')?->value());
    }

    /**
     * **Nothing is claimed about the future: no `RRULE` is written.** A rule
     * would say that the zone goes on changing as it does now, which is a
     * thing no zone database knows and several governments have disproved at
     * short notice. §3.6.5's own minimal example carries none either.
     */
    public function testNoRuleIsWritten(): void
    {
        $written = Generator::covering(
            new DateTimeZone('Europe/Berlin'),
            new DateTimeImmutable('2024-01-01T00:00:00Z'),
            new DateTimeImmutable('2025-01-01T00:00:00Z'),
        );

        foreach ($written->components() as $observance) {
            self::assertNull($observance->property('RRULE'), $observance->name());
            self::assertNull($observance->property('RDATE'), $observance->name());
        }
    }

    /**
     * **A zone whose own name is no registry name is written all the same, and
     * the definition carries itself.** PHP resolves `GMT+0` to a fixed-offset
     * zone it calls `+00:00` (one of exactly two such names of 597, see
     * `ResolverTest`), and §3.2.19 defines no naming convention — "This
     * document does not define a naming convention for time zone
     * identifiers" — so the name is written as the zone gives it.
     *
     * **What makes that safe is the definition beside it:** a reader that
     * cannot place the name evaluates the observances instead, which is
     * R-TZ-02, and the round trip here shows it works.
     */
    public function testAZoneWhoseNameIsNoRegistryNameCarriesItself(): void
    {
        $zone = new DateTimeZone('GMT+0');
        $written = Generator::covering(
            $zone,
            new DateTimeImmutable('2024-01-01T00:00:00Z'),
            new DateTimeImmutable('2025-01-01T00:00:00Z'),
        );

        self::assertSame('+00:00', $written->property('TZID')?->value());

        $definition = Definition::of($written);

        self::assertNotNull($definition);
        self::assertSame(0, $definition->offsetAt(new DateTimeImmutable('2024-07-01T12:00:00Z')));
    }

    /**
     * **A single moment is a span.** "Which zone applies at this instant" is a
     * fair question, and the answer is a definition of one observance: the
     * state at that moment. Refusing it would turn a question with an answer
     * into an error.
     */
    public function testASingleMomentIsASpan(): void
    {
        $moment = new DateTimeImmutable('2024-07-01T12:00:00Z');
        $written = Generator::covering(new DateTimeZone('Europe/Berlin'), $moment, $moment);

        self::assertCount(1, $written->components());

        $definition = Definition::of($written);

        self::assertNotNull($definition);
        self::assertSame(2 * 3600, $definition->offsetAt($moment));
    }

    /**
     * **A span that ends before it begins is refused.** There is no definition
     * that covers it, and writing one that covered a span nobody asked for
     * would be worse than saying so.
     */
    public function testASpanThatEndsBeforeItBeginsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/span/');

        Generator::covering(
            new DateTimeZone('Europe/Berlin'),
            new DateTimeImmutable('2025-01-01T00:00:00Z'),
            new DateTimeImmutable('2024-01-01T00:00:00Z'),
        );
    }

    /**
     * The moments worth asking about across a span: both ends, and both sides
     * of every change of offset the database makes inside it.
     *
     * @return list<DateTimeImmutable>
     */
    private static function momentsAcross(
        DateTimeZone $zone,
        DateTimeImmutable $from,
        DateTimeImmutable $until,
    ): array {
        $moments = [$from, $until];

        // Every zone this list names answers with a list of its own, so the
        // database is asked directly here — the generator has to find the
        // changes without being handed them.
        foreach ($zone->getTransitions($from->getTimestamp(), $until->getTimestamp()) as $change) {
            if ($change['ts'] <= $from->getTimestamp()) {
                continue;
            }

            $moments[] = new DateTimeImmutable('@' . $change['ts']);
            $moments[] = new DateTimeImmutable('@' . ($change['ts'] - 1));
        }

        // And the middle, for a span whose ends are both in the same
        // observance.
        $moments[] = new DateTimeImmutable(
            '@' . intdiv($from->getTimestamp() + $until->getTimestamp(), 2),
        );

        return $moments;
    }

    /**
     * The onsets of every observance of that kind, in the order they stand.
     *
     * @return list<string>
     */
    private static function onsetsOf(Component $written, string $kind): array
    {
        $onsets = [];

        foreach ($written->components($kind) as $observance) {
            $onsets[] = (string) $observance->property('DTSTART')?->value();
        }

        return $onsets;
    }
}
