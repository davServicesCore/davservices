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

use DavServices\Dav\Event\PropertiesChanging;
use DavServices\Dav\Method\PropPatch;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Exception\BadRequest;
use DavServices\Exception\NotFound;
use DavServices\Http\Body;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Xml\Element;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-DAV-05 (a `PROPPATCH` is atomic: on failure every
 * other property gets `424` and nothing is stored), R-PROP-03 (a value may be
 * any XML) and R-ARC-04 (`propPatch` is one of the promised extension points).
 *
 * The counterpart of `PROPFIND`, and the first method that has to be
 * all-or-nothing. The way that promise is kept here is by **deciding before
 * writing**: the listeners and the node say what they will and will not have,
 * and only once nothing has been refused does anything get written. A server
 * that wrote as it went and tried to wind back afterwards would be relying on
 * every storage having something to wind back with.
 */
#[CoversClass(PropPatch::class)]
#[CoversClass(PropertiesChanging::class)]
final class PropPatchTest extends TestCase
{
    public function testSetsAProperty(): void
    {
        $root = $this->tree();
        $file = $this->fileIn($root);

        $response = $this->propPatch($root, '/work.ics', '<D:set><D:prop><D:displayname>Work</D:displayname></D:prop></D:set>');

        self::assertSame(207, $response->status());
        self::assertStringContainsString('HTTP/1.1 200 OK', (string) $response->body());
        self::assertSame(['{DAV:}displayname'], $file->propertyNames());
    }

    public function testRemovesAProperty(): void
    {
        $root = $this->tree();
        $file = $this->fileIn($root)->withProperty('{DAV:}displayname', 'Work');

        $this->propPatch($root, '/work.ics', '<D:remove><D:prop><D:displayname/></D:prop></D:remove>');

        self::assertSame([], $file->propertyNames());
    }

    /**
     * RFC 4918 §9.2: the instructions are carried out in the order they were
     * sent. A client that removes a property and then sets it means to end up
     * with the value, and one that does it the other way round means to end up
     * with nothing.
     */
    public function testTheLastInstructionInTheDocumentWins(): void
    {
        $root = $this->tree();
        $file = $this->fileIn($root)->withProperty('{DAV:}displayname', 'The old name');

        $this->propPatch($root, '/work.ics', '
            <D:remove><D:prop><D:displayname/></D:prop></D:remove>
            <D:set><D:prop><D:displayname>The new name</D:displayname></D:prop></D:set>
        ');

        $name = $file->properties(['{DAV:}displayname'])['{DAV:}displayname'] ?? null;

        self::assertInstanceOf(Element::class, $name);
        self::assertSame('The new name', $name->text());
    }

    /**
     * R-DAV-05, the whole point: one refusal fails them all, and **nothing is
     * stored**. A client that was told `403` for one property and found the
     * other three changed would have no way of putting the resource back the
     * way it was.
     */
    public function testOneRefusalLeavesEverythingAsItWas(): void
    {
        $root = $this->tree();
        $file = $this->fileIn($root)->refuseProperty('{DAV:}owner');

        $response = $this->propPatch($root, '/work.ics', '
            <D:set><D:prop><D:displayname>Work</D:displayname><D:owner>Alice</D:owner></D:prop></D:set>
        ');

        $body = (string) $response->body();

        self::assertStringContainsString('HTTP/1.1 403 Forbidden', $body);
        self::assertStringContainsString('HTTP/1.1 424 Failed Dependency', $body);
        self::assertSame([], $file->propertyNames(), 'Something was stored although the request failed.');
    }

    /**
     * A node that keeps no properties cannot take any, and says so for each of
     * them rather than pretending the request worked.
     */
    public function testANodeThatKeepsNoPropertiesRefusesThemAll(): void
    {
        $root = $this->tree();
        $root->add(new MemoryCollection('calendars'));

        $body = (string) $this->propPatch($root, '/calendars', '<D:set><D:prop><D:displayname>Work</D:displayname></D:prop></D:set>')->body();

        self::assertStringContainsString('HTTP/1.1 403 Forbidden', $body);
        self::assertStringContainsString('<d:displayname/>', $body);
    }

    /**
     * R-ARC-04: `propPatch` is a promised extension point, and a listener that
     * refuses is heard **before** anything is written — the node is not even
     * asked.
     */
    public function testAListenerRefusesBeforeAnythingIsWritten(): void
    {
        $root = $this->tree();
        $file = $this->fileIn($root);

        $events = new EventEmitter();
        $events->on(PropertiesChanging::class, static function (PropertiesChanging $event): void {
            $event->result()->set('{DAV:}displayname', 409);
        });

        $body = (string) $this->propPatch(
            $root,
            '/work.ics',
            '<D:set><D:prop><D:displayname>Work</D:displayname></D:prop></D:set>',
            $events,
        )->body();

        self::assertStringContainsString('HTTP/1.1 409 Conflict', $body);
        self::assertSame(0, $file->patchesAsked, 'The node was asked although the change had been refused.');
    }

    /**
     * The event carries the node, or a listener could work out nothing about
     * the resource whose properties it is being asked about — an access-control
     * plugin has to know what it is guarding before it refuses.
     */
    public function testTheEventCarriesTheNodeItIsAbout(): void
    {
        $seen = null;

        $events = new EventEmitter();
        $events->on(PropertiesChanging::class, static function (PropertiesChanging $event) use (&$seen): void {
            $seen = $event->node()->name();
        });

        $this->propPatch(
            $this->tree(),
            '/work.ics',
            '<D:set><D:prop><D:displayname>Work</D:displayname></D:prop></D:set>',
            $events,
        );

        self::assertSame('work.ics', $seen);
    }

    /**
     * A listener may take a property on instead — which is how the dead
     * property storage of P2-10 will keep what a node has nowhere to put.
     */
    public function testAListenerCanTakeAPropertyOn(): void
    {
        $root = $this->tree();
        $file = $this->fileIn($root);
        $written = [];

        $events = new EventEmitter();
        $events->on(PropertiesChanging::class, static function (PropertiesChanging $event) use (&$written): void {
            $event->result()->willWrite('{DAV:}displayname', static function (Element|string|null $value) use (&$written): void {
                $written[] = $value instanceof Element ? $value->text() : $value;
            });
        });

        $body = (string) $this->propPatch(
            $root,
            '/work.ics',
            '<D:set><D:prop><D:displayname>Work</D:displayname></D:prop></D:set>',
            $events,
        )->body();

        self::assertSame(['Work'], $written);
        self::assertStringContainsString('HTTP/1.1 200 OK', $body);
        self::assertSame(0, $file->patchesAsked, 'The node was asked for a property somebody else had taken on.');
    }

    /**
     * The node is asked first, so that its refusal stops the listeners' writers
     * before they run. Two storages cannot share a transaction, and the one
     * that holds most of the properties is the one to hear first.
     */
    public function testAWriterDoesNotRunWhenTheNodeRefuses(): void
    {
        $root = $this->tree();
        $this->fileIn($root)->refuseProperty('{DAV:}owner');
        $written = [];

        $events = new EventEmitter();
        $events->on(PropertiesChanging::class, static function (PropertiesChanging $event) use (&$written): void {
            $event->result()->willWrite('{DAV:}displayname', static function () use (&$written): void {
                $written[] = 'the listener wrote';
            });
        });

        $this->propPatch(
            $root,
            '/work.ics',
            '<D:set><D:prop><D:displayname>Work</D:displayname><D:owner>Alice</D:owner></D:prop></D:set>',
            $events,
        );

        self::assertSame([], $written, 'A listener wrote although the node had refused.');
    }

    /**
     * A backend that says nothing about a property has not changed it.
     * Reporting `200` for a change nobody confirmed is the one answer that
     * cannot be taken back.
     */
    public function testAPropertyTheBackendSaidNothingAboutIsNotReportedAsChanged(): void
    {
        $root = $this->tree();
        $this->fileIn($root)->saysNothingAboutChanges();

        $body = (string) $this->propPatch($root, '/work.ics', '<D:set><D:prop><D:displayname>Work</D:displayname></D:prop></D:set>')->body();

        self::assertStringContainsString('HTTP/1.1 403 Forbidden', $body);
    }

    /**
     * R-PROP-03: a property may hold any XML, and it reaches the backend as
     * the element it arrived as rather than flattened into text.
     */
    public function testAValueReachesTheBackendAsTheXmlItArrivedAs(): void
    {
        $root = $this->tree();
        $file = $this->fileIn($root);

        $this->propPatch($root, '/work.ics', '
            <D:set><D:prop><D:owner><D:href>/principals/alice</D:href></D:owner></D:prop></D:set>
        ');

        $owner = $file->properties(['{DAV:}owner'])['{DAV:}owner'] ?? null;

        self::assertInstanceOf(Element::class, $owner);
        self::assertSame('{DAV:}href', ($owner->children()[0] ?? null)?->name());
    }

    /**
     * RFC 4918 §9.2: one `response` for the resource that was asked about, and
     * the properties in it. A `PROPPATCH` touches one resource, so there is
     * never a second one to report on.
     */
    public function testAnswersWithOneResponseForTheResource(): void
    {
        $response = $this->propPatch($this->tree(), '/work.ics', '<D:set><D:prop><D:displayname>Work</D:displayname></D:prop></D:set>');

        $body = (string) $response->body();

        self::assertSame(1, substr_count($body, '<d:response>'));
        self::assertStringContainsString('<d:href>/work.ics</d:href>', $body);
        self::assertStringContainsString('application/xml', (string) $response->headers()->first('Content-Type'));
    }

    /**
     * R-TREE-05: what the tree kept about the node is wrong the moment its
     * properties change, so the proof is that the backend is asked again.
     */
    public function testTheTreeForgetsTheNodeItChanged(): void
    {
        $root = $this->tree();

        $tree = new Tree($root);
        $server = new Server($tree);
        $propPatch = new PropPatch($server);
        $server->onMethod('PROPPATCH', $propPatch(...));

        $tree->node('work.ics');
        $server->handle(new Request('PROPPATCH', '/work.ics', body: $this->update('<D:set><D:prop><D:displayname>Work</D:displayname></D:prop></D:set>')));
        $tree->node('work.ics');

        self::assertSame(2, $root->lookups, 'The node was handed out from the cache after its properties changed.');
    }

    /**
     * RFC 4918 §14.19 spells the body as `propertyupdate (remove | set)+`:
     * what is neither is not an instruction, and a server that read it as one
     * would change a property the client never asked it to.
     */
    public function testReadsOnlySetAndRemove(): void
    {
        $root = $this->tree();
        $file = $this->fileIn($root);

        $response = $this->propPatch($root, '/work.ics', '
            <D:somethingelse><D:prop><D:owner>Alice</D:owner></D:prop></D:somethingelse>
            <D:set><D:prop><D:displayname>Work</D:displayname></D:prop></D:set>
        ');

        self::assertSame(207, $response->status());
        self::assertSame(['{DAV:}displayname'], $file->propertyNames());
    }

    /**
     * And inside an instruction, only `DAV:prop` says what is to change.
     */
    public function testReadsOnlyWhatIsInAProp(): void
    {
        $root = $this->tree();
        $file = $this->fileIn($root);

        $this->propPatch($root, '/work.ics', '
            <D:set>
                <D:somethingelse><D:owner>Alice</D:owner></D:somethingelse>
                <D:prop><D:displayname>Work</D:displayname></D:prop>
            </D:set>
        ');

        self::assertSame(['{DAV:}displayname'], $file->propertyNames());
    }

    public function testRefusesABodyThatIsNoPropertyUpdate(): void
    {
        self::assertSame(400, $this->propPatchBody($this->tree(), '/work.ics', '<D:propfind xmlns:D="DAV:"><D:allprop/></D:propfind>')->status());
    }

    /**
     * A `PROPPATCH` that changes nothing is not a request for anything: there
     * is no answer that would be true about it.
     */
    public function testRefusesARequestWithNoBody(): void
    {
        self::assertSame(400, $this->propPatchBody($this->tree(), '/work.ics', null)->status());
    }

    public function testRefusesAPropertyUpdateThatChangesNothing(): void
    {
        self::assertSame(400, $this->propPatchBody($this->tree(), '/work.ics', '<D:propertyupdate xmlns:D="DAV:"/>')->status());
    }

    public function testAnswersNotFoundWhereThereIsNothing(): void
    {
        self::assertSame(404, $this->propPatch($this->tree(), '/nowhere.txt', '<D:set><D:prop><D:displayname>Work</D:displayname></D:prop></D:set>')->status());
    }

    /**
     * Xdebug measures no branch of a method class that is only ever reached
     * through the server's first-class callable, so every decision is asked
     * for directly as well.
     */
    public function testAskedAsAMethodItAnswersWithAMultiStatus(): void
    {
        $root = $this->tree();

        $response = ($this->method($root))(new Request(
            'PROPPATCH',
            '/work.ics',
            body: $this->update('<D:set><D:prop><D:displayname>Work</D:displayname></D:prop></D:set>'),
        ));

        self::assertSame(207, $response->status());
    }

    /**
     * The refusals of a body come to the same status and say different things,
     * and what they say is what an application finds in its log.
     */
    public function testAskedAsAMethodItRefusesARequestWithNoBody(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('A PROPPATCH that changes nothing is not a request.');

        ($this->method($this->tree()))(new Request('PROPPATCH', '/work.ics'));
    }

    public function testAskedAsAMethodItRefusesABodyThatIsNoPropertyUpdate(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('The body of a PROPPATCH is a DAV:propertyupdate.');

        ($this->method($this->tree()))(new Request(
            'PROPPATCH',
            '/work.ics',
            body: $this->body('<D:propfind xmlns:D="DAV:"/>'),
        ));
    }

    public function testAskedAsAMethodItRaisesNotFoundWhereThereIsNothing(): void
    {
        $this->expectException(NotFound::class);

        ($this->method($this->tree()))(new Request(
            'PROPPATCH',
            '/nowhere.txt',
            body: $this->update('<D:set><D:prop><D:displayname>Work</D:displayname></D:prop></D:set>'),
        ));
    }

    private function tree(): MemoryCollection
    {
        return (new MemoryCollection(''))->add(new MemoryFile('work.ics'));
    }

    private function fileIn(MemoryCollection $root): MemoryFile
    {
        $file = $root->child('work.ics');

        if (!$file instanceof MemoryFile) {
            self::fail('The test tree has no work.ics in it.');
        }

        return $file;
    }

    private function method(MemoryCollection $root): PropPatch
    {
        return new PropPatch(new Server(new Tree($root)));
    }

    private function propPatch(
        MemoryCollection $root,
        string $target,
        string $instructions,
        ?EventEmitter $events = null,
    ): Response {
        return $this->propPatchBody(
            $root,
            $target,
            sprintf('<D:propertyupdate xmlns:D="DAV:">%s</D:propertyupdate>', $instructions),
            $events,
        );
    }

    private function propPatchBody(
        MemoryCollection $root,
        string $target,
        ?string $body,
        ?EventEmitter $events = null,
    ): Response {
        $server = new Server(new Tree($root), $events);
        $propPatch = new PropPatch($server);
        $server->onMethod('PROPPATCH', $propPatch(...));

        return $server->handle(new Request('PROPPATCH', $target, body: $body === null ? null : $this->body($body)));
    }

    private function body(string $xml): Body
    {
        return new Body(trim($xml));
    }

    /**
     * A whole `DAV:propertyupdate` around the instructions, as a client sends
     * it: the namespace is declared once, at the root.
     */
    private function update(string $instructions): Body
    {
        return $this->body(sprintf('<D:propertyupdate xmlns:D="DAV:">%s</D:propertyupdate>', $instructions));
    }
}
