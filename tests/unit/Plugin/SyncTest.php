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

use DavServices\Dav\Event\PropertiesRequested;
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
use DavServices\Sync\Token;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use PHPUnit\Framework\Attributes\CoversClass;
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
            revisionOf: static fn (): ?int => null,
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
            static fn (): ?int => null,
        );

        self::assertSame(412, $response->status());
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
        ?callable $revisionOf = null,
    ): Response {
        $server = $this->server();
        $report = new Report($server);

        $this->plugin($server, $revisionOf)->register($report);
        $report->register();

        return $server->handle(new Request(
            'PROPFIND',
            $path,
            headers: new Headers(['Depth' => '0']),
            body: new Body($body),
        ));
    }

    private function putWith(string $if, ?callable $revisionOf = null): Response
    {
        $server = $this->server();

        $this->plugin($server, $revisionOf)->register(new Report($server));
        $server->onMethod('PUT', (new Put($server))(...));

        return $server->handle(new Request(
            'PUT',
            self::COLLECTION . '/new.ics',
            headers: new Headers(['If' => $if]),
            body: new Body('BEGIN:VCALENDAR'),
        ));
    }

    private function plugin(Server $server, ?callable $revisionOf = null): Sync
    {
        return new Sync($server, $revisionOf ?? static fn (): int => 7);
    }

    /**
     * A home with one file in it.
     */
    private function server(): Server
    {
        $home = (new MemoryCollection('home'))->add(new MemoryFile('one.ics', 'BEGIN:VCALENDAR'));
        $events = new EventEmitter();

        $events->on(PropertiesRequested::class, (new LiveProperties())(...));

        $server = new Server(new Tree((new MemoryCollection(''))->add($home)), $events);

        $server->onMethod('PROPFIND', (new PropFind($server))(...));
        $server->onMethod('PROPPATCH', (new PropPatch($server))(...));

        return $server;
    }
}
