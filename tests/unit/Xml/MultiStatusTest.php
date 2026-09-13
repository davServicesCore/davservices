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
use DavServices\Xml\MultiStatus;
use DavServices\Xml\Reader;
use DavServices\Xml\Writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-XML-07 and RFC 4918 §14.16 to §14.24.
 *
 * A `207` is the answer to a request that partly worked, and its shape is the
 * part of WebDAV clients get wrong most often — so this builds it in one
 * place, and every method that answers with one goes through here.
 *
 * The nesting that matters: one `response` per resource, each with an `href`;
 * properties grouped into a `propstat` **per status**, never one block with
 * mixed results; and a `status` of its own where the whole resource failed
 * rather than a property of it.
 */
#[CoversClass(MultiStatus::class)]
final class MultiStatusTest extends TestCase
{
    public function testAnEmptyMultiStatusIsStillADocument(): void
    {
        $element = (new MultiStatus())->toElement();

        self::assertSame('{DAV:}multistatus', $element->name());
        self::assertSame([], $element->children());
    }

    public function testWritesAResponseWithItsHref(): void
    {
        $multiStatus = new MultiStatus();
        $multiStatus->addProperties('/calendars/alice/', [200 => ['{DAV:}displayname' => 'Alice']]);

        $response = self::childAt($multiStatus->toElement(), 0);

        self::assertSame('{DAV:}response', $response->name());
        self::assertSame('{DAV:}href', self::childAt($response, 0)->name());
        self::assertSame('/calendars/alice/', self::childAt($response, 0)->text());
    }

    /**
     * RFC 4918 §14.22: the status belongs to the `propstat`, and properties
     * that fared differently belong to different blocks. A client reading one
     * block with a `404` in it has no way to tell which property was missing.
     */
    public function testGroupsThePropertiesByTheirStatus(): void
    {
        $multiStatus = new MultiStatus();
        $multiStatus->addProperties('/x', [
            200 => ['{DAV:}displayname' => 'Alice'],
            404 => ['{DAV:}getetag' => null],
        ]);

        $response = self::childAt($multiStatus->toElement(), 0);

        self::assertSame('{DAV:}propstat', self::childAt($response, 1)->name());
        self::assertSame('{DAV:}propstat', self::childAt($response, 2)->name());
        self::assertSame('HTTP/1.1 200 OK', self::statusOf(self::childAt($response, 1)));
        self::assertSame('HTTP/1.1 404 Not Found', self::statusOf(self::childAt($response, 2)));
    }

    public function testWritesAPropertyThatCarriesText(): void
    {
        $multiStatus = new MultiStatus();
        $multiStatus->addProperties('/x', [200 => ['{DAV:}displayname' => 'Alice']]);

        $property = self::propertyAt($multiStatus, 0);

        self::assertSame('{DAV:}displayname', $property->name());
        self::assertSame('Alice', $property->text());
        self::assertSame([], $property->children());
    }

    /**
     * A property may hold any XML at all (R-PROP-03): `resourcetype` holds
     * elements, and a dead property holds whatever was put there.
     */
    public function testWritesAPropertyThatCarriesXml(): void
    {
        $resourceType = new Element('{DAV:}resourcetype');
        $resourceType->append(new Element('{DAV:}collection'));

        $multiStatus = new MultiStatus();
        $multiStatus->addProperties('/x', [200 => ['{DAV:}resourcetype' => $resourceType]]);

        $property = self::propertyAt($multiStatus, 0);

        self::assertSame('{DAV:}resourcetype', $property->name());
        self::assertSame('{DAV:}collection', self::childAt($property, 0)->name());
    }

    /**
     * A property that is absent is named and left empty — that is what a `404`
     * block is for, and what `propname` asks for.
     */
    public function testWritesAPropertyThatIsOnlyNamed(): void
    {
        $multiStatus = new MultiStatus();
        $multiStatus->addProperties('/x', [404 => ['{DAV:}getetag' => null]]);

        $property = self::propertyAt($multiStatus, 0);

        self::assertSame('{DAV:}getetag', $property->name());
        self::assertSame('', $property->text());
        self::assertSame([], $property->children());
    }

    /**
     * Where the whole resource failed, the response carries the status itself
     * rather than a `propstat` — a `DELETE` that could not remove one member
     * of a collection answers like this (RFC 4918 §9.6.1).
     */
    public function testWritesAResponseThatFailedAsAWhole(): void
    {
        $multiStatus = new MultiStatus();
        $multiStatus->addStatus('/x/locked.txt', 423);

        $response = self::childAt($multiStatus->toElement(), 0);

        self::assertSame('{DAV:}href', self::childAt($response, 0)->name());
        self::assertSame('{DAV:}status', self::childAt($response, 1)->name());
        self::assertSame('HTTP/1.1 423 Locked', self::childAt($response, 1)->text());
    }

    public function testWritesThePreconditionThatWasNotMet(): void
    {
        $multiStatus = new MultiStatus();
        $multiStatus->addStatus('/x', 423, '{DAV:}lock-token-submitted');

        $response = self::childAt($multiStatus->toElement(), 0);
        $error = self::childAt($response, 2);

        self::assertSame('{DAV:}error', $error->name());
        self::assertSame('{DAV:}lock-token-submitted', self::childAt($error, 0)->name());
    }

    public function testWritesAWordOfExplanation(): void
    {
        $multiStatus = new MultiStatus();
        $multiStatus->addStatus('/x', 507, null, 'The quota of the collection is exhausted.');

        $response = self::childAt($multiStatus->toElement(), 0);
        $description = self::childAt($response, 2);

        self::assertSame('{DAV:}responsedescription', $description->name());
        self::assertSame('The quota of the collection is exhausted.', $description->text());
    }

    /**
     * A word of explanation belongs to a response whose properties were asked
     * for just as much as to one that failed as a whole — a `403` on one
     * property is where a client most wants to be told why.
     */
    public function testWritesAWordOfExplanationBesideProperties(): void
    {
        $multiStatus = new MultiStatus();
        $multiStatus->addProperties('/x', [403 => ['{DAV:}owner' => null]], 'The owner may not be set here.');

        $response = self::childAt($multiStatus->toElement(), 0);
        $description = self::childAt($response, 2);

        self::assertSame('{DAV:}responsedescription', $description->name());
        self::assertSame('The owner may not be set here.', $description->text());
    }

    /**
     * RFC 9112 §4 allows a status line with no reason phrase, and a status
     * this library has no phrase for gets none — but the line must not go out
     * with the trailing space that would leave behind.
     */
    public function testAStatusWithoutAPhraseLeavesNoTrailingSpace(): void
    {
        $multiStatus = new MultiStatus();
        $multiStatus->addStatus('/x', 299);

        $response = self::childAt($multiStatus->toElement(), 0);

        self::assertSame('HTTP/1.1 299', self::childAt($response, 1)->text());
    }

    public function testKeepsTheResponsesInTheOrderTheyWereAdded(): void
    {
        $multiStatus = new MultiStatus();
        $multiStatus->addStatus('/first', 404);
        $multiStatus->addStatus('/second', 423);
        $multiStatus->addProperties('/third', [200 => ['{DAV:}getetag' => '"abc"']]);

        $hrefs = array_map(
            static fn (Element $response): string => self::childAt($response, 0)->text(),
            $multiStatus->toElement()->children(),
        );

        self::assertSame(['/first', '/second', '/third'], $hrefs);
    }

    /**
     * The document a client actually receives, read back with this library's
     * own reader: the shape of RFC 4918 §14.24 end to end.
     */
    public function testTheWrittenDocumentReadsBackAsItWasBuilt(): void
    {
        $multiStatus = new MultiStatus();
        $multiStatus->addProperties('/calendars/alice/', [
            200 => ['{DAV:}displayname' => 'Alice & friends'],
            404 => ['{DAV:}getctag' => null],
        ]);

        $read = (new Reader())->parse((new Writer())->write($multiStatus->toElement()));
        $response = self::childAt($read, 0);

        self::assertSame('{DAV:}multistatus', $read->name());
        self::assertSame('/calendars/alice/', self::childAt($response, 0)->text());
        self::assertSame('Alice & friends', self::childAt(self::childAt(self::childAt($response, 1), 0), 0)->text());
        self::assertSame('HTTP/1.1 404 Not Found', self::statusOf(self::childAt($response, 2)));
    }

    private static function statusOf(Element $propstat): string
    {
        foreach ($propstat->children() as $child) {
            if ($child->name() === '{DAV:}status') {
                return $child->text();
            }
        }

        self::fail('The propstat carries no status.');
    }

    private static function propertyAt(MultiStatus $multiStatus, int $index): Element
    {
        $response = self::childAt($multiStatus->toElement(), 0);
        $prop = self::childAt(self::childAt($response, 1), 0);

        self::assertSame('{DAV:}prop', $prop->name());

        return self::childAt($prop, $index);
    }

    private static function childAt(Element $element, int $index): Element
    {
        $child = $element->children()[$index] ?? null;

        if ($child === null) {
            self::fail(sprintf('The element holds no child at %d.', $index));
        }

        return $child;
    }
}
