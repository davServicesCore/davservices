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

namespace DavServices\Tests\Unit\Dav;

use DateTimeImmutable;
use DavServices\Dav\IFile;
use RuntimeException;

/**
 * A file whose content really is a stream, for the tests that have to prove
 * nothing reads it into a string on the way out (R-TREE-02).
 *
 * What it knows about itself is settable, because a backend that cannot say
 * how long a file is, or when it changed, is the ordinary case rather than the
 * odd one — and the server has to answer either way.
 */
final class StreamFile implements IFile
{
    public function __construct(
        private readonly string $name,
        private readonly string $content = '',
        private readonly ?string $contentType = 'text/plain',
        private readonly ?string $etag = '"abc"',
        private readonly ?DateTimeImmutable $lastModified = null,
        private readonly bool $knowsItsLength = true,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function lastModified(): ?DateTimeImmutable
    {
        return $this->lastModified;
    }

    public function delete(): void
    {
    }

    /**
     * @return resource
     */
    public function get(): mixed
    {
        $stream = fopen('php://memory', 'r+b');

        if ($stream === false) {
            throw new RuntimeException('The test could not open a stream.');
        }

        fwrite($stream, $this->content);
        rewind($stream);

        return $stream;
    }

    public function put(mixed $content): ?string
    {
        return null;
    }

    public function contentType(): ?string
    {
        return $this->contentType;
    }

    public function contentLength(): ?int
    {
        return $this->knowsItsLength ? strlen($this->content) : null;
    }

    public function etag(): ?string
    {
        return $this->etag;
    }
}
