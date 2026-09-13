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

use DavServices\Dav\Event\AfterBind;
use DavServices\Dav\Event\AfterCreateCollection;
use DavServices\Dav\Event\BeforeBind;
use DavServices\Dav\Event\BeforeCreateCollection;
use DavServices\Dav\Method\MkCol;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Conflict;
use DavServices\Exception\Forbidden;
use DavServices\Exception\MethodNotAllowed;
use DavServices\Exception\UnsupportedMediaType;
use DavServices\Http\Body;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryExtendedCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Xml\Element;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-DAV-08 (the extended form of RFC 5689 is a MUST),
 * R-ARC-04 and R-TREE-05.
 *
 * `MKCOL` makes a collection, and the plain form of RFC 4918 §9.3 is the whole
 * of it for most clients: no body, `201`, done. Two of its refusals are what
 * keep a tree from filling up with rubbish — a path that is taken is `405`
 * rather than a silent replacement, and a missing parent is `409` rather than
 * a quiet creation of the ancestors.
 *
 * The extended form of RFC 5689 is what makes calendars and address books
 * possible later: the client says in one request what kind of collection it
 * wants and what properties it is to carry, and the request either succeeds
 * whole or changes nothing. **A property that cannot be set is not skipped**
 * — the collection is not created at all, and the answer names the property,
 * because a client told `201` would go on believing its calendar has the
 * colour and the name it asked for.
 */
#[CoversClass(MkCol::class)]
#[CoversClass(BeforeCreateCollection::class)]
#[CoversClass(AfterCreateCollection::class)]
final class MkColTest extends TestCase
{
    public function testCreatesACollection(): void
    {
        $root = $this->tree();

        $response = $this->mkCol($root, '/calendars');

        self::assertSame(201, $response->status());
        self::assertNull($response->body(), 'A 201 from MKCOL carries nothing.');
        self::assertTrue($root->hasChild('calendars'));
    }

    /**
     * RFC 4918 §9.3.1: `MKCOL` on a path that is taken is `405`. Replacing
     * what is there would destroy a collection the client never named.
     */
    public function testRefusesAPathThatIsAlreadyThere(): void
    {
        $root = $this->tree();
        $root->add(new MemoryCollection('calendars'));

        self::assertSame(405, $this->mkCol($root, '/calendars')->status());
    }

    public function testRefusesAPathWhereAFileIs(): void
    {
        $root = $this->tree();
        $root->add(new MemoryFile('calendars'));

        self::assertSame(405, $this->mkCol($root, '/calendars')->status());
    }

    /**
     * RFC 4918 §9.3.1: the ancestors are not created quietly. That is how one
     * typo becomes a tree of empty collections nobody asked for.
     */
    public function testRefusesAMissingParentCollection(): void
    {
        $root = $this->tree();

        $response = $this->mkCol($root, '/nowhere/calendars');

        self::assertSame(409, $response->status());
        self::assertFalse($root->hasChild('nowhere'));
    }

    public function testRefusesAFileAsTheParent(): void
    {
        $root = $this->tree();
        $root->add(new MemoryFile('notes.txt'));

        self::assertSame(409, $this->mkCol($root, '/notes.txt/calendars')->status());
    }

    /**
     * R-TREE-05: a listing made before the collection was created would
     * otherwise be handed out without it in, so the proof is that the backend
     * is asked again.
     */
    public function testTheTreeForgetsTheCollectionItWasCreatedIn(): void
    {
        $root = $this->tree();
        $root->add(new MemoryCollection('calendars'));

        $tree = new Tree($root);
        $server = new Server($tree);
        $mkCol = new MkCol($server);
        $server->onMethod('MKCOL', $mkCol(...));

        $tree->node('calendars');
        $server->handle(new Request('MKCOL', '/calendars/alice'));
        $tree->node('calendars');

        self::assertSame(2, $root->lookups, 'The collection was handed out from the cache after a member was added.');
    }

    /**
     * R-ARC-04: the seam a plugin checks through — a naming policy, a quota,
     * a limit on how many collections one account may have.
     */
    public function testRaisesTheTwoEventsAroundTheCreation(): void
    {
        $root = $this->tree();

        /** @var list<string> $seen */
        $seen = [];
        $events = new EventEmitter();
        $events->on(BeforeCreateCollection::class, static function (BeforeCreateCollection $event) use (&$seen): void {
            $seen[] = 'before ' . $event->path();
        });
        $events->on(AfterCreateCollection::class, static function (AfterCreateCollection $event) use (&$seen): void {
            $seen[] = 'after ' . $event->path();
        });

        $this->mkCol($root, '/calendars', events: $events);

        self::assertSame(['before calendars', 'after calendars'], $seen);
    }

    /**
     * The before-event knows what kind of collection is being asked for, or a
     * plugin could only refuse every one of them alike.
     */
    public function testTheBeforeEventCarriesTheResourceTypes(): void
    {
        $root = $this->tree();
        $root->add($parent = new MemoryExtendedCollection('calendars'));

        /** @var list<string> $seen */
        $seen = [];
        $events = new EventEmitter();
        $events->on(BeforeCreateCollection::class, static function (BeforeCreateCollection $event) use (&$seen): void {
            $seen = $event->resourceTypes();
        });

        $this->mkCol($root, '/calendars/work', $this->calendarBody(), $events);

        self::assertSame(['{DAV:}collection', '{urn:ietf:params:xml:ns:caldav}calendar'], $seen);
        self::assertTrue($parent->hasChild('work'));
    }

    public function testAListenerCanRefuseTheCreation(): void
    {
        $root = $this->tree();

        $events = new EventEmitter();
        $events->on(BeforeCreateCollection::class, static function (): void {
            throw new Forbidden('Not in this account.');
        });

        $response = $this->mkCol($root, '/calendars', events: $events);

        self::assertSame(403, $response->status());
        self::assertFalse($root->hasChild('calendars'), 'The listener refused and it was created all the same.');
    }

    /**
     * A body that asks for exactly what `MKCOL` makes anyway is the plain
     * case: there is nothing in it a backend has to be able to do.
     */
    public function testTakesAnExtendedRequestForAPlainCollection(): void
    {
        $root = $this->tree();

        $response = $this->mkCol($root, '/calendars', $this->body('
            <D:mkcol xmlns:D="DAV:">
                <D:set><D:prop><D:resourcetype><D:collection/></D:resourcetype></D:prop></D:set>
            </D:mkcol>
        '));

        self::assertSame(201, $response->status());
        self::assertTrue($root->hasChild('calendars'));
    }

    /**
     * RFC 5689 §3: what the client asked for reaches the backend whole. A
     * server that dropped the resource types would create a plain collection
     * and report `201` — and the client would have an address book that is
     * not one.
     */
    public function testHandsTheKindAndThePropertiesToTheBackend(): void
    {
        $root = $this->tree();
        $root->add($parent = new MemoryExtendedCollection('calendars'));

        $response = $this->mkCol($root, '/calendars/work', $this->calendarBody());

        self::assertSame(201, $response->status());
        self::assertSame(['{DAV:}collection', '{urn:ietf:params:xml:ns:caldav}calendar'], $parent->resourceTypesAsked);
        self::assertSame(['{DAV:}displayname'], array_keys($parent->propertiesAsked));
    }

    /**
     * R-PROP-03: a property value may be any XML, so it is handed over as it
     * arrived rather than flattened to a string that would lose whatever was
     * inside it.
     */
    public function testHandsThePropertyOverAsTheXmlItArrivedAs(): void
    {
        $root = $this->tree();
        $root->add($parent = new MemoryExtendedCollection('calendars'));

        $this->mkCol($root, '/calendars/work', $this->calendarBody());

        $displayName = $parent->propertiesAsked['{DAV:}displayname'] ?? null;

        self::assertInstanceOf(Element::class, $displayName);
        self::assertSame('Work', $displayName->text());
    }

    /**
     * RFC 5689 §5.1 spells it `mkcol (set+)`: there may be more than one, and
     * a server that read only the first would make a collection without the
     * properties the client asked for in the second — and report `201` for it.
     */
    public function testReadsEverySetBlock(): void
    {
        $root = $this->tree();
        $root->add($parent = new MemoryExtendedCollection('calendars'));

        $this->mkCol($root, '/calendars/work', $this->body('
            <D:mkcol xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
                <D:set><D:prop><D:resourcetype><D:collection/><C:calendar/></D:resourcetype></D:prop></D:set>
                <D:set><D:prop><D:displayname>Work</D:displayname></D:prop></D:set>
            </D:mkcol>
        '));

        self::assertSame(['{DAV:}collection', '{urn:ietf:params:xml:ns:caldav}calendar'], $parent->resourceTypesAsked);
        self::assertSame(['{DAV:}displayname'], array_keys($parent->propertiesAsked));
    }

    /**
     * A collection that cannot make that kind says so with the precondition
     * RFC 5689 defines for it, so that a client learns *what* it asked for
     * wrongly rather than merely that it was refused.
     */
    public function testRefusesAKindThisCollectionCannotMake(): void
    {
        $root = $this->tree();
        $root->add(new MemoryCollection('calendars'));

        $response = $this->mkCol($root, '/calendars/work', $this->calendarBody());

        self::assertSame(403, $response->status());
        self::assertStringContainsString('<d:mkcol-response', (string) $response->body());
        self::assertStringContainsString('<d:resourcetype/>', (string) $response->body());
        self::assertStringContainsString('valid-resourcetype', (string) $response->body());
        self::assertStringContainsString('application/xml', (string) $response->headers()->first('Content-Type'));
    }

    /**
     * RFC 5689 §5.1 spells the body as `mkcol (set+)`: what is not in a
     * `DAV:set` is not being set. A server that read the whole document would
     * refuse a request over a property the client never asked it to set.
     */
    public function testReadsOnlyWhatIsInASet(): void
    {
        $root = $this->tree();

        $response = $this->mkCol($root, '/calendars', $this->body('
            <D:mkcol xmlns:D="DAV:">
                <D:remove><D:prop><D:displayname/></D:prop></D:remove>
                <D:set><D:prop><D:resourcetype><D:collection/></D:resourcetype></D:prop></D:set>
            </D:mkcol>
        '));

        self::assertSame(201, $response->status());
        self::assertTrue($root->hasChild('calendars'));
    }

    /**
     * RFC 5689 §3: the request either succeeds whole or changes nothing. A
     * collection left behind after a refusal is the worst of both — the client
     * believes it has none, and the next attempt is a `405`.
     */
    public function testCreatesNothingWhereAPropertyCannotBeSet(): void
    {
        $root = $this->tree();
        $root->add($calendars = new MemoryCollection('calendars'));

        $this->mkCol($root, '/calendars/work', $this->calendarBody());

        self::assertFalse($calendars->hasChild('work'), 'The collection was created although the request failed.');
    }

    /**
     * RFC 4918 §9.2, which RFC 5689 borrows its atomicity from: the properties
     * that were not themselves refused failed for another's sake, and `424`
     * says exactly that. Reporting them as `403` would send a client hunting
     * for a second problem it does not have.
     */
    public function testTheOtherPropertiesFailForTheFirstOnesSake(): void
    {
        $root = $this->tree();
        $root->add(new MemoryCollection('calendars'));

        $body = (string) $this->mkCol($root, '/calendars/work', $this->calendarBody())->body();

        self::assertStringContainsString('HTTP/1.1 403 Forbidden', $body);
        self::assertStringContainsString('HTTP/1.1 424 Failed Dependency', $body);
        self::assertStringContainsString('displayname', $body);
    }

    /**
     * Where the kind was never the problem, nothing is reported as depending
     * on it: the properties are refused on their own account.
     */
    public function testRefusesPropertiesWhereTheCollectionTakesNone(): void
    {
        $root = $this->tree();
        $root->add(new MemoryCollection('calendars'));

        $response = $this->mkCol($root, '/calendars/work', $this->body('
            <D:mkcol xmlns:D="DAV:">
                <D:set><D:prop><D:displayname>Work</D:displayname></D:prop></D:set>
            </D:mkcol>
        '));

        $body = (string) $response->body();

        self::assertSame(403, $response->status());
        self::assertStringContainsString('displayname', $body);
        self::assertStringContainsString('HTTP/1.1 403 Forbidden', $body);
        self::assertStringNotContainsString('424', $body);
        self::assertStringNotContainsString('valid-resourcetype', $body);
    }

    /**
     * A backend that refuses answers with its own status. It knows why it will
     * not have the collection, and inventing a property-by-property report for
     * a refusal it never broke down that way would be making an answer up.
     */
    public function testABackendThatRefusesIsAnsweredWithItsOwnStatus(): void
    {
        $root = $this->tree();
        $root->add((new MemoryExtendedCollection('calendars'))->refuseCreation());

        $response = $this->mkCol($root, '/calendars/work', $this->calendarBody());

        self::assertSame(403, $response->status());
        self::assertNull($response->body());
    }

    /**
     * RFC 4918 §9.3: a body the server does not understand is `415`. RFC 5689
     * reserves every other root element for later use, so a server that went
     * ahead and created a plain collection would be answering a request it did
     * not read.
     */
    public function testRefusesABodyThatIsNotAnMkcol(): void
    {
        $root = $this->tree();

        $response = $this->mkCol($root, '/calendars', $this->body('<D:propfind xmlns:D="DAV:"><D:allprop/></D:propfind>'));

        self::assertSame(415, $response->status());
        self::assertFalse($root->hasChild('calendars'));
    }

    /**
     * RFC 5689 §5.1 spells the body as `mkcol (set+)`, and a `set` as holding
     * a `prop`. A request that sets nothing is not the plain form written out
     * — it is a request that did not say what it wanted.
     */
    public function testRefusesAnExtendedRequestThatSetsNothing(): void
    {
        $root = $this->tree();

        self::assertSame(400, $this->mkCol($root, '/calendars', $this->body('<D:mkcol xmlns:D="DAV:"/>'))->status());
    }

    public function testRefusesABodyThatIsNotWellFormed(): void
    {
        $root = $this->tree();

        self::assertSame(400, $this->mkCol($root, '/calendars', $this->body('<D:mkcol'))->status());
    }

    /**
     * Xdebug measures no branch of a method class that is only ever reached
     * through the server's first-class callable, so every decision is asked
     * for directly as well.
     */
    public function testAskedAsAMethodItCreatesTheCollection(): void
    {
        $root = $this->tree();

        self::assertSame(201, ($this->method($root))(new Request('MKCOL', '/calendars'))->status());
    }

    public function testAskedAsAMethodItRefusesAPathThatIsTaken(): void
    {
        $root = $this->tree();
        $root->add(new MemoryCollection('calendars'));

        $this->expectException(MethodNotAllowed::class);

        ($this->method($root))(new Request('MKCOL', '/calendars'));
    }

    public function testAskedAsAMethodItRefusesAMissingParent(): void
    {
        $this->expectException(Conflict::class);

        ($this->method($this->tree()))(new Request('MKCOL', '/nowhere/calendars'));
    }

    public function testAskedAsAMethodItRefusesABodyItDoesNotUnderstand(): void
    {
        $this->expectException(UnsupportedMediaType::class);

        ($this->method($this->tree()))(new Request('MKCOL', '/calendars', body: $this->body('<D:propfind xmlns:D="DAV:"/>')));
    }

    public function testAskedAsAMethodItRefusesARequestThatSetsNothing(): void
    {
        $this->expectException(BadRequest::class);

        ($this->method($this->tree()))(new Request('MKCOL', '/calendars', body: $this->body('<D:mkcol xmlns:D="DAV:"/>')));
    }

    public function testAskedAsAMethodItReportsWhatItCouldNotSet(): void
    {
        $root = $this->tree();
        $root->add(new MemoryCollection('calendars'));

        $response = ($this->method($root))(new Request('MKCOL', '/calendars/work', body: $this->calendarBody()));

        self::assertSame(403, $response->status());
        self::assertStringContainsString('mkcol-response', (string) $response->body());
    }

    /**
     * R-ARC-04: the same seam as for a `PUT` that creates, and for the
     * destination of a `COPY`.
     */
    public function testRaisesTheBindEventsWhereItCreatesACollection(): void
    {
        /** @var list<string> $seen */
        $seen = [];
        $events = new EventEmitter();

        $events->on(BeforeBind::class, static function (BeforeBind $event) use (&$seen): void {
            $seen[] = 'before ' . $event->path();
        });
        $events->on(AfterBind::class, static function (AfterBind $event) use (&$seen): void {
            $seen[] = 'after ' . $event->path();
        });

        $this->mkCol($this->tree(), '/calendars', events: $events);

        self::assertSame(['before calendars', 'after calendars'], $seen);
    }

    private function tree(): MemoryCollection
    {
        return new MemoryCollection('');
    }

    private function method(MemoryCollection $root): MkCol
    {
        return new MkCol(new Server(new Tree($root)));
    }

    private function mkCol(
        MemoryCollection $root,
        string $target,
        ?Body $body = null,
        ?EventEmitter $events = null,
    ): Response {
        $server = new Server(new Tree($root), $events);
        $mkCol = new MkCol($server);
        $server->onMethod('MKCOL', $mkCol(...));

        return $server->handle(new Request('MKCOL', $target, body: $body));
    }

    private function body(string $xml): Body
    {
        return new Body(trim($xml));
    }

    /**
     * What a calendar client sends: a kind of collection a plain one cannot
     * make, and a property to go with it.
     */
    private function calendarBody(): Body
    {
        return $this->body('
            <D:mkcol xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
                <D:set>
                    <D:prop>
                        <D:resourcetype><D:collection/><C:calendar/></D:resourcetype>
                        <D:displayname>Work</D:displayname>
                    </D:prop>
                </D:set>
            </D:mkcol>
        ');
    }
}
