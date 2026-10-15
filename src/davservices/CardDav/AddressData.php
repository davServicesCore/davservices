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

namespace DavServices\CardDav;

use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use DavServices\Exception\UnsupportedMediaType;
use DavServices\VObject\Component;
use DavServices\VObject\ParseError;
use DavServices\VObject\Property;
use DavServices\VObject\Serializer;
use DavServices\VObject\VCard\Conversion;
use DavServices\VObject\VCard\Converter;
use DavServices\Xml\Element;

/**
 * Which parts of a card a report is to return, and in which version
 * (RFC 6352 §10.4).
 *
 * The element has two shapes, one per direction:
 *
 *     <!ELEMENT address-data (allprop | prop*)>   in a request
 *     <!ELEMENT address-data (#PCDATA)>           in a response
 *     <!ATTLIST address-data content-type CDATA "text/vcard"
 *                           version CDATA "3.0">
 *
 * "If the CARDDAV:address-data XML element doesn't contain any CARDDAV:prop
 * elements, address object resources will be returned in their entirety.
 * Additionally, a media type and version can be specified to request that the
 * server return the data in that format if possible."
 *
 * **It is not a WebDAV property**, which §10.4 says twice: it is written
 * "inside the DAV:prop XML element as if it were a WebDAV property. However,
 * the CARDDAV:address-data XML element is not a WebDAV property and as such it
 * is not returned in PROPFIND responses nor used in PROPPATCH requests." So a
 * report answers it itself rather than letting the property machinery try —
 * and a `DAV:allprop` never brings it along.
 *
 * ## Why a client restricts the properties
 *
 * §9.1: "Since some property data can be large (e.g., PHOTO or SOUND with
 * in-line content) clients can choose to ignore those by only requesting the
 * specific items it knows it will use." Which properties a name picks out is
 * §10.4.2's group-prefix rule, which is §10.5.1's rule again, so it lives
 * once in {@see PropertyName}.
 *
 * ## The answer may be an invalid vCard, on purpose
 *
 * §10.4: "address object resources specified in the CARDDAV:address-data XML
 * element MAY be invalid per their media type specification if the
 * CARDDAV:address-data XML element part of the address book REPORT request did
 * not specify required vCard properties (e.g., UID, etc.) or specified a
 * CARDDAV:prop XML element with the 'novalue' attribute set to 'yes'." So a
 * restricted card is not patched up to be valid.
 *
 * **`VERSION` is the one exception, and it is written from the attribute
 * rather than kept from the card.** Both choices are conformant by the
 * sentence above, and this one is the useful half: the `version` attribute is
 * a promise about what the data is, and data without it cannot be read back as
 * a vCard at all.
 *
 * ## Two refusals, and they are not the same refusal
 *
 * A media type **this server never advertised** breaks §8.6's and §8.7's
 * `CARDDAV:supported-address-data` precondition, which is about the request:
 * it is refused whole, before any card is read.
 *
 * A card **this server cannot turn into what was asked for** is §5.1.1's other
 * case — "In some cases, it might not be possible for a server to convert from
 * one media type to another" — and §8.7.2 shows the answer: `415` with
 * `CARDDAV:supported-address-data-conversion`, in that card's own
 * `DAV:response`. The other hrefs of a multiget are answered as usual.
 */
final class AddressData
{
    public const NAME = '{urn:ietf:params:xml:ns:carddav}address-data';

    /** §8.6, §8.7: the request names a media type this server keeps. */
    public const SUPPORTED = '{urn:ietf:params:xml:ns:carddav}supported-address-data';

    /** §5.1.1: this card cannot be turned into what was asked for. */
    public const CONVERSION = '{urn:ietf:params:xml:ns:carddav}supported-address-data-conversion';

    private const ALLPROP = '{urn:ietf:params:xml:ns:carddav}allprop';

    private const PROP = '{urn:ietf:params:xml:ns:carddav}prop';

    private const VCARD = 'text/vcard';

    /**
     * The media types a server keeps unless it says otherwise.
     *
     * §6.2.2 settles what silence means, and it is the narrow reading: "In the
     * absence of this property, the server MUST only accept data with the media
     * type 'text/vcard' and vCard version 3.0." So a server that reads 4.0 as
     * well has to say so, and this is what it says by default — kept here
     * rather than in each of the three places that need it, because they have
     * to agree: the property that publishes the list, and the two reports held
     * to it.
     *
     * @var list<array{string, string}>
     */
    public const KEPT = [[self::VCARD, '3.0'], [self::VCARD, '4.0']];

    private const DEFAULT_VERSION = '3.0';

    private const VERSION = 'VERSION';

    /** The versions this library can write, whatever a server advertises. */
    private const MAKEABLE = ['2.1', '3.0', '4.0'];

    /**
     * @param list<array{PropertyName, bool}> $wanted The properties named, and
     *                                                whether each was asked
     *                                                for without its value.
     *                                                Empty means the whole card
     */
    private function __construct(
        private readonly string $contentType,
        private readonly string $version,
        private readonly array $wanted,
    ) {
    }

    /**
     * Reads the element as a request.
     *
     * @param list<array{string, string}> $supported What the server says it
     *                                               keeps, as
     *                                               `CARDDAV:supported-address-data`
     *                                               publishes it (§6.2.2)
     *
     * @throws BadRequest If a child is one the DTD does not name, `allprop`
     *                    arrives beside a `prop`, a `prop` has no name, or
     *                    `novalue` says neither yes nor no
     * @throws Forbidden If the media type asked for is not one this server
     *                   advertises
     */
    public static function of(Element $element, array $supported): self
    {
        $contentType = strtolower($element->attribute('content-type') ?? self::VCARD);
        $version = $element->attribute('version') ?? self::DEFAULT_VERSION;

        self::mustBeOneItKeeps($contentType, $version, $supported);

        $wanted = [];
        $everything = false;

        foreach ($element->children() as $child) {
            if ($child->name() === self::ALLPROP) {
                $everything = true;

                continue;
            }

            if ($child->name() === self::PROP) {
                $wanted[] = self::named($child);

                continue;
            }

            throw new BadRequest(sprintf(
                '%s has no place in a CARDDAV:address-data of RFC 6352 §10.4.',
                $child->name(),
            ));
        }

        // `(allprop | prop*)` is a choice. Asking for everything and for three
        // named properties says two things at once, and only one can be meant.
        if ($everything && $wanted !== []) {
            throw new BadRequest(
                'A CARDDAV:address-data asks for everything or for named properties: RFC 6352 §10.4 spells it (allprop | prop*).',
            );
        }

        return new self($contentType, $version, $wanted);
    }

    /**
     * The card as the client asked for it, ready to go inside the element.
     *
     * @throws UnsupportedMediaType If this card cannot be turned into what was
     *                              asked for (§5.1.1, §8.7.2)
     */
    public function textOf(Component $card): string
    {
        return (new Serializer())->write($this->restricted($this->converted($card)));
    }

    /**
     * The element as a response carries it, which is §10.4's second shape:
     * `<!ELEMENT address-data (#PCDATA)>`.
     *
     * The writer escapes the text, which is what §10.4 asks for — "The address
     * data embedded within the CARDDAV:address-data XML element MUST follow the
     * standard XML character data encoding rules, including use of &lt;, &gt;,
     * &amp; etc., entity encoding or the use of a <![CDATA[ ... ]]> construct."
     * Entity encoding is the half chosen here, so the warning that follows it —
     * that CDATA cannot carry the sequence that ends a CDATA section — does not
     * apply.
     *
     * @throws UnsupportedMediaType If this card cannot be turned into what was
     *                              asked for (§5.1.1, §8.7.2)
     */
    public function elementFor(Component $card): Element
    {
        $element = new Element(self::NAME);

        $element->appendText($this->textOf($card));

        return $element;
    }

    /**
     * @param list<array{string, string}> $supported
     *
     * @throws Forbidden If this is not one of them
     */
    private static function mustBeOneItKeeps(string $contentType, string $version, array $supported): void
    {
        foreach ($supported as [$type, $kept]) {
            // RFC 9110 §8.3.1 makes a media type's "type, subtype, and
            // parameter name tokens" case-insensitive. A version is a string
            // the memo prints as it is, and is compared that way.
            if (strtolower($type) === $contentType && $kept === $version) {
                return;
            }
        }

        throw new Forbidden(
            sprintf(
                'This server does not keep address data as "%s" version %s; CARDDAV:supported-address-data says what it keeps (RFC 6352 §6.2.2).',
                $contentType,
                $version,
            ),
            self::SUPPORTED,
        );
    }

    /**
     * One `CARDDAV:prop`: the property named, and whether its value is wanted.
     *
     *
     * @throws BadRequest If there is no name, or `novalue` says something the
     *                    DTD does not admit
     *
     * @return array{PropertyName, bool}
     */
    private static function named(Element $element): array
    {
        $name = $element->attribute('name') ?? throw new BadRequest(
            'A CARDDAV:prop says which property it is about: RFC 6352 §10.4.2 makes "name" #REQUIRED.',
        );

        return [PropertyName::of($name), self::withoutValue($element->attribute('novalue'))];
    }

    /**
     * Whether the client asked for the property without its value.
     *
     * @throws BadRequest If the attribute says neither. Reading a third word
     *                    as "no" would send a client the photograph it was
     *                    taking care not to ask for
     */
    private static function withoutValue(?string $attribute): bool
    {
        return match ($attribute) {
            null, 'no' => false,
            'yes' => true,
            default => throw new BadRequest(sprintf(
                '"%s" is no novalue of RFC 6352 §10.4.2.',
                $attribute,
            )),
        };
    }

    /**
     * The card in the version that was asked for.
     *
     * @throws UnsupportedMediaType If it cannot be had. Two ways to get here:
     *                              the server advertises a media type this
     *                              library cannot write, or the stored card
     *                              carries a version it cannot read
     */
    private function converted(Component $card): Component
    {
        if ($this->contentType !== self::VCARD || !in_array($this->version, self::MAKEABLE, true)) {
            throw $this->cannotConvert();
        }

        try {
            return self::asked($card, $this->version)->card();
        } catch (ParseError $unreadable) {
            throw $this->cannotConvert($unreadable);
        }
    }

    /**
     * §8.7.2's own answer, with its own wording: "Unable to convert from vCard
     * v3.0 to vCard v4.0".
     */
    private function cannotConvert(?ParseError $why = null): UnsupportedMediaType
    {
        return new UnsupportedMediaType(
            sprintf('Unable to convert this card to "%s" version %s.', $this->contentType, $this->version),
            self::CONVERSION,
            $why,
        );
    }

    /**
     * Written as an `if` chain rather than a `match` for the reason
     * {@see Filter\MatchType::holds()} is: an exhaustive `match` compiles with
     * an implicit arm no value can reach, and the guard above has already left
     * only these three.
     *
     * @throws ParseError If the card carries a version this cannot read
     */
    private static function asked(Component $card, string $version): Conversion
    {
        if ($version === '2.1') {
            return Converter::toTwoOne($card);
        }

        if ($version === '3.0') {
            return Converter::toThreeZero($card);
        }

        return Converter::toFourZero($card);
    }

    /**
     * The card with only the properties the client named.
     */
    private function restricted(Component $card): Component
    {
        if ($this->wanted === []) {
            return $card;
        }

        $restricted = new Component($card->name());
        $restricted->add(new Property(self::VERSION, $this->version));

        foreach ($card->properties() as $property) {
            // Already written, from the attribute that promised it — and
            // writing it again would answer with two of them.
            if (strcasecmp($property->name(), self::VERSION) === 0) {
                continue;
            }

            $withoutValue = $this->askedFor($property);

            if ($withoutValue === null) {
                continue;
            }

            $restricted->add($withoutValue ? self::bare($property) : $property);
        }

        return $restricted;
    }

    /**
     * Whether this property was named, and if so whether its value was wanted.
     *
     * Null is the answer for a property nobody named, which is why this is not
     * two methods: one walk over the names answers both halves.
     */
    private function askedFor(Property $property): ?bool
    {
        foreach ($this->wanted as [$named, $withoutValue]) {
            if ($named->matches($property)) {
                return $withoutValue;
            }
        }

        return null;
    }

    /**
     * §10.4.2: "the server will return just the vCard property name and any
     * vCard parameters and a trailing ':' without the subsequent value data."
     *
     * A new property rather than the stored one emptied, because the stored
     * one belongs to the card the caller handed over.
     */
    private static function bare(Property $property): Property
    {
        return new Property($property->name(), '', $property->parameters(), $property->group());
    }
}
