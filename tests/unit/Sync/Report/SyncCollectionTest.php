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
use DavServices\Sync\Report\SyncCollection;
use DavServices\Sync\Token;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for `DAV:sync-collection`. R-SYNC-01, P7-01.
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
 * ## What this chunk answers, and what it refuses
 *
 * **The initial synchronisation**, which §3.4 defines: an empty
 * `DAV:sync-token` means "tell me everything", and the server answers every
 * member with no removals. That is the half a server can answer without
 * keeping any history, and §3.1 names it as the path a client takes first and
 * falls back to.
 *
 * **A token is refused** with §3.2's `DAV:valid-sync-token` precondition,
 * because this chunk keeps no change journal — which is the first reason §3.2
 * itself lists for invalidating one: "Servers might be unable to maintain all
 * of the change data for a collection due to storage or performance reasons".
 * P7-02 brings `Sync\Journal` and makes a token answerable; until then a client
 * does a full synchronisation each time, which is slower and not wrong.
 *
 * **A `DAV:limit` is refused** with §3.7's postcondition, which is §3.12's own
 * example: "If a server is unable to truncate the result at or below the
 * requested number, then it MUST fail the request with a
 * DAV:number-of-matches-within-limits postcondition error." Honouring a limit
 * means truncating, and truncating is §3.6 — R-SYNC-04, P7-03.
 *
 * Both refusals are the memo's own answers for a server that cannot do the
 * thing, rather than silence or a guess.
 */
#[CoversClass(SyncCollection::class)]
final class SyncCollectionTest extends TestCase
{
    private const HOME = '/home';

    private const VALID_SYNC_TOKEN = '{DAV:}valid-sync-token';

    private const WITHIN_LIMITS = '{DAV:}number-of-matches-within-limits';

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
            $this->sync(revisionOf: static fn (): ?int => null);
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
        $earlier = $this->tokenIn((string) $this->sync(revisionOf: static fn (): int => 9)->body());
        $later = $this->tokenIn((string) $this->sync(revisionOf: static fn (): int => 10)->body());

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
        ?callable $revisionOf = null,
    ): Response {
        return $this->syncOf(
            $this->body($token, $level, $properties, $limit),
            $path,
            $depth,
            revisionOf: $revisionOf,
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
        ?callable $revisionOf = null,
    ): Response {
        $server ??= $this->server();
        $report = new SyncCollection($server, $revisionOf ?? static fn (): int => 7);

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
     * The token the answer carries.
     */
    private function tokenIn(string $body): string
    {
        if (preg_match('~<d:sync-token>([^<]+)</d:sync-token>~', $body, $found) !== 1) {
            self::fail('The answer carries no sync-token: ' . $body);
        }

        return $found[1];
    }
}
