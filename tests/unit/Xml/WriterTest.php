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
     * The scaffold used to feed markup through this writer to show that it is
     * escaped. It cannot any more: Request refuses a method that is not a
     * token, so the guarantee moved to RequestTest, where it now holds for
     * every reader of a method rather than for this one call. Escaping belongs
     * to the real writer and is tested there, on the values that do carry text.
     */
    public function testProducesWellFormedXml(): void
    {
        $document = new DOMDocument();

        self::assertTrue($document->loadXML(self::write('REPORT')));
        self::assertSame('method', $document->documentElement?->nodeName);
    }

    private static function write(string $method): string
    {
        return (new Writer(new XMLWriter(), new Request($method, '/')))->methodElement();
    }
}
