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

use DavServices\Acl\Principal;
use DavServices\Acl\PrincipalCollection;
use DavServices\Dav\Acl\PrincipalInfo;
use DavServices\Dav\Event\BeforeMethod;
use DavServices\Dav\Event\CurrentPrincipalRequested;
use DavServices\Dav\Event\OptionsRequested;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\Method\Options;
use DavServices\Dav\Method\PropFind;
use DavServices\Dav\PropFindForm;
use DavServices\Dav\PropFindResult;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Plugin\Principals;
use DavServices\Tests\Unit\Backend\MemoryPrincipalBackend;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Xml\Element;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 3744 §4.2 and §5.8, RFC 5397, and R-ACL-01 with
 * R-PROP-01.
 *
 * **Three properties that no node can answer for itself**, because each of
 * them is about where things are rather than what they are:
 *
 * `DAV:principal-URL` is the canonical URL of a principal — and a node knows
 * its name and nothing about where it hangs, so the one place that can say is
 * the one holding the path.
 *
 * `DAV:principal-collection-set` is where principals live at all. It is
 * answered on **every** resource (§5.8), because it is how a client finds the
 * people before it knows any of them.
 *
 * `DAV:current-user-principal` (RFC 5397) is who you are. Until there is
 * authentication — P3-13 — the honest answer is `DAV:unauthenticated`, which
 * is the one the RFC gives for exactly this case. **Guessing at a principal
 * would be worse than saying nothing**: a client that believed it was
 * somebody would show that person's calendars.
 *
 * The plugin says `3` and `access-control` in `OPTIONS`, because a client
 * reads that header before it asks any of this.
 */
#[CoversClass(Principals::class)]
final class PrincipalsTest extends TestCase
{
    /**
     * RFC 3744 §4.2: the canonical URL of the principal, as an href. A client
     * puts it in an access control entry, so it has to be the URL this server
     * answers on — base URI and all.
     */
    public function testTellsAPrincipalItsOwnUrl(): void
    {
        $body = $this->propFind('/principals/alice', 'principal-URL');

        self::assertStringContainsString(
            '<d:principal-URL><d:href>/principals/alice</d:href></d:principal-URL>',
            $body,
        );
    }

    /**
     * And it is the URL of *this* server, which is why the node cannot work
     * it out: mounted at `/dav/`, the same principal has a different URL.
     */
    public function testTheUrlIsTheOneThisServerAnswersOn(): void
    {
        $body = $this->propFind('/dav/principals/alice', 'principal-URL', baseUri: '/dav/');

        self::assertStringContainsString('<d:href>/dav/principals/alice</d:href>', $body);
    }

    /**
     * A resource that is no principal has no `principal-URL`, and `404` says
     * so. Answering with the resource's own URL would make every file look
     * like a person.
     */
    public function testAResourceThatIsNoPrincipalHasNoPrincipalUrl(): void
    {
        $body = $this->propFind('/calendars/work.ics', 'principal-URL');

        self::assertStringContainsString('404 Not Found', $body);
        self::assertStringContainsString('principal-URL', $body);
    }

    /**
     * RFC 3744 §5.8: where the principals are, answered on **every**
     * resource — it is how a client finds the people before it knows any of
     * them.
     */
    public function testTellsEveryResourceWhereThePrincipalsAre(): void
    {
        $body = $this->propFind('/calendars/work.ics', 'principal-collection-set');

        self::assertStringContainsString(
            '<d:principal-collection-set><d:href>/principals</d:href></d:principal-collection-set>',
            $body,
        );
    }

    /**
     * RFC 5397: with nobody signed in, the answer is `DAV:unauthenticated`.
     * **Guessing would be worse than saying nothing** — a client that
     * believed it was somebody would show that person's calendars.
     */
    public function testSaysNobodyIsSignedInWhenNobodyIs(): void
    {
        $body = $this->propFind('/calendars/work.ics', 'current-user-principal');

        self::assertStringContainsString(
            '<d:current-user-principal><d:unauthenticated/></d:current-user-principal>',
            $body,
        );
    }

    /**
     * And where the application says who is there, the answer is that
     * principal's URL. The application is asked per request, because who is
     * signed in changes with every one of them.
     */
    public function testSaysWhoIsSignedInWhenTheApplicationKnows(): void
    {
        $body = $this->propFind(
            '/calendars/work.ics',
            'current-user-principal',
            whoIsThere: static fn (): string => 'principals/alice',
        );

        self::assertStringContainsString(
            '<d:current-user-principal><d:href>/principals/alice</d:href></d:current-user-principal>',
            $body,
        );
    }

    /**
     * The request is handed to whoever answers, because that is what a
     * session cookie or an `Authorization` header arrives in.
     */
    public function testAsksAboutTheRequestBeingAnswered(): void
    {
        $seen = [];

        $this->propFind(
            '/calendars/work.ics',
            'current-user-principal',
            whoIsThere: static function (Request $request) use (&$seen): ?string {
                $seen[] = $request->method();

                return null;
            },
        );

        self::assertSame(['PROPFIND'], $seen);
    }

    /**
     * RFC 3744 §5.1: a server that keeps access control says `3` and
     * `access-control` in `OPTIONS`. A client reads that before it asks for
     * any of this.
     */
    public function testSaysWhatItMakesTheServerCapableOf(): void
    {
        $server = $this->server();
        $options = new Options($server);

        $server->onMethod('OPTIONS', $options(...));

        self::assertSame('1, 3, access-control', $server->handle(new Request('OPTIONS', '/'))->headers()->first('DAV'));
    }

    /**
     * Without the plugin none of this is answered, and the server is a plain
     * WebDAV server (R-ARC-02). The properties come back `404`.
     */
    public function testAServerWithoutThePluginAnswersNoneOfIt(): void
    {
        $server = new Server(new Tree($this->tree()));
        $propFind = new PropFind($server);

        $server->onMethod('PROPFIND', $propFind(...));

        $body = (string) $this->ask($server, '/calendars/work.ics', 'current-user-principal')->body();

        self::assertStringContainsString('404 Not Found', $body);
    }

    /**
     * **Each listener asked directly at least once.** One reached only
     * through the emitter is one Xdebug collects no branch data for, and the
     * gate would report decisions as untaken that every test takes.
     */
    public function testAnswersItsThreePropertiesWhenAskedDirectly(): void
    {
        $result = new PropFindResult('principals/alice', PropFindForm::Named, [
            '{DAV:}principal-URL',
            '{DAV:}principal-collection-set',
            '{DAV:}current-user-principal',
        ]);

        $this->plugin()->describe(new PropertiesRequested($result, new Principal(new PrincipalInfo('alice'))));

        $answered = $result->byStatus()[200] ?? [];

        self::assertArrayHasKey('{DAV:}principal-URL', $answered);
        self::assertArrayHasKey('{DAV:}principal-collection-set', $answered);
        self::assertArrayHasKey('{DAV:}current-user-principal', $answered);
    }

    /**
     * **Nothing is worked out that nobody asked for.** On a listing of two
     * hundred members that would be two hundred pieces of work thrown away.
     */
    public function testWorksOutNoneOfThemWhereNoneWasAskedFor(): void
    {
        $result = new PropFindResult('principals/alice', PropFindForm::Named, ['{DAV:}getetag']);

        $this->plugin()->describe(new PropertiesRequested($result, new Principal(new PrincipalInfo('alice'))));

        self::assertSame([], $result->byStatus()[200] ?? []);
    }

    public function testAddsTheThirdComplianceClassToAnOptionsAnswer(): void
    {
        $event = new OptionsRequested(new Request('OPTIONS', '/'));

        $this->plugin()->announce($event);

        self::assertSame(['3', 'access-control'], $event->compliance());
    }

    /**
     * Without a request there is nobody to ask about, and the honest answer
     * is the same one as for nobody signed in.
     */
    public function testSaysNobodyIsThereWhereThereIsNoRequestAtAll(): void
    {
        $result = new PropFindResult('calendars/work.ics', PropFindForm::Named, ['{DAV:}current-user-principal']);

        $this->plugin(static fn (): string => 'principals/alice')
            ->describe(new PropertiesRequested($result, new MemoryFile('work.ics', '')));

        $answer = $result->byStatus()[200]['{DAV:}current-user-principal'] ?? null;

        self::assertInstanceOf(Element::class, $answer);
        self::assertSame('{DAV:}unauthenticated', ($answer->children()[0] ?? null)?->name());
    }

    /**
     * And once the request has been seen, the application is asked about it.
     */
    public function testAsksTheApplicationOnceTheRequestHasBeenSeen(): void
    {
        $plugin = $this->plugin(static fn (): string => 'principals/alice');

        $plugin->rememberTheRequest(new BeforeMethod(new Request('PROPFIND', '/calendars/work.ics')));

        $result = new PropFindResult('calendars/work.ics', PropFindForm::Named, ['{DAV:}current-user-principal']);

        $plugin->describe(new PropertiesRequested($result, new MemoryFile('work.ics', '')));

        $answer = $result->byStatus()[200]['{DAV:}current-user-principal'] ?? null;

        self::assertInstanceOf(Element::class, $answer);
        self::assertSame('{DAV:}href', ($answer->children()[0] ?? null)?->name());
    }

    /**
     * The plugin on a server of its own, **registered** — because what it
     * knows about who is signed in it now says on the one event everything
     * asks, and a plugin that was never switched on has said nothing.
     */
    /**
     * The one question about identity, asked of this plugin directly: what
     * the application knows is what it answers, and where the application
     * knows nothing it says nothing — which leaves the asker
     * unauthenticated rather than somebody.
     */
    public function testNamesWhoIsThereWhenTheApplicationKnows(): void
    {
        $event = new CurrentPrincipalRequested(new Request('PROPFIND', '/calendars/work.ics'));

        $this->plugin(static fn (): string => 'principals/alice')->nameWhoIsThere($event);

        self::assertSame('principals/alice', $event->principal());
    }

    public function testNamesNobodyWhereTheApplicationKnowsNobody(): void
    {
        $event = new CurrentPrincipalRequested(new Request('PROPFIND', '/calendars/work.ics'));

        $this->plugin()->nameWhoIsThere($event);

        self::assertNull($event->principal());
    }

    private function plugin(?callable $whoIsThere = null): Principals
    {
        $plugin = new Principals(new Server(new Tree($this->tree())), 'principals', $whoIsThere);

        $plugin->register();

        return $plugin;
    }

    private function tree(): MemoryCollection
    {
        $root = new MemoryCollection('');
        $calendars = new MemoryCollection('calendars');

        $calendars->add(new MemoryFile('work.ics', 'BEGIN:VCALENDAR'));
        $root->add($calendars);
        $root->add(new PrincipalCollection('principals', new MemoryPrincipalBackend(
            new PrincipalInfo('alice', 'Alice Ashton', ['mailto:alice@example.test']),
        )));

        return $root;
    }

    private function server(string $baseUri = '/', ?callable $whoIsThere = null): Server
    {
        $server = new Server(new Tree($this->tree()), baseUri: $baseUri);

        (new Principals($server, 'principals', $whoIsThere))->register();

        return $server;
    }

    private function propFind(
        string $target,
        string $property,
        string $baseUri = '/',
        ?callable $whoIsThere = null,
    ): string {
        $server = $this->server($baseUri, $whoIsThere);
        $propFind = new PropFind($server);

        $server->onMethod('PROPFIND', $propFind(...));

        return (string) $this->ask($server, $target, $property)->body();
    }

    private function ask(Server $server, string $target, string $property): Response
    {
        return $server->handle(new Request(
            'PROPFIND',
            $target,
            headers: new Headers(['Depth' => '0']),
            body: new Body(sprintf('<D:propfind xmlns:D="DAV:"><D:prop><D:%s/></D:prop></D:propfind>', $property)),
        ));
    }
}
