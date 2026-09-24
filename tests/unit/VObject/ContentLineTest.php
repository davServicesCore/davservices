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
use DavServices\VObject\ParseError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 5545 §3.1, RFC 6350 §3.3 and RFC 6868 §3.
 *
 * **One line, read as syntax.** RFC 5545 §3.1 and RFC 6350 §3.3 give almost
 * the same grammar, and RFC 5545 says so itself: "the content information
 * consists of CRLF-separated content lines" formatted "using a syntax similar
 * to that defined by [RFC2425]". So there is one reader:
 *
 *     contentline = [group "."] name *(";" param) ":" value
 *
 * The group is vCard's alone (RFC 6350 §3.3); iCalendar has no such
 * construct. It is read for both all the same, because a name is
 * `1*(ALPHA / DIGIT / "-")` in either specification and therefore cannot hold
 * a full stop — so a line carrying one says something, and a reader that
 * dropped it would be deciding rather than reading.
 *
 * ## What is decoded, and what is left exactly as it was
 *
 * **Encodings are decoded**, because they are how a character got into the
 * line at all: the double quotes around a `quoted-string` are syntax rather
 * than content, and RFC 6868's `^` sequences are the only way a parameter
 * value can hold a `"` or a line break at all — `QSAFE-CHAR` excludes the
 * one and `CONTROL` the other.
 *
 * **Everything else is left as written.** Case above all: both
 * specifications make names case-insensitive, but that is a rule about
 * *comparing* them, and it belongs where comparing happens. A lexer that
 * changed the spelling would be a lexer you cannot use to see what the file
 * says — and the spelling comes back out again when the object is written.
 *
 * **The value is raw.** `\n`, `\,` and `\;` are the escaping of one value
 * type, `TEXT` (RFC 5545 §3.3.11), and a `URI` or an `INTEGER` has no
 * escaping at all. Undoing it here would corrupt every value that is not
 * text.
 */
#[CoversClass(ContentLine::class)]
final class ContentLineTest extends TestCase
{
    /**
     * The shape of it: `name ":" value`, which is every line at its
     * simplest.
     */
    public function testReadsANameAndAValue(): void
    {
        $line = ContentLine::of('SUMMARY:Lunch with Ada');

        self::assertSame('SUMMARY', $line->name());
        self::assertSame('Lunch with Ada', $line->value());
        self::assertSame([], $line->parameters());
        self::assertNull($line->group());
    }

    /**
     * **The value runs to the end of the line**, colons and all. Only the
     * first one separates: `value = *VALUE-CHAR`, and `VALUE-CHAR` is "any
     * textual character" (RFC 5545 §3.1).
     */
    public function testTheValueKeepsEveryColonAfterTheFirst(): void
    {
        self::assertSame('mailto:ada@example.com', ContentLine::of('ATTENDEE:mailto:ada@example.com')->value());
    }

    /**
     * An empty value is a value. `value = *VALUE-CHAR` allows none at all,
     * and a client that sends `SUMMARY:` has said the summary is empty
     * rather than said nothing.
     */
    public function testAnEmptyValueIsAValue(): void
    {
        self::assertSame('', ContentLine::of('SUMMARY:')->value());
    }

    /**
     * **The value is handed over raw.** `\n` and `\,` are the escaping of
     * the `TEXT` value type (RFC 5545 §3.3.11); a `URI`, an `INTEGER` or a
     * `DATE-TIME` has none, and undoing it here would corrupt all of them.
     */
    public function testTheValueIsNotUnescaped(): void
    {
        self::assertSame('one\\nand\\, two', ContentLine::of('SUMMARY:one\\nand\\, two')->value());
    }

    /**
     * `param = param-name "=" param-value *("," param-value)`.
     */
    public function testReadsAParameter(): void
    {
        $line = ContentLine::of('ATTENDEE;CN=Ada Lovelace:mailto:ada@example.com');

        self::assertSame('ATTENDEE', $line->name());
        self::assertSame(['CN' => ['Ada Lovelace']], $line->parameters());
        self::assertSame('mailto:ada@example.com', $line->value());
    }

    /**
     * `*(";" param)` — as many as the line carries.
     */
    public function testReadsSeveralParameters(): void
    {
        $line = ContentLine::of('ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED:mailto:ada@example.com');

        self::assertSame(
            ['ROLE' => ['REQ-PARTICIPANT'], 'PARTSTAT' => ['ACCEPTED']],
            $line->parameters(),
        );
    }

    /**
     * `*("," param-value)` — one parameter, several values.
     */
    public function testReadsSeveralValuesOfOneParameter(): void
    {
        $line = ContentLine::of('TEL;TYPE=work,voice,pref:+44 20 7123 4567');

        self::assertSame(['TYPE' => ['work', 'voice', 'pref']], $line->parameters());
    }

    /**
     * **A parameter with no value at all is kept as one.** vCard 2.1 writes
     * `TEL;HOME;VOICE:…`, and this library has to read 2.1 (R-VOBJ-01).
     * Turning it into `TYPE=HOME` would be the version conversion doing its
     * work in the wrong place — and guessing which parameter was meant. So
     * the name is what was written and the list of values is empty, which is
     * exactly what the line says.
     */
    public function testAParameterWithNoValueKeepsItsNameAndHasNone(): void
    {
        $line = ContentLine::of('TEL;HOME;VOICE:+44 20 7123 4567');

        self::assertSame(['HOME' => [], 'VOICE' => []], $line->parameters());
        self::assertSame('+44 20 7123 4567', $line->value());
    }

    /**
     * **A quoted parameter value is the text between the quotes.**
     * `param-value = paramtext / quoted-string` and
     * `quoted-string = DQUOTE *QSAFE-CHAR DQUOTE`, so the quotes are syntax.
     */
    public function testAQuotedParameterValueLosesItsQuotes(): void
    {
        $line = ContentLine::of('ATTENDEE;CN="Lovelace, Ada":mailto:ada@example.com');

        self::assertSame(['CN' => ['Lovelace, Ada']], $line->parameters());
    }

    /**
     * **And inside the quotes nothing separates.** `QSAFE-CHAR` is "any
     * character except CONTROL and DQUOTE", so a semicolon, a colon and a
     * comma are all ordinary text there — which is the whole reason quoting
     * exists.
     *
     * @param non-empty-string $line
     */
    #[DataProvider('charactersThatOnlySeparateOutsideQuotes')]
    public function testNothingSeparatesInsideQuotes(string $line, string $expected): void
    {
        self::assertSame(['X-WHERE' => [$expected]], ContentLine::of($line)->parameters());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function charactersThatOnlySeparateOutsideQuotes(): iterable
    {
        yield 'a colon' => ['GEO;X-WHERE="https://example.com/a":51.5,-0.1', 'https://example.com/a'];

        yield 'a semicolon' => ['GEO;X-WHERE="here; and there":51.5,-0.1', 'here; and there'];

        yield 'a comma' => ['GEO;X-WHERE="one, two":51.5,-0.1', 'one, two'];
    }

    /**
     * **RFC 6868 §3: `^n` is a line break.** Without it a parameter value
     * cannot hold one at all — RFC 5545 §3.1 puts every control character
     * except HTAB outside `VALUE-CHAR`.
     *
     * It is decoded to `\n` and not to whatever this machine writes at the
     * end of a line: the RFC says "an appropriate formatted line break
     * according to the type of system being used", and a protocol library
     * has no system of its own. A value that came from a file on one server
     * and is read on another has to come out the same both times.
     */
    public function testALineBreakInAParameterIsDecoded(): void
    {
        $line = ContentLine::of('GEO;X-ADDRESS=Pittsburgh Pirates^n115 Federal St:40.44,-80.0');

        self::assertSame(['X-ADDRESS' => ["Pittsburgh Pirates\n115 Federal St"]], $line->parameters());
    }

    /**
     * RFC 6868 §3: "the character sequence ^' (U+005E, U+0027) is decoded
     * into the " character". Its own example, word for word.
     */
    public function testAQuoteInAParameterIsDecoded(): void
    {
        $line = ContentLine::of("ATTENDEE;CN=George Herman ^'Babe^' Ruth:mailto:babe@example.com");

        self::assertSame(['CN' => ['George Herman "Babe" Ruth']], $line->parameters());
    }

    /**
     * RFC 6868 §3: "the character sequence ^^ (U+005E, U+005E) is decoded
     * into the ^ character".
     */
    public function testACircumflexInAParameterIsDecoded(): void
    {
        self::assertSame(['X-A' => ['2^3']], ContentLine::of('X-M;X-A=2^^3:x')->parameters());
    }

    /**
     * **RFC 6868 §3, and it is a MUST:** "if a ^ (U+005E) character is
     * followed by any character other than the ones above, parsers MUST
     * leave both the ^ and the following character in place". A value
     * written before that RFC existed keeps its meaning.
     */
    public function testACircumflexBeforeAnythingElseIsLeftAlone(): void
    {
        self::assertSame(['X-A' => ['2^5x']], ContentLine::of('X-M;X-A=2^5x:x')->parameters());
    }

    /**
     * And a `^` at the very end has nothing following it to join with, so it
     * stays too — the same rule read to its end.
     */
    public function testACircumflexAtTheEndIsLeftAlone(): void
    {
        self::assertSame(['X-A' => ['2^']], ContentLine::of('X-M;X-A=2^:x')->parameters());
    }

    /**
     * RFC 6868 §3: "The ^-escaping mechanism can be used when the value is
     * either unquoted or quoted (i.e., whether or not the value is
     * surrounded by double-quotes)."
     */
    public function testTheEscapingWorksInsideQuotesToo(): void
    {
        $line = ContentLine::of('GEO;X-ADDRESS="Pittsburgh^nPittsburgh":40.44,-80.0');

        self::assertSame(['X-ADDRESS' => ["Pittsburgh\nPittsburgh"]], $line->parameters());
    }

    /**
     * **A parameter value of one character is not a quoted string**, however
     * it is read: `quoted-string = DQUOTE *QSAFE-CHAR DQUOTE` is two
     * characters at least, so there is nothing to strip.
     */
    public function testAParameterValueOfOneCharacterKeepsIt(): void
    {
        self::assertSame(['X-A' => ['x']], ContentLine::of('X-M;X-A=x:v')->parameters());
    }

    /**
     * **And a value that only begins with a quote is not one either.** The
     * quotes come off a `quoted-string`, which is quoted at both ends;
     * `"a"b` is none, and what a client wrote is handed over as it wrote it
     * rather than half unwrapped.
     */
    public function testAValueThatOnlyBeginsWithAQuoteKeepsIt(): void
    {
        self::assertSame(['X-A' => ['"a"b']], ContentLine::of('X-M;X-A="a"b:v')->parameters());
    }

    /**
     * RFC 6350 §3.3: `contentline = [group "."] name …`. "The group
     * construct is used to group related properties together", and every
     * Apple address book in the world uses it.
     */
    public function testReadsAVCardGroup(): void
    {
        $line = ContentLine::of('item1.EMAIL;TYPE=work:ada@example.com');

        self::assertSame('item1', $line->group());
        self::assertSame('EMAIL', $line->name());
    }

    /**
     * **A full stop after the name is not a group.** The group comes before
     * the name and nowhere else, and a value is allowed to hold as many full
     * stops as it likes.
     */
    public function testAFullStopInTheValueIsNoGroup(): void
    {
        $line = ContentLine::of('URL:https://example.com/a.b.c');

        self::assertNull($line->group());
        self::assertSame('URL', $line->name());
    }

    /**
     * And neither is one inside a parameter value, for the same reason.
     */
    public function testAFullStopInAParameterIsNoGroup(): void
    {
        $line = ContentLine::of('ATTENDEE;CN=Ada A. Lovelace:mailto:ada@example.com');

        self::assertNull($line->group());
        self::assertSame(['CN' => ['Ada A. Lovelace']], $line->parameters());
    }

    /**
     * **The spelling is kept.** Both specifications make names
     * case-insensitive — RFC 5545 §3.1: "All names of properties, property
     * parameters … are case-insensitive" — but that is a rule about
     * comparing them, and comparing is not what a lexer does. RFC 6350 §3.3
     * even recommends upper case *on output*, which is the serialiser's
     * business (P4-05).
     */
    public function testTheSpellingOfEverythingIsKept(): void
    {
        $line = ContentLine::of('Item1.fn;X-Odd=MiXeD:Ada');

        self::assertSame('Item1', $line->group());
        self::assertSame('fn', $line->name());
        self::assertSame(['X-Odd' => ['MiXeD']], $line->parameters());
    }

    /**
     * A line with no colon is no content line: the grammar has one, and
     * there is nothing to read without it. What the strict and the lenient
     * mode then do with the refusal is R-VOBJ-03's question (P4-06); what is
     * certain is that nothing can be invented here.
     */
    public function testALineWithoutAColonIsRefused(): void
    {
        $this->expectException(ParseError::class);

        // The message is part of what is being tested. Read on without this
        // refusal, the line is turned away a few steps later for a different
        // reason — and an administrator reading the log would be told that a
        // quoted value was never closed, in a line holding no quotes at all.
        $this->expectExceptionMessage('after a colon');

        ContentLine::of('BEGIN VCALENDAR');
    }

    /**
     * And neither is a line with nothing in front of the colon: `name` is
     * `1*(ALPHA / DIGIT / "-")`, one character at least.
     */
    public function testALineWithNoNameIsRefused(): void
    {
        $this->expectException(ParseError::class);

        ContentLine::of(':nothing named this');
    }

    /**
     * A group with no name after it is the same mistake one step along.
     */
    public function testAGroupWithNoNameIsRefused(): void
    {
        $this->expectException(ParseError::class);

        ContentLine::of('item1.:nothing named this');
    }

    /**
     * And so is a full stop with no group in front of it: `group` is
     * `1*(ALPHA / DIGIT / "-")` as well, so `.EMAIL:` names a group of no
     * characters — and a property filed under a group nobody can name is a
     * property nobody can find again.
     */
    public function testAGroupWithNoNameOfItsOwnIsRefused(): void
    {
        $this->expectException(ParseError::class);

        ContentLine::of('.EMAIL:ada@example.com');
    }

    /**
     * **A parameter with no name is refused**, because `param-name` is
     * `iana-token / x-name` and both are one character at least — and a
     * value under no name could never be looked up again.
     */
    public function testAParameterWithNoNameIsRefused(): void
    {
        $this->expectException(ParseError::class);

        ContentLine::of('ATTENDEE;=nobody:mailto:ada@example.com');
    }

    /**
     * **An unterminated quoted string is refused.** Read leniently it would
     * swallow the colon and everything after it, so the property would come
     * out with no value and a parameter holding the whole line — a wrong
     * answer is worse here than none.
     */
    public function testAnUnterminatedQuotedValueIsRefused(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('never closed');

        ContentLine::of('ATTENDEE;CN="Ada Lovelace:mailto:ada@example.com');
    }

    /**
     * **The same parameter twice keeps both.** RFC 6350 §5 has parameters
     * that may appear more than once, and a reader that kept the last would
     * quietly drop half of what a client sent.
     */
    public function testTheSameParameterTwiceKeepsEveryValue(): void
    {
        $line = ContentLine::of('TEL;TYPE=work;TYPE=voice:+44 20 7123 4567');

        self::assertSame(['TYPE' => ['work', 'voice']], $line->parameters());
    }

    /**
     * And a parameter is found by name whatever its spelling, because that
     * is the one place the case-insensitivity of RFC 5545 §3.1 has to be
     * honoured — a caller asking for `VALUE` means the one written `value`.
     */
    public function testAParameterIsFoundWhateverItsSpelling(): void
    {
        $line = ContentLine::of('DTSTART;value=DATE:20260923');

        self::assertSame(['DATE'], $line->parameter('VALUE'));
        self::assertSame([], $line->parameter('TZID'));
    }

    /**
     * **Found with every value it has**, not with the first. `TYPE=work,voice`
     * is one parameter saying two things, and a caller told only the first
     * would put a work number where a mobile belongs.
     */
    public function testAParameterIsFoundWithEveryValueItHas(): void
    {
        self::assertSame(['work', 'voice'], ContentLine::of('TEL;TYPE=work,voice:+44 20 7123 4567')->parameter('TYPE'));
    }

    /**
     * **And written twice in two spellings, it is found once with both.**
     * The two halves of the rule meet here: the values are kept apart as
     * they were written, and the lookup gathers them because RFC 5545 §3.1
     * makes the names the same name.
     */
    public function testAParameterWrittenTwiceInTwoSpellingsIsFoundWithBoth(): void
    {
        $line = ContentLine::of('TEL;TYPE=work;type=voice:+44 20 7123 4567');

        self::assertSame(['work' => null, 'voice' => null], array_fill_keys($line->parameter('TYPE'), null));
        self::assertSame(['TYPE' => ['work'], 'type' => ['voice']], $line->parameters(), 'and both spellings are kept');
    }

    /**
     * **An empty quoted value is an empty value.** `quoted-string` is
     * `DQUOTE *QSAFE-CHAR DQUOTE`, and none at all is what the star allows —
     * so `""` is the only way to say "this parameter is present and says
     * nothing", which is different from the parameter being absent.
     */
    public function testAnEmptyQuotedValueIsAnEmptyValue(): void
    {
        self::assertSame(['X-A' => ['']], ContentLine::of('X-M;X-A="":v')->parameters());
    }

    /**
     * **Quotes come off only when they wrap the whole value.** `a"b"` is not
     * a `quoted-string` — `paramtext` excludes DQUOTE, so it is malformed
     * either way — and a reader that stripped the tail would hand over `"b`,
     * which is neither what was written nor anything else.
     */
    public function testQuotesComeOffOnlyWhenTheyWrapTheWholeValue(): void
    {
        self::assertSame(['X-A' => ['a"b"']], ContentLine::of('X-M;X-A=a"b":v')->parameters());
    }
}
