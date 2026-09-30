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

namespace DavServices\Tests\Unit\VObject;

use DavServices\VObject\Component;
use DavServices\VObject\Finding;
use DavServices\VObject\Property;
use DavServices\VObject\Repairer;
use DavServices\VObject\Severity;
use DavServices\VObject\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for the repair half of R-VOBJ-04: "Validierungsfunktion mit
 * Schweregraden `REPAIR`, `WARNING`, `ERROR` **sowie eine
 * Reparaturfunktion**."
 *
 * **`Severity::Repair` was drawn for exactly this.** P4-06a put the line at
 * whether the specification leaves one right answer, and that is the line a
 * repairer can work along: where there is one answer it can be given without
 * anybody guessing, and where there is none nothing but the author knows what
 * was meant.
 *
 * So the rule is short, and it is the whole rule:
 *
 * - **`Repair` is put right.**
 * - **`Error` is left exactly as it stands.** Inventing a `PRODID` would put
 *   this library's name on somebody else's work.
 * - **`Warning` is left as it stands too.** A vCard with no `VERSION` is an
 *   old vCard (RFC 6350 §6.7.9: "or even to be absent"), and which version it
 *   was written in is precisely what nobody else can supply.
 *
 * ## An object is a document people edit
 *
 * It is repaired where it stands rather than copied. {@see Component} says
 * why: rebuilding a whole calendar to correct one line would make every edit
 * a copy of everything around it.
 */
#[CoversClass(Repairer::class)]
#[CoversClass(Validator::class)]
final class RepairerTest extends TestCase
{
    /**
     * **RFC 5545 §3.7.4: "A value of '2.0' corresponds to this memo."**
     * There is no other, so there is nothing to guess at.
     */
    public function testACalendarWithoutAVersionIsGivenOne(): void
    {
        $calendar = $this->calendar();

        unset($calendar['VERSION']);
        (new Repairer())->repair($calendar);

        self::assertSame('2.0', $calendar->property('VERSION')?->value());
    }

    /**
     * **And it goes at the front, which is a choice rather than a rule.**
     * RFC 5545 §3.5: "This memo imposes no ordering of properties within an
     * iCalendar object", so nothing is owed here. §3.4's own example puts
     * `VERSION` at the top, and one appended after the last `END` would look
     * to everyone who opened the file like it belonged to nothing.
     */
    public function testTheVersionIsPutAtTheFront(): void
    {
        $calendar = $this->calendar();

        unset($calendar['VERSION']);
        (new Repairer())->repair($calendar);

        self::assertSame(['VERSION', 'PRODID', 'VEVENT'], self::namesIn($calendar));
    }

    /**
     * **RFC 6350 §6.7.9: "it must appear immediately after BEGIN:VCARD."**
     * One right place, so moving it there is no guess either.
     */
    public function testAVersionOutOfPlaceIsMovedToTheFront(): void
    {
        $card = new Component('VCARD');

        $card->add(new Property('FN', 'Ada Lovelace'));
        $card->add(new Property('VERSION', '4.0'));

        (new Repairer())->repair($card);

        self::assertSame(['VERSION', 'FN'], self::namesIn($card));
    }

    /**
     * And the one that was there is the one that is moved, not a fresh one
     * carrying a version somebody never wrote.
     */
    public function testTheVersionThatWasThereIsTheOneThatMoves(): void
    {
        $card = new Component('VCARD');

        $card->add(new Property('FN', 'Ada Lovelace'));
        $card->add(new Property('VERSION', '3.0'));

        (new Repairer())->repair($card);

        self::assertSame('3.0', $card->property('VERSION')?->value());
        self::assertCount(1, $card->properties('VERSION'));
    }

    /**
     * **A repairer says what it put right**, and says it the way the
     * validator does. A protocol layer that has to answer for a repaired
     * object needs to know one happened — R-VOBJ-03: „Die Wahl der
     * Betriebsart hat unmittelbare Protokollfolgen".
     */
    public function testARepairerSaysWhatItPutRight(): void
    {
        $calendar = $this->calendar();

        unset($calendar['VERSION']);

        $repaired = (new Repairer())->repair($calendar);
        $first = $repaired[0] ?? null;

        self::assertCount(1, $repaired);
        self::assertInstanceOf(Finding::class, $first);
        self::assertSame(Severity::Repair, $first->severity());
        self::assertSame('VCALENDAR/VERSION', $first->where());
    }

    /**
     * **And afterwards the validator has nothing left to repair.** That is
     * the whole promise of the severity: one right answer, given.
     */
    public function testAfterwardsThereIsNothingLeftToRepair(): void
    {
        $calendar = $this->calendar();

        unset($calendar['VERSION']);
        (new Repairer())->repair($calendar);

        self::assertSame([], (new Validator())->check($calendar));
    }

    /**
     * **And one that cannot be repaired does not stop the ones that can.**
     * A file with two things wrong with it is the ordinary case rather than
     * the edge, and a repairer that gave up at the first thing it could not
     * mend would leave the rest for somebody to find later.
     */
    public function testWhatCannotBeRepairedDoesNotStopWhatCan(): void
    {
        $calendar = $this->calendar();

        unset($calendar['VERSION']);
        $calendar->add(new Property('X_A', 'an underscore is no name'));

        $repaired = (new Repairer())->repair($calendar);

        self::assertSame('2.0', $calendar->property('VERSION')?->value());
        self::assertCount(1, $repaired, 'the name is an error, and errors are left alone');
    }

    /**
     * **What nothing but the author knows is left exactly as it stands.**
     * A `PRODID` says who produced the file, and inventing one would write a
     * lie into it.
     */
    public function testAMissingProductIdentifierIsLeftAlone(): void
    {
        $calendar = $this->calendar();

        unset($calendar['PRODID']);

        self::assertSame([], (new Repairer())->repair($calendar));
        self::assertNull($calendar->property('PRODID'));
    }

    /**
     * And so is a vCard's missing `VERSION`, which says it is an older vCard
     * (RFC 6350 §6.7.9: "or even to be absent") — which one is not for a
     * repairer to decide.
     */
    public function testAVCardIsNotGivenAVersionItNeverHad(): void
    {
        $card = $this->card();

        unset($card['VERSION']);

        self::assertSame([], (new Repairer())->repair($card));
        self::assertNull($card->property('VERSION'));
    }

    /**
     * **An object with nothing wrong is not touched.** A repairer that
     * rewrote every file it saw would change files nobody had edited.
     */
    public function testAnObjectThatIsRightIsLeftAlone(): void
    {
        $calendar = $this->calendar();

        self::assertSame([], (new Repairer())->repair($calendar));
        self::assertSame(['VERSION', 'PRODID', 'VEVENT'], self::namesIn($calendar));
    }

    /**
     * And neither is one that is neither kind: there are no rules to repair
     * it against.
     */
    public function testAnObjectOfNeitherKindIsLeftAlone(): void
    {
        $event = new Component('VEVENT');

        $event->add(new Property('UID', 'x'));

        self::assertSame([], (new Repairer())->repair($event));
        self::assertSame(['UID'], self::namesIn($event));
    }

    /**
     * A calendar with everything RFC 5545 §3.6 asks for.
     */
    private function calendar(): Component
    {
        $calendar = new Component('VCALENDAR');

        $calendar->add(new Property('VERSION', '2.0'));
        $calendar->add(new Property('PRODID', '-//davServices//EN'));

        $event = new Component('VEVENT');

        $event->add(new Property('UID', '19970610T172345Z-AF23B2@example.com'));
        $calendar->add($event);

        return $calendar;
    }

    /**
     * A vCard with everything RFC 6350 asks for.
     */
    private function card(): Component
    {
        $card = new Component('VCARD');

        $card->add(new Property('VERSION', '4.0'));
        $card->add(new Property('FN', 'Ada Lovelace'));

        return $card;
    }

    /**
     * @return list<string>
     */
    private static function namesIn(Component $component): array
    {
        $names = [];

        foreach ($component->children() as $child) {
            $names[] = $child->name();
        }

        return $names;
    }
}
