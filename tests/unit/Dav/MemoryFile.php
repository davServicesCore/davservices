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
use DavServices\Exception\Forbidden;
use DavServices\Exception\IHttpFailure;

/**
 * A file that lives in a string, for the tests of this layer.
 */
final class MemoryFile implements IFile, IMember
{
    /** Whether the last write arrived as a stream, which R-TREE-03 asks for. */
    public bool $wasWrittenFromAStream = false;

    /** Set where being deleted is to be refused. */
    private ?IHttpFailure $deletionRefusal = null;

    private ?MemoryCollection $parent = null;

    public function __construct(
        private readonly string $name,
        private string $content = '',
    ) {
    }

    public function attachTo(MemoryCollection $parent): void
    {
        $this->parent = $parent;
    }

    /**
     * Refuses to go away — a locked file, or one the backend holds on to.
     */
    public function refuseDeletion(?IHttpFailure $refusal = null): self
    {
        $this->deletionRefusal = $refusal ?? new Forbidden(sprintf('"%s" is not to be deleted.', $this->name));

        return $this;
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
        if ($this->deletionRefusal !== null) {
            throw $this->deletionRefusal;
        }

        $this->content = '';
        $this->parent?->remove($this->name);
    }

    public function get(): string
    {
        return $this->content;
    }

    public function put(mixed $content): string
    {
        $this->wasWrittenFromAStream = !is_string($content);
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
