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
use DavServices\VObject\Parameter;
use DavServices\VObject\Property;
use DavServices\VObject\Severity;
use DavServices\VObject\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 5545 §3.1, §3.6, §3.7.3 and §3.7.4, and
 * RFC 6350 §6.1.1, §6.2.1 and §6.7.9. R-VOBJ-04.
 *
 * **This is where the debts come due.** Reading an object and judging it are
 * two different jobs, and the reading chunks said so each time they left a
 * rule alone: a component that holds nothing, a name outside the character
 * set, which component may stand outermost. Every one of those is here.
 *
 * ## The three severities, and what tells them apart
 *
 * R-VOBJ-04 names `REPAIR`, `WARNING` and `ERROR` and does not say where the
 * lines are. They are drawn here, and the line that matters is **whether
 * there is exactly one right answer**:
 *
 * - **`Repair`** — a MUST is broken **and** the specification leaves only one
 *   way to put it right, so a repairer can do it without guessing what the
 *   author meant.
 * - **`Error`** — a MUST is broken and nothing but the author knows what was
 *   meant.
 * - **`Warning`** — nothing is broken, but something is likely a mistake or
 *   belongs to an older specification.
 *
 * That is why a missing `VERSION` is a repair in an iCalendar object and a
 * warning in a vCard. §3.7.4 says "A value of '2.0' corresponds to this memo"
 * and there is no other, so there is one right answer. RFC 6350 §6.7.9 says
 * the opposite in its own note: "**Note that earlier versions of vCard
 * allowed this property to be placed anywhere in the vCard object, or even to
 * be absent.**" A vCard without one is an old vCard, not a broken one.
 *
 * ## Which rules apply is decided by the outermost name
 *
 * The reader could not tell an iCalendar file from a vCard one — RFC 5545
 * §3.4 names `VCALENDAR` and RFC 6350 §6.1.1 names `VCARD`, and a reader that
 * serves both cannot hold both rules. A validator sees the whole object, so
 * here it can.
 */
#[CoversClass(Validator::class)]
#[CoversClass(Finding::class)]
#[CoversClass(Severity::class)]
final class ValidatorTest extends TestCase
{
    /**
     * A calendar with everything the specification asks of it has nothing
     * said about it.
     */
    public function testACalendarThatIsRightSaysNothing(): void
    {
        self::assertSame([], (new Validator())->check($this->calendar()));
    }

    /**
     * And so does a vCard.
     */
    public function testAVCardThatIsRightSaysNothing(): void
    {
        self::assertSame([], (new Validator())->check($this->card()));
    }

    /**
     * **RFC 5545 §3.4 names `VCALENDAR` and RFC 6350 §6.1.1 names `VCARD`.**
     * Anything else outermost is not an object either specification has.
     */
    public function testAnObjectThatIsNeitherKindIsAnError(): void
    {
        $component = new Component('VEVENT');

        $component->add(new Property('UID', 'x'));

        self::assertSame(
            [[Severity::Error, 'VEVENT']],
            $this->findingsIn($component),
        );
    }

    /**
     * **RFC 5545 §3.6: `iana-comp = "BEGIN" ":" iana-token CRLF 1*contentline
     * "END" …`** — one content line at least. An empty component says
     * nothing at all, and it is the shape a client leaves behind when it
     * writes a `BEGIN` it then has nothing to put in.
     */
    public function testAComponentThatHoldsNothingIsAnError(): void
    {
        $calendar = $this->calendar();

        $calendar->add(new Component('VTODO'));

        self::assertContains([Severity::Error, 'VCALENDAR/VTODO'], $this->findingsIn($calendar));
    }

    /**
     * **RFC 5545 §3.1: `name = 1*(ALPHA / DIGIT / "-")`.** A name outside
     * that is a name no other implementation will read back, and the lexer
     * deliberately let it through so that somebody could be told where it is.
     *
     * @param non-empty-string $name
     */
    #[DataProvider('namesOutsideTheCharacterSet')]
    public function testANameOutsideTheCharacterSetIsAnError(string $name): void
    {
        $calendar = $this->calendar();
        $event = $calendar->component('VEVENT');

        self::assertInstanceOf(Component::class, $event);
        $event->add(new Property($name, 'x'));

        self::assertContains(
            [Severity::Error, 'VCALENDAR/VEVENT/' . $name],
            $this->findingsIn($calendar),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namesOutsideTheCharacterSet(): iterable
    {
        yield 'a space' => ['X MY'];

        yield 'an underscore' => ['X_MY'];

        yield 'a full stop' => ['X.MY'];

        yield 'nothing at all' => [''];
    }

    /**
     * And the same of a parameter name, which the grammar builds from the
     * same token.
     */
    public function testAParameterNameOutsideTheCharacterSetIsAnError(): void
    {
        $calendar = $this->calendar();
        $event = $calendar->component('VEVENT');

        self::assertInstanceOf(Component::class, $event);
        $event->add(new Property('X-A', 'x', [new Parameter('X Y', ['v'])]));

        self::assertContains(
            [Severity::Error, 'VCALENDAR/VEVENT/X-A;X Y'],
            $this->findingsIn($calendar),
        );
    }

    /**
     * **And a parameter name that is a name is left alone.** The character
     * set is `1*(ALPHA / DIGIT / "-")` and `TZID` is made of it, so a
     * validator that reported it would report every calendar ever written.
     */
    public function testAParameterNameThatIsANameIsNotReported(): void
    {
        $calendar = $this->calendar();
        $event = $calendar->component('VEVENT');

        self::assertInstanceOf(Component::class, $event);
        $event->add(new Property('DTSTART', '19980714T120000', [new Parameter('TZID', ['Europe/Berlin'])]));

        self::assertSame([], $this->findingsIn($calendar));
    }

    /**
     * **RFC 5545 §3.7.3: "The property MUST be specified once in an iCalendar
     * object."** And inventing one is not a repair — a product identifier
     * says who produced the file, and putting this library's name on somebody
     * else's work would be a lie in the file itself.
     */
    public function testACalendarWithoutAProductIdentifierIsAnError(): void
    {
        $calendar = $this->calendar();

        unset($calendar['PRODID']);

        self::assertContains([Severity::Error, 'VCALENDAR/PRODID'], $this->findingsIn($calendar));
    }

    /**
     * **RFC 5545 §3.7.4: "This property MUST be specified once."** Missing is
     * a repair and not an error, because §3.7.4 leaves exactly one right
     * answer: "A value of '2.0' corresponds to this memo."
     */
    public function testACalendarWithoutAVersionCanBeRepaired(): void
    {
        $calendar = $this->calendar();

        unset($calendar['VERSION']);

        self::assertContains([Severity::Repair, 'VCALENDAR/VERSION'], $this->findingsIn($calendar));
    }

    /**
     * But a version this library does not implement is an error: the file
     * says it needs something that is not here, and guessing past that would
     * be reading it as something it says it is not.
     */
    public function testACalendarOfAnotherVersionIsAnError(): void
    {
        $calendar = $this->calendar();

        $calendar['VERSION'] = new Property('VERSION', '1.0');

        self::assertContains([Severity::Error, 'VCALENDAR/VERSION'], $this->findingsIn($calendar));
    }

    /**
     * **But a range is a version too.** §3.7.4 has `vervalue = "2.0" /
     * maxver / (minver ";" maxver)`, so what a file needs may be written as
     * the two ends of a range — and a range that names 2.0 is one this
     * library is inside. The identifiers are IANA-registered names rather
     * than numbers, and 2.0 is the only one registered, so there is nothing
     * to compare for order.
     */
    public function testACalendarThatNamesTwoPointZeroInARangeIsRight(): void
    {
        $calendar = $this->calendar();

        $calendar['VERSION'] = new Property('VERSION', '2.0;2.0');

        self::assertSame([], $this->findingsIn($calendar));
    }

    /**
     * **"MUST be specified once"**, so twice is once too many — and which of
     * the two was meant is not something anybody else can say.
     */
    public function testACalendarWithTwoVersionsIsAnError(): void
    {
        $calendar = $this->calendar();

        $calendar->add(new Property('VERSION', '2.0'));

        self::assertContains([Severity::Error, 'VCALENDAR/VERSION'], $this->findingsIn($calendar));
    }

    /**
     * **RFC 5545 §3.6: "In addition, it MUST include at least one calendar
     * component."** A calendar with properties and nothing in it carries no
     * appointment, and is the shape a failed export leaves behind.
     */
    public function testACalendarWithNoComponentIsAnError(): void
    {
        $calendar = new Component('VCALENDAR');

        $calendar->add(new Property('VERSION', '2.0'));
        $calendar->add(new Property('PRODID', '-//davServices//EN'));

        self::assertContains([Severity::Error, 'VCALENDAR'], $this->findingsIn($calendar));
    }

    /**
     * **RFC 6350 §6.2.1: Cardinality `1*`, "The property MUST be present in
     * the vCard object."** A card with no formatted name has nothing to show
     * a person, and what it should say is not something a repairer knows.
     */
    public function testAVCardWithoutAFormattedNameIsAnError(): void
    {
        $card = $this->card();

        unset($card['FN']);

        self::assertContains([Severity::Error, 'VCARD/FN'], $this->findingsIn($card));
    }

    /**
     * **RFC 6350 §6.7.9 answers this one itself**: "Note that earlier
     * versions of vCard allowed this property to be placed anywhere in the
     * vCard object, or even to be absent." A card without a version is an old
     * card rather than a broken one — and which version it is written in is
     * exactly what nobody can supply.
     */
    public function testAVCardWithoutAVersionIsAWarning(): void
    {
        $card = $this->card();

        unset($card['VERSION']);

        self::assertContains([Severity::Warning, 'VCARD/VERSION'], $this->findingsIn($card));
    }

    /**
     * **And §6.7.9 gives VERSION a cardinality of 1**, which §6.1 reads as
     * "Exactly one instance per vCard MUST be present". Which of two was
     * meant is not something anybody else can say.
     */
    public function testAVCardWithTwoVersionsIsAnError(): void
    {
        $card = $this->card();

        $card->add(new Property('VERSION', '4.0'));

        self::assertContains([Severity::Error, 'VCARD/VERSION'], $this->findingsIn($card));
    }

    /**
     * **§6.7.9: "it must appear immediately after BEGIN:VCARD."** There is
     * exactly one right place, so moving it is a repair.
     */
    public function testAVersionThatIsNotFirstCanBeRepaired(): void
    {
        $card = new Component('VCARD');

        $card->add(new Property('FN', 'Ada Lovelace'));
        $card->add(new Property('VERSION', '4.0'));

        self::assertContains([Severity::Repair, 'VCARD/VERSION'], $this->findingsIn($card));
    }

    /**
     * **A vCard holds content lines and nothing else** (RFC 6350 §3.3:
     * `vcard = "BEGIN:VCARD" CRLF "VERSION:4.0" CRLF 1*contentline
     * "END:VCARD" CRLF`). A component inside one is a calendar's shape in an
     * address book.
     */
    public function testAComponentInsideAVCardIsAnError(): void
    {
        $card = $this->card();
        $event = new Component('VEVENT');

        // With something in it, so that the one finding is about where it is
        // and not about it being empty.
        $event->add(new Property('UID', 'x'));
        $card->add($event);

        self::assertSame([[Severity::Error, 'VCARD/VEVENT']], $this->findingsIn($card));
    }

    /**
     * **A finding says what it found and where.** A calendar of ten thousand
     * lines needs the path, or somebody has to read the whole file to find
     * the one line the message is about.
     */
    public function testAFindingSaysWhatAndWhere(): void
    {
        $calendar = $this->calendar();

        unset($calendar['PRODID']);

        $findings = (new Validator())->check($calendar);
        $first = $findings[0] ?? null;

        self::assertInstanceOf(Finding::class, $first);
        self::assertSame(Severity::Error, $first->severity());
        self::assertSame('VCALENDAR/PRODID', $first->where());
        self::assertStringContainsString('PRODID', $first->message());
    }

    /**
     * And the severity carries the word R-VOBJ-04 uses, so that a log or a
     * protocol answer can print it without a second table.
     */
    public function testEachSeverityCarriesItsOwnWord(): void
    {
        self::assertSame(
            ['REPAIR', 'WARNING', 'ERROR'],
            array_map(static fn (Severity $s): string => $s->value, Severity::cases()),
        );
    }

    /**
     * **Everything wrong is reported, not just the first thing.** Somebody
     * repairing a file wants the list, and a validator that stopped at the
     * first fault would have them come back as many times as there are
     * faults.
     */
    public function testEverythingWrongIsReported(): void
    {
        $card = new Component('VCARD');

        $card->add(new Property('X_A', 'x'));

        self::assertSame(
            [
                [Severity::Error, 'VCARD/X_A'],
                [Severity::Error, 'VCARD/FN'],
                [Severity::Warning, 'VCARD/VERSION'],
            ],
            $this->findingsIn($card),
        );
    }

    /**
     * **And every empty component is named, not the first one.** A calendar
     * an export gave up half way through has one of these per component it
     * never filled.
     */
    public function testEveryEmptyComponentIsNamed(): void
    {
        $calendar = $this->calendar();

        $calendar->add(new Component('VTODO'));
        $calendar->add(new Component('VJOURNAL'));

        self::assertSame(
            [
                [Severity::Error, 'VCALENDAR/VTODO'],
                [Severity::Error, 'VCALENDAR/VJOURNAL'],
            ],
            $this->findingsIn($calendar),
        );
    }

    /**
     * And every parameter of a property is looked at, not up to the first one
     * that is in order.
     */
    public function testEveryParameterNameIsLookedAt(): void
    {
        $calendar = $this->calendar();
        $event = $calendar->component('VEVENT');

        self::assertInstanceOf(Component::class, $event);
        $event->add(new Property('X-A', 'x', [
            new Parameter('TZID', ['Europe/Berlin']),
            new Parameter('X Y', ['v']),
            new Parameter('X.Z', ['v']),
        ]));

        self::assertSame(
            [
                [Severity::Error, 'VCALENDAR/VEVENT/X-A;X Y'],
                [Severity::Error, 'VCALENDAR/VEVENT/X-A;X.Z'],
            ],
            $this->findingsIn($calendar),
        );
    }

    /**
     * And a calendar that is missing all three things §3.6 asks for is told
     * about all three, in one go.
     */
    public function testACalendarMissingEverythingIsToldAboutEverything(): void
    {
        $calendar = new Component('VCALENDAR');

        $calendar->add(new Property('X-A', 'x'));

        self::assertSame(
            [
                [Severity::Error, 'VCALENDAR/PRODID'],
                [Severity::Repair, 'VCALENDAR/VERSION'],
                [Severity::Error, 'VCALENDAR'],
            ],
            $this->findingsIn($calendar),
        );
    }

    /**
     * What the validator said, as severity and place, so that a test can name
     * both without reaching into an object.
     *
     * @return list<array{Severity, string}>
     */
    private function findingsIn(Component $object): array
    {
        return array_map(
            static fn (Finding $finding): array => [$finding->severity(), $finding->where()],
            (new Validator())->check($object),
        );
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
     * A vCard with everything RFC 6350 asks for: the version first, then the
     * formatted name.
     */
    private function card(): Component
    {
        $card = new Component('VCARD');

        $card->add(new Property('VERSION', '4.0'));
        $card->add(new Property('FN', 'Ada Lovelace'));

        return $card;
    }
}
