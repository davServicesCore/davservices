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

namespace DavServices\Dav;

use DavServices\Exception\NotFound;
use DavServices\Uri\MalformedPath;
use DavServices\Uri\Path;

/**
 * Turns a path into the node it names.
 *
 * The tree walks down one member at a time from the root, which is what keeps
 * a backend from ever having to build more of itself than a request asks for.
 *
 * Every node it passes is kept for the length of the request (R-TREE-05). That
 * is not a nicety: a `PROPFIND` with `Depth: 1` on a collection of two hundred
 * members asks for every one of them, and each plugin that has something to
 * say about a node asks for it again. Without the cache a backend sees every
 * one of those, and what should be one query becomes hundreds.
 *
 * What is cached has to be forgotten at the right moment. A node handed out
 * after it was deleted is worse than one that was never cached, so anything
 * that changes the tree tells it — `forget()`.
 */
final class Tree
{
    /**
     * Nodes already found, by their normalised path.
     *
     * @var array<string, INode>
     */
    private array $found = [];

    public function __construct(private readonly ICollection $root)
    {
    }

    /**
     * The collection the tree hangs from.
     */
    public function root(): ICollection
    {
        return $this->root;
    }

    /**
     * The node a path names.
     *
     * @param string $path As it arrived; it is normalised here (R-TREE-04)
     *
     * @throws NotFound If nothing of that path is in the tree
     * @throws MalformedPath If the path cannot be resolved safely
     */
    public function node(string $path): INode
    {
        $path = Path::normalise($path);

        if (isset($this->found[$path])) {
            return $this->found[$path];
        }

        if ($path === '') {
            return $this->found[''] = $this->root;
        }

        [$parentPath, $name] = Path::split($path);
        $parent = $this->node($parentPath);

        if (!$parent instanceof ICollection) {
            // A file has no members, so the path goes nowhere. Saying which
            // kind of node stood in the way would tell a client something
            // about a tree it has no business knowing.
            throw new NotFound(sprintf('There is nothing at "%s".', $path));
        }

        return $this->found[$path] = $parent->child($name);
    }

    /**
     * Does the path lead anywhere?
     *
     * A path that cannot be resolved leads nowhere, and says so rather than
     * refusing: this is a question, not an operation.
     */
    public function exists(string $path): bool
    {
        try {
            $this->node($path);
        } catch (NotFound | MalformedPath) {
            return false;
        }

        return true;
    }

    /**
     * Drops a path and everything below it, so that the next request for it
     * goes to the backend again.
     *
     * @throws MalformedPath If the path cannot be resolved safely
     */
    public function forget(string $path): void
    {
        $path = Path::normalise($path);

        if ($path === '') {
            $this->found = [];

            return;
        }

        unset($this->found[$path]);

        foreach (array_keys($this->found) as $cached) {
            // The slash matters: `alice2` is not below `alice`, and a prefix
            // comparison without it would forget the wrong node.
            if (str_starts_with($cached, $path . '/')) {
                unset($this->found[$cached]);
            }
        }
    }
}
