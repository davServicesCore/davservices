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

namespace DavServices\Tests\Unit\Dav\Method;

use DavServices\Dav\Event\ListingMembers;
use DavServices\Dav\Method\PropFind;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-ACL-08 and RFC 5323 §2.3.1.
 *
 * **A `PROPFIND` with `Depth: 1` on a collection of a hundred thousand people
 * is a document nobody can use and a server that stops answering anything
 * else while it builds one.** R-ACL-08 asks for that to be limitable, and for
 * the client to be told rather than quietly handed less.
 *
 * ## Where the shape comes from
 *
 * RFC 4918 alone would have made `507` look wrong: §11.5 says the code means
 * the server "is unable to store the representation needed to successfully
 * complete the request", and calls the condition temporary. But **RFC 5323
 * §2.3.1 settles it for exactly this case** — a result set too large to
 * return:
 *
 * > the reply MUST use status code 207, return a DAV:multistatus response
 * > body, and indicate a status of 507 (Insufficient Storage) for the search
 * > arbiter URI. It SHOULD include the partial results.
 *
 * And §2.3.4 shows the document: the partial results, then **one more
 * `DAV:response` for the collection itself**, carrying the `507` and a
 * `DAV:responsedescription` saying what happened. That is what this builds,
 * with the collection standing where the search arbiter stands there.
 *
 * ## Two decisions that follow from the wording
 *
 * **The limit is off unless somebody sets it.** R-ACL-08 says a server MUST
 * be *limitable*, not that it must be limited — and a plain WebDAV server
 * that suddenly truncated its answers would be a surprise nobody asked for
 * (R-ARC-02).
 *
 * **Concealed members do not count towards it.** They are not part of the
 * answer at all (R-ACL-06), so counting them would tell a client there was
 * more to see when there was not — which is the one thing hiding them was
 * meant to prevent.
 */
#[CoversClass(PropFind::class)]
final class PropFindLimitTest extends TestCase
{
    /**
     * A collection inside the limit is answered whole, and says nothing about
     * limits: the notice is for when something was left out.
     */
    public function testACollectionInsideTheLimitIsAnsweredWhole(): void
    {
        $body = $this->listing(atMostMembers: 5);

        self::assertStringContainsString('<d:href>/people/one</d:href>', $body);
        self::assertStringContainsString('<d:href>/people/three</d:href>', $body);
        self::assertStringNotContainsString('507', $body);
    }

    /**
     * Over the limit, only as many members are reported as were allowed —
     * and in the order the collection gave them, so that a client walking
     * the same collection twice sees the same beginning.
     */
    public function testOnlyAsManyMembersAsWereAllowedAreReported(): void
    {
        $body = $this->listing(atMostMembers: 2);

        self::assertStringContainsString('<d:href>/people/one</d:href>', $body);
        self::assertStringContainsString('<d:href>/people/two</d:href>', $body);
        self::assertStringNotContainsString('<d:href>/people/three</d:href>', $body);
    }

    /**
     * **RFC 5323 §2.3.1: a `507` for the collection itself, inside a `207`.**
     * The request did not fail — the client has something usable and the
     * knowledge that it is not everything.
     */
    public function testTheCollectionItselfCarriesTheFiveOhSeven(): void
    {
        $response = $this->ask(atMostMembers: 2);
        $body = (string) $response->body();

        self::assertSame(207, $response->status(), 'the request itself succeeded');
        self::assertStringContainsString(
            '<d:href>/people/</d:href><d:status>HTTP/1.1 507 Insufficient Storage</d:status>',
            $body,
        );
    }

    /**
     * §2.3.4 puts a `DAV:responsedescription` beside it, because "507" alone
     * tells a person nothing about what to do next.
     */
    public function testTheNoticeSaysWhatHappenedInWords(): void
    {
        $body = $this->listing(atMostMembers: 2);

        self::assertStringContainsString('<d:responsedescription>', $body);
        self::assertStringContainsString('2', $body);
    }

    /**
     * **The notice comes last**, after the partial results, exactly as
     * §2.3.4 writes it. A client reading in order has the answer before it
     * learns the answer is short.
     */
    public function testTheNoticeComesAfterThePartialResults(): void
    {
        $body = $this->listing(atMostMembers: 2);

        self::assertLessThan(
            (int) strpos($body, '507'),
            (int) strpos($body, '/people/two'),
        );
    }

    /**
     * And the collection is still reported with its own properties: the
     * `507` response is **added**, not put in the place of the answer about
     * the collection.
     */
    public function testTheCollectionKeepsItsOwnAnswerToo(): void
    {
        $body = $this->listing(atMostMembers: 2);

        // Its own answer comes first, with whatever it had to say about the
        // properties; the notice is a second response about the same
        // resource, which is the shape RFC 5323 §2.3.4 writes.
        self::assertStringContainsString('<d:href>/people/</d:href><d:propstat>', $body);
    }

    /**
     * **R-ARC-02: unset, nothing changes.** R-ACL-08 asks for a server to be
     * limitable, not for every server to be limited, and one that began
     * truncating on its own would surprise a deployment that never asked.
     */
    public function testWithoutALimitEverythingIsReported(): void
    {
        $body = $this->listing();

        self::assertStringContainsString('<d:href>/people/three</d:href>', $body);
        self::assertStringNotContainsString('507', $body);
    }

    /**
     * `Depth: 0` lists nothing, so there is nothing to leave out — and a
     * notice about a listing that never happened would be a puzzle.
     */
    public function testAtDepthZeroThereIsNothingToLeaveOut(): void
    {
        $body = (string) $this->ask(atMostMembers: 1, depth: '0')->body();

        self::assertStringNotContainsString('507', $body);
    }

    /**
     * **R-ACL-06: what was concealed does not count.** Two of the three are
     * hidden, so one member is the whole of what may be seen — and telling
     * the client there was more would say exactly what hiding them was meant
     * not to say.
     */
    public function testConcealedMembersDoNotCountTowardsTheLimit(): void
    {
        $server = $this->server(atMostMembers: 2);

        $server->events()->on(
            ListingMembers::class,
            static fn (ListingMembers $event) => $event->conceal('people/two', 'people/three'),
        );

        $body = (string) $this->askOf($server, '1')->body();

        self::assertStringContainsString('<d:href>/people/one</d:href>', $body);
        self::assertStringNotContainsString('507', $body);
    }

    private function listing(?int $atMostMembers = null): string
    {
        return (string) $this->ask($atMostMembers)->body();
    }

    private function ask(?int $atMostMembers = null, string $depth = '1'): Response
    {
        return $this->askOf($this->server($atMostMembers), $depth);
    }

    private function askOf(Server $server, string $depth): Response
    {
        return $server->handle(new Request(
            'PROPFIND',
            '/people',
            headers: new Headers(['Depth' => $depth]),
            body: new Body('<D:propfind xmlns:D="DAV:"><D:prop><D:displayname/></D:prop></D:propfind>'),
        ));
    }

    private function server(?int $atMostMembers = null): Server
    {
        $root = new MemoryCollection('');
        $people = new MemoryCollection('people');

        foreach (['one', 'two', 'three'] as $name) {
            $people->add(new MemoryFile($name, ''));
        }

        $root->add($people);

        $server = new Server(new Tree($root));
        $propFind = new PropFind($server, atMostMembers: $atMostMembers);

        $server->onMethod('PROPFIND', $propFind(...));

        return $server;
    }
}
