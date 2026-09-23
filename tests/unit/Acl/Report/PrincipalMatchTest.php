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

namespace DavServices\Tests\Unit\Acl\Report;

use DavServices\Acl\GroupResolver;
use DavServices\Acl\PrincipalCollection;
use DavServices\Acl\Report\PrincipalMatch;
use DavServices\Dav\Acl\PrincipalInfo;
use DavServices\Dav\Event\CurrentPrincipalRequested;
use DavServices\Dav\Event\ListingMembers;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Exception\BadRequest;
use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Tests\Unit\Backend\MemoryPrincipalBackend;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Xml\Element;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 3744 §9.3.
 *
 * **Support for this report is REQUIRED**, and this server did not have it —
 * the second of the two found when the conformance check asked what
 * announcing `access-control` in `OPTIONS` commits a server to (§7.2: "all
 * MUST level requirements and REQUIRED features specified in this document").
 *
 * ## What it is for
 *
 * §9.3 gives both halves. **"For example, this report can return all of the
 * resources in a collection hierarchy that are owned by the current user"** —
 * that is `DAV:principal-property`, and it is how a client draws "my files"
 * without asking about every resource in turn. And **"if the collection
 * contains principals, the report can be used to identify all members of the
 * collection that match the current user"** — that is `DAV:self`, and it is
 * how a client discovers its own principal and the groups it is in when all
 * it knows is where the principals are kept.
 *
 * ## What "matches" means, and why the groups are in it
 *
 * §2 settles it in two sentences: **"If a person or computational agent
 * matches a principal resource that is a member of a group, they also match
 * the group. Membership in a group is recursive."** So a resource owned by a
 * group the asker is in is a resource that matches the asker, and §9.3 says
 * the same thing again for `DAV:self`: "it matches the group if a member
 * identifies the same principal as the current user".
 *
 * A report that compared only the principal who signed in would answer "you
 * own nothing" to somebody whose whole team's work is in front of them.
 *
 * ## Three decisions the wording settles
 *
 * **No limit, and no `DAV:number-of-matches-within-limits`.** §9.2 and §9.4
 * both carry that postcondition; §9.3 carries none, and the index at the back
 * of the RFC lists it on exactly those two pages. So a refusal invented here
 * would refuse a request the specification says succeeds.
 *
 * **Without `DAV:prop` a response is a bare `200`**, which is the shape
 * §9.3.1's own example answers with — `DAV:href` and `DAV:status`, no
 * `DAV:propstat`. With one, "the properties specified in the DAV:prop element
 * MUST be reported in the DAV:response elements".
 *
 * **The href is the one in the property, not one below it.** §9.3 speaks of
 * "the DAV:href element of the property", and the lesson from §9.2 is fresh:
 * a report gathering every href it can reach reports things that were never
 * principals.
 */
#[CoversClass(PrincipalMatch::class)]
final class PrincipalMatchTest extends TestCase
{
    private const BY_OWNER = <<<'XML'
        <D:principal-match xmlns:D="DAV:">
          <D:principal-property><D:owner/></D:principal-property>
        </D:principal-match>
        XML;

    private const BY_SELF = <<<'XML'
        <D:principal-match xmlns:D="DAV:">
          <D:self/>
        </D:principal-match>
        XML;

    /**
     * §9.3: "The response body for a successful request MUST be a
     * DAV:multistatus XML element."
     */
    public function testAnswersAMultiStatus(): void
    {
        $response = $this->ask(self::BY_OWNER);

        self::assertSame(207, $response->status());
        self::assertSame('application/xml; charset=utf-8', $response->headers()->first('Content-Type'));
        self::assertStringContainsString('<d:multistatus', (string) $response->body());
    }

    /**
     * **The point of the report**, in §9.3's own example: "all of the
     * resources in a collection hierarchy that are owned by the current
     * user".
     */
    public function testFindsWhatTheAskerOwns(): void
    {
        $body = (string) $this->ask(self::BY_OWNER)->body();

        self::assertStringContainsString('<d:href>/doc/mine.html</d:href>', $body);
    }

    /**
     * And nobody else's, which is the other half of the same sentence.
     */
    public function testDoesNotFindWhatSomebodyElseOwns(): void
    {
        $body = (string) $this->ask(self::BY_OWNER)->body();

        self::assertStringNotContainsString('hers.html', $body);
    }

    /**
     * §9.3: "all members (**at any depth**) of the collection identified by
     * the Request-URI". §9.3.1's example answers with
     * `/doc/img/bar.gif` — a member of a member.
     */
    public function testFindsThemAtAnyDepth(): void
    {
        $body = (string) $this->ask(self::BY_OWNER)->body();

        self::assertStringContainsString('<d:href>/doc/deep/buried.html</d:href>', $body);
    }

    /**
     * **A collection is a member like any other.** §9.3 says members, not
     * files, and a client asking what it owns wants the folder it owns as
     * much as the pages in it. The trailing slash is RFC 4918 §8.3.
     */
    public function testACollectionIsAMemberToo(): void
    {
        $body = (string) $this->ask(self::BY_OWNER)->body();

        self::assertStringContainsString('<d:href>/doc/deep/</d:href>', $body);
    }

    /**
     * **But not the collection the report was asked about.** §9.3 reports
     * "all members ... of the collection identified by the Request-URI", and
     * a collection is not a member of itself — §9.3.1 asks about `/doc/` and
     * answers with two things inside it.
     */
    public function testTheCollectionItselfIsNotAMember(): void
    {
        $body = (string) $this->ask(self::BY_OWNER)->body();

        self::assertStringNotContainsString('<d:href>/doc/</d:href>', $body);
    }

    /**
     * **§2: "If a person or computational agent matches a principal resource
     * that is a member of a group, they also match the group."** Alice is in
     * the staff, so what the staff owns is what Alice owns — and a report
     * that compared only the principal who signed in would tell her that her
     * team's work is not hers.
     */
    public function testWhatAGroupTheAskerIsInOwnsIsFoundToo(): void
    {
        $body = (string) $this->ask(self::BY_OWNER)->body();

        self::assertStringContainsString('<d:href>/doc/ours.html</d:href>', $body);
    }

    /**
     * §2: "Membership in a group is recursive, so if a principal is a member
     * of group GRPA, and GRPA is a member of group GRPB, then the principal
     * is also a member of GRPB."
     */
    public function testTheGroupsReachAsFarAsTheyGo(): void
    {
        $body = (string) $this->ask(self::BY_OWNER)->body();

        self::assertStringContainsString('<d:href>/doc/everyones.html</d:href>', $body);
    }

    /**
     * **A server that keeps no principals has no groups** (R-ARC-02), so the
     * asker counts as themselves and nothing more. That is an empty
     * recursion, not a missing one.
     */
    public function testWithoutAResolverTheAskerCountsAsThemselvesAlone(): void
    {
        $body = (string) $this->ask(self::BY_OWNER, withGroups: false)->body();

        self::assertStringContainsString('<d:href>/doc/mine.html</d:href>', $body);
        self::assertStringNotContainsString('ours.html', $body);
    }

    /**
     * §9.3: "if the collection contains principals, the report can be used to
     * identify all members of the collection that match the current user".
     */
    public function testSelfFindsThePrincipalTheAskerIs(): void
    {
        $body = (string) $this->ask(self::BY_SELF, target: '/principals/')->body();

        self::assertStringContainsString('<d:href>/principals/alice</d:href>', $body);
    }

    /**
     * §9.3: "When the DAV:self element is used in a DAV:principal-match
     * report issued against a group, it matches the group if a member
     * identifies the same principal as the current user."
     */
    public function testSelfFindsTheGroupsTheAskerIsIn(): void
    {
        $body = (string) $this->ask(self::BY_SELF, target: '/principals/')->body();

        self::assertStringContainsString('<d:href>/principals/staff</d:href>', $body);
        self::assertStringContainsString('<d:href>/principals/everyone</d:href>', $body);
    }

    /**
     * And nobody else, or the report would be a directory listing with the
     * word "self" on it.
     */
    public function testSelfFindsNobodyElse(): void
    {
        $body = (string) $this->ask(self::BY_SELF, target: '/principals/')->body();

        self::assertStringNotContainsString('bob', $body);
    }

    /**
     * **`DAV:self` over a collection of anything else finds nothing.** The
     * members of `/doc/` are pages; a page is not a principal, whoever owns
     * it.
     */
    public function testSelfFindsNothingWhereThereAreNoPrincipals(): void
    {
        $body = (string) $this->ask(self::BY_SELF)->body();

        self::assertStringNotContainsString('<d:response>', $body);
    }

    /**
     * **Nobody signed in matches nobody.** §5.5.1: "The current user matches
     * DAV:href only if that user is authenticated as being (or being a member
     * of) the principal identified by the URL" — so an unauthenticated
     * request is answered with the empty multistatus §9.3 provides for, and
     * not with somebody else's files.
     */
    #[DataProvider('bothWaysOfAsking')]
    public function testNobodySignedInMatchesNobody(string $body, string $target): void
    {
        $answer = (string) $this->ask($body, target: $target, asking: null)->body();

        self::assertStringNotContainsString('<d:response>', $answer);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bothWaysOfAsking(): iterable
    {
        yield 'by property' => [self::BY_OWNER, '/doc/'];

        yield 'by self' => [self::BY_SELF, '/principals/'];
    }

    /**
     * §9.3.1's example asks with no `DAV:prop` and is answered with
     * `DAV:href` and `DAV:status` — "HTTP/1.1 200 OK", and no `DAV:propstat`
     * anywhere.
     */
    public function testWithoutPropTheAnswerIsABareStatus(): void
    {
        $body = (string) $this->ask(self::BY_OWNER)->body();

        self::assertStringContainsString('<d:status>HTTP/1.1 200 OK</d:status>', $body);
        self::assertStringNotContainsString('propstat', $body);
    }

    /**
     * §9.3: "If DAV:prop is specified in the request body, the properties
     * specified in the DAV:prop element MUST be reported in the DAV:response
     * elements."
     */
    public function testWithPropThePropertiesAreReported(): void
    {
        $body = (string) $this->ask(self::askingAlsoFor('<D:displayname/>'))->body();

        self::assertStringContainsString('<d:displayname>My own page</d:displayname>', $body);
        self::assertStringContainsString('<d:propstat>', $body);
    }

    /**
     * And a property the resource has not is reported the way a `PROPFIND`
     * reports it, because it is the same machinery answering (R-PROP-05).
     */
    public function testAPropertyThatIsNotThereIsReportedAsMissing(): void
    {
        $body = (string) $this->ask(self::askingAlsoFor('<D:getcontentlanguage/>'))->body();

        self::assertStringContainsString('<d:getcontentlanguage/>', $body);
        self::assertStringContainsString('404', $body);
    }

    /**
     * An empty `DAV:prop` specifies no properties, so there are none that
     * MUST be reported — and a `DAV:response` holding neither a propstat nor
     * a status is not one RFC 4918 §14.24 allows. So it is answered the way
     * a body without a `DAV:prop` is answered.
     */
    public function testAnEmptyPropIsAnsweredLikeNone(): void
    {
        $body = (string) $this->ask(self::askingAlsoFor(''))->body();

        self::assertStringContainsString('<d:status>HTTP/1.1 200 OK</d:status>', $body);
        self::assertStringNotContainsString('propstat', $body);
    }

    /**
     * **A member that may not be seen is not reported** (R-ACL-06). The walk
     * goes through {@see \DavServices\Dav\VisibleMembers}, so what a listing
     * conceals a report does not hand over by another door.
     */
    public function testAConcealedMemberIsNotReported(): void
    {
        $body = (string) $this->ask(self::BY_OWNER, conceal: 'doc/hidden.html')->body();

        self::assertStringNotContainsString('hidden.html', $body);
        self::assertStringContainsString('<d:href>/doc/mine.html</d:href>', $body, 'and the rest is still found');
    }

    /**
     * **A property that may not be read does not match.** Access control
     * refuses by answering first, so the value the client was not to see is
     * not the value the report compares.
     */
    public function testAPropertyTheAskerMayNotReadDoesNotMatch(): void
    {
        $body = (string) $this->ask(self::BY_OWNER)->body();

        self::assertStringNotContainsString('secret.html', $body);
    }

    /**
     * A resource without the property at all is simply not a match — §9.3 has
     * the report identify the members whose property names the asker, and
     * says nothing about the ones that have no such property.
     */
    public function testAResourceWithoutThePropertyIsNoMatch(): void
    {
        $body = (string) $this->ask(self::BY_OWNER)->body();

        self::assertStringNotContainsString('nobody.html', $body);
    }

    /**
     * And neither is one whose property holds no href: §9.3 matches on "the
     * URI found in the DAV:href element of the property", and there is none.
     */
    public function testAPropertyWithoutAnHrefIsNoMatch(): void
    {
        $body = (string) $this->ask(self::BY_OWNER)->body();

        self::assertStringNotContainsString('unowned.html', $body);
    }

    /**
     * **§9.3: "the DAV:href element of the property".** An href further down
     * inside the value belongs to whatever holds it — the lesson §9.2 taught
     * with `DAV:inherited`, which carries an href that names a collection
     * rather than a principal.
     */
    public function testAnHrefBelowThePropertyIsNotTheProperty(): void
    {
        $body = (string) $this->ask(self::BY_OWNER)->body();

        self::assertStringNotContainsString('wrapped.html', $body);
    }

    /**
     * **Something that is no href is stepped over, not stopped at.**
     * RFC 3744 §10 requires the XML element ignore rule of every
     * implementation, and a property value is exactly where an extension puts
     * an element of its own — as readily in front of the href as after it.
     */
    public function testSomethingThatIsNoHrefIsSteppedOver(): void
    {
        $body = (string) $this->ask(self::BY_OWNER)->body();

        self::assertStringContainsString('<d:href>/doc/noted.html</d:href>', $body);
    }

    /**
     * **A property naming several principals matches if one of them is the
     * asker.** §9.3 expects "an href element" and a `DAV:owner` holds one,
     * but nothing in the grammar stops a property from holding more — and a
     * report that looked at the first only would answer differently
     * depending on the order somebody wrote them in.
     */
    public function testAPropertyNamingSeveralPrincipalsMatchesOnAnyOfThem(): void
    {
        $body = (string) $this->ask(self::BY_OWNER)->body();

        self::assertStringContainsString('<d:href>/doc/shared.html</d:href>', $body);
    }

    /**
     * **A principal on another server is no match here.** This server cannot
     * tell whether that URL names the person asking, and a guess either way
     * would be a guess about access.
     */
    public function testAPrincipalOnAnotherServerIsNoMatch(): void
    {
        $body = (string) $this->ask(self::BY_OWNER)->body();

        self::assertStringNotContainsString('elsewhere.html', $body);
    }

    /**
     * **An href written across lines is still that href.** Every example in
     * RFC 3744 is indented, and so is every property a person has written by
     * hand; a report comparing the raw text would find nothing at all.
     */
    public function testAnHrefWrittenAcrossLinesIsStillThatHref(): void
    {
        $body = (string) $this->ask(self::BY_OWNER)->body();

        self::assertStringContainsString('<d:href>/doc/indented.html</d:href>', $body);
    }

    /**
     * **The Request-URI names something that is not a collection.** §9.3 has
     * it identify a collection; a client that named a page has made a mistake
     * this report cannot correct, and the empty multistatus says what is
     * true — nothing there matched — where an error would say something about
     * what is there.
     */
    public function testATargetThatIsNoCollectionMatchesNothing(): void
    {
        $response = $this->ask(self::BY_OWNER, target: '/doc/mine.html');

        self::assertSame(207, $response->status());
        self::assertStringNotContainsString('<d:response>', (string) $response->body());
    }

    /**
     * §9.3: "This report is only defined when the Depth header has value
     * `0`; other values result in a 400 (Bad Request) error response."
     */
    #[DataProvider('depthsThatAreNotZero')]
    public function testOnlyDepthZeroIsDefined(string $depth): void
    {
        $this->expectException(BadRequest::class);

        $this->ask(self::BY_OWNER, depth: $depth);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function depthsThatAreNotZero(): iterable
    {
        yield 'one' => ['1'];

        yield 'infinity' => ['infinity'];
    }

    /**
     * §9.3, quoting RFC 3253 §3.6: "if the Depth header is not present, it
     * defaults to a value of `0`" — which is what every client sends.
     */
    public function testAMissingDepthIsDepthZero(): void
    {
        self::assertSame(207, $this->ask(self::BY_OWNER, depth: null)->status());
    }

    /**
     * **`<!ELEMENT principal-match ((principal-property | self), prop?)>`** —
     * one of the two, and a body with neither asks nothing. Read leniently it
     * would have to mean one of them, and choosing which would be answering a
     * question the client did not ask.
     */
    public function testABodyWithNeitherWayOfMatchingIsRefused(): void
    {
        $this->expectException(BadRequest::class);

        $this->ask('<D:principal-match xmlns:D="DAV:"/>');
    }

    /**
     * And a body with both is refused for the same reason: the grammar is a
     * choice, and the two say different things about the same resources.
     */
    public function testABodyWithBothWaysOfMatchingIsRefused(): void
    {
        $this->expectException(BadRequest::class);

        $this->ask(<<<'XML'
            <D:principal-match xmlns:D="DAV:">
              <D:self/>
              <D:principal-property><D:owner/></D:principal-property>
            </D:principal-match>
            XML);
    }

    /**
     * §9.3: `DAV:principal-property` holds "an element whose value identifies
     * a property" — so one that names none identifies nothing to look in.
     */
    public function testAPrincipalPropertyNamingNoPropertyIsRefused(): void
    {
        $this->expectException(BadRequest::class);

        $this->ask(<<<'XML'
            <D:principal-match xmlns:D="DAV:">
              <D:principal-property/>
            </D:principal-match>
            XML);
    }

    /**
     * And one that names several is refused rather than guessed at: §9.3
     * matches on "**the** property identified by the DAV:principal-property
     * element", and whether two of them mean both or either is not something
     * the specification says.
     */
    public function testAPrincipalPropertyNamingSeveralPropertiesIsRefused(): void
    {
        $this->expectException(BadRequest::class);

        $this->ask(<<<'XML'
            <D:principal-match xmlns:D="DAV:">
              <D:principal-property><D:owner/><D:group/></D:principal-property>
            </D:principal-match>
            XML);
    }

    /**
     * The same body with `DAV:prop` on the end of it, which is where the
     * grammar puts it.
     */
    private static function askingAlsoFor(string $property): string
    {
        return <<<XML
            <D:principal-match xmlns:D="DAV:">
              <D:principal-property><D:owner/></D:principal-property>
              <D:prop>{$property}</D:prop>
            </D:principal-match>
            XML;
    }

    private function ask(
        string $body,
        ?string $depth = '0',
        string $target = '/doc/',
        ?string $asking = 'principals/alice',
        bool $withGroups = true,
        ?string $conceal = null,
    ): Response {
        $backend = self::backend();
        $server = $this->server($backend, $asking, $conceal);
        $report = new PrincipalMatch($server, $withGroups ? new GroupResolver($backend, 'principals') : null);
        $headers = $depth === null ? new Headers() : new Headers(['Depth' => $depth]);

        $request = new Request('REPORT', $target, headers: $headers, body: new Body($body));

        return $report($request, $server->reader()->parse($body));
    }

    /**
     * Alice, the staff she is in, and everyone the staff are in — so that the
     * recursion of §2 has somewhere to go — and Bob, who is in neither.
     */
    private static function backend(): MemoryPrincipalBackend
    {
        return new MemoryPrincipalBackend(
            new PrincipalInfo('alice', 'Alice Ashton', [], ['staff']),
            new PrincipalInfo('staff', 'The staff', [], ['everyone'], ['alice']),
            new PrincipalInfo('everyone', 'Everyone', [], [], ['staff']),
            new PrincipalInfo('bob', 'Bob Barton'),
        );
    }

    /**
     * A collection of pages owned by various people, and the principals
     * themselves beside it.
     *
     * **The owners are properties of the files**, because that is where
     * RFC 3744 puts them and where this library leaves them: `DAV:owner` is
     * the backend's to answer, not a plugin's to invent. The two that a node
     * cannot answer for itself — the owner of a collection, and a property
     * refused to whoever is asking — are answered by a listener, which is the
     * other way this server ever learns a property.
     */
    private function server(MemoryPrincipalBackend $backend, ?string $asking, ?string $conceal): Server
    {
        $root = new MemoryCollection('');
        $doc = new MemoryCollection('doc');
        $deep = new MemoryCollection('deep');

        $deep->add(self::page('buried.html', self::owner('/principals/alice')));
        $doc->add($deep);
        $doc->add((new MemoryFile('mine.html'))
            ->withProperty('{DAV:}owner', self::owner('/principals/alice'))
            ->withProperty('{DAV:}displayname', 'My own page'));
        $doc->add(self::page('hers.html', self::owner('/principals/bob')));
        $doc->add(self::page('ours.html', self::owner('/principals/staff')));
        $doc->add(self::page('everyones.html', self::owner('/principals/everyone')));
        $doc->add(self::page('hidden.html', self::owner('/principals/alice')));
        $doc->add(self::page('elsewhere.html', self::owner('https://other.example.com/principals/alice')));
        $doc->add(self::page('indented.html', self::owner("\n        /principals/alice\n      ")));
        $doc->add(self::page('shared.html', self::owner('/principals/bob', '/principals/alice')));
        $doc->add(self::page('noted.html', self::noted('/principals/alice')));
        $doc->add(self::page('unowned.html', new Element('{DAV:}owner')));
        $doc->add(self::page('wrapped.html', self::wrapping(self::owner('/principals/alice'))));
        $doc->add(new MemoryFile('nobody.html'));
        $doc->add(new MemoryFile('secret.html'));

        $root->add($doc);
        $root->add(new PrincipalCollection('principals', $backend));

        $server = new Server(new Tree($root));

        $this->wire($server, $asking, $conceal);

        return $server;
    }

    /**
     * Who is asking, what is hidden from them, and the two properties no node
     * here can answer for itself.
     */
    private function wire(Server $server, ?string $asking, ?string $conceal): void
    {
        $events = $server->events();

        if ($asking !== null) {
            $events->on(
                CurrentPrincipalRequested::class,
                static fn (CurrentPrincipalRequested $event) => $event->answerWith($asking),
            );
        }

        if ($conceal !== null) {
            $events->on(ListingMembers::class, static fn (ListingMembers $event) => $event->conceal($conceal));
        }

        $events->on(PropertiesRequested::class, static function (PropertiesRequested $event): void {
            $result = $event->result();

            if (!$result->wants('{DAV:}owner')) {
                return;
            }

            // A collection has no properties of its own here, and access
            // control answers a refusal before the node is ever asked.
            match ($result->path()) {
                'doc', 'doc/deep' => $result->set('{DAV:}owner', self::owner('/principals/alice')),
                'doc/secret.html' => $result->set('{DAV:}owner', null, 403),
                default => null,
            };
        });
    }

    private static function page(string $name, Element $owner): MemoryFile
    {
        return (new MemoryFile($name))->withProperty('{DAV:}owner', $owner);
    }

    /**
     * `DAV:owner` as RFC 3744 §5.1 has it: the property, holding an href.
     */
    private static function owner(string ...$urls): Element
    {
        $owner = new Element('{DAV:}owner');

        foreach ($urls as $url) {
            $href = new Element('{DAV:}href');

            $href->appendText($url);
            $owner->append($href);
        }

        return $owner;
    }

    /**
     * The same property with an extension's own element in front of the href,
     * which the ignore rule has a reader step over.
     */
    private static function noted(string $url): Element
    {
        $owner = new Element('{DAV:}owner');

        $owner->append(new Element('{https://dav.services/test}note'));

        foreach (self::owner($url)->children() as $href) {
            $owner->append($href);
        }

        return $owner;
    }

    /**
     * The same href, one element further down — where it is no longer the
     * href of the property.
     */
    private static function wrapping(Element $owner): Element
    {
        $wrapped = new Element('{DAV:}owner');
        $inside = new Element('{https://dav.services/test}whose');

        foreach ($owner->children() as $href) {
            $inside->append($href);
        }

        $wrapped->append($inside);

        return $wrapped;
    }
}
