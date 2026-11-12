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

namespace DavServices\CalDav\Schedule;

use DavServices\VObject\Component;
use DavServices\VObject\Parameter;
use DavServices\VObject\ParseError;
use DavServices\VObject\Property;
use DavServices\VObject\Recur\ObjectSet;
use DavServices\VObject\Recur\TooManyIterations;

/**
 * The answers a reschedule takes back (RFC 6638 §3.2.8).
 *
 * > A reschedule occurs when any "DTSTART", "DTEND", "DURATION", "DUE",
 * > "RRULE", "RDATE", or "EXDATE" property changes in a calendar component
 * > such that existing recurrence instances are impacted by the changes, as
 * > shown in the table below. Servers MUST reset the "PARTSTAT" property
 * > parameter value of all "ATTENDEE" properties, except the one that
 * > corresponds to the "Organizer", to "NEEDS-ACTION" for each calendar
 * > component change that causes any instance to be rescheduled.
 *
 * ## The table is two questions, not four
 *
 * > DTSTART, DTEND, DURATION, DUE: Any change to these properties results in
 * > "PARTSTAT" being set to "NEEDS-ACTION".
 *
 * **Any change**, with nothing asked about what it does to a recurrence set —
 * so a `DTEND` that moves counts although it generates no instance at all.
 * That row is answered by comparing a component with its own earlier
 * revision, and by nothing else.
 *
 * The other three rows each name a condition, and the three conditions are one
 * question:
 *
 * > RRULE: A change to or addition of this property that results in the
 * > addition of new recurring instances or a change in time for existing
 * > recurring instances results in "PARTSTAT" being reset to "NEEDS-ACTION" on
 * > each affected component.
 *
 * > RDATE: [the same sentence]
 *
 * > EXDATE: A change to or removal of this property that results in the
 * > reinstatement of recurring instances results in "PARTSTAT" being set to
 * > "NEEDS-ACTION" on each affected component.
 *
 * An instance added, an instance whose time changed, an instance reinstated:
 * **each of the three is an identifier the expanded set answers for now and
 * did not answer for before, or answered for at another time.** RFC 5546
 * §2.1.5 makes `UID` and `RECURRENCE-ID` "the primary key for referencing" an
 * instance, so that is the pairing the comparison uses, and
 * {@see ObjectSet::instances()} is what knows the answer — only an expansion
 * can say what a rule generates.
 *
 * **An instance merely dropped is in none of the three rows**, and so takes
 * nobody's answer back: an attendee who accepted a series accepted the
 * instances that remain of it. Shortening a series with an `UNTIL` is the
 * common case of that, and the three rows pass over it in three different
 * wordings — "addition", "change in time", "reinstatement".
 *
 * ## Which component is the affected one
 *
 * "On each affected component" is answered by the expansion as well, because
 * {@see \DavServices\VObject\Recur\Instance::component()} is the component
 * that governs an instance — the master where the series answers for it, the
 * overriding component where one does. So an instance reinstated under an
 * override resets that override, an instance added by a rule resets the
 * master, and an attendee on an instance nothing touched keeps their answer.
 *
 * ## Why the set is compared rather than the properties that define it
 *
 * The three rows could be read as a comparison of `RRULE`, `RDATE` and
 * `EXDATE` alone, and that reading **misses a case the sentence above the
 * table catches**: a component overriding one instance with
 * `RANGE=THISANDFUTURE` moves "this and all subsequent recurrence instances"
 * (RFC 5545 §3.2.13), and adding one changes no property of the master at all.
 * The MUST does not speak of those three properties only — it asks whether
 * "existing recurrence instances are impacted by the changes". Two expanded
 * sets answer that question whatever was written to bring it about, so that is
 * what is compared.
 *
 * A `DTSTART` that moves on the master lands in the same comparison: every
 * identifier the rule generates moves with it. The first row would have caught
 * it too, which is no reason to ask twice and no harm when both agree.
 *
 * ## Two attendees this does not touch
 *
 * > This section specifies additional requirements on the handling of the
 * > "PARTSTAT" property parameter when the "SCHEDULE-AGENT" property parameter
 * > on the corresponding "ATTENDEE" property is set to the value "SERVER" or
 * > is not present.
 *
 * The section's own first sentence, which is a condition on every sentence
 * after it: an attendee whose `SCHEDULE-AGENT` is `CLIENT` or `NONE` is
 * somebody else's to invite (§7.1), so their answer is not this server's to
 * take back. And "except the one that corresponds to the 'Organizer'" leaves
 * the organizer's own participation where it stands — the other side of
 * §3.2.1's MUST NOT, which keeps an organizer from answering for anybody but
 * themselves. **Corresponding is a calendar user and not a spelling**, so the
 * caller says which addresses are the organizer's; §1.2 has "'Organizer'
 * refers to a role of a calendar user", and one calendar user may hold several
 * addresses (§2.4.1).
 *
 * ## Where the doubt is resolved
 *
 * A series this library cannot finish expanding — `FREQ=YEARLY;BYMONTH=2;
 * BYMONTHDAY=30` names a day that never comes — cannot be compared, and then
 * **the doubt is resolved towards taking the answer back**, but only where
 * something that defines the set was in fact written differently. §3.2.8's
 * last sentence is what makes that the safe direction:
 *
 * > The server MAY allow the "Organizer's" client to change an "Attendee's"
 * > "PARTSTAT" property parameter value to "NEEDS-ACTION" at any other time
 *
 * A `NEEDS-ACTION` too many is a value the section admits at any time, and an
 * answer left standing over an instance that was in fact added breaks a MUST.
 * **The "only where" is what keeps that from being a reset on every save**: a
 * resource whose series cannot be expanded would otherwise lose every answer
 * each time anything about it was stored. {@see \DavServices\CalDav\Limits}
 * refuses to keep such a resource at all where it is configured, so this is
 * the case that remains when it is not.
 */
final class Reschedule
{
    private const ATTENDEE = 'ATTENDEE';

    private const PARTSTAT = 'PARTSTAT';

    private const NEEDS_ACTION = 'NEEDS-ACTION';

    private const RECURRENCE_ID = 'RECURRENCE-ID';

    private const UID = 'UID';

    /**
     * The four properties of the table's first row, which asks only that one
     * of them changed.
     */
    private const TIMES = ['DTSTART', 'DTEND', 'DURATION', 'DUE'];

    /**
     * Everything that can put an instance at a time: which instance a
     * component overrides, when it starts, and the three properties that
     * define a recurrence set.
     *
     * Read only where the set cannot be expanded, and read of every component
     * rather than of the master alone — an override arriving or leaving takes
     * the time of its instance with it. Each of the three may appear more than
     * once in a component, RFC 5545 §3.8.5.1 and §3.8.5.2 both saying so of
     * their own.
     */
    private const SHAPE = ['RECURRENCE-ID', 'DTSTART', 'RRULE', 'RDATE', 'EXDATE'];

    /**
     * Resets, on the revision about to be stored, every answer a reschedule
     * takes back.
     *
     * @param Component|null $original The `VCALENDAR` as it stands, or null
     *                                 where there is none — §3.2.1.2's
     *                                 `<Absent>`, where no instance can have
     *                                 been impacted because none existed
     * @param Component $modified The `VCALENDAR` that is about to replace it,
     *                            **changed in place**
     * @param list<string> $addresses The organizer's own addresses, whose
     *                                answers are theirs to keep
     *
     * @return bool Whether any answer was taken back, so that the caller knows
     *              whether what it holds still is what it was given
     */
    public static function appliedTo(?Component $original, Component $modified, array $addresses): bool
    {
        if ($original === null) {
            return false;
        }

        $written = false;

        foreach (self::affectedIn($original, $modified) as $component) {
            if (self::resetOn($component, $addresses)) {
                $written = true;
            }
        }

        return $written;
    }

    /**
     * The components of the modification a reschedule reached, each once.
     *
     * Kept apart by object rather than by name or identifier: the master is
     * one component however many of its instances moved, and a reset applied
     * twice would be no error but would read as though it were meant.
     *
     * @return list<Component>
     */
    private static function affectedIn(Component $original, Component $modified): array
    {
        $affected = [];

        foreach (self::whereATimeChanged($original, $modified) as $component) {
            $affected[spl_object_id($component)] = $component;
        }

        foreach (self::whereTheSetMoved($original, $modified) as $component) {
            $affected[spl_object_id($component)] = $component;
        }

        return array_values($affected);
    }

    /**
     * The table's first row: the components one of the four changed in.
     *
     * Each component of the modification against **its own** earlier revision,
     * the master against the master and an overriding component against the
     * one that overrode the same instance. A component that is new has no
     * earlier revision for a property to have changed in, and what its arrival
     * did to the recurrence set is the other question — the master's `DTSTART`
     * is the series' own start and not the previous start of the instance an
     * override names.
     *
     * @return list<Component>
     */
    private static function whereATimeChanged(Component $original, Component $modified): array
    {
        $affected = [];

        foreach (Instances::of($modified) as $component) {
            $named = $component->property(self::RECURRENCE_ID);
            $was = $named === null
                ? Instances::theMasterOf($original)
                : Instances::theOverrideOf($original, $named->value());

            if ($was !== null && self::differ($was, $component, self::TIMES)) {
                $affected[] = $component;
            }
        }

        return $affected;
    }

    /**
     * The other three rows: the components of the instances the set now holds
     * at a time it did not hold them at before.
     *
     * @return list<Component>
     */
    private static function whereTheSetMoved(Component $original, Component $modified): array
    {
        $master = Instances::theMasterOf($modified);
        $uid = self::theUidIn($modified);
        $was = $uid === null ? null : self::setOf($original, $uid);
        $is = $uid === null ? null : self::setOf($modified, $uid);

        if ($was === null || $is === null) {
            if ($master === null || self::theShapeOf($original) === self::theShapeOf($modified)) {
                return [];
            }

            return [$master];
        }

        $affected = [];

        foreach ($is as $identifier => [$start, $component]) {
            if (($was[$identifier][0] ?? null) !== $start) {
                $affected[] = $component;
            }
        }

        return $affected;
    }

    /**
     * Everything about the object that can put an instance at a time, in an
     * order of its own.
     *
     * **All that can be asked where the set itself cannot be had.** Two
     * objects of the same shape generate the same instances at the same times
     * whatever this can or cannot expand, so a resource stored again unchanged
     * costs nobody their answer — which is what keeps the doubt above from
     * being resolved on every save.
     *
     * The components are put in an order of their own because RFC 5545 §3.6
     * imposes none on them: which of two overriding components is written
     * first says nothing about the series they belong to.
     *
     * @return list<list<list<array{string, array<string, list<string>>}>>>
     */
    private static function theShapeOf(Component $object): array
    {
        $shape = [];

        foreach (Instances::of($object) as $component) {
            $written = [];

            foreach (self::SHAPE as $name) {
                $written[] = self::written($component, $name);
            }

            $shape[] = $written;
        }

        sort($shape);

        return $shape;
    }

    /**
     * What the series comes to, by identifier, or null where it cannot be
     * expanded.
     *
     * The limit is the one R-CAL-08 fixes for every expansion here, inherited
     * rather than chosen again: a series longer than that is one
     * {@see \DavServices\CalDav\Limits} will not keep.
     *
     * @return array<string, array{string, Component}>|null
     */
    private static function setOf(Component $object, string $uid): ?array
    {
        $instances = [];

        try {
            foreach (ObjectSet::of($object, $uid)->instances() as $instance) {
                $instances[$instance->recurrenceId()->encode()] = [
                    $instance->start()->encode(),
                    $instance->component(),
                ];
            }
        } catch (ParseError | TooManyIterations) {
            return null;
        }

        return $instances;
    }

    /**
     * The `UID` the object's components carry, or null where none does.
     *
     * The first one found, RFC 4791 §4.1 making that the resource's: "a
     * calendar object resource MUST only contain one UID".
     */
    private static function theUidIn(Component $object): ?string
    {
        foreach (Instances::of($object) as $component) {
            $uid = $component->property(self::UID)?->value();

            if ($uid !== null) {
                return $uid;
            }
        }

        return null;
    }

    /**
     * Whether any of those properties is written differently on the two
     * revisions of a component.
     *
     * **As written, parameters and all.** RFC 5545 §3.8.2.4 has a `DTSTART`
     * mean one moment only together with its `TZID`, so a value compared
     * without its parameters would miss a meeting moved from one zone to
     * another at the same wall clock. Their order is not compared, nothing in
     * §3.1's grammar making it mean anything — the reading
     * {@see Property::offsetSet()} already keeps to.
     *
     * Where a property appears more than once, all of them are compared and
     * their order is not: RFC 5545 §3.8.5.1 lets the exclusions of one series
     * be written as several `EXDATE` properties, and which of them comes first
     * says nothing about the recurrence set they describe.
     *
     * @param list<string> $names
     */
    private static function differ(Component $was, Component $is, array $names): bool
    {
        foreach ($names as $name) {
            if (self::written($was, $name) !== self::written($is, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every property of that name as its value and its parameters, in an
     * order of its own.
     *
     * The parameters are kept by name rather than written into the value,
     * because a property is a value **and** what qualifies it, and a text with
     * both in it would need a separator that could not appear in either.
     *
     * **The names are compared without regard to case**, RFC 5545 §3.5 having
     * "Property names, parameter names, and enumerated parameter values are
     * case-insensitive" — a `tzid` respelled `TZID` is the same zone. Their
     * values are not: a `TZID` names a time zone, and §3.1 makes that
     * case-sensitive.
     *
     * @return list<array{string, array<string, list<string>>}>
     */
    private static function written(Component $component, string $name): array
    {
        $written = [];

        foreach ($component->properties($name) as $property) {
            $parameters = [];

            foreach ($property->parameters() as $parameter) {
                $parameters[strtoupper($parameter->name())] = $parameter->values();
            }

            ksort($parameters);
            $written[] = [$property->value(), $parameters];
        }

        sort($written);

        return $written;
    }

    /**
     * Takes back every answer on that component that is this server's to take
     * back, and says whether it took any.
     *
     * **Which answers are the organizer's own is the caller's to say**, for
     * the reason {@see Modification::whoseAnswerItSets()} gives of the same
     * question: §1.2 has "'Organizer' refers to a role of a calendar user",
     * and which addresses are one calendar user is a question only a directory
     * can answer. Reading the component's own `ORGANIZER` value instead would
     * leave the role and keep to the spelling, and an organizer who attends
     * their own meeting under another of their addresses would have the answer
     * §3.2.8 excepts taken back.
     *
     * @param list<string> $addresses The organizer's own addresses
     */
    private static function resetOn(Component $component, array $addresses): bool
    {
        $written = false;

        foreach ($component->properties(self::ATTENDEE) as $attendee) {
            // Compared as written, for the reason {@see SchedulingObject}
            // gives: RFC 5545 §3.1 makes a property value case-sensitive, and
            // whether two spellings name one calendar user is a question only
            // a directory can answer.
            if (in_array($attendee->value(), $addresses, true)) {
                continue;
            }

            if (ScheduleAgent::on($attendee) !== ScheduleAgent::Server) {
                continue;
            }

            $answer = $attendee->parameter(self::PARTSTAT)?->value();

            // An answer that is already `NEEDS-ACTION`, and one that was never
            // given — RFC 5545 §3.2.12 making that the default — are both
            // nothing to reset. Writing the default out would be a change to
            // the resource that says nothing, and it would have the caller
            // store the object again for it.
            if ($answer === null || strcasecmp($answer, self::NEEDS_ACTION) === 0) {
                continue;
            }

            $attendee[self::PARTSTAT] = new Parameter(self::PARTSTAT, [self::NEEDS_ACTION]);
            $written = true;
        }

        return $written;
    }
}
