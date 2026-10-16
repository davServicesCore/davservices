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
 * **What §4.1 settles beyond this belongs to the chunk that validates a
 * `PUT`**: one type of component only, no `METHOD` property, one `UID` per
 * resource, and every component of a recurrence set in the same resource. This
 * class answers the part the limits need — the object, and which kinds of
 * component it holds.
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
}
