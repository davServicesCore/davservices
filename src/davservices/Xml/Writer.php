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

use XMLWriter;

/**
 * Writes an element tree out as the XML a client receives.
 *
 * Two things this class is careful about.
 *
 * **Everything is escaped.** A display name a user chose, a path with an
 * ampersand in it, a message from a backend — all of it goes out inside XML
 * that clients parse, and a value that could close its own element would be a
 * defect in every response at once. `XMLWriter` escapes text and attribute
 * values, and nothing is written any other way.
 *
 * **Every namespace is declared at the root.** Declaring one where it is first
 * used is legal, but a `PROPFIND` answer would then repeat its declarations on
 * every one of two hundred responses, and more than one client in the wild
 * reads only what the root declares. The tree is walked once to collect the
 * namespaces before a byte is written.
 */
final class Writer
{
    /** The one namespace every DAV document uses, with the prefix everybody knows. */
    public const DEFAULT_PREFIXES = ['DAV:' => 'd'];

    /**
     * @param array<string, string> $prefixes Namespace URI => the prefix to
     *                                        write it with. A namespace that
     *                                        is not named here is given one.
     */
    public function __construct(private readonly array $prefixes = self::DEFAULT_PREFIXES)
    {
    }

    /**
     * The document, as a string ready to be sent.
     */
    public function write(Element $root): string
    {
        $writer = new XMLWriter();
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');

        $this->writeElement($writer, $root, self::namespacesOf($root));

        return $writer->outputMemory();
    }

    /**
     * Every namespace the tree uses, elements and attributes alike, each once
     * and in the order it is met.
     *
     * @return list<string>
     */
    private static function namespacesOf(Element $element): array
    {
        $namespaces = [self::namespaceOf($element->name())];

        foreach (array_keys($element->attributes()) as $attribute) {
            $namespaces[] = self::namespaceOf($attribute);
        }

        foreach ($element->children() as $child) {
            foreach (self::namespacesOf($child) as $namespace) {
                $namespaces[] = $namespace;
            }
        }

        return array_values(array_unique(array_filter(
            $namespaces,
            static fn (string $namespace): bool => $namespace !== '',
        )));
    }

    /**
     * @param list<string> $declare The namespaces to declare here, which is
     *                              the whole document's worth at the root and
     *                              nothing at all anywhere else
     */
    private function writeElement(XMLWriter $writer, Element $element, array $declare): void
    {
        $writer->startElement($this->qualified($element->name()));

        foreach ($declare as $namespace) {
            $writer->writeAttribute('xmlns:' . $this->prefixOf($namespace), $namespace);
        }

        foreach ($element->attributes() as $name => $value) {
            $writer->writeAttribute($this->qualified($name), $value);
        }

        if ($element->text() !== '') {
            $writer->text($element->text());
        }

        foreach ($element->children() as $child) {
            $this->writeElement($writer, $child, []);
        }

        $writer->endElement();
    }

    /**
     * `{DAV:}response` becomes `d:response`, `{}plain` stays `plain`.
     */
    private function qualified(string $name): string
    {
        $end = strpos($name, '}');

        // An attribute may carry a plain name with no braces at all, and it
        // keeps it: unlike an element it is in no namespace unless a prefix
        // puts it in one (Namespaces in XML §6.2).
        if ($end === false) {
            return $name;
        }

        $namespace = substr($name, 1, $end - 1);
        $local = substr($name, $end + 1);

        return $namespace === '' ? $local : $this->prefixOf($namespace) . ':' . $local;
    }

    /**
     * The prefix a namespace is written with.
     *
     * One nobody named is given a prefix derived from the namespace itself,
     * rather than a number counted out while walking the tree. It is the same
     * prefix wherever that namespace turns up, the document always comes out
     * the same way, and the writer has nothing to remember between elements.
     */
    private function prefixOf(string $namespace): string
    {
        return $this->prefixes[$namespace] ?? 'x' . substr(md5($namespace), 0, 6);
    }

    /**
     * The namespace out of a `{namespace}localname`.
     */
    private static function namespaceOf(string $name): string
    {
        $end = strpos($name, '}');

        return $end === false ? '' : substr($name, 1, $end - 1);
    }
}
