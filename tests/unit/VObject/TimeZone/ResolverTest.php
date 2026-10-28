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
use Exception;
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
     * separate `tzdata-legacy` package, and their PHP reads
     * `/usr/share/zoneinfo` rather than a copy of its own, so the package
     * decides what `DateTimeZone` knows. A build that carries them lists 597
     * names, 178 of them backward-only; a build without it lists fewer, and
     * both are ordinary. **Hence a property over whatever the database has**,
     * which is stronger than two names by hand and true on either machine —
     * where hard-coded names would have been a test of `tzdata` wearing this
     * library's clothes.
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
            $owed = self::databaseZone($name);

            if ($owed === null) {
                continue;
            }

            $zone = self::zone([['TZID', $name]]);

            if ($zone === null) {
                $wrong[$name] = 'no zone at all';

                continue;
            }

            foreach ($instants as $instant) {
                if ($zone->getOffset($instant) !== $owed->getOffset($instant)) {
                    $wrong[$name] = sprintf(
                        '%+d where %+d was owed',
                        $zone->getOffset($instant),
                        $owed->getOffset($instant),
                    );
                }
            }
        }

        self::assertSame([], $wrong);
    }

    /**
     * **A name that builds but is no identifier is a miss, and that guard is
     * worth an hour.** `new DateTimeZone('CEST')` succeeds and keeps `+02:00`
     * — *in January*, Central European Summer Time having no winter. So a
     * `TZID` of `CEST` resolved by construction alone would put every
     * appointment of the dark half of the year an hour out, and no client
     * would be told. An offset is the same lesson in another spelling: PHP
     * will build `+02:00` and no registry ever named it.
     *
     * §3.2.19 asks for an identifier rather than a shorthand: the `TZID`
     * parameter's "value […] will be equal to the value of the 'TZID'
     * property for the matching time zone definition", and a definition is
     * named. **So being buildable is not enough**, which is the second of
     * {@see Resolver}'s two questions and the one with the quiet failure
     * behind it.
     *
     * @param non-empty-string $written
     */
    #[DataProvider('namesThatBuildButNameNoZone')]
    public function testANameThatBuildsButIsNoIdentifierIsAMiss(string $written): void
    {
        // The premise of the case: PHP really does accept it.
        self::assertNotNull(self::databaseZone($written), $written);

        self::assertNull(self::zone([['TZID', $written]]), $written);
        self::assertNull(Resolver::forName($written), $written);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namesThatBuildButNameNoZone(): iterable
    {
        yield 'a summer-time abbreviation' => ['CEST'];

        yield 'an offset rather than a name' => ['+02:00'];
    }

    /**
     * **And a name the database lists but cannot make a zone of is a miss and
     * never an exception.** This is what {@see Resolver}'s whole shape rests
     * on: a `TZID` it does not understand answers null, so the caller decides
     * (R-TZ-02). A `DateInvalidTimeZoneException` escaping into a `PUT` would
     * turn one unreadable property into a `500`.
     *
     * **The two halves of that are not the same question**, which is the
     * finding this case exists for: on Ubuntu 26 the list carries
     * `leapseconds`, a plain data file in `/usr/share/zoneinfo` that PHP
     * enumerates and then refuses to open as a zone. So a name being listed
     * is not a promise that a zone can be built from it, and asking the list
     * is no longer enough on its own.
     */
    public function testANameTheDatabaseListsButCannotBuildIsAMissAndNotAThrow(): void
    {
        $listed = DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC);
        $unbuildable = array_values(array_filter(
            $listed,
            static fn (string $name): bool => self::databaseZone($name) === null,
        ));

        foreach ($unbuildable as $name) {
            self::assertNull(self::zone([['TZID', $name]]), $name);
            self::assertNull(Resolver::forName($name), $name);
        }

        // A name of this library's own invention is the unconditional half,
        // so the case says something on every platform — including those
        // whose list holds nothing but zones.
        self::assertNull(Resolver::forName('Nowhere/Special'));
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
            $owed = self::databaseZone($name);

            if ($owed === null) {
                continue;
            }

            $under = self::resolve([['TZID', $name]]);

            self::assertSame($owed->getName(), $under, $name);

            if ($under !== $name) {
                $renamed[$name] = $owed;
            }
        }

        // Where a build carries such a name, the zone is still the right one:
        // the two halves of the lesson, in one case.
        foreach ($renamed as $name => $owed) {
            self::assertSame(
                $owed->getOffset(new DateTimeImmutable('2024-07-15')),
                self::zone([['TZID', $name]])?->getOffset(new DateTimeImmutable('2024-07-15')),
                $name,
            );
        }
    }

    /**
     * The zone the database itself makes of that name, or null where it lists
     * the name and cannot.
     *
     * **A case may not ask `new DateTimeZone` without catching**, which is the
     * same lesson {@see Resolver} has to learn: `/usr/share/zoneinfo` holds
     * files that are not zones, and PHP lists some of them.
     */
    private static function databaseZone(string $name): ?DateTimeZone
    {
        try {
            return new DateTimeZone($name);
        } catch (Exception) {
            return null;
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
