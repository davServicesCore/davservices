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

use DavServices\Acl\Report\PrincipalPropertySearch;
use DavServices\Acl\SearchableProperty;
use DavServices\Dav\Event\ListingMembers;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\Method\Report;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Xml\Element;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 3744 §9.4 and §9.4.1.
 *
 * **This is how a client finds a person.** "One expected use of this report
 * is to discover the URL of a principal associated with a given person or
 * group by searching for them by name" — it is what happens when somebody
 * types three letters into the invitation field of a calendar client.
 *
 * ## What the RFC leaves open, and what it does not
 *
 * **The matching is the server's to choose** — exact, prefix, substring,
 * cased or not — because the people usually live in somebody else's
 * directory, and an LDAP attribute already has its own answer. §9.4 names the
 * preferred default where nothing constrains it: **caseless substring**, and
 * that is what this does.
 *
 * **The logic is fixed, though: everything is AND.** Several
 * `DAV:property-search` elements, and several properties inside one
 * `DAV:prop`, all have to match. RFC 3744 has no `test` attribute — that is a
 * CalDAV extension, and reading one here would answer a question the client
 * did not ask.
 *
 * ## Three refusals that are not pedantry
 *
 * A `DAV:property-search` **without a `DAV:match`** is refused rather than
 * read as an empty search string. An empty string is a substring of
 * everything, so the lenient reading hands back the entire directory to a
 * client that sent a malformed body — which is the one mistake this report
 * must not make.
 *
 * A **property this server does not search matches nobody** (§9.4), so a
 * search naming one returns nothing at all rather than silently searching
 * something else. That is the clasp between this report and
 * `DAV:principal-search-property-set`: the list that report publishes is the
 * list this one honours.
 *
 * And **too many matches is `403` with `DAV:number-of-matches-within-limits`**
 * — the postcondition §9.4 names, so the limit arrives with a name a client
 * already understands instead of an invented one.
 *
 * ## What it must not leak
 *
 * A member that was concealed is not searched, and a property that may not be
 * read does not match. Both would otherwise turn the search into a way of
 * asking questions about resources the client may not see — and a matching
 * href is an answer of its own, even with no properties beside it.
 */
#[CoversClass(PrincipalPropertySearch::class)]
final class PrincipalPropertySearchTest extends TestCase
{
    private const COLOUR = '{https://dav.services/test}colour';

    private const BY_NAME = <<<'XML'
        <D:principal-property-search xmlns:D="DAV:">
          <D:property-search>
            <D:prop><D:displayname/></D:prop>
            <D:match>doE</D:match>
          </D:property-search>
          <D:prop><D:displayname/></D:prop>
        </D:principal-property-search>
        XML;

    /**
     * §9.4's own example, down to the search string: "doE" finds John Doe and
     * Zygdoebert Smith. **Caseless, and anywhere in the value** — which is
     * what makes the feature usable, because nobody types a display name
     * exactly.
     */
    public function testFindsEverybodyWhoseNameHoldsTheString(): void
    {
        $body = (string) $this->search(self::BY_NAME)->body();

        self::assertStringContainsString('<d:href>/principals/jdoe</d:href>', $body);
        self::assertStringContainsString('<d:href>/principals/zsmith</d:href>', $body);
        self::assertStringNotContainsString('/principals/nobody', $body, 'who has no name to match');
    }

    /**
     * §9.4: "The response body for a successful request MUST be a
     * DAV:multistatus XML element."
     */
    public function testAnswersAMultiStatus(): void
    {
        $response = $this->search(self::BY_NAME);

        self::assertSame(207, $response->status());
        self::assertSame('application/xml; charset=utf-8', $response->headers()->first('Content-Type'));
        self::assertStringContainsString('<d:multistatus', (string) $response->body());
    }

    /**
     * §9.4: "If DAV:prop is specified in the request body, the properties
     * specified in the DAV:prop element MUST be reported in the DAV:response
     * elements." Finding somebody is only half of it — a client shows the
     * name it searched for.
     */
    public function testReportsThePropertiesThatWereAskedFor(): void
    {
        $body = (string) $this->search(self::BY_NAME)->body();

        self::assertStringContainsString('<d:displayname>John Doe</d:displayname>', $body);
    }

    /**
     * §9.4: "In the case where there are no response elements, the returned
     * multistatus XML element is empty." Not a `404`: the search ran, and the
     * answer is that nobody matched.
     */
    public function testNobodyMatchingIsAnEmptyMultiStatus(): void
    {
        $response = $this->search($this->byName('nobody of this name'));

        self::assertSame(207, $response->status());
        self::assertStringNotContainsString('<d:response>', (string) $response->body());
    }

    /**
     * **Several searches are AND** (§9.4), so adding one narrows the result.
     * Here the name must hold "smith" and the address must hold "example.com"
     * — which only one of them does.
     */
    public function testSeveralSearchesAreAnd(): void
    {
        $body = (string) $this->search(<<<'XML'
            <D:principal-property-search xmlns:D="DAV:">
              <D:property-search>
                <D:prop><D:displayname/></D:prop>
                <D:match>smith</D:match>
              </D:property-search>
              <D:property-search>
                <D:prop><D:alternate-URI-set/></D:prop>
                <D:match>example.com</D:match>
              </D:property-search>
            </D:principal-property-search>
            XML)->body();

        self::assertStringContainsString('<d:href>/principals/zsmith</d:href>', $body);
        self::assertStringNotContainsString('/principals/jdoe', $body);
    }

    /**
     * And so are several properties inside one `DAV:prop`: both have to hold
     * the same string.
     */
    public function testSeveralPropertiesInOneSearchAreAnd(): void
    {
        $body = (string) $this->search(<<<'XML'
            <D:principal-property-search xmlns:D="DAV:">
              <D:property-search>
                <D:prop><D:displayname/><D:alternate-URI-set/></D:prop>
                <D:match>smith</D:match>
              </D:property-search>
            </D:principal-property-search>
            XML)->body();

        self::assertStringContainsString('<d:href>/principals/zsmith</d:href>', $body);
        self::assertStringNotContainsString('/principals/jdoe', $body, 'whose name holds no "smith"');
    }

    /**
     * **§9.4: "A search requesting properties that are not searchable for a
     * particular principal will not match that principal."** The list
     * `DAV:principal-search-property-set` publishes is the list this report
     * honours — otherwise the first report would be a promise the second one
     * breaks.
     *
     * The colour is a property the principals really have, so this shows the
     * gate biting rather than the value merely being absent.
     */
    public function testAPropertyThisServerDoesNotSearchMatchesNobody(): void
    {
        $body = (string) $this->search(<<<'XML'
            <D:principal-property-search xmlns:D="DAV:" xmlns:T="https://dav.services/test">
              <D:property-search>
                <D:prop><T:colour/></D:prop>
                <D:match>blue</D:match>
              </D:property-search>
            </D:principal-property-search>
            XML)->body();

        self::assertStringNotContainsString('<d:response>', $body);
    }

    /**
     * §9.4: "the report searches all members (at any depth) of the collection
     * identified by the Request-URI". A deployment that sorts its principals
     * into `users` and `groups` is a deployment whose search must still work.
     */
    public function testSearchesMembersAtAnyDepth(): void
    {
        $body = (string) $this->search($this->byName('administrators'))->body();

        self::assertStringContainsString('<d:href>/principals/groups/admins</d:href>', $body);
    }

    /**
     * **R-ACL-06: a concealed member is not searched.** A search that found
     * what a listing hides would be a way of asking whether a resource exists
     * — and an href in the answer is that answer, with or without properties
     * beside it.
     */
    public function testAConcealedMemberIsNotFound(): void
    {
        $server = $this->server();

        $server->events()->on(
            ListingMembers::class,
            static fn (ListingMembers $event) => $event->conceal('principals/jdoe'),
        );

        $body = (string) $this->search(self::BY_NAME, $server)->body();

        self::assertStringNotContainsString('/principals/jdoe', $body);
        self::assertStringContainsString('<d:href>/principals/zsmith</d:href>', $body, 'the others are still found');
    }

    /**
     * **A property that may not be read does not match.** Access control
     * refuses by answering first (R-PROP-05); a search that compared the
     * node's value anyway would use exactly the value the client was not to
     * see, and report the match.
     */
    public function testAPropertyThatMayNotBeReadDoesNotMatch(): void
    {
        $server = $this->server();

        $server->events()->on(
            PropertiesRequested::class,
            static function (PropertiesRequested $event): void {
                if ($event->result()->path() === 'principals/jdoe') {
                    $event->result()->set('{DAV:}displayname', null, 403);
                }
            },
        );

        $body = (string) $this->search(self::BY_NAME, $server)->body();

        self::assertStringNotContainsString('/principals/jdoe', $body);
    }

    /**
     * §9.4: "This report is only defined when the Depth header has value 0."
     */
    public function testOnlyDepthZeroIsDefined(): void
    {
        $this->expectException(BadRequest::class);

        $this->search(self::BY_NAME, null, '1');
    }

    /**
     * The DTD wants at least one `DAV:property-search`, and a body without
     * one has asked for every principal there is without saying so.
     */
    public function testABodyThatSearchesForNothingIsRefused(): void
    {
        $this->expectException(BadRequest::class);

        $this->search('<D:principal-property-search xmlns:D="DAV:"/>');
    }

    /**
     * A search that names no property is a search over nothing.
     */
    public function testASearchWithoutAPropertyIsRefused(): void
    {
        $this->expectException(BadRequest::class);

        $this->search(<<<'XML'
            <D:principal-property-search xmlns:D="DAV:">
              <D:property-search><D:match>doe</D:match></D:property-search>
            </D:principal-property-search>
            XML);
    }

    /**
     * **And one that names no string to look for is refused rather than read
     * as an empty one.** The empty string is a substring of every value, so
     * the lenient reading would hand the whole directory to a client that
     * sent a malformed body.
     */
    public function testASearchWithoutAMatchIsRefused(): void
    {
        $this->expectException(BadRequest::class);

        $this->search(<<<'XML'
            <D:principal-property-search xmlns:D="DAV:">
              <D:property-search><D:prop><D:displayname/></D:prop></D:property-search>
            </D:principal-property-search>
            XML);
    }

    /**
     * And a `DAV:prop` that names nothing is the same mistake wearing a
     * different hat: nothing to look in is nothing to look for, and a search
     * over no properties would be satisfied by everybody.
     */
    public function testASearchOverNoPropertyAtAllIsRefused(): void
    {
        $this->expectException(BadRequest::class);

        $this->search(<<<'XML'
            <D:principal-property-search xmlns:D="DAV:">
              <D:property-search><D:prop/><D:match>doe</D:match></D:property-search>
            </D:principal-property-search>
            XML);
    }

    /**
     * A `DAV:principal-collection-set` naming a place that holds nothing
     * finds nobody there, and says so by leaving it out rather than by
     * failing: the set is somebody else's answer, and a search should not
     * turn a stale entry in it into a broken search.
     */
    public function testAPrincipalCollectionThatIsNotThereFindsNobody(): void
    {
        $server = $this->server();

        $server->events()->on(
            PropertiesRequested::class,
            static function (PropertiesRequested $event): void {
                $set = new Element('{DAV:}principal-collection-set');
                $href = new Element('{DAV:}href');

                $href->appendText('/nowhere');
                $set->append($href);
                $event->result()->set('{DAV:}principal-collection-set', $set);
            },
        );

        $response = $this->search($this->applyingToTheCollectionSet(), $server, '0', 250, '/');

        self::assertSame(207, $response->status());
        self::assertStringNotContainsString('<d:response>', (string) $response->body());
    }

    /**
     * And a resource whose `DAV:principal-collection-set` nobody answered has
     * no collections to search — an empty answer, not an error.
     */
    public function testAResourceWithNoCollectionSetFindsNobody(): void
    {
        $response = $this->search($this->applyingToTheCollectionSet(), null, '0', 250, '/');

        self::assertSame(207, $response->status());
        self::assertStringNotContainsString('<d:response>', (string) $response->body());
    }

    /**
     * **A `DAV:match` a client really did send empty matches everybody**, and
     * that is correct rather than generous: every value holds the empty
     * string. What keeps it from being a way to dump the directory is the
     * limit, which is why the limit is not optional.
     */
    public function testAnEmptyMatchIsAMatchAndTheLimitIsWhatGuardsIt(): void
    {
        $this->expectException(Forbidden::class);

        $this->search($this->byName(''), null, '0', 2);
    }

    /**
     * §9.4, postconditions: "(DAV:number-of-matches-within-limits): The
     * number of matching principals must fall within server-specific,
     * predefined limits." The precondition element is what tells a client to
     * narrow its search rather than to try again with other credentials.
     */
    public function testTooManyMatchesIsRefusedWithTheElementTheRfcNames(): void
    {
        $server = $this->server();
        $report = new Report($server);

        $report->on(
            '{DAV:}principal-property-search',
            (new PrincipalPropertySearch($server, SearchableProperty::standard(), 1))(...),
        );
        $report->register();

        // Wired to the method and asked for as a client asks, so that the
        // refusal is seen the way a client sees it: the status, and the
        // element beside it that says what to do about it.
        $response = $server->handle(new Request(
            'REPORT',
            '/principals',
            headers: new Headers(['Depth' => '0']),
            body: new Body(self::BY_NAME),
        ));

        self::assertSame(403, $response->status());
        self::assertStringContainsString('<d:number-of-matches-within-limits/>', (string) $response->body());
    }

    /**
     * §9.4 has `DAV:prop` optional. Without it a client is asking only who
     * matched, and it gets the hrefs — a response still carries a `propstat`,
     * because RFC 4918 §14.24 asks for one and a strict client refuses a
     * document without it.
     */
    public function testWithoutAPropTheMatchesAreStillNamed(): void
    {
        $body = (string) $this->search(<<<'XML'
            <D:principal-property-search xmlns:D="DAV:">
              <D:property-search>
                <D:prop><D:displayname/></D:prop>
                <D:match>john</D:match>
              </D:property-search>
            </D:principal-property-search>
            XML)->body();

        self::assertStringContainsString('<d:href>/principals/jdoe</d:href>', $body);
        self::assertStringContainsString('<d:propstat>', $body);
    }

    /**
     * RFC 4918 §8.3: a collection that matched is named with a trailing
     * slash, the same as anywhere else. A client appends to it.
     */
    public function testACollectionThatMatchedIsNamedWithASlash(): void
    {
        $server = $this->server();

        // The name comes from a listener rather than from the node, which is
        // the other half of the point: **a searchable property need not
        // belong to the principal backend at all.** That is why the matching
        // happens where the answers are assembled, and why no `search()` was
        // put into the backend contract.
        $server->events()->on(
            PropertiesRequested::class,
            static function (PropertiesRequested $event): void {
                if ($event->result()->path() === 'principals/groups') {
                    $event->result()->set('{DAV:}displayname', 'every group');
                }
            },
        );

        $body = (string) $this->search($this->byName('every group'), $server)->body();

        self::assertStringContainsString('<d:href>/principals/groups/</d:href>', $body);
    }

    /**
     * **§9.4: with `DAV:apply-to-principal-collection-set`, the search moves.**
     * "the request is applied instead to each collection identified by the
     * DAV:principal-collection-set property of the resource identified by the
     * Request-URI" — which is how a client searches everybody without knowing
     * where the principals are mounted.
     */
    public function testAppliesToThePrincipalCollectionSetWhenAsked(): void
    {
        $server = $this->server();

        $server->events()->on(
            PropertiesRequested::class,
            static function (PropertiesRequested $event): void {
                $set = new Element('{DAV:}principal-collection-set');
                $href = new Element('{DAV:}href');

                $href->appendText('/principals');
                $set->append($href);
                $event->result()->set('{DAV:}principal-collection-set', $set);
            },
        );

        $body = (string) $this->search(<<<'XML'
            <D:principal-property-search xmlns:D="DAV:">
              <D:property-search>
                <D:prop><D:displayname/></D:prop>
                <D:match>john</D:match>
              </D:property-search>
              <D:apply-to-principal-collection-set/>
            </D:principal-property-search>
            XML, $server, '0', 250, '/')->body();

        self::assertStringContainsString('<d:href>/principals/jdoe</d:href>', $body);
    }

    /**
     * **§9.4.1: a compound value is matched piece by piece.** An address set
     * holds two hrefs, and a string that runs from the end of one into the
     * start of the next was never in either of them — a client searching for
     * it means something else.
     */
    public function testAValueOfSeveralPiecesIsMatchedPieceByPiece(): void
    {
        $inside = (string) $this->search($this->byAddress('john@example.org'))->body();
        $across = (string) $this->search($this->byAddress('example.commailto'))->body();

        self::assertStringContainsString('<d:href>/principals/jdoe</d:href>', $inside);
        self::assertStringNotContainsString('<d:response>', $across);
    }

    /**
     * A request target that holds no members finds nobody. §9.4 has the
     * Request-URI identify a collection; one that does not is a client
     * mistake this cannot correct, and an empty answer says nothing about
     * what is there.
     */
    public function testARequestTargetThatIsNoCollectionFindsNobody(): void
    {
        $response = $this->search(self::BY_NAME, null, '0', 250, '/principals/jdoe');

        self::assertSame(207, $response->status());
        self::assertStringNotContainsString('<d:response>', (string) $response->body());
    }

    private function applyingToTheCollectionSet(): string
    {
        return <<<'XML'
            <D:principal-property-search xmlns:D="DAV:">
              <D:property-search>
                <D:prop><D:displayname/></D:prop>
                <D:match>john</D:match>
              </D:property-search>
              <D:apply-to-principal-collection-set/>
            </D:principal-property-search>
            XML;
    }

    private function byName(string $match): string
    {
        return sprintf(
            '<D:principal-property-search xmlns:D="DAV:"><D:property-search><D:prop><D:displayname/></D:prop>'
            . '<D:match>%s</D:match></D:property-search></D:principal-property-search>',
            $match,
        );
    }

    private function byAddress(string $match): string
    {
        return sprintf(
            '<D:principal-property-search xmlns:D="DAV:"><D:property-search>'
            . '<D:prop><D:alternate-URI-set/></D:prop>'
            . '<D:match>%s</D:match></D:property-search></D:principal-property-search>',
            $match,
        );
    }

    private function search(
        string $body,
        ?Server $server = null,
        string $depth = '0',
        int $atMost = 250,
        string $target = '/principals',
    ): Response {
        $server ??= $this->server();
        $report = new PrincipalPropertySearch($server, SearchableProperty::standard(), $atMost);

        $request = new Request('REPORT', $target, headers: new Headers(['Depth' => $depth]), body: new Body($body));

        return $report($request, $server->reader()->parse($body));
    }

    private function server(): Server
    {
        $root = new MemoryCollection('');
        $principals = new MemoryCollection('principals');
        $groups = new MemoryCollection('groups');

        $principals->add($this->person('jdoe', 'John Doe', 'mailto:jdoe@example.com', 'mailto:john@example.org'));
        $principals->add($this->person('zsmith', 'Zygdoebert Smith', 'mailto:zsmith@example.com'));
        $principals->add($this->person('nobody'));
        $groups->add($this->person('admins', 'Doe Administrators'));
        $principals->add($groups);
        $root->add($principals);

        return new Server(new Tree($root));
    }

    private function person(string $name, ?string $displayName = null, string ...$addresses): MemoryFile
    {
        $person = (new MemoryFile($name, ''))->withProperty(self::COLOUR, 'blue');
        $set = new Element('{DAV:}alternate-URI-set');

        foreach ($addresses as $address) {
            $href = new Element('{DAV:}href');

            $href->appendText($address);
            $set->append($href);
        }

        if ($displayName !== null) {
            $person = $person->withProperty('{DAV:}displayname', $displayName);
        }

        return $person->withProperty('{DAV:}alternate-URI-set', $set);
    }
}
