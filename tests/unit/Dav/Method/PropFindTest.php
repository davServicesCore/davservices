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

namespace DavServices\Tests\Unit\Dav\Method;

use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\Method\PropFind;
use DavServices\Dav\PropFindForm;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use DavServices\Exception\NotFound;
use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-DAV-02 (`Depth: 0` and `1`, and `infinity` refused
 * by default but switchable), R-DAV-03 (`allprop`, `propname`, `prop`, and
 * `allprop` with `include`), R-DAV-04 (a property nobody has is `404`, one
 * that may not be read is `403`) and R-PROP-05 (the order the contributions
 * are merged in).
 *
 * `PROPFIND` is how a client learns what is on a server at all, and how nearly
 * every client starts a synchronisation. Two things about it are worth more
 * attention than the rest.
 *
 * **`Depth: infinity` is refused unless it was switched on.** It walks the
 * whole tree below the path, and on the root that is every file of every
 * account — the cheapest way there is to bring a server down, one request
 * long. RFC 4918 §9.1 has a server treat a missing `Depth` as `infinity`, so
 * the refusal is what a client with no header gets too, and
 * `DAV:propfind-finite-depth` tells it exactly what to do about it.
 *
 * **A property that was asked for is always accounted for.** Leaving out what
 * nobody has would hand a client three answers to five questions with nothing
 * to say which two went missing.
 */
#[CoversClass(PropFind::class)]
#[CoversClass(PropertiesRequested::class)]
final class PropFindTest extends TestCase
{
    public function testAnswersWithAMultiStatus(): void
    {
        $response = $this->propFind($this->treeWithACalendar(), '/calendars');

        self::assertSame(207, $response->status());
        self::assertStringContainsString('<d:multistatus', (string) $response->body());
        self::assertStringContainsString('application/xml', (string) $response->headers()->first('Content-Type'));
    }

    public function testDepthZeroReportsOnlyTheResourceItself(): void
    {
        $body = (string) $this->propFind($this->treeWithACalendar(), '/calendars')->body();

        self::assertSame(1, substr_count($body, '<d:response>'));
        self::assertStringContainsString('<d:href>/calendars/</d:href>', $body);
    }

    public function testDepthOneReportsTheCollectionAndItsMembers(): void
    {
        $body = (string) $this->propFind($this->treeWithACalendar(), '/calendars', '1')->body();

        self::assertSame(3, substr_count($body, '<d:response>'));
        self::assertStringContainsString('<d:href>/calendars/work.ics</d:href>', $body);
        self::assertStringContainsString('<d:href>/calendars/home.ics</d:href>', $body);
    }

    /**
     * `Depth: 1` is the members and not their members. A server that went one
     * step further would answer a listing of one collection with the whole
     * tree below it — which is the request the client did not make, and the
     * one R-DAV-02 has it refuse.
     */
    public function testDepthOneStopsAtTheMembers(): void
    {
        $body = (string) $this->propFind($this->deeperTree(), '/calendars', '1')->body();

        self::assertSame(2, substr_count($body, '<d:response>'));
        self::assertStringContainsString('<d:href>/calendars/alice/</d:href>', $body);
        self::assertStringNotContainsString('work.ics', $body, 'A member of a member was reported.');
    }

    /**
     * A file has no members, so `Depth: 1` says no more about it than `0`.
     */
    public function testDepthOneOnAFileReportsTheFileAlone(): void
    {
        $body = (string) $this->propFind($this->treeWithACalendar(), '/calendars/work.ics', '1')->body();

        self::assertSame(1, substr_count($body, '<d:response>'));
    }

    /**
     * RFC 4918 §8.3: a collection is named with a trailing slash. Clients
     * build the addresses of its members by appending to it, and one that was
     * handed `/calendars` would go looking for `/calendarswork.ics`.
     */
    public function testACollectionIsNamedWithATrailingSlash(): void
    {
        $body = (string) $this->propFind($this->treeWithACalendar(), '/calendars', '1')->body();

        self::assertStringContainsString('<d:href>/calendars/</d:href>', $body);
        self::assertStringNotContainsString('<d:href>/calendars/work.ics/</d:href>', $body);
    }

    public function testTheRootIsNamedWithItsSingleSlash(): void
    {
        $body = (string) $this->propFind($this->treeWithACalendar(), '/')->body();

        self::assertStringContainsString('<d:href>/</d:href>', $body);
        self::assertStringNotContainsString('<d:href>//</d:href>', $body);
    }

    /**
     * R-DAV-02. RFC 4918 §9.1 has a missing `Depth` treated as `infinity`, so
     * a client that sends none meets the same refusal — and the precondition
     * tells it what to send instead.
     */
    public function testRefusesAnInfiniteDepthThatWasNotSwitchedOn(): void
    {
        $response = $this->propFind($this->treeWithACalendar(), '/calendars', null);

        self::assertSame(403, $response->status());
        self::assertStringContainsString('propfind-finite-depth', (string) $response->body());
    }

    public function testRefusesInfinityByName(): void
    {
        self::assertSame(403, $this->propFind($this->treeWithACalendar(), '/calendars', 'infinity')->status());
    }

    /**
     * R-DAV-02 asks for it to be switchable, because a small tree behind a
     * single account is a case where it costs nothing.
     */
    public function testWalksTheWholeTreeWhereInfiniteDepthIsAllowed(): void
    {
        $root = $this->treeWithACalendar();
        $server = new Server(new Tree($root));
        $propFind = new PropFind($server, true);
        $server->onMethod('PROPFIND', $propFind(...));

        $body = (string) $server->handle(new Request('PROPFIND', '/', new Headers(['Depth' => 'infinity'])))->body();

        self::assertSame(4, substr_count($body, '<d:response>'));
    }

    /**
     * RFC 5234 §2.3: what a grammar spells out in letters is matched without
     * regard to case.
     */
    public function testTakesInfinityHoweverItIsSpelt(): void
    {
        self::assertSame(403, $this->propFind($this->treeWithACalendar(), '/calendars', 'Infinity')->status());
    }

    /**
     * RFC 4918 §9.1 allows `0`, `1` and `infinity` and nothing else. A `2` is
     * a client that has misunderstood something, and answering it as though it
     * had said `1` would hide that.
     */
    public function testRefusesADepthTheProtocolDoesNotHave(): void
    {
        self::assertSame(400, $this->propFind($this->treeWithACalendar(), '/calendars', '2')->status());
    }

    /**
     * RFC 4918 §9.1: a `PROPFIND` with no body is `allprop`.
     */
    public function testAnEmptyBodyAsksForEverything(): void
    {
        $seen = null;
        $events = new EventEmitter();
        $events->on(PropertiesRequested::class, static function (PropertiesRequested $event) use (&$seen): void {
            $seen ??= $event->result()->form();
        });

        $this->propFind($this->treeWithACalendar(), '/calendars', events: $events);

        self::assertSame(PropFindForm::Everything, $seen);
    }

    public function testAnswersTheNamedPropertiesFromTheNode(): void
    {
        $root = $this->treeWithACalendar();

        $body = (string) $this->propFind($root, '/calendars/work.ics', body: $this->askFor('<D:displayname/>'))->body();

        self::assertStringContainsString('<d:displayname>Work</d:displayname>', $body);
        self::assertStringContainsString('HTTP/1.1 200 OK', $body);
    }

    /**
     * R-DAV-04: a property that was asked for and that nobody has gets its own
     * block with a `404`.
     */
    public function testAPropertyNobodyHasIsReportedAsMissing(): void
    {
        $root = $this->treeWithACalendar();

        $body = (string) $this->propFind($root, '/calendars/work.ics', body: $this->askFor('<D:owner/>'))->body();

        self::assertStringContainsString('<d:owner/>', $body);
        self::assertStringContainsString('HTTP/1.1 404 Not Found', $body);
    }

    /**
     * RFC 4918 §9.1: `propname` asks which properties there are. The answer
     * carries their names and not a single value.
     */
    public function testPropNameAnswersWithTheNamesAlone(): void
    {
        $root = $this->treeWithACalendar();

        $body = (string) $this->propFind($root, '/calendars/work.ics', body: '<D:propfind xmlns:D="DAV:"><D:propname/></D:propfind>')->body();

        self::assertStringContainsString('<d:displayname/>', $body);
        self::assertStringNotContainsString('Work', $body);
    }

    /**
     * R-DAV-03: `allprop` hands over what the node keeps, and the node's own
     * names are the only way to learn of a property nobody has asked for —
     * which is every dead property there is.
     */
    public function testAllPropHandsOverWhatTheNodeKeeps(): void
    {
        $root = $this->treeWithACalendar();

        $body = (string) $this->propFind($root, '/calendars/work.ics')->body();

        self::assertStringContainsString('<d:displayname>Work</d:displayname>', $body);
    }

    /**
     * What most clients actually send: `allprop` spelt out, with no extras.
     */
    public function testTakesAnAllPropThatAsksForNoExtras(): void
    {
        $root = $this->treeWithACalendar();

        $body = (string) $this->propFind($root, '/calendars/work.ics', body: '<D:propfind xmlns:D="DAV:"><D:allprop/></D:propfind>')->body();

        self::assertStringContainsString('<d:displayname>Work</d:displayname>', $body);
        self::assertStringNotContainsString('404', $body, 'Nothing was named, so nothing can be missing.');
    }

    /**
     * Not even under `allprop`: what a plugin has answered is not asked of the
     * backend a second time.
     */
    public function testTheNodeIsNotAskedForWhatAListenerAnswered(): void
    {
        $root = $this->treeWithACalendar();
        $work = $this->fileIn($root);

        $events = new EventEmitter();
        $events->on(PropertiesRequested::class, static function (PropertiesRequested $event): void {
            $event->result()->set('{DAV:}displayname', 'The plugin');
        });

        $this->propFind($root, '/calendars/work.ics', events: $events);

        self::assertSame([], $work->askedFor);
    }

    /**
     * RFC 4918 §9.1: `DAV:include` names what is to come besides `allprop`.
     */
    public function testAllPropTakesTheExtrasOfInclude(): void
    {
        $root = $this->treeWithACalendar();

        $body = (string) $this->propFind($root, '/calendars/work.ics', body: '
            <D:propfind xmlns:D="DAV:">
                <D:allprop/>
                <D:include><D:quota-used-bytes/></D:include>
            </D:propfind>
        ')->body();

        self::assertStringContainsString('<d:quota-used-bytes/>', $body);
        self::assertStringContainsString('HTTP/1.1 404 Not Found', $body);
    }

    /**
     * R-PROP-05: the plugins answer before the node, and the first answer
     * stands. That order is what lets access control refuse a property the
     * node would gladly hand over.
     */
    public function testAListenerAnswersBeforeTheNode(): void
    {
        $root = $this->treeWithACalendar();

        $events = new EventEmitter();
        $events->on(PropertiesRequested::class, static function (PropertiesRequested $event): void {
            $event->result()->set('{DAV:}displayname', 'The plugin');
        });

        $body = (string) $this->propFind($root, '/calendars/work.ics', body: $this->askFor('<D:displayname/>'), events: $events)->body();

        self::assertStringContainsString('<d:displayname>The plugin</d:displayname>', $body);
        self::assertStringNotContainsString('Work', $body);
    }

    /**
     * R-DAV-04: a property that may not be read is refused rather than left
     * out, and a refusal is an answer — the node is never asked for it.
     */
    public function testAListenerCanRefuseAProperty(): void
    {
        $root = $this->treeWithACalendar();

        $events = new EventEmitter();
        $events->on(PropertiesRequested::class, static function (PropertiesRequested $event): void {
            $event->result()->set('{DAV:}displayname', null, 403);
        });

        $body = (string) $this->propFind($root, '/calendars/work.ics', body: $this->askFor('<D:displayname/>'), events: $events)->body();

        self::assertStringContainsString('HTTP/1.1 403 Forbidden', $body);
        self::assertStringNotContainsString('Work', $body);
    }

    /**
     * A backend query for what a plugin has already answered is a query run
     * for nothing, and on a `Depth: 1` listing that is one per member.
     */
    public function testTheNodeIsAskedOnlyForWhatIsStillOpen(): void
    {
        $root = $this->treeWithACalendar();
        $work = $this->fileIn($root);

        $events = new EventEmitter();
        $events->on(PropertiesRequested::class, static function (PropertiesRequested $event): void {
            $event->result()->set('{DAV:}displayname', 'The plugin');
        });

        $this->propFind($root, '/calendars/work.ics', body: $this->askFor('<D:displayname/><D:owner/>'), events: $events);

        self::assertSame(['{DAV:}owner'], $work->askedFor);
    }

    /**
     * Every open name in one call, not one call per property: a backend asked
     * for them one at a time runs a query for each.
     */
    public function testTheNodeIsAskedForEveryOpenNameAtOnce(): void
    {
        $root = $this->treeWithACalendar();
        $work = $this->fileIn($root);

        $this->propFind($root, '/calendars/work.ics', body: $this->askFor('<D:owner/><D:creationdate/>'));

        self::assertSame(['{DAV:}owner', '{DAV:}creationdate'], $work->askedFor);
    }

    /**
     * The event carries the node, or a plugin could work out nothing about the
     * resource it is answering for.
     */
    public function testTheEventCarriesTheNodeItIsAbout(): void
    {
        $root = $this->treeWithACalendar();

        $events = new EventEmitter();
        $events->on(PropertiesRequested::class, static function (PropertiesRequested $event): void {
            $event->result()->set('{DAV:}displayname', $event->node()->name());
        });

        $body = (string) $this->propFind($root, '/calendars/work.ics', body: $this->askFor('<D:displayname/>'), events: $events)->body();

        self::assertStringContainsString('<d:displayname>work.ics</d:displayname>', $body);
    }

    /**
     * A node that keeps no properties of its own is no error: most collections
     * keep none, and everything they report comes from the live properties a
     * plugin works out.
     */
    public function testANodeThatKeepsNoPropertiesStillGetsAResponse(): void
    {
        $body = (string) $this->propFind($this->treeWithACalendar(), '/calendars', body: $this->askFor('<D:displayname/>'))->body();

        self::assertSame(1, substr_count($body, '<d:response>'));
        self::assertStringContainsString('HTTP/1.1 404 Not Found', $body);
    }

    public function testAnswersNotFoundWhereThereIsNothing(): void
    {
        self::assertSame(404, $this->propFind($this->treeWithACalendar(), '/nowhere')->status());
    }

    /**
     * The body of a `PROPFIND` is a `DAV:propfind` and nothing else. Unlike
     * `MKCOL`, where RFC 4918 §9.3 asks for a `415`, there is no other entity
     * type to speak of here: a body that is not one is a client error.
     */
    public function testRefusesABodyThatIsNotAPropfind(): void
    {
        self::assertSame(400, $this->propFind($this->treeWithACalendar(), '/calendars', body: '<D:mkcol xmlns:D="DAV:"/>')->status());
    }

    public function testRefusesAPropfindThatAsksForNothing(): void
    {
        self::assertSame(400, $this->propFind($this->treeWithACalendar(), '/calendars', body: '<D:propfind xmlns:D="DAV:"/>')->status());
    }

    /**
     * Xdebug measures no branch of a method class that is only ever reached
     * through the server's first-class callable, so every decision is asked
     * for directly as well.
     */
    public function testAskedAsAMethodItAnswersWithAMultiStatus(): void
    {
        $root = $this->treeWithACalendar();

        $response = ($this->method($root))(new Request('PROPFIND', '/calendars', new Headers(['Depth' => '1'])));

        self::assertSame(207, $response->status());
    }

    public function testAskedAsAMethodItRefusesAnInfiniteDepth(): void
    {
        $this->expectException(Forbidden::class);

        ($this->method($this->treeWithACalendar()))(new Request('PROPFIND', '/calendars'));
    }

    public function testAskedAsAMethodItRefusesADepthTheProtocolDoesNotHave(): void
    {
        $this->expectException(BadRequest::class);

        ($this->method($this->treeWithACalendar()))(new Request('PROPFIND', '/calendars', new Headers(['Depth' => '2'])));
    }

    /**
     * The two refusals of a body come to the same status and say different
     * things, and what they say is what an application finds in its log.
     */
    public function testAskedAsAMethodItSaysWhyABodyIsNoPropfind(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('The body of a PROPFIND is a DAV:propfind.');

        ($this->method($this->treeWithACalendar()))(new Request(
            'PROPFIND',
            '/calendars',
            new Headers(['Depth' => '0']),
            new Body('<D:mkcol xmlns:D="DAV:"/>'),
        ));
    }

    public function testAskedAsAMethodItRefusesABodyThatAsksForNothing(): void
    {
        $this->expectException(BadRequest::class);

        ($this->method($this->treeWithACalendar()))(new Request(
            'PROPFIND',
            '/calendars',
            new Headers(['Depth' => '0']),
            new Body('<D:propfind xmlns:D="DAV:"/>'),
        ));
    }

    public function testAskedAsAMethodItRaisesNotFoundWhereThereIsNothing(): void
    {
        $this->expectException(NotFound::class);

        ($this->method($this->treeWithACalendar()))(new Request('PROPFIND', '/nowhere', new Headers(['Depth' => '0'])));
    }

    /**
     * A collection holding two files, one of which knows its own display name.
     */
    private function treeWithACalendar(): MemoryCollection
    {
        $calendars = new MemoryCollection('calendars');
        $calendars->add((new MemoryFile('work.ics'))->withProperty('{DAV:}displayname', 'Work'));
        $calendars->add(new MemoryFile('home.ics'));

        return (new MemoryCollection(''))->add($calendars);
    }

    /**
     * A collection inside a collection, to tell one step from two.
     */
    private function deeperTree(): MemoryCollection
    {
        $alice = (new MemoryCollection('alice'))->add(new MemoryFile('work.ics'));

        return (new MemoryCollection(''))->add((new MemoryCollection('calendars'))->add($alice));
    }

    /**
     * The file that knows its own display name.
     */
    private function fileIn(MemoryCollection $root): MemoryFile
    {
        $calendars = $root->child('calendars');

        if (!$calendars instanceof MemoryCollection) {
            self::fail('The test tree has no calendars collection.');
        }

        $work = $calendars->child('work.ics');

        if (!$work instanceof MemoryFile) {
            self::fail('The test tree has no work.ics in it.');
        }

        return $work;
    }

    private function method(MemoryCollection $root): PropFind
    {
        return new PropFind(new Server(new Tree($root)));
    }

    private function propFind(
        MemoryCollection $root,
        string $target,
        ?string $depth = '0',
        ?string $body = null,
        ?EventEmitter $events = null,
    ): Response {
        $server = new Server(new Tree($root), $events);
        $propFind = new PropFind($server);
        $server->onMethod('PROPFIND', $propFind(...));

        return $server->handle(new Request(
            'PROPFIND',
            $target,
            new Headers($depth === null ? [] : ['Depth' => $depth]),
            $body === null ? null : new Body(trim($body)),
        ));
    }

    private function askFor(string $properties): string
    {
        return sprintf('<D:propfind xmlns:D="DAV:"><D:prop>%s</D:prop></D:propfind>', $properties);
    }
}
