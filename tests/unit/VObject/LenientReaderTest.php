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
use DavServices\VObject\Lexer;
use DavServices\VObject\Mode;
use DavServices\VObject\ParseError;
use DavServices\VObject\Property;
use DavServices\VObject\Reader;
use DavServices\VObject\Severity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for the second of R-VOBJ-03's two modes.
 *
 * **Strict is the default and lenient is asked for**, which is the way round
 * R-VOBJ-03 has it: „strikt (Vorgabe, lehnt fehlerhafte Eingaben ab)". A
 * caller who wants leniency says so, and a caller who says nothing gets the
 * reader P4-02b built.
 *
 * ## What lenient repairs, and what it still refuses
 *
 * **The line is the one P4-06a drew**: `Severity::Repair` means a rule is
 * broken **and** there is exactly one right answer. A lenient reader repairs
 * what has one right answer and refuses the rest — it is not a reader that
 * takes anything.
 *
 * That line falls in one place here: **a missing `END`**. There is only one
 * line that closes a `VEVENT`, so a file that never writes it can be read as
 * though it had. Three faults are the same fault:
 *
 * - the stream stops with components still open;
 * - an `END` names something further out, so the ones inside it were never
 *   closed;
 * - an `END` names nothing open at all, so there is nothing for it to close.
 *
 * And RFC 5545 §3.6 says why it is worth doing: applications "SHOULD NOT
 * silently drop any components as that can lead to user data loss". The
 * events in a truncated file are real events.
 *
 * **The refusals that stay** have no one right answer, and guessing at one
 * would put somebody's data somewhere it was never written:
 *
 * - a property before any `BEGIN` belongs to no component, and there is
 *   nothing that says which one it was meant for;
 * - a `BEGIN` that names nothing opens something no `END` could close;
 * - a line that is no content line was never read in the first place.
 *
 * ## Nothing is repaired silently
 *
 * Every repair is a {@see Finding}, with the same severity and the same kind
 * of path the validator gives. **R-VOBJ-03 says the choice of mode "hat
 * unmittelbare Protokollfolgen"**, and a protocol layer cannot draw any
 * consequence from something it was never told about.
 *
 * The consequence itself is written down in {@see Mode}: RFC 9110 §9.3.4
 * forbids an `ETag` on a `PUT` whose content was transformed, and a repaired
 * object is transformed content.
 */
/*
 * `Lexer` is named as covered for the reason set out at length in
 * {@see ReaderTest}: these tests run its generators, and Xdebug keeps a
 * generator's branch map with whatever test first ran it.
 */
#[CoversClass(Reader::class)]
#[CoversClass(Lexer::class)]
#[CoversClass(Mode::class)]
final class LenientReaderTest extends TestCase
{
    /**
     * A calendar whose file was cut off in the middle — the shape a transfer
     * that failed leaves behind.
     */
    private const CUT_OFF = "BEGIN:VCALENDAR\r\n"
        . "VERSION:2.0\r\n"
        . "BEGIN:VEVENT\r\n"
        . "UID:19970610T172345Z-AF23B2@example.com\r\n";

    /**
     * **Strict is the default**, and R-VOBJ-03 says so: a reader asked for
     * nothing in particular refuses what it cannot read.
     */
    public function testStrictIsTheDefault(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches('/still open/');

        iterator_to_array((new Reader(self::CUT_OFF))->objects(), false);
    }

    /**
     * And a strict reader never repairs anything, so it has nothing to say.
     */
    public function testAStrictReaderRepairsNothing(): void
    {
        $reader = new Reader("BEGIN:VCARD\r\nFN:Ada\r\nEND:VCARD\r\n", Mode::Strict);

        iterator_to_array($reader->objects(), false);

        self::assertSame([], $reader->repairs());
    }

    /**
     * **A file that stops early is still a file full of appointments.**
     * RFC 5545 §3.6: applications "SHOULD NOT silently drop any components as
     * that can lead to user data loss."
     */
    public function testTheStreamThatStopsEarlyIsClosed(): void
    {
        $calendar = $this->onlyObjectIn(self::CUT_OFF);

        self::assertSame('VCALENDAR', $calendar->name());
        self::assertSame('2.0', $calendar->property('VERSION')?->value());
        self::assertSame(
            '19970610T172345Z-AF23B2@example.com',
            $calendar->component('VEVENT')?->property('UID')?->value(),
        );
    }

    /**
     * **And every `END` it had to supply is named**, innermost first, because
     * that is the order they were missing in.
     */
    public function testEveryEndTheStreamNeverWroteIsReported(): void
    {
        $reader = new Reader(self::CUT_OFF, Mode::Lenient);

        iterator_to_array($reader->objects(), false);

        self::assertSame(
            [
                [Severity::Repair, 'VCALENDAR/VEVENT'],
                [Severity::Repair, 'VCALENDAR'],
            ],
            self::saidBy($reader),
        );
    }

    /**
     * **And a truncated stream keeps its nesting.** Closing the components
     * from the inside out is what puts each one back where it was written;
     * any other order would hand back a calendar inside its own alarm.
     */
    public function testATruncatedStreamKeepsItsNesting(): void
    {
        $calendar = $this->onlyObjectIn(
            "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:x\r\nBEGIN:VALARM\r\nACTION:DISPLAY\r\n",
        );

        self::assertSame(
            'DISPLAY',
            $calendar->component('VEVENT')?->component('VALARM')?->property('ACTION')?->value(),
        );
    }

    /**
     * **An `END` closes the innermost open component of its name.** Two of a
     * name can be open at once — RFC 5545 §3.6 keeps an `x-comp` nobody has
     * heard of whole, wherever it stands — and closing the outer one would
     * swallow everything between them.
     */
    public function testAnEndClosesTheInnermostOpenComponentOfItsName(): void
    {
        $reader = new Reader(
            "BEGIN:VCALENDAR\r\nBEGIN:X-BOX\r\nBEGIN:X-BOX\r\nBEGIN:VEVENT\r\nUID:x\r\n"
            . "END:X-BOX\r\nEND:X-BOX\r\nEND:VCALENDAR\r\n",
            Mode::Lenient,
        );

        $objects = iterator_to_array($reader->objects(), false);
        $calendar = $objects[0] ?? null;

        self::assertInstanceOf(Component::class, $calendar);
        self::assertSame(
            'x',
            $calendar->component('X-BOX')?->component('X-BOX')?->component('VEVENT')?->property('UID')?->value(),
        );
        self::assertSame(
            [[Severity::Repair, 'VCALENDAR/X-BOX/X-BOX/VEVENT']],
            self::saidBy($reader),
            'only the event was closed early; the outer X-BOX is closed by its own END',
        );
    }

    /**
     * **An `END` may name something further out**, and then the components
     * inside it were never closed. It is the same missing `END` as at the end
     * of a truncated stream, met in the middle of the file instead.
     */
    public function testAnEndFurtherOutClosesWhatIsStillOpenInsideIt(): void
    {
        $calendar = $this->onlyObjectIn(
            "BEGIN:VCALENDAR\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:x\r\n"
            . "BEGIN:VALARM\r\n"
            . "ACTION:DISPLAY\r\n"
            . "END:VCALENDAR\r\n",
        );

        $event = $calendar->component('VEVENT');

        self::assertInstanceOf(Component::class, $event);
        self::assertSame('x', $event->property('UID')?->value());
        self::assertSame('DISPLAY', $event->component('VALARM')?->property('ACTION')?->value());
    }

    /**
     * And it says so for each one it had to close.
     */
    public function testEachOneClosedEarlyIsReported(): void
    {
        $reader = new Reader(
            "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nBEGIN:VALARM\r\nACTION:DISPLAY\r\nEND:VCALENDAR\r\n",
            Mode::Lenient,
        );

        iterator_to_array($reader->objects(), false);

        self::assertSame(
            [
                [Severity::Repair, 'VCALENDAR/VEVENT/VALARM'],
                [Severity::Repair, 'VCALENDAR/VEVENT'],
            ],
            self::saidBy($reader),
        );
    }

    /**
     * **And the spelling does not decide which one it names.** RFC 6350
     * §6.1.2: "The value is case-insensitive", and RFC 5545 §3.5 says the
     * same of enumerated values. It holds for the one immediately open, and
     * it has to hold for the search further out as well.
     */
    public function testTheSearchFurtherOutIgnoresTheSpelling(): void
    {
        $calendar = $this->onlyObjectIn(
            "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:x\r\nend:vcalendar\r\n",
        );

        self::assertSame('x', $calendar->component('VEVENT')?->property('UID')?->value());
    }

    /**
     * **An `END` that closes nothing is left out.** It names a component that
     * was never opened, so there is nothing for it to close — and an `END`
     * carries no data of its own, so leaving it out drops nothing.
     */
    public function testAnEndThatClosesNothingIsLeftOut(): void
    {
        $calendar = $this->onlyObjectIn(
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VTODO\r\nPRODID:-//x//EN\r\nEND:VCALENDAR\r\n",
        );

        self::assertSame(['VERSION', 'PRODID'], self::namesIn($calendar));
    }

    /**
     * And that one is reported where it stood.
     */
    public function testAnEndThatClosesNothingIsReportedWhereItStood(): void
    {
        $reader = new Reader(
            "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:x\r\nEND:VTODO\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
            Mode::Lenient,
        );

        iterator_to_array($reader->objects(), false);

        self::assertSame([[Severity::Repair, 'VCALENDAR/VEVENT/VTODO']], self::saidBy($reader));
    }

    /**
     * **Even with nothing open at all** — the shape a file has when two
     * exports were concatenated and the first one's beginning went missing.
     */
    public function testAnEndWithNothingOpenAtAllIsLeftOut(): void
    {
        $card = $this->onlyObjectIn("END:VCALENDAR\r\nBEGIN:VCARD\r\nFN:Ada\r\nEND:VCARD\r\n");

        self::assertSame('Ada', $card->property('FN')?->value());
    }

    /**
     * And it is reported by the only name there is to give it.
     */
    public function testTheStrayEndIsReportedByItsOwnName(): void
    {
        $reader = new Reader("END:VCALENDAR\r\nBEGIN:VCARD\r\nFN:Ada\r\nEND:VCARD\r\n", Mode::Lenient);

        iterator_to_array($reader->objects(), false);

        self::assertSame([[Severity::Repair, 'VCALENDAR']], self::saidBy($reader));
    }

    /**
     * **A repair says where and what**, the same way a validator's finding
     * does. Somebody reading a log has ten thousand lines in front of them,
     * and the path is the only thing that says where to look.
     */
    public function testARepairSaysWhereAndWhat(): void
    {
        $reader = new Reader(self::CUT_OFF, Mode::Lenient);

        iterator_to_array($reader->objects(), false);

        $first = $reader->repairs()[0] ?? null;

        self::assertInstanceOf(Finding::class, $first);
        self::assertSame(Severity::Repair, $first->severity());
        self::assertSame('VCALENDAR/VEVENT', $first->where());
        self::assertSame('No END closes this component; the stream ends first.', $first->message());
    }

    /**
     * **What has no one right answer is refused in both modes.** A lenient
     * reader repairs; it does not guess.
     *
     * @param non-empty-string $source
     */
    #[DataProvider('whatIsRefusedEvenSo')]
    public function testWhatHasNoRightAnswerIsRefusedEvenSo(string $source, string $because): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessageMatches($because);

        iterator_to_array((new Reader($source, Mode::Lenient))->objects(), false);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function whatIsRefusedEvenSo(): iterable
    {
        yield 'a property before any BEGIN, which belongs to no component' => [
            "VERSION:2.0\r\n",
            '/outside any component/',
        ];

        yield 'a BEGIN that names nothing, which no END could close' => [
            "BEGIN:\r\nEND:\r\n",
            '/names the component/',
        ];

        yield 'a line that is no content line at all' => [
            "BEGIN:VCARD\r\nFN\r\nEND:VCARD\r\n",
            '/value after a colon/',
        ];
    }

    /**
     * **A file with nothing wrong reads the same either way**, and a lenient
     * reader that had quietly changed something would be saying so.
     */
    public function testAGoodStreamIsUntouchedAndUnremarkedOn(): void
    {
        $source = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:x\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        $reader = new Reader($source, Mode::Lenient);
        $objects = iterator_to_array($reader->objects(), false);
        $calendar = $objects[0] ?? null;

        self::assertInstanceOf(Component::class, $calendar);
        self::assertSame(['VERSION', 'VEVENT'], self::namesIn($calendar));
        self::assertSame([], $reader->repairs());
    }

    /**
     * And a stream of several objects still hands over several, repaired or
     * not: `icalstream = 1*icalobject` (RFC 5545 §3.4).
     */
    public function testSeveralObjectsStillComeOutSeparately(): void
    {
        $reader = new Reader(
            "BEGIN:VCARD\r\nFN:Ada\r\nEND:VCARD\r\nBEGIN:VCARD\r\nFN:Grace\r\n",
            Mode::Lenient,
        );

        $names = array_map(
            static fn (Component $card): ?string => $card->property('FN')?->value(),
            iterator_to_array($reader->objects(), false),
        );

        self::assertSame(['Ada', 'Grace'], $names);
    }

    /**
     * The one object a source holds.
     */
    private function onlyObjectIn(string $source): Component
    {
        $objects = iterator_to_array((new Reader($source, Mode::Lenient))->objects(), false);
        $first = $objects[0] ?? null;

        self::assertCount(1, $objects);
        self::assertInstanceOf(Component::class, $first);

        return $first;
    }

    /**
     * What the reader repaired, as severity and place.
     *
     * @return list<array{Severity, string}>
     */
    private static function saidBy(Reader $reader): array
    {
        return array_map(
            static fn (Finding $finding): array => [$finding->severity(), $finding->where()],
            $reader->repairs(),
        );
    }

    /**
     * @return list<string>
     */
    private static function namesIn(Component $component): array
    {
        return array_map(
            static fn (Property|Component $child): string => $child->name(),
            $component->children(),
        );
    }
}
