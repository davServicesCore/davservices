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

use DavServices\Dav\ICopyTarget;
use DavServices\Dav\IFile;
use DavServices\Dav\IMoveTarget;
use DavServices\Dav\INode;

/**
 * A collection that can take a node over itself (R-TREE-06).
 *
 * The bargain of `ICopyTarget` and `IMoveTarget`: a backend that can rename a
 * row or a directory in one operation says so, and the server does not walk
 * the tree node by node. Saying no is not a failure — it hands the work back —
 * so this double can do either on demand.
 */
final class MemoryTransferCollection extends MemoryCollection implements ICopyTarget, IMoveTarget
{
    /** @var list<string> The names it was asked to copy in */
    public array $copiedIn = [];

    /** @var list<string> The names it was asked to move in */
    public array $movedIn = [];

    /** Whether it does the work itself or hands it back to the server. */
    private bool $doesItItself = true;

    /**
     * Hands the work back, as a backend does that cannot see the source.
     */
    public function handsItBack(): self
    {
        $this->doesItItself = false;

        return $this;
    }

    public function copyInto(string $name, string $sourcePath, INode $source): bool
    {
        $this->copiedIn[] = $name;

        if (!$this->doesItItself) {
            return false;
        }

        $this->add(self::likeness($name, $source));

        return true;
    }

    public function moveInto(string $name, string $sourcePath, INode $source): bool
    {
        $this->movedIn[] = $name;

        if (!$this->doesItItself) {
            return false;
        }

        $this->add(self::likeness($name, $source));
        $source->delete();

        return true;
    }

    /**
     * What the backend would have made of it, near enough for a test.
     */
    private static function likeness(string $name, INode $source): IMember
    {
        if (!$source instanceof IFile) {
            return new MemoryCollection($name);
        }

        $content = $source->get();

        return new MemoryFile($name, is_string($content) ? $content : (string) stream_get_contents($content));
    }
}
