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
use DavServices\Dav\Acl\PrincipalInfo;
use DavServices\Dav\Event\CurrentPrincipalRequested;
use DavServices\Dav\Method\Get;
use DavServices\Dav\Method\PropFind;
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
use DavServices\Tests\Unit\Dav\MemoryFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 3744 §2 and §5.5.1, and R-ACL-04.
 *
 * **An access control entry naming a group applies to everybody in it.**
 * §5.5.1: "the current user matches DAV:href only if that user is
 * authenticated as being (**or being a member of**) the principal identified
 * by the URL contained by that DAV:href". And §2: "Membership in a group is
 * recursive, so if a principal is a member of group GRPA, and GRPA is a
 * member of group GRPB, then the principal is also a member of GRPB."
 *
 * So the question this plugin asks is not "what does alice hold here" but
 * "what does everything alice counts as hold here" — and the answers are
 * merged. **The core does the recursion, so an application cannot forget
 * it**; the resolver contract is untouched, and one that only ever compared
 * the principal who signed in would silently deny somebody a group had been
 * given the right to.
 *
 * What stays the same is worth as much: a server with no principal
 * collection has no groups, so it asks about one identity and behaves exactly
 * as it did before (R-ARC-02).
 */
#[CoversClass(Acl::class)]
final class AclGroupsTest extends TestCase
{
    private const ALICE = '/principals/alice';

    private const STAFF = '/principals/staff';

    private const EVERYONE = '/principals/everyone';

    /**
     * **§5.5.1: what a group holds, its members hold.** Alice is granted
     * nothing of her own; `staff` is granted the right to read.
     */
    public function testWhatAGroupHoldsItsMembersHold(): void
    {
        $response = $this->read([self::STAFF => ['calendars/work.ics' => ['{DAV:}read']]]);

        self::assertSame(200, $response->status());
    }

    /**
     * **§2, the specification's own chain:** alice is in `staff`, `staff` is
     * in `everyone`. A check that stopped at the first level would deny what
     * whoever wrote the entry plainly meant to grant.
     */
    public function testMembershipReachesThroughAGroupIntoItsGroups(): void
    {
        $response = $this->read([self::EVERYONE => ['calendars/work.ics' => ['{DAV:}read']]]);

        self::assertSame(200, $response->status());
    }

    /**
     * And the asker keeps what they hold themselves: the groups are added to
     * that, not put in its place.
     */
    public function testWhatTheAskerHoldsThemselvesStillCounts(): void
    {
        $response = $this->read([self::ALICE => ['calendars/work.ics' => ['{DAV:}read']]]);

        self::assertSame(200, $response->status());
    }

    /**
     * **The privileges are merged, not chosen between.** Reading is granted
     * to one group and writing to another; alice is in both, and a `PUT`
     * needs what only the second gave her while the request itself needs what
     * only the first did.
     */
    public function testThePrivilegesOfEveryIdentityAreMerged(): void
    {
        $server = $this->server([
            self::STAFF => ['calendars/work.ics' => ['{DAV:}read']],
            self::EVERYONE => ['calendars/work.ics' => ['{DAV:}write-content']],
        ]);

        $response = $server->handle(new Request(
            'PUT',
            '/calendars/work.ics',
            body: new Body('BEGIN:VCALENDAR'),
        ));

        self::assertSame(204, $response->status());
    }

    /**
     * A group somebody is **not** in grants them nothing, which is the half
     * of this that makes the other half worth anything.
     */
    public function testAGroupSomebodyIsNotInGrantsThemNothing(): void
    {
        $response = $this->read(['/principals/strangers' => ['calendars/work.ics' => ['{DAV:}read']]]);

        self::assertSame(403, $response->status());
    }

    /**
     * **R-ACL-06 through a group.** Concealment asks the same question, so a
     * member of a group that may read a collection sees its members — and the
     * listing is still one question per identity, not one per member.
     */
    public function testAListingShowsWhatAGroupMayRead(): void
    {
        $server = $this->server([
            self::STAFF => ['calendars' => ['{DAV:}read'], 'calendars/work.ics' => ['{DAV:}read']],
        ]);
        $propFind = new PropFind($server);

        $server->onMethod('PROPFIND', $propFind(...));

        $body = (string) $server->handle(new Request(
            'PROPFIND',
            '/calendars',
            headers: new Headers(['Depth' => '1']),
            body: new Body('<D:propfind xmlns:D="DAV:"><D:prop><D:displayname/></D:prop></D:propfind>'),
        ))->body();

        self::assertStringContainsString('<d:href>/calendars/work.ics</d:href>', $body);
    }

    /**
     * RFC 3744 §5.4: `DAV:current-user-privilege-set` is "the exact set of
     * privileges that the current authenticated HTTP user has on this
     * resource" — so it has to include what the groups gave, or a client is
     * told it may not do what it then does successfully.
     */
    public function testTheCurrentUserPrivilegeSetCountsTheGroupsToo(): void
    {
        $server = $this->server([self::EVERYONE => ['calendars/work.ics' => ['{DAV:}read']]]);
        $propFind = new PropFind($server);

        $server->onMethod('PROPFIND', $propFind(...));

        $body = (string) $server->handle(new Request(
            'PROPFIND',
            '/calendars/work.ics',
            headers: new Headers(['Depth' => '0']),
            body: new Body(
                '<D:propfind xmlns:D="DAV:"><D:prop><D:current-user-privilege-set/></D:prop></D:propfind>',
            ),
        ))->body();

        self::assertStringContainsString('<d:privilege><d:read/></d:privilege>', $body);
    }

    /**
     * **A server that keeps no principals behaves exactly as it did**
     * (R-ARC-02): there are no groups to be in, so the asker counts as
     * themselves alone. Nothing here is a gap; it is the same answer for a
     * simpler deployment.
     */
    public function testAServerWithoutAGroupResolverAsksAboutTheAskerAlone(): void
    {
        $server = $this->server([self::ALICE => ['calendars/work.ics' => ['{DAV:}read']]], withGroups: false);

        self::assertSame(200, $server->handle(new Request('GET', '/calendars/work.ics'))->status());
    }

    public function testAndThenAGroupGrantsNothingAtAll(): void
    {
        $server = $this->server([self::STAFF => ['calendars/work.ics' => ['{DAV:}read']]], withGroups: false);

        self::assertSame(403, $server->handle(new Request('GET', '/calendars/work.ics'))->status());
    }

    /**
     * **Nobody signed in is still asked about**, because an entry may name
     * `DAV:unauthenticated` (§5.5.1) and that is an answer the resolver has
     * to be allowed to give.
     */
    public function testNobodySignedInIsAskedAboutAsNobody(): void
    {
        $server = $this->server([self::ALICE => ['calendars/work.ics' => ['{DAV:}read']]], signedIn: false);

        self::assertSame(403, $server->handle(new Request('GET', '/calendars/work.ics'))->status());
    }

    /**
     * @param array<string, array<string, list<string>>> $granted
     */
    private function read(array $granted): Response
    {
        return $this->server($granted)->handle(new Request('GET', '/calendars/work.ics'));
    }

    /**
     * @param array<string, array<string, list<string>>> $granted
     */
    private function server(array $granted, bool $withGroups = true, bool $signedIn = true): Server
    {
        $root = new MemoryCollection('');
        $calendars = new MemoryCollection('calendars');

        $calendars->add(new MemoryFile('work.ics', 'BEGIN:VCALENDAR'));
        $root->add($calendars);

        $server = new Server(new Tree($root));
        $get = new Get($server);
        $put = new \DavServices\Dav\Method\Put($server);

        $server->onMethod('GET', $get(...));
        $server->onMethod('PUT', $put(...));

        if ($signedIn) {
            $server->events()->on(
                CurrentPrincipalRequested::class,
                static fn (CurrentPrincipalRequested $event) => $event->answerWith('principals/alice'),
            );
        }

        (new Acl(
            $server,
            new ArrayPrivilegeResolver($granted),
            null,
            false,
            $withGroups ? new GroupResolver($this->principals(), 'principals') : null,
        ))->register();

        return $server;
    }

    /**
     * Alice is in `staff`, and `staff` is in `everyone` — §2's own example,
     * written out.
     */
    private function principals(): MemoryPrincipalBackend
    {
        return new MemoryPrincipalBackend(
            new PrincipalInfo('alice', 'Alice Ashton', [], ['staff']),
            new PrincipalInfo('staff', 'The staff', [], ['everyone'], ['alice']),
            new PrincipalInfo('everyone', 'Everybody here', [], [], ['staff']),
        );
    }
}
