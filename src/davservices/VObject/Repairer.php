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
 * Puts right what has exactly one right answer (R-VOBJ-04).
 *
 *     foreach ((new Repairer())->repair($calendar) as $put) {
 *         printf("%s: %s\n", $put->where(), $put->message());
 *     }
 *
 * **{@see Severity::Repair} was drawn for this.** {@see Validator} put the
 * line at whether the specification leaves one right answer, and that is
 * exactly the line a repairer can work along: where there is one answer it
 * can be given without anybody guessing, and where there is none nothing but
 * the author knows what was meant. So the whole rule is three lines long:
 *
 * - **`Repair` is put right.**
 * - **`Error` is left exactly as it stands** — a `PRODID` says who produced
 *   the file, and inventing one would write a lie into it.
 * - **`Warning` is left as it stands too** — a vCard with no `VERSION` is an
 *   older vCard (RFC 6350 §6.7.9: "or even to be absent"), and which one is
 *   not for a repairer to decide.
 *
 * **The object is repaired where it stands**, rather than copied and handed
 * back. {@see Component} says why: a calendar is a document people edit, and
 * rebuilding one to correct a single line would make every edit a copy of
 * everything around it.
 *
 * **Every repair that is made is handed back.** R-VOBJ-03: „Die Wahl der
 * Betriebsart hat unmittelbare Protokollfolgen" — and {@see Mode} names the
 * consequence, RFC 9110 §9.3.4, which a layer that was never told a repair
 * happened cannot honour.
 */
final class Repairer
{
    /**
     * RFC 5545 §3.7.4, as {@see Validator} names the place.
     */
    private const CALENDAR_VERSION = 'VCALENDAR/VERSION';

    /**
     * RFC 6350 §6.7.9, likewise.
     */
    private const CARD_VERSION = 'VCARD/VERSION';

    /**
     * Puts right everything the validator marks as a repair, and says what it
     * put right.
     *
     * @return list<Finding> What was repaired, in the order it was found
     */
    public function repair(Component $object): array
    {
        $repaired = [];

        foreach ((new Validator())->check($object) as $finding) {
            if ($finding->severity() !== Severity::Repair) {
                continue;
            }

            self::put($object, $finding->where());

            $repaired[] = $finding;
        }

        return $repaired;
    }

    /**
     * The one right answer for the place the finding names.
     *
     * **Every `Repair` the validator raises has an answer here**, because the
     * severity says one exists; a place with nothing to do would be the
     * library contradicting itself. What keeps that true is not a clause in
     * this method but a test: `RepairerTest` asks the validator again
     * afterwards and requires it to have nothing left to say, so a rule added
     * on one side and not the other fails as soon as it is written.
     */
    private static function put(Component $object, string $where): void
    {
        // §3.7.4: "A value of '2.0' corresponds to this memo."
        //
        // **Where it goes is a free choice**, and deliberately so: §3.5 says
        // "This memo imposes no ordering of properties within an iCalendar
        // object", so nothing here is owed. It goes at the front because
        // §3.4's own example puts it there, and because a `VERSION` appended
        // after the last `END` would look to everyone who opened the file
        // like it belonged to nothing.
        if ($where === self::CALENDAR_VERSION) {
            $object->add(new Property('VERSION', '2.0'));
            $object->moveFirst('VERSION');
        }

        // §6.7.9: "it must appear immediately after BEGIN:VCARD." The one
        // that is there is the one that moves; a fresh one would carry a
        // version nobody wrote.
        if ($where === self::CARD_VERSION) {
            $object->moveFirst('VERSION');
        }
    }
}
