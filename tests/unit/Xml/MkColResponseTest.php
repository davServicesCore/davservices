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

namespace DavServices\Tests\Unit\Xml;

use DavServices\Xml\Element;
use DavServices\Xml\MkColResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 5689 §5.2.
 *
 * The answer to an extended `MKCOL` that failed. It looks like a `207` and is
 * not one: there is a single resource involved — the collection that was not
 * created — so there is nothing to put an `href` beside, and the document
 * holds `propstat` blocks on its own.
 *
 * What a client needs from it is the one thing a bare `403` cannot say: which
 * of the properties it asked for is the one the server would not have.
 */
#[CoversClass(MkColResponse::class)]
final class MkColResponseTest extends TestCase
{
    public function testAnEmptyResponseIsStillADocument(): void
    {
        $element = (new MkColResponse())->toElement();

        self::assertSame('{DAV:}mkcol-response', $element->name());
        self::assertSame([], $element->children());
    }

    public function testNamesThePropertyAndTheStatusItCameTo(): void
    {
        $response = new MkColResponse();
        $response->add(403, ['{DAV:}resourcetype']);

        $propStat = self::childAt($response->toElement(), 0);

        self::assertSame('{DAV:}propstat', $propStat->name());
        self::assertSame('{DAV:}prop', self::childAt($propStat, 0)->name());
        self::assertSame('{DAV:}resourcetype', self::childAt(self::childAt($propStat, 0), 0)->name());
    }

    /**
     * The phrase comes from the response object, so that the library says the
     * same thing in a status line and in a body.
     */
    public function testWritesTheStatusAsAStatusLine(): void
    {
        $response = new MkColResponse();
        $response->add(424, ['{DAV:}displayname']);

        self::assertSame('HTTP/1.1 424 Failed Dependency', self::childAt(self::childAt($response->toElement(), 0), 1)->text());
    }

    /**
     * The precondition is what a client acts on. `valid-resourcetype` tells it
     * that the kind of collection it asked for is not one this server makes,
     * where a bare `403` leaves it to guess which part was refused.
     */
    public function testNamesThePreconditionWhereThereIsOne(): void
    {
        $response = new MkColResponse();
        $response->add(403, ['{DAV:}resourcetype'], '{DAV:}valid-resourcetype');

        $error = self::childAt(self::childAt($response->toElement(), 0), 2);

        self::assertSame('{DAV:}error', $error->name());
        self::assertSame('{DAV:}valid-resourcetype', self::childAt($error, 0)->name());
    }

    public function testLeavesOutTheErrorWhereThereIsNoPrecondition(): void
    {
        $response = new MkColResponse();
        $response->add(403, ['{DAV:}displayname']);

        self::assertCount(2, self::childAt($response->toElement(), 0)->children());
    }

    /**
     * RFC 4918 §14.22, which RFC 5689 borrows the block from: properties that
     * fared differently belong to different blocks. One block holding a mixed
     * result tells a client nothing about which property met which fate.
     */
    public function testKeepsThePropertiesOfEachStatusApart(): void
    {
        $response = new MkColResponse();
        $response->add(403, ['{DAV:}resourcetype'], '{DAV:}valid-resourcetype');
        $response->add(424, ['{DAV:}displayname', '{DAV:}getcontenttype']);

        $document = $response->toElement();

        self::assertCount(2, $document->children());
        self::assertCount(1, self::childAt(self::childAt($document, 0), 0)->children());
        self::assertCount(2, self::childAt(self::childAt($document, 1), 0)->children());
    }

    /**
     * RFC 9112 §4 allows a status line with no reason phrase, and a status
     * this library has no phrase for gets none — but the line must not go out
     * with the trailing space that would leave behind.
     */
    public function testAStatusWithoutAPhraseLeavesNoTrailingSpace(): void
    {
        $response = new MkColResponse();
        $response->add(299, ['{DAV:}displayname']);

        self::assertSame('HTTP/1.1 299', self::childAt(self::childAt($response->toElement(), 0), 1)->text());
    }

    private static function childAt(Element $element, int $position): Element
    {
        return $element->children()[$position] ?? self::fail(sprintf('No child %d in "%s".', $position, $element->name()));
    }
}
