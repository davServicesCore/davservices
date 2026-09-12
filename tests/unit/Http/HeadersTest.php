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

namespace DavServices\Tests\Unit\Http;

use Closure;
use DavServices\Http\Headers;
use DavServices\Http\MalformedHeader;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-HTTP-04: access regardless of case, and repeated
 * fields kept rather than collapsed.
 *
 * Reading: the empty collection, case-insensitive lookup, the first of several
 * values, all values in order, and the spelling a field was given in.
 *
 * Writing: replacing, appending, removing — each leaving the collection it was
 * called on untouched, because the same collection is handed to every plugin.
 *
 * Refusing: a field name that is not a token (RFC 9110 §5.1), and a value
 * carrying a control character, which is how a response gets a header nobody
 * wrote (RFC 9110 §5.5).
 */
#[CoversClass(Headers::class)]
#[CoversClass(MalformedHeader::class)]
final class HeadersTest extends TestCase
{
    public function testTheEmptyCollectionKnowsNothing(): void
    {
        $headers = new Headers();

        self::assertFalse($headers->has('Content-Type'));
        self::assertNull($headers->first('Content-Type'));
        self::assertSame([], $headers->all('Content-Type'));
        self::assertSame([], $headers->toArray());
    }

    #[DataProvider('spellings')]
    public function testFindsAFieldWhateverTheCase(string $lookup): void
    {
        $headers = new Headers(['Content-Type' => 'text/xml']);

        self::assertTrue($headers->has($lookup));
        self::assertSame('text/xml', $headers->first($lookup));
        self::assertSame(['text/xml'], $headers->all($lookup));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function spellings(): iterable
    {
        yield 'as it was given' => ['Content-Type'];
        yield 'in lower case' => ['content-type'];
        yield 'in upper case' => ['CONTENT-TYPE'];
        yield 'mixed' => ['CoNtEnT-tYpE'];
    }

    /**
     * A client that sends `DAV: 1` and `DAV: 3` means both, and a response
     * carrying two `WWW-Authenticate` fields offers two schemes. Collapsing
     * either into one value would change the meaning.
     */
    public function testKeepsRepeatedFields(): void
    {
        $headers = new Headers(['WWW-Authenticate' => ['Basic realm="dav"', 'Digest realm="dav"']]);

        self::assertSame('Basic realm="dav"', $headers->first('WWW-Authenticate'));
        self::assertSame(['Basic realm="dav"', 'Digest realm="dav"'], $headers->all('WWW-Authenticate'));
    }

    /**
     * The same field arriving under two spellings is one field. Merging on the
     * way in means nothing downstream has to look twice.
     */
    public function testMergesFieldsThatDifferOnlyInCase(): void
    {
        $headers = new Headers(['Accept' => 'text/xml', 'ACCEPT' => 'text/calendar']);

        self::assertSame(['text/xml', 'text/calendar'], $headers->all('accept'));
        self::assertSame(['Accept' => ['text/xml', 'text/calendar']], $headers->toArray());
    }

    public function testKeepsTheSpellingAndTheOrderOfTheFields(): void
    {
        $headers = new Headers(['Depth' => '1', 'Content-Type' => 'text/xml', 'Brief' => 't']);

        self::assertSame(
            ['Depth' => ['1'], 'Content-Type' => ['text/xml'], 'Brief' => ['t']],
            $headers->toArray(),
        );
    }

    public function testReplacesEveryValueOfAField(): void
    {
        $headers = (new Headers(['Depth' => ['0', '1']]))->with('Depth', 'infinity');

        self::assertSame(['infinity'], $headers->all('Depth'));
    }

    public function testReplacesRegardlessOfTheCaseItWasStoredUnder(): void
    {
        $headers = (new Headers(['Content-Type' => 'text/xml']))->with('CONTENT-TYPE', 'text/calendar');

        self::assertSame(['CONTENT-TYPE' => ['text/calendar']], $headers->toArray());
    }

    public function testReplacesWithSeveralValuesAtOnce(): void
    {
        $headers = (new Headers())->with('DAV', '1', '3', 'access-control');

        self::assertSame(['1', '3', 'access-control'], $headers->all('DAV'));
    }

    public function testAppendsToAFieldThatIsAlreadyThere(): void
    {
        $headers = (new Headers(['DAV' => '1']))->withAdded('dav', '3');

        self::assertSame(['1', '3'], $headers->all('DAV'));
    }

    /**
     * Appending must not rename the field: a response that started with
     * `WWW-Authenticate` should not suddenly read `www-authenticate` because
     * a second scheme was added under a different spelling.
     */
    public function testAppendingKeepsTheSpellingTheFieldArrivedWith(): void
    {
        $headers = (new Headers(['DAV' => '1']))->withAdded('dav', '3');

        self::assertSame(['DAV' => ['1', '3']], $headers->toArray());
    }

    /**
     * The field has to be found under the case it was stored with rather than
     * the one the caller happens to type. Otherwise appending quietly adds a
     * second field instead of a second value, and the message goes out with
     * both — which no client reads the way the server meant it.
     */
    public function testAppendsToTheFieldWhateverCaseItWasStoredUnder(): void
    {
        $headers = (new Headers(['dav' => '1']))->withAdded('DAV', '3');

        self::assertSame(['dav' => ['1', '3']], $headers->toArray());
    }

    public function testAppendingCreatesAFieldThatIsNotThereYet(): void
    {
        $headers = (new Headers())->withAdded('ETag', '"abc"');

        self::assertSame(['ETag' => ['"abc"']], $headers->toArray());
    }

    public function testRemovesAFieldRegardlessOfCase(): void
    {
        $headers = (new Headers(['Depth' => '1', 'Brief' => 't']))->without('DEPTH');

        self::assertFalse($headers->has('Depth'));
        self::assertSame(['Brief' => ['t']], $headers->toArray());
    }

    public function testRemovingAFieldThatIsNotThereChangesNothing(): void
    {
        $headers = new Headers(['Depth' => '1']);

        self::assertSame($headers->toArray(), $headers->without('Overwrite')->toArray());
    }

    /**
     * The collection is handed to every plugin in turn. An immutable one
     * cannot be changed behind the back of whoever holds it, which is why
     * each of these returns a new collection instead of altering this one.
     */
    /**
     * @param Closure(Headers): Headers $write
     */
    #[DataProvider('writes')]
    public function testWritingLeavesTheOriginalUntouched(Closure $write): void
    {
        $headers = new Headers(['Depth' => '1']);

        $written = $write($headers);

        self::assertSame(['Depth' => ['1']], $headers->toArray());
        self::assertNotSame($headers->toArray(), $written->toArray());
    }

    /**
     * @return iterable<string, array{Closure(Headers): Headers}>
     */
    public static function writes(): iterable
    {
        yield 'replacing' => [static fn (Headers $headers): Headers => $headers->with('Depth', 'infinity')];
        yield 'appending' => [static fn (Headers $headers): Headers => $headers->withAdded('Depth', '0')];
        yield 'adding another field' => [static fn (Headers $headers): Headers => $headers->with('Overwrite', 'F')];
    }

    /**
     * RFC 9110 §5.5: the surrounding whitespace is not part of the value.
     * Clients pad it, and a padded ETag would never match.
     */
    public function testTrimsTheWhitespaceAroundAValue(): void
    {
        $headers = new Headers(['ETag' => "  \t\"abc\" \t "]);

        self::assertSame('"abc"', $headers->first('ETag'));
    }

    public function testAcceptsAnEmptyValue(): void
    {
        $headers = new Headers(['Brief' => '']);

        self::assertTrue($headers->has('Brief'));
        self::assertSame('', $headers->first('Brief'));
    }

    public function testAcceptsATabInsideAValue(): void
    {
        $headers = new Headers(['X-Note' => "one\ttwo"]);

        self::assertSame("one\ttwo", $headers->first('X-Note'));
    }

    /**
     * A carriage return or a line feed in a value ends the field and starts
     * another one — the whole of header injection in one character. Refusing
     * is the only answer that cannot be got around.
     */
    #[DataProvider('rejectedValues')]
    public function testRefusesAValueThatCouldForgeAField(string $value): void
    {
        $this->expectException(MalformedHeader::class);

        new Headers(['X-Note' => $value]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedValues(): iterable
    {
        yield 'a carriage return' => ["one\rtwo"];
        yield 'a line feed' => ["one\ntwo"];
        yield 'a folded continuation' => ["one\r\n two"];
        yield 'a nul byte' => ["one\x00two"];
        yield 'another control character' => ["one\x07two"];
        yield 'a delete character' => ["one\x7Ftwo"];
    }

    #[DataProvider('rejectedNames')]
    public function testRefusesAFieldNameThatIsNotAToken(string $name): void
    {
        $this->expectException(MalformedHeader::class);

        new Headers([$name => 'x']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedNames(): iterable
    {
        yield 'empty' => [''];
        yield 'with a space' => ['Content Type'];
        yield 'with a colon' => ['Content:Type'];
        yield 'with a line feed' => ["Content\nType"];
        yield 'with a non-ascii letter' => ['Übergabe'];
        yield 'with a quote' => ['"Depth"'];
    }

    /**
     * The token characters of RFC 9110 §5.1 are wider than they look, and a
     * field the library refuses is a field a client cannot use.
     */
    public function testAcceptsEveryTokenCharacterInAName(): void
    {
        $name = "!#$%&'*+-.^_`|~0123456789abcXYZ";

        self::assertSame([$name => ['x']], (new Headers([$name => 'x']))->toArray());
    }

    /**
     * @param Closure(Headers): Headers $write
     */
    #[DataProvider('writeMethods')]
    public function testRefusesTheSameThingWhenWriting(Closure $write): void
    {
        $this->expectException(MalformedHeader::class);

        $write(new Headers());
    }

    /**
     * @return iterable<string, array{Closure(Headers): Headers}>
     */
    public static function writeMethods(): iterable
    {
        yield 'replacing' => [static fn (Headers $headers): Headers => $headers->with('X-Note', "one\r\nTwo: forged")];
        yield 'appending' => [static fn (Headers $headers): Headers => $headers->withAdded('X-Note', "one\r\nTwo: forged")];
    }

    /**
     * A caller that has never heard of this library still catches the SPL type.
     */
    public function testTheErrorIsAnInvalidArgumentException(): void
    {
        self::assertInstanceOf(InvalidArgumentException::class, new MalformedHeader('boom'));
    }

    /**
     * The message names what was refused, because a log entry that only says
     * "malformed header" leaves the reader guessing which one.
     */
    public function testTheErrorNamesTheFieldItRefused(): void
    {
        $this->expectExceptionMessageMatches('/X-Note/');

        (new Headers())->with('X-Note', "one\rtwo");
    }
}
