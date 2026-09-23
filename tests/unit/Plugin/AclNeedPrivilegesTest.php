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

use DavServices\Dav\Event\CurrentPrincipalRequested;
use DavServices\Dav\Method\Get;
use DavServices\Dav\Method\Put;
use DavServices\Dav\Method\Report;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Http\Body;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Plugin\Acl;
use DavServices\Tests\Unit\Acl\ArrayPrivilegeResolver;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 3744 §7.1.1.
 *
 * **A refusal that does not say what was missing is a refusal nobody can act
 * on.** §7.1.1 makes that a MUST, for every method:
 *
 * > If an HTTP method fails due to insufficient privileges, the response body
 * > to the "403 Forbidden" error MUST contain the `<DAV:error>` element,
 * > which in turn contains the `<DAV:need-privileges>` element, which
 * > contains one or more `<DAV:resource>` elements indicating which resource
 * > had insufficient privileges, and what the lacking privileges were.
 *
 * > `<!ELEMENT need-privileges (resource)* >`
 * > `<!ELEMENT resource ( href , privilege ) >`
 *
 * This server sent a bare `403` with no body at all, so a client was told
 * "no" and left to guess which of half a dozen privileges it wanted and on
 * which resource — and a `COPY`, a `MOVE` or a `LOCK` touches more than one.
 *
 * ## Two details the wording settles
 *
 * **The resource named is the one that lacked the privilege**, not the
 * request target. A `PUT` into a collection needs `DAV:bind` *on the
 * collection*; saying the file's own URL would send a client to change the
 * rights on something that does not exist yet.
 *
 * **The `DAV:href` is a URL**, since that is what `DAV:resource` holds — so
 * it goes through the same place every other href in this library is made,
 * and a server mounted at `/dav/` says so.
 *
 * ## Where it does not apply
 *
 * A `404` chosen because the existence of a resource is itself a secret
 * (R-ACL-06) carries nothing: §7.1.1 speaks of the body of a `403`, and a
 * deployment that hides a resource would not then describe the privileges
 * needed for it.
 */
#[CoversClass(Acl::class)]
final class AclNeedPrivilegesTest extends TestCase
{
    private const ALICE = '/principals/alice';

    /**
     * §7.1.1 for a read: the resource asked for, and `DAV:read`.
     */
    public function testARefusalToReadSaysWhatWasMissing(): void
    {
        $response = $this->get([]);
        $body = (string) $response->body();

        self::assertSame(403, $response->status());
        // The root element carries the namespace declaration; what follows it
        // is the shape RFC 3744 §7.1.1 asks for, element for element.
        self::assertStringContainsString(
            '<d:error xmlns:d="DAV:"><d:need-privileges><d:resource>'
            . '<d:href>/calendars/work.ics</d:href>'
            . '<d:privilege><d:read/></d:privilege>'
            . '</d:resource></d:need-privileges></d:error>',
            $body,
        );
    }

    /**
     * And the body is XML, which is what a client parses it as.
     */
    public function testTheRefusalIsAnXmlBody(): void
    {
        self::assertSame(
            'application/xml; charset=utf-8',
            $this->get([])->headers()->first('Content-Type'),
        );
    }

    /**
     * **A write names the resource that lacked the privilege**, which for a
     * new member is the collection it would go into (§3.9: `DAV:bind`). A
     * client told the file's own URL would go looking for rights on something
     * that is not there yet.
     */
    public function testAWriteNamesTheCollectionAndTheBind(): void
    {
        $body = (string) $this->put(['calendars' => ['{DAV:}read'], 'calendars/new.ics' => ['{DAV:}read']])->body();

        self::assertStringContainsString('<d:href>/calendars/</d:href>', $body);
        self::assertStringContainsString('<d:privilege><d:bind/></d:privilege>', $body);
    }

    /**
     * **The href is a URL this server answers on**, made where every other
     * href is made — a server mounted at `/dav/` says `/dav/…`, because a
     * client is being told where to go and change something.
     */
    public function testTheHrefIsTheUrlThisServerAnswersOn(): void
    {
        $body = (string) $this->get([], baseUri: '/dav/')->body();

        self::assertStringContainsString('<d:href>/dav/calendars/work.ics</d:href>', $body);
    }

    /**
     * Somebody who may read gets no refusal at all, which is the half that
     * makes the other half worth having.
     */
    public function testNothingIsSaidWhereNothingWasRefused(): void
    {
        $response = $this->get(['calendars/work.ics' => ['{DAV:}read']]);

        self::assertSame(200, $response->status());
    }

    /**
     * **R-ACL-06: where the refusal is a `404`, nothing is described.**
     * §7.1.1 speaks of the body of a `403`; a deployment that hides a
     * resource would hardly then explain which privileges it wanted.
     */
    public function testAHiddenResourceExplainsNothing(): void
    {
        $response = $this->get([], hidden: true);

        self::assertSame(404, $response->status());
        self::assertStringNotContainsString('need-privileges', (string) $response->body());
    }

    /**
     * **A refusal can come before anybody has looked.** The guard runs on
     * `BeforeMethod`, so a request for something that is not there is refused
     * for want of a privilege while the tree has still never been asked — and
     * the href is then simply the URL that was asked for, since there is no
     * node to say whether it would have been a collection.
     */
    public function testAResourceThatIsNotThereIsStillNamed(): void
    {
        $response = $this->server([])->handle(new Request('GET', '/nothing-here'));

        self::assertSame(403, $response->status());
        self::assertStringContainsString('<d:href>/nothing-here</d:href>', (string) $response->body());
    }

    /**
     * @param array<string, list<string>> $granted
     */
    private function get(array $granted, string $baseUri = '/', bool $hidden = false): Response
    {
        $server = $this->server($granted, $baseUri, $hidden);

        return $server->handle(new Request('GET', rtrim($baseUri, '/') . '/calendars/work.ics'));
    }

    /**
     * @param array<string, list<string>> $granted
     */
    private function put(array $granted): Response
    {
        return $this->server($granted)->handle(new Request(
            'PUT',
            '/calendars/new.ics',
            body: new Body('BEGIN:VCALENDAR'),
        ));
    }

    /**
     * @param array<string, list<string>> $granted
     */
    private function server(array $granted, string $baseUri = '/', bool $hidden = false): Server
    {
        $root = new MemoryCollection('');
        $calendars = new MemoryCollection('calendars');

        $calendars->add(new MemoryFile('work.ics', 'BEGIN:VCALENDAR'));
        $root->add($calendars);

        $server = new Server(new Tree($root), baseUri: $baseUri);
        $get = new Get($server);
        $put = new Put($server);

        $server->onMethod('GET', $get(...));
        $server->onMethod('PUT', $put(...));

        $server->events()->on(
            CurrentPrincipalRequested::class,
            static fn (CurrentPrincipalRequested $event) => $event->answerWith('principals/alice'),
        );

        (new Acl($server, new ArrayPrivilegeResolver([self::ALICE => $granted]), null, $hidden))->register(new Report($server));

        return $server;
    }
}
