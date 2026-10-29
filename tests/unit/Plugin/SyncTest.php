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

namespace DavServices\Tests\Unit\Plugin;

use DavServices\CalDav\Calendar;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\Method\Delete;
use DavServices\Dav\Method\Move;
use DavServices\Dav\Method\Options;
use DavServices\Dav\Method\PropFind;
use DavServices\Dav\Method\PropPatch;
use DavServices\Dav\Method\Put;
use DavServices\Dav\Method\Report;
use DavServices\Dav\Property\LiveProperties;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Plugin\Sync;
use DavServices\Sync\IRevisions;
use DavServices\Sync\Journal;
use DavServices\Sync\Token;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Tests\Unit\Sync\FixedRevisions;
use DavServices\Tests\Unit\Sync\MemoryJournalBackend;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for the synchronisation plugin. R-SYNC-01, R-SYNC-02, P7-01.
 *
 * It owes three things, and each is a MUST of RFC 6578:
 *
 * - **the report** (§3.2), and §3.2 also has it listed in
 *   `DAV:supported-report-set` "on any collection that supports
 *   synchronization";
 * - **`DAV:sync-token` as a property** (§4): "MUST be defined on all resources
 *   that support the DAV:sync-collection report", protected, and "SHOULD NOT be
 *   returned by a PROPFIND DAV:allprop request";
 * - **the token in an `If` header** (§5): "Servers MUST support use of
 *   DAV:sync-token values in If request headers."
 *
 * The third is the one easiest to leave out, and the one a client notices
 * most: §5 is how it writes into a collection "only if nothing has changed
 * since I last looked".
 */
#[CoversClass(Sync::class)]
final class SyncTest extends TestCase
{
    private const COLLECTION = '/home';

    private const CALENDAR = '/calendar';

    /**
     * §3.2: the report is answered where it is registered. What it answers is
     * tested under `Sync\Report`; what is proved here is that it is reachable
     * at all — without the wiring, `Report` refuses the name with `403` and
     * `DAV:supported-report`.
     */
    public function testRegisterAnswersTheReport(): void
    {
        $server = $this->server();
        $report = new Report($server);

        $this->plugin($server)->register($report);
        $report->register();

        $response = $server->handle(new Request(
            'REPORT',
            self::COLLECTION,
            headers: new Headers(['Depth' => '0']),
            body: new Body(
                '<D:sync-collection xmlns:D="DAV:"><D:sync-token/><D:sync-level>1</D:sync-level>'
                . '<D:prop><D:getetag/></D:prop></D:sync-collection>',
            ),
        ));

        self::assertSame(207, $response->status());
        self::assertStringContainsString('<d:sync-token>', (string) $response->body());
    }

    /**
     * **§3.2: the report MUST be listed in `DAV:supported-report-set`** on any
     * collection that supports synchronisation. `Dav\Method\Report` answers
     * that property from what is registered, so registering the report is what
     * makes the claim — and a claim nobody could act on would be worse than
     * none.
     */
    public function testTheReportIsAnnouncedInTheSupportedReportSet(): void
    {
        $answer = (string) $this->propFind(
            '<propfind xmlns="DAV:"><prop><supported-report-set/></prop></propfind>',
        )->body();

        self::assertStringContainsString('sync-collection', $answer);
    }

    /**
     * **§4: "The DAV:sync-token property MUST be defined on all resources that
     * support the DAV:sync-collection report. It contains the value of the
     * synchronization token as it would be returned by a DAV:sync-collection
     * report on that resource at the same point in time."**
     *
     * So the property and the report answer the same token for the same state,
     * which is what lets a client synchronise first by some other means — §3.1
     * offers `PROPFIND`, `SEARCH` or a CalDAV report — and still hold a usable
     * token afterwards.
     */
    public function testThePropertyIsTheTokenTheReportWouldAnswer(): void
    {
        $server = $this->server();
        $report = new Report($server);

        $this->plugin($server)->register($report);
        $report->register();

        $answer = (string) $server->handle(new Request(
            'PROPFIND',
            self::COLLECTION,
            headers: new Headers(['Depth' => '0']),
            body: new Body('<propfind xmlns="DAV:"><prop><sync-token/></prop></propfind>'),
        ))->body();

        $fromTheReport = (string) $server->handle(new Request(
            'REPORT',
            self::COLLECTION,
            headers: new Headers(['Depth' => '0']),
            body: new Body(
                '<D:sync-collection xmlns:D="DAV:"><D:sync-token/><D:sync-level>1</D:sync-level>'
                . '<D:prop><D:getetag/></D:prop></D:sync-collection>',
            ),
        ))->body();

        self::assertStringContainsString((string) Token::for('home', 7), $answer);
        self::assertStringContainsString((string) Token::for('home', 7), $fromTheReport);
    }

    /**
     * **§4: "It SHOULD NOT be returned by a PROPFIND DAV:allprop request."**
     * A token is of no use to a client that did not ask for it, and it would
     * be in every listing of every collection for ever.
     */
    public function testItIsNotInAnAllpropAnswer(): void
    {
        $answer = (string) $this->propFind('<propfind xmlns="DAV:"><allprop/></propfind>')->body();

        self::assertStringNotContainsString('sync-token', $answer);
    }

    /**
     * And it is named by `DAV:propname`, which asks what a resource has rather
     * than what it says — §4 defines the property on the resource, so a client
     * asking for the names is owed it.
     */
    public function testItIsNamedByAPropnameRequest(): void
    {
        $answer = (string) $this->propFind('<propfind xmlns="DAV:"><propname/></propfind>')->body();

        self::assertStringContainsString('sync-token', $answer);
    }

    /**
     * **§4: "Protected: MUST be protected because this value is created and
     * controlled by the server."** A `PROPPATCH` is therefore refused with
     * RFC 4918 §16's own precondition for one.
     */
    public function testItCannotBeWritten(): void
    {
        $server = $this->server();

        $this->plugin($server)->register(new Report($server));

        $answer = (string) $server->handle(new Request(
            'PROPPATCH',
            self::COLLECTION,
            headers: new Headers(['Content-Type' => 'application/xml']),
            body: new Body(
                '<propertyupdate xmlns="DAV:"><set><prop><sync-token>mine</sync-token></prop></set></propertyupdate>',
            ),
        ))->body();

        self::assertStringContainsString('cannot-modify-protected-property', $answer);
        self::assertStringContainsString('403 Forbidden', $answer);
    }

    /**
     * **A resource that is no collection has no token**, because §4 defines
     * the property on the resources that support the report and §3.2 has the
     * report about a collection. A file answering one would be a client told it
     * could synchronise something it cannot.
     */
    public function testAFileHasNoToken(): void
    {
        $answer = (string) $this->propFind(
            '<propfind xmlns="DAV:"><prop><sync-token/></prop></propfind>',
            self::COLLECTION . '/one.ics',
        )->body();

        self::assertStringContainsString('404 Not Found', $answer);
    }

    /**
     * And neither has a collection this server tracks no revision of: §4's
     * property is for "all resources that support the DAV:sync-collection
     * report", and one that cannot say which state it is in does not.
     */
    public function testACollectionWithNoRevisionHasNoToken(): void
    {
        $answer = (string) $this->propFind(
            '<propfind xmlns="DAV:"><prop><sync-token/></prop></propfind>',
            revisions: FixedRevisions::none(),
        )->body();

        self::assertStringContainsString('404 Not Found', $answer);
    }

    /**
     * **§5: "Servers MUST support use of DAV:sync-token values in If request
     * headers."** This is how a client writes into a collection only if nothing
     * has changed since it last looked:
     *
     * > By using this, clients can ensure requests only complete when there
     * > have been no changes to the content of a collection, by virtue of an
     * > unchanged DAV:sync-token value.
     *
     * §5.1's own example is this `PUT`.
     */
    public function testATokenInAnIfHeaderLetsAConditionalWriteThrough(): void
    {
        $response = $this->putWith(sprintf('<%s> (<%s>)', self::COLLECTION, Token::for('home', 7)));

        self::assertSame(201, $response->status(), (string) $response->body());
    }

    /**
     * And a token of another state stops it, which is the whole point: the
     * collection moved on, so the write is refused rather than applied to a
     * state the client never saw.
     */
    public function testATokenOfAnotherStateStopsTheWrite(): void
    {
        $response = $this->putWith(sprintf('<%s> (<%s>)', self::COLLECTION, Token::for('home', 6)));

        self::assertSame(412, $response->status());
    }

    /**
     * A collection this server tracks no revision of names no state token
     * either, so a condition about one is false — which is the fail-closed
     * answer `Dav\Precondition\RequestConditions` gives throughout: "a
     * condition this server cannot check is not one it may call true".
     */
    public function testACollectionWithNoRevisionNamesNoState(): void
    {
        $response = $this->putWith(
            sprintf('<%s> (<%s>)', self::COLLECTION, Token::for('home', 7)),
            FixedRevisions::none(),
        );

        self::assertSame(412, $response->status());
    }

    /**
     * **§3.2's first duty: "a server needs to keep track of changes to any
     * member URLs and their mapped resources in a collection".**
     *
     * Registering the plugin is what switches that on, so a `PUT` of something
     * new is in the next answer — §3.5.1: "A member URL MUST be reported as
     * changed if it has been newly mapped as a member of the target collection
     * since the request sync-token was generated."
     */
    public function testAMemberThatWasPutThereIsReportedAsChanged(): void
    {
        [$server, $held] = $this->synchronising();

        $server->handle(new Request('PUT', self::COLLECTION . '/new.ics', body: new Body('BEGIN:VCALENDAR')));

        $body = $this->reportOn($server, $held);

        self::assertStringContainsString('/home/new.ics', $body);
        self::assertStringContainsString('<d:propstat>', $body);
        self::assertStringNotContainsString('/home/one.ics', $body);
    }

    /**
     * And a member written over is reported too, §3.5.1: "A member URL MUST be
     * reported as changed if its mapped resource's entity tag value […] has
     * changed since the request sync-token was generated." A `PUT` over
     * something that is already there raises no bind, so a server listening
     * only for that would never mention it again.
     */
    public function testAMemberWrittenOverIsReportedAsChanged(): void
    {
        [$server, $held] = $this->synchronising();

        $server->handle(new Request('PUT', self::COLLECTION . '/one.ics', body: new Body('BEGIN:VCALENDAR')));

        self::assertStringContainsString('/home/one.ics', $this->reportOn($server, $held));
    }

    /**
     * **§3.5.2: "A member MUST be reported as removed if its mapping under the
     * target collection has been removed since the request sync-token was
     * generated."** §3.2 gives it "one DAV:status with a value set to '404 Not
     * Found'" and no properties.
     */
    public function testAMemberThatWasDeletedIsReportedAsRemoved(): void
    {
        [$server, $held] = $this->synchronising();

        $server->handle(new Request('DELETE', self::COLLECTION . '/one.ics'));

        $body = $this->reportOn($server, $held);

        self::assertStringContainsString('/home/one.ics', $body);
        self::assertStringContainsString('<d:status>HTTP/1.1 404 Not Found</d:status>', $body);
        self::assertStringNotContainsString('<d:propstat>', $body);
    }

    /**
     * **A `MOVE` is one removal and one change**, which is the two sentences
     * read together: §3.5.1 counts "member URLs that have been newly mapped as
     * the result of a COPY, MOVE" as changed, and §3.5.2 counts "members that
     * have been unmapped as the result of a MOVE" as removed.
     */
    public function testAMoveIsAChangeWhereItLandedAndARemovalWhereItLeft(): void
    {
        [$server, $held] = $this->synchronising();

        $server->handle(new Request(
            'MOVE',
            self::COLLECTION . '/one.ics',
            headers: new Headers(['Destination' => self::COLLECTION . '/moved.ics']),
        ));

        $body = $this->reportOn($server, $held);

        self::assertStringContainsString('/home/moved.ics', $body);
        self::assertStringContainsString('<d:propstat>', $body);
        self::assertStringContainsString('/home/one.ics', $body);
        self::assertStringContainsString('<d:status>HTTP/1.1 404 Not Found</d:status>', $body);
    }

    /**
     * **And a `MOVE` over something is a change there and not a removal**,
     * which §3.5.1 asks for in as many words: "In the case where a mapping
     * between a member URL and the target collection was removed, then a new
     * mapping with the same URI was created, the member URL MUST be reported
     * as changed and MUST NOT be reported as removed."
     *
     * A client told the destination had gone would delete its copy of
     * something that is there.
     */
    public function testAMoveOverAMemberIsAChangeThereAndNotARemoval(): void
    {
        [$server, $held] = $this->synchronising();

        $server->handle(new Request('PUT', self::COLLECTION . '/new.ics', body: new Body('BEGIN:VCALENDAR')));
        $server->handle(new Request(
            'MOVE',
            self::COLLECTION . '/new.ics',
            headers: new Headers(['Destination' => self::COLLECTION . '/one.ics']),
        ));

        $body = $this->reportOn($server, $held);

        self::assertSame(1, substr_count($body, '/home/one.ics'));
        self::assertStringContainsString('/home/one.ics</d:href><d:propstat>', $body);
    }

    /**
     * **§4: the property is "the value of the synchronization token as it
     * would be returned by a DAV:sync-collection report on that resource at
     * the same point in time"** — which is the sentence that makes one seam
     * answer both. After a write, the property and the report still agree,
     * because there is no second place to ask how far the collection has got.
     */
    public function testThePropertyFollowsTheWritesTheJournalRecorded(): void
    {
        [$server, $held] = $this->synchronising();

        $server->handle(new Request('PUT', self::COLLECTION . '/new.ics', body: new Body('BEGIN:VCALENDAR')));

        $answer = (string) $server->handle(new Request(
            'PROPFIND',
            self::COLLECTION,
            headers: new Headers(['Depth' => '0']),
            body: new Body('<propfind xmlns="DAV:"><prop><sync-token/></prop></propfind>'),
        ))->body();

        self::assertStringNotContainsString($held, $answer);
        self::assertStringContainsString($this->tokenIn($this->reportOn($server, $held)), $answer);
    }

    /**
     * **`CS:getctag`, and the specification that defines it is Apple's own.**
     * There is no RFC: `caldav-ctag-03` (C. Daboo, Apple, 27 May 2015) is a
     * Calendar Server extension, and §4.1 of it is the whole definition —
     *
     * > Name: getctag
     * > Namespace: http://calendarserver.org/ns/
     * > Purpose: Specifies a "synchronization" token used to indicate when the
     * > contents of a calendar or scheduling Inbox or Outbox collection have
     * > changed.
     *
     * — which R-SYNC-06 asks for "zusätzlich", beside §4's own token.
     */
    public function testACalendarCarriesTheTagTheExtensionDefines(): void
    {
        $answer = (string) $this->propFind($this->asking('<T:getctag/>'), self::CALENDAR)->body();

        self::assertStringContainsString('http://calendarserver.org/ns/', $answer);
        self::assertNotSame('', $this->tagIn($answer));
        self::assertStringNotContainsString('404 Not Found', $answer);
    }

    /**
     * **And its value is the token itself, which the specification asks for in
     * as many words.** Its abstract, deprecating itself in favour of RFC 6578:
     *
     * > Whilst most modern clients do support the WebDAV Sync REPORT, servers
     * > MAY continue to support this specification by simply using the
     * > DAV:sync-token property value for the getctag property value, in order
     * > to provide backwards compatibility with old clients.
     *
     * So the two names answer one number, and that is not a shortcut: a server
     * whose tag and token named different states would be one whose answers
     * about the same collection disagree, which is the trap §4 of RFC 6578
     * closes for the property and the report with "at the same point in time".
     */
    public function testTheTagIsTheTokenItself(): void
    {
        $answer = (string) $this->propFind(
            $this->asking('<T:getctag/><D:sync-token/>'),
            self::CALENDAR,
        )->body();

        self::assertSame($this->tokenIn($answer), $this->tagIn($answer));
    }

    /**
     * **The tag comes back for `allprop` and the token does not**, which is
     * the one place the two part company. §4.1 of the extension: it "SHOULD be
     * returned by a PROPFIND DAV:allprop request". §4 of RFC 6578, about its
     * own property: "It SHOULD NOT be returned by a PROPFIND DAV:allprop
     * request."
     *
     * Two SHOULDs pointing opposite ways over the same number, and each is
     * followed for the property it is written about.
     */
    public function testTheTagComesBackForAllpropAndTheTokenDoesNot(): void
    {
        $answer = (string) $this->propFind(
            '<propfind xmlns="DAV:"><allprop/></propfind>',
            self::CALENDAR,
        )->body();

        self::assertStringContainsString('getctag', $answer);
        self::assertStringNotContainsString('sync-token', $answer);
    }

    /**
     * **A collection that is no calendar carries no tag**, §4.1's conformance
     * naming where it belongs: "This property MUST be defined on a calendar or
     * scheduling Inbox or Outbox collection resource."
     *
     * The question is asked of the resource type rather than of a PHP class,
     * because that is what a calendar collection *is*: RFC 4791 §4.2 has one
     * as a collection whose `DAV:resourcetype` carries `CALDAV:calendar`.
     */
    public function testACollectionThatIsNoCalendarCarriesNoTag(): void
    {
        $answer = (string) $this->propFind($this->asking('<T:getctag/>'), self::COLLECTION)->body();

        self::assertStringContainsString('404 Not Found', $answer);
    }

    /**
     * And neither does a file: §4.1 says "collection resource", and a tag on
     * an event would tell a client to poll the thing it already has an
     * `ETag` for.
     */
    public function testAFileCarriesNoTag(): void
    {
        $answer = (string) $this->propFind(
            $this->asking('<T:getctag/>'),
            self::CALENDAR . '/kept.ics',
        )->body();

        self::assertStringContainsString('404 Not Found', $answer);
    }

    /**
     * **A calendar this server tracks no revision of carries no tag either.**
     * §3.1 of the extension has the value "guaranteed to be unique over the
     * lifetime" of the collection, and nothing can promise that about a
     * collection whose changes nobody records — the same reason §4's token is
     * withheld there.
     */
    public function testACalendarWithNoRevisionCarriesNoTag(): void
    {
        $answer = (string) $this->propFind(
            $this->asking('<T:getctag/>'),
            self::CALENDAR,
            FixedRevisions::none(),
        )->body();

        self::assertStringContainsString('404 Not Found', $answer);
    }

    /**
     * **§3.1's MUST, in all three of its cases:** "Whenever a calendar
     * resource is added to, modified or deleted from the calendar collection,
     * the value of the CS:getctag property MUST change."
     *
     * @param string $method What the client does
     * @param string $member Where it does it
     * @param string|null $body What it sends, or nothing for a `DELETE`
     */
    #[DataProvider('whatChangesACalendar')]
    public function testTheTagChangesWhenTheCalendarsContentsChange(
        string $method,
        string $member,
        ?string $body,
    ): void {
        [$server] = $this->synchronising();

        $before = $this->tagOn($server, self::CALENDAR);

        $server->handle(new Request(
            $method,
            self::CALENDAR . $member,
            body: $body === null ? null : new Body($body),
        ));

        self::assertNotSame($before, $this->tagOn($server, self::CALENDAR));
    }

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function whatChangesACalendar(): iterable
    {
        yield 'a resource added' => ['PUT', '/added.ics', 'BEGIN:VCALENDAR'];

        yield 'a resource modified' => ['PUT', '/kept.ics', 'BEGIN:VCALENDAR'];

        yield 'a resource deleted' => ['DELETE', '/kept.ics', null];
    }

    /**
     * And it stands still while nothing happens — the other half of R-SYNC-06,
     * which asks that the value change *genau* on a change of contents. A tag
     * that moved on its own would send every client back for the whole
     * collection, which is the cost this property exists to avoid.
     */
    public function testTheTagStandsStillWhileNothingHappens(): void
    {
        [$server] = $this->synchronising();

        self::assertSame($this->tagOn($server, self::CALENDAR), $this->tagOn($server, self::CALENDAR));
    }

    /**
     * **§4.1: "It MUST be protected".** A client that could write the tag
     * could tell the server that nothing had changed.
     */
    public function testTheTagIsProtected(): void
    {
        $server = $this->server();

        $this->plugin($server)->register(new Report($server));

        $answer = (string) $server->handle(new Request(
            'PROPPATCH',
            self::CALENDAR,
            headers: new Headers(['Content-Type' => 'application/xml']),
            body: new Body(
                '<propertyupdate xmlns="DAV:" xmlns:T="http://calendarserver.org/ns/">'
                . '<set><prop><T:getctag>mine</T:getctag></prop></set></propertyupdate>',
            ),
        ))->body();

        self::assertStringContainsString('cannot-modify-protected-property', $answer);
    }

    /**
     * **§4.1, the sentence that asks for more than monotonicity:** "each
     * change MUST result in a value that is different from any other used with
     * that collection URI." Not merely different from the one before — unique
     * over the collection's whole life, which §3.1 repeats as "guaranteed to
     * be unique over the lifetime".
     *
     * So a series of changes is a series of values with no repeat in it, which
     * a counter that only ever grows satisfies and a hash of the contents
     * would not.
     */
    public function testNoTagIsEverUsedTwiceForTheSameCalendar(): void
    {
        [$server] = $this->synchronising();
        $seen = [$this->tagOn($server, self::CALENDAR)];

        foreach (['one', 'two', 'three'] as $name) {
            $server->handle(new Request(
                'PUT',
                self::CALENDAR . '/' . $name . '.ics',
                body: new Body('BEGIN:VCALENDAR'),
            ));

            $seen[] = $this->tagOn($server, self::CALENDAR);
        }

        $server->handle(new Request('DELETE', self::CALENDAR . '/one.ics'));
        $seen[] = $this->tagOn($server, self::CALENDAR);

        self::assertSame($seen, array_values(array_unique($seen)));
    }

    /**
     * **How much a report will answer at once is the deployment's to set**
     * (R-SYNC-04), and §3.6 has it as the server's own choice: "A server MAY
     * limit the number of member URLs in a response, for example, to limit the
     * amount of work expended in processing a request."
     *
     * So the plugin takes the number and hands it down. **Off by default**,
     * because a MAY not taken is a conforming server and §3.4's "the server
     * MUST return all member URLs of the collection" is the answer a client
     * gets for nothing.
     */
    public function testHowMuchIsAnsweredAtOnceIsTheServersToSet(): void
    {
        [$server, $held] = $this->synchronising(atMost: 1);

        $server->handle(new Request('PUT', self::COLLECTION . '/one.ics', body: new Body('BEGIN:VCALENDAR')));
        $server->handle(new Request('PUT', self::COLLECTION . '/two.ics', body: new Body('BEGIN:VCALENDAR')));

        $answer = $this->reportOn($server, $held);

        self::assertSame(1, substr_count($answer, '<d:propstat>'));
        self::assertStringContainsString('<d:status>HTTP/1.1 507 Insufficient Storage</d:status>', $answer);
    }

    /**
     * And without one set, nothing is cut: the same two writes come back
     * together.
     */
    public function testWithNoLimitSetNothingIsCut(): void
    {
        [$server, $held] = $this->synchronising();

        $server->handle(new Request('PUT', self::COLLECTION . '/one.ics', body: new Body('BEGIN:VCALENDAR')));
        $server->handle(new Request('PUT', self::COLLECTION . '/two.ics', body: new Body('BEGIN:VCALENDAR')));

        $answer = $this->reportOn($server, $held);

        self::assertSame(2, substr_count($answer, '<d:propstat>'));
        self::assertStringNotContainsString('<d:status>HTTP/1.1 507 Insufficient Storage</d:status>', $answer);
    }

    /**
     * It announces no compliance class. RFC 6578 defines none — there is no
     * `DAV` header value in it — and what tells a client the report is there is
     * `DAV:supported-report-set`, which §3.2 names in as many words.
     */
    public function testItAnnouncesNoComplianceClass(): void
    {
        $server = $this->server();

        $server->onMethod('OPTIONS', (new Options($server))(...));
        $this->plugin($server)->register(new Report($server));

        $response = $server->handle(new Request('OPTIONS', self::COLLECTION));

        self::assertStringNotContainsString('sync', $response->headers()->first('DAV') ?? '');
    }

    private function propFind(
        string $body,
        string $path = self::COLLECTION,
        ?IRevisions $revisions = null,
    ): Response {
        $server = $this->server();
        $report = new Report($server);

        $this->plugin($server, $revisions)->register($report);
        $report->register();

        return $server->handle(new Request(
            'PROPFIND',
            $path,
            headers: new Headers(['Depth' => '0']),
            body: new Body($body),
        ));
    }

    private function putWith(string $if, ?IRevisions $revisions = null): Response
    {
        $server = $this->server();

        $this->plugin($server, $revisions)->register(new Report($server));
        $server->onMethod('PUT', (new Put($server))(...));

        return $server->handle(new Request(
            'PUT',
            self::COLLECTION . '/new.ics',
            headers: new Headers(['If' => $if]),
            body: new Body('BEGIN:VCALENDAR'),
        ));
    }

    private function plugin(
        Server $server,
        ?IRevisions $revisions = null,
        int $atMost = IRevisions::EVERYTHING,
    ): Sync {
        return new Sync($server, $revisions ?? FixedRevisions::at(7), $atMost);
    }

    /**
     * A server keeping a journal, together with the token a client holding the
     * state before anything happened would have.
     *
     * The methods that move something in the tree are registered here rather
     * than in the fixture, because what is proved is that the plugin hears
     * them — and a case that recorded the change itself would prove nothing.
     *
     * @return array{Server, string}
     */
    private function synchronising(int $atMost = IRevisions::EVERYTHING): array
    {
        $server = $this->server();
        $journal = new Journal(new MemoryJournalBackend());
        $report = new Report($server);

        $this->plugin($server, $journal, $atMost)->register($report);
        $report->register();

        $server->onMethod('PUT', (new Put($server))(...));
        $server->onMethod('DELETE', (new Delete($server))(...));
        $server->onMethod('MOVE', (new Move($server))(...));

        return [$server, (string) Token::for('home', $journal->revisionOf('home'))];
    }

    /**
     * What a `DAV:sync-collection` carrying that token answers.
     */
    private function reportOn(Server $server, string $token): string
    {
        return (string) $server->handle(new Request(
            'REPORT',
            self::COLLECTION,
            headers: new Headers(['Depth' => '0']),
            body: new Body(sprintf(
                '<D:sync-collection xmlns:D="DAV:"><D:sync-token>%s</D:sync-token>'
                . '<D:sync-level>infinite</D:sync-level><D:prop><D:getetag/></D:prop></D:sync-collection>',
                htmlspecialchars($token, ENT_XML1),
            )),
        ))->body();
    }

    private function tokenIn(string $body): string
    {
        if (preg_match('~<d:sync-token>([^<]+)</d:sync-token>~', $body, $found) !== 1) {
            self::fail('The answer carries no sync-token: ' . $body);
        }

        return $found[1];
    }

    /**
     * A `PROPFIND` body naming properties in both namespaces, so that a case
     * can ask for the tag and the token in one request and compare them.
     */
    private function asking(string $properties): string
    {
        return sprintf(
            '<D:propfind xmlns:D="DAV:" xmlns:T="http://calendarserver.org/ns/"><D:prop>%s</D:prop></D:propfind>',
            $properties,
        );
    }

    /**
     * The tag an answer carries, whichever prefix the writer chose for the
     * extension's namespace.
     */
    private function tagIn(string $body): string
    {
        if (preg_match('~<[^>]*:getctag[^>]*>([^<]+)</[^>]*:getctag>~', $body, $found) !== 1) {
            self::fail('The answer carries no getctag: ' . $body);
        }

        return $found[1];
    }

    /**
     * The tag that server answers for that collection now.
     */
    private function tagOn(Server $server, string $path): string
    {
        return $this->tagIn((string) $server->handle(new Request(
            'PROPFIND',
            $path,
            headers: new Headers(['Depth' => '0']),
            body: new Body($this->asking('<T:getctag/>')),
        ))->body());
    }

    /**
     * A home with one file in it.
     */
    private function server(): Server
    {
        $home = (new MemoryCollection('home'))->add(new MemoryFile('one.ics', 'BEGIN:VCALENDAR'));
        $calendar = Calendar::of((new MemoryCollection('calendar'))->add(
            new MemoryFile('kept.ics', 'BEGIN:VCALENDAR'),
        ));
        $events = new EventEmitter();

        $events->on(PropertiesRequested::class, (new LiveProperties())(...));

        $server = new Server(new Tree((new MemoryCollection(''))->add($home)->add($calendar)), $events);

        $server->onMethod('PROPFIND', (new PropFind($server))(...));
        $server->onMethod('PROPPATCH', (new PropPatch($server))(...));

        return $server;
    }
}
