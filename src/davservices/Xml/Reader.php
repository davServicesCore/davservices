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

namespace DavServices\Xml;

use DavServices\Exception\BadRequest;
use XMLReader;

/**
 * Reads the XML a client sent, and nothing else.
 *
 * This is the most exposed class in the library: it is handed whatever an
 * unauthenticated stranger cared to send. Three rules follow from that.
 *
 * **Nothing is fetched.** Every document type declaration is refused, entities
 * and all (R-XML-04). A DAV request has no use for one, and telling a harmless
 * declaration from a dangerous one is exactly the judgement a parser should
 * not have to make: it is how `file:///etc/passwd` ends up in a response, and
 * how ten nested entities turn one line into three gigabytes.
 *
 * **Nothing runs away.** Size, depth and the number of elements are capped,
 * and going past a cap is a `400` — never a memory error, never a server still
 * chewing (R-XML-05).
 *
 * **Prefixes mean nothing.** An element is named by its namespace URI and its
 * local name (R-XML-03); whether a client writes `D:`, `d:` or a default
 * namespace is its own business.
 *
 * It is built on `XMLReader` rather than on `simplexml`, as R-XML-01 requires:
 * a pull parser can stop when a document goes past a cap, while a tree parser
 * has already built the whole thing by the time anyone could ask.
 */
final class Reader
{
    /** Generous for DAV: a multiget of five thousand hrefs is a fifth of it. */
    public const DEFAULT_MAXIMUM_BYTES = 1_048_576;

    /** DAV documents are shallow; ten would do, and a hundred leaves room. */
    public const DEFAULT_MAXIMUM_DEPTH = 100;

    /** Twice what the largest report R-CAL-08 allows would need. */
    public const DEFAULT_MAXIMUM_ELEMENTS = 10_000;

    /** The nodes that carry text; everything else in a body is markup. */
    private const TEXT_NODES = [XMLReader::TEXT, XMLReader::CDATA, XMLReader::SIGNIFICANT_WHITESPACE];

    public function __construct(
        private readonly int $maximumBytes = self::DEFAULT_MAXIMUM_BYTES,
        private readonly int $maximumDepth = self::DEFAULT_MAXIMUM_DEPTH,
        private readonly int $maximumElements = self::DEFAULT_MAXIMUM_ELEMENTS,
    ) {
    }

    /**
     * Reads a document and hands back its root element.
     *
     * @throws BadRequest If the document is too large, too deep, holds too
     *                    many elements, is not well formed, or carries a
     *                    document type declaration
     */
    public function parse(string $document): Element
    {
        if (strlen($document) > $this->maximumBytes) {
            throw new BadRequest(sprintf('The XML body is longer than the %d bytes allowed.', $this->maximumBytes));
        }

        // PHP refuses an empty document with a ValueError of its own, which
        // would reach a client as a 500. What a body of nothing means is for
        // the method to decide — a PROPFIND without one asks for allprop —
        // and by the time it reaches here the request makes no sense.
        if ($document === '') {
            throw new BadRequest('The XML body is empty.');
        }

        $errors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            return $this->read($document);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($errors);
        }
    }

    /**
     * @throws BadRequest
     */
    private function read(string $document): Element
    {
        // LIBXML_NONET is belt and braces: the document type declaration that
        // could name a URL is refused below in any case.
        $reader = XMLReader::XML($document, null, LIBXML_NONET);

        // @codeCoverageIgnoreStart
        // PHP declares a false here — and a true, from the object form this
        // does not use. libxml produces neither for a document that is not
        // empty, and an empty one is refused above. Caught all the same: a
        // fatal error would be a worse answer than a 400, and no test can
        // bring the library into this state.
        if (!$reader instanceof XMLReader) {
            throw new BadRequest('The XML body could not be opened for reading.');
        }
        // @codeCoverageIgnoreEnd

        return $this->readInto($reader);
    }

    /**
     * Reads every node of the document into a tree.
     *
     * Elements are hung under whatever was last seen one level up, which is
     * all a well-formed document needs: libxml has already made sure the tags
     * match, so there is nothing here to keep a stack for.
     *
     * @throws BadRequest
     */
    private function readInto(XMLReader $reader): Element
    {
        $document = new Element('{}');
        $byDepth = [];
        $elements = 0;

        while ($this->step($reader)) {
            $this->handle($reader, $document, $byDepth, $elements);
        }

        $root = $document->children()[0] ?? null;

        // @codeCoverageIgnoreStart
        // A document without a root element is not well formed, and libxml has
        // refused it above before ever getting here. The contract of this
        // method says it hands back an element, and this is what holds it.
        if ($root === null) {
            throw new BadRequest('The XML body holds no element.');
        }
        // @codeCoverageIgnoreEnd

        return $root;
    }

    /**
     * Deals with one node of the document.
     *
     * @param array<int, Element> $byDepth The element last opened at each depth
     * @param int $elements How many have been seen, for the cap
     *
     * @throws BadRequest
     */
    private function handle(XMLReader $reader, Element $document, array &$byDepth, int &$elements): void
    {
        if ($reader->nodeType === XMLReader::DOC_TYPE) {
            throw new BadRequest('The XML body carries a document type declaration, which is not read here.');
        }

        if (in_array($reader->nodeType, self::TEXT_NODES, true)) {
            self::parentOf($byDepth, $reader->depth, $document)->appendText($reader->value);

            return;
        }

        if ($reader->nodeType === XMLReader::ELEMENT) {
            $this->openElement($reader, $document, $byDepth, $elements);
        }
    }

    /**
     * @param array<int, Element> $byDepth
     *
     * @throws BadRequest
     */
    private function openElement(XMLReader $reader, Element $document, array &$byDepth, int &$elements): void
    {
        if (++$elements > $this->maximumElements) {
            throw new BadRequest(
                sprintf('The XML body holds more than the %d elements allowed.', $this->maximumElements),
            );
        }

        if ($reader->depth >= $this->maximumDepth) {
            throw new BadRequest(
                sprintf('The XML body is nested deeper than the %d levels allowed.', $this->maximumDepth),
            );
        }

        $element = new Element(self::nameOf($reader), self::attributesOf($reader));

        self::parentOf($byDepth, $reader->depth, $document)->append($element);
        $byDepth[$reader->depth] = $element;
    }

    /**
     * What a node at this depth belongs to: the element last opened one level
     * up, or the document holder where there is none — which is the root.
     *
     * @param array<int, Element> $byDepth
     */
    private static function parentOf(array $byDepth, int $depth, Element $document): Element
    {
        return $byDepth[$depth - 1] ?? $document;
    }

    /**
     * One step through the document, turning whatever libxml collected on the
     * way into a refusal of our own.
     *
     * @throws BadRequest
     */
    private function step(XMLReader $reader): bool
    {
        $more = $reader->read();
        $error = libxml_get_last_error();

        if ($error !== false) {
            throw new BadRequest(sprintf(
                'The XML body could not be read: %s (line %d).',
                trim($error->message),
                $error->line,
            ));
        }

        return $more;
    }

    /**
     * `{namespace}localname`, with an empty namespace where the element has none.
     */
    private static function nameOf(XMLReader $reader): string
    {
        return '{' . $reader->namespaceURI . '}' . $reader->localName;
    }

    /**
     * Every attribute except the namespace declarations, which are not data:
     * they are what gives the names around them their meaning, and handing
     * them out would have every reader filtering them back out.
     *
     * @return array<string, string>
     */
    private static function attributesOf(XMLReader $reader): array
    {
        $attributes = [];

        if (!$reader->hasAttributes) {
            return $attributes;
        }

        $reader->moveToFirstAttribute();

        do {
            if ($reader->namespaceURI !== 'http://www.w3.org/2000/xmlns/') {
                $attributes[self::attributeNameOf($reader)] = $reader->value;
            }
        } while ($reader->moveToNextAttribute());

        $reader->moveToElement();

        return $attributes;
    }

    /**
     * An attribute without a prefix is in no namespace at all — unlike an
     * element it is not taken into the default one (Namespaces in XML §6.2),
     * so it keeps its plain name.
     */
    private static function attributeNameOf(XMLReader $reader): string
    {
        return $reader->namespaceURI === ''
            ? $reader->localName
            : '{' . $reader->namespaceURI . '}' . $reader->localName;
    }
}
