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

use DavServices\Exception\BadRequest;
use DavServices\Xml\Element;
use DavServices\Xml\Reader;
use DOMDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-XML-01 and R-XML-03 to R-XML-08.
 *
 * This class reads what an unauthenticated stranger sends, which makes it the
 * most exposed piece of the library. Three things are being held down:
 *
 * - **Nothing is fetched.** An entity that names a file or a URL must not be
 *   resolved; that is how XML parsers hand out `/etc/passwd` (R-XML-04).
 * - **Nothing runs away.** A document may be refused for its size, its depth
 *   or the number of elements in it, and the refusal is a `400` — never a
 *   memory error, and never a server that is still chewing (R-XML-05).
 * - **Prefixes mean nothing.** `D:`, `d:` or a default namespace all name the
 *   same element, because only the namespace URI counts (R-XML-03).
 */
#[CoversClass(Reader::class)]
#[CoversClass(Element::class)]
final class ReaderTest extends TestCase
{
    private const DAV = '{DAV:}';

    /**
     * The classic. A parser that resolves this hands the contents of a file to
     * whoever asked, and it has been doing so in one product or another every
     * year for two decades.
     */
    public function testDoesNotFetchAFileAnEntityNames(): void
    {
        $this->expectException(BadRequest::class);

        (new Reader())->parse(
            '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            . '<d:propfind xmlns:d="DAV:">&xxe;</d:propfind>',
        );
    }

    public function testDoesNotFetchAUrlAnEntityNames(): void
    {
        $this->expectException(BadRequest::class);

        (new Reader())->parse(
            '<!DOCTYPE d [<!ENTITY out SYSTEM "http://dav.example/collect">]><d>&out;</d>',
        );
    }

    /**
     * The billion laughs: ten entities, each ten of the one below it, and a
     * parser that expands them needs three gigabytes for a document of one
     * line. It is refused before any of that, and the test would take minutes
     * rather than milliseconds if it were not.
     */
    public function testRefusesTheDocumentThatWouldExpandToGigabytes(): void
    {
        $entities = '<!ENTITY a0 "haha">';

        for ($level = 1; $level <= 9; $level++) {
            $entities .= sprintf('<!ENTITY a%d "%s">', $level, str_repeat('&a' . ($level - 1) . ';', 10));
        }

        $this->expectException(BadRequest::class);

        (new Reader())->parse(sprintf('<!DOCTYPE d [%s]><d>&a9;</d>', $entities));
    }

    /**
     * Every document type declaration is refused, entities or not. A DAV
     * request has no use for one, and telling a harmless one from a dangerous
     * one is exactly the judgement this class should not have to make.
     */
    public function testRefusesADocumentTypeDeclarationEvenWithoutEntities(): void
    {
        $this->expectException(BadRequest::class);

        (new Reader())->parse('<!DOCTYPE propfind><propfind/>');
    }

    public function testRefusesAnEntityThatWasNeverDeclared(): void
    {
        $this->expectException(BadRequest::class);

        (new Reader())->parse('<d:propfind xmlns:d="DAV:">&nothing;</d:propfind>');
    }

    #[DataProvider('malformedDocuments')]
    public function testRefusesWhatIsNoWellFormedDocument(string $document): void
    {
        $this->expectException(BadRequest::class);

        (new Reader())->parse($document);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedDocuments(): iterable
    {
        yield 'nothing at all' => [''];
        yield 'only whitespace' => ["  \n "];
        yield 'not XML' => ['this is not XML'];
        yield 'an element that is never closed' => ['<propfind>'];
        yield 'a closing tag that does not match' => ['<propfind></prop>'];
        yield 'junk before the root' => ['nonsense<propfind/>'];
        yield 'two roots' => ['<one/><other/>'];
        yield 'an unescaped ampersand' => ['<d>a & b</d>'];
    }

    #[DataProvider('limits')]
    public function testRefusesADocumentThatGoesPastALimit(Reader $reader, string $document): void
    {
        $this->expectException(BadRequest::class);

        $reader->parse($document);
    }

    /**
     * The limits are configurable, and the test sets them low rather than
     * building a document of a megabyte to prove it (R-XML-05).
     *
     * @return iterable<string, array{Reader, string}>
     */
    public static function limits(): iterable
    {
        yield 'longer than allowed' => [
            new Reader(maximumBytes: 20),
            '<d:propfind xmlns:d="DAV:"><d:allprop/></d:propfind>',
        ];

        // One past the limit, not two: an off-by-one has to be fatal here, and
        // a document that overshoots by a mile would let one through.
        yield 'one level deeper than allowed' => [
            new Reader(maximumDepth: 3),
            '<a><b><c><d/></c></b></a>',
        ];

        yield 'one element more than allowed' => [
            new Reader(maximumElements: 3),
            '<a><b/><c/><d/></a>',
        ];
    }

    public function testAcceptsADocumentThatSitsExactlyOnItsLimits(): void
    {
        $document = '<a><b><c/></b></a>';

        $reader = new Reader(maximumBytes: strlen($document), maximumDepth: 3, maximumElements: 3);

        self::assertSame('{}a', $reader->parse($document)->name());
    }

    /**
     * R-XML-03: only the namespace URI decides. Every one of these is the same
     * element to this library, and a client may pick whichever prefix it likes
     * — many pick `D:`, Microsoft's pick `a:`, and some declare a default.
     */
    #[DataProvider('spellingsOfPropfind')]
    public function testReadsAnElementByItsNamespaceRatherThanItsPrefix(string $document): void
    {
        self::assertSame(self::DAV . 'propfind', (new Reader())->parse($document)->name());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function spellingsOfPropfind(): iterable
    {
        yield 'the usual prefix' => ['<D:propfind xmlns:D="DAV:"/>'];
        yield 'in lower case' => ['<d:propfind xmlns:d="DAV:"/>'];
        yield 'a prefix nobody else uses' => ['<whatever:propfind xmlns:whatever="DAV:"/>'];
        yield 'a default namespace' => ['<propfind xmlns="DAV:"/>'];
        yield 'declared on an ancestor' => ['<x:propfind xmlns:x="DAV:"/>'];
    }

    public function testAnElementWithoutANamespaceHasAnEmptyOne(): void
    {
        self::assertSame('{}propfind', (new Reader())->parse('<propfind/>')->name());
    }

    public function testKeepsTheNamespaceOfEveryElementApart(): void
    {
        $document = '<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
            . '<d:prop><d:getetag/><c:calendar-data/></d:prop></d:propfind>';

        $prop = self::childAt((new Reader())->parse($document), 0);

        self::assertSame(self::DAV . 'prop', $prop->name());
        self::assertSame(self::DAV . 'getetag', self::childAt($prop, 0)->name());
        self::assertSame('{urn:ietf:params:xml:ns:caldav}calendar-data', self::childAt($prop, 1)->name());
    }

    public function testReadsTheChildrenInOrder(): void
    {
        $document = '<d:prop xmlns:d="DAV:"><d:getetag/><d:displayname/><d:resourcetype/></d:prop>';

        $names = array_map(
            static fn (Element $child): string => $child->name(),
            (new Reader())->parse($document)->children(),
        );

        self::assertSame(
            [self::DAV . 'getetag', self::DAV . 'displayname', self::DAV . 'resourcetype'],
            $names,
        );
    }

    public function testAnEmptyElementHasNeitherChildrenNorText(): void
    {
        $element = (new Reader())->parse('<d:allprop xmlns:d="DAV:"/>');

        self::assertSame([], $element->children());
        self::assertSame('', $element->text());
    }

    public function testReadsTheTextOfAnElement(): void
    {
        self::assertSame('/calendars/alice/', (new Reader())->parse('<href>/calendars/alice/</href>')->text());
    }

    public function testReadsTextThatArrivedInACdataSection(): void
    {
        self::assertSame('a < b', (new Reader())->parse('<t><![CDATA[a < b]]></t>')->text());
    }

    /**
     * Text is handed over as it stands, with its whitespace. A `text-match` of
     * CalDAV searches for exactly what the client wrote, trailing space and
     * all, so trimming here would change what a search finds. Callers that
     * want a token — an href, a name — trim it themselves.
     */
    public function testKeepsTheWhitespaceInTextAsItStands(): void
    {
        self::assertSame(' foo ', (new Reader())->parse('<t> foo </t>')->text());
    }

    public function testJoinsTextThatWasBrokenUpByAnEntityOrASection(): void
    {
        self::assertSame('a<b', (new Reader())->parse('<t>a&lt;<![CDATA[b]]></t>')->text());
    }

    public function testReadsTheAttributesOfAnElement(): void
    {
        $element = (new Reader())->parse('<t name="value" other="second"/>');

        self::assertSame(['name' => 'value', 'other' => 'second'], $element->attributes());
        self::assertSame('value', $element->attribute('name'));
        self::assertNull($element->attribute('missing'));
    }

    /**
     * A namespace declaration is not an attribute of the element: it is what
     * gives the names around it their meaning, and handing it out as data
     * would have every reader filtering it back out.
     */
    public function testDoesNotHandOutNamespaceDeclarationsAsAttributes(): void
    {
        $element = (new Reader())->parse('<d:t xmlns:d="DAV:" xmlns="urn:x" name="value"/>');

        self::assertSame(['name' => 'value'], $element->attributes());
    }

    public function testReadsAnAttributeThatCarriesANamespace(): void
    {
        $element = (new Reader())->parse('<t xmlns:x="urn:x" x:flag="yes"/>');

        self::assertSame(['{urn:x}flag' => 'yes'], $element->attributes());
    }

    public function testPassesOverCommentsAndProcessingInstructions(): void
    {
        $document = '<?xml version="1.0"?><!-- a note --><d:prop xmlns:d="DAV:">'
            . '<!-- another --><?php echo "nothing"; ?><d:getetag/></d:prop>';

        $element = (new Reader())->parse($document);

        self::assertCount(1, $element->children());
        self::assertSame('', $element->text());
    }

    /**
     * R-XML-08. A client that announces another encoding is read in it and
     * handed over as UTF-8; bytes that are neither are refused rather than
     * carried into a response, where they would break the XML the server
     * writes.
     */
    public function testReadsADocumentThatAnnouncesAnotherEncoding(): void
    {
        $document = "<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?><t>\xDCbung</t>";

        self::assertSame("\u{00DC}bung", (new Reader())->parse($document)->text());
    }

    public function testKeepsUtf8AsItIs(): void
    {
        self::assertSame("\u{00DC}bung", (new Reader())->parse("<t>\u{00DC}bung</t>")->text());
    }

    public function testRefusesBytesThatAreNoValidEncoding(): void
    {
        $this->expectException(BadRequest::class);

        (new Reader())->parse("<t>\xFF\xFE</t>");
    }

    /**
     * Whitespace between elements is text like any other, and is kept. A
     * container's own text is of no interest to a reader of `DAV:prop`, and
     * dropping it would mean deciding elsewhere what counts as meaningful.
     */
    public function testKeepsTheWhitespaceBetweenElementsAsText(): void
    {
        $element = (new Reader())->parse("<d:prop xmlns:d=\"DAV:\">\n  <d:getetag/>\n</d:prop>");

        self::assertSame("\n  \n", $element->text());
        self::assertCount(1, $element->children());
    }

    /**
     * libxml ends its messages with a newline. Left in, it lands in the middle
     * of ours — one log line becomes two, and a grep becomes a puzzle.
     */
    public function testTheMessageIsOneLine(): void
    {
        try {
            (new Reader())->parse('<broken>');
        } catch (BadRequest $refused) {
            self::assertStringNotContainsString(chr(10), $refused->getMessage());

            return;
        }

        self::fail('A document that is not well formed was accepted.');
    }

    /**
     * The library shares libxml's error handling with everything else in the
     * process. An error somebody else left lying about must not be read as a
     * fault in this document.
     */
    public function testIsNotConfusedByAnErrorSomebodyElseLeftBehind(): void
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        @$document->loadXML('<broken>');

        try {
            self::assertSame('{}fine', (new Reader())->parse('<fine/>')->name());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * And it leaves nothing lying about itself: neither its error, which would
     * be read as a fault in whatever is parsed next, nor the error handling it
     * switched on, which would swallow warnings the rest of the process wants.
     */
    public function testLeavesLibxmlAsItFoundIt(): void
    {
        $previous = libxml_use_internal_errors(false);

        try {
            (new Reader())->parse('<broken>');
        } catch (BadRequest) {
            // The point of the test is what is left behind.
        }

        $handling = libxml_use_internal_errors(false);
        $error = libxml_get_last_error();

        libxml_use_internal_errors($previous);

        self::assertFalse($handling, 'The reader left libxml collecting errors internally.');
        self::assertFalse($error, 'The reader left its error behind for whoever parses next.');
    }

    /**
     * The message names where it went wrong. A client developer reading a
     * `400` with nothing in it has to guess, and the line number is the one
     * thing this library knows that they do not.
     */
    public function testSaysWhereTheDocumentWentWrong(): void
    {
        $this->expectExceptionMessageMatches('/line \d+/');

        (new Reader())->parse("<d:propfind xmlns:d=\"DAV:\">\n<d:allprop>\n</d:propfind>");
    }

    /**
     * The reader is left as it was found: parsing a broken document must not
     * make the next one fail, and the library shares libxml's error handling
     * with whatever else the process is doing.
     */
    public function testAFailedParseLeavesTheNextOneAlone(): void
    {
        $reader = new Reader();

        try {
            $reader->parse('<broken>');
        } catch (BadRequest) {
            // The point of the test is what happens next.
        }

        self::assertSame('{}fine', $reader->parse('<fine/>')->name());
    }

    private static function childAt(Element $element, int $index): Element
    {
        $child = $element->children()[$index] ?? null;

        if ($child === null) {
            self::fail(sprintf('The element holds no child at %d.', $index));
        }

        return $child;
    }

    /**
     * A `PROPFIND` may arrive with several thousand hrefs in it, and the
     * limits are there to stop an attack, not a client doing its job.
     */
    public function testReadsADocumentOfTheSizeARealRequestHas(): void
    {
        $hrefs = str_repeat('<d:href>/calendars/alice/1234567890.ics</d:href>', 2_000);

        $element = (new Reader())->parse(
            sprintf('<d:calendar-multiget xmlns:d="DAV:">%s</d:calendar-multiget>', $hrefs),
        );

        self::assertCount(2_000, $element->children());
    }
}
