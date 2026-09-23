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

use DavServices\Dav\Event\BeforeBind;
use DavServices\Dav\Event\BeforeMethod;
use DavServices\Dav\Event\BeforeMove;
use DavServices\Dav\Event\BeforeUnbind;
use DavServices\Dav\Event\BeforeWriteContent;
use DavServices\Dav\Event\CurrentPrincipalRequested;
use DavServices\Dav\Event\ListingMembers;
use DavServices\Dav\Event\PropertiesChanging;
use DavServices\Dav\Method\Copy;
use DavServices\Dav\Method\Delete;
use DavServices\Dav\Method\Get;
use DavServices\Dav\Method\MkCol;
use DavServices\Dav\Method\Move;
use DavServices\Dav\Method\PropFind;
use DavServices\Dav\Method\PropPatch;
use DavServices\Dav\Method\Put;
use DavServices\Dav\Method\Report;
use DavServices\Dav\PropPatchResult;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Exception\Forbidden;
use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Plugin\Acl;
use DavServices\Tests\Unit\Acl\ArrayPrivilegeResolver;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-ACL-05 and R-ACL-06.
 *
 * **Fail closed.** That is the whole of R-ACL-05, and the first test here
 * says it: with access control switched on and nothing granted, nothing is
 * allowed. A server that let a request through because no rule mentioned it
 * would be a server whose rules are a suggestion.
 *
 * The checks hang on the seams that were already there — nothing reaches into
 * a method class, the same as the lock enforcement of P3-04. What each seam
 * needs comes from RFC 3744 §3, and one of them is easy to get wrong:
 *
 * **`DAV:bind` and `DAV:unbind` are privileges of the collection, not of the
 * member** (§3.9, §3.10). Creating a file is a change to the collection it
 * appears in, and a server that asked the member instead would be asking
 * about something that does not exist yet.
 *
 * **Hiding is two things.** Refusing to read a resource is half of it; the
 * other half is that the listing of its parent must not name it (R-ACL-06),
 * or the client has been told the thing exists. And whether that refusal is
 * `403` or `404` is the deployment's choice: `403` says "not for you", `404`
 * says nothing at all, and which of those is right depends on whether the
 * existence of the resource is itself a secret.
 *
 * **Without the plugin nothing of this happens** (R-ARC-02): a server built
 * without access control is a plain WebDAV server, not one that refuses
 * everything.
 */
#[CoversClass(Acl::class)]
final class AclEnforcementTest extends TestCase
{
    private const ALICE = '/principals/alice';

    /**
     * **R-ACL-05, and the sentence this whole file is about.** With the
     * plugin on and nothing granted, every one of these is refused.
     */
    #[DataProvider('everyKindOfRequest')]
    public function testRefusesEverythingThatWasNotGranted(Request $request): void
    {
        self::assertSame(403, $this->handle($request, [])->status());
    }

    /**
     * @return iterable<string, array{Request}>
     */
    public static function everyKindOfRequest(): iterable
    {
        yield 'reading a file' => [new Request('GET', '/calendars/work.ics')];
        yield 'listing a collection' => [self::propFindRequest('/calendars')];
        yield 'writing a file' => [new Request('PUT', '/calendars/work.ics', body: new Body('changed'))];
        yield 'creating a file' => [new Request('PUT', '/calendars/new.ics', body: new Body('new'))];
        yield 'removing a file' => [new Request('DELETE', '/calendars/work.ics')];
        yield 'making a collection' => [new Request('MKCOL', '/calendars/inner')];
        yield 'changing a property' => [new Request(
            'PROPPATCH',
            '/calendars/work.ics',
            body: new Body('<D:propertyupdate xmlns:D="DAV:"><D:set><D:prop><D:displayname>x</D:displayname></D:prop></D:set></D:propertyupdate>'),
        )];
    }

    /**
     * RFC 3744 §3.1: reading needs `DAV:read`, and having it is enough.
     */
    public function testLetsThroughAReadThatWasGranted(): void
    {
        $response = $this->handle(new Request('GET', '/calendars/work.ics'), [
            'calendars/work.ics' => ['{DAV:}read'],
        ]);

        self::assertSame(200, $response->status());
    }

    /**
     * RFC 3744 §3.4: changing the content of a resource needs
     * `DAV:write-content` — **not** `DAV:bind`, which is about the
     * collection.
     */
    public function testLetsThroughAWriteThatWasGranted(): void
    {
        $response = $this->handle(new Request('PUT', '/calendars/work.ics', body: new Body('changed')), [
            'calendars/work.ics' => ['{DAV:}write-content'],
        ]);

        self::assertSame(204, $response->status());
    }

    /**
     * And `DAV:read` on its own does not let a write through: the two are
     * different privileges, and a server that muddled them would hand out
     * write access to every reader.
     */
    public function testAReaderMayNotWrite(): void
    {
        $response = $this->handle(new Request('PUT', '/calendars/work.ics', body: new Body('changed')), [
            'calendars/work.ics' => ['{DAV:}read'],
        ]);

        self::assertSame(403, $response->status());
    }

    /**
     * **RFC 3744 §3.9: `DAV:bind` belongs to the collection.** Creating a
     * file is a change to the collection it appears in — a server that asked
     * the member instead would be asking about something that does not exist
     * yet, and would grant on a rule nobody could have written.
     */
    public function testCreatingAFileNeedsBindOnTheCollection(): void
    {
        $granted = $this->handle(new Request('PUT', '/calendars/new.ics', body: new Body('new')), [
            'calendars' => ['{DAV:}bind'],
        ]);

        self::assertSame(201, $granted->status());

        $refused = $this->handle(new Request('PUT', '/calendars/new.ics', body: new Body('new')), [
            'calendars/new.ics' => ['{DAV:}bind'],
        ]);

        self::assertSame(403, $refused->status());
    }

    public function testMakingACollectionNeedsBindOnTheCollection(): void
    {
        $response = $this->handle(new Request('MKCOL', '/calendars/inner'), ['calendars' => ['{DAV:}bind']]);

        self::assertSame(201, $response->status());
    }

    /**
     * RFC 3744 §3.10: removing a member is `DAV:unbind` on the collection,
     * for the same reason.
     */
    public function testRemovingAFileNeedsUnbindOnTheCollection(): void
    {
        $response = $this->handle(new Request('DELETE', '/calendars/work.ics'), ['calendars' => ['{DAV:}unbind']]);

        self::assertSame(204, $response->status());
    }

    /**
     * RFC 3744 §3.3: properties are their own privilege. A client that may
     * change the content may not thereby rename the resource.
     */
    public function testChangingAPropertyNeedsItsOwnPrivilege(): void
    {
        $body = new Body('<D:propertyupdate xmlns:D="DAV:"><D:set><D:prop><D:displayname>x</D:displayname></D:prop></D:set></D:propertyupdate>');

        $response = $this->handle(new Request('PROPPATCH', '/calendars/work.ics', body: $body), [
            'calendars/work.ics' => ['{DAV:}write-properties'],
        ]);

        self::assertSame(207, $response->status());
    }

    /**
     * `DAV:write` aggregates all four, so granting it grants them — which is
     * the point of the tree and the reason nothing here asks about
     * aggregates itself.
     */
    public function testGrantingWriteGrantsWhatItAggregates(): void
    {
        $response = $this->handle(new Request('PUT', '/calendars/work.ics', body: new Body('changed')), [
            'calendars/work.ics' => ['{DAV:}write'],
        ]);

        self::assertSame(204, $response->status());
    }

    /**
     * **`COPY` and `MOVE` are covered by the seams already, and this says
     * so.** Both bind at the destination, and a move unbinds at the source —
     * so neither needs a rule of its own, and a test is what keeps that from
     * being an assumption.
     */
    public function testCopyingNeedsBindOnTheDestinationCollection(): void
    {
        $refused = $this->handle(self::transfer('COPY', '/calendars/work.ics', '/archive/work.ics'), [
            'calendars/work.ics' => ['{DAV:}read'],
        ]);

        self::assertSame(403, $refused->status());

        $granted = $this->handle(self::transfer('COPY', '/calendars/work.ics', '/archive/work.ics'), [
            'calendars/work.ics' => ['{DAV:}read'],
            'archive' => ['{DAV:}bind'],
        ]);

        self::assertSame(201, $granted->status());
    }

    /**
     * And a `MOVE` needs both ends: bind where it lands, unbind where it
     * came from. Granting only the first is not enough, which is what makes
     * this two privileges rather than one.
     */
    public function testMovingNeedsBothEnds(): void
    {
        $refused = $this->handle(self::transfer('MOVE', '/calendars/work.ics', '/archive/work.ics'), [
            'archive' => ['{DAV:}bind'],
        ]);

        self::assertSame(403, $refused->status());

        $granted = $this->handle(self::transfer('MOVE', '/calendars/work.ics', '/archive/work.ics'), [
            'archive' => ['{DAV:}bind'],
            'calendars' => ['{DAV:}unbind'],
        ]);

        self::assertSame(201, $granted->status());
    }

    /**
     * **R-ACL-06, the other half of hiding.** A member nobody may read is not
     * in the listing of its parent — a `404` on the member alone would still
     * have told the client it exists.
     */
    public function testAMemberNobodyMayReadIsNotInTheListing(): void
    {
        $body = (string) $this->handle(self::propFindRequest('/calendars', '1'), [
            'calendars' => ['{DAV:}read'],
            'calendars/work.ics' => ['{DAV:}read'],
        ])->body();

        self::assertStringContainsString('work.ics', $body);
        self::assertStringNotContainsString('secret.ics', $body);
    }

    /**
     * **And the listing is decided in one question**, not one per member: a
     * collection of two hundred would otherwise be two hundred questions to
     * whatever keeps the rules (R-PRIV-01).
     */
    public function testDecidesAWholeListingInOneQuestion(): void
    {
        $resolver = new ArrayPrivilegeResolver([self::ALICE => ['calendars' => ['{DAV:}read']]]);

        $this->handle(self::propFindRequest('/calendars', '1'), null, $resolver);

        self::assertSame(2, $resolver->lookups, 'one for the request itself, one for the whole listing');
    }

    /**
     * R-ACL-06: whether a refusal to read is `403` or `404` is the
     * deployment's choice. `403` says "not for you"; `404` says nothing at
     * all, which is what a server wants when the existence of the resource
     * is itself a secret.
     */
    public function testARefusalToReadMayBeAnswered404Instead(): void
    {
        $response = $this->handle(new Request('GET', '/calendars/work.ics'), [], hidden: true);

        self::assertSame(404, $response->status());
    }

    /**
     * **And only a refusal to read.** A write that was refused is a `403`
     * whatever the setting: the client plainly knows the resource is there —
     * it is writing to it — so a `404` would be a lie it could see through.
     */
    public function testARefusalToWriteStaysA403(): void
    {
        $response = $this->handle(
            new Request('PUT', '/calendars/work.ics', body: new Body('changed')),
            ['calendars/work.ics' => ['{DAV:}read']],
            hidden: true,
        );

        self::assertSame(403, $response->status());
    }

    /**
     * R-ARC-02: without the plugin nothing of this happens. A server built
     * without access control is a plain WebDAV server, not one that refuses
     * everything.
     */
    public function testAServerWithoutThePluginRefusesNothing(): void
    {
        $server = $this->server();

        self::assertSame(200, $server->handle(new Request('GET', '/calendars/work.ics'))->status());
        self::assertSame(
            204,
            $server->handle(new Request('PUT', '/calendars/work.ics', body: new Body('changed')))->status(),
        );
    }

    /**
     * R-PRIV-03: somebody who has not signed in holds nothing, so everything
     * is refused — which is fail-closed seen from the other side.
     */
    public function testSomebodyWhoIsNotSignedInMayDoNothing(): void
    {
        $server = $this->server();

        (new Acl($server, new ArrayPrivilegeResolver([self::ALICE => ['calendars/work.ics' => ['{DAV:}read']]])))
            ->register(new Report($server));

        self::assertSame(403, $server->handle(new Request('GET', '/calendars/work.ics'))->status());
    }

    /**
     * **Every guard asked directly.** One reached only through the emitter is
     * one Xdebug collects no branch data for, and the gate would report
     * decisions as untaken that every test takes.
     */
    public function testEveryGuardRefusesWhatWasNotGranted(): void
    {
        $refusals = 0;

        foreach ([
            static fn (Acl $acl) => $acl->guardWritingContent(new BeforeWriteContent('calendars/work.ics', 'x')),
            static fn (Acl $acl) => $acl->guardBinding(new BeforeBind('calendars/new.ics')),
            static fn (Acl $acl) => $acl->guardUnbinding(new BeforeUnbind('calendars/work.ics')),
            static fn (Acl $acl) => $acl->guardMoving(new BeforeMove('calendars/work.ics', 'archive/work.ics')),
            static fn (Acl $acl) => $acl->guardChangingProperties(new PropertiesChanging(
                new PropPatchResult('calendars/work.ics', ['{DAV:}displayname' => 'x']),
                new MemoryFile('work.ics', ''),
            )),
        ] as $ask) {
            try {
                $ask($this->plugin([]));
            } catch (Forbidden $refused) {
                $refusals++;
            }
        }

        self::assertSame(5, $refusals, 'Every seam a write passes through refuses what was not granted.');
    }

    /**
     * And none of them refuses what was granted, which is what proves the
     * refusals above are about the rule rather than about the seam.
     */
    public function testEveryGuardLetsThroughWhatWasGranted(): void
    {
        $acl = $this->plugin([
            'calendars' => ['{DAV:}bind', '{DAV:}unbind'],
            'calendars/work.ics' => ['{DAV:}write-content', '{DAV:}write-properties'],
        ]);

        $acl->guardWritingContent(new BeforeWriteContent('calendars/work.ics', 'x'));
        $acl->guardBinding(new BeforeBind('calendars/new.ics'));
        $acl->guardUnbinding(new BeforeUnbind('calendars/work.ics'));
        $acl->guardMoving(new BeforeMove('calendars/work.ics', 'archive/work.ics'));
        $acl->guardChangingProperties(new PropertiesChanging(
            new PropPatchResult('calendars/work.ics', ['{DAV:}displayname' => 'x']),
            new MemoryFile('work.ics', ''),
        ));

        self::expectNotToPerformAssertions();
    }

    /**
     * A method that neither reads nor writes — `OPTIONS` describes the
     * server, not the resource — passes the guard without a question being
     * asked at all.
     */
    public function testAMethodThatAsksAboutNothingIsNotGuarded(): void
    {
        $this->plugin([])->guardTheRequest(new BeforeMethod(new Request('OPTIONS', '/calendars/work.ics')));

        self::expectNotToPerformAssertions();
    }

    /**
     * The read check asked directly, both ways round: granted passes,
     * ungranted is refused. Through the server both already happen, but a
     * decision measured only from the outside is one the gate reports as
     * untaken.
     */
    public function testTheReadCheckLetsThroughWhatWasGranted(): void
    {
        $this->plugin(['calendars/work.ics' => ['{DAV:}read']])
            ->guardTheRequest(new BeforeMethod(new Request('GET', '/calendars/work.ics')));

        self::expectNotToPerformAssertions();
    }

    public function testTheReadCheckRefusesWhatWasNot(): void
    {
        $this->expectException(Forbidden::class);

        $this->plugin([])->guardTheRequest(new BeforeMethod(new Request('GET', '/calendars/work.ics')));
    }

    /**
     * Taking a lock, asked directly. **A `LOCK` writes nothing**, so no write
     * seam sees it — the check sits with the reading ones and is the only
     * rule there that depends on what is at the path.
     */
    public function testTakingALockOnSomethingThatExistsNeedsWriteContent(): void
    {
        $this->expectException(Forbidden::class);

        $this->plugin(['calendars/work.ics' => ['{DAV:}read']])
            ->guardTheRequest(new BeforeMethod(new Request('LOCK', '/calendars/work.ics')));
    }

    public function testTakingALockIsAllowedForWhoeverMayWrite(): void
    {
        $this->plugin(['calendars/work.ics' => ['{DAV:}write-content']])
            ->guardTheRequest(new BeforeMethod(new Request('LOCK', '/calendars/work.ics')));

        self::expectNotToPerformAssertions();
    }

    /**
     * And a lock on a path that holds nothing asks for nothing here: it
     * creates a resource, and creating one is `DAV:bind` at the seam that
     * already guards it (RFC 4918 §9.10.4).
     */
    public function testTakingALockOnNothingAsksForNothingHere(): void
    {
        $this->plugin([])->guardTheRequest(new BeforeMethod(new Request('LOCK', '/calendars/new.ics')));

        self::expectNotToPerformAssertions();
    }

    /**
     * The concealment asked directly: what may be read stays, what may not
     * goes, and the question is asked once for the whole listing.
     */
    public function testConcealsOnlyWhatMayNotBeRead(): void
    {
        $event = new ListingMembers('calendars', ['calendars/work.ics', 'calendars/secret.ics']);

        $this->plugin(['calendars/work.ics' => ['{DAV:}read']])->concealWhatMayNotBeRead($event);

        self::assertSame(['calendars/work.ics'], $event->visible());
    }

    public function testConcealsNothingWhereEverythingMayBeRead(): void
    {
        $event = new ListingMembers('calendars', ['calendars/work.ics', 'calendars/secret.ics']);

        $this->plugin([
            'calendars/work.ics' => ['{DAV:}read'],
            'calendars/secret.ics' => ['{DAV:}read'],
        ])->concealWhatMayNotBeRead($event);

        self::assertSame(['calendars/work.ics', 'calendars/secret.ics'], $event->visible());
    }

    /**
     * The plugin on a server of its own, with Alice signed in and holding
     * what the test says she holds.
     *
     * @param array<string, list<string>> $granted
     */
    private function plugin(array $granted): Acl
    {
        $server = $this->server();

        $server->events()->on(
            CurrentPrincipalRequested::class,
            static fn (CurrentPrincipalRequested $event) => $event->answerWith('principals/alice'),
        );

        $acl = new Acl($server, new ArrayPrivilegeResolver([self::ALICE => $granted]));

        $acl->register(new Report($server));
        $acl->guardTheRequest(new BeforeMethod(new Request('OPTIONS', '/calendars/work.ics')));

        return $acl;
    }

    private static function transfer(string $method, string $target, string $destination): Request
    {
        return new Request($method, $target, headers: new Headers(['Destination' => $destination]));
    }

    private static function propFindRequest(string $target, string $depth = '0'): Request
    {
        return new Request(
            'PROPFIND',
            $target,
            headers: new Headers(['Depth' => $depth]),
            body: new Body('<D:propfind xmlns:D="DAV:"><D:prop><D:resourcetype/></D:prop></D:propfind>'),
        );
    }

    private function tree(): MemoryCollection
    {
        $root = new MemoryCollection('');
        $calendars = new MemoryCollection('calendars');

        $calendars->add(new MemoryFile('work.ics', 'BEGIN:VCALENDAR'));
        $calendars->add(new MemoryFile('secret.ics', 'BEGIN:VCALENDAR'));
        $root->add($calendars);
        $root->add(new MemoryCollection('archive'));

        return $root;
    }

    private function server(): Server
    {
        $server = new Server(new Tree($this->tree()));
        $get = new Get($server);

        $server->onMethod('GET', $get(...));

        foreach ([
            'COPY' => new Copy($server),
            'MOVE' => new Move($server),
            'PUT' => new Put($server),
            'DELETE' => new Delete($server),
            'MKCOL' => new MkCol($server),
            'PROPFIND' => new PropFind($server),
            'PROPPATCH' => new PropPatch($server),
        ] as $name => $method) {
            $server->onMethod($name, $method(...));
        }

        return $server;
    }

    /**
     * @param array<string, list<string>>|null $granted What Alice may do, by path
     */
    private function handle(
        Request $request,
        ?array $granted = null,
        ?ArrayPrivilegeResolver $resolver = null,
        bool $hidden = false,
    ): Response {
        $server = $this->server();

        $server->events()->on(
            CurrentPrincipalRequested::class,
            static fn (CurrentPrincipalRequested $event) => $event->answerWith('principals/alice'),
        );

        (new Acl(
            $server,
            $resolver ?? new ArrayPrivilegeResolver([self::ALICE => $granted ?? []]),
            unreadableIsNotFound: $hidden,
        ))->register(new Report($server));

        return $server->handle($request);
    }
}
