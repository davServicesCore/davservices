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

use DavServices\Exception\Conflict;
use DavServices\Exception\Forbidden;
use DavServices\Exception\IHttpFailure;
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
     * The collection a new member would go into.
     *
     * RFC 4918 §9.3.1 and §9.7.1 say the same thing for `MKCOL` and for `PUT`,
     * and `COPY` and `MOVE` say it about their destination: the ancestors have
     * to be there already, or the method fails with a `409`. Creating them
     * quietly is how one typo becomes a tree of empty collections.
     *
     * @throws Conflict If nothing is there, or what is there is no collection
     * @throws MalformedPath If the path cannot be resolved safely
     */
    public function collectionAt(string $path): ICollection
    {
        try {
            $parent = $this->node($path);
        } catch (NotFound $missing) {
            throw new Conflict('The collection this would go in does not exist.', null, $missing);
        }

        if (!$parent instanceof ICollection) {
            throw new Conflict('A file cannot hold another node.');
        }

        return $parent;
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
     * Copies a node to another path, with or without what lies below it.
     *
     * **A backend that can do it itself is asked first** (R-TREE-06). A
     * `COPY` inside one backend is usually a single operation there — a
     * `INSERT ... SELECT`, a directory copied by the operating system — while
     * the server's own way is to create every node one at a time. On a
     * calendar of a million objects that is the difference between a moment
     * and an hour.
     *
     * A member that will not copy does not stop the ones after it: RFC 4918
     * §9.8.5 has every one of them named in the answer, and the paths named
     * are the ones the client knows — the members of the source it asked to
     * have copied.
     *
     * @param bool $withMembers False copies a collection without what is in
     *                          it, which is what `Depth: 0` asks for
     *
     * @throws NotFound If there is nothing at the source path
     * @throws Conflict If the collection the copy would go in is not there
     * @throws MalformedPath If either path cannot be resolved safely
     *
     * @return array<string, IHttpFailure> What would not copy, by source path
     */
    public function copy(string $from, string $to, bool $withMembers = true): array
    {
        $source = $this->node($from);
        [$parentPath, $name] = Path::split($to);
        $parent = $this->collectionAt($parentPath);

        $failures = $this->copyNode($parent, $name, $from, $source, $withMembers);

        // Forgetting the collection the copy went into drops the copy with it.
        $this->forget($parentPath);

        return $failures;
    }

    /**
     * Moves a node to another path.
     *
     * The backend is asked first here too, and where it hands the work back
     * the server copies and then removes — in that order, because a removal
     * that happened before a copy that failed would have destroyed what the
     * client was moving.
     *
     * @throws NotFound If there is nothing at the source path
     * @throws Conflict If the collection it would go in is not there
     * @throws Forbidden If the source will not go away
     * @throws MalformedPath If either path cannot be resolved safely
     *
     * @return array<string, IHttpFailure> What would not move, by source path
     */
    public function move(string $from, string $to): array
    {
        $source = $this->node($from);
        [$parentPath, $name] = Path::split($to);
        $parent = $this->collectionAt($parentPath);

        if ($parent instanceof IMoveTarget && $parent->moveInto($name, $from, $source)) {
            $this->forgetBoth($from, $parentPath);

            return [];
        }

        $failures = $this->copyNode($parent, $name, $from, $source, true);

        if ($failures === []) {
            $source->delete();
        }

        $this->forgetBoth($from, $parentPath);

        return $failures;
    }

    /**
     * Copies one node into a collection, and everything below it where it is
     * asked for.
     *
     * @return array<string, IHttpFailure> What would not copy, by source path
     */
    private function copyNode(
        ICollection $parent,
        string $name,
        string $from,
        INode $source,
        bool $withMembers,
    ): array {
        try {
            if ($parent instanceof ICopyTarget && $parent->copyInto($name, $from, $source)) {
                return [];
            }

            if ($source instanceof IFile) {
                $parent->createFile($name, $source->get());

                return [];
            }

            if (!$source instanceof ICollection) {
                // A node that is neither a file nor a collection is something
                // this library has no way of making a second one of.
                throw new Forbidden(sprintf('There is no copying "%s".', $from));
            }

            $parent->createCollection($name);
        } catch (IHttpFailure $refusal) {
            return [$from => $refusal];
        }

        return $withMembers ? $this->copyMembers($parent, $name, $from, $source) : [];
    }

    /**
     * @return array<string, IHttpFailure>
     */
    private function copyMembers(ICollection $parent, string $name, string $from, ICollection $source): array
    {
        try {
            $copy = $parent->child($name);
            $members = $source->children();
        } catch (IHttpFailure $refusal) {
            return [$from => $refusal];
        }

        if (!$copy instanceof ICollection) {
            // The backend took a collection and made something else of it.
            return [$from => new Forbidden(sprintf('"%s" was not made a collection.', $name))];
        }

        $failures = [];

        foreach ($members as $member) {
            $failures += $this->copyNode($copy, $member->name(), Path::join($from, $member->name()), $member, true);
        }

        return $failures;
    }

    private function forgetBoth(string $from, string $parentPath): void
    {
        $this->forget($from);
        $this->forget($parentPath);
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
