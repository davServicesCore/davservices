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

use Closure;
use InvalidArgumentException;

/**
 * What each element of a document means, by name.
 *
 * An element arrives as a name, some attributes, some children and some text.
 * What it *means* — a set of property names, a filter, a lock request — is
 * registered here against `{namespace}localname`, and plugins add their own
 * (R-XML-02). Without it, every method of the server would have to know the
 * shape of every element that could turn up inside its request.
 *
 * The two directions are deliberately not symmetric.
 *
 * **Reading tolerates the unknown.** An element nobody registered a reader for
 * is handed back as it stands. A client asking for a property this server has
 * never heard of is an ordinary Tuesday, and a dead property is by definition
 * an element nobody knows anything about (R-PROP-03) — a server that fell over
 * either would fall over on nearly every `PROPFIND` sent to it.
 *
 * **Writing does not.** A value nobody registered a writer for is refused: the
 * server writes only what it meant to write, and something invented in its
 * place would go out on the wire as though it had been meant. A missing writer
 * is a gap in this library, not a fault of a client's.
 */
final class ElementRegistry
{
    /** @var array<string, Closure(Element, self): mixed> */
    private array $readers = [];

    /** @var array<string, Closure(mixed, self): Element> */
    private array $writers = [];

    /**
     * Registers what an element of this name means.
     *
     * The reader is handed the registry along with the element, so that it can
     * read what is inside without holding on to one of its own — which is what
     * `DAV:prop` does with every property in it.
     *
     * A later registration takes over from an earlier one. That is what makes
     * this an extension point rather than a table: a CalDAV plugin knows more
     * about a calendar's `DAV:resourcetype` than the core ever will.
     *
     * @param string $name As `{namespace}localname`
     * @param Closure(Element, self): mixed $reader
     */
    public function readWith(string $name, Closure $reader): void
    {
        $this->readers[$name] = $reader;
    }

    /**
     * Registers how a value of this name is written.
     *
     * @param string $name As `{namespace}localname`
     * @param Closure(mixed, self): Element $writer
     */
    public function writeWith(string $name, Closure $writer): void
    {
        $this->writers[$name] = $writer;
    }

    /**
     * What the element means, or the element itself where nobody has said.
     */
    public function read(Element $element): mixed
    {
        $reader = $this->readers[$element->name()] ?? null;

        if ($reader === null) {
            return $element;
        }

        return $reader($element, $this);
    }

    /**
     * The element a value of this name is written as.
     *
     * @throws InvalidArgumentException If nothing is registered for the name
     */
    public function write(string $name, mixed $value): Element
    {
        $writer = $this->writers[$name] ?? null;

        if ($writer === null) {
            throw new InvalidArgumentException(sprintf('Nothing is registered to write "%s".', $name));
        }

        return $writer($value, $this);
    }
}
