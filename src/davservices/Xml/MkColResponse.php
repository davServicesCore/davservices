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

use DavServices\Http\Response;

/**
 * The `DAV:mkcol-response` of RFC 5689 §5.2: why an extended `MKCOL` failed.
 *
 * It looks like a `207` and is not one. Only a single resource is involved —
 * the collection that was not created — so there is no `href` to put anything
 * beside, and the document carries its `propstat` blocks on its own.
 *
 * What it says is the one thing a bare `403` cannot: which of the properties
 * the client asked for is the one the server would not have. A client that
 * learns only that it was refused can do nothing but give up or guess.
 */
final class MkColResponse
{
    /** @var list<Element> */
    private array $blocks = [];

    /**
     * Adds the properties that all fared the same way.
     *
     * @param list<string> $properties Names as `{namespace}localname`
     * @param string|null $error A precondition of RFC 4918 §16 or RFC 5689
     *                           §5.3, as `{namespace}localname`, where one
     *                           applies
     */
    public function add(int $status, array $properties, ?string $error = null): void
    {
        $block = new Element('{DAV:}propstat');
        $prop = new Element('{DAV:}prop');

        foreach ($properties as $name) {
            // The name alone: this says what could not be set, and repeating
            // the value the client just sent adds nothing it does not have.
            $prop->append(new Element($name));
        }

        $block->append($prop);
        $block->append(self::status($status));

        if ($error !== null) {
            $failed = new Element('{DAV:}error');

            $failed->append(new Element($error));
            $block->append($failed);
        }

        $this->blocks[] = $block;
    }

    /**
     * The whole document, ready for the writer.
     */
    public function toElement(): Element
    {
        $document = new Element('{DAV:}mkcol-response');

        foreach ($this->blocks as $block) {
            $document->append($block);
        }

        return $document;
    }

    /**
     * `HTTP/1.1 403 Forbidden`. The phrase comes from the response object, so
     * that the library says the same thing in a status line and in a body.
     */
    private static function status(int $status): Element
    {
        $element = new Element('{DAV:}status');

        $element->appendText(rtrim(sprintf('HTTP/1.1 %d %s', $status, (new Response($status))->reason())));

        return $element;
    }
}
