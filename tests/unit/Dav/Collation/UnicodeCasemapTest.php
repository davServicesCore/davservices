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

namespace DavServices\Tests\Unit\Dav\Collation;

use DavServices\Dav\Collation\Collation;
use DavServices\Dav\Collation\Decompositions;
use DavServices\Dav\Collation\UnicodeCasemap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The contract, run against `i;unicode-casemap`, plus the four steps RFC 5051
 * spells out.
 *
 * This is the collation CardDAV defaults to — §8.3: "In the absence of a
 * collation explicitly specified by the client, or if the client specifies the
 * 'default' collation identifier […] the server MUST default to using
 * 'i;unicode-casemap' as the collation" — so it is the one that decides what a
 * search means in practice.
 *
 * ## The preparation, in the memo's own order
 *
 * Each input string is turned into a "titlecased canonicalized UTF-8" string:
 *
 * 1. read a codepoint; an invalid sequence stops everything;
 * 2. (a) replace it by its **titlecase** property, if it has one, then
 *    (b) replace the result by its **decomposition** property of any type,
 *    recursively, "effectively Normalization Form KD";
 * 3. append in UTF-8;
 * 4. repeat.
 *
 * "Following the above preparation process on each string, the equality,
 * ordering, and substring operations are as for i;octet."
 *
 * ## And the step that is easy to miss
 *
 * Step (1)(b): "If the input string is in an unknown charset, or an invalid
 * sequence occurs in step (1)(a), conversion ceases. **No further preparation
 * is performed, and any partial preparation results are discarded. The
 * original string is used unchanged with the i;octet comparator.**" So a
 * string that is not valid UTF-8 is not refused and not repaired — it is
 * compared as bytes.
 */
#[CoversClass(UnicodeCasemap::class)]
#[CoversClass(Decompositions::class)]
final class UnicodeCasemapTest extends CollationContract
{
    /**
     * **The worked example from the memo itself.**
     *
     * > Example: codepoint U+01C4 (LATIN CAPITAL LETTER DZ WITH CARON) has a
     * > titlecase property of U+01C5 […] Therefore, U+01C4 is converted to
     * > U+0044 U+007A U+030C by this step.
     *
     * Three codepoints that the memo says must come out the same way, and they
     * are the sharpest test there is: U+01C4's titlecase differs from its
     * uppercase, so a collation that reached for uppercase would fail here and
     * nowhere else in Latin.
     */
    public function testTheWorkedExampleFromTheMemo(): void
    {
        $collation = $this->collation();

        // All three forms of the digraph titlecase to U+01C5 and decompose to
        // the same three codepoints, so they agree with one another.
        self::assertTrue($collation->equals("\u{01C4}", "\u{01C5}"));
        self::assertTrue($collation->equals("\u{01C5}", "\u{01C6}"));
        self::assertTrue($collation->equals("\u{01C4}", "\u{01C6}"));
    }

    /**
     * **And here is what that example costs**, measured rather than assumed.
     *
     * The memo's own worked answer for U+01C4 is U+0044 U+007A U+030C — a
     * capital D, a **small** z, and a caron. The small z is there because step
     * (2)(a) titlecases the *input* codepoint and step (2)(b) then decomposes
     * the result: nothing goes back and folds what the decomposition brought
     * in. So the digraph matches no spelling of itself written out, because
     * any `z` a client types is titlecased to `Z` first.
     *
     * This is the algorithm doing what it says, and §4 says to expect it: "The
     * results from this comparator will vary depending upon the implementation
     * […] Implementations MUST consider whether these possibilities are a
     * problem for their use case." It is written down here so that nobody
     * later takes it for a defect and "fixes" it into a disagreement with
     * every other implementation.
     */
    public function testWhatTheStepOrderCostsIsWrittenDownRatherThanFixed(): void
    {
        $collation = $this->collation();

        // U+01C4 prepares to D, small z, caron. "D" followed by U+017E (ž)
        // prepares to D, capital Z, caron — because ž is titlecased before it
        // decomposes.
        self::assertFalse($collation->equals("\u{01C4}", "\u{0044}\u{017E}"));

        // The same shape with a ligature: U+FB01 has no titlecase property, so
        // it decomposes to a small f and a small i, while `fi` typed by a
        // client becomes `FI`.
        self::assertTrue($collation->equals("\u{fb01}", "\u{fb01}"));
        self::assertFalse($collation->equals("\u{fb01}", 'fi'));
        self::assertFalse($collation->equals("\u{fb01}", 'FI'));
    }

    /**
     * **Case is folded far beyond ASCII**, which is the whole reason this
     * collation exists. RFC 5051 §1: "the 'i;ascii-casemap' collation does not
     * produce satisfactory results with non-ASCII characters."
     *
     * @param non-empty-string $one
     * @param non-empty-string $other
     */
    #[DataProvider('pairsThatMatchAcrossCase')]
    public function testCaseIsFoldedFarBeyondAscii(string $one, string $other): void
    {
        self::assertTrue($this->collation()->equals($one, $other), $one . ' / ' . $other);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pairsThatMatchAcrossCase(): iterable
    {
        yield 'ASCII' => ['simon', 'SIMON'];

        yield 'Latin with a diaeresis' => ["\u{00fc}ber", "\u{00dc}BER"];

        yield 'Greek' => ["\u{03c3}", "\u{03a3}"];

        yield 'Cyrillic' => ["\u{0434}", "\u{0414}"];

        yield 'a letter and its decomposition' => ["\u{00fc}", "u\u{0308}"];

        yield 'a decomposed capital and a composed small' => ["U\u{0308}", "\u{00fc}"];
    }

    /**
     * **It collates characters with diacriticals beside their plain forms**,
     * which §1 names as the second thing it adds — but only as far as the
     * decomposition goes: the mark is kept, so `u` alone is still not `ü`.
     * It is the *prefix* of it, and a `starts-with` search finds it.
     */
    public function testADiacriticalIsKeptButSeparated(): void
    {
        $collation = $this->collation();

        self::assertFalse($collation->equals('u', "\u{00fc}"));
        self::assertTrue($collation->startsWith('u', "\u{00fc}"));
        self::assertTrue($collation->contains("\u{0308}", "\u{00fc}"));
    }

    /**
     * **A compatibility decomposition is applied too**, because step (2)(b)
     * asks for "a decomposition property of **any** type" — not only the
     * canonical ones. So the `fi` ligature is `fi`, and a search for `fi`
     * finds a word typeset with one.
     */
    public function testACompatibilityDecompositionIsAppliedToo(): void
    {
        $collation = $this->collation();

        // The OHM SIGN is a singleton compatibility decomposition to GREEK
        // CAPITAL LETTER OMEGA, and neither has a titlecase property — so the
        // two agree, which is the case this step is really for.
        self::assertTrue($collation->equals("\u{2126}", "\u{03a9}"));

        // A fullwidth letter is the plain letter, either way round.
        self::assertTrue($collation->equals("\u{ff21}", 'A'));
        self::assertTrue($collation->equals("\u{ff41}", 'a'));

        // And a superscript two is a two.
        self::assertTrue($collation->equals("\u{00b2}", '2'));
    }

    /**
     * **Hangul is decomposed by the algorithm**, not from the table: Unicode
     * gives its eleven thousand syllables no decomposition property, because
     * theirs is arithmetic. A collation that only read the table would leave
     * Korean uncollated and never notice.
     */
    public function testHangulIsDecomposedByTheAlgorithm(): void
    {
        $collation = $this->collation();

        // U+AC01 (각) is U+1100 (ᄀ) U+1161 (ᅡ) U+11A8 (ᆨ): lead, vowel, tail.
        self::assertTrue($collation->equals("\u{ac01}", "\u{1100}\u{1161}\u{11a8}"));

        // U+AC00 (가) has no tail, so two jamo and not three.
        self::assertTrue($collation->equals("\u{ac00}", "\u{1100}\u{1161}"));
        self::assertFalse($collation->equals("\u{ac00}", "\u{1100}\u{1161}\u{11a8}"));

        // And the syllable is found inside a word built from syllables.
        self::assertTrue($collation->contains("\u{ac00}", "\u{ac00}\u{b098}"));
    }

    /**
     * **Every part of the arithmetic is exercised, not just the first
     * syllable.**
     *
     * A mutation run earned this test: the syllables above all have a lead
     * index and a vowel index of zero, so `LEAD_BASE + 0` and `LEAD_BASE - 0`
     * are the same answer, and `%` against `intdiv` cannot be told apart
     * either. U+D7A3 is the **last** syllable of the block — lead 18, vowel 20,
     * tail 27 — so nothing in the formula can be changed without this failing.
     *
     * The expected jamo were worked out from the Unicode standard's own
     * algorithm, written separately, rather than read back out of the code.
     *
     * @param non-empty-string $syllable
     * @param non-empty-string $jamo
     */
    #[DataProvider('syllablesAcrossTheBlock')]
    public function testEveryPartOfTheHangulArithmeticIsExercised(string $syllable, string $jamo): void
    {
        self::assertTrue($this->collation()->equals($syllable, $jamo), bin2hex($syllable));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function syllablesAcrossTheBlock(): iterable
    {
        yield 'the first syllable' => ["\u{ac00}", "\u{1100}\u{1161}"];

        yield 'the first with a tail' => ["\u{ac01}", "\u{1100}\u{1161}\u{11a8}"];

        yield 'a second lead consonant' => ["\u{b098}", "\u{1102}\u{1161}"];

        yield 'a twelfth lead consonant' => ["\u{c544}", "\u{110b}\u{1161}"];

        yield 'the last syllable of the block' => ["\u{d7a3}", "\u{1112}\u{1175}\u{11c2}"];
    }

    /**
     * **And the block ends where Unicode says it does.**
     *
     * U+D7A3 is the last syllable; U+D7A4 is not one at all and must be left
     * alone. A mutation run showed `>=` and `>` were indistinguishable without
     * this, which would have made one codepoint past the block decompose into
     * nonsense.
     */
    public function testTheHangulBlockEndsWhereUnicodeSaysItDoes(): void
    {
        $collation = $this->collation();

        // Just past the block: no jamo, and equal only to itself.
        self::assertTrue($collation->equals("\u{d7a4}", "\u{d7a4}"));

        // **The assertion that bites.** Carried one index too far, the formula
        // gives U+D7A4 a lead of U+1113 and a vowel of U+1161 — a jamo pair
        // for a codepoint that is not a syllable. Comparing against something
        // else entirely would pass either way; this is the pair that only
        // appears if the boundary is wrong.
        self::assertFalse($collation->equals("\u{d7a4}", "\u{1113}\u{1161}"));

        // And just before it, likewise untouched.
        self::assertTrue($collation->equals("\u{abff}", "\u{abff}"));
    }

    /**
     * **A string that is not valid UTF-8 is compared as bytes**, which is step
     * (1)(b): "any partial preparation results are discarded. The original
     * string is used unchanged with the i;octet comparator."
     *
     * Not refused, not repaired. A vCard that arrived with a broken sequence
     * is still searchable, and it matches itself.
     */
    public function testAStringThatIsNotValidUtf8IsComparedAsBytes(): void
    {
        $collation = $this->collation();
        $broken = "Sim\xc3on";

        self::assertTrue($collation->equals($broken, $broken));
        self::assertFalse($collation->equals($broken, 'SIMON'));

        // Lower case is not folded either, because no preparation happened.
        self::assertFalse($collation->equals("sim\xc3on", "SIM\xc3ON"));
    }

    /**
     * **An overlong sequence is invalid**, and the memo says so in as many
     * words: "the UTF-8 sequence must be valid according to the rules of
     * [UTF-8]; e.g., overlong UTF-8 sequences are invalid." An
     * implementation that accepted `C0 AF` as a slash would let a client
     * smuggle one past a comparison.
     *
     * @param non-empty-string $bytes
     */
    #[DataProvider('sequencesThatAreNotUtf8')]
    public function testASequenceThatIsNotUtf8FallsThroughToOctets(string $bytes): void
    {
        $collation = $this->collation();

        // Compared as bytes: equal to itself, and not folded.
        self::assertTrue($collation->equals($bytes, $bytes));
        self::assertTrue($collation->contains($bytes, $bytes));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sequencesThatAreNotUtf8(): iterable
    {
        yield 'an overlong slash' => ["\xc0\xaf"];

        yield 'an overlong NUL' => ["\xc0\x80"];

        yield 'a lone continuation byte' => ["\x80"];

        yield 'a truncated sequence' => ["\xc3"];

        yield 'half of a surrogate pair' => ["\xed\xa0\x80"];

        yield 'a codepoint past the end of Unicode' => ["\xf4\x90\x80\x80"];
    }

    /**
     * **A substring is decided on what was prepared, not on the input.**
     *
     * RFC 4790 §4.2.3 defines substring through equality, and this collation
     * changes the length of what it compares — `ü` becomes two codepoints. A
     * collation that searched the raw input for the prepared needle would miss
     * this, which is why the comparing lives in one place for all three.
     */
    public function testASubstringIsDecidedOnWhatWasPrepared(): void
    {
        $collation = $this->collation();

        self::assertTrue($collation->contains("u\u{0308}b", "\u{00fc}ber"));
        self::assertTrue($collation->contains("\u{00fc}b", "U\u{0308}BER"));
    }

    /**
     * **ß is left alone**, and that is the memo's doing rather than an
     * oversight: step (2) reads UnicodeData.txt, where U+00DF has neither a
     * titlecase nor a decomposition property. A library routine that folded it
     * to `SS` — as full uppercasing does — would make `Straße` match
     * `STRASSE` and disagree with every other implementation of this
     * collation.
     */
    public function testTheSharpSIsLeftAlone(): void
    {
        $collation = $this->collation();

        self::assertFalse($collation->equals("Stra\u{00df}e", 'STRASSE'));
        self::assertTrue($collation->equals("stra\u{00df}e", "STRA\u{00df}E"));
    }

    protected function collation(): Collation
    {
        return new UnicodeCasemap();
    }

    protected function identifier(): string
    {
        return 'i;unicode-casemap';
    }
}
