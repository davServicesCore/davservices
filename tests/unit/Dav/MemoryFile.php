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
use DavServices\Dav\IProperties;
use DavServices\Exception\Forbidden;
use DavServices\Exception\IHttpFailure;
use DavServices\Xml\Element;

/**
 * A file that lives in a string, for the tests of this layer.
 */
final class MemoryFile implements IFile, IMember, IProperties
{
    /**
     * The names of the last `properties()` call, so that a test can show the
     * node was not asked for what somebody had answered already.
     *
     * @var list<string>
     */
    public array $askedFor = [];

    /** Whether the last write arrived as a stream, which R-TREE-03 asks for. */
    public bool $wasWrittenFromAStream = false;

    /**
     * How often the entity tag has been worked out, so that a test can show
     * nothing was computed for a property nobody asked for.
     */
    public int $entityTagsGiven = 0;

    /** @var array<string, Element|string|null> */
    private array $properties = [];

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

    /**
     * Gives the file a property of its own, as a backend keeps a dead one.
     */
    public function withProperty(string $name, Element|string|null $value): self
    {
        $this->properties[$name] = $value;

        return $this;
    }

    public function propertyNames(): array
    {
        return array_keys($this->properties);
    }

    public function properties(array $names): array
    {
        $this->askedFor = $names;
        $found = [];

        foreach ($names as $name) {
            if (array_key_exists($name, $this->properties)) {
                $found[$name] = $this->properties[$name];
            }
        }

        return $found;
    }

    public function patchProperties(array $mutations): array
    {
        $statuses = [];

        foreach ($mutations as $name => $value) {
            if ($value === null) {
                unset($this->properties[$name]);
            } else {
                $this->properties[$name] = $value;
            }

            $statuses[$name] = 200;
        }

        return $statuses;
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
        $this->entityTagsGiven++;

        return '"' . md5($this->content) . '"';
    }
}
