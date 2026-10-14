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

namespace DavServices\Tests\Unit\CardDav\Report;

use DateTimeImmutable;
use DavServices\Dav\IFile;

/**
 * A card that says how often it has been read.
 *
 * **It is here because RFC 6352 §8.6.2 lets a server limit a response "to
 * limit the amount of work expended in processing a query"** — so stopping is
 * the point of a limit, not a detail of how one is applied. Nothing in a
 * multistatus shows whether the server read the rest of the address book
 * before deciding not to report it, which is why the counting has to come from
 * the store.
 *
 * A mutation run on 14.10.2026 made the case: turning the `break` that ends
 * the walk into a `continue` left every response exactly as it was, and no test
 * in the suite could tell the two apart.
 */
final class CountingCard implements IFile
{
    private int $reads = 0;

    public function __construct(
        private readonly string $name,
        private readonly string $body,
    ) {
    }

    /**
     * How often the body has been asked for.
     */
    public function reads(): int
    {
        return $this->reads;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function lastModified(): ?DateTimeImmutable
    {
        return null;
    }

    public function delete(): void
    {
    }

    public function get(): string
    {
        ++$this->reads;

        return $this->body;
    }

    public function put(mixed $content): ?string
    {
        return null;
    }

    public function contentType(): string
    {
        return 'text/vcard; charset=utf-8';
    }

    public function contentLength(): int
    {
        return strlen($this->body);
    }

    public function etag(): string
    {
        return sprintf('"%s"', md5($this->body));
    }
}
