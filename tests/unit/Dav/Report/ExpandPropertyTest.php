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

namespace DavServices\Tests\Unit\Dav\Report;

use DavServices\Dav\Event\ListingMembers;
use DavServices\Dav\Method\Report;
use DavServices\Dav\Report\ExpandProperty;
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
 * Test list, derived from RFC 3253 §3.8.
 *
 * **One request instead of a chain of them.** Half the properties in this
 * protocol family hold `DAV:href` elements, and a client that wants to show
 * anything about what they point at has to fetch each one: the principal's
 * `DAV:group-membership`, then a `PROPFIND` per group for its name. This
 * report does it in one — "not only decreases the number of requests
 * required, but also allows the server to minimize the number of separate
 * read transactions".
 *
 * ## The body does not look like a `DAV:prop`
 *
 * It is `DAV:property` elements carrying **attributes**: `name` is required
 * and `namespace` defaults to `DAV:`. Nesting them is what asks for the
 * expansion, and the nesting can go on: "the nested DAV:property elements can
 * in turn contain DAV:property elements, so that multiple levels of DAV:href
 * expansion can be requested".
 *
 * ## What replacement means
 *
 * "Every DAV:href in the value of the corresponding property is replaced by a
 * DAV:response element". The href goes; a whole response takes its place,
 * inside the property value. The RFC puts it plainly: the report "effectively
 * modifies the DTD of every property by replacing every occurrence of `href`
 * in the DTD with `href | response`".
 *
 * ## Three things it will not do
 *
 * **An href it cannot resolve here stays an href.** A `mailto:` in a
 * `DAV:alternate-URI-set` is an href and is not a resource on this server; so
 * is a link to another host, and so is a reference to something that has
 * since gone. Leaving it alone loses nothing — the client sees exactly what
 * it would have seen without the report — while a `404` response in its place
 * would be an answer about a resource this server was never asked about.
 *
 * **What the asker may not read is not expanded.** This is the hole worth
 * naming: an expanded href reaches a resource that neither the request guard
 * nor a collection listing covers, so without a check here `expand-property`
 * would hand over the properties of resources a `PROPFIND` would have refused
 * (R-ACL-06). The question goes out as {@see ListingMembers} — the same
 * question the same listener already answers for a collection, asked about
 * all the hrefs at once (R-PRIV-01).
 *
 * **It will not expand without limit.** At *n* hrefs a level and *d* levels
 * of nesting there are *n^d* responses, and the client chooses *d*. The
 * budget is spent **before** each expansion rather than counted after, so it
 * bounds the work and not merely the answer — a cycle between two resources
 * cannot run away either, though the nesting of the body already sees to
 * that.
 */
#[CoversClass(ExpandProperty::class)]
final class ExpandPropertyTest extends TestCase
{
    private const MEMBERSHIP = '{DAV:}group-membership';

    private const ADDRESSES = '{DAV:}alternate-URI-set';

    private const COLOUR = '{https://dav.services/test}colour';

    private const NOTE = '{https://dav.services/test}note';

    private const EXPAND_MEMBERSHIP = <<<'XML'
        <D:expand-property xmlns:D="DAV:">
          <D:property name="group-membership">
            <D:property name="displayname"/>
          </D:property>
        </D:expand-property>
        XML;

    /**
     * §3.8: "The response body for a successful request MUST be a
     * DAV:multistatus XML element", and it is about the resource asked.
     */
    public function testAnswersAMultiStatusAboutTheRequestTarget(): void
    {
        $response = $this->expand(self::EXPAND_MEMBERSHIP);

        self::assertSame(207, $response->status());
        self::assertSame('application/xml; charset=utf-8', $response->headers()->first('Content-Type'));
        self::assertStringContainsString('<d:href>/principals/alice</d:href>', (string) $response->body());
    }

    /**
     * A property named without nesting is simply reported, as a `PROPFIND`
     * would — the expansion is the extra, not the whole of it.
     */
    public function testReportsAPropertyThatWasOnlyNamed(): void
    {
        $body = (string) $this->expand($this->asking('<D:property name="displayname"/>'))->body();

        self::assertStringContainsString('<d:displayname>Alice</d:displayname>', $body);
    }

    /**
     * **The name and the namespace come from attributes**, which is what
     * makes this body unlike every other in the family, and `namespace`
     * defaults to `DAV:` when it is left out (§3.8).
     */
    public function testTheNameAndNamespaceComeFromTheAttributes(): void
    {
        $body = (string) $this->expand($this->asking(
            '<D:property name="colour" namespace="https://dav.services/test"/>',
        ))->body();

        self::assertStringContainsString('>blue</', $body);
    }

    /**
     * **The heart of it** (§3.8): every `DAV:href` in the value is replaced
     * by the response of the resource it names, with the nested properties
     * inside.
     */
    public function testReplacesEveryHrefWithTheResponseOfWhatItNames(): void
    {
        $body = (string) $this->expand(self::EXPAND_MEMBERSHIP)->body();

        self::assertStringContainsString(
            '<d:group-membership><d:response><d:href>/principals/admins</d:href>'
            . '<d:propstat><d:prop><d:displayname>Administrators</d:displayname></d:prop>'
            . '<d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>',
            $body,
        );
        self::assertStringContainsString('<d:displayname>Staff</d:displayname>', $body, 'and the second href too');

        // **Replaced, not reported beside.** The expanded value has to stand
        // where the property stood, in the block of the status it came to —
        // an answer carrying both would leave a client to guess which one
        // the server meant.
        self::assertStringNotContainsString('<d:group-membership><d:href>', $body);
    }

    /**
     * §3.8: "The nested DAV:property elements can in turn contain
     * DAV:property elements, so that multiple levels of DAV:href expansion
     * can be requested." The client decides how deep, by how deep it nests.
     */
    public function testTheExpansionGoesAsDeepAsTheBodyNests(): void
    {
        $body = (string) $this->expand(<<<'XML'
            <D:expand-property xmlns:D="DAV:">
              <D:property name="group-membership">
                <D:property name="group-membership">
                  <D:property name="displayname"/>
                </D:property>
              </D:property>
            </D:expand-property>
            XML)->body();

        // alice is in admins, and admins is in everyone: two levels down.
        self::assertStringContainsString('<d:displayname>Everyone</d:displayname>', $body);
    }

    /**
     * **An href does not have to be a direct child of the property**, and the
     * most useful expansion there is proves it: a `DAV:acl` keeps its
     * principals two levels down, inside `DAV:ace` and `DAV:principal`. A
     * report that only looked at the top level would leave exactly the hrefs
     * a client most wants filled in.
     */
    public function testAnHrefNestedInsideTheValueIsExpandedToo(): void
    {
        $body = (string) $this->expand(<<<'XML'
            <D:expand-property xmlns:D="DAV:">
              <D:property name="acl">
                <D:property name="displayname"/>
              </D:property>
            </D:expand-property>
            XML)->body();

        self::assertStringContainsString(
            '<d:ace><d:principal><d:response><d:href>/principals/admins</d:href>',
            $body,
        );
        self::assertStringContainsString('<d:displayname>Administrators</d:displayname>', $body);
        self::assertStringContainsString('<d:displayname>Staff</d:displayname>', $body, 'the second entry too');
        self::assertStringContainsString('<d:href>/principals/</d:href>', $body, 'and the collection it came from');
    }

    /**
     * **Several properties in one body, each accounted for** (R-DAV-04): the
     * one this resource has and the one it does not, in blocks of their own.
     * A client that asked for two and was handed one has nothing to tell it
     * which went missing.
     */
    public function testReportsSeveralPropertiesAndAccountsForEachOfThem(): void
    {
        $body = (string) $this->expand($this->asking(
            '<D:property name="displayname"/><D:property name="getctag"/>',
        ))->body();

        self::assertStringContainsString('<d:displayname>Alice</d:displayname>', $body);
        self::assertStringContainsString('HTTP/1.1 404 Not Found', $body);
    }

    /**
     * **Text that stands beside the hrefs stays.** Replacing an href does not
     * make the rest of the value disappear — a property is whatever its
     * owner put in it, and a report that quietly dropped the words around the
     * links would be handing back a different property.
     */
    public function testKeepsTextThatStandsBesideTheHrefs(): void
    {
        $body = (string) $this->expand($this->asking(
            '<D:property name="note" namespace="https://dav.services/test">'
            . '<D:property name="displayname"/></D:property>',
        ))->body();

        self::assertStringContainsString('see also', $body);
        self::assertStringContainsString('<d:displayname>Administrators</d:displayname>', $body);
    }

    /**
     * **An href may be written across lines**, and RFC 3253 §3.8.1 writes one
     * that way in its own example — indented, with the URL on a line of its
     * own. A server comparing the raw text would find nothing to expand and
     * would answer, quite correctly by its own lights, that there was nothing
     * there.
     */
    public function testAnHrefWrittenAcrossLinesIsStillFound(): void
    {
        $body = (string) $this->expand($this->asking(
            '<D:property name="owner"><D:property name="displayname"/></D:property>',
        ))->body();

        self::assertStringContainsString('<d:displayname>Staff</d:displayname>', $body);
    }

    /**
     * **Exactly the budget is still expanded.** „At most" means the last one
     * allowed is allowed; a report that refused at the limit would expand one
     * fewer than it promised, and nobody would know which.
     */
    public function testExactlyTheBudgetIsStillExpanded(): void
    {
        $response = $this->expand(self::EXPAND_MEMBERSHIP, atMost: 2);

        self::assertSame(207, $response->status());
        self::assertStringContainsString('<d:displayname>Staff</d:displayname>', (string) $response->body());
    }

    /**
     * A property named without nesting keeps its hrefs as hrefs. Expanding
     * what nobody asked to expand would answer a question nobody asked.
     */
    public function testAPropertyNobodyAskedToExpandKeepsItsHrefs(): void
    {
        $body = (string) $this->expand($this->asking('<D:property name="group-membership"/>'))->body();

        self::assertStringContainsString('<d:href>/principals/admins</d:href>', $body);
        self::assertStringNotContainsString('<d:displayname>Administrators</d:displayname>', $body);
    }

    /**
     * **A `mailto:` is an href and is not a resource here.** Leaving it as it
     * stands loses nothing: the client sees what it would have seen without
     * the report, which is the address it was after.
     */
    public function testAnHrefThisServerDoesNotServeStaysAnHref(): void
    {
        $body = (string) $this->expand(<<<'XML'
            <D:expand-property xmlns:D="DAV:">
              <D:property name="alternate-URI-set">
                <D:property name="displayname"/>
              </D:property>
            </D:expand-property>
            XML)->body();

        self::assertStringContainsString('<d:href>mailto:alice@example.com</d:href>', $body);
    }

    /**
     * And so does one that names a resource this server has not got: a
     * reference that has gone stale is not this report's to correct, and a
     * `404` response in its place would be an answer about something nobody
     * asked about.
     */
    public function testAnHrefPointingAtNothingStaysAnHref(): void
    {
        $body = (string) $this->expand(self::EXPAND_MEMBERSHIP, target: '/principals/bob')->body();

        self::assertStringContainsString('<d:href>/principals/vanished</d:href>', $body);
    }

    /**
     * And one on another server: this one cannot report on it, and saying
     * so with a status would be claiming to know something about somebody
     * else's resource.
     */
    public function testAnHrefOnAnotherServerStaysAnHref(): void
    {
        $body = (string) $this->expand(self::EXPAND_MEMBERSHIP, target: '/principals/bob')->body();

        self::assertStringContainsString('<d:href>https://other.example.com/principals/carol</d:href>', $body);
    }

    /**
     * **The whole body is looked over before any of it is answered.** A
     * nameless property deeper down would otherwise be complained about only
     * if the expansion happened to reach it — so the same body would be
     * refused or answered depending on what the resources held, which is no
     * way to tell a client its request was wrong.
     */
    public function testANestedPropertyWithoutANameIsRefusedToo(): void
    {
        $this->expectException(BadRequest::class);

        $this->expand(<<<'XML'
            <D:expand-property xmlns:D="DAV:">
              <D:property name="displayname">
                <D:property/>
              </D:property>
            </D:expand-property>
            XML);
    }

    /**
     * **R-ACL-06, and the hole this closes.** An expanded href reaches a
     * resource that neither the request guard nor a collection listing
     * covers — so without this, the report would hand over the properties of
     * a resource a `PROPFIND` would have refused.
     */
    public function testWhatTheAskerMayNotReadIsNotExpanded(): void
    {
        $server = $this->server();

        $server->events()->on(
            ListingMembers::class,
            static fn (ListingMembers $event) => $event->conceal('principals/admins'),
        );

        $body = (string) $this->expand(self::EXPAND_MEMBERSHIP, $server)->body();

        self::assertStringNotContainsString('Administrators', $body);
        self::assertStringContainsString('<d:href>/principals/admins</d:href>', $body, 'the href it already had');
        self::assertStringContainsString('<d:displayname>Staff</d:displayname>', $body, 'and the rest is expanded');
    }

    /**
     * **R-PRIV-01: all the hrefs of one value are asked about at once.** A
     * question per href turns a group of two hundred into two hundred
     * questions, which is the N+1 the resolver contract exists to prevent.
     */
    public function testAsksAboutEveryHrefOfAValueAtOnce(): void
    {
        $server = $this->server();
        $asked = [];

        $server->events()->on(ListingMembers::class, static function (ListingMembers $event) use (&$asked): void {
            $asked[] = $event->members();
        });

        $this->expand(self::EXPAND_MEMBERSHIP, $server);

        self::assertSame([['principals/admins', 'principals/staff']], $asked);
    }

    /**
     * **At *n* hrefs a level and *d* levels there are *n^d* responses, and
     * the client chooses *d*.** So there is a budget, and exceeding it is a
     * refusal rather than a quietly shortened answer.
     */
    public function testTooMuchExpansionIsRefused(): void
    {
        $this->expectException(Forbidden::class);

        $this->expand(self::EXPAND_MEMBERSHIP, atMost: 1);
    }

    /**
     * **And the budget is spent before the work is done, not counted after.**
     * That is what makes it a limit on the work rather than on the answer: a
     * report that expanded everything and then refused would have done all of
     * it already.
     */
    public function testTheBudgetIsSpentBeforeTheWorkIsDone(): void
    {
        $root = $this->tree();
        $staff = $this->personIn($root, 'staff');

        try {
            $this->expand(self::EXPAND_MEMBERSHIP, $this->serverFor($root), atMost: 1);
        } catch (Forbidden) {
            // The refusal is the other test's business; this one counts work.
        }

        self::assertSame([], $staff->askedFor, 'the second one was never read');
    }

    /**
     * **`Depth` other than `0` is refused, and that is this server's limit
     * rather than the RFC's.** RFC 3253 §3.6 gives a report with a `Depth`
     * header a meaning — it is applied to the collection and its members —
     * which multiplies a fan-out this report already has to hold down. It is
     * a seam to open when there is a client that wants it; a missing header
     * means `0` (§3.6), which is what every client sends.
     */
    public function testOnlyDepthZeroIsOffered(): void
    {
        $this->expectException(BadRequest::class);

        $this->expand(self::EXPAND_MEMBERSHIP, depth: '1');
    }

    /**
     * §3.8 has `name` as `#REQUIRED`. One without it names nothing, and
     * guessing which property was meant is not something a server can do.
     */
    public function testAPropertyWithoutANameIsRefused(): void
    {
        $this->expectException(BadRequest::class);

        $this->expand('<D:expand-property xmlns:D="DAV:"><D:property/></D:expand-property>');
    }

    /**
     * A body that names no property at all asks about the resource and
     * nothing on it. The DTD allows it (`property*`), so it is answered
     * rather than refused — with a response that carries no properties,
     * because RFC 4918 §14.24 wants a `propstat` all the same.
     */
    public function testABodyThatNamesNoPropertyIsStillAnswered(): void
    {
        $response = $this->expand('<D:expand-property xmlns:D="DAV:"/>');

        self::assertSame(207, $response->status());
        self::assertStringContainsString('<d:href>/principals/alice</d:href>', (string) $response->body());
    }

    /**
     * R-DAV-04: a property that was named and that nobody has is accounted
     * for, the same as anywhere else.
     */
    public function testAPropertyNobodyHasIsAccountedFor(): void
    {
        $body = (string) $this->expand($this->asking('<D:property name="getctag"/>'))->body();

        self::assertStringContainsString('HTTP/1.1 404 Not Found', $body);
    }

    /**
     * And it works where a client meets it: given to the method, asked for
     * by name, answered.
     */
    public function testAnsweredThroughTheReportMethod(): void
    {
        $server = $this->server();
        $method = new Report($server);

        $method->on('{DAV:}expand-property', (new ExpandProperty($server))(...));
        $method->register();

        $response = $server->handle(new Request(
            'REPORT',
            '/principals/alice',
            body: new Body(self::EXPAND_MEMBERSHIP),
        ));

        self::assertSame(207, $response->status());
        self::assertStringContainsString('<d:displayname>Administrators</d:displayname>', (string) $response->body());
    }

    private function asking(string $property): string
    {
        return sprintf('<D:expand-property xmlns:D="DAV:">%s</D:expand-property>', $property);
    }

    private function expand(
        string $body,
        ?Server $server = null,
        string $depth = '0',
        int $atMost = 250,
        string $target = '/principals/alice',
    ): Response {
        $server ??= $this->server();
        $report = new ExpandProperty($server, $atMost);

        $request = new Request('REPORT', $target, headers: new Headers(['Depth' => $depth]), body: new Body($body));

        return $report($request, $server->reader()->parse($body));
    }

    private function server(): Server
    {
        return $this->serverFor($this->tree());
    }

    private function serverFor(MemoryCollection $root): Server
    {
        return new Server(new Tree($root));
    }

    /**
     * Alice is in two groups; one of those is in a third, so that a body can
     * ask for two levels. Bob's membership points at somebody who has gone.
     */
    private function tree(): MemoryCollection
    {
        $root = new MemoryCollection('');
        $principals = new MemoryCollection('principals');

        $alice = (new MemoryFile('alice', ''))
            ->withProperty('{DAV:}displayname', 'Alice')
            ->withProperty(self::COLOUR, 'blue')
            ->withProperty(self::MEMBERSHIP, self::hrefs(self::MEMBERSHIP, '/principals/admins', '/principals/staff'))
            ->withProperty(self::ADDRESSES, self::hrefs(self::ADDRESSES, 'mailto:alice@example.com'))
            ->withProperty('{DAV:}acl', self::acl())
            ->withProperty(self::NOTE, self::note())
            ->withProperty('{DAV:}owner', self::ownerAcrossLines());

        $admins = (new MemoryFile('admins', ''))
            ->withProperty('{DAV:}displayname', 'Administrators')
            ->withProperty(self::MEMBERSHIP, self::hrefs(self::MEMBERSHIP, '/principals/everyone'));

        $bob = (new MemoryFile('bob', ''))
            ->withProperty('{DAV:}displayname', 'Bob')
            ->withProperty(self::MEMBERSHIP, self::hrefs(self::MEMBERSHIP, '/principals/vanished', 'https://other.example.com/principals/carol'));

        $principals->add($alice);
        $principals->add($admins);
        $principals->add((new MemoryFile('staff', ''))->withProperty('{DAV:}displayname', 'Staff'));
        $principals->add((new MemoryFile('everyone', ''))->withProperty('{DAV:}displayname', 'Everyone'));
        $principals->add($bob);
        $root->add($principals);

        return $root;
    }

    private function personIn(MemoryCollection $root, string $name): MemoryFile
    {
        $principals = $root->child('principals');

        self::assertInstanceOf(MemoryCollection::class, $principals);

        $person = $principals->child($name);

        self::assertInstanceOf(MemoryFile::class, $person);

        return $person;
    }

    /**
     * The shape of RFC 3744 §5.5, where the href a client cares about sits
     * two elements down.
     */
    private static function acl(): Element
    {
        $acl = new Element('{DAV:}acl');

        // The first entry carries two hrefs of its own — RFC 3744 §5.5 lets
        // an entry say where it was inherited from — and there are two
        // entries, so that both the gathering and the rebuilding have more
        // than one of everything to get right.
        $acl->append(self::ace('/principals/admins', '/principals'));
        $acl->append(self::ace('/principals/staff'));

        return $acl;
    }

    private static function ace(string $principal, ?string $inheritedFrom = null): Element
    {
        $ace = new Element('{DAV:}ace');
        $who = new Element('{DAV:}principal');

        $who->append(self::href($principal));
        $ace->append($who);

        if ($inheritedFrom !== null) {
            $inherited = new Element('{DAV:}inherited');

            $inherited->append(self::href($inheritedFrom));
            $ace->append($inherited);
        }

        return $ace;
    }

    private static function href(string $url): Element
    {
        $href = new Element('{DAV:}href');

        $href->appendText($url);

        return $href;
    }

    /**
     * A property that is not only hrefs: words of its own, and a link in the
     * middle of them.
     */
    private static function note(): Element
    {
        $note = new Element(self::NOTE);

        $note->appendText('see also');
        $note->append(self::href('/principals/admins'));

        return $note;
    }

    /**
     * An href written the way RFC 3253 §3.8.1 writes one: on a line of its
     * own, indented.
     */
    private static function ownerAcrossLines(): Element
    {
        $owner = new Element('{DAV:}owner');
        $href = new Element('{DAV:}href');

        $href->appendText("\n        /principals/staff\n      ");
        $owner->append($href);

        return $owner;
    }

    private static function hrefs(string $name, string ...$urls): Element
    {
        $value = new Element($name);

        foreach ($urls as $url) {
            $href = new Element('{DAV:}href');

            $href->appendText($url);
            $value->append($href);
        }

        return $value;
    }
}
