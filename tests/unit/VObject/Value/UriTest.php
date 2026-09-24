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

use DavServices\VObject\Value\LanguageTag;
use DavServices\VObject\Value\Uri;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for `URI` and `LANGUAGE-TAG`, derived from RFC 5545 §3.3.13 and
 * RFC 6350 §4.2 and §4.8.
 *
 * **These two carry their value through untouched, and that is the whole
 * point of having them.** §3.3.13 says it in the sentence that matters here:
 * "No additional content value encoding (i.e., BACKSLASH character encoding,
 * see Section 3.3.11) is defined for this value type."
 *
 * A URI is full of the characters `TEXT` escapes — `;` and `,` appear in
 * nearly every `data:` URI and in plenty of ordinary ones — so a writer that
 * reached for the `TEXT` rules would corrupt every one of them. Saying so in
 * a class, with a test, is what stops that happening when P4-05 comes to
 * dispatch by type.
 *
 * **What they do not do is check the syntax.** §3.3.13: "Property values with
 * this value type MUST follow the generic URI syntax defined in [RFC3986]" —
 * a MUST about the value, which makes it a question for the validation of
 * P4-06 rather than for reading. The same goes for RFC 6350 §4.8's "a single
 * language tag, as defined in [RFC5646]".
 */
#[CoversClass(Uri::class)]
#[CoversClass(LanguageTag::class)]
final class UriTest extends TestCase
{
    /**
     * RFC 5545 §3.3.13's own example, and RFC 6350 §4.2's.
     *
     * @param non-empty-string $raw
     */
    #[DataProvider('theExamplesFromTheSpecifications')]
    public function testAUriIsCarriedThroughUntouched(string $raw): void
    {
        self::assertSame($raw, Uri::decode($raw));
        self::assertSame($raw, Uri::encode($raw));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function theExamplesFromTheSpecifications(): iterable
    {
        yield 'RFC 5545 §3.3.13' => ['http://example.com/my-report.txt'];

        yield 'RFC 6350 §4.2' => ['http://www.example.com/my/picture.jpg'];

        yield 'and its second, with a percent escape' => ['ldap://ldap.example.com/cn=babs%20jensen'];
    }

    /**
     * **The characters `TEXT` would escape are left alone**, which is the one
     * thing that could go wrong here and the reason this class exists. A
     * `data:` URI carries a semicolon and a comma in its own syntax
     * (RFC 2397), and a mailto with a subject carries both again.
     *
     * @param non-empty-string $raw
     */
    #[DataProvider('uriesFullOfCharactersTextWouldEscape')]
    public function testNothingIsEscaped(string $raw): void
    {
        self::assertSame($raw, Uri::encode($raw));
        self::assertSame($raw, Uri::decode($raw));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function uriesFullOfCharactersTextWouldEscape(): iterable
    {
        yield 'a data URI, semicolon and comma and all' => ['data:image/jpeg;base64,AAAA'];

        yield 'a mailto with parameters' => ['mailto:ada@example.com?subject=a,b;c'];

        yield 'a path with a backslash' => ['http://example.com/a\\b'];
    }

    /**
     * **A malformed URI is carried through too.** Whether it follows RFC 3986
     * is a MUST about the value, and answering that is the validation of
     * P4-06 — reading it is not where a judgement belongs, and a reader that
     * refused it would take the evidence away from whoever has to repair the
     * file.
     */
    public function testSomethingThatIsNoUriIsCarriedThroughAsWell(): void
    {
        self::assertSame('not a uri at all', Uri::decode('not a uri at all'));
    }

    /**
     * RFC 6350 §4.8: "a single language tag, as defined in [RFC5646]" — and
     * the same reasoning throughout: carried through, not judged.
     */
    public function testALanguageTagIsCarriedThroughUntouched(): void
    {
        self::assertSame('de-CH-1901', LanguageTag::decode('de-CH-1901'));
        self::assertSame('de-CH-1901', LanguageTag::encode('de-CH-1901'));
    }

    /**
     * **And its case is kept.** RFC 5646 §2.1.1 is explicit that "the tags
     * and their subtags … are to be treated as case-insensitive", so the
     * spelling carries no meaning — which is exactly why changing it would be
     * a change to somebody's file for nothing.
     */
    public function testTheSpellingOfALanguageTagIsKept(): void
    {
        self::assertSame('DE-ch', LanguageTag::decode('DE-ch'));
    }
}
