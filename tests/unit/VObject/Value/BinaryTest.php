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

use DavServices\VObject\ParseError;
use DavServices\VObject\Value\Binary;
use DavServices\VObject\Value\DataUri;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for `BINARY`, derived from RFC 5545 §3.3.1, and for the `data:`
 * URI of RFC 2397, which is how vCard 4.0 carries the same thing.
 *
 *     binary = *(4b-char) [b-end]
 *     ; A "BASE64" encoded character string, as defined by [RFC4648].
 *
 * §3.3.1: "all inline binary data MUST first be character encoded using the
 * 'BASE64' encoding method", and "No additional content value encoding (i.e.,
 * BACKSLASH character encoding …) is defined for this value type".
 *
 * ## Why the `data:` URI is here and has a class of its own
 *
 * **vCard 4.0 has no `BINARY` type at all.** RFC 6350 §4 lists nine value
 * types and that is not among them; a photograph is a `URI`, and RFC 6350's
 * own examples are all `data:` URIs:
 *
 *     PHOTO:data:image/jpeg;base64,MIICajCCAdOgAwIBAgICBEUwDQYJKoZIhv
 *
 * That form pairs a media type with the octets, which is more than `Binary`
 * or `Uri` can say on its own — and it is a third specification, RFC 2397:
 *
 *     dataurl := "data:" [ mediatype ] [ ";base64" ] "," data
 */
#[CoversClass(Binary::class)]
#[CoversClass(DataUri::class)]
final class BinaryTest extends TestCase
{
    private const OCTETS = "\x00\x01\xFE\xFFhello";

    /**
     * Base64 in, octets out — RFC 4648, by way of §3.3.1.
     */
    public function testReadsBase64(): void
    {
        self::assertSame('hello', Binary::decode('aGVsbG8='));
    }

    /**
     * And octets in, base64 out. Octets that are no text at all are the point
     * of the type.
     */
    public function testWritesBase64(): void
    {
        self::assertSame(self::OCTETS, Binary::decode(Binary::encode(self::OCTETS)));
    }

    /**
     * **`b-end` is the padding**, and the grammar has it: `(2b-char "==") /
     * (3b-char "=")`. Both forms are read.
     *
     * @param non-empty-string $raw
     */
    #[DataProvider('bothKindsOfPadding')]
    public function testBothKindsOfPaddingAreRead(string $raw, string $expected): void
    {
        self::assertSame($expected, Binary::decode($raw));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bothKindsOfPadding(): iterable
    {
        yield 'no padding at all' => ['YWJj', 'abc'];

        yield 'one equals' => ['YWJjZA==', 'abcd'];

        yield 'two equals' => ['YWJjZGU=', 'abcde'];
    }

    /**
     * **What is not base64 is refused**, rather than quietly decoded as far
     * as it goes: `b-char = ALPHA / DIGIT / "+" / "/"` and nothing else, so a
     * value with other characters in it is not the octets somebody meant.
     *
     * @param non-empty-string $raw
     */
    #[DataProvider('whatIsNoBase64')]
    public function testWhatIsNoBase64IsRefused(string $raw): void
    {
        $this->expectException(ParseError::class);

        Binary::decode($raw);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function whatIsNoBase64(): iterable
    {
        yield 'a character outside the alphabet' => ['aGVsbG8!'];

        yield 'a space in the middle' => ['aGVs bG8='];

        yield 'padding in the middle' => ['aG=sbG8='];

        yield 'a length that cannot be right' => ['aGVsbG'];
    }

    /**
     * Nothing at all is no octets, which is a value a property may carry.
     */
    public function testAnEmptyValueIsNoOctets(): void
    {
        self::assertSame('', Binary::decode(''));
    }

    /**
     * **RFC 6350's own example shape**: `data:image/jpeg;base64,<octets>`.
     */
    public function testReadsADataUri(): void
    {
        $uri = 'data:image/jpeg;base64,aGVsbG8=';

        self::assertSame('hello', DataUri::octetsIn($uri));
        self::assertSame('image/jpeg', DataUri::mediaTypeOf($uri));
    }

    /**
     * **RFC 2397 §2: the media type may be left out**, and then "If
     * <mediatype> is omitted, it defaults to text/plain;charset=US-ASCII".
     * Answering with an empty string instead would make every caller invent
     * that default for itself.
     */
    public function testAMissingMediaTypeIsTheDefaultOfTheSpecification(): void
    {
        self::assertSame('text/plain;charset=US-ASCII', DataUri::mediaTypeOf('data:;base64,aGVsbG8='));
    }

    /**
     * **Without `;base64` the data is not base64**, and RFC 2397 §2 says what
     * it is instead: "Without ';base64', the data (as a sequence of octets)
     * is represented using ASCII encoding for octets inside the range of safe
     * URL characters and using the standard %xx hex encoding of URLs for
     * octets outside that range."
     */
    public function testWithoutBase64TheDataIsPercentEncoded(): void
    {
        self::assertSame('a b', DataUri::octetsIn('data:text/plain,a%20b'));
    }

    /**
     * Writing one puts the three parts back in the order RFC 2397 gives them.
     */
    public function testWritesADataUri(): void
    {
        self::assertSame('data:image/png;base64,aGVsbG8=', DataUri::of('hello', 'image/png'));
    }

    /**
     * And what is written can be read back, octets and media type alike.
     */
    public function testWhatIsWrittenCanBeReadBack(): void
    {
        $uri = DataUri::of(self::OCTETS, 'application/octet-stream');

        self::assertSame(self::OCTETS, DataUri::octetsIn($uri));
        self::assertSame('application/octet-stream', DataUri::mediaTypeOf($uri));
    }

    /**
     * **Something that is no `data:` URI at all is refused.** A `PHOTO` that
     * turns out to be an `https:` URL is a perfectly good photograph — it is
     * simply not one this reads, and saying so is better than answering with
     * octets nobody put there.
     *
     * @param non-empty-string $uri
     */
    #[DataProvider('whatIsNoDataUri')]
    public function testWhatIsNoDataUriIsRefused(string $uri): void
    {
        $this->expectException(ParseError::class);

        DataUri::octetsIn($uri);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function whatIsNoDataUri(): iterable
    {
        yield 'another scheme entirely' => ['https://example.com/a.jpg'];

        yield 'the scheme with no comma after it' => ['data:image/jpeg;base64'];

        yield 'nothing at all' => ['x'];
    }

    /**
     * And asking for the media type of one of those is refused for the same
     * reason, rather than answering with the default of a URI that is not
     * there.
     */
    public function testTheMediaTypeOfSomethingElseIsRefused(): void
    {
        $this->expectException(ParseError::class);

        DataUri::mediaTypeOf('https://example.com/a.jpg');
    }
}
