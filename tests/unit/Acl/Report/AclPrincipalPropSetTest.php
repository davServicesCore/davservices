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

use DavServices\Acl\PrincipalCollection;
use DavServices\Acl\Report\AclPrincipalPropSet;
use DavServices\Dav\Acl\PrincipalInfo;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
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
 * Test list, derived from RFC 3744 §9.2.
 *
 * **Support for this report is REQUIRED**, and this server did not have it —
 * found when the conformance check asked what announcing `access-control` in
 * `OPTIONS` commits a server to. §7.2: that value "MUST indicate that the
 * server supports all MUST level requirements and REQUIRED features specified
 * in this document". Two of the four reports in §9 were missing; this is one.
 *
 * ## What it is for
 *
 * §9.2 says it plainly: "One expected use of this report is to retrieve the
 * human readable name (found in the DAV:displayname property) of each
 * principal found in an ACL. This is useful for constructing user interfaces
 * that show each ACE in a human readable form."
 *
 * Without it, a client reading `DAV:acl` has a list of URLs and no way to
 * show a person's name beside an entry without one request per principal.
 *
 * ## Where the principals come from
 *
 * **From the `DAV:acl` property, not from the resolver.** The report asks the
 * server the same question a client would, so there is one source of truth
 * and whatever access control says about reading that list applies here too.
 *
 * And the hrefs are taken from `ace → principal` in particular, not from
 * wherever they appear: a `DAV:inherited` element holds an href as well
 * (§5.5.2), and that one names the collection an entry was inherited from
 * rather than a principal.
 *
 * ## Two rules the wording settles
 *
 * **A principal named twice is reported once**: "In the case where a
 * principal URL appears multiple times, the DAV:acl-principal-prop-set report
 * MUST return the properties for that principal only once." A list granting
 * read in one entry and write in another names the same person twice, and a
 * client showing each of them a row would show the person twice.
 *
 * **Only `DAV:href` principals are reported.** §9.2's normative sentence is
 * about "each principal identified by an http(s) URL listed in a DAV:principal
 * XML element" — `DAV:all`, `DAV:authenticated` and the rest name no
 * principal resource, so there are no properties of theirs to fetch.
 */
#[CoversClass(AclPrincipalPropSet::class)]
final class AclPrincipalPropSetTest extends TestCase
{
    private const ASKING = <<<'XML'
        <D:acl-principal-prop-set xmlns:D="DAV:">
          <D:prop><D:displayname/></D:prop>
        </D:acl-principal-prop-set>
        XML;

    /**
     * §9.2: "The response body for a successful request MUST be a
     * DAV:multistatus XML element (i.e., the response uses the same format as
     * the response for PROPFIND)."
     */
    public function testAnswersAMultiStatus(): void
    {
        $response = $this->ask(self::ASKING);

        self::assertSame(207, $response->status());
        self::assertSame('application/xml; charset=utf-8', $response->headers()->first('Content-Type'));
        self::assertStringContainsString('<d:multistatus', (string) $response->body());
    }

    /**
     * **The point of the report**: the name beside each entry, in one
     * request instead of one per principal.
     */
    public function testNamesEveryPrincipalInTheList(): void
    {
        $body = (string) $this->ask(self::ASKING)->body();

        self::assertStringContainsString('<d:href>/principals/alice</d:href>', $body);
        self::assertStringContainsString('<d:displayname>Alice Ashton</d:displayname>', $body);
        self::assertStringContainsString('<d:displayname>The staff</d:displayname>', $body);
    }

    /**
     * **§9.2: "MUST return the properties for that principal only once".**
     * Alice appears in two entries — one granting read, one granting write —
     * and a client showing a row per response would show her twice.
     */
    public function testAPrincipalNamedTwiceIsReportedOnce(): void
    {
        $body = (string) $this->ask(self::ASKING)->body();

        self::assertSame(1, substr_count($body, '<d:href>/principals/alice</d:href>'));
    }

    /**
     * **A principal that is not one, is not reported.** `DAV:all` and its
     * kin name no principal resource (§5.5.1), so there are no properties of
     * theirs to fetch — and §9.2 asks only for those "identified by an
     * http(s) URL".
     */
    public function testAPrincipalThatIsNoResourceIsNotReported(): void
    {
        $body = (string) $this->ask(self::ASKING)->body();

        self::assertStringNotContainsString('{DAV:}all', $body);
        self::assertStringNotContainsString('<d:all/>', $body);
    }

    /**
     * **An href that names no principal is left alone.** RFC 3744 §5.5.2
     * puts one in `DAV:inherited` to say where an entry came from, and that
     * is a collection rather than a person. A report gathering every href in
     * the value would report the collection as though somebody had been
     * granted something.
     */
    public function testAnHrefThatIsNoPrincipalIsNotReported(): void
    {
        $body = (string) $this->ask(self::ASKING)->body();

        self::assertStringNotContainsString('<d:href>/calendars/</d:href>', $body);
    }

    /**
     * A stale entry says so. The report exists to describe a list to a
     * person, and "this entry points at somebody who is gone" is exactly
     * what an administrator needs to see.
     */
    public function testAPrincipalThatHasGoneIsReportedAsMissing(): void
    {
        $body = (string) $this->ask(self::ASKING)->body();

        self::assertStringContainsString('<d:href>/principals/vanished</d:href>', $body);
        self::assertStringContainsString('HTTP/1.1 404 Not Found', $body);
    }

    /**
     * §9.2: "In the case where there are no response elements, the returned
     * multistatus XML element is empty." Not a failure: the list was read,
     * and nobody is in it.
     */
    public function testAResourceWithNoEntriesAnswersAnEmptyMultiStatus(): void
    {
        $response = $this->ask(self::ASKING, target: '/calendars/quiet.ics');

        self::assertSame(207, $response->status());
        self::assertStringNotContainsString('<d:response>', (string) $response->body());
    }

    /**
     * **A resource with no list at all is not a failure.** Most resources on
     * most servers have no `DAV:acl` — access control is a plugin here
     * (R-ARC-02) — and asking about one of those is a fair question with an
     * empty answer.
     */
    public function testAResourceWithNoListAtAllAnswersAnEmptyMultiStatus(): void
    {
        $response = $this->ask(self::ASKING, target: '/calendars/plain.ics');

        self::assertSame(207, $response->status());
        self::assertStringNotContainsString('<d:response>', (string) $response->body());
    }

    /**
     * **Something that is no entry is ignored**, which is the XML element
     * ignore rule RFC 3744 §10 requires of every implementation (RFC 2518
     * §23.3.2). An extension putting its own element in a list should not
     * make this report refuse to describe the entries beside it.
     */
    public function testSomethingThatIsNoEntryIsIgnored(): void
    {
        $body = (string) $this->ask(self::ASKING, target: '/calendars/odd.ics')->body();

        self::assertStringContainsString('<d:href>/principals/alice</d:href>', $body);
        self::assertStringNotContainsString('not-an-ace', $body);
    }

    /**
     * **A principal on another server is not reported.** There are no
     * properties of it this server could fetch, and inventing a `404` would
     * claim to know it is not there — which is somebody else's business.
     */
    public function testAPrincipalOnAnotherServerIsNotReported(): void
    {
        $body = (string) $this->ask(self::ASKING, target: '/calendars/odd.ics')->body();

        self::assertStringNotContainsString('other.example.com', $body);
    }

    /**
     * **An inverted entry is stepped over.** §5.5.1 lets a server wrap the
     * principal in `DAV:invert` to mean everybody *except* that one — so the
     * entry has no `DAV:principal` of its own, and a report reaching straight
     * for one would fall over.
     *
     * Skipping it is also the honest answer here: this server declares
     * `DAV:no-invert` in `DAV:acl-restrictions` (§5.6.2), so it writes no
     * such entry — and listing the named principal among those who have
     * access would say the opposite of what the entry means.
     */
    public function testAnInvertedEntryIsSteppedOver(): void
    {
        $body = (string) $this->ask(self::ASKING, target: '/calendars/odd.ics')->body();

        self::assertStringNotContainsString('/principals/inverted', $body);
        self::assertStringContainsString('<d:href>/principals/alice</d:href>', $body, 'and the rest is read');
    }

    /**
     * **An href written across lines is still that href.** The examples in
     * RFC 3744 §5.5 are indented, a list written out by a person is indented,
     * and a report comparing the raw text would find a principal nobody has.
     */
    public function testAnHrefWrittenAcrossLinesIsStillFound(): void
    {
        $body = (string) $this->ask(self::ASKING, target: '/calendars/odd.ics')->body();

        self::assertStringContainsString('<d:displayname>The staff</d:displayname>', $body);
    }

    /**
     * §9.2: "This report is only defined when the Depth header has value
     * `0`; other values result in a 400 (Bad Request) error response."
     */
    #[DataProvider('depthsThatAreNotZero')]
    public function testOnlyDepthZeroIsDefined(string $depth): void
    {
        $this->expectException(BadRequest::class);

        $this->ask(self::ASKING, depth: $depth);
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
     * RFC 3253 §3.6: a missing header is `Depth: 0`, which is what every
     * client sends.
     */
    public function testAMissingDepthIsDepthZero(): void
    {
        self::assertSame(207, $this->ask(self::ASKING, depth: null)->status());
    }

    /**
     * §9.2's DTD is `ANY` with "at most one DAV:prop element", so a body
     * naming no properties is a body asking who is in the list and nothing
     * about them.
     */
    public function testABodyWithNoPropStillNamesThePrincipals(): void
    {
        $body = (string) $this->ask('<D:acl-principal-prop-set xmlns:D="DAV:"/>')->body();

        self::assertStringContainsString('<d:href>/principals/alice</d:href>', $body);
        self::assertStringNotContainsString('Alice Ashton', $body);
    }

    /**
     * §9.2, postconditions: "(DAV:number-of-matches-within-limits): The
     * number of matching principals must fall within server-specific,
     * predefined limits." An access control list on a busy collection can
     * name a great many people.
     */
    public function testTooManyPrincipalsIsRefusedWithTheElementTheRfcNames(): void
    {
        $this->expectException(Forbidden::class);

        $this->ask(self::ASKING, atMost: 1);
    }

    /**
     * And exactly the limit is still answered: "at most" means the last one
     * allowed is allowed.
     */
    public function testExactlyTheLimitIsStillAnswered(): void
    {
        self::assertSame(207, $this->ask(self::ASKING, atMost: 3)->status());
    }

    private function ask(
        string $body,
        ?string $depth = '0',
        string $target = '/calendars/work.ics',
        int $atMost = 250,
    ): Response {
        $server = $this->server();
        $report = new AclPrincipalPropSet($server, $atMost);
        $headers = $depth === null ? new Headers() : new Headers(['Depth' => $depth]);

        $request = new Request('REPORT', $target, headers: $headers, body: new Body($body));

        return $report($request, $server->reader()->parse($body));
    }

    /**
     * A calendar whose list names Alice twice, the staff once, somebody who
     * has gone, everybody at large, and the collection an entry was
     * inherited from — and a second calendar whose list is empty.
     *
     * **The list is answered by a listener rather than built by the access
     * control plugin**, so that the report is tested against the shape
     * RFC 3744 §5.5 allows and not only against the part of it this library
     * happens to write today: `DAV:inherited` is in the grammar (§5.5.2) and
     * a report that gathered every href would take it for a principal.
     */
    private function server(): Server
    {
        $root = new MemoryCollection('');
        $calendars = new MemoryCollection('calendars');

        $calendars->add(new MemoryFile('work.ics', 'BEGIN:VCALENDAR'));
        $calendars->add(new MemoryFile('quiet.ics', 'BEGIN:VCALENDAR'));
        $calendars->add(new MemoryFile('plain.ics', 'BEGIN:VCALENDAR'));
        $calendars->add(new MemoryFile('odd.ics', 'BEGIN:VCALENDAR'));
        $root->add($calendars);
        $root->add(new PrincipalCollection('principals', new MemoryPrincipalBackend(
            new PrincipalInfo('alice', 'Alice Ashton'),
            new PrincipalInfo('staff', 'The staff'),
        )));

        $server = new Server(new Tree($root));

        $server->events()->on(
            PropertiesRequested::class,
            static function (PropertiesRequested $event): void {
                $result = $event->result();

                if (!$result->wants('{DAV:}acl')) {
                    return;
                }

                $list = match ($result->path()) {
                    'calendars/work.ics' => self::theList(),
                    'calendars/odd.ics' => self::theOddList(),
                    // And `plain.ics` gets nothing at all: most resources on
                    // most servers have no list, and that is not a failure.
                    'calendars/plain.ics' => null,
                    default => new Element('{DAV:}acl'),
                };

                if ($list !== null) {
                    $result->set('{DAV:}acl', $list);
                }
            },
        );

        return $server;
    }

    /**
     * The shape of RFC 3744 §5.5, with everything in it that a report has to
     * tell apart.
     */
    private static function theList(): Element
    {
        $acl = new Element('{DAV:}acl');

        $acl->append(self::entry(self::href('/principals/alice')));
        $acl->append(self::entry(self::href('/principals/staff')));
        // The same person again, in a second entry: §9.2 has her reported once.
        $acl->append(self::entry(self::href('/principals/alice')));
        // A principal that is no resource, so there is nothing to fetch.
        $acl->append(self::entry(new Element('{DAV:}all')));
        // An entry that names somebody who has gone.
        $acl->append(self::entry(self::href('/principals/vanished')));

        // §5.5.2: where an entry came from. An href, and not a principal.
        $inherited = self::entry(self::href('/principals/alice'));
        $from = new Element('{DAV:}inherited');

        $from->append(self::href('/calendars/'));
        $inherited->append($from);
        $acl->append($inherited);

        return $acl;
    }

    /**
     * A list with two things in it this report has to step over: an element
     * that is no entry at all, and a principal on somebody else's server.
     */
    private static function theOddList(): Element
    {
        $acl = new Element('{DAV:}acl');

        $acl->append(new Element('{https://dav.services/test}not-an-ace'));
        $acl->append(self::entry(self::href('https://other.example.com/principals/carol')));

        // §5.5.1: everybody except this one. The principal sits inside
        // `DAV:invert`, so the entry has none of its own.
        $inverted = new Element('{DAV:}ace');
        $invert = new Element('{DAV:}invert');
        $principal = new Element('{DAV:}principal');

        $principal->append(self::href('/principals/inverted'));
        $invert->append($principal);
        $inverted->append($invert);
        $acl->append($inverted);

        // Written the way a person writes one, or a pretty printer.
        $acl->append(self::entry(self::href("
            /principals/staff
          ")));
        $acl->append(self::entry(self::href('/principals/alice')));

        return $acl;
    }

    private static function entry(Element $who): Element
    {
        $ace = new Element('{DAV:}ace');
        $principal = new Element('{DAV:}principal');

        $principal->append($who);
        $ace->append($principal);

        return $ace;
    }

    private static function href(string $url): Element
    {
        $href = new Element('{DAV:}href');

        $href->appendText($url);

        return $href;
    }
}
