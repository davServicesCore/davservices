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

namespace DavServices\Tests\Unit\Dav\Property;

use DateTimeImmutable;
use DateTimeZone;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\INode;
use DavServices\Dav\Method\PropFind;
use DavServices\Dav\Property\LiveProperties;
use DavServices\Dav\PropFindForm;
use DavServices\Dav\PropFindResult;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Tests\Unit\Dav\MemoryQuotaCollection;
use DavServices\Tests\Unit\Dav\StreamFile;
use DavServices\Tests\Unit\Dav\TypedCollection;
use DavServices\Tests\Unit\Dav\TypedNode;
use DavServices\Xml\Element;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-PROP-01 (the live properties a server computes for
 * itself) and RFC 4331 (the two quota properties).
 *
 * These are the properties no backend stores because the server can work them
 * out: what kind of thing a node is, how long a file is, what it is called in
 * terms of media types, when it last changed. They arrive as a listener on
 * `PropertiesRequested`, so `PROPFIND` needs to know nothing about them and
 * an application that wants none of them registers none.
 *
 * **What a backend cannot say is not invented.** A node that does not know its
 * own entity tag gets no `DAV:getetag`, and the `404` a client then receives
 * is the truth. A made-up tag would be believed, and the file would go on
 * changing underneath a client that has been told it has not.
 *
 * Two of R-PROP-01's properties are deliberately not here.
 * `DAV:creationdate` and `DAV:displayname` cannot be worked out from a node at
 * all — only the backend knows them, and `IProperties` is how it says so.
 * Guessing a display name from a file name is the kind of helpfulness that
 * ends with `holiday.ics` shown to somebody who named the calendar something
 * else.
 */
#[CoversClass(LiveProperties::class)]
final class LivePropertiesTest extends TestCase
{
    public function testACollectionSaysThatItIsOne(): void
    {
        $type = $this->valueOf(new MemoryCollection('calendars'), '{DAV:}resourcetype');

        self::assertInstanceOf(Element::class, $type);
        self::assertSame('{DAV:}collection', self::childAt($type, 0)->name());
    }

    /**
     * RFC 4918 §15.9: a resource that is no collection has an empty
     * `resourcetype`, not a missing one. The property is how a client tells
     * the two apart, and leaving it out leaves it guessing.
     */
    public function testAFileSaysThatItIsNotOne(): void
    {
        $type = $this->valueOf(new MemoryFile('work.ics'), '{DAV:}resourcetype');

        self::assertInstanceOf(Element::class, $type);
        self::assertSame([], $type->children());
    }

    /**
     * **A node may be more than a collection or not one.** RFC 3744 §4 wants
     * `DAV:principal` in the `resourcetype` of a principal, RFC 4791 wants
     * `CALDAV:calendar` in a calendar's — and none of that can be worked out
     * from the outside. A node that knows says so through
     * {@see \DavServices\Dav\IResourceType}, and what it names is added to
     * what the server could tell by itself.
     *
     * Answering it here rather than leaving it to the node's own properties
     * keeps the rule of P2-07 intact: listeners answer before the node is
     * asked, so a node that tried to answer `resourcetype` itself would never
     * be reached.
     */
    public function testANodeThatKnowsWhatItIsSaysSo(): void
    {
        $type = $this->valueOf(new TypedNode('alice', '{DAV:}principal'), '{DAV:}resourcetype');

        self::assertInstanceOf(Element::class, $type);
        self::assertSame('{DAV:}principal', self::childAt($type, 0)->name());
    }

    /**
     * And a collection that knows is both: the collection element the server
     * works out, and whatever the node adds to it.
     */
    public function testACollectionThatKnowsWhatItIsSaysBoth(): void
    {
        $type = $this->valueOf(new TypedCollection('users', '{DAV:}principal'), '{DAV:}resourcetype');

        self::assertInstanceOf(Element::class, $type);
        self::assertSame('{DAV:}collection', self::childAt($type, 0)->name());
        self::assertSame('{DAV:}principal', self::childAt($type, 1)->name());
    }

    public function testAFileHandsOverWhatItKnowsAboutItself(): void
    {
        $answers = $this->propertiesOf(new MemoryFile('work.ics', 'a meeting'));

        self::assertSame('9', $answers['{DAV:}getcontentlength'] ?? null);
        self::assertSame('application/octet-stream', $answers['{DAV:}getcontenttype'] ?? null);
        self::assertIsString($answers['{DAV:}getetag'] ?? null);
    }

    /**
     * A backend that cannot say is the ordinary case rather than the odd one,
     * and what it cannot say is left out rather than invented.
     */
    public function testWhatTheBackendCannotSayIsLeftOut(): void
    {
        $answers = $this->propertiesOf(new StreamFile('work.ics', 'a meeting', null, null, null, false));

        self::assertArrayNotHasKey('{DAV:}getcontentlength', $answers);
        self::assertArrayNotHasKey('{DAV:}getcontenttype', $answers);
        self::assertArrayNotHasKey('{DAV:}getetag', $answers);
        self::assertArrayNotHasKey('{DAV:}getlastmodified', $answers);
    }

    /**
     * RFC 4918 §15.7: `getlastmodified` is the format of `Last-Modified`, so
     * that a client can compare the two without reading either.
     */
    public function testTheTimeOfTheLastChangeIsAnHttpDate(): void
    {
        $changed = new DateTimeImmutable('2026-09-13 12:34:56', new DateTimeZone('UTC'));

        $answers = $this->propertiesOf(new StreamFile('work.ics', 'a meeting', 'text/calendar', '"abc"', $changed));

        self::assertSame('Sun, 13 Sep 2026 12:34:56 GMT', $answers['{DAV:}getlastmodified'] ?? null);
    }

    /**
     * RFC 4331: what is used, and what may still be used. A collection that
     * keeps no account gets neither, because a client shows what it is told
     * and `0` would read as a full disc.
     */
    public function testACollectionThatKeepsAnAccountReportsIt(): void
    {
        $answers = $this->propertiesOf(new MemoryQuotaCollection('calendars', 2048, 1024));

        self::assertSame('2048', $answers['{DAV:}quota-used-bytes'] ?? null);
        self::assertSame('1024', $answers['{DAV:}quota-available-bytes'] ?? null);
    }

    /**
     * A backend with no limit set says how much is used and nothing about how
     * much is left: there is no number that means "as much as you like".
     */
    public function testAQuotaWithNoLimitSaysOnlyWhatIsUsed(): void
    {
        $answers = $this->propertiesOf(new MemoryQuotaCollection('calendars', 2048));

        self::assertSame('2048', $answers['{DAV:}quota-used-bytes'] ?? null);
        self::assertArrayNotHasKey('{DAV:}quota-available-bytes', $answers);
    }

    public function testACollectionWithoutAnAccountReportsNoQuota(): void
    {
        self::assertArrayNotHasKey('{DAV:}quota-used-bytes', $this->propertiesOf(new MemoryCollection('calendars')));
    }

    /**
     * A server with no reports supports none, and saying so is worth more than
     * leaving the property out: a client handed a `404` cannot tell "none"
     * from "this server does not know the question". The reports themselves
     * arrive with `REPORT`, and add themselves to this.
     */
    public function testSaysWhichReportsItSupports(): void
    {
        $reports = $this->valueOf(new MemoryCollection('calendars'), '{DAV:}supported-report-set');

        self::assertInstanceOf(Element::class, $reports);
        self::assertSame([], $reports->children());
    }

    /**
     * On a `Depth: 1` listing of two hundred members, a property computed for
     * one nobody asked for is two hundred pieces of work thrown away — and an
     * entity tag can mean reading the file to work it out.
     */
    public function testWorksOutNothingNobodyAskedFor(): void
    {
        $file = new MemoryFile('work.ics', 'a meeting');

        $this->propertiesOf($file, PropFindForm::Named, ['{DAV:}displayname']);

        self::assertSame(0, $file->entityTagsGiven, 'A property nobody asked for was worked out all the same.');
    }

    /**
     * `propname` asks which properties there are. The names come, the values
     * do not.
     */
    public function testUnderPropNameTheNamesComeWithoutTheValues(): void
    {
        $answers = $this->propertiesOf(new MemoryFile('work.ics', 'a meeting'), PropFindForm::NamesOnly);

        self::assertSame([
            '{DAV:}resourcetype' => null,
            '{DAV:}supported-report-set' => null,
            '{DAV:}getcontentlength' => null,
            '{DAV:}getcontenttype' => null,
            '{DAV:}getetag' => null,
        ], $answers);
    }

    /**
     * The whole point of the arrangement: `PROPFIND` knows nothing of these
     * properties, and registering the listener is what puts them in an answer.
     */
    public function testAPropfindAnswersWithThemOnceTheyAreRegistered(): void
    {
        $root = (new MemoryCollection(''))->add(new MemoryFile('work.ics', 'a meeting'));

        $events = new EventEmitter();
        $live = new LiveProperties();
        $events->on(PropertiesRequested::class, $live(...));

        $server = new Server(new Tree($root), $events);
        $propFind = new PropFind($server);
        $server->onMethod('PROPFIND', $propFind(...));

        $body = (string) $server->handle(new Request('PROPFIND', '/work.ics', new Headers(['Depth' => '0'])))->body();

        self::assertStringContainsString('<d:getcontentlength>9</d:getcontentlength>', $body);
        self::assertStringContainsString('<d:resourcetype/>', $body);
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, Element|string|null>
     */
    private function propertiesOf(INode $node, PropFindForm $form = PropFindForm::Everything, array $names = []): array
    {
        $result = new PropFindResult('x', $form, $names);
        $live = new LiveProperties();

        $live(new PropertiesRequested($result, $node));

        return $result->byStatus()[200] ?? [];
    }

    private function valueOf(INode $node, string $name): Element|string|null
    {
        return $this->propertiesOf($node)[$name] ?? null;
    }

    private static function childAt(Element $element, int $position): Element
    {
        return $element->children()[$position] ?? self::fail(sprintf('No child %d in "%s".', $position, $element->name()));
    }
}
