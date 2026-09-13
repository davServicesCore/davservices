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

/**
 * A file that lives in a string, for the tests of this layer.
 */
final class MemoryFile implements IFile
{
    public function __construct(
        private readonly string $name,
        private string $content = '',
    ) {
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
        $this->content = '';
    }

    public function get(): string
    {
        return $this->content;
    }

    public function put(mixed $content): string
    {
        $this->content = is_string($content) ? $content : (string) stream_get_contents($content);

        return $this->etag();
    }

    public function contentType(): string
    {
        return 'application/octet-stream';
    }

    public function contentLength(): int
    {
        return strlen($this->content);
    }

    public function etag(): string
    {
        return '"' . md5($this->content) . '"';
    }
}
