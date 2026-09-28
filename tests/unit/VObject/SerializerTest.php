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
use DavServices\VObject\Lexer;
use DavServices\VObject\Parameter;
use DavServices\VObject\Property;
use DavServices\VObject\Reader;
use DavServices\VObject\Serializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 5545 §3.1 and RFC 6350 §3.2 and §3.3, and from
 * R-VOBJ-05.
 *
 * **This closes the circle.** P4-01 reads octets into lines, P4-02 holds them
 * as a tree, P4-02b builds the tree from a file — and this writes it back.
 *
 * ## The folding, which is the whole difficulty
 *
 * - RFC 5545 §3.1: "Lines of text SHOULD NOT be longer than 75 octets,
 *   excluding the line break."
 * - RFC 6350 §3.2: "Content lines SHOULD be folded to a maximum width of 75
 *   octets, excluding the line break. **Multi-octet characters MUST remain
 *   contiguous.**"
 * - And: "The folded line MUST contain at least one character."
 *
 * R-VOBJ-05 says the same in one sentence: fold at seventy-five octets without
 * cutting a UTF-8 sequence in half. **Octets, not characters** — a line of
 * forty emoji is a hundred and sixty octets and has to be folded, and folding
 * it in the wrong place produces a file no reader can decode.
 *
 * ## What is written as it stands, and what is written afresh
 *
 * **A property value is written exactly as it was read.** {@see Property}
 * keeps it raw and says why: the escaping belongs to one value type, and the
 * value types of P4-03 and P4-04 are for callers who mean to change
 * something. A serialiser that re-encoded every value would rewrite files
 * nobody had edited, and R-VOBJ-05's round trip is worth having only if it
 * changes nothing that was not changed.
 *
 * **A parameter value is written afresh**, and for the same reason read the
 * other way: {@see ContentLine} decoded it — took the quotes off and undid
 * RFC 6868's `^` sequences — so writing it back is putting back what reading
 * took away.
 */
/*
 * **`Reader` and `Lexer` are named here as well, and they have to be.** The
 * round-trip tests below read back what was written, with the real reader —
 * which is the only thing that can say whether the folding is right. Xdebug
 * attaches a function's branch map the first time it runs in the process, and
 * for a **generator** that map stays with whatever test triggered it; so when
 * the suite reaches this file before `LexerTest` or `ReaderTest`, their maps
 * are recorded against a test that covers `Serializer`, thrown away with the
 * rest, and the coverage gate fails on the order the tests ran in.
 *
 * It happened once before (#72) and the rule generalises: **a test class that
 * drives another class's generator names that class.** Naming it is also true
 * — these tests do read whole files through both of them.
 */
#[CoversClass(Serializer::class)]
#[CoversClass(Reader::class)]
#[CoversClass(Lexer::class)]
final class SerializerTest extends TestCase
{
    /**
     * RFC 5545 §3.4's own example, written back exactly as it appears there.
     */
    public function testWritesTheExampleFromTheSpecification(): void
    {
        $calendar = new Component('VCALENDAR');

        $calendar->add(new Property('VERSION', '2.0'));
        $calendar->add(new Property('PRODID', '-//hacksw/handcal//NONSGML v1.0//EN'));

        $event = new Component('VEVENT');

        $event->add(new Property('UID', '19970610T172345Z-AF23B2@example.com'));
        $event->add(new Property('SUMMARY', 'Bastille Day Party'));
        $calendar->add($event);

        self::assertSame(
            "BEGIN:VCALENDAR\r\n"
            . "VERSION:2.0\r\n"
            . "PRODID:-//hacksw/handcal//NONSGML v1.0//EN\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:19970610T172345Z-AF23B2@example.com\r\n"
            . "SUMMARY:Bastille Day Party\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n",
            (new Serializer())->write($calendar),
        );
    }

    /**
     * **Every line ends with CRLF**, which both grammars write into every
     * production and which nothing else will do: a file with bare newlines is
     * read by this library and refused by others.
     */
    public function testEveryLineEndsWithCarriageReturnAndLineFeed(): void
    {
        $written = (new Serializer())->write(new Component('VCARD'));

        self::assertSame("BEGIN:VCARD\r\nEND:VCARD\r\n", $written);
    }

    /**
     * Components nest as deep as the tree does, and close in the order they
     * opened.
     */
    public function testComponentsNestAndCloseInOrder(): void
    {
        $calendar = new Component('VCALENDAR');
        $event = new Component('VEVENT');

        $event->add(new Component('VALARM'));
        $calendar->add($event);

        self::assertSame(
            "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nBEGIN:VALARM\r\nEND:VALARM\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
            (new Serializer())->write($calendar),
        );
    }

    /**
     * RFC 6350 §3.3: `contentline = [group "."] name …`. An address book that
     * lost the group would scatter entries somebody had grouped.
     */
    public function testAGroupIsWrittenBeforeTheName(): void
    {
        self::assertSame(
            'item1.EMAIL:ada@example.com',
            $this->lineFor(new Property('EMAIL', 'ada@example.com', [], 'item1')),
        );
    }

    /**
     * `*(";" param)`, in the order they stand.
     */
    public function testParametersAreWrittenAfterTheName(): void
    {
        $property = new Property('ATTENDEE', 'mailto:ada@example.com', [
            new Parameter('ROLE', ['CHAIR']),
            new Parameter('PARTSTAT', ['ACCEPTED']),
        ]);

        self::assertSame(
            'ATTENDEE;ROLE=CHAIR;PARTSTAT=ACCEPTED:mailto:ada@example.com',
            $this->lineFor($property),
        );
    }

    /**
     * `param-value *("," param-value)` — several values under one name.
     */
    public function testTheValuesOfOneParameterAreSeparatedByCommas(): void
    {
        $property = new Property('TEL', '+44 20 7123 4567', [new Parameter('TYPE', ['work', 'voice'])]);

        self::assertSame('TEL;TYPE=work,voice:+44 20 7123 4567', $this->lineFor($property));
    }

    /**
     * **A parameter written without a value keeps that shape**, which is what
     * vCard 2.1's `TEL;HOME;VOICE:` is made of — and what {@see ContentLine}
     * deliberately did not turn into `TYPE=HOME` on the way in.
     */
    public function testAParameterWithNoValuesIsWrittenAsItsNameAlone(): void
    {
        $property = new Property('TEL', '+44 20 7123 4567', [new Parameter('HOME')]);

        self::assertSame('TEL;HOME:+44 20 7123 4567', $this->lineFor($property));
    }

    /**
     * **A parameter value is quoted where it has to be.** `SAFE-CHAR` leaves
     * out the semicolon, the colon and the comma, so a value holding one of
     * them can only be written as a `quoted-string`.
     *
     * @param non-empty-string $value
     */
    #[DataProvider('valuesThatMustBeQuoted')]
    public function testAParameterValueIsQuotedWhereItHasToBe(string $value, string $expected): void
    {
        $property = new Property('X-M', 'v', [new Parameter('X-A', [$value])]);

        self::assertSame(sprintf('X-M;X-A=%s:v', $expected), $this->lineFor($property));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function valuesThatMustBeQuoted(): iterable
    {
        yield 'a colon' => ['https://example.com/a', '"https://example.com/a"'];

        yield 'a semicolon' => ['here; and there', '"here; and there"'];

        yield 'a comma' => ['one, two', '"one, two"'];

        yield 'and nothing of the sort is left unquoted' => ['plain', 'plain'];
    }

    /**
     * **RFC 6868 §3 is put back on the way out**, because reading took it
     * off: a `"` becomes `^'`, a `^` becomes `^^`, and a line break becomes
     * `^n`. Without it those characters cannot be written at all —
     * `QSAFE-CHAR` excludes the quote and `CONTROL` the line break.
     *
     * @param non-empty-string $value
     */
    #[DataProvider('whatRfc6868Encodes')]
    public function testTheParameterEscapingIsPutBack(string $value, string $expected): void
    {
        $property = new Property('X-M', 'v', [new Parameter('X-A', [$value])]);

        self::assertSame(sprintf('X-M;X-A=%s:v', $expected), $this->lineFor($property));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function whatRfc6868Encodes(): iterable
    {
        yield 'a circumflex' => ['2^3', '2^^3'];

        yield 'a quote' => ['George "Babe" Ruth', 'George ^\'Babe^\' Ruth'];

        yield 'a line break' => ["a\nb", 'a^nb'];

        yield 'and a Windows one is the same break' => ["a\r\nb", 'a^nb'];
    }

    /**
     * **The circumflex goes first.** A value holding `^"` must come out as
     * `^^^'`, and a writer that put the quote back before the circumflex
     * would escape its own escape and produce `^^'`, which reads as a quote
     * where a circumflex was meant.
     */
    public function testTheCircumflexIsEscapedBeforeWhatUsesIt(): void
    {
        $property = new Property('X-M', 'v', [new Parameter('X-A', ['^"'])]);

        self::assertSame('X-M;X-A=^^^\':v', $this->lineFor($property));
    }

    /**
     * **A property value is written exactly as it stands.** It was never
     * decoded, so there is nothing to put back — and a serialiser that
     * escaped it again would double every backslash in the file each time it
     * was saved.
     */
    public function testAPropertyValueIsWrittenAsItStands(): void
    {
        self::assertSame(
            'SUMMARY:one\\nand\\, two',
            $this->lineFor(new Property('SUMMARY', 'one\\nand\\, two')),
        );
    }

    /**
     * An empty value is written as an empty value: `SUMMARY:` says the
     * summary is empty, which is not the same as the property being absent.
     */
    public function testAnEmptyValueIsWritten(): void
    {
        self::assertSame('SUMMARY:', $this->lineFor(new Property('SUMMARY', '')));
    }

    /**
     * **RFC 5545 §3.1: no line longer than seventy-five octets**, excluding
     * the break — and the continuation's own leading space counts towards its
     * seventy-five.
     */
    public function testNoLineIsLongerThanSeventyFiveOctets(): void
    {
        $written = (new Serializer())->write($this->calendarWith(new Property('DESCRIPTION', str_repeat('a', 500))));

        foreach (explode("\r\n", rtrim($written, "\r\n")) as $line) {
            self::assertLessThanOrEqual(75, strlen($line), sprintf('"%s" is too long', $line));
        }
    }

    /**
     * And every continuation begins with exactly one space, which is what
     * unfolding takes away again.
     */
    public function testEveryContinuationBeginsWithOneSpace(): void
    {
        $written = (new Serializer())->write($this->calendarWith(new Property('DESCRIPTION', str_repeat('a', 200))));
        $continuations = [];

        foreach (explode("\r\n", rtrim($written, "\r\n")) as $line) {
            if (str_starts_with($line, ' ')) {
                $continuations[] = substr($line, 0, 2);
            }
        }

        self::assertNotSame([], $continuations, 'it really did have to fold');
        self::assertSame(array_fill(0, count($continuations), ' a'), $continuations);
    }

    /**
     * **RFC 6350 §3.2: "Multi-octet characters MUST remain contiguous."**
     * This is the one that turns a file into rubbish when it is got wrong,
     * and it cannot be seen by counting characters — a line of euro signs is
     * three octets to each one of them, so the seventy-fifth octet lands in
     * the middle of one.
     */
    public function testAMultiOctetCharacterIsNeverCutInHalf(): void
    {
        $value = str_repeat("\u{20AC}", 100);
        $written = (new Serializer())->write($this->calendarWith(new Property('DESCRIPTION', $value)));

        self::assertGreaterThan(1, substr_count($written, "\r\n "), 'it really did have to fold');

        foreach (explode("\r\n", rtrim($written, "\r\n")) as $line) {
            self::assertSame($line, mb_convert_encoding($line, 'UTF-8', 'UTF-8'), 'every line decodes on its own');
        }
    }

    /**
     * **A two-octet character is the harder case than a three-octet one.**
     * A line of euro signs happens to break on a character boundary; a line
     * of e-acute does not, and its second octet is one whose low bit is set —
     * so a reader that tested for a continuation octet with the wrong mask
     * would cut it in half and never notice on the easy case.
     */
    public function testATwoOctetCharacterIsNeverCutInHalfEither(): void
    {
        $value = str_repeat("\u{00E9}", 50);
        $written = (new Serializer())->write($this->calendarWith(new Property('DESCRIPTION', $value)));

        foreach (explode("\r\n", rtrim($written, "\r\n")) as $line) {
            self::assertLessThanOrEqual(75, strlen($line), sprintf('"%s" is too long', $line));
            self::assertSame($line, mb_convert_encoding($line, 'UTF-8', 'UTF-8'), 'every line decodes on its own');
        }

        self::assertSame(
            $value,
            $this->firstObjectIn($written)->component('VEVENT')?->property('DESCRIPTION')?->value(),
        );
    }

    /**
     * **A line of exactly seventy-five octets is not folded.** "SHOULD NOT be
     * longer than 75" — so seventy-five is allowed, and folding it anyway
     * would add a line to every file that sat exactly on the edge.
     */
    public function testALineOfExactlySeventyFiveOctetsStandsAsItIs(): void
    {
        $value = str_repeat('a', 75 - strlen('DESCRIPTION:'));
        $written = (new Serializer())->write($this->calendarWith(new Property('DESCRIPTION', $value)));

        self::assertStringContainsString("\r\n" . 'DESCRIPTION:' . $value . "\r\n", $written);
    }

    /**
     * And one octet more is folded into two.
     */
    public function testOneOctetMoreIsFolded(): void
    {
        $value = str_repeat('a', 76 - strlen('DESCRIPTION:'));
        $written = (new Serializer())->write($this->calendarWith(new Property('DESCRIPTION', $value)));

        self::assertStringContainsString("\r\n" . ' a' . "\r\n", $written);
    }

    /**
     * **Octets that are no UTF-8 at all must not make the writer spin.** The
     * back-off looks for the start of a character, and a value of nothing but
     * continuation octets has none — so the search has to stop somewhere, or
     * the line is never finished and the process never returns.
     */
    public function testAValueThatIsNoUtf8DoesNotHangTheWriter(): void
    {
        $value = str_repeat("\x80", 200);
        $written = (new Serializer())->write($this->calendarWith(new Property('DESCRIPTION', $value)));

        self::assertSame(
            $value,
            $this->firstObjectIn($written)->component('VEVENT')?->property('DESCRIPTION')?->value(),
        );
    }

    /**
     * **The lines are handed over one at a time**, so that a calendar of ten
     * megabytes can go to a stream without being built in memory first.
     */
    public function testTheLinesAreHandedOverOneAtATime(): void
    {
        $lines = iterator_to_array((new Serializer())->lines(new Component('VCARD')), false);

        self::assertSame(['BEGIN:VCARD' . "\r\n", 'END:VCARD' . "\r\n"], $lines);
    }

    /**
     * **What follows a component is still written.** A calendar holds an
     * event and then a to-do, and a writer that stopped after the first thing
     * it recursed into would lose every sibling after it.
     */
    public function testWhatFollowsAComponentIsStillWritten(): void
    {
        $calendar = new Component('VCALENDAR');

        $calendar->add(new Component('VEVENT'));
        $calendar->add(new Component('VTODO'));
        $calendar->add(new Property('METHOD', 'REQUEST'));

        self::assertSame(
            'BEGIN:VCALENDAR' . "\r\n" . 'BEGIN:VEVENT' . "\r\n" . 'END:VEVENT' . "\r\n"
            . 'BEGIN:VTODO' . "\r\n" . 'END:VTODO' . "\r\n" . 'METHOD:REQUEST' . "\r\n" . 'END:VCALENDAR' . "\r\n",
            (new Serializer())->write($calendar),
        );
    }

    /**
     * **And the whole thing still says what it said.** Folding is only
     * correct if unfolding undoes it, so the value is read back out of what
     * was written.
     */
    public function testWhatIsFoldedUnfoldsToTheSameValue(): void
    {
        $value = str_repeat("\u{20AC}x", 80);
        $written = (new Serializer())->write($this->calendarWith(new Property('DESCRIPTION', $value)));
        $read = $this->firstObjectIn($written);

        self::assertSame($value, $read->component('VEVENT')?->property('DESCRIPTION')?->value());
    }

    /**
     * **The round trip R-VOBJ-05 asks for, over a whole file.** What comes
     * out of the reader and goes back through the serialiser is the file that
     * went in — folding, parameters, quotes, escapes and all.
     */
    public function testAWholeFileSurvivesTheRoundTrip(): void
    {
        $file = "BEGIN:VCALENDAR\r\n"
            . "VERSION:2.0\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:19970610T172345Z-AF23B2@example.com\r\n"
            . "ATTENDEE;CN=\"Lovelace, Ada\";ROLE=CHAIR:mailto:ada@example.com\r\n"
            . "SUMMARY:Lunch\\, then the review\r\n"
            . "DESCRIPTION:" . str_repeat('word ', 40) . "\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n";

        $written = (new Serializer())->write($this->firstObjectIn($file));

        self::assertSame(
            $this->unfolded($file),
            $this->unfolded($written),
            'the same content lines, however they were folded',
        );
    }

    /**
     * The one object a piece of text holds, read back with the reader of
     * P4-02b — which is the only thing that can say whether what was written
     * can be read.
     */
    private function firstObjectIn(string $text): Component
    {
        $objects = iterator_to_array((new Reader($text))->objects(), false);
        $first = $objects[0] ?? null;

        self::assertInstanceOf(Component::class, $first);

        return $first;
    }

    /**
     * A calendar holding one event with one property, which is the smallest
     * thing that puts a property inside something.
     */
    private function calendarWith(Property $property): Component
    {
        $calendar = new Component('VCALENDAR');
        $event = new Component('VEVENT');

        $event->add($property);
        $calendar->add($event);

        return $calendar;
    }

    /**
     * The one content line a property is written as, with the folding taken
     * out again so that a test can say what it means.
     */
    private function lineFor(Property $property): string
    {
        $component = new Component('X-HOLDER');

        $component->add($property);

        $lines = explode("\r\n", $this->unfolded((new Serializer())->write($component)));

        return $lines[1] ?? '';
    }

    /**
     * Folding undone, for comparing what was written against what was read.
     */
    private function unfolded(string $text): string
    {
        return str_replace("\r\n ", '', $text);
    }
}
