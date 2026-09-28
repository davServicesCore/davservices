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

/**
 * Says what is wrong with an object, and where (R-VOBJ-04).
 *
 *     foreach ((new Validator())->check($calendar) as $finding) {
 *         printf("%s %s: %s\n", $finding->severity()->value, $finding->where(), $finding->message());
 *     }
 *
 * **This is where the debts of the reading chunks come due.** Reading an
 * object and judging it are two different jobs, and P4-01, P4-02b, P4-03 and
 * P4-04 each said so where they left a rule alone: a reader that refused what
 * it could still read would take the evidence away from whoever has to repair
 * the file.
 *
 * **Which rules apply is decided by the outermost name.** The reader could not
 * tell an iCalendar file from a vCard one — RFC 5545 §3.4 names `VCALENDAR`
 * and RFC 6350 §6.1.1 names `VCARD`, and a reader serving both cannot hold
 * both rules at once. A validator sees the whole object, so here it can.
 *
 * **Everything wrong is reported, not the first thing.** Somebody repairing a
 * file wants the list; a validator that stopped at the first fault would have
 * them come back once per fault.
 *
 * ## What is not checked here, and why
 *
 * `BEGIN-param = 0" "` (RFC 6350 §6.1.1) cannot be checked from an object at
 * all: the reader turns `BEGIN` and `END` into structure, so whatever
 * parameters they carried are no longer there to look at. That rule belongs
 * to the reader, and is written down in the progress file rather than quietly
 * dropped.
 */
final class Validator
{
    private const CALENDAR = 'VCALENDAR';

    private const CARD = 'VCARD';

    /**
     * RFC 5545 §3.1: `name = 1*(ALPHA / DIGIT / "-")`, which `param-name` is
     * built from as well.
     */
    private const NAME = '/^[A-Za-z0-9-]+$/';

    /**
     * Everything wrong with this object.
     *
     * @return list<Finding>
     */
    public function check(Component $object): array
    {
        $findings = self::structureOf($object, $object->name());

        if ($object->name() === self::CALENDAR) {
            return [...$findings, ...self::calendarRules($object)];
        }

        if ($object->name() === self::CARD) {
            return [...$findings, ...self::cardRules($object)];
        }

        return [
            new Finding(
                Severity::Error,
                $object->name(),
                sprintf(
                    'An object is a VCALENDAR (RFC 5545 §3.4) or a VCARD (RFC 6350 §6.1.1), not a "%s".',
                    $object->name(),
                ),
            ),
            ...$findings,
        ];
    }

    /**
     * The rules that hold whatever the object is: every component holds
     * something, and every name is a name.
     *
     * @return list<Finding>
     */
    private static function structureOf(Component $component, string $where): array
    {
        $findings = [];

        if ($component->children() === []) {
            $findings[] = new Finding(
                Severity::Error,
                $where,
                'A component holds at least one content line (RFC 5545 §3.6: "1*contentline").',
            );
        }

        foreach ($component->children() as $child) {
            $findings = [
                ...$findings,
                ...($child instanceof Component
                    ? self::structureOf($child, $where . '/' . $child->name())
                    : self::namesOf($child, $where . '/' . $child->name())),
            ];
        }

        return $findings;
    }

    /**
     * RFC 5545 §3.1: a property name and a parameter name are both built from
     * `1*(ALPHA / DIGIT / "-")`. The lexer let anything through so that
     * somebody could be told where it is; this is where they are told.
     *
     * @return list<Finding>
     */
    private static function namesOf(Property $property, string $where): array
    {
        $findings = [];

        if (preg_match(self::NAME, $property->name()) !== 1) {
            $findings[] = new Finding(
                Severity::Error,
                $where,
                sprintf('"%s" is no name: RFC 5545 §3.1 has 1*(ALPHA / DIGIT / "-").', $property->name()),
            );
        }

        foreach ($property->parameters() as $parameter) {
            if (preg_match(self::NAME, $parameter->name()) === 1) {
                continue;
            }

            $findings[] = new Finding(
                Severity::Error,
                $where . ';' . $parameter->name(),
                sprintf(
                    '"%s" is no parameter name: RFC 5545 §3.1 has 1*(ALPHA / DIGIT / "-").',
                    $parameter->name(),
                ),
            );
        }

        return $findings;
    }

    /**
     * RFC 5545 §3.6: "An iCalendar object MUST include the 'PRODID' and
     * 'VERSION' calendar properties. In addition, it MUST include at least
     * one calendar component."
     *
     * A missing `PRODID` is an error rather than a repair, although §3.7.3
     * leaves the value open: a product identifier says who produced the file,
     * and putting this library's name on somebody else's work would be a lie
     * written into the file itself.
     *
     * @return list<Finding>
     */
    private static function calendarRules(Component $calendar): array
    {
        $findings = [
            ...self::exactlyOnce($calendar, 'PRODID', Severity::Error, self::CALENDAR),
            ...self::exactlyOnce($calendar, 'VERSION', Severity::Repair, self::CALENDAR),
        ];

        $version = $calendar->property('VERSION');

        // §3.7.4: "A value of '2.0' corresponds to this memo." A file saying
        // it needs another one is saying it needs something that is not here,
        // and reading past that would be reading it as what it says it is not.
        if ($version !== null && $version->value() !== '2.0') {
            $findings[] = new Finding(
                Severity::Error,
                'VCALENDAR/VERSION',
                sprintf('This library reads VERSION:2.0 (RFC 5545 §3.7.4), and this says "%s".', $version->value()),
            );
        }

        if ($calendar->components() === []) {
            $findings[] = new Finding(
                Severity::Error,
                self::CALENDAR,
                'A calendar MUST include at least one calendar component (RFC 5545 §3.6).',
            );
        }

        return $findings;
    }

    /**
     * RFC 6350 §6.2.1 and §6.7.9, and §3.3's grammar for what a vCard holds.
     *
     * @return list<Finding>
     */
    private static function cardRules(Component $card): array
    {
        $findings = [];

        if ($card->property('FN') === null) {
            $findings[] = new Finding(
                Severity::Error,
                'VCARD/FN',
                'A vCard MUST have an FN (RFC 6350 §6.2.1), and what it should say is nobody else to supply.',
            );
        }

        $findings = [...$findings, ...self::versionOf($card)];

        foreach ($card->components() as $component) {
            $findings[] = new Finding(
                Severity::Error,
                'VCARD/' . $component->name(),
                'A vCard holds content lines and no components (RFC 6350 §3.3).',
            );
        }

        return $findings;
    }

    /**
     * RFC 6350 §6.7.9: "This property MUST be present in the vCard object,
     * and it must appear immediately after BEGIN:VCARD."
     *
     * **And the same section says how hard to be about it**: "Note that
     * earlier versions of vCard allowed this property to be placed anywhere
     * in the vCard object, or even to be absent." A card without one is an
     * old card rather than a broken one — and which version it was written in
     * is exactly what nobody else can supply. Out of place, though, has one
     * right place to go.
     *
     * @return list<Finding>
     */
    private static function versionOf(Component $card): array
    {
        $version = $card->property('VERSION');

        if ($version === null) {
            return [new Finding(
                Severity::Warning,
                'VCARD/VERSION',
                'A vCard of this specification has a VERSION (RFC 6350 §6.7.9); earlier ones were allowed none.',
            )];
        }

        // "Immediately after BEGIN:VCARD" is the first child and nothing
        // else, so the one the card holds first is the one that is in place.
        if (($card->children()[0] ?? null) === $version) {
            return [];
        }

        return [new Finding(
            Severity::Repair,
            'VCARD/VERSION',
            'VERSION must appear immediately after BEGIN:VCARD (RFC 6350 §6.7.9).',
        )];
    }

    /**
     * A property a specification says MUST be there once.
     *
     * @return list<Finding>
     */
    private static function exactlyOnce(
        Component $component,
        string $name,
        Severity $whenMissing,
        string $where,
    ): array {
        $found = count($component->properties($name));

        if ($found === 1) {
            return [];
        }

        return [new Finding(
            $found === 0 ? $whenMissing : Severity::Error,
            $where . '/' . $name,
            sprintf('%s MUST be specified once, and is here %d times.', $name, $found),
        )];
    }
}
