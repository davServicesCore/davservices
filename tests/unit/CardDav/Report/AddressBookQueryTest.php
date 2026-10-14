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

namespace DavServices\Tests\Unit\CardDav\Report;

use DavServices\CardDav\AddressBook;
use DavServices\CardDav\Report\AddressBookQuery;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\Property\LiveProperties;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Tests\Unit\Dav\PlainNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for `CARDDAV:addressbook-query`. R-CARD-03, P5-03.
 *
 * RFC 6352 §8.6 and §10.3:
 *
 *     <!ELEMENT addressbook-query ((DAV:allprop |
 *                                   DAV:propname |
 *                                   DAV:prop)?, filter, limit?)>
 *
 * "The format of this report is modeled on the PROPFIND method. […] Support
 * for the CARDDAV:addressbook-query REPORT is REQUIRED."
 *
 * The filter itself is tested where it lives, under `CardDav\Filter`. What is
 * tested here is the report around it: the scope the `Depth` header sets, the
 * multistatus, the limit, and the shape §8.6.2 gives a result set that was cut
 * short.
 */
#[CoversClass(AddressBookQuery::class)]
final class AddressBookQueryTest extends TestCase
{
    private const BOOK = '/addressbooks/default';

    /** A card the filters below look for by name, by nickname and by number. */
    private const SIMON = "BEGIN:VCARD\r\nVERSION:4.0\r\nUID:simon\r\nFN:Simon Perreault\r\n"
        . "NICKNAME:Si\r\nTEL;TYPE=work:+1 418 656 9254\r\nEND:VCARD\r\n";

    private const TIM = "BEGIN:VCARD\r\nVERSION:4.0\r\nUID:tim\r\nFN:Tim Howes\r\n"
        . "TEL;TYPE=home:+1 555 0100\r\nEND:VCARD\r\n";

    private const ANNA = "BEGIN:VCARD\r\nVERSION:4.0\r\nUID:anna\r\nFN:Anna Perreault\r\nEND:VCARD\r\n";

    /** Everyone called Perreault: two of the three cards above. */
    private const BY_SURNAME = '<prop-filter name="FN"><text-match>perreault</text-match></prop-filter>';

    public function testFindsTheCardsTheFilterMatches(): void
    {
        $body = (string) $this->query(self::BY_SURNAME)->body();

        self::assertStringContainsString('/addressbooks/default/simon.vcf', $body);
        self::assertStringContainsString('/addressbooks/default/anna.vcf', $body);
        self::assertStringNotContainsString('tim.vcf', $body);
    }

    public function testAnswersTwoOhSevenWithAMultistatus(): void
    {
        $response = $this->query(self::BY_SURNAME);

        self::assertSame(207, $response->status());
        self::assertSame('application/xml; charset=utf-8', $response->headers()->first('Content-Type'));
        self::assertStringContainsString('<d:multistatus', (string) $response->body());
    }

    /**
     * §8.6: "In the case where there are no response elements, the returned
     * DAV:multistatus XML element is empty." So a search that finds nobody is
     * an answer rather than a `404`.
     */
    public function testAMatchlessSearchIsAnEmptyMultistatus(): void
    {
        $response = $this->query('<prop-filter name="FN"><text-match>nobody</text-match></prop-filter>');

        self::assertSame(207, $response->status());
        self::assertStringNotContainsString('<d:response>', (string) $response->body());
    }

    /**
     * **§8.6 makes the header a MUST: "The request MUST include a Depth
     * header. The scope of the query is determined by the value of the Depth
     * header."**
     *
     * RFC 3253 §3.6 would have a missing header mean `Depth: 0`, and that
     * default is deliberately not applied here: at depth zero an address book
     * collection holds no address object of its own, so a client that forgot
     * the header would be told its search found nothing. The memo raised the
     * header to a MUST for exactly that reason, and a refusal says what an
     * empty answer would hide.
     */
    public function testRefusesARequestWithoutADepthHeader(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Depth');

        $this->query(self::BY_SURNAME, null);
    }

    public function testRefusesADepthTheProtocolHasNoMeaningFor(): void
    {
        $this->expectException(BadRequest::class);

        $this->query(self::BY_SURNAME, '2');
    }

    /**
     * **At depth zero the query is about the Request-URI itself**, which is an
     * address object only when the Request-URI names one. §5.1 settles that: a
     * collection is not an address object, so an address book asked at depth
     * zero matches nothing however the filter reads.
     */
    public function testAtDepthZeroACollectionHoldsNoAddressObjectOfItsOwn(): void
    {
        $response = $this->query(self::BY_SURNAME, '0');

        self::assertStringNotContainsString('<d:response>', (string) $response->body());
    }

    public function testAtDepthZeroACardAnswersForItself(): void
    {
        $body = (string) $this->query(self::BY_SURNAME, '0', self::BOOK . '/simon.vcf')->body();

        self::assertStringContainsString('/addressbooks/default/simon.vcf', $body);
    }

    /**
     * §8.6's own example: "to query all address object resources in an address
     * book collection, the REPORT would use the address book collection as the
     * Request-URI and specify a Depth of 1 or infinity."
     */
    public function testAtInfiniteDepthTheCardsBelowAHomeAreReached(): void
    {
        $body = (string) $this->query(self::BY_SURNAME, 'infinity', '/addressbooks')->body();

        self::assertStringContainsString('/addressbooks/default/simon.vcf', $body);
    }

    /**
     * And the same request one level up at depth one finds nothing but the
     * address book, which is no address object.
     */
    public function testAtDepthOneAHomeHoldsOnlyAddressBooks(): void
    {
        $body = (string) $this->query(self::BY_SURNAME, '1', '/addressbooks')->body();

        self::assertStringNotContainsString('<d:response>', $body);
    }

    /**
     * The DTD has the filter outside the optional group: a query without one
     * is not a query for everything.
     */
    public function testRefusesABodyWithoutAFilter(): void
    {
        $body = '<addressbook-query xmlns="urn:ietf:params:xml:ns:carddav"/>';
        $server = $this->server();

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('filter');

        $this->report($server)($this->request($body, '1', self::BOOK), $server->reader()->parse($body));
    }

    /**
     * §8.6: "the response should follow the same behavior as PROPFIND with
     * respect to the DAV:multistatus response elements used to return specific
     * WebDAV property results. For instance, a request to retrieve the value
     * of a WebDAV property that does not exist is an error and MUST be noted
     * with a response XML element that contains a 404 (Not Found) status
     * value."
     */
    public function testThePropertiesComeBackAsAPropfindWouldAnswerThem(): void
    {
        $body = (string) $this->query(
            self::BY_SURNAME,
            '1',
            self::BOOK,
            '<D:prop xmlns:D="DAV:"><D:getetag/><D:displayname/></D:prop>',
        )->body();

        self::assertStringContainsString('<d:getetag>', $body);
        self::assertStringContainsString('404', $body, 'the property nobody has');
    }

    public function testPropnameAsksForTheNamesAlone(): void
    {
        $body = (string) $this->query(
            self::BY_SURNAME,
            '1',
            self::BOOK,
            '<D:propname xmlns:D="DAV:"/>',
        )->body();

        self::assertStringContainsString('<d:getetag/>', $body);
    }

    public function testAllpropAsksForWhatThereIs(): void
    {
        $body = (string) $this->query(
            self::BY_SURNAME,
            '1',
            self::BOOK,
            '<D:allprop xmlns:D="DAV:"/>',
        )->body();

        self::assertStringContainsString('<d:getetag>', $body);
    }

    /**
     * **The property request is optional, and its absence is not a request for
     * everything.** §8.6 has it that "the request can include XML elements to
     * request WebDAV properties to be returned" — so a client that included
     * none asked for none, and answering `allprop` would send it card data it
     * never asked for.
     *
     * What is left is the resource having matched, which RFC 4918 §14.24 has a
     * shape for: a `DAV:response` is an href with either propstats or a status,
     * and this one carries the status.
     */
    public function testWithoutAPropertyRequestTheHrefComesBackWithAStatus(): void
    {
        $body = (string) $this->query(self::BY_SURNAME)->body();

        self::assertStringContainsString('HTTP/1.1 200 OK', $body);
        self::assertStringNotContainsString('<d:propstat>', $body);
    }

    /**
     * **§8.3: "If the client chooses a collation not supported by the server,
     * the server MUST respond with a CARDDAV:supported-collation precondition
     * error response."** §8.6 lists it among this report's preconditions, so
     * the search never starts: the cards below count their reads, and none of
     * them is opened.
     */
    public function testACollationItHasNotGotIsRefusedBeforeAnythingIsRead(): void
    {
        $card = new CountingCard('one.vcf', self::SIMON);
        $book = (new MemoryCollection('default'))->add($card);
        $root = (new MemoryCollection(''))->add((new MemoryCollection('addressbooks'))->add(AddressBook::of($book)));
        $server = new Server(new Tree($root));
        $body = '<addressbook-query xmlns="urn:ietf:params:xml:ns:carddav"><filter>'
            . '<prop-filter name="FN"><text-match collation="i;ascii-numeric">42</text-match></prop-filter>'
            . '</filter></addressbook-query>';

        try {
            $this->report($server)($this->request($body, '1', self::BOOK), $server->reader()->parse($body));
        } catch (Forbidden $refusal) {
            self::assertSame('{urn:ietf:params:xml:ns:carddav}supported-collation', $refusal->errorElement());
            self::assertSame(0, $card->reads(), 'a precondition is a reason not to run the report');

            return;
        }

        self::fail('A collation this server has not got was accepted.');
    }
    /**
     * **§8.6.2's own example, written out as a test.** "If a client-requested
     * limit is being applied, the 507 response for the Request-URI MUST NOT be
     * included in calculating the limit (e.g., if the client requests that only
     * a single result be returned, and multiple matches are present, then the
     * DAV:multistatus response will include one DAV:response for the matching
     * resource and one DAV:response for the 507 status on the Request-URI)."
     *
     * Two responses, then: the card and the `507`. A server that counted the
     * `507` against the limit would send the client no card at all.
     */
    public function testAClientsLimitLeavesRoomForTheTruncationItself(): void
    {
        $body = (string) $this->query(self::BY_SURNAME, '1', self::BOOK, '', '<nresults>1</nresults>')->body();

        self::assertSame(2, substr_count($body, '<d:response>'));
        self::assertSame(1, substr_count($body, '507'));
        self::assertStringContainsString('<d:number-of-matches-within-limits/>', $body);
    }

    /**
     * §8.6.2: the truncation is reported "for the Request-URI", not for the
     * resource that was left out.
     */
    public function testTheTruncationIsReportedForTheRequestUri(): void
    {
        $body = (string) $this->query(self::BY_SURNAME, '1', self::BOOK, '', '<nresults>1</nresults>')->body();

        self::assertMatchesRegularExpression(
            '~<d:response><d:href>/addressbooks/default/?</d:href><d:status>HTTP/1\.1 507~',
            $body,
        );
    }

    /**
     * §8.6.2 asks for the `507` only where the result set "is truncated
     * because of such a limit". A limit nobody reached truncated nothing.
     */
    public function testALimitNobodyReachesSaysNothing(): void
    {
        $body = (string) $this->query(self::BY_SURNAME, '1', self::BOOK, '', '<nresults>9</nresults>')->body();

        self::assertStringNotContainsString('507', $body);
        self::assertSame(2, substr_count($body, '<d:response>'));
    }

    /**
     * A limit of nothing is an unsigned integer like any other, and it cuts
     * every match — which is still a truncation and still says so.
     */
    public function testALimitOfNothingCutsEverythingAndSaysSo(): void
    {
        $body = (string) $this->query(self::BY_SURNAME, '1', self::BOOK, '', '<nresults>0</nresults>')->body();

        self::assertSame(1, substr_count($body, '<d:response>'));
        self::assertStringContainsString('507', $body);
    }

    /**
     * **The server's own ceiling truncates the same way.** §8.6.2 puts the two
     * side by side — "A server MAY limit the number of resources in a response,
     * for example, to limit the amount of work expended in processing a query,
     * or as the result of an explicit limit set by the client" — and gives both
     * the one shape.
     */
    public function testTheServersOwnCeilingTruncatesTheSameWay(): void
    {
        $body = (string) $this->query(self::BY_SURNAME, '1', self::BOOK, '', null, 1)->body();

        self::assertSame(2, substr_count($body, '<d:response>'));
        self::assertStringContainsString('507', $body);
    }

    /**
     * Whichever of the two is the smaller is the one that bites: a client
     * asking for more than the server will give gets the server's number.
     */
    public function testTheSmallerOfTheTwoLimitsIsTheOneThatApplies(): void
    {
        $body = (string) $this->query(self::BY_SURNAME, '1', self::BOOK, '', '<nresults>9</nresults>', 1)->body();

        self::assertSame(2, substr_count($body, '<d:response>'));
        self::assertStringContainsString('507', $body);
    }

    /**
     * §10.6.1: "The value of this element is an unsigned integer", and the DTD
     * says it twice — "nresults value: unsigned integer, must be digits".
     */
    public function testRefusesALimitThatIsNoUnsignedInteger(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('-1');

        $this->query(self::BY_SURNAME, '1', self::BOOK, '', '<nresults>-1</nresults>');
    }

    public function testRefusesALimitThatIsNoNumberAtAll(): void
    {
        $this->expectException(BadRequest::class);

        $this->query(self::BY_SURNAME, '1', self::BOOK, '', '<nresults>many</nresults>');
    }

    /**
     * A number written with room around it in a pretty-printed body is the
     * same number; it is the sign and the decimal point the DTD rules out.
     */
    public function testWhitespaceAroundTheNumberIsNotPartOfIt(): void
    {
        $body = (string) $this->query(
            self::BY_SURNAME,
            '1',
            self::BOOK,
            '',
            "<nresults>\n  1\n</nresults>",
        )->body();

        self::assertSame(2, substr_count($body, '<d:response>'));
    }

    /**
     * `<!ELEMENT limit (nresults)>` — the child is not optional, and a limit
     * that names no kind of limit asks for nothing this server could apply.
     */
    public function testRefusesALimitWithoutAnNresults(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('nresults');

        $this->query(self::BY_SURNAME, '1', self::BOOK, '', limit: '');
    }

    /**
     * **`infinity` is a literal of RFC 4918 §10.2's grammar, and a literal in
     * ABNF is case-insensitive** (RFC 5234 §2.3: "literal text strings are
     * interpreted as a concatenation of printable ASCII characters […] these
     * strings are case-insensitive"). A client that shouted it meant the same
     * thing, and `PROPFIND` reads it the same way.
     */
    public function testTheDepthIsReadWhateverItsCase(): void
    {
        $body = (string) $this->query(self::BY_SURNAME, 'INFINITY', '/addressbooks')->body();

        self::assertStringContainsString('/addressbooks/default/simon.vcf', $body);
    }

    /**
     * **"must be digits" means all of them.** A number with something after it
     * is not an unsigned integer, and taking the digits off the front of it
     * would be this server deciding what the client probably meant.
     */
    public function testRefusesALimitWithSomethingAfterTheDigits(): void
    {
        $this->expectException(BadRequest::class);

        $this->query(self::BY_SURNAME, '1', self::BOOK, '', '<nresults>1x</nresults>');
    }

    /**
     * **§8.6.2 lets a server cut a response short "to limit the amount of work
     * expended in processing a query"**, so a limit that was reached stops the
     * search rather than finishing it quietly. Nothing in the multistatus shows
     * that, which is why the cards here count how often they were read.
     *
     * Two reads: the card that was reported, and the one whose existence made
     * the answer a truncated one. The third is never opened.
     */
    public function testALimitThatWasReachedStopsTheSearch(): void
    {
        $cards = [
            new CountingCard('one.vcf', self::SIMON),
            new CountingCard('two.vcf', self::ANNA),
            new CountingCard('three.vcf', self::ANNA),
        ];

        $book = new MemoryCollection('default');

        foreach ($cards as $card) {
            $book->add($card);
        }

        $root = (new MemoryCollection(''))->add((new MemoryCollection('addressbooks'))->add(AddressBook::of($book)));
        $server = new Server(new Tree($root));
        $body = '<addressbook-query xmlns="urn:ietf:params:xml:ns:carddav">'
            . sprintf('<filter>%s</filter>', self::BY_SURNAME)
            . '<limit><nresults>1</nresults></limit></addressbook-query>';

        $this->report($server)($this->request($body, '1', self::BOOK), $server->reader()->parse($body));

        self::assertSame([1, 1, 0], array_map(static fn (CountingCard $card): int => $card->reads(), $cards));
    }

    /**
     * **§5.1: "Address object resources contained in address book collections
     * MUST contain a single vCard component only."** A member that is not one
     * is therefore not an address object, and §8.6 has the response carry "a
     * DAV:response element for each address object that matched the search
     * filter" — so there is nothing to report for it.
     *
     * Three ways of not being one are passed over here: text that is no vCard
     * at all, a file holding two of them, and a calendar that wandered in. The
     * alternative would be to fail the client's whole search over data it
     * cannot see and could not correct.
     */
    public function testAMemberThatIsNoSingleVcardIsNoAddressObject(): void
    {
        $body = (string) $this->query('<prop-filter name="FN"/>')->body();

        self::assertStringNotContainsString('broken.vcf', $body);
        self::assertStringNotContainsString('twice.vcf', $body);
        self::assertStringNotContainsString('calendar.ics', $body);
        self::assertStringNotContainsString('empty.vcf', $body, 'a file holding no card at all');
        self::assertSame(3, substr_count($body, '<d:response>'), 'the three real cards');
    }

    /**
     * **A member that is no file at all has no vCard to look at.** `INode` is
     * the base of the tree and a collection may hold one — a principal
     * mounted inside one is the usual case — so a walk that assumed every
     * non-collection could be read would fail on a tree that is perfectly
     * legal.
     */
    public function testAMemberThatIsNoFileIsPassedOver(): void
    {
        $body = (string) $this->query('<prop-filter name="FN"/>')->body();

        self::assertStringNotContainsString('a-principal', $body);
    }

    /**
     * A collection below the target is no address object either, whatever its
     * name — and §5.2 keeps one out of an address book in the first place.
     */
    public function testACollectionIsNoAddressObject(): void
    {
        $body = (string) $this->query('<prop-filter name="FN"/>', 'infinity', '/addressbooks')->body();

        self::assertStringNotContainsString('<d:href>/addressbooks/default</d:href>', $body);
        self::assertStringNotContainsString('<d:href>/addressbooks</d:href>', $body);
    }

    /**
     * The whole body, as a client sends it, with the namespace prefixes a
     * client uses: the report has to read its own element as well as the
     * pieces inside it.
     */
    public function testReadsABodyWrittenTheWayTheMemosExamplesAre(): void
    {
        $body = <<<'XML'
            <C:addressbook-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav">
              <D:prop><D:getetag/></D:prop>
              <C:filter>
                <C:prop-filter name="NICKNAME">
                  <C:text-match collation="i;unicode-casemap" match-type="contains">SI</C:text-match>
                </C:prop-filter>
              </C:filter>
            </C:addressbook-query>
            XML;

        $server = $this->server();
        $answer = (string) $this->report($server)(
            $this->request($body, '1', self::BOOK),
            $server->reader()->parse($body),
        )->body();

        self::assertStringContainsString('simon.vcf', $answer);
        self::assertStringNotContainsString('tim.vcf', $answer);
    }

    /**
     * Runs a query, given the pieces that differ from case to case.
     *
     * @param string|null $depth What the `Depth` header says, or null where the
     *                           request carries none
     * @param string|null $limit What goes inside a `CARDDAV:limit` element, or
     *                           null for a body carrying no limit at all
     */
    private function query(
        string $conditions,
        ?string $depth = '1',
        string $path = self::BOOK,
        string $properties = '',
        ?string $limit = null,
        ?int $atMost = null,
    ): Response {
        $body = sprintf(
            '<addressbook-query xmlns="urn:ietf:params:xml:ns:carddav">%s<filter>%s</filter>%s</addressbook-query>',
            $properties,
            $conditions,
            $limit === null ? '' : sprintf('<limit>%s</limit>', $limit),
        );

        $server = $this->server();

        return $this->report($server, $atMost)(
            $this->request($body, $depth, $path),
            $server->reader()->parse($body),
        );
    }

    private function report(?Server $server = null, ?int $atMost = null): AddressBookQuery
    {
        $server ??= $this->server();

        return $atMost === null ? new AddressBookQuery($server) : new AddressBookQuery($server, $atMost);
    }

    private function request(string $body, ?string $depth, string $path): Request
    {
        return new Request(
            'REPORT',
            $path,
            headers: new Headers($depth === null ? [] : ['Depth' => $depth]),
            body: new Body($body),
        );
    }

    /**
     * A home holding one address book, which holds three cards and three
     * members that are no cards at all.
     */
    private function server(): Server
    {
        $root = new MemoryCollection('');
        $home = new MemoryCollection('addressbooks');
        $book = new MemoryCollection('default');

        $book->add(new MemoryFile('simon.vcf', self::SIMON));
        $book->add(new MemoryFile('tim.vcf', self::TIM));
        $book->add(new MemoryFile('anna.vcf', self::ANNA));
        $book->add(new MemoryFile('broken.vcf', 'This is a note, not a card.'));
        $book->add(new MemoryFile('twice.vcf', self::SIMON . self::TIM));
        $book->add(new MemoryFile('calendar.ics', "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n"));
        $book->add(new MemoryFile('empty.vcf'));
        $book->add(new PlainNode('a-principal'));

        $home->add(AddressBook::of($book));
        $root->add($home);

        // The live properties are wired up because §8.6 has the report answer
        // properties the way a PROPFIND does, and a server without them would
        // let this test pass on a report that answered none.
        $events = new EventEmitter();
        $events->on(PropertiesRequested::class, (new LiveProperties())(...));

        return new Server(new Tree($root), $events);
    }
}
