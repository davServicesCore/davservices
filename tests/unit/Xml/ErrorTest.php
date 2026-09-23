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
use DavServices\Xml\Error;
use DavServices\Xml\Writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 4918 §16 and RFC 3744 §7.1.1.
 *
 * **A refusal says which rule it broke, and §16 has one shape for saying it.**
 * Two places in this library write one — the server, for a request that failed
 * whole, and `MultiStatus`, for one that failed per resource — so the shape
 * lives here and a second copy cannot drift from the first.
 *
 * **A condition may be a name or a whole element**, and both are needed.
 * Most mean only themselves: `DAV:propfind-finite-depth` has nothing inside
 * it, and a name says everything. Some carry their own detail — RFC 3744
 * §7.1.1's `DAV:need-privileges` names the resource that lacked a privilege
 * and the privilege it lacked — and a refusal that could only give a name
 * would leave a client knowing it may not and nothing about what to change.
 */
#[CoversClass(Error::class)]
final class ErrorTest extends TestCase
{
    /**
     * RFC 4918 §16: the body is a `DAV:error` holding the condition.
     */
    public function testANameBecomesAnEmptyElementInsideTheError(): void
    {
        self::assertSame(
            '<d:error xmlns:d="DAV:"><d:propfind-finite-depth/></d:error>',
            $this->written(Error::of('{DAV:}propfind-finite-depth')),
        );
    }

    /**
     * **And an element goes in whole**, which is what RFC 3744 §7.1.1 needs:
     * the condition that names the resource and the privilege would be
     * useless flattened to its own name.
     */
    public function testAnElementGoesInWithEverythingInsideIt(): void
    {
        $needed = new Element('{DAV:}need-privileges');
        $resource = new Element('{DAV:}resource');
        $href = new Element('{DAV:}href');

        $href->appendText('/calendars/work.ics');
        $resource->append($href);
        $needed->append($resource);

        self::assertSame(
            '<d:error xmlns:d="DAV:"><d:need-privileges><d:resource>'
            . '<d:href>/calendars/work.ics</d:href>'
            . '</d:resource></d:need-privileges></d:error>',
            $this->written(Error::of($needed)),
        );
    }

    /**
     * The condition from another namespace keeps it: an extension refuses
     * with its own conditions, and §16 has no opinion about whose they are.
     */
    public function testAConditionFromAnotherNamespaceKeepsIt(): void
    {
        $written = $this->written(Error::of('{urn:ietf:params:xml:ns:caldav}supported-calendar-data'));

        self::assertStringContainsString('urn:ietf:params:xml:ns:caldav', $written);
        self::assertStringContainsString(':supported-calendar-data/>', $written);
    }

    public function testTheBodyIsADavError(): void
    {
        self::assertSame('{DAV:}error', Error::of('{DAV:}lock-token-submitted')->name());
    }

    private function written(Element $error): string
    {
        // The declaration is the writer's business; the shape is this one's.
        return substr((string) strstr((new Writer())->write($error), '<d:error'), 0);
    }
}
