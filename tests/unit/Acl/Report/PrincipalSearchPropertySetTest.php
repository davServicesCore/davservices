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

use DavServices\Acl\Report\PrincipalSearchPropertySet;
use DavServices\Acl\SearchableProperty;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Exception\BadRequest;
use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 3744 §9.5.
 *
 * **This report is how a client learns what it may search for.** §9.4 leaves
 * the search method to the server and says plainly that "servers do not
 * typically support searching on all properties" — so without this, a client
 * building a search box would have to guess a property name, and a guess that
 * is wrong matches nothing at all rather than failing.
 *
 * It is the smallest report there is: it reads nothing and answers a list.
 * That makes three decisions worth stating.
 *
 * **It answers `200`, not `207`.** Every other report in this family is a
 * multistatus, and this one is not: §9.5 has the response body be a
 * `DAV:principal-search-property-set`, because nothing here is said *about a
 * resource* — there is no per-resource status to report.
 *
 * **The order is kept.** §9.5 asks a server to put the most frequently
 * searched properties first, so that a client with little room on screen
 * shows the useful ones without scrolling. That makes the order part of the
 * answer, so the list is a list and is written as it was given.
 *
 * **`Depth` is read, and only `0` is allowed.** §9.5 says other values are
 * `400`, and RFC 3253 §3.6 makes a missing header mean `0`. This is the one
 * report where depth means something, which is exactly why
 * {@see \DavServices\Dav\Method\Report} does not read it for everybody.
 *
 * What is **not** here: `Accept-Language`. §9.5 says a server SHOULD consider
 * it, which needs more than one description per property. This library has
 * one, in the language the application wrote it in — an application serving
 * German users builds the list in German. That is a seam to open when
 * somebody brings two languages, not before.
 */
#[CoversClass(PrincipalSearchPropertySet::class)]
final class PrincipalSearchPropertySetTest extends TestCase
{
    private const CALENDAR_USER_ADDRESS = '{urn:ietf:params:xml:ns:caldav}calendar-user-address-set';

    private const ASKED = '<D:principal-search-property-set xmlns:D="DAV:"/>';

    /**
     * §9.5: the body is a `DAV:principal-search-property-set` and the status
     * is a plain `200`. A client handed `207` would go looking for a
     * `DAV:response` that is not there.
     */
    public function testAnswersTwoHundredRatherThanAMultiStatus(): void
    {
        $response = $this->ask();

        self::assertSame(200, $response->status());
        self::assertSame('application/xml; charset=utf-8', $response->headers()->first('Content-Type'));
        self::assertStringContainsString('<d:principal-search-property-set', (string) $response->body());
    }

    /**
     * Each property is named inside a `DAV:prop`, with the description beside
     * it and the language it is in — which is what the DTD of §9.5 requires,
     * element for element.
     */
    public function testNamesEachPropertyWithTheSentenceThatExplainsIt(): void
    {
        $body = (string) $this->ask([
            new SearchableProperty('{DAV:}displayname', 'what a person is called'),
        ])->body();

        self::assertStringContainsString(
            '<d:principal-search-property><d:prop><d:displayname/></d:prop>'
            . '<d:description xml:lang="en">what a person is called</d:description>'
            . '</d:principal-search-property>',
            $body,
        );
    }

    /**
     * **The order is part of the answer** (§9.5): the most frequently
     * searched first, so a client with a short list on screen shows the ones
     * people use.
     */
    public function testKeepsTheOrderItWasGiven(): void
    {
        $body = (string) $this->ask([
            new SearchableProperty('{DAV:}displayname', 'what a person is called'),
            new SearchableProperty('{DAV:}alternate-URI-set', 'another way to reach them'),
        ])->body();

        self::assertLessThan(
            (int) strpos($body, 'alternate-URI-set'),
            (int) strpos($body, 'displayname'),
        );
    }

    /**
     * A server that searches nothing says so, with an empty element. That is
     * a different statement from having no such report — and a client reads
     * it as "do not offer a search", which is the truth.
     */
    public function testAServerThatSearchesNothingSaysSo(): void
    {
        $body = (string) $this->ask([])->body();

        // The root element carries the namespace declaration, so the empty
        // answer is the element closed on itself with nothing inside.
        self::assertStringContainsString('<d:principal-search-property-set xmlns:d="DAV:"/>', $body);
        self::assertStringNotContainsString('<d:principal-search-property>', $body);
    }

    /**
     * A description written in German is labelled German. The alternative is
     * a server that says `en` over text nobody can read as English, and a
     * client believes the label.
     */
    public function testLabelsEachDescriptionWithItsOwnLanguage(): void
    {
        $body = (string) $this->ask([
            new SearchableProperty('{DAV:}displayname', 'wie jemand heißt', 'de'),
        ])->body();

        self::assertStringContainsString('<d:description xml:lang="de">wie jemand heißt</d:description>', $body);
    }

    /**
     * **A property from another namespace keeps it.** The one CalDAV clients
     * actually search by is `CALDAV:calendar-user-address-set`, and a report
     * that could only name `DAV:` properties would be useless to exactly the
     * clients this exists for.
     */
    public function testAPropertyFromAnotherNamespaceKeepsIt(): void
    {
        $body = (string) $this->ask([
            new SearchableProperty(self::CALENDAR_USER_ADDRESS, 'an address this person is invited by'),
        ])->body();

        // The prefix a writer picks for a namespace of its own is its own
        // business; that the namespace is declared is not.
        self::assertStringContainsString('urn:ietf:params:xml:ns:caldav', $body);
        self::assertStringContainsString(':calendar-user-address-set/>', $body);
    }

    /**
     * §9.5: "This report is only defined when the Depth header has value
     * `0`; other values result in a 400 (Bad Request) error response."
     */
    #[DataProvider('depthsThatAreNotZero')]
    public function testOnlyDepthZeroIsDefined(string $depth): void
    {
        $this->expectException(BadRequest::class);

        $this->ask(null, $depth);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function depthsThatAreNotZero(): iterable
    {
        yield 'one' => ['1'];

        yield 'infinity' => ['infinity'];

        yield 'nonsense' => ['deep'];
    }

    /**
     * **A missing `Depth` is `0`** — RFC 3253 §3.6 says so for every report,
     * and it is the opposite of `PROPFIND`, where a missing header means
     * infinity. A report that refused it would refuse the request RFC 3744
     * §9.5.1 shows a client making.
     */
    public function testAMissingDepthIsDepthZero(): void
    {
        $report = new PrincipalSearchPropertySet($this->server(), SearchableProperty::standard());

        $response = $report(new Request('REPORT', '/principals', body: new Body(self::ASKED)));

        self::assertSame(200, $response->status());
    }

    /**
     * **What else the body carried is ignored** (RFC 3744 §10, the XML
     * element ignore rule of RFC 2518 §23.3.2). §9.5 says the element is
     * empty, but a client that sent something extra asked a question this
     * report can still answer, and refusing would be inventing a rule the
     * specification does not have.
     */
    public function testIgnoresWhateverElseTheBodyCarried(): void
    {
        $report = new PrincipalSearchPropertySet($this->server(), SearchableProperty::standard());

        $response = $report(new Request(
            'REPORT',
            '/principals',
            headers: new Headers(['Depth' => '0']),
            body: new Body('<D:principal-search-property-set xmlns:D="DAV:"><D:whatever/></D:principal-search-property-set>'),
        ));

        self::assertSame(200, $response->status());
    }

    /**
     * @param list<SearchableProperty>|null $properties
     */
    private function ask(?array $properties = null, string $depth = '0'): Response
    {
        $report = new PrincipalSearchPropertySet(
            $this->server(),
            $properties ?? SearchableProperty::standard(),
        );

        return $report(new Request(
            'REPORT',
            '/principals',
            headers: new Headers(['Depth' => $depth]),
            body: new Body(self::ASKED),
        ));
    }

    private function server(): Server
    {
        return new Server(new Tree(new MemoryCollection('')));
    }
}
