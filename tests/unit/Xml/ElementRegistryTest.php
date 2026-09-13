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
use DavServices\Xml\ElementRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-XML-02: a registry from `{namespace}localname` to
 * the readers and writers of that element, which plugins add to.
 *
 * The asymmetry between the two directions is the point of this class, and it
 * is deliberate. **An element nobody registered a reader for is handed back as
 * it is** — a `PROPFIND` asking for a property this server has never heard of
 * is an ordinary Tuesday, and a dead property is precisely an element nobody
 * knows anything about (R-PROP-03). **A value nobody registered a writer for
 * is refused**, because the server writes only what it meant to write, and a
 * missing writer is a mistake of ours rather than of a client.
 */
#[CoversClass(ElementRegistry::class)]
final class ElementRegistryTest extends TestCase
{
    public function testReadsAnElementWithWhatWasRegisteredForIt(): void
    {
        $registry = new ElementRegistry();
        $registry->readWith('{DAV:}href', static fn (Element $element): string => trim($element->text()));

        $href = new Element('{DAV:}href');
        $href->appendText("\n /calendars/alice/ \n");

        self::assertSame('/calendars/alice/', $registry->read($href));
    }

    /**
     * A server that fell over an element it had never seen would fall over on
     * every `PROPFIND` a client sends, since clients ask for properties of
     * their own as a matter of course.
     */
    public function testHandsBackAnElementNobodyRegisteredAnythingFor(): void
    {
        $unknown = new Element('{urn:some-client}private-property');

        self::assertSame($unknown, (new ElementRegistry())->read($unknown));
    }

    /**
     * The name is the namespace and the local name together. Two protocols
     * using the same word for different things is the ordinary case —
     * `calendar-data` means one thing to CalDAV and nothing at all to DAV.
     */
    public function testTellsTheSameLocalNameInTwoNamespacesApart(): void
    {
        $registry = new ElementRegistry();
        $registry->readWith('{DAV:}thing', static fn (): string => 'dav');
        $registry->readWith('{urn:other}thing', static fn (): string => 'other');

        self::assertSame('dav', $registry->read(new Element('{DAV:}thing')));
        self::assertSame('other', $registry->read(new Element('{urn:other}thing')));
    }

    /**
     * A reader is handed the registry along with its element, so that it can
     * read what is inside it without having to hold on to one — which is what
     * `DAV:prop` does with every property in it.
     */
    public function testAReaderReadsTheChildrenThroughTheRegistryItIsGiven(): void
    {
        $registry = new ElementRegistry();
        $registry->readWith('{DAV:}href', static fn (Element $element): string => $element->text());
        $registry->readWith(
            '{DAV:}prop',
            static fn (Element $element, ElementRegistry $registry): array => array_map(
                static fn (Element $child): mixed => $registry->read($child),
                $element->children(),
            ),
        );

        $prop = new Element('{DAV:}prop');
        $first = new Element('{DAV:}href');
        $first->appendText('/one');
        $second = new Element('{DAV:}href');
        $second->appendText('/two');
        $prop->append($first);
        $prop->append($second);

        self::assertSame(['/one', '/two'], $registry->read($prop));
    }

    /**
     * A plugin registering for a name the library already knows takes it over.
     * That is what makes the registry an extension point rather than a table:
     * a CalDAV plugin knows more about `DAV:resourcetype` than the core does.
     */
    public function testTheLastRegistrationWins(): void
    {
        $registry = new ElementRegistry();
        $registry->readWith('{DAV:}thing', static fn (): string => 'the library');
        $registry->readWith('{DAV:}thing', static fn (): string => 'the plugin');

        self::assertSame('the plugin', $registry->read(new Element('{DAV:}thing')));
    }

    public function testWritesAValueWithWhatWasRegisteredForIt(): void
    {
        $registry = new ElementRegistry();
        $registry->writeWith('{DAV:}href', static function (mixed $value): Element {
            $element = new Element('{DAV:}href');
            $element->appendText(is_string($value) ? $value : '');

            return $element;
        });

        $written = $registry->write('{DAV:}href', '/calendars/alice/');

        self::assertSame('{DAV:}href', $written->name());
        self::assertSame('/calendars/alice/', $written->text());
    }

    /**
     * The other direction, and the one where silence would be wrong: an
     * element the server cannot write is a gap in the server, and answering
     * with something invented would put it on the wire as though it were
     * meant.
     */
    public function testRefusesToWriteWhatNobodyRegisteredAWriterFor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/\{DAV:\}resourcetype/');

        (new ElementRegistry())->write('{DAV:}resourcetype', 'collection');
    }

    public function testAWriterWritesTheChildrenThroughTheRegistryItIsGiven(): void
    {
        $registry = new ElementRegistry();
        $registry->writeWith('{DAV:}href', static function (mixed $value): Element {
            $element = new Element('{DAV:}href');
            $element->appendText(is_string($value) ? $value : '');

            return $element;
        });
        $registry->writeWith(
            '{DAV:}prop',
            static function (mixed $value, ElementRegistry $registry): Element {
                $element = new Element('{DAV:}prop');

                foreach (is_array($value) ? $value : [] as $href) {
                    $element->append($registry->write('{DAV:}href', $href));
                }

                return $element;
            },
        );

        $written = $registry->write('{DAV:}prop', ['/one', '/two']);

        $first = $written->children()[0] ?? null;

        self::assertCount(2, $written->children());
        self::assertNotNull($first);
        self::assertSame('/one', $first->text());
    }

    /**
     * R-ARC-05 and R-ARC-07 again: no static state, so a process may hold as
     * many of these as it has servers.
     */
    public function testTwoRegistriesKnowNothingOfEachOther(): void
    {
        $one = new ElementRegistry();
        $other = new ElementRegistry();

        $one->readWith('{DAV:}thing', static fn (): string => 'one');

        $element = new Element('{DAV:}thing');

        self::assertSame('one', $one->read($element));
        self::assertSame($element, $other->read($element));
    }

    /**
     * What the registry reads it writes back, which is what lets a property
     * survive a round trip through a server that only stores it.
     */
    public function testWhatIsReadCanBeWrittenBack(): void
    {
        $registry = new ElementRegistry();
        $registry->readWith('{DAV:}displayname', static fn (Element $element): string => $element->text());
        $registry->writeWith('{DAV:}displayname', static function (mixed $value): Element {
            $element = new Element('{DAV:}displayname');
            $element->appendText(is_string($value) ? $value : '');

            return $element;
        });

        $original = new Element('{DAV:}displayname');
        $original->appendText('Alice');

        $written = $registry->write('{DAV:}displayname', $registry->read($original));

        self::assertSame('Alice', $written->text());
    }

    /**
     * A reader and a writer are kept apart: registering one does not make the
     * other appear, and the failure when it is missing says which is missing.
     */
    public function testAReaderIsNoWriter(): void
    {
        $registry = new ElementRegistry();
        $registry->readWith('{DAV:}thing', static fn (): string => 'read');

        $this->expectException(InvalidArgumentException::class);

        $registry->write('{DAV:}thing', 'anything');
    }

    public function testAWriterIsNoReader(): void
    {
        $registry = new ElementRegistry();
        $registry->writeWith('{DAV:}thing', static fn (): Element => new Element('{DAV:}thing'));

        $element = new Element('{DAV:}thing');

        self::assertSame($element, $registry->read($element));
    }

}
