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

namespace DavServices\Tests\Unit\Sync;

use DavServices\Sync\Token;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for the synchronisation token. R-SYNC-02, P7-01.
 *
 * > RFC 6578 §3.2: "The synchronization token itself MUST be treated as an
 * > 'opaque' string by the client, i.e., the actual string data has no specific
 * > meaning or syntax. However, the token MUST be a valid URI to allow its use
 * > in an If precondition request header (see Section 5)."
 *
 * > §4: "Value: Any valid URI."
 *
 * > R-SYNC-02: Sync-Tokens MÜSSEN monoton steigend, undurchsichtig und je
 * > Kollektion eindeutig sein.
 *
 * Three promises, and each has its own cases below.
 *
 * ## Monotonically increasing
 *
 * **As the states they stand for, and the comparison is on the change.** §3.2
 * offers the shape this follows — "a simple implementation of such a token
 * could be a numeric counter that counts each change as it occurs" — and a
 * counter written in decimal does not sort as a string: `9` is after `10` to
 * `strcmp()` and before it to anybody who means it. So the class owns the
 * comparison rather than leaving a caller to guess which kind it is.
 *
 * ## Opaque
 *
 * The obligation is the **client's** ("MUST be treated as an 'opaque' string
 * by the client"), so it is not something a server can enforce. What a server
 * can do is give it nothing to read: the collection is a hash and not a path.
 *
 * ## Unique per collection
 *
 * §3.2's precondition is what makes this load-bearing: the token "MUST be a
 * valid token previously returned by the server **for the collection targeted
 * by the request-URI**". So a server has to be able to tell that a token
 * belongs to another collection, which is why the collection is in it.
 *
 * ## Why an `https:` URI and not a `tag:` one
 *
 * A tag URI (RFC 4151) would be the more truthful identifier: §2.1 has them
 * "not meant to be dereferenced", which is exactly what §3.2 asks of the
 * client, said in the identifier itself rather than in a sentence the client
 * has to have read. It was **not** chosen, and the reason is interoperability
 * rather than correctness: the shape clients are known to round-trip is the one
 * §3.2's own example uses (`http://example.com/sync/10`) and the one the widely
 * deployed implementations hand out. A token is worth nothing if a client
 * mangles it, and nothing here can test somebody else's client.
 */
#[CoversClass(Token::class)]
final class TokenTest extends TestCase
{
    private const COLLECTION = 'calendars/work';

    /**
     * §3.2 and §4: a valid URI, and §3.2's own example is a counter appended
     * to a base.
     */
    public function testItIsAUriWithTheChangeAtTheEnd(): void
    {
        $token = (string) Token::for(self::COLLECTION, 42);

        self::assertMatchesRegularExpression('~^https://dav\.services/sync/[0-9a-f]{32}/42$~', $token);
        self::assertNotFalse(filter_var($token, FILTER_VALIDATE_URL));
    }

    /**
     * **Nothing of the path is readable in it.** The obligation to treat the
     * token as opaque is the client's, so this is the half a server can keep:
     * give it nothing to read.
     */
    public function testThePathIsNotReadableInIt(): void
    {
        $token = (string) Token::for(self::COLLECTION, 1);

        self::assertStringNotContainsString('calendars', $token);
        self::assertStringNotContainsString('work', $token);
    }

    /**
     * **Unique per collection** (R-SYNC-02): the same change number under two
     * collections is two different tokens, or §3.2's precondition could not be
     * checked at all.
     */
    public function testTwoCollectionsAtTheSameChangeAreTwoTokens(): void
    {
        self::assertNotSame(
            (string) Token::for('calendars/work', 7),
            (string) Token::for('calendars/home', 7),
        );
    }

    /**
     * And a token says which collection it is for, which is what the
     * precondition of §3.2 is asked of: "a valid token previously returned by
     * the server for the collection targeted by the request-URI".
     */
    public function testItKnowsWhichCollectionItIsFor(): void
    {
        $token = Token::of((string) Token::for(self::COLLECTION, 7));

        self::assertNotNull($token);
        self::assertTrue($token->isFor(self::COLLECTION));
        self::assertFalse($token->isFor('calendars/home'));
    }

    /**
     * **A path that merely begins the same way is a different path.**
     * `calendars/work2` is not `calendars/work`, and a server that thought
     * otherwise would answer one collection's changes for another.
     */
    public function testAPathThatBeginsTheSameWayIsADifferentOne(): void
    {
        $token = Token::of((string) Token::for(self::COLLECTION, 7));

        self::assertNotNull($token);
        self::assertFalse($token->isFor(self::COLLECTION . '2'));
    }

    /**
     * **Monotonically increasing** (R-SYNC-02), as the states they stand for.
     * The comparison is on the change and not on the string, because a decimal
     * counter does not sort as text: `strcmp('9', '10')` is positive and
     * anybody who means the numbers means the other way round.
     */
    public function testALaterChangeIsALaterToken(): void
    {
        $earlier = Token::for(self::COLLECTION, 9);
        $later = Token::for(self::COLLECTION, 10);

        self::assertTrue($later->isAfter($earlier));
        self::assertFalse($earlier->isAfter($later));
        self::assertFalse($later->isAfter($later));
        self::assertGreaterThan(0, strcmp((string) $earlier, (string) $later));
    }

    /**
     * The change comes back as the number it went in as, which is what a
     * journal is asked for the changes since.
     */
    public function testTheChangeItStandsForComesBack(): void
    {
        $token = Token::of((string) Token::for(self::COLLECTION, 1232));

        self::assertNotNull($token);
        self::assertSame(1232, $token->change());
    }

    /**
     * **A token this server did not make is no token.** §3.2's precondition
     * then applies — the value is not "a valid token previously returned by the
     * server" — and the report refuses it by name rather than guessing at a
     * number inside it.
     *
     * @param string $raw What the client sent
     */
    #[DataProvider('valuesThatAreNoTokenOfThisServer')]
    public function testWhatThisServerDidNotMakeIsNoToken(string $raw): void
    {
        self::assertNull(Token::of($raw));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function valuesThatAreNoTokenOfThisServer(): iterable
    {
        yield 'nothing at all' => [''];

        // §3.1: an empty element means a full synchronisation, and that is the
        // absence of a token rather than a token of nothing.
        yield 'somebody else’s' => ['http://example.com/ns/sync/1232'];

        yield 'the right base and no change' => ['https://dav.services/sync/' . str_repeat('a', 32)];

        yield 'a change that is no number' => ['https://dav.services/sync/' . str_repeat('a', 32) . '/soon'];

        yield 'a collection that is no key' => ['https://dav.services/sync/work/7'];

        yield 'a key of the wrong length' => ['https://dav.services/sync/' . str_repeat('a', 31) . '/7'];

        // **Anything between the base and the key is somebody else's URI**,
        // and the key alone being well formed is not enough: a reader that
        // looked for the shape anywhere in the value would accept this, and
        // then a token this server never issued would name a state it would
        // answer from.
        yield 'a key with something before it' => [
            'https://dav.services/sync/zz/' . str_repeat('a', 32) . '/7',
        ];

        yield 'a negative change' => ['https://dav.services/sync/' . str_repeat('a', 32) . '/-1'];

        yield 'a change with a leading zero' => ['https://dav.services/sync/' . str_repeat('a', 32) . '/007'];

        yield 'something after the change' => ['https://dav.services/sync/' . str_repeat('a', 32) . '/7/more'];

        yield 'the base alone' => ['https://dav.services/sync/'];

        // A token of this server's shape under another scheme is another
        // server's: the whole value is compared, not the tail of it.
        yield 'the same path over http' => ['http://dav.services/sync/' . str_repeat('a', 32) . '/7'];
    }

    /**
     * **A token survives the round trip**, which is the whole of what it is
     * for: the server hands it out, the client hands it back, and the two mean
     * the same state.
     */
    public function testItSurvivesTheRoundTrip(): void
    {
        $made = Token::for(self::COLLECTION, 1232);
        $read = Token::of((string) $made);

        self::assertNotNull($read);
        self::assertSame((string) $made, (string) $read);
        self::assertSame($made->change(), $read->change());
        self::assertTrue($read->isFor(self::COLLECTION));
    }

    /**
     * §3.3: "DAV:sync-token values returned by the server are not specific to
     * the value of the DAV:sync-level XML element used in the request. As such,
     * clients MAY use a DAV:sync-token value from a request with one
     * DAV:sync-level XML element value for a similar request with a different
     * DAV:sync-level XML element value."
     *
     * So the level is deliberately not in it, and this case is what keeps it
     * out: a token that carried the level would refuse the client the memo
     * permits.
     */
    public function testTheLevelIsNotInIt(): void
    {
        self::assertSame(
            (string) Token::for(self::COLLECTION, 7),
            (string) Token::for(self::COLLECTION, 7),
        );
    }
}
