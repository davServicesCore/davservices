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

namespace DavServices\CalDav;

use DavServices\Exception\BadRequest;
use DavServices\VObject\Component;
use DavServices\VObject\Property;
use DavServices\Xml\Element;

/**
 * Which parts of a component a report returns (RFC 4791 §9.6.1 to §9.6.4).
 *
 *     <!ELEMENT comp ((allprop | prop*), (allcomp | comp*))>
 *     <!ATTLIST comp name CDATA #REQUIRED>
 *     <!ELEMENT allcomp EMPTY>
 *     <!ELEMENT allprop EMPTY>
 *     <!ELEMENT prop EMPTY>
 *     <!ATTLIST prop name CDATA #REQUIRED
 *                    novalue (yes | no) "no">
 *
 * **Why a client narrows at all** is §9.6's own sentence: "one can explicitly
 * specify the calendar components and properties that should be returned in
 * the calendar object resource data that matches the filter". A month view
 * needs a summary and a start, not every alarm and attachment of every
 * appointment — and §9.6.4's `novalue` is how a client learns that an
 * attachment is there without being sent it.
 *
 * ## It carries itself, and that is the whole shape
 *
 * A `CALDAV:comp` holds `CALDAV:comp` elements, so one restriction describes a
 * tree: the calendar object, the components in it, the alarms in those. Each
 * level says what of its own component comes back, and a level that says
 * nothing says nothing comes back — `prop*` and `comp*` with no occurrence are
 * a request for none, which is what §7.8.1's example relies on when "the only
 * property returned in the VCALENDAR object is VERSION".
 *
 * **The outermost one is about the calendar object itself.** §9.6.1 names
 * `VCALENDAR` in its own example, and §9.7.1 scopes the outermost
 * `CALDAV:comp-filter` the same way. A restriction about another kind of
 * component therefore names no part of this object, and {@see appliedTo()}
 * answers null rather than guessing which part was meant.
 *
 * ## The answer may be invalid iCalendar, on purpose
 *
 * §9.6: "calendar object resources specified in the CALDAV:calendar-data XML
 * element MAY be invalid per their media type specification if the
 * CALDAV:calendar-data XML element part of the calendaring REPORT request did
 * not specify required properties (e.g., UID, DTSTAMP, etc.), or specified a
 * CALDAV:prop XML element with the 'novalue' attribute set to 'yes'." So
 * nothing is patched up.
 *
 * **Unlike {@see \DavServices\CardDav\AddressData}**, which writes a `VERSION`
 * from its own attribute whatever the client named: a vCard without one cannot
 * be read back at all, while iCalendar carries its version on the object
 * rather than on each component — so a narrowed `VEVENT` is still readable as
 * what it is, and the client gets what it asked for.
 *
 * ## What is kept, and in which order
 *
 * The properties of a component come before the components inside it, which is
 * the order RFC 5545 §3.6 writes — `eventc = "BEGIN:VEVENT" CRLF eventprop
 * *alarmc "END:VEVENT"` — and within each the object's own order stands. A
 * restriction says which parts come back, and §9.6 says nothing about
 * rearranging them.
 *
 * Every occurrence of a property named comes back, not the first: §3.6.1 lets
 * a component carry one `ATTENDEE` per attendee, and a client that asked for
 * them asked for all of them.
 */
final readonly class CalendarDataFilter
{
    public const NAME = '{urn:ietf:params:xml:ns:caldav}comp';

    private const ALLCOMP = '{urn:ietf:params:xml:ns:caldav}allcomp';

    private const ALLPROP = '{urn:ietf:params:xml:ns:caldav}allprop';

    private const PROP = '{urn:ietf:params:xml:ns:caldav}prop';

    /**
     * @param list<array{string, bool}> $properties The properties named, and
     *                                              whether each was asked for
     *                                              without its value
     * @param list<self> $components The restrictions on the components inside
     */
    private function __construct(
        private string $name,
        private bool $allProperties,
        private array $properties,
        private bool $allComponents,
        private array $components,
    ) {
    }

    /**
     * Reads one `CALDAV:comp`, and everything it carries.
     *
     * @throws BadRequest If the `name` attribute is missing, `novalue` says
     *                    neither yes nor no, or the children are not what the
     *                    DTD admits in the order it writes them
     */
    public static function of(Element $element): self
    {
        $name = $element->attribute('name') ?? throw new BadRequest(
            'A CALDAV:comp says which component it is about: RFC 4791 §9.6.1 makes "name" #REQUIRED.',
        );

        $allProperties = false;
        $properties = [];
        $allComponents = false;
        $components = [];

        foreach ($element->children() as $child) {
            $wantsAComponent = $child->name() === self::ALLCOMP || $child->name() === self::NAME;

            // `((allprop | prop*), (allcomp | comp*))`: the properties come
            // first, and the order the DTD writes is the order it means.
            if (!$wantsAComponent && ($allComponents || $components !== [])) {
                throw new BadRequest(sprintf(
                    'A %s comes before the components of a CALDAV:comp: RFC 4791 §9.6.1 writes that order.',
                    $child->name(),
                ));
            }

            match ($child->name()) {
                self::ALLPROP => $allProperties = true,
                self::PROP => $properties[] = self::namedProperty($child),
                self::ALLCOMP => $allComponents = true,
                self::NAME => $components[] = self::of($child),
                default => throw new BadRequest(sprintf(
                    '%s has no place in a CALDAV:comp of RFC 4791 §9.6.1.',
                    $child->name(),
                )),
            };
        }

        // Both groups are a choice. Asking for everything and for three named
        // things says two things at once, and only one can be meant.
        if ($allProperties && $properties !== []) {
            throw new BadRequest(
                'A CALDAV:comp asks for every property or for named ones: RFC 4791 §9.6.1 spells it (allprop | prop*).',
            );
        }

        if ($allComponents && $components !== []) {
            throw new BadRequest(
                'A CALDAV:comp asks for every component or for named ones: RFC 4791 §9.6.1 spells it (allcomp | comp*).',
            );
        }

        return new self($name, $allProperties, $properties, $allComponents, $components);
    }

    /**
     * The component with only the parts this restriction names, or null where
     * the restriction is about another kind of component.
     *
     * The component handed over is left as it is: what comes back is built
     * beside it, because the one handed over belongs to whatever store the
     * report read it from.
     */
    public function appliedTo(Component $component): ?Component
    {
        // Without regard to case, because RFC 5545 §3.1 and RFC 5234 §2.3 read
        // the names of the grammar that way.
        if (strcasecmp($component->name(), $this->name) !== 0) {
            return null;
        }

        $kept = new Component($component->name());

        foreach ($component->properties() as $property) {
            $this->keepTheProperty($kept, $property);
        }

        foreach ($component->components() as $inner) {
            $this->keepTheComponent($kept, $inner);
        }

        return $kept;
    }

    /**
     * Adds this property where it was asked for.
     */
    private function keepTheProperty(Component $kept, Property $property): void
    {
        if ($this->allProperties) {
            $kept->add($property);

            return;
        }

        $withoutValue = $this->askedFor($property);

        if ($withoutValue === null) {
            return;
        }

        $kept->add($withoutValue ? self::bare($property) : $property);
    }

    /**
     * Adds this component, narrowed as the restriction about it says.
     *
     * **`allcomp` brings a component whole**, because §9.6.2 is a request for
     * "all types of components" and there is no `CALDAV:comp` beside it to say
     * which parts of them.
     */
    private function keepTheComponent(Component $kept, Component $inner): void
    {
        if ($this->allComponents) {
            $kept->add($inner);

            return;
        }

        foreach ($this->components as $restriction) {
            $narrowed = $restriction->appliedTo($inner);

            if ($narrowed !== null) {
                $kept->add($narrowed);

                return;
            }
        }
    }

    /**
     * Whether this property was named, and if so whether its value was
     * wanted.
     *
     * Null is the answer for a property nobody named, which is why this is not
     * two methods: one walk over the names answers both halves.
     */
    private function askedFor(Property $property): ?bool
    {
        foreach ($this->properties as [$named, $withoutValue]) {
            if (strcasecmp($property->name(), $named) === 0) {
                return $withoutValue;
            }
        }

        return null;
    }

    /**
     * One `CALDAV:prop`: the property named, and whether its value is wanted.
     *
     * @throws BadRequest If there is no name, or `novalue` says something the
     *                    DTD does not admit
     *
     * @return array{string, bool}
     */
    private static function namedProperty(Element $element): array
    {
        $name = $element->attribute('name') ?? throw new BadRequest(
            'A CALDAV:prop says which property it is about: RFC 4791 §9.6.4 makes "name" #REQUIRED.',
        );

        return [$name, self::withoutValue($element->attribute('novalue'))];
    }

    /**
     * Whether the client asked for the property without its value.
     *
     * @throws BadRequest If the attribute says neither. Reading a third word
     *                    as "no" would send a client the attachment it was
     *                    taking care not to ask for
     */
    private static function withoutValue(?string $attribute): bool
    {
        return match ($attribute) {
            null, 'no' => false,
            'yes' => true,
            default => throw new BadRequest(sprintf(
                '"%s" is no novalue of RFC 4791 §9.6.4.',
                $attribute,
            )),
        };
    }

    /**
     * §9.6.4: "the server will return just the iCalendar property name and any
     * iCalendar parameters and a trailing ':' without the subsequent value
     * data."
     *
     * A new property rather than the stored one emptied, because the stored
     * one belongs to the object the caller handed over.
     */
    private static function bare(Property $property): Property
    {
        return new Property($property->name(), '', $property->parameters());
    }
}
