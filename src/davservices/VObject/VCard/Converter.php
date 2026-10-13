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

namespace DavServices\VObject\VCard;

use DavServices\VObject\Component;
use DavServices\VObject\Finding;
use DavServices\VObject\Parameter;
use DavServices\VObject\ParseError;
use DavServices\VObject\Property;
use DavServices\VObject\Severity;

/**
 * Converts a vCard between 3.0 (RFC 2426) and 4.0 (RFC 6350).
 *
 *     $converted = Converter::toFourZero($card);   // Conversion
 *     $card = $converted->card();
 *     $lost = $converted->losses();
 *
 * R-CARD-06 asks for a conversion that is "verlustarm", R-CARD-08 for embedded
 * binary data "in beiden Kodierungen", and R-VOBJ-08 for "dokumentierte
 * Verluste" — so what cannot come across is {@see Conversion::losses()} rather
 * than nothing at all.
 *
 * ## The table comes from the definitions, not from the summary
 *
 * RFC 6350's Appendix A says of itself:
 *
 * > This appendix contains a high-level overview of the major changes that
 * > have been made in the vCard specification from RFCs 2425 and 2426. **It is
 * > incomplete**, as it only lists the most important changes.
 *
 * So what follows was built from §6 of RFC 6350 against §3 of RFC 2426, with
 * the appendix held over it afterwards as a check. **Two things fall out of
 * that order and would have been wrong the other way round:**
 *
 * - `SORT-STRING` is a property in 3.0 (§3.6.5) and a **parameter** in 4.0
 *   (`SORT-AS`, §5.9). The appendix mentions neither name.
 * - A `TYPE` value 4.0 does not list is **still a value it admits**, §5.6's
 *   grammar reading `type-value = "work" / "home" / type-param-tel /
 *   type-param-related / iana-token / x-name`. So `TYPE=MSG` on a telephone
 *   number and `TYPE=INTERNET` on an address survive, while the four the
 *   appendix names as removed from `ADR` do not. Dropping the others would be
 *   a loss the memo never asked for.
 *
 * ## What is carried in another shape
 *
 * | 3.0 | 4.0 | where |
 * |---|---|---|
 * | `ENCODING=b` with `TYPE=JPEG` | a `data:` URI | §3.1.4, §6.2.4, A.3 |
 * | `TYPE=pref` | `PREF=1` | A.3 |
 * | `SORT-STRING` | `SORT-AS` on `N` | §3.6.5, §5.9 |
 *
 * **A preference keeps its place and loses its level.** 4.0's `PREF` is "a
 * positive integer value indicating the level of preference" (A.3) and 3.0 has
 * only the flag, so `PREF=37` comes back as `TYPE=pref` and the level is
 * named as a loss: carrying what can be carried is what "verlustarm" means.
 */
final class Converter
{
    /**
     * The properties 4.0 has no room for, with the section that says so.
     *
     * Appendix A.2: "The NAME, MAILER, LABEL, and CLASS properties are no
     * more. … In-line vCards (such as the value of the AGENT property) are no
     * longer supported." `PROFILE` goes with the merge of RFC 2425 into the
     * format itself (A.1).
     *
     * @var array<string, string>
     */
    private const GONE_IN_FOUR = [
        'LABEL' => 'RFC 6350 A.2',
        'MAILER' => 'RFC 6350 A.2',
        'NAME' => 'RFC 6350 A.2',
        'CLASS' => 'RFC 6350 A.2',
        'AGENT' => 'RFC 6350 A.2',
        'PROFILE' => 'RFC 6350 A.1',
    ];

    /**
     * The properties 3.0 has no room for, each with the section that defines
     * it in 4.0.
     *
     * @var array<string, string>
     */
    private const GONE_IN_THREE = [
        'KIND' => 'RFC 6350 §6.1.4',
        'XML' => 'RFC 6350 §6.1.5',
        'ANNIVERSARY' => 'RFC 6350 §6.2.6',
        'GENDER' => 'RFC 6350 §6.2.7',
        'LANG' => 'RFC 6350 §6.4.4',
        'MEMBER' => 'RFC 6350 §6.6.5',
        'RELATED' => 'RFC 6350 §6.6.6',
        'CLIENTPIDMAP' => 'RFC 6350 §6.7.7',
    ];

    /**
     * The parameters 4.0 has no room for.
     *
     * Appendix A.2: "The CONTEXT and CHARSET parameters are no more." A
     * `CHARSET` said how to read a value, and in 4.0 the answer is always the
     * same: "UTF-8 is now the only possible character set" (A.1).
     *
     * @var list<string>
     */
    private const PARAMETERS_GONE_IN_FOUR = ['CHARSET', 'CONTEXT'];

    /**
     * The `TYPE` values Appendix A.2 names as removed from `ADR`, lower-cased
     * for comparison: "The 'intl', 'dom', 'postal', and 'parcel' TYPE
     * parameter values for the ADR property have been removed."
     *
     * @var list<string>
     */
    private const ADDRESS_TYPES_GONE_IN_FOUR = ['intl', 'dom', 'postal', 'parcel'];

    /**
     * The media type each of 3.0's format names stands for.
     *
     * 3.0 names a format in `TYPE` (RFC 2426 §3.1.4: "TYPE=JPEG") where 4.0
     * carries a media type, and this is the table between them. **It is data
     * rather than arithmetic**, like the zone catalogue of P4-11a: the names
     * come from RFC 2426's own examples and registered IANA types, and a name
     * that is not here is carried across as it stands rather than guessed at.
     *
     * @var array<string, string>
     */
    private const MEDIA_TYPES = [
        'JPEG' => 'image/jpeg',
        'JPG' => 'image/jpeg',
        'GIF' => 'image/gif',
        'PNG' => 'image/png',
        'TIFF' => 'image/tiff',
        'BMP' => 'image/bmp',
        'MP3' => 'audio/mpeg',
        'MPEG' => 'audio/mpeg',
        'WAVE' => 'audio/wav',
        'WAV' => 'audio/wav',
        'AIFF' => 'audio/aiff',
        'BASIC' => 'audio/basic',
        'PGP' => 'application/pgp-keys',
        'X509' => 'application/x-x509-ca-cert',
    ];

    /**
     * The properties that can carry binary data in either version: RFC 2426
     * §§3.1.4, 3.5.3, 3.6.6 and 3.7.2 against RFC 6350 §§6.2.4, 6.6.3, 6.7.5
     * and 6.8.1.
     *
     * @var list<string>
     */
    private const BINARY = ['PHOTO', 'LOGO', 'SOUND', 'KEY'];

    /**
     * The media type for bytes whose format has no name here (RFC 2046 §4.5.1:
     * "The 'octet-stream' subtype is used to indicate that a body contains
     * arbitrary binary data").
     */
    private const SOME_BYTES = 'application/octet-stream';

    /** The versions this converts between, oldest first. */
    private const V21 = '2.1';

    private const V30 = '3.0';

    private const V40 = '4.0';

    /** @var list<string> */
    private const VERSIONS = [self::V21, self::V30, self::V40];

    /**
     * The properties RFC 2426 defines as "Type value: A single text value",
     * read out of its §3.
     *
     * **This is the list §5's escaping rule is about** — "Any COMMA or
     * SEMICOLON in a text type value must be backslash escaped" — and the
     * reason it is a list rather than every property: `N`, `ADR` and `ORG`
     * are "structured text" whose semicolons separate their components, and
     * escaping those would make one field out of five. `URL` is a URI, where
     * a semicolon belongs to the URL.
     *
     * @var list<string>
     */
    private const SINGLE_TEXT = [
        'FN',
        'LABEL',
        'EMAIL',
        'MAILER',
        'TITLE',
        'ROLE',
        'NOTE',
        'PRODID',
        'SORT-STRING',
        'UID',
        'CLASS',
    ];

    /**
     * The properties RFC 2426 §5 names as added in 3.0: "The CATEGORIES,
     * CLASS, NICKNAME, PRODID and SORT-STRING types have been added."
     *
     * @var list<string>
     */
    private const ADDED_IN_THREE = ['CATEGORIES', 'CLASS', 'NICKNAME', 'PRODID', 'SORT-STRING'];

    /**
     * The parameters 3.0 writes differently from 4.0, which is what
     * {@see self::typesFor()} is for. Everything else is carried across as it
     * stands.
     *
     * @var list<string>
     */
    private const WORKED_ON_IN_THREE = ['SORT-AS', 'PREF', 'TYPE', 'MEDIATYPE'];

    /**
     * This card as vCard 2.1.
     *
     * @throws ParseError If the component is not a vCard, or carries a
     *                    version this cannot read
     */
    public static function toTwoOne(Component $card): Conversion
    {
        return self::convert($card, self::V21);
    }

    /**
     * This card as vCard 3.0.
     *
     * @throws ParseError If the component is not a vCard, or carries a
     *                    version this cannot read
     */
    public static function toThreeZero(Component $card): Conversion
    {
        return self::convert($card, self::V30);
    }

    /**
     * This card as vCard 4.0.
     *
     * @throws ParseError If the component is not a vCard, or carries a
     *                    version this cannot read
     */
    public static function toFourZero(Component $card): Conversion
    {
        return self::convert($card, self::V40);
    }

    /**
     * The card in the version asked for, one neighbouring step at a time.
     *
     * **2.1 reaches 4.0 through 3.0**, and that is a decision rather than a
     * shortcut: each step names what it could not carry, so a caller reading
     * {@see Conversion::losses()} can tell **where** a value was lost instead
     * of only that it is gone. A third table for the long way round would
     * also be a third place to keep in step with two memos.
     *
     * @throws ParseError If the component is not a vCard, or carries a
     *                    version this cannot read
     */
    private static function convert(Component $card, string $to): Conversion
    {
        self::refuseAnythingElse($card);

        $at = self::versionOf($card);
        $losses = [];

        while ($at !== $to) {
            $next = self::nextTowards($at, $to);
            [$card, $found] = self::oneStep($card, $at, $next);
            $losses = [...$losses, ...$found];
            $at = $next;
        }

        return new Conversion(self::withVersion($card, $to), $losses);
    }

    /**
     * The version a card says it is.
     *
     * @throws ParseError If it says none, or one this cannot read
     */
    private static function versionOf(Component $card): string
    {
        $version = $card->property('VERSION')?->value();

        if ($version !== null && in_array($version, self::VERSIONS, true)) {
            return $version;
        }

        throw new ParseError(sprintf(
            'A vCard says which version it is written to, and this one says "%s" — 2.1, 3.0 and 4.0 are the ones here.',
            $version ?? '',
        ));
    }

    /**
     * The next version on the way from one to another.
     */
    private static function nextTowards(string $at, string $to): string
    {
        // There are three versions and 3.0 is in the middle, so from either
        // end there is only one way to go — and from the middle the way is
        // the one that was asked for. Said as the three cases it is rather
        // than as arithmetic on an index.
        if ($at === self::V21 || $at === self::V40) {
            return self::V30;
        }

        return $to === self::V21 ? self::V21 : self::V40;
    }

    /**
     * One step between neighbouring versions.
     *
     * @return array{Component, list<Finding>}
     */
    private static function oneStep(Component $card, string $from, string $to): array
    {
        if ($from === self::V21) {
            return self::upFromTwoOne($card);
        }

        if ($to === self::V21) {
            return self::downToTwoOne($card);
        }

        if ($to === self::V40) {
            return self::upToFourZero($card);
        }

        return self::downToThreeZero($card);
    }

    /**
     * The card with that version in front of it, and nothing else changed.
     *
     * Built rather than altered: the card handed to a conversion is never
     * touched, so a caller can still write out what it passed in.
     */
    private static function withVersion(Component $card, string $version): Component
    {
        $written = new Component('VCARD');
        $written->add(new Property('VERSION', $version));

        foreach ($card->properties() as $property) {
            if (strtoupper($property->name()) === 'VERSION') {
                continue;
            }

            $written->add(new Property($property->name(), $property->value(), $property->parameters()));
        }

        return $written;
    }

    /**
     * 3.0 to 4.0, which is P4-13a's table.
     *
     * @return array{Component, list<Finding>}
     */
    private static function upToFourZero(Component $card): array
    {
        $written = new Component('VCARD');
        $losses = [];
        $sortAs = null;

        foreach ($card->properties() as $property) {
            $name = strtoupper($property->name());

            if ($name === 'VERSION') {
                continue;
            }

            if (isset(self::GONE_IN_FOUR[$name])) {
                $losses[] = self::loss($name, sprintf(
                    'vCard 4.0 has no %s property (%s), so it was left behind.',
                    $name,
                    self::GONE_IN_FOUR[$name],
                ));

                continue;
            }

            // §3.6.5's property is §5.9's parameter, and it belongs on the
            // name it sorts. Kept aside until the N is in hand.
            if ($name === 'SORT-STRING') {
                $sortAs = $property->value();

                continue;
            }

            $written->add(self::inFourZero($property, $name, $losses));
        }

        self::putTheSortAsOnTheName($written, $sortAs, $losses);

        return [$written, $losses];
    }

    /**
     * 4.0 back to 3.0.
     *
     * @return array{Component, list<Finding>}
     */
    private static function downToThreeZero(Component $card): array
    {
        $written = new Component('VCARD');
        $losses = [];
        $sortString = null;

        foreach ($card->properties() as $property) {
            $name = strtoupper($property->name());

            if ($name === 'VERSION') {
                continue;
            }

            if (isset(self::GONE_IN_THREE[$name])) {
                $losses[] = self::loss($name, sprintf(
                    'vCard 3.0 has no %s property (%s defines it), so it was left behind.',
                    $name,
                    self::GONE_IN_THREE[$name],
                ));

                continue;
            }

            $sortString ??= $property->parameter('SORT-AS')?->value();
            $written->add(self::inThreeZero($property, $name, $losses));
        }

        self::putTheSortStringIn($written, $sortString);

        return [$written, $losses];
    }

    /**
     * 2.1 up to 3.0.
     *
     * Every rule here is one of RFC 2426 §5's, and §5 is the only normative
     * text about 2.1 in `docs/referenzen/` — vCard 2.1 itself is a Versit
     * industry specification and no RFC. **What §5 does not name is carried
     * across untouched**, because §5 lists the differences and not the
     * properties 2.1 has.
     *
     * @return array{Component, list<Finding>}
     */
    private static function upFromTwoOne(Component $card): array
    {
        $written = new Component('VCARD');
        $losses = [];

        foreach ($card->properties() as $property) {
            $name = strtoupper($property->name());

            if ($name === 'VERSION') {
                continue;
            }

            $written->add(self::inThreeZeroFromTwoOne($property, $name, $losses));
        }

        // §5: "The VERSION, N and FN types MUST be specified in a vCard."
        // 2.1 did not require them, so such a card exists and a reader meets
        // it: it comes across, and what it lacks is said rather than refused.
        foreach (['N', 'FN'] as $required) {
            if ($written->property($required) === null) {
                $losses[] = self::loss($required, sprintf(
                    'vCard 3.0 requires %s (RFC 2426 §5) and this card has none.',
                    $required,
                ));
            }
        }

        return [$written, $losses];
    }

    /**
     * One property as 3.0 writes it, coming from 2.1.
     *
     * @param list<Finding> $losses
     *
     * @param-out list<Finding> $losses
     */
    private static function inThreeZeroFromTwoOne(Property $property, string $name, array &$losses): Property
    {
        $value = $property->value();
        $parameters = [];
        $types = [];
        $encoding = null;

        foreach ($property->parameters() as $parameter) {
            $of = strtoupper($parameter->name());

            // §5: "The 'TYPE=' prefix to parameter values is required. In
            // [VCARD] this was optional." A parameter written without one is
            // a type value whose name was left out.
            if ($parameter->values() === []) {
                $types[] = $of;

                continue;
            }

            if ($of === 'CHARSET') {
                $losses[] = self::loss($name, sprintf(
                    'vCard 3.0 has no CHARSET parameter (RFC 2426 §5), so %s lost "%s".',
                    $name,
                    (string) $parameter->value(),
                ));

                continue;
            }

            if ($of === 'ENCODING') {
                $encoding = strtoupper((string) $parameter->value());
                $kept = self::encodingForThreeZero($encoding, $name, $losses);

                if ($kept !== null) {
                    $parameters[] = new Parameter('ENCODING', [$kept]);
                }

                continue;
            }

            if ($of === 'TYPE') {
                $types = [...$types, ...$parameter->values()];

                continue;
            }

            $parameters[] = $parameter;
        }

        if ($encoding === 'QUOTED-PRINTABLE') {
            $value = self::fromQuotedPrintable($value);
        }

        if (in_array($name, self::SINGLE_TEXT, true)) {
            $value = self::escapedForThreeZero($value);
        }

        if ($types !== []) {
            $parameters[] = new Parameter('TYPE', $types);
        }

        return new Property($name, $value, $parameters);
    }

    /**
     * What 3.0 writes for an encoding 2.1 named, or null where it writes
     * none.
     *
     * | written | becomes | because |
     * |---|---|---|
     * | `QUOTED-PRINTABLE` | nothing | §5: "The QUOTED-PRINTABLE inline encoding has been eliminated" — the value is decoded instead |
     * | `BASE64` | `b` | §5: "Only the 'B' encoding of [RFC 2047] is an allowed value" — the same transformation under the name 3.0 allows |
     * | `B` | `b` | already that |
     * | `8BIT` | nothing | RFC 2425 §5.5: "The default encoding is 8bit", so saying it says nothing |
     *
     * **Anything else is kept and named.** RFC 2047 is not in
     * `docs/referenzen/`, and no memo there says what another name means, so
     * the value is left exactly as it stands rather than decoded on a guess.
     *
     * @param list<Finding> $losses
     *
     * @param-out list<Finding> $losses
     */
    private static function encodingForThreeZero(string $encoding, string $name, array &$losses): ?string
    {
        if ($encoding === 'QUOTED-PRINTABLE' || $encoding === '8BIT') {
            return null;
        }

        if ($encoding === 'BASE64' || $encoding === 'B') {
            return 'b';
        }

        $losses[] = self::loss($name, sprintf(
            'vCard 3.0 allows only the "B" encoding (RFC 2426 §5), and no memo here says what "%s" means, so the value was left as it stands.',
            $encoding,
        ));

        return $encoding;
    }

    /**
     * 3.0 down to 2.1.
     *
     * @return array{Component, list<Finding>}
     */
    private static function downToTwoOne(Component $card): array
    {
        $written = new Component('VCARD');
        $losses = [];

        foreach ($card->properties() as $property) {
            $name = strtoupper($property->name());

            if ($name === 'VERSION') {
                continue;
            }

            if (in_array($name, self::ADDED_IN_THREE, true)) {
                $losses[] = self::loss($name, sprintf(
                    'vCard 2.1 has no %s property (RFC 2426 §5 names it as added in 3.0), so it was left behind.',
                    $name,
                ));

                continue;
            }

            $written->add(self::inTwoOne($property, $name));
        }

        return [$written, $losses];
    }

    /**
     * One property as 2.1 writes it.
     */
    private static function inTwoOne(Property $property, string $name): Property
    {
        $value = $property->value();
        $parameters = [];

        foreach ($property->parameters() as $parameter) {
            if (strtoupper($parameter->name()) !== 'TYPE') {
                $parameters[] = $parameter;

                continue;
            }

            // §5 calls the prefix optional in 2.1, and without it is how a
            // reader of 2.1 expects to find a type.
            foreach ($parameter->values() as $type) {
                $parameters[] = new Parameter(strtoupper($type), []);
            }
        }

        if (in_array($name, self::SINGLE_TEXT, true)) {
            $value = self::unescapedForTwoOne($value);
        }

        return new Property($name, $value, $parameters);
    }

    /**
     * A quoted-printable value decoded, its line breaks written the way 3.0
     * writes one.
     *
     * §5: "The CRLF character sequence in a text type value is specified with
     * the backslash character sequence '\n' or '\N'."
     */
    private static function fromQuotedPrintable(string $value): string
    {
        $decoded = quoted_printable_decode($value);

        return str_replace(["\r\n", "\n", "\r"], '\\n', $decoded);
    }

    /**
     * A text value with the characters §5 asks to be escaped, escaped.
     *
     * > Any COMMA or SEMICOLON in a text type value must be backslash
     * > escaped.
     *
     * A backslash already there is left alone: a value that came through
     * {@see self::fromQuotedPrintable()} carries the `\\n` that sentence asks
     * for, and doubling it would make a backslash of it.
     */
    private static function escapedForThreeZero(string $value): string
    {
        return (string) preg_replace('/(?<!\\\\)([,;])/', '\\\\$1', $value);
    }

    /**
     * And a 2.1 value, where the backslash has no such meaning.
     */
    private static function unescapedForTwoOne(string $value): string
    {
        return str_replace(['\\,', '\\;'], [',', ';'], $value);
    }

    /**
     * One property as 4.0 writes it.
     *
     * @param list<Finding> $losses
     *
     * @param-out list<Finding> $losses
     */
    private static function inFourZero(Property $property, string $name, array &$losses): Property
    {
        $value = $property->value();
        $parameters = [];
        $preferred = false;
        $media = null;

        // **Whether the value is base64 decides where the format name goes.**
        // A photo written inline becomes a `data:` URI and takes its media
        // type with it; a photo written as a URL keeps its value and takes the
        // media type into the `MEDIATYPE` parameter A.3 added for it. Folding
        // a URL into a `data:` URI would make nonsense of both.
        $inline = in_array($name, self::BINARY, true)
            && strtolower((string) $property->parameter('ENCODING')?->value()) === 'b';

        foreach ($property->parameters() as $parameter) {
            $of = strtoupper($parameter->name());

            if (in_array($of, self::PARAMETERS_GONE_IN_FOUR, true)) {
                $losses[] = self::loss($name, sprintf(
                    'vCard 4.0 has no %s parameter (RFC 6350 A.2), so %s lost it.',
                    $of,
                    $name,
                ));

                continue;
            }

            // The encoding is not a parameter of a URI, and the format moves
            // into the value with it (A.3).
            if ($of === 'ENCODING' && in_array($name, self::BINARY, true)) {
                continue;
            }

            if ($of === 'TYPE') {
                $kept = self::typesForFourZero($parameter, $name, $losses);
                $preferred = $kept['preferred'];
                $media = $kept['media'];

                if ($kept['types'] !== []) {
                    $parameters[] = new Parameter('TYPE', $kept['types']);
                }

                continue;
            }

            $parameters[] = $parameter;
        }

        if ($preferred) {
            $parameters[] = new Parameter('PREF', ['1']);
        }

        // **An inline value has to become a URI**, §6.2.4 and its like taking
        // a URI value in 4.0: leaving the base64 where it was would write a
        // card no reader of 4.0 can take. Where the format name has no media
        // type here, the value still becomes a URI — with the type that says
        // "some bytes" — and **the format is named as a loss** rather than
        // guessed at from a name nobody registered.
        if ($inline) {
            $value = self::asADataUri($value, $media ?? self::SOME_BYTES);

            if ($media === null) {
                $losses[] = self::loss($name, sprintf(
                    'No media type is known here for the %s format, so the value carries "%s" instead.',
                    $name,
                    self::SOME_BYTES,
                ));
            }
        } elseif ($media !== null) {
            $parameters[] = new Parameter('MEDIATYPE', [$media]);
        }

        return new Property($name, $value, $parameters);
    }

    /**
     * The `TYPE` values 4.0 keeps, with the format folded into the value and
     * the preference taken out of the list.
     *
     * @param list<Finding> $losses
     *
     * @param-out list<Finding> $losses
     *
     * @return array{types: list<string>, preferred: bool, media: string|null}
     */
    private static function typesForFourZero(
        Parameter $parameter,
        string $name,
        array &$losses,
    ): array {
        $types = [];
        $preferred = false;
        $media = null;
        $binary = in_array($name, self::BINARY, true);

        foreach ($parameter->values() as $written) {
            $type = strtolower($written);

            if ($type === 'pref') {
                $preferred = true;

                continue;
            }

            // A format name on a property that can carry binary data is a
            // media type from 4.0 on (A.3). Where it goes — into the value or
            // into MEDIATYPE — the caller decides, knowing whether the value
            // is the data itself.
            if ($binary && isset(self::MEDIA_TYPES[strtoupper($written)])) {
                $media = self::MEDIA_TYPES[strtoupper($written)];

                continue;
            }

            if ($name === 'ADR' && in_array($type, self::ADDRESS_TYPES_GONE_IN_FOUR, true)) {
                $losses[] = self::loss($name, sprintf(
                    'vCard 4.0 removed the "%s" TYPE value of ADR (RFC 6350 A.2), so it was left behind.',
                    $type,
                ));

                continue;
            }

            $types[] = $type;
        }

        return ['types' => $types, 'preferred' => $preferred, 'media' => $media];
    }

    /**
     * One property as 3.0 writes it.
     *
     * @param list<Finding> $losses
     *
     * @param-out list<Finding> $losses
     */
    private static function inThreeZero(Property $property, string $name, array &$losses): Property
    {
        $value = $property->value();
        $parameters = [];
        $types = [];

        foreach ($property->parameters() as $parameter) {
            $of = strtoupper($parameter->name());

            // **A table rather than a chain of ifs**, and Xdebug is why: four
            // independent `if`s in a row make sixteen ways through this loop
            // for a reader to count, and the coverage run counts them all.
            // Measured on 13.10.2026, this method came to 726 paths that way
            // and to a tenth of that as a lookup — see the note in the
            // progress file that P4-07b paid for.
            if (!in_array($of, self::WORKED_ON_IN_THREE, true)) {
                $parameters[] = $parameter;

                continue;
            }

            $types = self::typesFor($of, $parameter, $name, $types, $losses);
        }

        if (in_array($name, self::BINARY, true)) {
            [$value, $format, $encoded] = self::fromADataUri($value);

            if ($encoded) {
                $parameters[] = new Parameter('ENCODING', ['b']);
            }

            // **One TYPE parameter, however many places the format came
            // from.** The media type of a `data:` URI and a `MEDIATYPE`
            // beside it name the same thing, and writing both out would put
            // the same word in twice.
            if ($format !== null && !in_array($format, $types, true)) {
                $types[] = $format;
            }

            if ($encoded && $format === null) {
                $losses[] = self::loss($name, sprintf(
                    'vCard 3.0 names a format rather than a media type, and this value carries one with no name here, so %s lost it.',
                    $name,
                ));
            }
        }

        if ($types !== []) {
            $parameters[] = new Parameter('TYPE', $types);
        }

        return new Property($name, $value, $parameters);
    }

    /**
     * The type values a parameter 3.0 writes differently comes to.
     *
     * The four cases {@see self::WORKED_ON_IN_THREE} names, each with the
     * section that makes it a case:
     *
     * - **`SORT-AS`** is lifted out into a `SORT-STRING` of its own
     *   (RFC 2426 §3.6.5 against RFC 6350 §5.9), so nothing is added here.
     * - **`PREF`** becomes a type value again, A.3 having made it a parameter
     *   "with a positive integer value indicating the level of preference" —
     *   and the level is a loss, 3.0 having only the flag.
     * - **`TYPE`** keeps its values, in front of whatever came before.
     * - **`MEDIATYPE`** goes back to being a format name where the table has
     *   one, A.3: it "replaces the TYPE parameter when it was used for
     *   indicating the media type".
     *
     * @param list<string> $types
     * @param list<Finding> $losses
     *
     * @param-out list<Finding> $losses
     *
     * @return list<string>
     */
    private static function typesFor(
        string $of,
        Parameter $parameter,
        string $name,
        array $types,
        array &$losses,
    ): array {
        if ($of === 'SORT-AS') {
            return $types;
        }

        if ($of === 'TYPE') {
            return [...$parameter->values(), ...$types];
        }

        if ($of === 'PREF') {
            $level = $parameter->value();

            if ($level !== null && $level !== '1') {
                $losses[] = self::loss($name, sprintf(
                    'vCard 3.0 has no level of preference, so PREF=%s became TYPE=pref alone.',
                    $level,
                ));
            }

            return [...$types, 'pref'];
        }

        $format = array_search((string) $parameter->value(), self::MEDIA_TYPES, true);

        if (!is_string($format)) {
            $losses[] = self::loss($name, sprintf(
                'vCard 3.0 has no MEDIATYPE parameter and no format name for "%s", so it was left behind.',
                (string) $parameter->value(),
            ));

            return $types;
        }

        return [...$types, $format];
    }

    /**
     * A base64 value as the `data:` URI 4.0 writes (RFC 6350 §6.2.4, which
     * takes a URI value, and RFC 2397 for the shape of it).
     */
    private static function asADataUri(string $value, string $type): string
    {
        return sprintf('data:%s;base64,%s', $type, $value);
    }

    /**
     * A `data:` URI taken apart again: the base64 value, the format name 3.0
     * writes for its media type where there is one, and whether it was such a
     * URI at all.
     *
     * @return array{string, string|null, bool}
     */
    private static function fromADataUri(string $value): array
    {
        if (preg_match('/^data:([^;,]+);base64,(.*)$/', $value, $found) !== 1) {
            return [$value, null, false];
        }

        $format = array_search($found[1], self::MEDIA_TYPES, true);

        return [$found[2], is_string($format) ? $format : null, true];
    }

    /**
     * Puts a kept-aside sort string on the `N` it sorts, §5.9 making it a
     * parameter of the name rather than a property of its own.
     *
     * **A sort string without an `N` has nowhere to go**, and that is a loss
     * like any other: §5.9 is a parameter of a property, so a card that sorts
     * by a name it does not carry cannot say so in 4.0. Dropping it in silence
     * would hide a line its author wrote.
     *
     * @param list<Finding> $losses
     *
     * @param-out list<Finding> $losses
     */
    private static function putTheSortAsOnTheName(Component $card, ?string $sortAs, array &$losses): void
    {
        if ($sortAs === null) {
            return;
        }

        $name = $card->property('N');

        if ($name === null) {
            $losses[] = self::loss('SORT-STRING', sprintf(
                'vCard 4.0 sorts by a parameter of N (RFC 6350 §5.9), and this card has no N, so "%s" was left behind.',
                $sortAs,
            ));

            return;
        }

        $name->add(new Parameter('SORT-AS', [$sortAs]));
    }

    /**
     * Writes a kept-aside `SORT-AS` back as the property 3.0 has for it
     * (RFC 2426 §3.6.5).
     */
    private static function putTheSortStringIn(Component $card, ?string $sortString): void
    {
        if ($sortString === null) {
            return;
        }

        $card->add(new Property('SORT-STRING', $sortString));
    }

    /**
     * One thing the other version had no room for.
     */
    private static function loss(string $where, string $message): Finding
    {
        return new Finding(Severity::Warning, $where, $message);
    }

    /**
     * @throws ParseError If the component is not a vCard
     */
    private static function refuseAnythingElse(Component $card): void
    {
        if (strtoupper($card->name()) !== 'VCARD') {
            throw new ParseError(sprintf(
                'A vCard is converted, and this is a "%s" — a VCARD is what carries a VERSION of 3.0 or 4.0.',
                $card->name(),
            ));
        }
    }
}
