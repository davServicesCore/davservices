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

namespace DavServices\Tests\Unit\VObject\VCard;

use DavServices\VObject\Component;
use DavServices\VObject\ParseError;
use DavServices\VObject\Reader;
use DavServices\VObject\VCard\Conversion;
use DavServices\VObject\VCard\Converter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for vCard 2.1, the version with no RFC. R-VOBJ-08.
 *
 * ## Where the wording comes from
 *
 * **vCard 2.1 is a Versit industry specification, not an RFC**, and it is not
 * in `docs/referenzen/`. What is there is **RFC 2426 §5, "Differences From
 * vCard v2.1"**, and it is normative:
 *
 * > This specification has been reviewed by the IETF community. The review
 * > process introduced a number of differences from the [VCARD] version 2.1.
 * > … The differences include the following:
 *
 * Every rule below quotes that list. **What §5 does not name is not decidable
 * here**, so it is carried across untouched rather than invented — §5 lists
 * the differences, not the properties 2.1 has.
 *
 * ## The one that shows up in every card
 *
 * > The "TYPE=" prefix to parameter values is required. In [VCARD] this was
 * > optional.
 *
 * So `TEL;WORK;VOICE:+1-555-0100` is 2.1 and `TEL;TYPE=WORK,VOICE:
 * +1-555-0100` is 3.0, and a reader that did not know this would see two
 * nameless parameters instead of a telephone number for work.
 *
 * ## And the encodings, which are three cases and not one
 *
 * | written | becomes | because |
 * |---|---|---|
 * | `QUOTED-PRINTABLE` | decoded | §5: "The QUOTED-PRINTABLE inline encoding has been eliminated" |
 * | `BASE64` | `b` | §5: "Only the 'B' encoding of [RFC 2047] is an allowed value" — the same transformation under the name 3.0 allows |
 * | `8BIT` | nothing at all | RFC 2425 §5.5: "The default encoding is 8bit", so saying it says nothing |
 *
 * Anything else is carried across **and named**, because no memo in
 * `docs/referenzen/` defines it. RFC 2047 is not there either, which is why
 * `BASE64` is treated as a name for a transformation rather than looked up.
 */
#[CoversClass(Converter::class)]
#[CoversClass(Conversion::class)]
final class TwoOneTest extends TestCase
{
    /**
     * **A parameter value without its name becomes one with it.** §5: "The
     * 'TYPE=' prefix to parameter values is required. In [VCARD] this was
     * optional."
     */
    public function testAParameterValueWithoutItsNameGetsOne(): void
    {
        $converted = Converter::toThreeZero(self::card('2.1', ['TEL;WORK;VOICE:+1-555-0100']));
        $written = $converted->card()->property('TEL');

        self::assertSame('+1-555-0100', $written?->value());
        self::assertSame(['WORK', 'VOICE'], $written->parameter('TYPE')?->values());
        self::assertSame([], $converted->losses());
    }

    /**
     * **And on the way to 2.1 the prefix is dropped again**, which is what a
     * reader of 2.1 expects to find.
     */
    public function testTheTypePrefixIsDroppedOnTheWayBack(): void
    {
        $converted = Converter::toTwoOne(self::card('3.0', ['TEL;TYPE=WORK,VOICE:+1-555-0100']));
        $written = $converted->card()->property('TEL');

        self::assertSame('+1-555-0100', $written?->value());
        self::assertNull($written->parameter('TYPE'));

        // A nameless parameter is how 2.1 wrote one, and it reads back as a
        // name with no values at all.
        self::assertSame([], $written->parameter('WORK')?->values());
        self::assertSame([], $written->parameter('VOICE')?->values());
    }

    /**
     * **A `TYPE=` that is already there is left alone**, 2.1 having allowed it
     * as well — §5 calls the prefix "optional" there, not absent.
     */
    public function testATypePrefixAlreadyThereIsLeftAlone(): void
    {
        $converted = Converter::toThreeZero(self::card('2.1', ['TEL;TYPE=WORK:+1-555-0100']));

        self::assertSame(
            ['WORK'],
            $converted->card()->property('TEL')?->parameter('TYPE')?->values(),
        );
        self::assertSame([], $converted->losses());
    }

    /**
     * **Quoted-printable is decoded**, §5 naming it as gone: "The
     * QUOTED-PRINTABLE inline encoding has been eliminated."
     *
     * @param non-empty-string $written
     */
    #[DataProvider('theQuotedPrintableValues')]
    public function testQuotedPrintableIsDecoded(string $written, string $expected): void
    {
        $converted = Converter::toThreeZero(self::card('2.1', [$written]));
        $note = $converted->card()->property('NOTE');

        self::assertSame($expected, $note?->value());
        self::assertNull($note->parameter('ENCODING'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function theQuotedPrintableValues(): iterable
    {
        yield 'an umlaut' => ['NOTE;ENCODING=QUOTED-PRINTABLE:Gr=C3=BC=C3=9Fe', 'Grüße'];

        yield 'an equals sign' => ['NOTE;ENCODING=QUOTED-PRINTABLE:a=3Db', 'a=b'];

        // Several sequences in a row, which is what an umlaut-heavy note
        // looked like in 2.1. A soft line break is not a case here: the lexer
        // unfolds the line before a conversion ever sees it.
        yield 'three umlauts' => ['NOTE;ENCODING=QUOTED-PRINTABLE:=C3=A4=C3=B6=C3=BC', 'äöü'];
    }

    /**
     * **A line break inside a quoted-printable value becomes 3.0's own
     * escape.** §5: "The CRLF character sequence in a text type value is
     * specified with the backslash character sequence '\n' or '\N'."
     */
    public function testALineBreakBecomesTheEscapeThreeZeroUses(): void
    {
        $converted = Converter::toThreeZero(self::card('2.1', [
            'NOTE;ENCODING=QUOTED-PRINTABLE:eins=0D=0Azwei',
        ]));

        self::assertSame('eins\\nzwei', $converted->card()->property('NOTE')?->value());
    }

    /**
     * **`BASE64` becomes `b`**, which is the same transformation under the
     * name 3.0 allows: §5 says "Only the 'B' encoding of [RFC 2047] is an
     * allowed value for the ENCODING parameter".
     */
    public function testBase64BecomesB(): void
    {
        $converted = Converter::toThreeZero(self::card('2.1', [
            'PHOTO;ENCODING=BASE64;TYPE=JPEG:aGVsbG8=',
        ]));
        $written = $converted->card()->property('PHOTO');

        self::assertSame('aGVsbG8=', $written?->value());
        self::assertSame('b', $written->parameter('ENCODING')?->value());
        self::assertSame([], $converted->losses());
    }

    /**
     * **`8BIT` says nothing and is written as nothing**, RFC 2425 §5.5: "The
     * default encoding is 8bit." A parameter that repeats the default is not a
     * loss when it goes.
     */
    public function testTheDefaultEncodingIsWrittenAsNothing(): void
    {
        $converted = Converter::toThreeZero(self::card('2.1', ['NOTE;ENCODING=8BIT:Zettel']));
        $note = $converted->card()->property('NOTE');

        self::assertSame('Zettel', $note?->value());
        self::assertNull($note->parameter('ENCODING'));
        self::assertSame([], $converted->losses());
    }

    /**
     * **An encoding no memo here defines is carried across and named.** §5
     * allows only "B" in 3.0, and `docs/referenzen/` has nothing that says
     * what another name means — so the value is left exactly as it stands and
     * the reader is told, rather than being handed a guess.
     */
    public function testAnEncodingNoMemoDefinesIsCarriedAcrossAndNamed(): void
    {
        $converted = Converter::toThreeZero(self::card('2.1', ['NOTE;ENCODING=X-WEIRD:Zettel']));
        $note = $converted->card()->property('NOTE');

        self::assertSame('Zettel', $note?->value());
        self::assertSame('X-WEIRD', $note->parameter('ENCODING')?->value());
        self::assertSame(['NOTE'], self::whereOf($converted->losses()));
    }

    /**
     * **A `CHARSET` goes, and that is a loss worth naming.** §5: "The [VCARD]
     * CHARSET type parameter has been eliminated. Character set can only be
     * specified on the CHARSET parameter on the Content-Type MIME header
     * field."
     */
    public function testACharsetGoesAndIsNamed(): void
    {
        $converted = Converter::toThreeZero(self::card('2.1', ['NOTE;CHARSET=ISO-8859-1:Zettel']));

        self::assertNull($converted->card()->property('NOTE')?->parameter('CHARSET'));
        self::assertSame(['NOTE'], self::whereOf($converted->losses()));
    }

    /**
     * **A comma or a semicolon in a text value is escaped.** §5: "Any COMMA or
     * SEMICOLON in a text type value must be backslash escaped." A 2.1 card
     * wrote them plain, and carrying them across unescaped would split one
     * note into two values.
     */
    public function testACommaOrSemicolonIsEscaped(): void
    {
        $converted = Converter::toThreeZero(self::card('2.1', ['NOTE:eins,zwei;drei']));

        self::assertSame('eins\\,zwei\\;drei', $converted->card()->property('NOTE')?->value());
    }

    /**
     * **And unescaped again on the way to 2.1**, where the backslash has no
     * such meaning.
     */
    public function testACommaOrSemicolonIsUnescapedOnTheWayBack(): void
    {
        $converted = Converter::toTwoOne(self::card('3.0', ['NOTE:eins\\,zwei\\;drei']));

        self::assertSame('eins,zwei;drei', $converted->card()->property('NOTE')?->value());
    }

    /**
     * **The five properties 3.0 added are losses on the way to 2.1.** §5: "The
     * CATEGORIES, CLASS, NICKNAME, PRODID and SORT-STRING types have been
     * added."
     *
     * @param non-empty-string $written
     */
    #[DataProvider('thePropertiesThreeZeroAdded')]
    public function testAPropertyThreeZeroAddedIsALossOnTheWayBack(string $written, string $property): void
    {
        $converted = Converter::toTwoOne(self::card('3.0', [$written]));

        self::assertNull($converted->card()->property($property));
        self::assertSame([$property], self::whereOf($converted->losses()));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function thePropertiesThreeZeroAdded(): iterable
    {
        yield 'CATEGORIES' => ['CATEGORIES:TRAVEL,BUSINESS', 'CATEGORIES'];

        yield 'CLASS' => ['CLASS:PUBLIC', 'CLASS'];

        yield 'NICKNAME' => ['NICKNAME:Frank', 'NICKNAME'];

        yield 'PRODID' => ['PRODID:-//davServices//EN', 'PRODID'];

        yield 'SORT-STRING' => ['SORT-STRING:Dawson', 'SORT-STRING'];
    }

    /**
     * **The version written is the one asked for.** §5: "VERSION value
     * corresponding to this specification MUST be '3.0'."
     */
    public function testTheVersionWrittenIsTheOneAskedFor(): void
    {
        self::assertSame('3.0', Converter::toThreeZero(self::card('2.1'))->card()->property('VERSION')?->value());
        self::assertSame('2.1', Converter::toTwoOne(self::card('3.0'))->card()->property('VERSION')?->value());
    }

    /**
     * **A 2.1 card without `N` or `FN` is named as incomplete, not refused.**
     * §5: "The VERSION, N and FN types MUST be specified in a vCard" — which
     * 2.1 did not require, so such a card exists and a reader meets it. It
     * comes across, and what it lacks is said out loud: refusing would throw
     * away a card whose data is there.
     */
    public function testACardWithoutTheNamesThreeZeroRequiresIsNamedNotRefused(): void
    {
        $text = "BEGIN:VCARD\r\nVERSION:2.1\r\nTEL;WORK:+1-555-0100\r\nEND:VCARD\r\n";
        $converted = Converter::toThreeZero(self::read($text));

        self::assertSame('+1-555-0100', $converted->card()->property('TEL')?->value());
        // Both are missing, and both are named: §5 requires N and FN.
        self::assertSame(['N', 'FN'], self::whereOf($converted->losses()));
    }

    /**
     * **2.1 to 4.0 goes through 3.0, and the losses of both steps are in the
     * list.** Two steps rather than a third table: each one names what it
     * could not carry, so a caller reading the list can tell where a value
     * was lost instead of being told only that it is gone.
     */
    public function testTwoOneToFourZeroGoesThroughThreeZero(): void
    {
        $converted = Converter::toFourZero(self::card('2.1', [
            'TEL;WORK;VOICE:+1-555-0100',
            'LABEL:6544 Battleford Drive',
            'NOTE;CHARSET=ISO-8859-1:Zettel',
        ]));
        $card = $converted->card();

        self::assertSame('4.0', $card->property('VERSION')?->value());
        self::assertSame(['work', 'voice'], $card->property('TEL')?->parameter('TYPE')?->values());
        self::assertNull($card->property('LABEL'));

        // The CHARSET goes in the first step, the LABEL in the second.
        self::assertSame(['NOTE', 'LABEL'], self::whereOf($converted->losses()));
    }

    /**
     * **And 4.0 to 2.1 the same way round.**
     */
    public function testFourZeroToTwoOneGoesThroughThreeZero(): void
    {
        $converted = Converter::toTwoOne(self::card('4.0', [
            'TEL;TYPE=work;PREF=1:+1-555-0100',
            'KIND:individual',
            'NICKNAME:Frank',
        ]));
        $card = $converted->card();

        self::assertSame('2.1', $card->property('VERSION')?->value());
        self::assertSame([], $card->property('TEL')?->parameter('WORK')?->values());
        self::assertSame([], $card->property('TEL')->parameter('PREF')?->values());
        self::assertSame(['KIND', 'NICKNAME'], self::whereOf($converted->losses()));
    }

    /**
     * **A card already in the version asked for is left as it is**, save its
     * `VERSION`: there is nothing to convert, and converting anyway would
     * escape what is already escaped.
     */
    public function testACardAlreadyInThatVersionIsLeftAsItIs(): void
    {
        $converted = Converter::toThreeZero(self::card('3.0', ['NOTE:eins\\,zwei']));

        self::assertSame('eins\\,zwei', $converted->card()->property('NOTE')?->value());
        self::assertSame([], $converted->losses());
    }

    /**
     * **A card whose version cannot be read is refused.** Both memos make the
     * property mandatory — RFC 2426 §3.6.9 and RFC 6350 §6.7.9, the latter
     * adding "VERSION MUST come immediately after BEGIN:VCARD" — and a
     * conversion that guessed a version would convert by rules the card was
     * never written to.
     *
     * @param non-empty-string $text
     */
    #[DataProvider('theCardsWithNoVersionToRead')]
    public function testACardWhoseVersionCannotBeReadIsRefused(string $text): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches('/2\.1, 3\.0 and 4\.0/');

        Converter::toThreeZero(self::read($text));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function theCardsWithNoVersionToRead(): iterable
    {
        yield 'none at all' => ["BEGIN:VCARD
FN:Frank Dawson
END:VCARD
"];

        yield 'one nobody has written' => ["BEGIN:VCARD
VERSION:5.0
FN:Frank Dawson
END:VCARD
"];
    }

    /**
     * **A parameter neither version treats specially is carried across.**
     * `LANGUAGE` is RFC 2426 §5.4, both versions have it, and a conversion
     * has no business touching it — in either direction, and beside a
     * parameter that is being worked on.
     */
    public function testAParameterNeitherVersionTreatsSpeciallyIsCarriedAcross(): void
    {
        $up = Converter::toThreeZero(self::card('2.1', [
            'NOTE;LANGUAGE=de;ENCODING=QUOTED-PRINTABLE:Gr=C3=BC=C3=9Fe',
        ]));
        $note = $up->card()->property('NOTE');

        self::assertSame('Grüße', $note?->value());
        self::assertSame('de', $note->parameter('LANGUAGE')?->value());

        $down = Converter::toTwoOne(self::card('3.0', ['NOTE;LANGUAGE=de;TYPE=WORK:Zettel']));
        $back = $down->card()->property('NOTE');

        self::assertSame('Zettel', $back?->value());
        self::assertSame('de', $back->parameter('LANGUAGE')?->value());
        self::assertSame([], $back->parameter('WORK')?->values());
    }

    /**
     * A card of that version carrying those lines.
     *
     * @param list<string> $lines
     */
    private static function card(string $version, array $lines = []): Component
    {
        return self::read("BEGIN:VCARD\r\nVERSION:" . $version . "\r\nFN:Frank Dawson\r\nN:Dawson;Frank;;;\r\n"
            . ($lines === [] ? '' : implode("\r\n", $lines) . "\r\n")
            . "END:VCARD\r\n");
    }

    private static function read(string $text): Component
    {
        $objects = iterator_to_array((new Reader($text))->objects(), false);
        $card = $objects[0] ?? null;

        self::assertInstanceOf(Component::class, $card);

        return $card;
    }

    /**
     * What the findings are about, in order, each named once.
     *
     * @param list<\DavServices\VObject\Finding> $losses
     *
     * @return list<string>
     */
    private static function whereOf(array $losses): array
    {
        $where = [];

        foreach ($losses as $loss) {
            $where[$loss->where()] = $loss->where();
        }

        return array_values($where);
    }
}
