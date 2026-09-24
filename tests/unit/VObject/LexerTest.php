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

use DavServices\VObject\ContentLine;
use DavServices\VObject\Lexer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 5545 §3.1, RFC 6350 §3.2 and RFC 2425 §5.8.1,
 * and from R-VOBJ-01 and R-VOBJ-02.
 *
 * **Unfolding comes before everything else.** RFC 5545 §3.1: "When parsing a
 * content line, folded lines MUST first be unfolded according to the
 * unfolding procedure described above." The three specifications that govern
 * the formats this library reads say the same thing in the same words:
 *
 * - RFC 5545 §3.1 (iCalendar): "Any sequence of CRLF followed immediately by
 *   a single linear white-space character is ignored (i.e., removed) when
 *   processing the content type."
 * - RFC 2425 §5.8.1, which vCard 3.0 adopts by reference (RFC 2426 §2.4.2):
 *   the same sentence.
 * - RFC 6350 §3.2 (vCard 4.0): "regarding CRLF immediately followed by a
 *   white space character … as equivalent to no characters at all (i.e., the
 *   CRLF and single white space character are removed)."
 *
 * **vCard 2.1 has no RFC**, and its text is not obtainable from here. Where
 * three specifications agree and none disagrees, one rule is what this reads
 * by — and anything 2.1 turns out to do differently is a repair for the
 * lenient mode (R-VOBJ-03, P4-06), not a second lexer.
 *
 * ## The line break
 *
 * Every one of them says CRLF, and each says it about **writing** a file.
 * Read only CRLF and a file saved by any Unix tool becomes one enormous
 * line — so all three of CRLF, LF and CR delimit here (R-VOBJ-01 asks for
 * exactly that). Refusing the ones the grammar does not have is a judgement
 * about the input, and a judgement needs the input to have been read first;
 * that is R-VOBJ-03's strict mode, which comes in P4-06.
 *
 * ## Memory
 *
 * R-VOBJ-02: "Streamende Verarbeitung; Dateien über 10 MB DÜRFEN NICHT
 * proportional Speicher belegen." One logical line is held at a time, which
 * is what streaming can honestly mean here: a `PHOTO` carrying an inline
 * image is a single content line, and there is no reading it without having
 * it. What must never be held is the file.
 *
 * ## Why every assertion compares a whole list
 *
 * Reaching for `$lines[0]` says nothing about how many there were, and the
 * faults of an unfolder are almost all about the number of lines: one too
 * many where a fold was missed, one too few where a line ending was. So the
 * expected value is the whole list, every time.
 */
#[CoversClass(Lexer::class)]
final class LexerTest extends TestCase
{
    /**
     * The whole point of the class, in one object: what comes out has been
     * unfolded, and comes out as content lines.
     */
    public function testReadsTheLinesOfAnObject(): void
    {
        $names = $this->namesOf("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n");

        self::assertSame(['BEGIN', 'VERSION', 'END'], $names);
    }

    /**
     * RFC 5545 §3.1's own example, word for word:
     *
     *     DESCRIPTION:This is a lo
     *      ng description
     *       that exists on a long line.
     *
     * Note what it settles on its own: the third line begins with **two**
     * spaces, and only one of them belongs to the folding. The other is part
     * of the description.
     */
    public function testUnfoldsTheExampleFromTheSpecification(): void
    {
        $values = $this->valuesOf(
            "DESCRIPTION:This is a lo\r\n ng description\r\n  that exists on a long line.\r\n",
        );

        self::assertSame(['This is a long description that exists on a long line.'], $values);
    }

    /**
     * **Only one white-space character is removed**, said on its own because
     * it is the half of the rule that is easy to miss: "a single linear
     * white-space character" (RFC 5545 §3.1). A reader that ate the run of
     * them would quietly reformat somebody's text.
     */
    public function testOnlyOneWhiteSpaceCharacterBelongsToTheFold(): void
    {
        self::assertSame(['one  two'], $this->valuesOf("NOTE:one\r\n   two\r\n"));
    }

    /**
     * RFC 6350 §3.2 names both: "a single white space character (space
     * (U+0020) or horizontal tab (U+0009))".
     */
    public function testATabFoldsAsWellAsASpace(): void
    {
        self::assertSame(['onetwo'], $this->valuesOf("NOTE:one\r\n\ttwo\r\n"));
    }

    /**
     * **Every line ending folds, because every line ending delimits.** The
     * two halves of the rule are one sentence and cannot come apart.
     *
     * The space after the ending belongs to the fold and goes with it, so
     * the two halves of the first line meet with nothing between them.
     *
     * @param non-empty-string $break
     */
    #[DataProvider('theThreeLineEndings')]
    public function testEveryLineEndingDelimitsAndFolds(string $break): void
    {
        $values = $this->valuesOf(sprintf('NOTE:one%s two%sUID:x%s', $break, $break, $break));

        self::assertSame(['onetwo', 'x'], $values);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function theThreeLineEndings(): iterable
    {
        yield 'CRLF, which every specification asks for' => ["\r\n"];

        yield 'LF, which every Unix tool writes' => ["\n"];

        yield 'CR, which an old Macintosh wrote' => ["\r"];
    }

    /**
     * **RFC 5545 §3.1's note, and RFC 6350 §3.2 repeats it:** "It is
     * possible for very simple implementations to generate improperly folded
     * lines in the middle of a UTF-8 multi-octet sequence. For this reason,
     * implementations need to unfold lines in such a way to properly restore
     * the original sequence."
     *
     * So the fold is taken out of the octets, before anything is read as
     * text — a reader that decoded first would find a broken character and
     * have nothing left to repair it with.
     */
    public function testAFoldInsideACharacterRestoresTheCharacter(): void
    {
        $euro = "\u{20AC}";

        self::assertSame([$euro], $this->valuesOf(sprintf("SUMMARY:%s\r\n %s\r\n", $euro[0], substr($euro, 1))));
    }

    /**
     * **The last line needs no ending.** Every grammar writes `… CRLF`, and
     * a file that stops without one is still a file somebody has to read —
     * truncated at the end of the content, which is where truncation is
     * harmless.
     */
    public function testTheLastLineNeedsNoEnding(): void
    {
        self::assertSame(['x'], $this->valuesOf('UID:x'));
    }

    /**
     * **An empty line is no content line and is passed over.** Every object
     * ends with a line ending — `"END:VCALENDAR" CRLF` — so a file that is
     * exactly right ends with one, and a reader that yielded what follows it
     * would report an empty line for every correctly written file there is.
     */
    public function testEmptyLinesArePassedOver(): void
    {
        self::assertSame(['x', 'y'], $this->valuesOf("UID:x\r\n\r\n\r\nSUMMARY:y\r\n"));
    }

    /**
     * Nothing at all is no lines, rather than one empty one.
     */
    public function testAnEmptySourceHasNoLines(): void
    {
        self::assertSame([], $this->valuesOf(''));
    }

    /**
     * A stream is read as readily as a string, because that is what a
     * request body arrives as (R-VOBJ-02) — and it is the same reading
     * either way.
     */
    public function testReadsFromAStream(): void
    {
        $stream = fopen('php://temp', 'r+b');

        self::assertIsResource($stream);
        fwrite($stream, "SUMMARY:from a stream\r\n");
        rewind($stream);

        self::assertSame(['from a stream'], $this->valuesOf($stream));
    }

    /**
     * **A fold across a chunk boundary is still a fold.** The reader works
     * in chunks, and the decision "does white space follow this line
     * ending?" needs a character that may not have arrived yet. Getting this
     * wrong splits one line in two, and only on inputs of the wrong length —
     * which is the kind of fault that reaches production.
     */
    public function testAFoldAcrossAChunkBoundaryIsStillAFold(): void
    {
        // The line ending lands on the last octet of a chunk, so that the
        // white space deciding its meaning is in the next one.
        $filler = self::fillerToTheEndOfAChunk();

        self::assertSame([$filler . 'end'], $this->valuesOf(sprintf("SUMMARY:%s\r\n end\r\n", $filler)));
    }

    /**
     * And so is a `CRLF` that is itself split between two chunks: the `CR`
     * arrives in one and the `LF` in the next, and a reader that decided on
     * the `CR` alone would take the `LF` for the beginning of a line.
     */
    public function testACarriageReturnAtTheEndOfAChunkWaitsForWhatFollows(): void
    {
        $filler = self::fillerToTheEndOfAChunk();

        self::assertSame([$filler, 'x'], $this->valuesOf(sprintf("SUMMARY:%s\r\nUID:x\r\n", $filler)));
    }

    /**
     * **What is held back at a chunk boundary is kept, not replaced.** When
     * a chunk ends with a line ending and the first octet of the next line,
     * both have to survive into the next read. A reader that dropped them
     * would run two lines into one and lose an octet in the middle — and
     * only for files of exactly the wrong length.
     */
    public function testWhatIsHeldBackAtAChunkBoundaryIsKept(): void
    {
        $filler = str_repeat('a', Lexer::CHUNK - strlen('SUMMARY:') - 2);

        self::assertSame([$filler, 'y'], $this->valuesOf(sprintf("SUMMARY:%s\nUID:y\r\n", $filler)));
    }

    /**
     * **R-VOBJ-02: the file is not read into memory.** Two megabytes are put
     * in front of the reader and one line is taken; what it has read by then
     * is at most a chunk.
     *
     * This says it more plainly than a measurement of memory could. A reader
     * that swallowed the file whole would stand at two million octets here,
     * and no threshold has to be chosen for that to be obvious. Measuring
     * `memory_get_usage()` instead means measuring PHP's allocator, which
     * grows its arena when it feels like it — a test that fails on the
     * ordering of the suite rather than on the code.
     */
    public function testOnlyAsMuchIsReadAsTheNextLineNeeds(): void
    {
        $stream = fopen('php://temp/maxmemory:0', 'r+b');

        self::assertIsResource($stream);

        // Two thousand lines of a thousand octets: two megabytes, and every
        // line shorter than a chunk, so that one line needs one read.
        $block = str_repeat(sprintf("SUMMARY:%s\r\n", str_repeat('x', 1000)), 100);

        for ($at = 0; $at < 20; ++$at) {
            fwrite($stream, $block);
        }

        rewind($stream);

        $lines = (new Lexer($stream))->lines();

        self::assertSame('SUMMARY', $lines->current()->name());
        self::assertLessThanOrEqual(Lexer::CHUNK, ftell($stream));
    }

    /**
     * And what it holds while doing that is one line and not the file: a
     * line of ten thousand octets comes out whole from a reader whose
     * buffer is a quarter of that.
     */
    public function testALineLongerThanAChunkComesOutWhole(): void
    {
        $value = str_repeat('x', 10_000);

        self::assertSame([$value, 'x'], $this->valuesOf(sprintf("SUMMARY:%s\r\nUID:x\r\n", $value)));
    }

    /**
     * **A line folded many times comes out whole.** A `PHOTO` inline in a
     * vCard is folded every seventy-five octets, so a picture arrives in
     * thousands of pieces — and a reader that lost one of them, or joined
     * two in the wrong order, would hand over an image nobody can open.
     *
     * There is deliberately **no assertion about how long this takes**. The
     * coverage run traces every line that executes and is tens of times
     * slower, so a time limit here would be a test of the machine. What the
     * size is for is the joining: the pieces are collected and joined once,
     * rather than the line being copied afresh for every fold.
     */
    public function testALineFoldedManyTimesComesOutWhole(): void
    {
        $pieces = 200;

        $values = $this->valuesOf('PHOTO:' . str_repeat("x
 ", $pieces) . "
");

        self::assertSame([str_repeat('x', $pieces)], $values);
    }

    /**
     * As much filler as reaches the last octet of the first chunk, so that a
     * line ending written after it straddles the boundary.
     */
    private static function fillerToTheEndOfAChunk(): string
    {
        return str_repeat('a', Lexer::CHUNK - strlen('SUMMARY:') - 1);
    }

    /**
     * @param resource|string $source
     *
     * @return list<string>
     */
    private function valuesOf(mixed $source): array
    {
        return array_map(
            static fn (ContentLine $line): string => $line->value(),
            iterator_to_array((new Lexer($source))->lines(), false),
        );
    }

    /**
     * @param resource|string $source
     *
     * @return list<string>
     */
    private function namesOf(mixed $source): array
    {
        return array_map(
            static fn (ContentLine $line): string => $line->name(),
            iterator_to_array((new Lexer($source))->lines(), false),
        );
    }
}
