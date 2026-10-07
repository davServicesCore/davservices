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
     * **A name the time zone database has retired still names its zone.**
     * `Asia/Calcutta` became `Asia/Kolkata` and `Europe/Kiev` became
     * `Europe/Kyiv`; data written before that is not wrong, and a reader that
     * refused it would be.
     *
     * @param non-empty-string $written
     */
    #[DataProvider('theRetiredNames')]
    public function testARetiredNameStillNamesItsZone(string $written, int $offset): void
    {
        $zone = self::zone([['TZID', $written]]);

        self::assertNotNull($zone);
        self::assertSame($offset, $zone->getOffset(new DateTimeImmutable('2024-01-15')));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function theRetiredNames(): iterable
    {
        yield 'Asia/Calcutta, now Asia/Kolkata' => ['Asia/Calcutta', 19800];

        yield 'Europe/Kiev, now Europe/Kyiv' => ['Europe/Kiev', 7200];
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
