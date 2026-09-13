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
use DavServices\Xml\Reader;
use DavServices\Xml\Writer;
use DOMDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-XML-07 (a correct `207 Multi-Status`) and R-XML-08
 * (UTF-8 out).
 *
 * Two things are held down here. **Everything is escaped**: a display name a
 * user chose, a path with an ampersand in it, a message from a backend — all
 * of it goes out inside XML that clients parse, and a value that could break
 * out of its element would be a defect in every response at once.
 *
 * And **every namespace is declared at the root**. It is legal to declare one
 * where it is first used, but a `PROPFIND` answer repeats its declarations on
 * every one of two hundred responses that way, and more than one client in the
 * wild reads only what the root declares.
 */
#[CoversClass(Writer::class)]
final class WriterTest extends TestCase
{
    public function testWritesADocumentThatSaysItIsUtf8(): void
    {
        $written = (new Writer())->write(new Element('{DAV:}multistatus'));

        self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $written);
    }

    public function testWritesAnElementOfTheDavNamespaceWithAShortPrefix(): void
    {
        $written = (new Writer())->write(new Element('{DAV:}multistatus'));

        self::assertStringContainsString('<d:multistatus xmlns:d="DAV:"/>', $written);
    }

    public function testWritesAnElementOfNoNamespaceWithoutAPrefix(): void
    {
        self::assertStringContainsString('<plain/>', (new Writer())->write(new Element('{}plain')));
    }

    /**
     * A namespace nobody named a prefix for still needs one, and the same one
     * throughout the document — a client that reads `x1:` at the root has to
     * find `x1:` further down.
     */
    public function testInventsAPrefixForANamespaceNobodyNamed(): void
    {
        $root = new Element('{DAV:}multistatus');
        $root->append(new Element('{http://example.test/ns}first'));
        $root->append(new Element('{http://example.test/ns}second'));

        $written = (new Writer())->write($root);

        $prefix = self::prefixDeclaredFor('http://example.test/ns', $written);

        self::assertStringContainsString(sprintf('<%s:first/>', $prefix), $written);
        self::assertStringContainsString(sprintf('<%s:second/>', $prefix), $written);
    }

    /**
     * The prefix an invented one gets is nobody's business but the writer's;
     * that it is the same everywhere is everybody's.
     */
    private static function prefixDeclaredFor(string $namespace, string $written): string
    {
        preg_match(sprintf('/xmlns:([^=]+)="%s"/', preg_quote($namespace, '/')), $written, $found);

        $prefix = $found[1] ?? null;

        if ($prefix === null) {
            self::fail(sprintf('The document declares no prefix for "%s".', $namespace));
        }

        return $prefix;
    }

    /**
     * Two namespaces must never end up under one prefix: the second
     * declaration would win, and every element of the first would be read as
     * belonging to the second — a document that says something other than
     * what the server meant.
     */
    public function testGivesTwoNamespacesTwoPrefixes(): void
    {
        $root = new Element('{urn:one}root');
        $root->append(new Element('{urn:other}child'));

        $written = (new Writer())->write($root);

        self::assertNotSame(
            self::prefixDeclaredFor('urn:one', $written),
            self::prefixDeclaredFor('urn:other', $written),
        );
    }

    public function testTakesThePrefixesItWasGiven(): void
    {
        $root = new Element('{urn:ietf:params:xml:ns:caldav}calendar-data');

        $written = (new Writer(['urn:ietf:params:xml:ns:caldav' => 'c']))->write($root);

        self::assertStringContainsString('<c:calendar-data xmlns:c="urn:ietf:params:xml:ns:caldav"/>', $written);
    }

    /**
     * R-XML-07 in one document: the declarations belong to the root, and
     * nowhere else.
     */
    public function testDeclaresEveryNamespaceAtTheRootAndNowhereElse(): void
    {
        $root = new Element('{DAV:}multistatus');
        $child = new Element('{DAV:}response');
        $child->append(new Element('{urn:ietf:params:xml:ns:caldav}calendar-data'));
        $root->append($child);

        $written = (new Writer())->write($root);

        $belowTheRoot = substr($written, (int) strpos($written, '<d:response'));

        self::assertStringContainsString('<d:multistatus xmlns:d="DAV:"', $written);
        self::assertStringNotContainsString('xmlns:', $belowTheRoot, 'A namespace was declared below the root.');
        self::assertSame(2, substr_count($written, 'xmlns:'), 'Both namespaces belong at the root.');
    }

    public function testWritesTheChildrenInOrder(): void
    {
        $root = new Element('{DAV:}prop');
        $root->append(new Element('{DAV:}getetag'));
        $root->append(new Element('{DAV:}displayname'));

        $written = (new Writer())->write($root);

        self::assertStringContainsString('<d:getetag/><d:displayname/>', $written);
    }

    public function testWritesTheTextOfAnElement(): void
    {
        $element = new Element('{DAV:}href');
        $element->appendText('/calendars/alice/');

        self::assertStringContainsString('<d:href xmlns:d="DAV:">/calendars/alice/</d:href>', (new Writer())->write($element));
    }

    /**
     * Every one of these comes from somewhere a user or a client can reach: a
     * display name, a file name, a message. A value that could close its own
     * element would be a defect in every response the server sends.
     */
    #[DataProvider('dangerousText')]
    public function testEscapesWhatCouldBreakOutOfItsElement(string $text, string $expected): void
    {
        $element = new Element('{DAV:}displayname');
        $element->appendText($text);

        self::assertStringContainsString('<d:displayname xmlns:d="DAV:">' . $expected . '</d:displayname>', (new Writer())->write($element));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dangerousText(): iterable
    {
        yield 'markup' => ['<script>alert(1)</script>', '&lt;script&gt;alert(1)&lt;/script&gt;'];
        yield 'an ampersand' => ['Bloggs & Son', 'Bloggs &amp; Son'];
        yield 'a closing tag' => ['</d:displayname>', '&lt;/d:displayname&gt;'];
        yield 'an entity that is not one' => ['&amp;', '&amp;amp;'];
    }

    public function testEscapesWhatCouldBreakOutOfAnAttribute(): void
    {
        $element = new Element('{DAV:}response', ['name' => 'a "quoted" & <marked> value']);

        $written = (new Writer())->write($element);

        self::assertStringContainsString('name="a &quot;quoted&quot; &amp; &lt;marked&gt; value"', $written);
        self::assertSame(1, substr_count($written, 'xmlns:'), 'A plain attribute was read as a namespace.');
    }

    /**
     * A prefix has to be a name XML allows. The hash a namespace gets its
     * prefix from begins with a digit often enough, and a document whose
     * prefix began with one would be refused by every parser that read it.
     */
    #[DataProvider('namespacesWithoutAPrefix')]
    public function testTheInventedPrefixIsAName(string $namespace): void
    {
        $written = (new Writer())->write(new Element(sprintf('{%s}thing', $namespace)));

        self::assertMatchesRegularExpression(
            '/^[A-Za-z_][A-Za-z0-9_.-]*$/',
            self::prefixDeclaredFor($namespace, $written),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namespacesWithoutAPrefix(): iterable
    {
        yield 'a urn' => ['urn:x'];
        yield 'a URL' => ['http://example.test/ns'];
        yield 'another urn' => ['urn:example'];
    }

    public function testWritesAnAttributeThatCarriesANamespace(): void
    {
        $element = new Element('{DAV:}response', ['{urn:x}flag' => 'yes']);

        $written = (new Writer())->write($element);

        $prefix = self::prefixDeclaredFor('urn:x', $written);

        self::assertStringContainsString(sprintf('%s:flag="yes"', $prefix), $written);
    }

    public function testKeepsUtf8AsItIs(): void
    {
        $element = new Element('{DAV:}displayname');
        $element->appendText("\u{00DC}bung");

        self::assertStringContainsString("<d:displayname xmlns:d=\"DAV:\">\u{00DC}bung</d:displayname>", (new Writer())->write($element));
    }

    public function testWritesADocumentEveryParserAccepts(): void
    {
        $root = new Element('{DAV:}multistatus');
        $response = new Element('{DAV:}response');
        $href = new Element('{DAV:}href');
        $href->appendText('/a & b/');
        $response->append($href);
        $root->append($response);

        $document = new DOMDocument();

        self::assertTrue($document->loadXML((new Writer())->write($root)));
    }

    /**
     * What this library writes, it must be able to read: the two are used on
     * both ends of a proxy, a test and a migration.
     */
    public function testWhatIsWrittenCanBeReadBack(): void
    {
        $root = new Element('{DAV:}multistatus');
        $response = new Element('{DAV:}response', ['{urn:x}flag' => 'yes']);
        $href = new Element('{DAV:}href');
        $href->appendText('/a & b/<c>');
        $response->append($href);
        $root->append($response);

        $read = (new Reader())->parse((new Writer())->write($root));

        $response = self::childAt($read, 0);

        self::assertSame('{DAV:}multistatus', $read->name());
        self::assertSame('{DAV:}response', $response->name());
        self::assertSame(['{urn:x}flag' => 'yes'], $response->attributes());
        self::assertSame('/a & b/<c>', self::childAt($response, 0)->text());
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
