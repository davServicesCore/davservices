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
     * RFC 5545 §3.1: `name = iana-token / x-name` and `param-name =
     * iana-token / x-name`, and both of those are built from
     * `1*(ALPHA / DIGIT / "-")` — so the whole of that character set, and
     * nothing outside it, is a name.
     */
    private const NAME = '/^[A-Za-z0-9-]+$/';

    /**
     * RFC 5545 §3.6, component by component, as its grammar comments give
     * them. Three of the four kinds of rule are worth a finding and the
     * fourth is silence:
     *
     * - `required` — "REQUIRED, but MUST NOT occur more than once"
     * - `once` — "OPTIONAL, but MUST NOT occur more than once"
     * - `preferablyOnce` — "OPTIONAL, but SHOULD NOT occur more than once"
     *
     * **A property on none of these lists may appear as often as it likes**,
     * because every one of §3.6's lists ends in `x-prop / iana-prop`, so any
     * name at all matches the component's grammar. Where a property may
     * stand is written in the property's own Conformance clause rather than
     * here — §3.8.2.2 says `DTEND` belongs to a `VEVENT` or a `VFREEBUSY` —
     * and reading all forty of those is a chunk of its own.
     *
     * **The lists differ from component to component**, which is why each
     * has its own: `CONTACT` may appear as often as it likes in a `VEVENT`
     * and once in a `VFREEBUSY`, and `DESCRIPTION` the other way about
     * between a `VEVENT` and a `VJOURNAL`.
     *
     * **`VCALENDAR` is not here**, although `calprops` is a table of the same
     * shape: its `VERSION` is a repair when missing rather than an error, and
     * this table knows only errors. {@see self::calendarRules()} holds that
     * one, and the two entries of `calprops` that belong here — "calscale /
     * method — OPTIONAL, but MUST NOT occur more than once" — are checked
     * there beside it.
     *
     * @var array<string, array{required: list<string>, once: list<string>, preferablyOnce: list<string>}>
     */
    private const COMPONENTS = [
        'VEVENT' => [
            'required' => ['DTSTAMP', 'UID'],
            'once' => [
                'CLASS', 'CREATED', 'DESCRIPTION', 'DTEND', 'DTSTART', 'DURATION', 'GEO',
                'LAST-MODIFIED', 'LOCATION', 'ORGANIZER', 'PRIORITY', 'RECURRENCE-ID',
                'SEQUENCE', 'STATUS', 'SUMMARY', 'TRANSP', 'URL',
            ],
            'preferablyOnce' => ['RRULE'],
        ],
        'VTODO' => [
            'required' => ['DTSTAMP', 'UID'],
            'once' => [
                'CLASS', 'COMPLETED', 'CREATED', 'DESCRIPTION', 'DTSTART', 'DUE', 'DURATION',
                'GEO', 'LAST-MODIFIED', 'LOCATION', 'ORGANIZER', 'PERCENT-COMPLETE',
                'PRIORITY', 'RECURRENCE-ID', 'SEQUENCE', 'STATUS', 'SUMMARY', 'URL',
            ],
            'preferablyOnce' => ['RRULE'],
        ],
        'VJOURNAL' => [
            'required' => ['DTSTAMP', 'UID'],
            'once' => [
                'CLASS', 'CREATED', 'DTSTART', 'LAST-MODIFIED', 'ORGANIZER',
                'RECURRENCE-ID', 'SEQUENCE', 'STATUS', 'SUMMARY', 'URL',
            ],
            'preferablyOnce' => ['RRULE'],
        ],
        'VFREEBUSY' => [
            'required' => ['DTSTAMP', 'UID'],
            'once' => ['CONTACT', 'DTEND', 'DTSTART', 'ORGANIZER', 'URL'],
            'preferablyOnce' => [],
        ],
        'VTIMEZONE' => [
            'required' => ['TZID'],
            'once' => ['LAST-MODIFIED', 'TZURL'],
            'preferablyOnce' => [],
        ],
        'STANDARD' => [
            'required' => ['DTSTART', 'TZOFFSETTO', 'TZOFFSETFROM'],
            'once' => [],
            'preferablyOnce' => ['RRULE'],
        ],
        'DAYLIGHT' => [
            'required' => ['DTSTART', 'TZOFFSETTO', 'TZOFFSETFROM'],
            'once' => [],
            'preferablyOnce' => ['RRULE'],
        ],
    ];

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
        //
        // **But the version may be written as a range**: `vervalue = "2.0" /
        // maxver / (minver ";" maxver)`, so `2.0;2.0` says the same thing the
        // long way round, and a range naming 2.0 is one this library is
        // inside. The ends are IANA-registered identifiers rather than
        // numbers, and 2.0 is the only one registered, so there is nothing to
        // compare for order.
        if ($version !== null && !in_array('2.0', explode(';', $version->value()), true)) {
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

        // The rest of `calprops`: "calscale / method — OPTIONAL, but MUST NOT
        // occur more than once."
        foreach (['CALSCALE', 'METHOD'] as $name) {
            $findings = [...$findings, ...self::atMostOnce($calendar, $name, Severity::Error, self::CALENDAR)];
        }

        // "The following is REQUIRED if the component appears in an iCalendar
        // object that doesn't specify the 'METHOD' property" — the one rule of
        // §3.6 that depends on the object around the component, which is why
        // it can be checked here at all.
        $namesAMethod = $calendar->property('METHOD') !== null;

        foreach ($calendar->components() as $component) {
            $findings = [
                ...$findings,
                ...self::componentRules($component, self::CALENDAR . '/' . $component->name(), $namesAMethod),
            ];
        }

        return $findings;
    }

    /**
     * What §3.6 asks of one calendar component, and of everything inside it.
     *
     * A component this memo has never heard of is left alone, which §3.6 says
     * in prose as well: "Applications MUST ignore x-comp and iana-comp values
     * they don't recognize."
     *
     * @return list<Finding>
     */
    private static function componentRules(Component $component, string $where, bool $namesAMethod): array
    {
        $rules = self::COMPONENTS[$component->name()] ?? null;
        $findings = $rules === null ? [] : [
            ...self::countsIn($component, $rules, $where),
            ...self::extraRulesOf($component, $where, $namesAMethod),
        ];

        foreach ($component->components() as $inside) {
            $findings = [
                ...$findings,
                ...self::componentRules($inside, $where . '/' . $inside->name(), $namesAMethod),
            ];
        }

        return $findings;
    }

    /**
     * The three kinds of counting rule, in the order the grammar lists them.
     *
     * @param array{required: list<string>, once: list<string>, preferablyOnce: list<string>} $rules
     *
     * @return list<Finding>
     */
    private static function countsIn(Component $component, array $rules, string $where): array
    {
        $findings = [];

        foreach ($rules['required'] as $name) {
            $findings = [...$findings, ...self::exactlyOnce($component, $name, Severity::Error, $where)];
        }

        foreach ($rules['once'] as $name) {
            $findings = [...$findings, ...self::atMostOnce($component, $name, Severity::Error, $where)];
        }

        foreach ($rules['preferablyOnce'] as $name) {
            $findings = [...$findings, ...self::atMostOnce($component, $name, Severity::Warning, $where)];
        }

        return $findings;
    }

    /**
     * The rules §3.6 writes out in prose beside a component's lists.
     *
     * **Each one is a named predicate and there is one finding made**, for the
     * reason P4-07 measured: sequential independent branches multiply into
     * paths, and five copies of `if (…) { $findings[] = … }` would say the
     * same thing five times while hiding which of the five a reader is looking
     * at.
     *
     * @return list<Finding>
     */
    private static function extraRulesOf(Component $component, string $where, bool $namesAMethod): array
    {
        $findings = [];

        foreach (self::whatWouldBeWrong($component, $namesAMethod) as [$wrong, $at, $because]) {
            if ($wrong) {
                $findings[] = new Finding(Severity::Error, $where . $at, $because);
            }
        }

        return $findings;
    }

    /**
     * What would be wrong with this component, where to say it, and what to
     * say.
     *
     * @return list<array{bool, string, string}>
     */
    private static function whatWouldBeWrong(Component $component, bool $namesAMethod): array
    {
        return [
            [
                self::anEventThatEndsTwice($component),
                '',
                'An event says when it ends with a DTEND or a DURATION, not both (RFC 5545 §3.6.1).',
            ],
            [
                self::aTodoThatEndsTwice($component),
                '',
                'A to-do says when it is due with a DUE or a DURATION, not both (RFC 5545 §3.6.2).',
            ],
            [
                self::aLengthWithNothingToMeasureFrom($component),
                '/DURATION',
                'A to-do with a DURATION MUST also have a DTSTART to measure it from (RFC 5545 §3.6.2).',
            ],
            [
                self::aTimeZoneWithNoTimes($component),
                '',
                'A time zone MUST have one STANDARD or DAYLIGHT at least (RFC 5545 §3.6.5).',
            ],
            [
                self::anEventWithNoStart($component, $namesAMethod),
                '/DTSTART',
                'An event in an object that names no METHOD MUST have a DTSTART (RFC 5545 §3.6.1).',
            ],
        ];
    }

    /**
     * §3.6.1: "Either 'dtend' or 'duration' MAY appear in a 'eventprop', but
     * 'dtend' and 'duration' MUST NOT occur in the same 'eventprop'."
     */
    private static function anEventThatEndsTwice(Component $component): bool
    {
        return $component->name() === 'VEVENT'
            && $component->property('DTEND') !== null
            && $component->property('DURATION') !== null;
    }

    /**
     * §3.6.2, in the same words with the other two names: "Either 'due' or
     * 'duration' MAY appear in a 'todoprop', but 'due' and 'duration' MUST
     * NOT occur in the same 'todoprop'."
     */
    private static function aTodoThatEndsTwice(Component $component): bool
    {
        return $component->name() === 'VTODO'
            && $component->property('DUE') !== null
            && $component->property('DURATION') !== null;
    }

    /**
     * §3.6.2, the rule an event has not: "If 'duration' appear in a
     * 'todoprop', then 'dtstart' MUST also appear in the same 'todoprop'."
     */
    private static function aLengthWithNothingToMeasureFrom(Component $component): bool
    {
        return $component->name() === 'VTODO'
            && $component->property('DURATION') !== null
            && $component->property('DTSTART') === null;
    }

    /**
     * §3.6.5: "One of 'standardc' or 'daylightc' MUST occur and each MAY
     * occur more than once."
     */
    private static function aTimeZoneWithNoTimes(Component $component): bool
    {
        return $component->name() === 'VTIMEZONE'
            && $component->components('STANDARD') === []
            && $component->components('DAYLIGHT') === [];
    }

    /**
     * §3.6.1: "The following is REQUIRED if the component appears in an
     * iCalendar object that doesn't specify the 'METHOD' property; otherwise,
     * it is OPTIONAL; in any case, it MUST NOT occur more than once."
     *
     * The cardinality is in the table; this is the half that needs the object.
     */
    private static function anEventWithNoStart(Component $component, bool $namesAMethod): bool
    {
        return $component->name() === 'VEVENT'
            && !$namesAMethod
            && $component->property('DTSTART') === null;
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
     * **The value is not judged here**, because §6.7.9 makes that conditional
     * on the very thing it would be judging: "The value MUST be '4.0' **if
     * the vCard corresponds to this specification**." A card saying `3.0` is
     * an RFC 2426 card, and telling it to be a 4.0 one would be telling it to
     * be a different document.
     *
     * @return list<Finding>
     */
    private static function versionOf(Component $card): array
    {
        $versions = $card->properties('VERSION');
        $version = $versions[0] ?? null;

        if ($version === null) {
            return [new Finding(
                Severity::Warning,
                'VCARD/VERSION',
                'A vCard of this specification has a VERSION (RFC 6350 §6.7.9); earlier ones were allowed none.',
            )];
        }

        // §6.7.9 gives VERSION a cardinality of 1, which §6.1 reads as
        // "Exactly one instance per vCard MUST be present".
        if (count($versions) > 1) {
            return [new Finding(
                Severity::Error,
                'VCARD/VERSION',
                sprintf('VERSION MUST be present exactly once (RFC 6350 §6.7.9), and is here %d times.', count($versions)),
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
     * A property a specification allows at most once.
     *
     * The severity carries whether the specification said MUST NOT or SHOULD
     * NOT, which is what {@see Severity} is for, so one message serves both.
     *
     * @return list<Finding>
     */
    private static function atMostOnce(
        Component $component,
        string $name,
        Severity $whenRepeated,
        string $where,
    ): array {
        $found = count($component->properties($name));

        if ($found < 2) {
            return [];
        }

        return [new Finding(
            $whenRepeated,
            $where . '/' . $name,
            sprintf('%s is specified at most once, and is here %d times.', $name, $found),
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
