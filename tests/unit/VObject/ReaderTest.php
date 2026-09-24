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

namespace DavServices\Tests\Unit\VObject;

use DavServices\VObject\Component;
use DavServices\VObject\ParseError;
use DavServices\VObject\Property;
use DavServices\VObject\Reader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 5545 §3.4 and §3.6 and RFC 6350 §6.1.
 *
 * **This is what joins the two halves of P4 so far.** The lexer reads octets
 * into content lines; the model holds properties and components. Nothing
 * turned one into the other, so until now this library could not read a
 * calendar file at all.
 *
 * The shape is the same in both specifications:
 *
 *     iana-comp = "BEGIN" ":" iana-token CRLF 1*contentline "END" ":" iana-token CRLF
 *
 * and a stream may carry more than one: `icalstream = 1*icalobject`
 * (RFC 5545 §3.4), `vcard-entity = 1*vcard` (RFC 6350 §3.3).
 *
 * ## Two sentences decide how an unknown component is treated
 *
 * RFC 5545 §3.6: "Applications **MUST ignore** x-comp and iana-comp values
 * they don't recognize." And immediately after: "SHOULD **NOT silently drop**
 * any components as that can lead to user data loss."
 *
 * Read together they are not in tension: *ignore* means do not choke on it,
 * *do not drop* means keep it. So an unknown component is read like any
 * other and handed on whole. It is also why {@see Component} keeps no list of
 * names it knows.
 *
 * ## Strict first, lenient later
 *
 * Every question this reader raises is a question of R-VOBJ-03's two modes,
 * and this is where they first arise: an `END` with the wrong name, an `END`
 * with no `BEGIN`, a file that stops early, content before the first `BEGIN`.
 *
 * **They are all refused here**, and P4-06 will add the lenient side. That
 * order is deliberate: a strict reader can be made lenient, while a lenient
 * one can never be made strict again — by then nobody knows which files
 * depended on the leniency.
 */
#[CoversClass(Reader::class)]
final class ReaderTest extends TestCase
{
    /**
     * RFC 5545 §3.4's own example, word for word.
     */
    private const THE_EXAMPLE = "BEGIN:VCALENDAR\r\n"
        . "VERSION:2.0\r\n"
        . "PRODID:-//hacksw/handcal//NONSGML v1.0//EN\r\n"
        . "BEGIN:VEVENT\r\n"
        . "UID:19970610T172345Z-AF23B2@example.com\r\n"
        . "DTSTAMP:19970610T172345Z\r\n"
        . "DTSTART:19970714T170000Z\r\n"
        . "DTEND:19970715T040000Z\r\n"
        . "SUMMARY:Bastille Day Party\r\n"
        . "END:VEVENT\r\n"
        . "END:VCALENDAR\r\n";

    /**
     * The whole point: a file goes in, an object comes out.
     */
    public function testReadsTheExampleFromTheSpecification(): void
    {
        $calendar = $this->one(self::THE_EXAMPLE);

        self::assertSame('VCALENDAR', $calendar->name());
        self::assertSame('2.0', $calendar->property('VERSION')?->value());
        self::assertSame('Bastille Day Party', $calendar->component('VEVENT')?->property('SUMMARY')?->value());
    }

    /**
     * **The properties of the object itself stay with the object**, and the
     * ones inside the event stay inside it. RFC 5545 §3.6: "The calendar
     * properties are attributes that apply to the calendar object as a
     * whole."
     */
    public function testEachPropertyStaysWhereItWasWritten(): void
    {
        $calendar = $this->one(self::THE_EXAMPLE);

        self::assertSame(['VERSION', 'PRODID'], self::namesOf($calendar->properties()));
        self::assertNull($calendar->property('SUMMARY'));
        self::assertCount(5, $calendar->component('VEVENT')?->properties() ?? []);
    }

    /**
     * And in the order they were written, which is what a round trip needs
     * (R-VOBJ-05).
     */
    public function testTheOrderOfTheFileIsTheOrderOfTheObject(): void
    {
        self::assertSame(['VERSION', 'PRODID', 'VEVENT'], self::namesOf($this->one(self::THE_EXAMPLE)->children()));
    }

    /**
     * **Components nest as deep as the file does.** A `VALARM` lives inside a
     * `VEVENT` which lives inside the calendar (RFC 5545 §3.6.6), and that is
     * three deep before anything unusual has happened.
     */
    public function testComponentsNestAsDeepAsTheFileDoes(): void
    {
        $calendar = $this->one(
            "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nBEGIN:VALARM\r\nACTION:DISPLAY\r\n"
            . "END:VALARM\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
        );

        $alarm = $calendar->component('VEVENT')?->component('VALARM');

        self::assertInstanceOf(Component::class, $alarm);
        self::assertSame('DISPLAY', $alarm->property('ACTION')?->value());
    }

    /**
     * **RFC 5545 §3.4: `icalstream = 1*icalobject`**, and RFC 6350 §3.3 says
     * the same of vCards: "multiple iCalendar objects can be sequentially
     * grouped together in an iCalendar stream". An address book export is a
     * thousand vCards one after another, so a reader that answered with one
     * would read the first contact and drop the rest.
     */
    public function testAStreamMayCarryMoreThanOneObject(): void
    {
        $objects = $this->read(
            "BEGIN:VCARD\r\nFN:Ada\r\nEND:VCARD\r\nBEGIN:VCARD\r\nFN:Bob\r\nEND:VCARD\r\n",
        );

        self::assertSame(['VCARD', 'VCARD'], self::namesOf($objects));
        self::assertSame(['Ada', 'Bob'], array_map(
            static fn (Component $card): ?string => $card->property('FN')?->value(),
            $objects,
        ));
    }

    /**
     * A vCard is read by the same reader, because RFC 6350 §6.1 gives it the
     * same shape — `BEGIN:VCARD` … `END:VCARD` — and RFC 5545 §3.1 says its
     * own syntax is "similar to that defined by [RFC2425]" in the first
     * place.
     */
    public function testAVCardIsReadTheSameWay(): void
    {
        $card = $this->one("BEGIN:VCARD\r\nVERSION:4.0\r\nFN:Ada Lovelace\r\nEND:VCARD\r\n");

        self::assertSame('VCARD', $card->name());
        self::assertSame('Ada Lovelace', $card->property('FN')?->value());
    }

    /**
     * **RFC 6350 §6.1.1 and §6.1.2: "The value is case-insensitive."** And
     * RFC 5545 §3.1 puts enumerated property values in the same sentence as
     * names. So `BEGIN:vevent` is closed by `END:VEVENT`.
     */
    public function testTheNamesOfBeginAndEndAreComparedWithoutCase(): void
    {
        $calendar = $this->one("BEGIN:vcalendar\r\nBEGIN:VEvent\r\nEND:vEVENT\r\nEND:VCALENDAR\r\n");

        self::assertSame('vcalendar', $calendar->name(), 'and the spelling of BEGIN is the one that is kept');
        self::assertSame('VEvent', $calendar->component('VEVENT')?->name());
    }

    /**
     * **RFC 5545 §3.6: "Applications MUST ignore x-comp and iana-comp values
     * they don't recognize"**, and in the next breath: "SHOULD NOT silently
     * drop any components as that can lead to user data loss."
     *
     * Read together those are not in tension. *Ignore* means do not choke on
     * it; *do not drop* means keep it. Anything else loses somebody's data to
     * a reader that was merely being tidy.
     */
    public function testAComponentNobodyHasHeardOfIsKeptWhole(): void
    {
        $calendar = $this->one(
            "BEGIN:VCALENDAR\r\nBEGIN:X-WHATEVER\r\nX-THING:kept\r\nEND:X-WHATEVER\r\nEND:VCALENDAR\r\n",
        );

        self::assertSame('kept', $calendar->component('X-WHATEVER')?->property('X-THING')?->value());
    }

    /**
     * **The folding is already gone by the time a property is built**, since
     * the reader takes its lines from the lexer. RFC 5545 §3.1: "When parsing
     * a content line, folded lines MUST first be unfolded."
     */
    public function testAFoldedLineArrivesUnfolded(): void
    {
        $calendar = $this->one("BEGIN:VCALENDAR\r\nSUMMARY:Lunch wi\r\n th Ada\r\nEND:VCALENDAR\r\n");

        self::assertSame('Lunch with Ada', $calendar->property('SUMMARY')?->value());
    }

    /**
     * And a property keeps everything the line said — its parameters and, in
     * a vCard, its group.
     */
    public function testAPropertyKeepsItsParametersAndGroup(): void
    {
        $card = $this->one("BEGIN:VCARD\r\nitem1.EMAIL;TYPE=work:ada@example.com\r\nEND:VCARD\r\n");
        $email = $card->property('EMAIL');

        self::assertInstanceOf(Property::class, $email);
        self::assertSame('item1', $email->group());
        self::assertSame(['work'], $email->parameter('TYPE')?->values());
    }

    /**
     * **Nothing at all is no objects**, rather than one empty one.
     */
    public function testAnEmptySourceHasNoObjects(): void
    {
        self::assertSame([], $this->read(''));
    }

    /**
     * A stream is read as readily as a string, because that is what a request
     * body arrives as.
     */
    public function testReadsFromAStream(): void
    {
        $stream = fopen('php://temp', 'r+b');

        self::assertIsResource($stream);
        fwrite($stream, "BEGIN:VCARD\r\nFN:Ada\r\nEND:VCARD\r\n");
        rewind($stream);

        self::assertSame('Ada', $this->one($stream)->property('FN')?->value());
    }

    /**
     * **Everything the grammar makes impossible is refused**, and each for
     * the same reason: there is no object to hand on. What the lenient mode
     * of R-VOBJ-03 makes of these is P4-06's question — and it is asked of a
     * reader that refuses them, not of one that already let them through.
     *
     * @param non-empty-string $source
     */
    #[DataProvider('whatCannotBeRead')]
    public function testWhatCannotBeReadIsRefused(string $source, string $because): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches($because);

        $this->read($source);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function whatCannotBeRead(): iterable
    {
        yield 'an END that closes something else' => [
            "BEGIN:VCALENDAR\r\nEND:VEVENT\r\n",
            '/VEVENT.*VCALENDAR/',
        ];

        yield 'an END with no BEGIN' => ["END:VCALENDAR\r\n", '/no component is open/'];

        yield 'a file that stops before the END' => [
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n",
            '/still open/',
        ];

        yield 'a property before the first BEGIN' => ["VERSION:2.0\r\n", '/outside any component/'];

        yield 'a BEGIN that names nothing' => ["BEGIN:\r\nEND:\r\n", '/names the component/'];
    }

    /**
     * **A refusal names the file's own words.** Somebody reading a log has a
     * calendar of ten thousand lines in front of them, and "END:VEVENT" is
     * the only thing that tells them where to look.
     */
    public function testARefusalSaysWhatItSaw(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('VEVENT');

        $this->read("BEGIN:VCALENDAR\r\nEND:VEVENT\r\n");
    }

    /**
     * @param resource|string $source
     */
    private function one(mixed $source): Component
    {
        $objects = $this->read($source);

        $first = $objects[0] ?? null;

        self::assertCount(1, $objects);
        self::assertInstanceOf(Component::class, $first);

        return $first;
    }

    /**
     * @param resource|string $source
     *
     * @return list<Component>
     */
    private function read(mixed $source): array
    {
        return iterator_to_array((new Reader($source))->objects(), false);
    }

    /**
     * @param list<Property|Component> $children
     *
     * @return list<string>
     */
    private static function namesOf(array $children): array
    {
        return array_map(static fn (Property|Component $child): string => $child->name(), $children);
    }
}
