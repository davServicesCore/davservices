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

use DavServices\Http\Request;
use DavServices\Xml\Writer;
use DOMDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use XMLWriter;

#[CoversClass(Writer::class)]
final class WriterTest extends TestCase
{
    public function testWritesTheRequestMethodAsAnElement(): void
    {
        self::assertSame('<method>PROPFIND</method>', self::write('PROPFIND'));
    }

    /**
     * Anything reaching the writer ends up inside a DAV response body, so a
     * method token carrying XML metacharacters must not be able to break out
     * of its element.
     */
    public function testEscapesMarkupInTheMethodToken(): void
    {
        self::assertSame(
            '<method>&lt;script&gt;&amp;</method>',
            self::write('<script>&'),
        );
    }

    public function testProducesWellFormedXml(): void
    {
        $document = new DOMDocument();

        self::assertTrue($document->loadXML(self::write('REPORT')));
        self::assertSame('method', $document->documentElement?->nodeName);
    }

    private static function write(string $method): string
    {
        return (new Writer(new XMLWriter(), new Request($method)))->methodElement();
    }
}
