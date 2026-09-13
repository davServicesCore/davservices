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

/**
 * One element of a document that was read.
 *
 * The name carries its namespace: `{DAV:}propfind`, `{}unqualified`. That is
 * the form the whole library uses to talk about elements and properties
 * (R-XML-03), because a prefix means nothing — the same element arrives as
 * `D:propfind`, `d:propfind` or `propfind` with a default namespace, and all
 * three are this one name.
 *
 * Text is kept as it stands, whitespace and all. A `text-match` of CalDAV
 * searches for exactly what the client wrote, so trimming here would quietly
 * change what a search finds; callers that want a token — an href, a name —
 * trim it themselves.
 */
final class Element
{
    /** @var list<self> */
    private array $children = [];

    private string $text = '';

    /**
     * @param array<string, string> $attributes Namespaced ones keyed as
     *                                          `{namespace}localname`
     */
    public function __construct(
        private readonly string $name,
        private readonly array $attributes = [],
    ) {
    }

    /**
     * The name, as `{namespace}localname`.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * The elements inside this one, in the order they arrived.
     *
     * @return list<self>
     */
    public function children(): array
    {
        return $this->children;
    }

    /**
     * The text inside this one, as it stands.
     */
    public function text(): string
    {
        return $this->text;
    }

    /**
     * Every attribute, without the namespace declarations.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /**
     * One attribute, or null where the element does not carry it.
     */
    public function attribute(string $name): ?string
    {
        return $this->attributes[$name] ?? null;
    }

    /**
     * Adds an element inside this one.
     *
     * @internal Used by the reader while it builds the tree; an element is not
     *           meant to be changed once it has been handed over
     */
    public function append(self $child): void
    {
        $this->children[] = $child;
    }

    /**
     * Adds to the text of this element.
     *
     * @internal Used by the reader while it builds the tree
     */
    public function appendText(string $text): void
    {
        $this->text .= $text;
    }
}
