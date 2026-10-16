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

use DavServices\Dav\IFile;
use DavServices\Dav\INode;
use DavServices\Exception\Forbidden;
use DavServices\VObject\Component;
use DavServices\VObject\ParseError;
use DavServices\VObject\Reader;

/**
 * Whether a node of the tree is a calendar object, and which one (RFC 4791
 * §4.1).
 *
 * **The question CalDAV asks at every write.** The limits of §5.2 are each
 * about "a calendar object resource stored in a calendar collection", and
 * §4.1's own restrictions are about the same thing — so "is this one, and what
 * is in it" is asked in one place, the way {@see \DavServices\CardDav\AddressObject}
 * asks it for a card.
 *
 * **And what §4.1 asks of a resource is asked here too** — one type of
 * component, no `METHOD` property, one `UID`, and a `VTIMEZONE` for every zone
 * the object names. {@see mustBeOneResource()} is that half, and it is the
 * `CALDAV:valid-calendar-object-resource` precondition of §5.3.2.1: "The
 * resource submitted in the PUT request, or targeted by a COPY or MOVE
 * request, MUST obey all restrictions specified in Section 4.1".
 *
 * **What it cannot ask is whether the `UID` is free**, because that is a
 * question about the collection and not about the object: §4.1 wants it
 * "unique in the scope of the calendar collection in which they are stored",
 * which only the layer that can see the collection knows.
 * {@see \DavServices\Plugin\CalDav} asks it, with
 * `CALDAV:no-uid-conflict`.
 *
 * ## `VTIMEZONE` is not a kind of its own, except when it is
 *
 * §5.2.3 draws that line in one sentence: "The empty-element tag
 * `<C:comp name="VTIMEZONE"/>` MUST only be specified if support for calendar
 * object resources that only contain VTIMEZONE components is provided or
 * desired. Support for VTIMEZONE components in calendar object resources that
 * contain VEVENT or VTODO components is always assumed."
 *
 * So an event and the zone that event needs are **one** kind, not two — §4.1
 * says the same thing from the other side, excepting "VTIMEZONE components,
 * which MUST be specified for each unique TZID parameter value specified in
 * the iCalendar object". An object of nothing but zones, on the other hand,
 * has to be able to name its kind, or a collection could not be said to take
 * it.
 */
final class CalendarObject
{
    private const VCALENDAR = 'VCALENDAR';

    private const VTIMEZONE = 'VTIMEZONE';

    /** §5.3.2.1's precondition for everything §4.1 asks of a resource. */
    public const VALID_OBJECT = '{urn:ietf:params:xml:ns:caldav}valid-calendar-object-resource';

    /**
     * The property §4.1 forbids outright, and the parameter that asks for a
     * zone.
     */
    private const METHOD = 'METHOD';

    private const TZID = 'TZID';

    /**
     * The one iCalendar object this node holds, or null where it holds none.
     */
    public static function of(INode $node): ?Component
    {
        if (!$node instanceof IFile) {
            return null;
        }

        return self::in($node->get());
    }

    /**
     * The one iCalendar object this text holds, or null.
     *
     * The same question asked of bytes rather than of a node, because
     * §5.3.2.1 asks it of "the resource submitted in the PUT request" — a body
     * on its way in, which has no node yet.
     *
     * @param resource|string $text As {@see Reader} takes it
     */
    public static function in(mixed $text): ?Component
    {
        $object = null;

        try {
            foreach ((new Reader($text))->objects() as $read) {
                if ($object !== null) {
                    // A second one is read far enough to know that it is there
                    // and no further: whatever else the file holds, it is not
                    // one calendar object.
                    return null;
                }

                $object = $read;
            }
        } catch (ParseError) {
            return null;
        }

        // **Compared without regard to case**, because RFC 5234 §2.3 says what
        // the quotes in §3.4's `"BEGIN" ":" "VCALENDAR"` mean: "ABNF strings
        // are case insensitive". RFC 5545 §3.1 says the same of the names
        // inside, and {@see \DavServices\VObject\Component} already looks them
        // up that way — a reader that answered only to the shouted spelling
        // would refuse legal iCalendar as invalid.
        if ($object === null || strcasecmp($object->name(), self::VCALENDAR) !== 0) {
            return null;
        }

        return $object;
    }

    /**
     * The kinds of component in the object, each once, in the order they first
     * appear.
     *
     * `VTIMEZONE` is left out where anything else is there, for the reason
     * §5.2.3 gives — and is the answer where it is all there is.
     *
     * @return list<string>
     */
    public static function kindsIn(Component $object): array
    {
        $kinds = [];

        foreach ($object->components() as $component) {
            $name = strtoupper($component->name());

            if (!in_array($name, $kinds, true)) {
                $kinds[] = $name;
            }
        }

        $besideTheZones = array_values(array_filter(
            $kinds,
            static fn (string $kind): bool => $kind !== self::VTIMEZONE,
        ));

        return $besideTheZones === [] ? $kinds : $besideTheZones;
    }
    /**
     * Holds an object to everything §4.1 asks of a calendar object resource.
     *
     * Four sentences of that section, each refused with
     * `CALDAV:valid-calendar-object-resource` because §5.3.2.1 says the
     * precondition covers "all restrictions specified in Section 4.1".
     *
     * **What is not here is the uniqueness of the `UID`**, which §4.1 asks for
     * "in the scope of the calendar collection in which they are stored" — a
     * question about the collection, answered by the layer that can see one.
     *
     * @throws Forbidden If the object is not one resource
     */
    public static function mustBeOneResource(Component $object): void
    {
        self::mustSpecifyNoMethod($object);
        self::mustHoldOneKind($object);
        self::mustHoldOneUid($object);
        self::mustCarryEveryZoneItNames($object);
    }

    /**
     * §4.1: "Calendar object resources contained in calendar collections MUST
     * NOT specify the iCalendar METHOD property."
     *
     * The property belongs to iTIP, where an object is a message between
     * calendar users — RFC 5546 §1.4 — rather than something a collection
     * keeps. §5.3.2.1 names it as one of its two examples.
     *
     * @throws Forbidden If it specifies one
     */
    private static function mustSpecifyNoMethod(Component $object): void
    {
        if ($object->property(self::METHOD) === null) {
            return;
        }

        throw new Forbidden(
            'A calendar object resource specifies no METHOD property (RFC 4791 §4.1): the CALDAV:valid-calendar-object-resource precondition of §5.3.2.1.',
            self::VALID_OBJECT,
        );
    }

    /**
     * §4.1: "MUST NOT contain more than one type of calendar component (e.g.,
     * VEVENT, VTODO, VJOURNAL, VFREEBUSY, etc.) with the exception of
     * VTIMEZONE components".
     *
     * {@see kindsIn()} has the exception in it already, so one kind coming
     * back is one kind in the sense the sentence means.
     *
     * @throws Forbidden If it holds more than one
     */
    private static function mustHoldOneKind(Component $object): void
    {
        $kinds = self::kindsIn($object);

        if (count($kinds) <= 1) {
            return;
        }

        throw new Forbidden(
            sprintf(
                'A calendar object resource holds one type of component, not %s (RFC 4791 §4.1): '
                    . 'the CALDAV:valid-calendar-object-resource precondition of §5.3.2.1.',
                implode(' and ', $kinds),
            ),
            self::VALID_OBJECT,
        );
    }

    /**
     * §4.1, both halves of one rule: "Calendar components in a calendar
     * collection that have different UID property values MUST be stored in
     * separate calendar object resources. Calendar components with the same
     * UID property value, in a given calendar collection, MUST be contained in
     * the same calendar object resource."
     *
     * So a resource carries exactly one `UID`, which is what R-CAL-07 asks
     * for. **Not none either**: RFC 5545 §3.6.1 lists `uid` among the
     * properties that "are REQUIRED, but MUST NOT occur more than once", and
     * the rule above has nothing to hold on a component without one.
     *
     * **A resource of nothing but zones has no `UID` to carry**, because a
     * `VTIMEZONE` has none — §3.6.5 gives it a `TZID` instead — and §5.2.3
     * licenses such a resource.
     *
     * @throws Forbidden If it holds more than one `UID`, or a component
     *                   without one
     */
    private static function mustHoldOneUid(Component $object): void
    {
        $uids = [];

        foreach ($object->components() as $component) {
            if (strcasecmp($component->name(), self::VTIMEZONE) === 0) {
                continue;
            }

            $uid = $component->property('UID')?->value() ?? '';

            if ($uid === '') {
                throw new Forbidden(
                    sprintf(
                        'A %s in a calendar carries a UID (RFC 5545 §3.6.1, RFC 4791 §4.1): '
                            . 'the CALDAV:valid-calendar-object-resource precondition of §5.3.2.1.',
                        strtoupper($component->name()),
                    ),
                    self::VALID_OBJECT,
                );
            }

            if (!in_array($uid, $uids, true)) {
                $uids[] = $uid;
            }
        }

        if (count($uids) <= 1) {
            return;
        }

        throw new Forbidden(
            'Components with different UIDs belong in separate calendar object resources (RFC 4791 §4.1): the CALDAV:valid-calendar-object-resource precondition of §5.3.2.1.',
            self::VALID_OBJECT,
        );
    }

    /**
     * §4.1's exception has a duty in it: `VTIMEZONE` components "MUST be
     * specified for each unique TZID parameter value specified in the
     * iCalendar object". RFC 5545 §3.2.19 says the same sentence, and adds
     * what the two values have to do with each other: "The value of the 'TZID'
     * property parameter will be equal to the value of the 'TZID' property for
     * the matching time zone definition."
     *
     * **A solidus is no exemption.** §3.2.19 reads "The presence of the SOLIDUS
     * character as a prefix, indicates that this 'TZID' represents a unique ID
     * in a globally defined time zone registry (when such registry is
     * defined)" — and the parenthesis is the point, because none of the memos
     * here defines one. The grammar puts the prefix inside the parameter value
     * (`tzidparam = "TZID" "=" [tzidprefix] paramtext`), so a prefixed
     * identifier is one of the "unique TZID parameter value[s]" the sentence
     * asks for a component for.
     *
     * **The comparison is exact.** RFC 5545 §3.1 makes names case-insensitive
     * and leaves values alone — "Property values are case sensitive unless
     * otherwise stated" — and both of these are values.
     *
     * @throws Forbidden If the object names a zone it does not carry
     */
    private static function mustCarryEveryZoneItNames(Component $object): void
    {
        $carried = [];

        foreach ($object->components(self::VTIMEZONE) as $zone) {
            $carried[] = $zone->property(self::TZID)?->value() ?? '';
        }

        foreach (self::zonesNamedIn($object) as $named) {
            if (!in_array($named, $carried, true)) {
                throw new Forbidden(
                    sprintf(
                        'The object names the time zone "%s" without carrying it (RFC 4791 §4.1): '
                            . 'the CALDAV:valid-calendar-object-resource precondition of §5.3.2.1.',
                        $named,
                    ),
                    self::VALID_OBJECT,
                );
            }
        }
    }

    /**
     * Every zone a `TZID` parameter names, anywhere in the object.
     *
     * **Anywhere**, because §4.1 says "specified in the iCalendar object"
     * rather than naming the properties: a `VALARM`'s `TRIGGER` carries one as
     * readily as a `DTSTART` does, and it sits a component deeper.
     *
     * @return list<string>
     */
    private static function zonesNamedIn(Component $component): array
    {
        $named = [];

        foreach ($component->properties() as $property) {
            $zone = $property->parameter(self::TZID)?->value();

            if ($zone !== null && $zone !== '' && !in_array($zone, $named, true)) {
                $named[] = $zone;
            }
        }

        foreach ($component->components() as $inside) {
            foreach (self::zonesNamedIn($inside) as $zone) {
                if (!in_array($zone, $named, true)) {
                    $named[] = $zone;
                }
            }
        }

        return $named;
    }
}
