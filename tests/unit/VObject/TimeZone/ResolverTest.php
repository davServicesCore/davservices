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
use DavServices\VObject\TimeZone\Aliases;
use DavServices\VObject\TimeZone\Resolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for naming the zone a `VTIMEZONE` means. RFC 5545 §3.2.19 and
 * §3.6.5. R-TZ-01.
 *
 * **A `TZID` is whatever the system that wrote it calls a zone.** §3.2.19
 * says almost nothing about the name — only that "The presence of the SOLIDUS
 * character as a prefix, indicates that this 'TZID' represents a unique ID in
 * a globally defined time zone registry (when such registry is defined)" —
 * and nothing at all about it being IANA's. So R-TZ-01 asks for four ways in,
 * in this order: the `TZID` itself, `X-LIC-LOCATION`, `X-MICROSOFT-CDO-TZID`,
 * and a maintained catalogue of the names other systems use.
 *
 * ## Why a miss is an answer
 *
 * **{@see Resolver} never invents UTC.** R-TZ-02: "Ein stiller Rückfall auf
 * UTC DARF NICHT ohne Protokolleintrag erfolgen" — and a library has no log,
 * so it has something better: it says it does not know. A caller that gets
 * null has to decide, which is the whole point; a caller that got UTC would
 * never find out that an appointment had moved.
 *
 * Evaluating the `VTIMEZONE` itself where nothing names it is R-TZ-02's other
 * half and the chunk after this one.
 *
 * ## The one input that is not here
 *
 * `X-MICROSOFT-CDO-TZID` carries a **number**, and the table that turns it
 * into a zone is Microsoft's old zone index — defined in MS-OXOCAL, and not
 * in the Windows registry, which has not carried an `Index` value for many
 * versions. It is named as missing rather than guessed at: an index read
 * wrongly puts an appointment in the wrong country. The test for it is here
 * too, as a test that the property is **not** read, so that nobody believes
 * it is.
 */
#[CoversClass(Resolver::class)]
#[CoversClass(Aliases::class)]
final class ResolverTest extends TestCase
{
    /**
     * **An IANA name is the ordinary case**, and needs no catalogue.
     */
    public function testAnIanaNameIsTakenAsItStands(): void
    {
        self::assertSame('Europe/Berlin', self::resolve([['TZID', 'Europe/Berlin']]));
    }

    /**
     * **Every name this platform's database carries names the zone the
     * database gives it** — which is the whole of what a reader can promise,
     * and why {@see DateTimeZone::ALL_WITH_BC} is the list asked rather than
     * `ALL`. The backward-compatibility names a build still ships are names
     * real data was written with: `Asia/Calcutta` before it became
     * `Asia/Kolkata`, `Europe/Kiev` before `Europe/Kyiv`. Data written before
     * a rename is not wrong, and a reader that refused it would be.
     *
     * **Which of them a platform carries is the platform's business**, and
     * that is not a detail: Debian and Ubuntu moved the backward names into a
     * separate `tzdata-legacy` package, so a build that lists 597 names and
     * one that lists 419 are both ordinary. Ubuntu's PHP reads
     * `/usr/share/zoneinfo` rather than a copy of its own, so the package
     * decides what `DateTimeZone` knows. **Hence a property over whatever the
     * database has**, which is stronger than two names by hand and true on
     * either machine — where hard-coded names would have been a test of
     * `tzdata` wearing this library's clothes.
     *
     * **And nothing a client is owed depends on it**, because §3.6.5 has the
     * definition travel with the data: "An individual 'VTIMEZONE' calendar
     * component MUST be specified for each unique 'TZID' parameter value
     * specified in the iCalendar object." §3.2.19 says it a second time. So a
     * name is a shortcut for data that keeps that MUST and a courtesy to data
     * that breaks it; where the shortcut is not there, R-TZ-02's path reads
     * the definition the object carries
     * ({@see \DavServices\VObject\TimeZone\Zones}).
     */
    public function testEveryNameTheDatabaseCarriesNamesTheZoneItGivesIt(): void
    {
        $names = DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC);
        $instants = [new DateTimeImmutable('2024-01-15'), new DateTimeImmutable('2024-07-15')];
        $wrong = [];

        self::assertNotSame([], $names);

        foreach ($names as $name) {
            $zone = self::zone([['TZID', $name]]);

            if ($zone === null) {
                $wrong[$name] = 'no zone at all';

                continue;
            }

            foreach ($instants as $instant) {
                $owed = (new DateTimeZone($name))->getOffset($instant);

                if ($zone->getOffset($instant) !== $owed) {
                    $wrong[$name] = sprintf('%+d where %+d was owed', $zone->getOffset($instant), $owed);
                }
            }
        }

        self::assertSame([], $wrong);
    }

    /**
     * **And it comes back under the database's own spelling, which need not be
     * the name asked for.** `GMT+0` and `GMT-0` are names a build may carry,
     * and `DateTimeZone` answers both with a fixed-offset zone it calls
     * `+00:00` — the zone asked for, under another name.
     *
     * So a caller that compared `getName()` against its own `TZID` to decide
     * whether it got what it asked for would be wrong exactly there.
     * Comparing the zone is right; comparing the name is not. **What is
     * asserted is that the resolver renames nothing of its own**: whatever
     * spelling comes back is the one `DateTimeZone` would have given, so the
     * surprise belongs to the database and this library adds none.
     */
    public function testTheSpellingThatComesBackIsTheDatabasesOwn(): void
    {
        $renamed = [];

        foreach (DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC) as $name) {
            $under = self::resolve([['TZID', $name]]);

            self::assertSame((new DateTimeZone($name))->getName(), $under, $name);

            if ($under !== $name) {
                $renamed[$name] = $under;
            }
        }

        // Where a build carries such a name, the zone is still the right one:
        // the two halves of the lesson, in one case.
        foreach (array_keys($renamed) as $name) {
            self::assertSame(
                (new DateTimeZone($name))->getOffset(new DateTimeImmutable('2024-07-15')),
                self::zone([['TZID', $name]])?->getOffset(new DateTimeImmutable('2024-07-15')),
                $name,
            );
        }
    }

    /**
     * **Microsoft's name through the catalogue.** `W. Europe Standard Time`
     * is what every Microsoft calendaring product writes, and the registry
     * that invented it says the zone covers "Amsterdam, Berlin, Bern, Rome,
     * Stockholm, Vienna".
     */
    public function testAWindowsNameGoesThroughTheCatalogue(): void
    {
        self::assertSame('Europe/Berlin', self::resolve([['TZID', 'W. Europe Standard Time']]));
    }

    /**
     * **And the comparison is exact**, §3.1 making property values
     * case-sensitive — "all other property values are case-sensitive, unless
     * otherwise stated". A system that writes the name in another case has
     * written a `TZID` nothing defines.
     */
    public function testACatalogueNameInAnotherCaseIsNotTheSameName(): void
    {
        self::assertNull(self::zone([['TZID', 'w. europe standard time']]));
    }

    /**
     * **A `TZID` prefixed with a solidus names a registry**, §3.2.19: "The
     * presence of the SOLIDUS character as a prefix, indicates that this
     * 'TZID' represents a unique ID in a globally defined time zone registry"
     * — and the registry everybody means is IANA's, written after a prefix of
     * the writer's own choosing.
     *
     * @param non-empty-string $written
     */
    #[DataProvider('theSolidusForms')]
    public function testASolidusPrefixedNameIsFound(string $written, string $expected): void
    {
        self::assertSame($expected, self::resolve([['TZID', $written]]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function theSolidusForms(): iterable
    {
        yield 'the bare prefix' => ['/Europe/Berlin', 'Europe/Berlin'];

        yield "Mozilla's" => ['/mozilla.org/20070129_1/Europe/Berlin', 'Europe/Berlin'];

        yield "libical's" => [
            '/freeassociation.sourceforge.net/Tzfile/Europe/Berlin',
            'Europe/Berlin',
        ];

        // Three segments, so the longest trailing name has to be tried first.
        yield 'a zone of three segments' => [
            '/mozilla.org/20070129_1/America/Indiana/Indianapolis',
            'America/Indiana/Indianapolis',
        ];
    }

    /**
     * **A prefix that names no zone at all is no zone**, however it is
     * written. Nothing is guessed from the shape of a name.
     */
    public function testAPrefixedNameThatNamesNoZoneIsRefused(): void
    {
        self::assertNull(self::zone([['TZID', '/example.com/Nowhere/Special']]));
    }

    /**
     * **`X-LIC-LOCATION` is what libical writes beside a name it knew was
     * not IANA's**, and it is read where the `TZID` cannot be.
     */
    public function testTheLocationIsReadWhereTheNameCannotBe(): void
    {
        self::assertSame('Europe/Berlin', self::resolve([
            ['TZID', 'Mitteleuropäische Zeit'],
            ['X-LIC-LOCATION', 'Europe/Berlin'],
        ]));
    }

    /**
     * **The order is R-TZ-01's own**: the `TZID` first, then
     * `X-LIC-LOCATION`. Where both name a zone, the `TZID` is the one the
     * memo defines and the other is a hint beside it.
     */
    public function testTheNameComesBeforeTheLocation(): void
    {
        self::assertSame('Europe/Berlin', self::resolve([
            ['TZID', 'Europe/Berlin'],
            ['X-LIC-LOCATION', 'America/New_York'],
        ]));
    }

    /**
     * **And the catalogue comes after both**, because it answers from a name
     * alone where the other two carry a statement.
     */
    public function testTheLocationComesBeforeTheCatalogue(): void
    {
        self::assertSame('America/New_York', self::resolve([
            ['TZID', 'W. Europe Standard Time'],
            ['X-LIC-LOCATION', 'America/New_York'],
        ]));
    }

    /**
     * **`X-MICROSOFT-CDO-TZID` is not read**, and this test is here so that
     * nobody believes it is. The number is an index into Microsoft's old zone
     * list, defined in MS-OXOCAL; the table is not in the registry and is not
     * guessed at here.
     */
    public function testTheMicrosoftIndexIsNotRead(): void
    {
        self::assertNull(self::zone([
            ['TZID', 'Mitteleuropäische Zeit'],
            ['X-MICROSOFT-CDO-TZID', '4'],
        ]));
    }

    /**
     * **A component with no name at all names nothing.**
     */
    public function testAComponentWithoutANameNamesNothing(): void
    {
        self::assertNull(self::zone([['TZOFFSETTO', '+0100']]));
    }

    /**
     * **Nothing falls back to UTC**, which is R-TZ-02's prohibition read as a
     * design rather than as a log line: a caller that is told "I do not know"
     * can decide, and one that was handed UTC could not.
     */
    public function testAnUnknownZoneIsNotQuietlyUtc(): void
    {
        $zone = self::zone([['TZID', 'Nowhere/Special']]);

        self::assertNull($zone);
    }

    /**
     * **A name alone can be asked**, because a caller usually holds a `TZID`
     * parameter rather than the component it belongs to.
     */
    public function testANameCanBeAskedOnItsOwn(): void
    {
        self::assertSame('Europe/Berlin', Resolver::forName('W. Europe Standard Time')?->getName());
        self::assertNull(Resolver::forName('Nowhere/Special'));
    }

    /**
     * The zone a `VTIMEZONE` of these properties comes to, by name.
     *
     * @param list<array{string, string}> $properties
     */
    private static function resolve(array $properties): ?string
    {
        return self::zone($properties)?->getName();
    }

    /**
     * @param list<array{string, string}> $properties
     */
    private static function zone(array $properties): ?DateTimeZone
    {
        $component = new Component('VTIMEZONE');

        foreach ($properties as $property) {
            $component->add(new Property($property[0], $property[1]));
        }

        return Resolver::resolve($component);
    }
}
