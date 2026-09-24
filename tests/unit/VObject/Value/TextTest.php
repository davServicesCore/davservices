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

namespace DavServices\Tests\Unit\VObject\Value;

use DavServices\VObject\Value\Text;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 5545 §3.3.11 and RFC 6350 §3.4.
 *
 * **`TEXT` is the one value type with escaping**, and every other type says so
 * of itself: "No additional content value encoding (i.e., BACKSLASH character
 * encoding, see Section 3.3.11) is defined for this value type" appears under
 * `BINARY`, `BOOLEAN`, `FLOAT`, `INTEGER` and `URI` alike. That is why
 * {@see \DavServices\VObject\Property::value()} hands the value over raw and
 * the unescaping waits until here, where the type is known.
 *
 * ## The four escapes, and only those four
 *
 *     ESCAPED-CHAR = ("\\" / "\;" / "\," / "\N" / "\n")
 *       ; \\ encodes \, \N or \n encodes newline
 *       ; \; encodes ;, \, encodes ,
 *
 * RFC 6350 §3.4 closes the list in as many words: "In all other cases,
 * escaping MUST NOT be used." And RFC 5545 §3.3.11 singles out the one people
 * get wrong: "a COLON character in a 'TEXT' property value SHALL NOT be
 * escaped with a BACKSLASH character."
 *
 * ## Lists
 *
 * "If the property permits, multiple TEXT values are specified by a
 * COMMA-separated list of values" (§3.3.11) — *if the property permits*, so
 * whether a list is meant is the property's business and both readings are
 * offered here. An escaped comma is never a separator, which is the whole
 * reason it is escaped.
 */
#[CoversClass(Text::class)]
final class TextTest extends TestCase
{
    /**
     * Text with nothing special in it comes back as it went in.
     */
    public function testPlainTextIsUnchanged(): void
    {
        self::assertSame('Bastille Day Party', Text::decode('Bastille Day Party'));
    }

    /**
     * **RFC 5545 §3.3.11's own example**: "Project XYZ Final Review\nConference
     * Room - 3B\nCome Prepared." is the representation of three lines.
     */
    public function testTheExampleFromTheSpecification(): void
    {
        self::assertSame(
            "Project XYZ Final Review\nConference Room - 3B\nCome Prepared.",
            Text::decode('Project XYZ Final Review\nConference Room - 3B\nCome Prepared.'),
        );
    }

    /**
     * **The four escapes.** `\\` encodes a backslash, `\;` a semicolon, `\,`
     * a comma, and `\n` or `\N` a line break.
     *
     * @param non-empty-string $raw
     */
    #[DataProvider('theFourEscapes')]
    public function testEachEscapeIsUndone(string $raw, string $expected): void
    {
        self::assertSame($expected, Text::decode($raw));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function theFourEscapes(): iterable
    {
        yield 'a backslash' => ['a\\\\b', 'a\\b'];

        yield 'a semicolon' => ['a\\;b', 'a;b'];

        yield 'a comma' => ['a\\,b', 'a,b'];

        yield 'a small n is a line break' => ['a\\nb', "a\nb"];

        yield 'and a capital N is the same line break' => ['a\\Nb', "a\nb"];
    }

    /**
     * **A backslash before anything else keeps both characters.** Neither
     * specification defines such a sequence — RFC 6350 §3.4: "In all other
     * cases, escaping MUST NOT be used" — so there is nothing to undo, and
     * dropping the backslash would quietly change somebody's text.
     *
     * It is also what RFC 6868 §3 requires of the `^` escapes in the same
     * family of formats: "parsers MUST leave both the ^ and the following
     * character in place".
     */
    public function testABackslashBeforeAnythingElseKeepsBoth(): void
    {
        self::assertSame('a\\qb', Text::decode('a\\qb'));
    }

    /**
     * **RFC 5545 §3.3.11: "a COLON character … SHALL NOT be escaped."** So a
     * backslash before a colon is not an escape either, and both stay.
     */
    public function testAColonIsNotEscaped(): void
    {
        self::assertSame('a\\:b', Text::decode('a\\:b'));
    }

    /**
     * A backslash at the very end has nothing following it to pair with, so
     * it stays — the same rule read to its end.
     */
    public function testABackslashAtTheEndKeepsItself(): void
    {
        self::assertSame('a\\', Text::decode('a\\'));
    }

    /**
     * **An escaped backslash is not the start of the next escape.**
     * `\\n` is a backslash followed by the letter n, not a line break, and a
     * reader working left to right one character at a time is the only one
     * that gets this right.
     */
    public function testAnEscapedBackslashDoesNotSwallowWhatFollows(): void
    {
        self::assertSame('a\\nb', Text::decode('a\\\\nb'));
    }

    /**
     * Writing it puts the four back, and RFC 6350 §3.4 says which: a comma
     * "MUST be escaped … even for properties that don't allow multiple
     * instances (for consistency)", a backslash likewise, a semicolon in a
     * compound property, and a line break as `\n`.
     *
     * @param non-empty-string $expected
     */
    #[DataProvider('whatIsWrittenBack')]
    public function testWritingPutsTheEscapesBack(string $value, string $expected): void
    {
        self::assertSame($expected, Text::encode($value));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function whatIsWrittenBack(): iterable
    {
        yield 'a backslash' => ['a\\b', 'a\\\\b'];

        yield 'a comma' => ['a,b', 'a\\,b'];

        yield 'a semicolon' => ['a;b', 'a\\;b'];

        yield 'a line break' => ["a\nb", 'a\\nb'];

        yield 'a Windows line break is one break' => ["a\r\nb", 'a\\nb'];

        yield 'and a lone carriage return is too' => ["a\rb", 'a\\nb'];

        yield 'a colon is left alone' => ['a:b', 'a:b'];
    }

    /**
     * **What is written can be read back.** That is what R-VOBJ-05's round
     * trip comes to at the level of one value.
     */
    public function testWhatIsWrittenCanBeReadBack(): void
    {
        $value = "a\\b,c;d\ne:f";

        self::assertSame($value, Text::decode(Text::encode($value)));
    }

    /**
     * §3.3.11: "multiple TEXT values are specified by a COMMA-separated list
     * of values". RFC 6350 §4.1 shows it: `this is one value,this is another`.
     */
    public function testAListIsSplitOnCommas(): void
    {
        self::assertSame(['MEETING', 'PROJECT'], Text::decodeList('MEETING,PROJECT'));
    }

    /**
     * **An escaped comma is never a separator**, which is the whole reason it
     * is escaped. RFC 6350 §4.1's other example says so:
     * `this is a single value\, with a comma encoded`.
     */
    public function testAnEscapedCommaIsNotASeparator(): void
    {
        self::assertSame(
            ['this is a single value, with a comma encoded'],
            Text::decodeList('this is a single value\\, with a comma encoded'),
        );
    }

    /**
     * And an escaped backslash before a comma does not protect it: the
     * backslash is spoken for, so the comma separates after all.
     */
    public function testAnEscapedBackslashLeavesTheCommaSeparating(): void
    {
        self::assertSame(['a\\', 'b'], Text::decodeList('a\\\\,b'));
    }

    /**
     * **An escaped comma at the very end is still not a separator.** It is
     * the place a reader is most likely to get wrong, because there is
     * nothing after it to make the mistake obvious — the list would simply
     * come back one value longer than it is.
     */
    public function testAnEscapedCommaAtTheEndIsNotASeparator(): void
    {
        self::assertSame(['a,'], Text::decodeList('a\,'));
    }

    /**
     * And a trailing backslash that escapes nothing leaves the list as it
     * was, rather than swallowing the end of it.
     */
    public function testATrailingBackslashLeavesTheListAlone(): void
    {
        self::assertSame(['a\\'], Text::decodeList('a\\'));
    }

    /**
     * One value is a list of one, rather than nothing.
     */
    public function testOneValueIsAListOfOne(): void
    {
        self::assertSame(['MEETING'], Text::decodeList('MEETING'));
    }

    /**
     * An empty value is one empty value: `CATEGORIES:` says the list is
     * empty, and a reader answering with no values at all would lose the
     * difference between that and the property being absent.
     */
    public function testAnEmptyValueIsOneEmptyValue(): void
    {
        self::assertSame([''], Text::decodeList(''));
    }

    /**
     * Writing a list puts the commas between and escapes the ones inside.
     */
    public function testWritingAListSeparatesAndEscapes(): void
    {
        self::assertSame('a\\,b,c', Text::encodeList(['a,b', 'c']));
    }

    /**
     * And a list survives the round trip as a list.
     */
    public function testAListSurvivesTheRoundTrip(): void
    {
        $values = ['a,b', 'c;d', "e\nf", 'g\\h'];

        self::assertSame($values, Text::decodeList(Text::encodeList($values)));
    }
}
