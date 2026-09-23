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

use DavServices\Acl\GroupResolver;
use DavServices\Acl\PrincipalCollection;
use DavServices\Dav\Acl\PrincipalInfo;
use DavServices\Dav\Event\CurrentPrincipalRequested;
use DavServices\Dav\Method\Options;
use DavServices\Dav\Method\PropFind;
use DavServices\Dav\Method\Report;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Plugin\Acl;
use DavServices\Tests\Unit\Acl\ArrayPrivilegeResolver;
use DavServices\Tests\Unit\Backend\MemoryPrincipalBackend;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Xml\Element;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 3744 §7.2, §9.1 and §9.2 to §9.5.
 *
 * **What `access-control` in the `DAV` header means.** §7.2: "A value of
 * 'access-control' in the DAV header MUST indicate that the server supports
 * all MUST level requirements and REQUIRED features specified in this
 * document." It is a protocol statement, not a hint — a client reads it
 * before it asks anything, and acts on it.
 *
 * Five reports are covered by that sentence. Four say "Support for this
 * report is REQUIRED" in as many words (§9.2, §9.3, §9.4, §9.5), and §9.1
 * adds the fifth from another specification: "A server that supports the
 * WebDAV Access Control Protocol MUST support the DAV:expand-property report
 * (defined in Section 3.8 of [RFC3253])."
 *
 * All five existed before this. **Nothing wired them.** The plugin announced
 * `access-control` whatever the application had registered, so a deployment
 * that switched access control on and forgot one of the five announced
 * something untrue — and the client believed it, which is what the
 * announcement is for.
 *
 * So `register()` now takes the `REPORT` method it owes them to. The
 * obligation is in the signature: there is no way to announce without
 * answering.
 *
 * **`Plugin\Principals` no longer announces it at all.** Principal
 * properties are §4; `access-control` promises §5, §6 and §7.1.1 as well, and
 * a server with principals alone has none of them. RFC 5397, the other half
 * of what that plugin answers, defines no compliance token whatsoever. The
 * untruth was the same one from the other side, and `PrincipalsTest` has the
 * case.
 */
#[CoversClass(Acl::class)]
final class AclReportsTest extends TestCase
{
    private const ALICE = '/principals/alice';

    /**
     * The five, by the name a client asks for them under.
     */
    private const OWED = [
        '{DAV:}expand-property',
        '{DAV:}acl-principal-prop-set',
        '{DAV:}principal-match',
        '{DAV:}principal-property-search',
        '{DAV:}principal-search-property-set',
    ];

    /**
     * RFC 3253 §3.1.5: `DAV:supported-report-set` is what a client reads to
     * find out what it may ask for. Every one of the five has to be in it,
     * or the announcement in `OPTIONS` promises something this server will
     * then refuse.
     */
    public function testTheSupportedReportSetNamesEveryOneOfThem(): void
    {
        $body = (string) $this->propFind('{DAV:}supported-report-set')->body();

        foreach (self::OWED as $name) {
            self::assertStringContainsString(
                sprintf('<d:%s/>', substr($name, strlen('{DAV:}'))),
                $body,
                $name,
            );
        }
    }

    /**
     * And naming them is not the same as answering them: a report that is
     * announced and then refused with `DAV:supported-report` is the same
     * untruth one step further on.
     *
     * **Four of them answer `207` and one answers `200`**, which is not an
     * inconsistency but §9.5: its response body "MUST contain a
     * DAV:principal-search-property-set XML element", and that is no
     * multistatus. The expected status is carried per report rather than
     * assumed, because assuming it is how the difference gets lost.
     */
    #[DataProvider('theFiveAndAskingForThem')]
    public function testEachOfThemIsAnswered(string $body, int $status): void
    {
        self::assertSame($status, $this->report($body)->status());
    }

    /**
     * The smallest body each of them is defined for, and what it answers.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function theFiveAndAskingForThem(): iterable
    {
        yield 'expand-property (RFC 3253 §3.8)' => ['<D:expand-property xmlns:D="DAV:"/>', 207];

        yield 'acl-principal-prop-set (§9.2)' => ['<D:acl-principal-prop-set xmlns:D="DAV:"/>', 207];

        yield 'principal-match (§9.3)' => [
            '<D:principal-match xmlns:D="DAV:"><D:self/></D:principal-match>',
            207,
        ];

        yield 'principal-property-search (§9.4)' => [
            '<D:principal-property-search xmlns:D="DAV:"><D:property-search>'
            . '<D:prop><D:displayname/></D:prop><D:match>Ash</D:match>'
            . '</D:property-search></D:principal-property-search>',
            207,
        ];

        yield 'principal-search-property-set (§9.5)' => [
            '<D:principal-search-property-set xmlns:D="DAV:"/>',
            200,
        ];
    }

    /**
     * §7.2: "If the server supports access control, it MUST return
     * 'access-control' as a field in the DAV response header from an OPTIONS
     * request on any resource implemented by that server."
     */
    public function testTheAnnouncementIsStillMade(): void
    {
        $server = $this->server();
        $options = new Options($server);

        $this->plugin($server)->register(new Report($server));
        $server->onMethod('OPTIONS', $options(...));

        self::assertSame(
            '1, 3, access-control',
            $server->handle(new Request('OPTIONS', '/'))->headers()->first('DAV'),
        );
    }

    /**
     * **`DAV:principal-match` is handed what the asker counts as.** §2 makes
     * matching reach through the groups somebody is in, and the plugin
     * already holds the resolver that works that out — so wiring the report
     * without it would give a client a narrower answer than the same server
     * gives everywhere else.
     */
    public function testPrincipalMatchIsGivenWhatTheAskerCountsAs(): void
    {
        $body = (string) $this->report(
            '<D:principal-match xmlns:D="DAV:"><D:self/></D:principal-match>',
            target: '/principals/',
        )->body();

        self::assertStringContainsString('<d:href>/principals/alice</d:href>', $body);
        self::assertStringContainsString('<d:href>/principals/staff</d:href>', $body, 'and the group she is in');
    }

    /**
     * Without a resolver there are no groups to be in, and the asker counts
     * as themselves alone — which is R-ARC-02 rather than a gap, and shows
     * the one above was not passing by accident.
     */
    public function testWithoutAResolverTheAskerCountsAsThemselvesAlone(): void
    {
        $body = (string) $this->report(
            '<D:principal-match xmlns:D="DAV:"><D:self/></D:principal-match>',
            target: '/principals/',
            withGroups: false,
        )->body();

        self::assertStringContainsString('<d:href>/principals/alice</d:href>', $body);
        self::assertStringNotContainsString('staff', $body);
    }

    /**
     * **What is wired is a default, not a decision taken from the
     * application.** A deployment with its own idea of what may be searched,
     * or its own limit, registers its own afterwards and that one answers —
     * the plugin fills the gap rather than holding the place.
     */
    public function testAnApplicationsOwnVersionAnswersInstead(): void
    {
        $server = $this->server();
        $report = new Report($server);

        $this->plugin($server)->register($report);
        $report->on(
            '{DAV:}principal-match',
            static fn (Request $request, Element $asked): Response => new Response(418),
        );
        $report->register();

        self::assertSame(418, $this->ask($server, '/principals/', '<D:principal-match xmlns:D="DAV:"><D:self/></D:principal-match>')->status());
    }

    private function report(string $body, string $target = '/principals/', bool $withGroups = true): Response
    {
        $server = $this->server($withGroups);
        $report = new Report($server);

        $this->plugin($server, $withGroups)->register($report);
        $report->register();

        return $this->ask($server, $target, $body);
    }

    private function propFind(string $property): Response
    {
        $server = $this->server();
        $report = new Report($server);

        $this->plugin($server)->register($report);
        $report->register();

        $propFind = new PropFind($server);

        $server->onMethod('PROPFIND', $propFind(...));

        return $server->handle(new Request(
            'PROPFIND',
            '/principals/',
            headers: new Headers(['Depth' => '0']),
            body: new Body(sprintf(
                '<D:propfind xmlns:D="DAV:"><D:prop><D:%s/></D:prop></D:propfind>',
                substr($property, strlen('{DAV:}')),
            )),
        ));
    }

    private function ask(Server $server, string $target, string $body): Response
    {
        return $server->handle(new Request(
            'REPORT',
            $target,
            headers: new Headers(['Depth' => '0']),
            body: new Body($body),
        ));
    }

    /**
     * A server where Alice may read the principals, and is signed in — the
     * plugin refuses a `REPORT` from whoever may not read the target
     * (R-ACL-05), so without both there is nothing to see whatever is wired.
     */
    private function server(bool $withGroups = true): Server
    {
        $root = new MemoryCollection('');

        $root->add(new PrincipalCollection('principals', self::principals()));
        $root->add(new MemoryCollection('calendars'));

        $server = new Server(new Tree($root));

        $server->events()->on(
            CurrentPrincipalRequested::class,
            static fn (CurrentPrincipalRequested $event) => $event->answerWith('principals/alice'),
        );

        return $server;
    }

    private function plugin(Server $server, bool $withGroups = true): Acl
    {
        return new Acl(
            $server,
            new ArrayPrivilegeResolver([
                self::ALICE => [
                    'principals' => ['{DAV:}read'],
                    'principals/alice' => ['{DAV:}read'],
                    'principals/staff' => ['{DAV:}read'],
                    'principals/bob' => ['{DAV:}read'],
                    '' => ['{DAV:}read'],
                ],
            ]),
            null,
            false,
            $withGroups ? new GroupResolver(self::principals(), 'principals') : null,
        );
    }

    private static function principals(): MemoryPrincipalBackend
    {
        return new MemoryPrincipalBackend(
            new PrincipalInfo('alice', 'Alice Ashton', [], ['staff']),
            new PrincipalInfo('staff', 'The staff', [], [], ['alice']),
            new PrincipalInfo('bob', 'Bob Barton'),
        );
    }
}
