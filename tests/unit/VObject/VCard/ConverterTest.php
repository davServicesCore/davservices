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
use DavServices\VObject\Serializer;
use DavServices\VObject\VCard\Conversion;
use DavServices\VObject\VCard\Converter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for converting a vCard between 3.0 and 4.0. RFC 2426 and RFC 6350.
 * R-CARD-06, R-CARD-08.
 *
 * > R-CARD-06: Konvertierung zwischen vCard 3.0 und 4.0 MUSS verlustarm
 * > möglich sein.
 *
 * > R-CARD-08: Eingebettete Binärdaten MÜSSEN in beiden Kodierungen
 * > verarbeitet werden (Base64 bei 3.0, Data-URI bei 4.0).
 *
 * ## The table comes from the definitions, not from the summary
 *
 * RFC 6350's Appendix A lists what changed, and says of itself:
 *
 * > This appendix contains a high-level overview of the major changes that
 * > have been made in the vCard specification from RFCs 2425 and 2426. **It is
 * > incomplete**, as it only lists the most important changes.
 *
 * So the conversion is built from §6 of RFC 6350 against §3 of RFC 2426, and
 * the appendix is the check afterwards. **Reading it the other way round
 * misses things**, and these two are the proof: `SORT-STRING` is a property in
 * 3.0 (§3.6.5) and a **parameter** in 4.0 (`SORT-AS`, §5.9), and the appendix
 * mentions neither; `TYPE=INTERNET` on an `EMAIL` is ordinary in 3.0 (§3.3.2)
 * and not among 4.0's values for it (§6.4.2), which the appendix does not say
 * either — it names only the four `TYPE` values dropped from `ADR`.
 *
 * ## Losses are named, not quiet
 *
 * R-VOBJ-08 asks for "dokumentierte Verluste", so a conversion answers with
 * the card **and a list of what it could not carry across**, each naming the
 * property or parameter and the section that removed it. A conversion that
 * dropped a `LABEL` in silence would be worse than one that refused, because
 * nobody would find out.
 */
#[CoversClass(Converter::class)]
#[CoversClass(Conversion::class)]
final class ConverterTest extends TestCase
{
    /**
     * **The version is the one asked for**, and §6.7.9 makes it the one thing
     * a card cannot be without: "VERSION MUST come immediately after
     * BEGIN:VCARD."
     */
    public function testTheVersionIsTheOneAskedFor(): void
    {
        self::assertSame('4.0', Converter::toFourZero(self::card('3.0'))->card()->property('VERSION')?->value());
        self::assertSame('3.0', Converter::toThreeZero(self::card('4.0'))->card()->property('VERSION')?->value());
    }

    /**
     * **Base64 becomes a data URI**, which is R-CARD-08 in one direction.
     * RFC 2426 §3.1.4 writes a photo with `ENCODING=b` and the format in
     * `TYPE`; RFC 6350 §6.2.4 writes a URI, and Appendix A.3 says where the
     * format went: "The MEDIATYPE parameter has been added and replaces the
     * TYPE parameter when it was used for indicating the media type of the
     * property's content."
     *
     * @param non-empty-string $property
     */
    #[DataProvider('thePropertiesThatCarryBinaryData')]
    public function testBinaryDataBecomesADataUri(string $property, string $kind, string $type): void
    {
        $converted = Converter::toFourZero(self::card('3.0', [
            $property . ';ENCODING=b;TYPE=' . $kind . ':aGVsbG8=',
        ]));

        self::assertSame(
            'data:' . $type . ';base64,aGVsbG8=',
            $converted->card()->property($property)?->value(),
        );
        self::assertNull($converted->card()->property($property)->parameter('ENCODING'));
        self::assertSame([], $converted->losses());
    }

    /**
     * **And a data URI becomes base64 again**, which is the other direction of
     * the same requirement — the format back in `TYPE`, the encoding back in
     * `ENCODING`.
     *
     * @param non-empty-string $property
     */
    #[DataProvider('thePropertiesThatCarryBinaryData')]
    public function testADataUriBecomesBinaryData(string $property, string $kind, string $type): void
    {
        $converted = Converter::toThreeZero(self::card('4.0', [
            $property . ':data:' . $type . ';base64,aGVsbG8=',
        ]));
        $written = $converted->card()->property($property);

        self::assertSame('aGVsbG8=', $written?->value());
        self::assertSame('b', $written->parameter('ENCODING')?->value());
        self::assertSame($kind, $written->parameter('TYPE')?->value());
        self::assertSame([], $converted->losses());
    }

    /**
     * The four properties both versions let carry binary data: RFC 2426
     * §§3.1.4, 3.5.3, 3.6.6 and 3.7.2, RFC 6350 §§6.2.4, 6.6.3, 6.7.5 and
     * 6.8.1.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function thePropertiesThatCarryBinaryData(): iterable
    {
        yield 'PHOTO' => ['PHOTO', 'JPEG', 'image/jpeg'];

        yield 'LOGO' => ['LOGO', 'PNG', 'image/png'];

        yield 'SOUND' => ['SOUND', 'MP3', 'audio/mpeg'];

        yield 'KEY' => ['KEY', 'PGP', 'application/pgp-keys'];
    }

    /**
     * **A photo that is a URL stays a URL, and its format becomes a media
     * type.** This is the common case by far, and the one where folding the
     * format into the value would make nonsense of both: `data:image/jpeg;
     * base64,http://example.com/p.jpg` is neither a URL nor an image.
     *
     * Appendix A.3 says where the format goes instead: "The MEDIATYPE
     * parameter has been added and replaces the TYPE parameter when it was
     * used for indicating the media type of the property's content."
     */
    public function testAPhotoThatIsAUrlStaysAUrl(): void
    {
        $converted = Converter::toFourZero(self::card('3.0', [
            'PHOTO;VALUE=uri;TYPE=JPEG:http://example.com/p.jpg',
        ]));
        $written = $converted->card()->property('PHOTO');

        self::assertSame('http://example.com/p.jpg', $written?->value());
        self::assertSame('image/jpeg', $written->parameter('MEDIATYPE')?->value());
        self::assertSame([], $converted->losses());
    }

    /**
     * **And back the media type is a format name again**, 3.0 having no
     * `MEDIATYPE` parameter at all.
     */
    public function testAMediaTypeBecomesAFormatNameAgain(): void
    {
        $converted = Converter::toThreeZero(self::card('4.0', [
            'PHOTO;MEDIATYPE=image/jpeg:http://example.com/p.jpg',
        ]));
        $written = $converted->card()->property('PHOTO');

        self::assertSame('http://example.com/p.jpg', $written?->value());
        self::assertSame(['JPEG'], $written->parameter('TYPE')?->values());
        self::assertNull($written->parameter('MEDIATYPE'));
        self::assertSame([], $converted->losses());
    }

    /**
     * **A media type with no format name of its own is named as a loss.**
     * 3.0 writes a format, not a media type, and inventing a name for one the
     * table has not got would put a word into the card that no memo defines.
     */
    public function testAMediaTypeWithoutAFormatNameIsNamedAsALoss(): void
    {
        $converted = Converter::toThreeZero(self::card('4.0', [
            'PHOTO;MEDIATYPE=image/avif:http://example.com/p.avif',
        ]));
        $written = $converted->card()->property('PHOTO');

        self::assertSame('http://example.com/p.avif', $written?->value());
        self::assertNull($written->parameter('MEDIATYPE'));
        self::assertSame(['PHOTO'], self::whereOf($converted->losses()));
    }

    /**
     * **A parameter both versions know is carried across untouched**, which is
     * most of them: `LANGUAGE` is RFC 2426 §5.4 and RFC 6350 §5.1, and a
     * conversion has no business touching it.
     */
    public function testAParameterBothVersionsKnowIsCarriedAcross(): void
    {
        $toFour = Converter::toFourZero(self::card('3.0', ['NOTE;LANGUAGE=de:Zettel']));

        self::assertSame('de', $toFour->card()->property('NOTE')?->parameter('LANGUAGE')?->value());
        self::assertSame([], $toFour->losses());

        $toThree = Converter::toThreeZero(self::card('4.0', ['NOTE;LANGUAGE=de:Zettel']));

        self::assertSame('de', $toThree->card()->property('NOTE')?->parameter('LANGUAGE')?->value());
        self::assertSame([], $toThree->losses());
    }

    /**
     * **A `TYPE=pref` becomes the parameter of its own.** Appendix A.3: "The
     * 'pref' value of the TYPE parameter is now a parameter of its own, with a
     * positive integer value indicating the level of preference."
     */
    public function testAPreferredValueBecomesTheParameterOfItsOwn(): void
    {
        $converted = Converter::toFourZero(self::card('3.0', ['TEL;TYPE=VOICE,PREF:+1-555-0100']));
        $written = $converted->card()->property('TEL');

        self::assertSame('1', $written?->parameter('PREF')?->value());
        self::assertSame(['voice'], $written->parameter('TYPE')?->values());
        self::assertSame([], $converted->losses());
    }

    /**
     * **And back it is a `TYPE` value again.** §5.9's integer has no place in
     * 3.0, so a level beyond the first is carried as far as it can be — the
     * preference stays visible as `TYPE=pref` — and **the level itself is
     * named as a loss**: "verlustarm" means carrying what can be carried, not
     * dropping what cannot be carried whole.
     *
     * **And nothing else is added while doing it.** §6.4.1 says "The default
     * type is 'voice'", which is what a reader may assume and not what a
     * writer has to put down: a conversion that wrote `TYPE=voice` onto a
     * number whose author left it out would be inventing a line.
     */
    public function testAPreferenceKeepsItsPlaceAndLosesItsLevel(): void
    {
        $first = Converter::toThreeZero(self::card('4.0', ['TEL;PREF=1:+1-555-0100']));

        self::assertSame(['pref'], $first->card()->property('TEL')?->parameter('TYPE')?->values());
        self::assertSame([], $first->losses());

        $later = Converter::toThreeZero(self::card('4.0', ['TEL;PREF=37:+1-555-0100']));

        self::assertSame(['pref'], $later->card()->property('TEL')?->parameter('TYPE')?->values());
        self::assertSame(['TEL'], self::whereOf($later->losses()));

        // And where the author did write a type, it keeps its place in front.
        $beside = Converter::toThreeZero(self::card('4.0', ['TEL;TYPE=work;PREF=1:+1-555-0100']));

        self::assertSame(['work', 'pref'], $beside->card()->property('TEL')?->parameter('TYPE')?->values());
    }

    /**
     * **A `SORT-STRING` becomes a `SORT-AS` on the `N` it sorts.** It is a
     * property in 3.0 (§3.6.5: "specifies the family name or given name text
     * to be used for national-language-specific sorting") and a parameter in
     * 4.0 (§5.9), and **Appendix A says nothing about it** — which is why the
     * table was built from the definitions.
     */
    public function testASortStringBecomesTheSortAsParameter(): void
    {
        $converted = Converter::toFourZero(self::card('3.0', [
            'N:Harten;Rene;;Sir;R.D.O.N.',
            'SORT-STRING:Harten',
        ]));

        self::assertNull($converted->card()->property('SORT-STRING'));
        self::assertSame('Harten', $converted->card()->property('N')?->parameter('SORT-AS')?->value());
        self::assertSame([], $converted->losses());
    }

    /**
     * **And back it is a property again.**
     */
    public function testASortAsParameterBecomesASortString(): void
    {
        $converted = Converter::toThreeZero(self::card('4.0', [
            'N;SORT-AS=Harten:Harten;Rene;;Sir;R.D.O.N.',
        ]));

        self::assertSame('Harten', $converted->card()->property('SORT-STRING')?->value());
        self::assertNull($converted->card()->property('N')?->parameter('SORT-AS'));
        self::assertSame([], $converted->losses());
    }

    /**
     * **What 4.0 has not got is named, one finding per property.** Appendix
     * A.2: "The NAME, MAILER, LABEL, and CLASS properties are no more. … In-
     * line vCards (such as the value of the AGENT property) are no longer
     * supported."
     *
     * @param non-empty-string $written
     */
    #[DataProvider('thePropertiesFourZeroDroppped')]
    public function testWhatFourZeroHasNotGotIsNamedAsALoss(string $written, string $property): void
    {
        $converted = Converter::toFourZero(self::card('3.0', [$written]));

        self::assertNull($converted->card()->property($property));
        self::assertSame([$property], self::whereOf($converted->losses()));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function thePropertiesFourZeroDroppped(): iterable
    {
        yield 'LABEL' => ['LABEL:6544 Battleford Drive', 'LABEL'];

        yield 'MAILER' => ['MAILER:PigeonMail 2.1', 'MAILER'];

        yield 'NAME' => ['NAME:Frank Dawson', 'NAME'];

        yield 'CLASS' => ['CLASS:PUBLIC', 'CLASS'];

        yield 'AGENT' => ['AGENT:http://example.com/dir/agent.vcf', 'AGENT'];

        yield 'PROFILE' => ['PROFILE:VCARD', 'PROFILE'];
    }

    /**
     * **And what 3.0 has not got is named the same way.** Appendix A.3 lists
     * them as added: "The KIND, GENDER, LANG, ANNIVERSARY, XML, and
     * CLIENTPIDMAP properties have been added", and §§6.6.5 and 6.6.6 add
     * `MEMBER` and `RELATED`.
     *
     * @param non-empty-string $written
     */
    #[DataProvider('thePropertiesThreeZeroHasNotGot')]
    public function testWhatThreeZeroHasNotGotIsNamedAsALoss(string $written, string $property): void
    {
        $converted = Converter::toThreeZero(self::card('4.0', [$written]));

        self::assertNull($converted->card()->property($property));
        self::assertSame([$property], self::whereOf($converted->losses()));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function thePropertiesThreeZeroHasNotGot(): iterable
    {
        yield 'KIND' => ['KIND:individual', 'KIND'];

        yield 'GENDER' => ['GENDER:F', 'GENDER'];

        yield 'ANNIVERSARY' => ['ANNIVERSARY:19901021', 'ANNIVERSARY'];

        yield 'XML' => ['XML:<a xmlns="http://example.com">x</a>', 'XML'];

        yield 'MEMBER' => ['MEMBER:urn:uuid:03a0e51f-d1aa-4385-8a53-e29025acd8af', 'MEMBER'];

        yield 'RELATED' => ['RELATED;TYPE=friend:urn:uuid:03a0e51f-d1aa-4385-8a53-e29025acd8af', 'RELATED'];

        yield 'CLIENTPIDMAP' => ['CLIENTPIDMAP:1;urn:uuid:3df403f4-5924-4bb7-b077-3c711d9eb34b', 'CLIENTPIDMAP'];

        yield 'LANG' => ['LANG:de', 'LANG'];
    }

    /**
     * **A parameter 4.0 removed is named too.** Appendix A.2: "The CONTEXT and
     * CHARSET parameters are no more." The property itself survives — a
     * `CHARSET` says how to read a value, and in 4.0 the answer is always
     * UTF-8 ("UTF-8 is now the only possible character set", A.1).
     *
     * @param non-empty-string $written
     */
    #[DataProvider('theParametersFourZeroDropped')]
    public function testAParameterFourZeroRemovedIsNamedAsALoss(string $written, string $parameter): void
    {
        $converted = Converter::toFourZero(self::card('3.0', [$written]));

        self::assertSame('Frank Dawson', $converted->card()->property('FN')?->value());
        self::assertNull($converted->card()->property('FN')->parameter($parameter));
        self::assertSame(['FN'], self::whereOf($converted->losses()));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function theParametersFourZeroDropped(): iterable
    {
        yield 'CHARSET' => ['FN;CHARSET=UTF-8:Frank Dawson', 'CHARSET'];

        yield 'CONTEXT' => ['FN;CONTEXT=word:Frank Dawson', 'CONTEXT'];
    }

    /**
     * **The four `TYPE` values 4.0 removed from `ADR` are named**, and the
     * ones it still knows stay. Appendix A.2: "The 'intl', 'dom', 'postal',
     * and 'parcel' TYPE parameter values for the ADR property have been
     * removed."
     */
    public function testTheAddressTypesFourZeroRemovedAreNamedAsALoss(): void
    {
        $converted = Converter::toFourZero(self::card('3.0', [
            'ADR;TYPE=WORK,POSTAL,PARCEL,DOM,INTL:;;6544 Battleford Drive;Raleigh;NC;27613;U.S.A.',
        ]));

        self::assertSame(['work'], $converted->card()->property('ADR')?->parameter('TYPE')?->values());
        self::assertSame(['ADR'], self::whereOf($converted->losses()));
    }

    /**
     * **But a `TYPE` value 4.0 merely does not list is kept, and is no
     * loss.** §5.6's grammar is wider than its table:
     *
     *     type-value = "work" / "home" / type-param-tel
     *                / type-param-related / iana-token / x-name
     *
     * So `MSG` on a `TEL` and `INTERNET` on an `EMAIL` — both ordinary in 3.0
     * (§§3.3.1, 3.3.2) and in neither of 4.0's tables — are still values the
     * memo admits. **Dropping them would be a loss the memo does not ask
     * for**, and "verlustarm" is the requirement; a reader that does not know
     * them ignores them, which costs nothing.
     *
     * This is the one place where reading Appendix A instead of §5.6 would
     * have made the conversion worse rather than wrong.
     *
     * @param non-empty-string $written
     * @param list<string> $kept
     */
    #[DataProvider('theTypeValuesFourZeroDoesNotList')]
    public function testATypeValueFourZeroMerelyDoesNotListIsKept(
        string $written,
        string $property,
        array $kept,
    ): void {
        $converted = Converter::toFourZero(self::card('3.0', [$written]));

        self::assertSame($kept, $converted->card()->property($property)?->parameter('TYPE')?->values());
        self::assertSame([], $converted->losses());
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function theTypeValuesFourZeroDoesNotList(): iterable
    {
        yield "EMAIL's INTERNET" => [
            'EMAIL;TYPE=INTERNET,WORK:frank@example.com',
            'EMAIL',
            ['internet', 'work'],
        ];

        yield "TEL's MSG" => ['TEL;TYPE=VOICE,MSG,WORK:+1-919-676-9515', 'TEL', ['voice', 'msg', 'work']];
    }

    /**
     * **A name is a name however it is written.** RFC 6350 §3.3: "Property
     * names and parameter names are case-insensitive (e.g., the property name
     * 'fn' is the same as 'FN')" — and the memos write their own cards as
     * `BEGIN:vCard`, so a conversion that only understood capitals would fail
     * on the examples it is meant to carry.
     */
    public function testANameIsANameHoweverItIsWritten(): void
    {
        $converted = Converter::toFourZero(self::card('3.0', [
            'photo;encoding=b;type=jpeg:aGVsbG8=',
            'tel;type=voice,pref:+1-555-0100',
            'label:6544 Battleford Drive',
            'n:Dawson;Frank;;;',
            'sort-string:Dawson',
        ]));
        $card = $converted->card();

        self::assertSame('data:image/jpeg;base64,aGVsbG8=', $card->property('photo')?->value());
        self::assertSame('1', $card->property('tel')?->parameter('PREF')?->value());
        self::assertSame('Dawson', $card->property('n')?->parameter('SORT-AS')?->value());
        self::assertNull($card->property('label'));
        self::assertSame(['LABEL'], self::whereOf($converted->losses()));
    }

    /**
     * **A property that cannot come across does not take its neighbours with
     * it.** The one after it is converted as if nothing had happened, which is
     * what makes a loss a loss and not a truncation.
     */
    public function testAPropertyLeftBehindDoesNotTakeItsNeighboursWithIt(): void
    {
        $converted = Converter::toFourZero(self::card('3.0', [
            'LABEL:6544 Battleford Drive',
            'TEL;TYPE=VOICE:+1-919-676-9515',
            'MAILER:PigeonMail 2.1',
            'URL:http://example.com/frank',
        ]));
        $card = $converted->card();

        self::assertSame('+1-919-676-9515', $card->property('TEL')?->value());
        self::assertSame('http://example.com/frank', $card->property('URL')?->value());
        self::assertSame(['LABEL', 'MAILER'], self::whereOf($converted->losses()));
    }

    /**
     * **And a parameter that cannot come across does not take its neighbours
     * either.** The `CHARSET` goes, the `LANGUAGE` beside it stays.
     */
    public function testAParameterLeftBehindDoesNotTakeItsNeighboursWithIt(): void
    {
        $converted = Converter::toFourZero(self::card('3.0', [
            'NOTE;CHARSET=UTF-8;LANGUAGE=de;TYPE=WORK:Zettel',
        ]));
        $written = $converted->card()->property('NOTE');

        self::assertSame('Zettel', $written?->value());
        self::assertSame('de', $written->parameter('LANGUAGE')?->value());
        self::assertSame(['work'], $written->parameter('TYPE')?->values());
        self::assertNull($written->parameter('CHARSET'));
        self::assertSame(['NOTE'], self::whereOf($converted->losses()));
    }

    /**
     * **Nothing is preferred unless it says so.** A property without
     * `TYPE=pref` comes across without a `PREF`, and one without `PREF` comes
     * back without a `TYPE=pref`: 4.0's `PREF` means a level of preference,
     * and writing one onto a property that claimed none would invent a rank.
     */
    public function testNothingIsPreferredUnlessItSaysSo(): void
    {
        $toFour = Converter::toFourZero(self::card('3.0', ['TEL;TYPE=VOICE,WORK:+1-555-0100']));

        self::assertNull($toFour->card()->property('TEL')?->parameter('PREF'));
        self::assertSame(['voice', 'work'], $toFour->card()->property('TEL')?->parameter('TYPE')?->values());

        $toThree = Converter::toThreeZero(self::card('4.0', ['TEL;TYPE=voice,work:+1-555-0100']));

        self::assertSame(['voice', 'work'], $toThree->card()->property('TEL')?->parameter('TYPE')?->values());
    }

    /**
     * **Every `TYPE` value keeps its place beside a preference.** The ones the
     * author wrote come first, in their order, and `pref` is added to them
     * rather than put in their place.
     */
    public function testEveryTypeValueKeepsItsPlaceBesideAPreference(): void
    {
        $converted = Converter::toThreeZero(self::card('4.0', ['TEL;TYPE=work,cell;PREF=1:+1-555-0100']));

        self::assertSame(['work', 'cell', 'pref'], $converted->card()->property('TEL')?->parameter('TYPE')?->values());
    }

    /**
     * **A parameter beside binary data keeps its place too**, the encoding and
     * the format being added to what is there rather than replacing it.
     */
    public function testAParameterBesideBinaryDataKeepsItsPlace(): void
    {
        $converted = Converter::toThreeZero(self::card('4.0', [
            'PHOTO;LANGUAGE=de:data:image/jpeg;base64,aGVsbG8=',
        ]));
        $written = $converted->card()->property('PHOTO');

        self::assertSame('aGVsbG8=', $written?->value());
        self::assertSame('de', $written->parameter('LANGUAGE')?->value());
        self::assertSame('b', $written->parameter('ENCODING')?->value());
        self::assertSame(['JPEG'], $written->parameter('TYPE')?->values());
    }

    /**
     * **A sort string without an `N` is named as a loss.** §5.9 makes it a
     * parameter of a property, so there is nowhere to put it — and a card
     * whose author wrote a sorting that then vanished in silence would be the
     * worst of the three outcomes.
     */
    public function testASortStringWithoutANameIsNamedAsALoss(): void
    {
        $converted = Converter::toFourZero(self::card('3.0', ['SORT-STRING:Dawson']));

        self::assertNull($converted->card()->property('SORT-STRING'));
        self::assertSame(['SORT-STRING'], self::whereOf($converted->losses()));
    }

    /**
     * **The first `SORT-AS` of a card is the one that comes back.** §5.9
     * allows the parameter on `N` and on `ORG`, and 3.0 has one
     * `SORT-STRING` for the card — so the name's sorting is the one kept, and
     * it is kept because it comes first.
     */
    public function testTheFirstSortAsIsTheOneThatComesBack(): void
    {
        $converted = Converter::toThreeZero(self::card('4.0', [
            'N;SORT-AS=Harten:Harten;Rene;;Sir;',
            'ORG;SORT-AS=ABC:ABC Inc.',
        ]));

        self::assertSame('Harten', $converted->card()->property('SORT-STRING')?->value());
    }

    /**
     * **Binary data whose format has no media type here still becomes a
     * URI**, because §6.2.4 and its like take a URI value in 4.0: leaving the
     * base64 where it was would write a card no reader of 4.0 can take. The
     * value says "some bytes" (`application/octet-stream`), the format name
     * stays where §5.6's grammar admits it, and **the media type is named as a
     * loss** rather than guessed from a name nobody registered.
     */
    public function testBinaryDataOfAnUnknownFormatStillBecomesAUri(): void
    {
        $converted = Converter::toFourZero(self::card('3.0', ['PHOTO;ENCODING=b;TYPE=AVIF:aGVsbG8=']));
        $written = $converted->card()->property('PHOTO');

        self::assertSame('data:application/octet-stream;base64,aGVsbG8=', $written?->value());
        self::assertSame(['avif'], $written->parameter('TYPE')?->values());
        self::assertSame(['PHOTO'], self::whereOf($converted->losses()));
    }

    /**
     * **And a URL whose format has no media type keeps the name alone.**
     * There is no value to re-encode, so nothing is lost: the format name is a
     * `TYPE` value 4.0 admits (§5.6) and stays one.
     */
    public function testAUrlOfAnUnknownFormatKeepsTheNameAlone(): void
    {
        $converted = Converter::toFourZero(self::card('3.0', [
            'PHOTO;VALUE=uri;TYPE=AVIF:http://example.com/p.avif',
        ]));
        $written = $converted->card()->property('PHOTO');

        self::assertSame('http://example.com/p.avif', $written?->value());
        self::assertSame(['avif'], $written->parameter('TYPE')?->values());
        self::assertNull($written->parameter('MEDIATYPE'));
        self::assertSame([], $converted->losses());
    }

    /**
     * **An encoding is named however it is written.** Parameter values of the
     * defined kind are case-insensitive, and RFC 2426 §5.1 writes the encoding
     * as `b` while plenty of cards in the field write `B`.
     */
    public function testAnEncodingIsNamedHoweverItIsWritten(): void
    {
        $converted = Converter::toFourZero(self::card('3.0', ['PHOTO;ENCODING=B;TYPE=JPEG:aGVsbG8=']));

        self::assertSame(
            'data:image/jpeg;base64,aGVsbG8=',
            $converted->card()->property('PHOTO')?->value(),
        );
        self::assertSame([], $converted->losses());
    }

    /**
     * **A property that says nothing about preference gets no `PREF`.** Most
     * properties carry no `TYPE` at all, and 4.0's `PREF` is "a positive
     * integer value indicating the level of preference" (A.3) — writing one
     * onto an `FN` would claim a rank its author never gave.
     */
    public function testAPropertyThatSaysNothingAboutPreferenceGetsNone(): void
    {
        $converted = Converter::toFourZero(self::card('3.0', ['NOTE:Zettel']));

        self::assertNull($converted->card()->property('FN')?->parameter('PREF'));
        self::assertNull($converted->card()->property('NOTE')?->parameter('PREF'));
    }

    /**
     * **A sort string does not swallow what comes after it.** It is kept aside
     * until the `N` is in hand, and the properties written after it are
     * converted as if nothing had been kept aside at all.
     */
    public function testASortStringDoesNotSwallowWhatComesAfterIt(): void
    {
        $converted = Converter::toFourZero(self::card('3.0', [
            'SORT-STRING:Dawson',
            'N:Dawson;Frank;;;',
            'TEL;TYPE=VOICE:+1-919-676-9515',
            'URL:http://example.com/frank',
        ]));
        $card = $converted->card();

        self::assertSame('Dawson', $card->property('N')?->parameter('SORT-AS')?->value());
        self::assertSame('+1-919-676-9515', $card->property('TEL')?->value());
        self::assertSame('http://example.com/frank', $card->property('URL')?->value());
        self::assertSame([], $converted->losses());
    }

    /**
     * **The way back is held to the same promises**, which is the half a test
     * list forgets: names in either case, a property left behind that does not
     * take its neighbours with it, a parameter beside it that stays, and a
     * component that is no vCard refused.
     */
    public function testTheWayBackIsHeldToTheSamePromises(): void
    {
        $converted = Converter::toThreeZero(self::card('4.0', [
            'kind:individual',
            'tel;type=voice:+1-919-676-9515',
            'gender:F',
            'note;language=de;mediatype=text/plain:Zettel',
            'url:http://example.com/frank',
        ]));
        $card = $converted->card();

        self::assertSame('+1-919-676-9515', $card->property('tel')?->value());
        self::assertSame('http://example.com/frank', $card->property('url')?->value());
        self::assertSame('de', $card->property('note')?->parameter('LANGUAGE')?->value());
        self::assertNull($card->property('kind'));
        self::assertNull($card->property('gender'));
        self::assertSame(['KIND', 'GENDER', 'NOTE'], self::whereOf($converted->losses()));
    }

    /**
     * **And a component that is no vCard is refused either way round.**
     */
    public function testOnlyAVCardIsConvertedTheOtherWayToo(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches('/VCARD/');

        Converter::toThreeZero(new Component('VTODO'));
    }

    /**
     * **A property with many parameters loses only the one that has to go.**
     * Every parameter the conversion treats specially is in this one line, and
     * an ordinary one stands behind each of them: a conversion that stopped at
     * the first special case instead of stepping over it would leave the rest
     * of the line out, and a card is mostly parameters.
     */
    public function testAPropertyWithManyParametersLosesOnlyWhatHasToGo(): void
    {
        $converted = Converter::toFourZero(self::card('3.0', [
            'PHOTO;CHARSET=UTF-8;ENCODING=b;TYPE=JPEG,PREF;LANGUAGE=de:aGVsbG8=',
        ]));
        $written = $converted->card()->property('PHOTO');

        self::assertSame('data:image/jpeg;base64,aGVsbG8=', $written?->value());
        self::assertSame('1', $written->parameter('PREF')?->value());
        self::assertSame('de', $written->parameter('LANGUAGE')?->value());
        self::assertNull($written->parameter('CHARSET'));
        self::assertNull($written->parameter('ENCODING'));
        self::assertSame(['PHOTO'], self::whereOf($converted->losses()));
    }

    /**
     * **And the same the other way round**, where the parameters that need
     * work are different ones: a `MEDIATYPE` to turn back into a format name,
     * a `PREF` to put back among the types, a `SORT-AS` to lift out.
     *
     * **The type values come out in the order their sources stood in**, which
     * is the only order there is to follow: a `TYPE` is "a comma-separated
     * list" and its values carry no ranking, so the line decides rather than
     * the conversion.
     */
    public function testAPropertyWithManyParametersComesBackWhole(): void
    {
        $converted = Converter::toThreeZero(self::card('4.0', [
            'N;SORT-AS=Dawson:Dawson;Frank;;;',
            'PHOTO;MEDIATYPE=image/jpeg;PREF=2;TYPE=work;LANGUAGE=de:http://example.com/p.jpg',
        ]));
        $written = $converted->card()->property('PHOTO');

        self::assertSame('http://example.com/p.jpg', $written?->value());
        self::assertSame(['work', 'JPEG', 'pref'], $written->parameter('TYPE')?->values());
        self::assertSame('de', $written->parameter('LANGUAGE')?->value());
        self::assertNull($written->parameter('MEDIATYPE'));
        self::assertNull($written->parameter('PREF'));
        self::assertSame('Dawson', $converted->card()->property('SORT-STRING')?->value());
        self::assertSame(['PHOTO'], self::whereOf($converted->losses()));
    }

    /**
     * **One `TYPE` parameter, however many places the format came from.** A
     * `data:` URI carries a media type and a `MEDIATYPE` beside it names the
     * same thing; writing both out would put the same word in the card twice.
     */
    public function testTheFormatIsWrittenOnceHoweverOftenItWasGiven(): void
    {
        $converted = Converter::toThreeZero(self::card('4.0', [
            'PHOTO;MEDIATYPE=image/jpeg:data:image/jpeg;base64,aGVsbG8=',
        ]));
        $written = $converted->card()->property('PHOTO');

        self::assertSame('aGVsbG8=', $written?->value());
        self::assertSame(['JPEG'], $written->parameter('TYPE')?->values());
        self::assertSame('b', $written->parameter('ENCODING')?->value());
    }

    /**
     * **A value whose media type has no format name here keeps the value and
     * names the loss.** `application/octet-stream` is what the way out writes
     * for bytes it cannot name, and the way back cannot name it either — so
     * the data comes across and the format does not, said out loud.
     */
    public function testAMediaTypeWithNoFormatNameHereIsNamedOnTheWayBack(): void
    {
        $converted = Converter::toThreeZero(self::card('4.0', [
            'PHOTO:data:application/octet-stream;base64,aGVsbG8=',
        ]));
        $written = $converted->card()->property('PHOTO');

        self::assertSame('aGVsbG8=', $written?->value());
        self::assertSame('b', $written->parameter('ENCODING')?->value());
        self::assertNull($written->parameter('TYPE'));
        self::assertSame(['PHOTO'], self::whereOf($converted->losses()));
    }

    /**
     * **A parameter written without a value does not bring the conversion
     * down.** `MEDIATYPE` with no `=` is a broken line a reader meets in the
     * field, and the grammar of §5 has no room for it — so it is named as a
     * loss like any other media type that has no format name here, and the
     * card comes across.
     */
    public function testAParameterWrittenWithoutAValueIsNamedAsALoss(): void
    {
        $converted = Converter::toThreeZero(self::card('4.0', [
            'PHOTO;MEDIATYPE:http://example.com/p.jpg',
        ]));
        $written = $converted->card()->property('PHOTO');

        self::assertSame('http://example.com/p.jpg', $written?->value());
        self::assertNull($written->parameter('MEDIATYPE'));
        self::assertSame(['PHOTO'], self::whereOf($converted->losses()));
    }

    /**
     * **A type value that goes does not take the ones behind it.** The
     * preference and the four addresses 4.0 removed are all written **first**
     * here, with values that must survive behind them — because a conversion
     * that stopped at the first one it had to deal with would quietly shorten
     * the list, and a `TYPE` is a list.
     */
    public function testATypeValueThatGoesDoesNotTakeTheOnesBehindIt(): void
    {
        $preference = Converter::toFourZero(self::card('3.0', ['TEL;TYPE=PREF,WORK,CELL:+1-555-0100']));
        $written = $preference->card()->property('TEL');

        self::assertSame(['work', 'cell'], $written?->parameter('TYPE')?->values());
        self::assertSame('1', $written->parameter('PREF')?->value());

        $addresses = Converter::toFourZero(self::card('3.0', [
            'ADR;TYPE=POSTAL,PARCEL,WORK,HOME:;;a;b;c;d;e',
        ]));

        self::assertSame(
            ['work', 'home'],
            $addresses->card()->property('ADR')?->parameter('TYPE')?->values(),
        );
        self::assertSame(['ADR'], self::whereOf($addresses->losses()));
    }

    /**
     * **And on the way back a parameter that goes does not take the ones
     * behind it either** — the `SORT-AS` that is lifted out and the
     * `MEDIATYPE` that has no format name here are both written in front of a
     * parameter that has to survive them.
     */
    public function testAParameterThatGoesDoesNotTakeTheOnesBehindItEither(): void
    {
        $sorted = Converter::toThreeZero(self::card('4.0', [
            'N;SORT-AS=Dawson;LANGUAGE=de:Dawson;Frank;;;',
        ]));

        self::assertSame('de', $sorted->card()->property('N')?->parameter('LANGUAGE')?->value());
        self::assertSame('Dawson', $sorted->card()->property('SORT-STRING')?->value());

        $nameless = Converter::toThreeZero(self::card('4.0', [
            'PHOTO;MEDIATYPE=image/avif;LANGUAGE=de:http://example.com/p.avif',
        ]));
        $written = $nameless->card()->property('PHOTO');

        self::assertSame('de', $written?->parameter('LANGUAGE')?->value());
        self::assertNull($written->parameter('MEDIATYPE'));
        self::assertSame(['PHOTO'], self::whereOf($nameless->losses()));
    }

    /**
     * **What both versions know survives the round trip**, which is the whole
     * of "verlustarm" put to the test — and the card is the one RFC 2426
     * publishes of its own author (§7), written out as the memo has it.
     */
    public function testWhatBothVersionsKnowSurvivesTheRoundTrip(): void
    {
        $original = self::published();
        $back = Converter::toThreeZero(Converter::toFourZero($original)->card())->card();

        foreach (['FN', 'ORG', 'URL'] as $property) {
            self::assertSame(
                $original->property($property)?->value(),
                $back->property($property)?->value(),
                $property,
            );
        }

        // Two telephone numbers and two addresses, in the order they were
        // written: nothing is dropped and nothing changes places.
        self::assertSame(
            ['+1-919-676-9515', '+1-919-676-9564'],
            self::valuesOf($back, 'TEL'),
        );
        self::assertSame(
            ['Frank_Dawson@Lotus.com', 'fdawson@earthlink.net'],
            self::valuesOf($back, 'EMAIL'),
        );
    }

    /**
     * **And the losses of that card are exactly the one the memos explain**:
     * the address types 4.0 removed, and nothing else. Its `MSG` and
     * `INTERNET` type values are kept, §5.6's grammar admitting them, and its
     * `TYPE=PREF` becomes a `PREF` of its own.
     */
    public function testTheLossesOfThePublishedCardAreTheOnesTheMemosExplain(): void
    {
        $converted = Converter::toFourZero(self::published());

        self::assertSame(['ADR'], self::whereOf($converted->losses()));
    }

    /**
     * **The card handed in is not touched.** A conversion answers with a new
     * card, because a caller that passed its own object in and got it back
     * changed would have no way to write the original out again.
     */
    public function testTheCardHandedInIsNotTouched(): void
    {
        $original = self::published();
        $before = (new Serializer())->write($original);

        Converter::toFourZero($original);

        self::assertSame($before, (new Serializer())->write($original));
    }

    /**
     * **A property whose 3.0 definition lives in another memo is passed on.**
     * `IMPP` is RFC 4770 and `FBURL` is RFC 2739, both merged into 4.0
     * (Appendix A.3) and both defined for 3.0 in memos that are not in
     * `docs/referenzen/`. They are carried across rather than dropped: 3.0
     * takes extensions, and a reader that knows them loses nothing.
     */
    public function testAPropertyDefinedInAnotherMemoIsPassedOn(): void
    {
        $converted = Converter::toThreeZero(self::card('4.0', [
            'IMPP:xmpp:frank@example.com',
            'FBURL:http://example.com/busy/frank',
        ]));

        self::assertSame('xmpp:frank@example.com', $converted->card()->property('IMPP')?->value());
        self::assertSame('http://example.com/busy/frank', $converted->card()->property('FBURL')?->value());
        self::assertSame([], $converted->losses());
    }

    /**
     * **An extension is carried across untouched**, both versions allowing one
     * (RFC 2426 §3.8, RFC 6350 §6.10).
     */
    public function testAnExtensionIsCarriedAcross(): void
    {
        $converted = Converter::toFourZero(self::card('3.0', ['X-ABLabel:Work']));

        self::assertSame('Work', $converted->card()->property('X-ABLabel')?->value());
        self::assertSame([], $converted->losses());
    }

    /**
     * **Only a vCard is converted.** A component of another name is refused
     * rather than given a `VERSION` it has no business carrying.
     */
    public function testOnlyAVCardIsConverted(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches('/VCARD/');

        Converter::toFourZero(new Component('VEVENT'));
    }

    /**
     * A card of that version carrying those lines.
     *
     * @param list<string> $lines
     */
    private static function card(string $version, array $lines = []): Component
    {
        $text = "BEGIN:VCARD\r\nVERSION:" . $version . "\r\nFN:Frank Dawson\r\n"
            . implode("\r\n", $lines) . ($lines === [] ? '' : "\r\n")
            . "END:VCARD\r\n";

        return self::read($text);
    }

    /**
     * The first card RFC 2426 publishes in §7, as the memo writes it.
     */
    private static function published(): Component
    {
        return self::read(implode("\r\n", [
            'BEGIN:vCard',
            'VERSION:3.0',
            'FN:Frank Dawson',
            'ORG:Lotus Development Corporation',
            'ADR;TYPE=WORK,POSTAL,PARCEL:;;6544 Battleford Drive',
            ' ;Raleigh;NC;27613-3502;U.S.A.',
            'TEL;TYPE=VOICE,MSG,WORK:+1-919-676-9515',
            'TEL;TYPE=FAX,WORK:+1-919-676-9564',
            'EMAIL;TYPE=INTERNET,PREF:Frank_Dawson@Lotus.com',
            'EMAIL;TYPE=INTERNET:fdawson@earthlink.net',
            'URL:http://home.earthlink.net/~fdawson',
            'END:vCard',
        ]) . "\r\n");
    }

    private static function read(string $text): Component
    {
        $objects = iterator_to_array((new Reader($text))->objects(), false);
        $card = $objects[0] ?? null;

        self::assertInstanceOf(Component::class, $card);

        return $card;
    }

    /**
     * The values of every property of that name, in order.
     *
     * @return list<string>
     */
    private static function valuesOf(Component $card, string $name): array
    {
        $values = [];

        foreach ($card->properties($name) as $property) {
            $values[] = $property->value();
        }

        return $values;
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
