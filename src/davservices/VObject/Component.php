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

namespace DavServices\VObject;

use ArrayAccess;
use ArrayIterator;
use IteratorAggregate;
use Traversable;

/**
 * A calendar component or a vCard: properties and components, in order.
 *
 * RFC 5545 §3.6: "The body of the iCalendar object consists of a sequence of
 * calendar properties and one or more calendar components. The calendar
 * properties are attributes that apply to the calendar object as a whole. The
 * calendar components are collections of properties that express a particular
 * calendar semantic."
 *
 *     $calendar = new Component('VCALENDAR');
 *     $calendar[] = new Property('VERSION', '2.0');
 *     $calendar[] = $event;
 *
 *     $calendar['VEVENT'];                     // the first event
 *     $calendar->components('VEVENT');         // all of them
 *     $event->property('SUMMARY')?->value();
 *
 * ## Any name at all
 *
 * The grammar of §3.6 ends on `iana-comp` and `x-comp`:
 *
 *     iana-comp = "BEGIN" ":" iana-token CRLF 1*contentline "END" ":" iana-token CRLF
 *
 * So a component may be called anything, and a model with a list of known
 * names would turn away `VAVAILABILITY` (RFC 7953) and whatever is registered
 * next. **Nothing here knows what a `VEVENT` is**; that belongs to CalDAV.
 *
 * ## Order is kept although nothing requires it
 *
 * RFC 5545 §3.5: "This memo imposes no ordering of properties within an
 * iCalendar object." The order carries no meaning — and it is kept all the
 * same, because a file that comes back with its lines shuffled is one
 * somebody has to read again to see that nothing changed. R-VOBJ-05 wants a
 * round trip, and a round trip that reorders is one people stop trusting.
 *
 * That is also why {@see self::offsetSet()} replaces **in place** rather than
 * taking one away and putting another at the end.
 *
 * ## Case
 *
 * RFC 5545 §3.5: "Property names, parameter names, and enumerated parameter
 * values are case-insensitive." Looking one up honours that; the spelling
 * that was written is kept, because it has to come back out again.
 *
 * ## Why it can be changed
 *
 * A calendar object is a document people edit: an attendee accepts, a summary
 * is corrected, an alarm is added. An immutable tree would mean rebuilding a
 * whole calendar to change one line — unlike the request and response of the
 * HTTP layer, which describe one moment and are then done with.
 *
 * @implements ArrayAccess<string, Property|Component>
 * @implements IteratorAggregate<int, Property|Component>
 */
final class Component implements ArrayAccess, IteratorAggregate
{
    /** @var list<Property|Component> */
    private array $children = [];

    public function __construct(private readonly string $name)
    {
    }

    /**
     * The component name, spelled as it was written.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Everything in it, properties and components alike, in order.
     *
     * @return list<Property|Component>
     */
    public function children(): array
    {
        return $this->children;
    }

    /**
     * Puts one more at the end.
     */
    public function add(Property|Component $child): void
    {
        $this->children[] = $child;
    }

    /**
     * One property by name, whatever its spelling, or null where there is
     * none of that name.
     */
    public function property(string $name): ?Property
    {
        return $this->properties($name)[0] ?? null;
    }

    /**
     * The properties of one name, or all of them where no name is given.
     *
     * A property may appear as often as it likes — an event has as many
     * `ATTENDEE` properties as it has attendees — so a caller that needs all
     * of them asks for all of them.
     *
     * @return list<Property>
     */
    public function properties(?string $name = null): array
    {
        $found = [];

        foreach ($this->children as $child) {
            if ($child instanceof Property && self::isCalled($child, $name)) {
                $found[] = $child;
            }
        }

        return $found;
    }

    /**
     * One component by name, whatever its spelling, or null.
     */
    public function component(string $name): ?Component
    {
        return $this->components($name)[0] ?? null;
    }

    /**
     * The components of one name, or all of them where no name is given.
     *
     * **Only the children, never the grandchildren.** A `VALARM` inside a
     * `VEVENT` is not a component of the calendar, and a search that reached
     * through would answer that a calendar has an alarm — which is not a
     * thing calendars have.
     *
     * @return list<Component>
     */
    public function components(?string $name = null): array
    {
        $found = [];

        foreach ($this->children as $child) {
            if ($child instanceof self && self::isCalled($child, $name)) {
                $found[] = $child;
            }
        }

        return $found;
    }

    /**
     * Walks everything in it, in order.
     *
     * A fresh walk every time, rather than a cursor on the component itself:
     * a calendar is walked while its events are walked inside it, which is
     * what every serialiser and every validator does.
     *
     * @return Traversable<int, Property|Component>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->children);
    }

    /**
     * Whether anything of that name is in it (R-VOBJ-07).
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->offsetGet($offset) !== null;
    }

    /**
     * The first child of that name — property or component, whichever it is
     * (R-VOBJ-07).
     *
     * **One rule, and no guessing.** A name belongs either to a property or
     * to a component and never to both, so asking for the first child called
     * `VEVENT` answers the event and asking for `SUMMARY` answers the
     * property, without this having to know which of the two somebody meant.
     *
     * @return Property|Component|null
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->firstCalled($offset);
    }

    /**
     * Puts a child where the first of that name stood, leaving no others of
     * that name — or adds it where there was none.
     *
     * **In place, not at the end**, so that a corrected property still stands
     * where somebody wrote it and a diff shows the one line that changed
     * (R-VOBJ-05). `$component[] = $child` appends instead, which is the same
     * thing {@see self::add()} says and reads better in a run of them.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->add($value);

            return;
        }

        $this->replace($offset, $value);
    }

    /**
     * Takes away every child of that name.
     *
     * Taking away a name nothing is called is not an error, the same as
     * everywhere else in PHP.
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->remove($offset);
    }

    private function firstCalled(string $name): Property|Component|null
    {
        foreach ($this->children as $child) {
            if (self::isCalled($child, $name)) {
                return $child;
            }
        }

        return null;
    }

    /**
     * **This is where a wrong type is turned away**, and PHP itself does the
     * turning: `ArrayAccess` takes `mixed` and an implementation may not
     * narrow that, so the narrowing happens one step in, where a native type
     * can state it. A caller writing `$calendar['SUMMARY'] = 'Lunch'` gets a
     * `TypeError` naming this method, rather than a calendar quietly holding
     * a string that breaks somewhere else entirely.
     */
    private function replace(string $name, Property|Component $value): void
    {
        $kept = [];
        $replaced = false;

        foreach ($this->children as $child) {
            if (!self::isCalled($child, $name)) {
                $kept[] = $child;

                continue;
            }

            if (!$replaced) {
                $kept[] = $value;
                $replaced = true;
            }
        }

        if (!$replaced) {
            $kept[] = $value;
        }

        $this->children = $kept;
    }

    private function remove(string $name): void
    {
        $kept = [];

        foreach ($this->children as $child) {
            if (!self::isCalled($child, $name)) {
                $kept[] = $child;
            }
        }

        $this->children = $kept;
    }

    /**
     * Whether a child answers to that name — or to any, where none is given.
     *
     * RFC 5545 §3.5 makes the comparison case-insensitive: "the property name
     * `DUE` is the same as `due` and `Due`".
     */
    private static function isCalled(Property|self $child, ?string $name): bool
    {
        return $name === null || strcasecmp($child->name(), $name) === 0;
    }
}
