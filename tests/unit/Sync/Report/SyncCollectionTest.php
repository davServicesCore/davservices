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

namespace DavServices\Tests\Unit\Sync\Report;

use DateInterval;
use DateTimeImmutable;
use DavServices\Dav\Event\ListingMembers;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\Property\LiveProperties;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use DavServices\Exception\InsufficientStorage;
use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Sync\IRevisions;
use DavServices\Sync\Journal;
use DavServices\Sync\Report\SyncCollection;
use DavServices\Sync\Token;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Tests\Unit\Sync\FixedRevisions;
use DavServices\Tests\Unit\Sync\MemoryJournalBackend;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for `DAV:sync-collection`. R-SYNC-01, R-SYNC-03, P7-01, P7-02.
 *
 * RFC 6578 §6.1:
 *
 *     <!ELEMENT sync-collection (sync-token, sync-level, limit?, prop)>
 *
 * > §3.1: In order to synchronize the contents of a collection between a server
 * > and client, the server provides the client with a synchronization token each
 * > time the synchronization report is executed. […] The client can then present
 * > that same token back to the server at some later time, and the server will
 * > return only those items that are new, have changed, or were deleted since
 * > that token was generated.
 *
 * ## What this report answers, and what it refuses
 *
 * **The initial synchronisation**, which §3.4 defines: an empty
 * `DAV:sync-token` means "tell me everything", and the server answers every
 * member with no removals. That is the half a server can answer without
 * keeping any history, and §3.1 names it as the path a client takes first and
 * falls back to.
 *
 * **A subsequent one**, which §3.5 defines: the members that changed carry
 * their properties, the members that went carry a `404`, and nothing else is
 * in the answer. Which is which comes from the seam that keeps the history —
 * `Sync\Journal` in these cases — and a server that keeps none answers every
 * token with §3.2's `DAV:valid-sync-token`, which is the first reason that
 * section itself lists for refusing one: "Servers might be unable to maintain
 * all of the change data for a collection due to storage or performance
 * reasons". Both servers are conformant, and
 * {@see \DavServices\Tests\Unit\Sync\FixedRevisions} is the second.
 *
 * **A token this server never made for this collection**, which is §3.2's
 * precondition read literally: "a valid token previously returned by the
 * server for the collection targeted by the request-URI".
 *
 * **A `DAV:limit` is refused** with §3.7's postcondition, which is §3.12's own
 * example: "If a server is unable to truncate the result at or below the
 * requested number, then it MUST fail the request with a
 * DAV:number-of-matches-within-limits postcondition error." Honouring a limit
 * means truncating, and truncating is §3.6 — R-SYNC-04, P7-03.
 *
 * Every refusal is the memo's own answer for a server that cannot do the
 * thing, rather than silence or a guess.
 */
#[CoversClass(SyncCollection::class)]
final class SyncCollectionTest extends TestCase
{
    private const HOME = '/home';

    private const VALID_SYNC_TOKEN = '{DAV:}valid-sync-token';

    private const WITHIN_LIMITS = '{DAV:}number-of-matches-within-limits';

    /**
     * What an answer that was cut short says about the collection itself.
     *
     * **Spelt out rather than looked for as a number**, because the token
     * carries a hash and a hash carries any three digits you care to name:
     * a bare `507` was found in `…8150773…` while nothing had been
     * truncated at all.
     */
    private const CUT_SHORT = '<d:status>HTTP/1.1 507 Insufficient Storage</d:status>';

    /**
     * **§3.4's initial synchronisation**: "the server MUST return all member
     * URLs of the collection […] and it MUST NOT return any removed member
     * URLs. All types of member (collection or non-collection) MUST be
     * reported."
     *
     * And §3.2: the answer "MUST be a DAV:multistatus XML element, which MUST
     * contain one DAV:sync-token element in addition to one DAV:response
     * element for each member URL".
     */
    public function testAnEmptyTokenAnswersEveryMember(): void
    {
        $response = $this->sync();
        $body = (string) $response->body();

        self::assertSame(207, $response->status());
        self::assertSame('application/xml; charset=utf-8', $response->headers()->first('Content-Type'));
        self::assertStringContainsString('/home/one.ics', $body);
        self::assertStringContainsString('/home/two.ics', $body);
        self::assertStringContainsString('/home/inner/', $body);
        self::assertStringContainsString('<d:sync-token>https://dav.services/sync/', $body);

        // "and it MUST NOT return any removed member URLs" — a removal is a
        // status on the response (§3.2), so every response having a propstat
        // is the same sentence counted. A property nobody has is a `404`
        // **inside** the propstat and is nothing of the kind: the collections
        // here have no entity tag, and saying so is not saying they are gone.
        self::assertSame(substr_count($body, '<d:response>'), substr_count($body, '<d:propstat>'));
    }

    /**
     * **For a member that is there, §3.2 asks for properties and no status of
     * its own**: "For members that have changed (i.e., are new or have had
     * their mapped resource modified), the DAV:response MUST contain at least
     * one DAV:propstat element and MUST NOT contain any DAV:status element."
     *
     * So a response carries a `propstat` with the properties the body named,
     * the way a `PROPFIND` carries them — and never a bare status.
     */
    public function testAMemberThatIsThereCarriesItsPropertiesAndNoStatusOfItsOwn(): void
    {
        $body = (string) $this->sync()->body();

        self::assertStringContainsString('<d:propstat>', $body);
        self::assertStringContainsString('<d:getetag>', $body);
        self::assertSame(
            substr_count($body, '<d:response>'),
            substr_count($body, '<d:propstat>'),
            'Every response is a propstat, and none is a status.',
        );
    }

    /**
     * And a property nobody has is a `404` **inside** the `propstat`, the way
     * §3.2 has the answer "follow the same behavior as PROPFIND with respect to
     * the DAV:multistatus response elements used to return specific property
     * results" — not a status on the response, which §3.2 reserves for a member
     * that was removed.
     */
    public function testAPropertyNobodyHasIsRefusedInsideThePropstat(): void
    {
        $body = (string) $this->sync(properties: '<getetag/><nothing xmlns="https://dav.services/test"/>')->body();

        self::assertStringContainsString('404 Not Found', $body);
        self::assertStringNotContainsString('<d:response><d:href>/home/one.ics</d:href><d:status>', $body);

        // **Both named properties are answered**, each in the block for the
        // status it came to: §14.22 of RFC 4918 gives a `propstat` one status,
        // so a file with an entity tag and without the other property is two
        // blocks. A report that answered only the first name would look right
        // to a case that merely counted the `404`s — the collections here have
        // no entity tag and bring one of their own.
        self::assertStringContainsString('nothing/>', $body);

        // §3.2: "A given member URL MUST appear only once in the response."
        self::assertSame(1, substr_count($body, '<d:href>/home/one.ics</d:href>'));
    }

    /**
     * **§3.3: "When the client specifies the DAV:sync-level XML element with a
     * value of '1', only appropriate internal member URLs (immediate children)
     * of the collection specified as the request-URI are reported."**
     */
    public function testLevelOneReachesTheImmediateChildrenOnly(): void
    {
        $body = (string) $this->sync(level: '1')->body();

        self::assertStringContainsString('/home/inner/', $body);
        self::assertStringNotContainsString('/home/inner/deep.ics', $body);
    }

    /**
     * **And "infinite" reaches all of them**: "all appropriate member URLs of
     * the collection specified as the request-URI are reported, provided child
     * collections themselves also support the DAV:sync-collection report."
     */
    public function testLevelInfiniteReachesEveryDepth(): void
    {
        $body = (string) $this->sync(level: 'infinite')->body();

        self::assertStringContainsString('/home/inner/', $body);
        self::assertStringContainsString('/home/inner/deep.ics', $body);
        self::assertStringContainsString('/home/inner/deeper/', $body);
        self::assertStringContainsString('/home/inner/deeper/bottom.ics', $body);
    }

    /**
     * **A level the memo does not define is refused.** §6.3: `<!-- Text MUST be
     * either "1" or "infinite" -->`, so a third value is a body this server
     * cannot read as asked rather than one to guess the nearer meaning of.
     *
     * @param string $level What the element says
     */
    #[DataProvider('levelsTheMemoDoesNotDefine')]
    public function testALevelTheMemoDoesNotDefineIsRefused(string $level): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('1 or infinite');

        // And it says what it refused, because a client that sent `2` has to
        // be able to see which of its two numbers the server read.
        $this->expectExceptionMessage(sprintf('not "%s"', $level));

        $this->sync(level: $level);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function levelsTheMemoDoesNotDefine(): iterable
    {
        yield 'zero' => ['0'];
        yield 'two' => ['2'];
        yield 'nothing at all' => [''];
        yield 'the word for the other one' => ['deep'];

        // RFC 4918 §10.2 writes `infinity` for the `Depth` header, and this is
        // a different element with a different word. A client that sent the
        // header's spelling asked for something §6.3 does not define.
        yield 'the spelling the Depth header uses' => ['infinity'];
    }

    /**
     * §6.1 puts all three outside any optional group, so a body missing one is
     * not a `sync-collection`.
     *
     * @param string $children What the body carries
     */
    #[DataProvider('bodiesMissingSomethingTheDefinitionRequires')]
    public function testABodyMissingSomethingTheDefinitionRequiresIsRefused(string $children): void
    {
        $this->expectException(BadRequest::class);

        $this->syncOf('<sync-collection xmlns="DAV:">' . $children . '</sync-collection>');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bodiesMissingSomethingTheDefinitionRequires(): iterable
    {
        yield 'no sync-token' => ['<sync-level>1</sync-level><prop><getetag/></prop>'];
        yield 'no sync-level' => ['<sync-token/><prop><getetag/></prop>'];
        yield 'no prop' => ['<sync-token/><sync-level>1</sync-level>'];
        yield 'nothing at all' => [''];
    }

    /**
     * **An empty `DAV:sync-token` is not a missing one.** §3.1: "That is done
     * by the client sending an empty token value to the server. This indicates
     * to the server that a full listing is required." So the element has to be
     * there and may be empty, which is the one distinction in §6.1 that is easy
     * to get backwards.
     */
    public function testAnEmptyTokenIsNotAMissingOne(): void
    {
        $response = $this->syncOf(
            '<sync-collection xmlns="DAV:"><sync-token></sync-token>'
            . '<sync-level>1</sync-level><prop><getetag/></prop></sync-collection>',
        );

        self::assertSame(207, $response->status());
    }

    /**
     * **§3.2: "This report is only defined when the Depth header has value
     * '0'; other values result in a 400 (Bad Request) error response. Note that
     * [RFC3253], Section 3.6, states that if the Depth header is not present,
     * it defaults to a value of '0'."**
     *
     * The scope is the `DAV:sync-level` element and never the header — §3.3:
     * "Servers MUST support only Depth:0 behavior with the
     * DAV:sync-collection report."
     *
     * @param string|null $depth What the header says, or nothing at all
     * @param bool $answered Whether the request is answered
     */
    #[DataProvider('depthsAndWhetherTheReportIsDefinedAtThem')]
    public function testTheReportIsDefinedAtDepthZeroAndNowhereElse(?string $depth, bool $answered): void
    {
        if (!$answered) {
            $this->expectException(BadRequest::class);
        }

        $response = $this->sync(depth: $depth);

        self::assertSame(207, $response->status());
    }

    /**
     * @return iterable<string, array{?string, bool}>
     */
    public static function depthsAndWhetherTheReportIsDefinedAtThem(): iterable
    {
        yield 'none, which is zero' => [null, true];
        yield 'zero' => ['0', true];
        yield 'one' => ['1', false];
        yield 'infinity' => ['infinity', false];
    }

    /**
     * **§3.2: "The request-URI MUST identify a collection."** A file is not
     * one, and a `sync-collection` aimed at one is a request this server cannot
     * answer as asked.
     *
     * @param string $path What the Request-URI names
     */
    #[DataProvider('pathsThatAreNoCollection')]
    public function testARequestUriThatIsNoCollectionIsRefused(string $path): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('collection');

        $this->sync(path: $path);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathsThatAreNoCollection(): iterable
    {
        yield 'a file' => [self::HOME . '/one.ics'];
        yield 'a file deeper down' => [self::HOME . '/inner/deep.ics'];
    }

    /**
     * **§3.2's precondition, and this chunk raises it for every token.**
     *
     * > (DAV:valid-sync-token): The DAV:sync-token element value MUST be a
     * > valid token previously returned by the server for the collection
     * > targeted by the request-URI.
     *
     * The first reason §3.2 lists for invalidating one is this server's
     * situation exactly: "Servers might be unable to maintain all of the change
     * data for a collection due to storage or performance reasons." P7-02
     * brings the journal that makes a token answerable.
     *
     * @param string $token What the client sends back
     */
    #[DataProvider('tokensThisServerCannotAnswer')]
    public function testATokenThisServerCannotAnswerIsRefusedByName(string $token): void
    {
        try {
            $this->sync(token: $token);
        } catch (Forbidden $refusal) {
            self::assertSame(403, $refusal->status());
            self::assertSame(self::VALID_SYNC_TOKEN, $refusal->errorElement());

            return;
        }

        self::fail('A token this server keeps no history for was answered.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tokensThisServerCannotAnswer(): iterable
    {
        // One this server made, for this very collection: it is well formed
        // and still out of date, because no history is kept.
        yield 'one of this server’s own' => [(string) Token::for('home', 7)];

        yield 'one for another collection' => [(string) Token::for('elsewhere', 7)];

        yield 'one from another server' => ['http://example.com/ns/sync/1232'];

        yield 'something that is no URI at all' => ['yesterday'];
    }

    /**
     * **§3.7's postcondition, which is §3.12's own example.** "If a server is
     * unable to truncate the result at or below the requested number, then it
     * MUST fail the request with a DAV:number-of-matches-within-limits
     * postcondition error." §3.12 answers it with a `507` and a bare
     * `DAV:error` — not a multistatus, which is what §3.6's *truncation*
     * answers with, and truncation is R-SYNC-04.
     */
    public function testALimitIsRefusedWithTheStatusTheExampleShows(): void
    {
        try {
            $this->sync(limit: '<limit><nresults>1</nresults></limit>');
        } catch (InsufficientStorage $refusal) {
            self::assertSame(507, $refusal->status());
            self::assertSame(self::WITHIN_LIMITS, $refusal->errorElement());

            return;
        }

        self::fail('A limit this server cannot honour was accepted.');
    }

    /**
     * And a limit this server could satisfy anyway is refused by the same
     * sentence: §3.7 is about whether the server can *truncate*, not about
     * whether this particular answer happens to be short enough. A server that
     * accepted the easy ones would answer the same request differently
     * depending on how much was in the collection.
     */
    public function testALimitLargerThanTheAnswerIsRefusedToo(): void
    {
        $this->expectException(InsufficientStorage::class);

        $this->sync(limit: '<limit><nresults>1000</nresults></limit>');
    }

    /**
     * **And the server's own limit leaves a first listing whole**, for the
     * same reason the client's is refused: §3.6's limiting is a MAY ("A server
     * MAY limit the number of member URLs in a response"), and this server
     * does not take the offer where it could not name the result. §3.4's MUST
     * is then answered in full — "the server MUST return all member URLs of
     * the collection".
     */
    public function testTheServersOwnLimitLeavesAFirstListingWhole(): void
    {
        $body = (string) $this->sync(atMost: 1)->body();

        self::assertSame(6, substr_count($body, '<d:response>'));
        self::assertStringNotContainsString(self::CUT_SHORT, $body);
    }

    /**
     * **§3.6's shape for an answer that was cut short.** "If the result set is
     * truncated, the response MUST use status code 207 (Multi-Status), return
     * a DAV:multistatus response body, and indicate a status of 507
     * (Insufficient Storage) for the request-URI. That DAV:response element
     * SHOULD include a DAV:error element with the
     * DAV:number-of-matches-within-limits precondition."
     *
     * The collection stands where RFC 5323 §2.3.1 puts the search arbiter, and
     * is named with its trailing slash (RFC 4918 §5.2).
     */
    public function testAnAnswerCutShortSaysSoForTheCollectionItself(): void
    {
        $journal = new Journal(new MemoryJournalBackend());
        $held = $this->tokenOf($journal);

        $journal->recordAChange('home/one.ics');
        $journal->recordAChange('home/two.ics');

        $answer = $this->sync(token: $held, revisions: $journal, atMost: 1);
        $body = (string) $answer->body();

        self::assertSame(207, $answer->status());
        self::assertStringContainsString(
            '<d:href>/home/</d:href><d:status>HTTP/1.1 507 Insufficient Storage</d:status>',
            $body,
        );
        self::assertStringContainsString('<d:number-of-matches-within-limits/>', $body);
    }

    /**
     * **The `507` is one response more and not one of the limited ones.**
     * RFC 5323 §5.17 has `DAV:nresults` as "a requested maximum number of
     * DAV:response elements to be returned in the response body", which would
     * make the `507` count — and §3.6's example settles that it does not: the
     * server "would return only 10 DAV:response elements […] together with an
     * **additional** DAV:response element for the request-URI with a status
     * code of 507".
     */
    public function testTheResponseThatSaysThereWasMoreIsOneMoreAndNotOneOfThem(): void
    {
        $journal = new Journal(new MemoryJournalBackend());
        $held = $this->tokenOf($journal);

        $journal->recordAChange('home/one.ics');
        $journal->recordAChange('home/two.ics');

        $body = (string) $this->sync(token: $held, revisions: $journal, atMost: 1)->body();

        self::assertSame(2, substr_count($body, '<d:response>'));
        self::assertSame(1, substr_count($body, '<d:propstat>'));
    }

    /**
     * **§3.6's own example, end to end.** "Assume the last DAV:sync-token used
     * by the client was 'http://example.com/sync/10', and since then 15
     * additional changes to different resources have occurred. […] if the
     * server chooses to limit responses to at most 10 changes, then it would
     * return only 10 DAV:response elements and a DAV:sync-token with value
     * 'http://example.com/sync/20', together with an additional DAV:response
     * element for the request-URI with a status code of 507. Subsequently, the
     * client can reissue the request with the DAV:sync-token value returned
     * from the server and fetch the remaining 5 changes."
     *
     * Every number in that paragraph is asserted here, the journal counting
     * the way the example does — one revision per entry, each greater than the
     * last.
     */
    public function testAnAnswerCutShortCarriesTheNumbersTheMemosExampleDoes(): void
    {
        $journal = new Journal(new MemoryJournalBackend());

        for ($i = 1; $i <= 10; ++$i) {
            $journal->recordAChange('home/before-' . $i . '.ics');
        }

        $held = $this->tokenOf($journal);

        for ($i = 1; $i <= 15; ++$i) {
            $journal->recordAChange('home/after-' . $i . '.ics');
        }

        $first = (string) $this->sync(token: $held, revisions: $journal, atMost: 10)->body();

        self::assertSame($held, (string) Token::for('home', 10));
        self::assertSame(11, substr_count($first, '<d:response>'));
        self::assertStringContainsString(self::CUT_SHORT, $first);
        self::assertSame((string) Token::for('home', 20), $this->tokenIn($first));

        $rest = (string) $this->sync(token: $this->tokenIn($first), revisions: $journal, atMost: 10)->body();

        self::assertSame(5, substr_count($rest, '<d:response>'));
        self::assertStringNotContainsString(self::CUT_SHORT, $rest);
        self::assertSame((string) Token::for('home', 25), $this->tokenIn($rest));
    }

    /**
     * An answer that fits says nothing about more, because §3.6's `507` is for
     * a result set that "is truncated" and this one is not. The token is then
     * where the collection stands, as every other complete answer's is.
     */
    public function testAnAnswerWithinTheLimitSaysNothingAboutMore(): void
    {
        $journal = new Journal(new MemoryJournalBackend());
        $held = $this->tokenOf($journal);

        $journal->recordAChange('home/one.ics');

        $body = (string) $this->sync(token: $held, revisions: $journal, atMost: 10)->body();

        self::assertStringNotContainsString(self::CUT_SHORT, $body);
        self::assertSame($this->tokenOf($journal), $this->tokenIn($body));
    }

    /**
     * **A client's limit is honoured where the server has none of its own**,
     * which is §3.6's second cause: a server limits "to limit the amount of
     * work expended in processing a request, **or as the result of an explicit
     * limit set by the client**".
     */
    public function testAClientsLimitIsHonouredWhereTheServerHasNone(): void
    {
        $journal = new Journal(new MemoryJournalBackend());
        $held = $this->tokenOf($journal);

        $journal->recordAChange('home/one.ics');
        $journal->recordAChange('home/two.ics');

        $body = (string) $this->sync(
            token: $held,
            limit: '<limit><nresults>1</nresults></limit>',
            revisions: $journal,
        )->body();

        self::assertSame(1, substr_count($body, '<d:propstat>'));
        self::assertStringContainsString(self::CUT_SHORT, $body);
    }

    /**
     * **The smaller of the two decides.** §3.7 has the server fail a request
     * it cannot hold "at or below the requested number", so a client's smaller
     * number binds it; §3.6's own limit is a MAY, so the server's smaller
     * number is its to apply. Either way the answer is at or below both.
     *
     * @param string $limit What the body asks for
     * @param int $atMost What this server would allow anyway
     * @param int $reported How many members may be named
     */
    #[DataProvider('theTwoLimitsAndWhatTheyLeave')]
    public function testTheSmallerOfTheTwoLimitsDecides(string $limit, int $atMost, int $reported): void
    {
        $journal = new Journal(new MemoryJournalBackend());
        $held = $this->tokenOf($journal);

        $journal->recordAChange('home/one.ics');
        $journal->recordAChange('home/two.ics');
        $journal->recordAChange('home/inner/deep.ics');

        $body = (string) $this->sync(
            token: $held,
            limit: $limit,
            revisions: $journal,
            atMost: $atMost,
        )->body();

        self::assertSame($reported, substr_count($body, '<d:propstat>'));
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function theTwoLimitsAndWhatTheyLeave(): iterable
    {
        yield 'the client asks for less' => ['<limit><nresults>1</nresults></limit>', 3, 1];

        yield 'the server allows less' => ['<limit><nresults>3</nresults></limit>', 1, 1];

        yield 'both say the same' => ['<limit><nresults>2</nresults></limit>', 2, 2];

        yield 'neither binds' => ['<limit><nresults>9</nresults></limit>', 9, 3];
    }

    /**
     * **§5.17's grammar, read as a grammar.** `<!ELEMENT limit (nresults) >`
     * puts the child outside any optional group, and
     * `<!ELEMENT nresults (#PCDATA)> <!-- only digits -->` with "The value of
     * this element is an unsigned integer" says what may be in it. Anything
     * else is a body this server cannot act on, and a `400` says so rather
     * than a server guessing a number.
     *
     * @param string $limit The `DAV:limit` the body carries
     */
    #[DataProvider('theLimitsThatAreNoLimit')]
    public function testALimitThatIsNoUnsignedIntegerIsRefused(string $limit): void
    {
        $journal = new Journal(new MemoryJournalBackend());

        $this->expectException(BadRequest::class);

        $this->sync(token: $this->tokenOf($journal), limit: $limit, revisions: $journal);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function theLimitsThatAreNoLimit(): iterable
    {
        yield 'no nresults at all' => ['<limit/>'];

        yield 'something else inside' => ['<limit><nothing/></limit>'];

        yield 'nothing in the nresults' => ['<limit><nresults/></limit>'];

        yield 'a negative number' => ['<limit><nresults>-1</nresults></limit>'];

        yield 'not a number' => ['<limit><nresults>lots</nresults></limit>'];

        yield 'a number with a fraction' => ['<limit><nresults>1.5</nresults></limit>'];
    }

    /**
     * **A member nobody may see fills its place in the page all the same**,
     * and that is a difference from {@see \DavServices\Dav\Method\PropFind}
     * worth knowing about: there the limit counts members reported, here it
     * counts changes taken from the history.
     *
     * It is allowed, because §5.17 asks for "a requested maximum number of
     * DAV:response elements" and a page that names fewer is at or below that
     * maximum. And it is the only place the count can live if a store is to
     * stop reading early — the concealment question is asked about members
     * that have already been read.
     *
     * The client still comes on: the token covers the page whether or not
     * every member of it could be shown.
     */
    public function testAConcealedMemberFillsItsPlaceInThePage(): void
    {
        $journal = new Journal(new MemoryJournalBackend());
        $held = $this->tokenOf($journal);

        $journal->recordAChange('home/one.ics');
        $journal->recordAChange('home/two.ics');

        $server = $this->server();

        $server->events()->on(ListingMembers::class, static function (ListingMembers $event): void {
            $event->conceal('home/one.ics');
        });

        $body = (string) $this->syncOf(
            $this->body($held, limit: '<limit><nresults>1</nresults></limit>'),
            server: $server,
            revisions: $journal,
        )->body();

        self::assertStringNotContainsString('one.ics', $body);
        self::assertStringNotContainsString('two.ics', $body);
        self::assertStringContainsString(self::CUT_SHORT, $body);
        self::assertSame((string) Token::for('home', 1), $this->tokenIn($body));
    }

    /**
     * **A member a client may not see is not in the answer** (R-ACL-06). The
     * walk goes through {@see \DavServices\Dav\VisibleMembers}, so concealment
     * lives in one place — and §3.5.2 provides for exactly this: "A member MAY
     * be reported as removed if the user issuing the request no longer has
     * access to this member, due to access control changes." On an initial
     * synchronisation §3.4 forbids reporting removals, so it is simply absent.
     */
    public function testAConcealedMemberIsNotInTheAnswer(): void
    {
        $server = $this->server();

        $server->events()->on(ListingMembers::class, static function (ListingMembers $event): void {
            $event->conceal('home/one.ics');
        });

        $body = (string) $this->syncOf($this->body(), server: $server)->body();

        self::assertStringNotContainsString('/home/one.ics', $body);
        self::assertStringContainsString('/home/two.ics', $body);
    }

    /**
     * **The token is of the collection the report was aimed at**, which is what
     * §3.2's precondition is asked of later. Two collections answer two tokens.
     */
    public function testTheTokenIsTheTargetedCollectionsOwn(): void
    {
        $home = $this->tokenIn((string) $this->sync()->body());
        $inner = $this->tokenIn((string) $this->sync(path: self::HOME . '/inner')->body());

        self::assertNotSame($home, $inner);

        $read = Token::of($home);

        self::assertNotNull($read);
        self::assertTrue($read->isFor('home'));
    }

    /**
     * **A collection whose store keeps no last-modified cannot be
     * synchronised**, because a token has to say which state it stands for and
     * nothing here could tell one state from the next. §3.2 requires the report
     * to be listed "on any collection that supports synchronization", so a
     * collection that does not support it is refused by RFC 3253 §3.6's own
     * precondition for a report a resource does not answer.
     */
    public function testACollectionThatCannotSayWhenItChangedIsRefusedByName(): void
    {
        try {
            $this->sync(revisions: FixedRevisions::none());
        } catch (Forbidden $refusal) {
            self::assertSame(403, $refusal->status());
            self::assertSame('{DAV:}supported-report', $refusal->errorElement());

            return;
        }

        self::fail('A collection that cannot say when it changed was given a token.');
    }

    /**
     * **The token follows the state, and that is what makes §5 worth
     * anything.** A client uses the token in an `If` header to say "only if
     * nothing has changed since", so a token that stood still while the
     * collection moved would turn every conditional write into an
     * unconditional one.
     *
     * R-SYNC-02's "monotonically increasing" is asked of the token here, where
     * the state comes from: a later revision is a later token.
     */
    public function testALaterStateIsALaterToken(): void
    {
        $earlier = $this->tokenIn((string) $this->sync(revisions: FixedRevisions::at(9))->body());
        $later = $this->tokenIn((string) $this->sync(revisions: FixedRevisions::at(10))->body());

        self::assertNotSame($earlier, $later);

        $read = Token::of($later);
        $before = Token::of($earlier);

        self::assertNotNull($read);
        self::assertNotNull($before);
        self::assertTrue($read->isAfter($before));
    }

    /**
     * And a collection that has not changed answers the same token twice,
     * which is the other half of the same sentence: §3.1 has the token
     * "define the state of the data being synchronized at a particular point
     * in time", so two reports over one state are one state.
     */
    public function testAStateThatHasNotChangedAnswersTheSameToken(): void
    {
        self::assertSame(
            $this->tokenIn((string) $this->sync()->body()),
            $this->tokenIn((string) $this->sync()->body()),
        );
    }

    /**
     * **§3.2, for a member that changed: "the DAV:response MUST contain at
     * least one DAV:propstat element and MUST NOT contain any DAV:status
     * element."**
     *
     * So a token this server can answer brings back the changed member and
     * nothing else — §3.1's whole purpose: "the server will return only those
     * items that are new, have changed, or were deleted since that token was
     * generated".
     */
    public function testAChangedMemberCarriesItsPropertiesAndNothingElseIsReported(): void
    {
        $journal = new Journal(new MemoryJournalBackend());
        $held = $this->tokenOf($journal);

        $journal->recordAChange('home/one.ics');

        $body = (string) $this->sync(token: $held, revisions: $journal)->body();

        self::assertStringContainsString('/home/one.ics', $body);
        self::assertStringContainsString('<d:getetag>', $body);
        self::assertStringNotContainsString('/home/two.ics', $body);
        self::assertSame(1, substr_count($body, '<d:response>'));
        self::assertSame(1, substr_count($body, '<d:propstat>'));
    }

    /**
     * **§3.2, for a member that went: "the DAV:response MUST contain one
     * DAV:status with a value set to '404 Not Found' and MUST NOT contain any
     * DAV:propstat element."**
     *
     * The status is on the response and not inside a `propstat`, which is the
     * distinction a client reads to tell "this resource is gone" from "this
     * resource has no such property".
     */
    public function testARemovedMemberCarriesTheStatusTheMemoNamesAndNoProperties(): void
    {
        $journal = new Journal(new MemoryJournalBackend());
        $held = $this->tokenOf($journal);

        $journal->recordARemoval('home/gone.ics');

        $body = (string) $this->sync(token: $held, revisions: $journal)->body();

        self::assertStringContainsString('/home/gone.ics', $body);
        self::assertStringContainsString('<d:status>HTTP/1.1 404 Not Found</d:status>', $body);
        self::assertStringNotContainsString('<d:propstat>', $body);
    }

    /**
     * **A member the journal recorded as changed but the tree no longer holds
     * is reported as removed.** The record says what happened and the tree
     * says what is there, and where they disagree the tree is right: a
     * `propstat` for a resource that has gone would send the client to fetch
     * what is not there, and §3.5.2's first sentence is a MUST about the
     * mapping rather than about the record of it.
     */
    public function testAChangedMemberThatIsNoLongerThereIsReportedAsRemoved(): void
    {
        $journal = new Journal(new MemoryJournalBackend());
        $held = $this->tokenOf($journal);

        $journal->recordAChange('home/vanished.ics');

        $body = (string) $this->sync(token: $held, revisions: $journal)->body();

        self::assertStringContainsString('/home/vanished.ics', $body);
        self::assertStringContainsString('<d:status>HTTP/1.1 404 Not Found</d:status>', $body);
        self::assertStringNotContainsString('<d:propstat>', $body);
    }

    /**
     * **§3.2 asks for "one DAV:response element for each member URL", and a
     * removal early in the list is no reason to stop reading it.** A client
     * that deleted one event and wrote another in the same interval is owed
     * both answers: the `404` for the one, the properties for the other.
     *
     * The removal comes first here on purpose, the order being the journal's.
     * What is still there is looked up in one pass over the changes, and a
     * pass that ended at the first removal would turn every later change into
     * a resource the client is told has gone — the one wrong answer it would
     * act on, by throwing away what it holds.
     */
    public function testARemovalEarlyInTheListDoesNotHideWhatChangedAfterIt(): void
    {
        $journal = new Journal(new MemoryJournalBackend());
        $held = $this->tokenOf($journal);

        $journal->recordARemoval('home/gone.ics');
        $journal->recordAChange('home/one.ics');

        $body = (string) $this->sync(token: $held, revisions: $journal)->body();

        self::assertStringContainsString('/home/gone.ics', $body);
        self::assertStringContainsString('/home/one.ics', $body);
        self::assertSame(2, substr_count($body, '<d:response>'));
        self::assertSame(1, substr_count($body, '<d:propstat>'));
    }

    /**
     * **Every changed member the tree still holds carries its properties**,
     * not the first of them: §3.2 wants a response "for each member URL that
     * was added, has had its mapped resource changed, or was deleted since the
     * last synchronization operation".
     *
     * Counted rather than looked for, because a changed member left out of the
     * lookup is not missing from the answer — it is in it as a `404`, and a
     * client reading that would drop what it correctly holds.
     */
    public function testEveryChangedMemberThatIsStillThereCarriesItsProperties(): void
    {
        $journal = new Journal(new MemoryJournalBackend());
        $held = $this->tokenOf($journal);

        $journal->recordAChange('home/one.ics');
        $journal->recordAChange('home/two.ics');

        $body = (string) $this->sync(token: $held, revisions: $journal)->body();

        self::assertStringContainsString('/home/one.ics', $body);
        self::assertStringContainsString('/home/two.ics', $body);
        self::assertSame(2, substr_count($body, '<d:response>'));
        self::assertSame(2, substr_count($body, '<d:propstat>'));
    }

    /**
     * **§3.4: an empty token answers everything and no removals**, however
     * much the journal holds. "The server MUST return all member URLs of the
     * collection […] and it MUST NOT return any removed member URLs."
     *
     * So the initial synchronisation is the tree and never the history — a
     * server that answered a removal there would tell a client that something
     * it has never heard of has gone.
     */
    public function testAnEmptyTokenAnswersTheTreeAndNeverTheHistory(): void
    {
        $journal = new Journal(new MemoryJournalBackend());

        $journal->recordARemoval('home/gone.ics');

        $body = (string) $this->sync(revisions: $journal)->body();

        self::assertStringContainsString('/home/one.ics', $body);
        self::assertStringNotContainsString('/home/gone.ics', $body);

        // Every response carries a `propstat`, which is the same sentence
        // counted: a removal is a status on the response itself. The `404`
        // **inside** a propstat is a property these collections have not got,
        // and saying so is not saying they are gone.
        self::assertSame(substr_count($body, '<d:response>'), substr_count($body, '<d:propstat>'));
    }

    /**
     * **The token moves on with the state**, and handing the new one straight
     * back answers nothing: §3.1 has the token "represent the state of the
     * data being synchronized at that point in time", so a client in step is
     * owed an empty answer rather than a repetition.
     */
    public function testTheTokenItAnswersWithIsTheStateTheAnswerCovers(): void
    {
        $journal = new Journal(new MemoryJournalBackend());
        $held = $this->tokenOf($journal);

        $journal->recordAChange('home/one.ics');

        $answered = $this->tokenIn((string) $this->sync(token: $held, revisions: $journal)->body());

        self::assertNotSame($held, $answered);

        $body = (string) $this->sync(token: $answered, revisions: $journal)->body();

        self::assertSame($answered, $this->tokenIn($body));
        self::assertStringNotContainsString('<d:response>', $body);
    }

    /**
     * **§3.3's level scopes the history as well as the tree.** "When the client
     * specifies the DAV:sync-level XML element with a value of '1', only
     * appropriate internal member URLs (immediate children) […] are reported."
     *
     * And §3.3 says what that costs, which is why it says to use `infinite`:
     * "clients cannot rely on a series of reports using the DAV:sync-level XML
     * element value set to '1' at multiple levels to track all changes within
     * a collection."
     */
    public function testALevelOfOneDoesNotReachAChangeFurtherDown(): void
    {
        $journal = new Journal(new MemoryJournalBackend());
        $held = $this->tokenOf($journal);

        $journal->recordAChange('home/inner/deep.ics');

        self::assertStringNotContainsString(
            '/home/inner/deep.ics',
            (string) $this->sync(token: $held, level: '1', revisions: $journal)->body(),
        );
        self::assertStringContainsString(
            '/home/inner/deep.ics',
            (string) $this->sync(token: $held, revisions: $journal)->body(),
        );
    }

    /**
     * **A changed member a client may not see is not named** (R-ACL-06), the
     * same as on an initial synchronisation. A `404` on a resource nobody may
     * read is only half the work if the answer still says it is there.
     *
     * **A removal is reported all the same**, and §3.5.2 licenses it in as many
     * words: "A member MAY be reported as removed if the user issuing the
     * request no longer has access to this member, due to access control
     * changes." A path with nothing behind it is a path no resolver can be
     * asked about, and §3.5.2's first sentence is a MUST — a removal dropped
     * because the resource it named has gone would leave a client holding it
     * for ever.
     */
    public function testAChangedMemberTheClientMayNotSeeIsNotInTheAnswer(): void
    {
        $journal = new Journal(new MemoryJournalBackend());
        $held = $this->tokenOf($journal);
        $server = $this->server();

        $server->events()->on(ListingMembers::class, static function (ListingMembers $event): void {
            $event->conceal('home/one.ics', 'home/vanished.ics');
        });

        $journal->recordAChange('home/one.ics');
        $journal->recordAChange('home/two.ics');
        $journal->recordAChange('home/vanished.ics');
        $journal->recordARemoval('home/gone.ics');

        $body = (string) $this->syncOf(
            $this->body($held),
            server: $server,
            revisions: $journal,
        )->body();

        self::assertStringNotContainsString('/home/one.ics', $body);
        self::assertStringContainsString('/home/two.ics', $body);
        self::assertStringContainsString('/home/gone.ics', $body);

        // And a member that has gone is reported however a listener would
        // have answered about it: the question is only ever asked of what is
        // there, so the two rules do not depend on which `if` comes first.
        self::assertStringContainsString('/home/vanished.ics', $body);
    }

    /**
     * **§3.3: "DAV:sync-token values returned by the server are not specific
     * to the value of the DAV:sync-level XML element used in the request."**
     *
     * So the two levels answer one token, which is what the memo's next
     * sentence rests on: "clients MAY use a DAV:sync-token value from a
     * request with one DAV:sync-level XML element value for a similar request
     * with a different DAV:sync-level XML element value".
     *
     * And §3.3 says plainly what that costs, which is why it tells clients to
     * use `infinite`: a change the level did not reach is behind the token all
     * the same, so "clients cannot rely on a series of reports using the
     * DAV:sync-level XML element value set to '1' at multiple levels to track
     * all changes within a collection".
     */
    public function testTheTokenDoesNotDependOnTheLevelItWasAskedAt(): void
    {
        $journal = new Journal(new MemoryJournalBackend());
        $held = $this->tokenOf($journal);

        $journal->recordAChange('home/inner/deep.ics');

        self::assertSame(
            $this->tokenIn((string) $this->sync(token: $held, level: '1', revisions: $journal)->body()),
            $this->tokenIn((string) $this->sync(token: $held, revisions: $journal)->body()),
        );
    }

    /**
     * **The whole answer, as §3.9's own example writes it**: a changed member
     * with one `propstat` for the property it has and another for the one
     * nobody set, a removed member with a bare status, and the token last.
     *
     * The request is §3.9's too, down to the proprietary property in the
     * `DAV:prop` — which is what shows the `404` on the inside and the `404`
     * on the outside are different answers rather than one printed twice.
     */
    public function testAnswersATokenTheWayTheMemosExampleDoes(): void
    {
        $journal = new Journal(new MemoryJournalBackend());
        $held = $this->tokenOf($journal);

        $journal->recordAChange('home/one.ics');
        $journal->recordARemoval('home/gone.ics');

        $request = <<<XML
            <?xml version="1.0" encoding="utf-8" ?>
            <D:sync-collection xmlns:D="DAV:">
              <D:sync-token>{$held}</D:sync-token>
              <D:sync-level>1</D:sync-level>
              <D:prop xmlns:R="urn:ns.example.com:boxschema">
                <D:getetag/>
                <R:bigbox/>
              </D:prop>
            </D:sync-collection>
            XML;

        $body = (string) $this->syncOf($request, revisions: $journal)->body();

        self::assertStringContainsString('<d:href>/home/one.ics</d:href><d:propstat>', $body);
        self::assertStringContainsString('<d:getetag>', $body);
        self::assertStringContainsString('bigbox', $body);
        self::assertStringContainsString(
            '<d:href>/home/gone.ics</d:href><d:status>HTTP/1.1 404 Not Found</d:status></d:response>',
            $body,
        );

        // §6.4 overrides RFC 4918 §14.16 to put the token last:
        // `<!ELEMENT multistatus (response*, responsedescription?, sync-token?)>`
        self::assertStringEndsWith('</d:sync-token></d:multistatus>', trim($body));
    }

    /**
     * **A token from before what the journal keeps is refused by name**, which
     * is R-SYNC-03 reaching the client: the journal raises §3.2's
     * `DAV:valid-sync-token`, and the report lets it out rather than answering
     * an emptier truth.
     */
    public function testATokenOlderThanTheJournalIsRefusedByName(): void
    {
        $moment = '2026-10-27 09:00:00';
        $journal = new Journal(
            new MemoryJournalBackend(),
            new DateInterval('P1D'),
            function () use (&$moment): DateTimeImmutable {
                return new DateTimeImmutable($moment);
            },
        );
        $held = $this->tokenOf($journal);

        $journal->recordAChange('home/one.ics');

        $moment = '2026-11-20 09:00:00';
        $journal->recordAChange('home/two.ics');
        $journal->clean();

        try {
            $this->sync(token: $held, revisions: $journal);
        } catch (Forbidden $refusal) {
            self::assertSame(403, $refusal->status());
            self::assertSame(self::VALID_SYNC_TOKEN, $refusal->errorElement());

            return;
        }

        self::fail('A token older than the journal was answered from.');
    }

    /**
     * **And a token made for another collection is refused by the same name**,
     * however well formed it is: §3.2's precondition is "a valid token
     * previously returned by the server **for the collection targeted by the
     * request-URI**". A server that read the number out of somebody else's
     * token would answer one collection's changes from another's state.
     */
    public function testATokenMadeForAnotherCollectionIsRefusedByName(): void
    {
        $journal = new Journal(new MemoryJournalBackend());

        $journal->recordAChange('home/one.ics');

        try {
            $this->sync(token: (string) Token::for('elsewhere', 1), revisions: $journal);
        } catch (Forbidden $refusal) {
            self::assertSame(self::VALID_SYNC_TOKEN, $refusal->errorElement());

            return;
        }

        self::fail('A token made for another collection was answered from.');
    }

    /**
     * The whole body, as §3.8's own example writes it.
     */
    public function testReadsABodyWrittenTheWayTheMemosExampleIs(): void
    {
        $body = <<<'XML'
            <?xml version="1.0" encoding="utf-8" ?>
            <D:sync-collection xmlns:D="DAV:">
              <D:sync-token/>
              <D:sync-level>1</D:sync-level>
              <D:prop>
                <D:getetag/>
              </D:prop>
            </D:sync-collection>
            XML;

        self::assertStringContainsString('/home/one.ics', (string) $this->syncOf($body)->body());
    }

    /**
     * Runs the report, given the pieces that differ from case to case.
     *
     * @param string $token What the `DAV:sync-token` carries
     * @param string $level What the `DAV:sync-level` says
     * @param string $properties What the `DAV:prop` names
     * @param string $limit The `DAV:limit`, or nothing for none
     * @param string|null $depth What the `Depth` header says
     * @param string $path The Request-URI
     */
    private function sync(
        string $token = '',
        string $level = 'infinite',
        string $properties = '<getetag/>',
        string $limit = '',
        ?string $depth = '0',
        string $path = self::HOME,
        ?IRevisions $revisions = null,
        int $atMost = IRevisions::EVERYTHING,
    ): Response {
        return $this->syncOf(
            $this->body($token, $level, $properties, $limit),
            $path,
            $depth,
            revisions: $revisions,
            atMost: $atMost,
        );
    }

    private function body(
        string $token = '',
        string $level = 'infinite',
        string $properties = '<getetag/>',
        string $limit = '',
    ): string {
        return sprintf(
            '<sync-collection xmlns="DAV:"><sync-token>%s</sync-token><sync-level>%s</sync-level>%s'
            . '<prop>%s</prop></sync-collection>',
            htmlspecialchars($token, ENT_XML1),
            $level,
            $limit,
            $properties,
        );
    }

    private function syncOf(
        string $body,
        string $path = self::HOME,
        ?string $depth = '0',
        ?Server $server = null,
        ?IRevisions $revisions = null,
        int $atMost = IRevisions::EVERYTHING,
    ): Response {
        $server ??= $this->server();
        $report = new SyncCollection($server, $revisions ?? FixedRevisions::at(7), $atMost);

        return $report(
            new Request(
                'REPORT',
                $path,
                headers: new Headers($depth === null ? [] : ['Depth' => $depth]),
                body: new Body($body),
            ),
            $server->reader()->parse($body),
        );
    }

    /**
     * A home with two files, a collection holding a third, and another
     * collection below that one.
     */
    private function server(): Server
    {
        $deeper = (new MemoryCollection('deeper'))->add(new MemoryFile('bottom.ics', 'BEGIN:VCALENDAR'));
        $inner = (new MemoryCollection('inner'))
            ->add(new MemoryFile('deep.ics', 'BEGIN:VCALENDAR'))
            ->add($deeper);
        $home = (new MemoryCollection('home'))
            ->add(new MemoryFile('one.ics', 'BEGIN:VCALENDAR'))
            ->add(new MemoryFile('two.ics', 'BEGIN:VCALENDAR'))
            ->add($inner);

        return $this->serverOver((new MemoryCollection(''))->add($home));
    }

    private function serverOver(MemoryCollection $root): Server
    {
        $events = new EventEmitter();

        $events->on(PropertiesRequested::class, (new LiveProperties())(...));

        return new Server(new Tree($root), $events);
    }

    /**
     * The token the home stands at now, as a client would have been handed it.
     */
    private function tokenOf(Journal $journal): string
    {
        return (string) Token::for('home', $journal->revisionOf('home'));
    }

    private function tokenIn(string $body): string
    {
        if (preg_match('~<d:sync-token>([^<]+)</d:sync-token>~', $body, $found) !== 1) {
            self::fail('The answer carries no sync-token: ' . $body);
        }

        return $found[1];
    }
}
