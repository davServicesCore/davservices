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

namespace DavServices\Backend\File;

use DateTimeImmutable;
use DavServices\Dav\ICollection;
use DavServices\Dav\ICopyTarget;
use DavServices\Dav\IMoveTarget;
use DavServices\Dav\INode;
use DavServices\Exception\Conflict;
use DavServices\Exception\Forbidden;
use DavServices\Exception\NotFound;
use FilesystemIterator;
use RuntimeException;

/**
 * A directory on a disc, served as a collection.
 *
 * The reference backend of R-BE-05, and the shortest way to a working server:
 *
 *     $server = new Server(new Tree(new Directory('/var/lib/davservices')));
 *
 * It is here to be read, copied and measured against, **not to be run in
 * production**. Nothing here knows about two requests writing to one path at
 * the same moment, about quotas, or about what a filesystem does when it runs
 * out of inodes.
 *
 * The member names are the only thing that comes in from outside, and there
 * are **two** rules about them, which are not the same rule.
 *
 * **What could reach outside this directory is refused when it is read.** A
 * separator, a dot name, a zero byte. `Uri\Path` refuses all of those before a
 * request gets this far; they are refused again here because a plugin may ask
 * a collection for a member directly, and then this is the last door.
 *
 * **What no filesystem everywhere would take is refused when it is created.**
 * `CON` cannot be a file on Windows, and a name ending in a space or a dot is
 * silently renamed there. Refusing them everywhere keeps a tree made on one
 * system usable on another, and a silent rename is a worse answer than a
 * refusal.
 */
final class Directory implements ICollection, ICopyTarget, IMoveTarget
{
    /** What Windows keeps for itself, whatever extension is put after it. */
    private const RESERVED = '/^(?:CON|PRN|AUX|NUL|COM\d|LPT\d)$/i';

    /** What a name may not hold if it is to be stored wherever this runs. */
    private const AWKWARD = ':*?"<>|';

    /**
     * @param string $location Where the directory is on the disc
     * @param string $name What it is called inside its collection; the root of
     *                     a tree is called nothing at all
     */
    public function __construct(
        private readonly string $location,
        private readonly string $name = '',
    ) {
    }

    /**
     * The name it has inside its collection. The root of a tree is called
     * nothing at all, because nothing holds it.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Where this directory is on the disc.
     *
     * Private, unlike the one on {@see File}: only another directory of this
     * backend ever asks, and it may, being of the same class.
     */
    private function location(): string
    {
        return $this->location;
    }

    /**
     * The time the filesystem keeps for the directory, which says when a
     * member was last added or removed rather than when one last changed.
     */
    public function lastModified(): ?DateTimeImmutable
    {
        $changed = @filemtime($this->location);

        return $changed === false ? null : new DateTimeImmutable('@' . $changed);
    }

    /**
     * Removes the directory and everything in it, as `INode::delete()` asks of
     * a collection. A `DELETE` empties it member by member instead, so that
     * one member which will not go stops the whole of it; this is what a
     * `COPY` over an existing collection uses.
     */
    public function delete(): void
    {
        self::removeEverythingIn($this->location);
    }

    /**
     * Everything in the directory, each as a file or a collection of its own.
     *
     * @return list<INode>
     */
    public function children(): array
    {
        $members = [];

        foreach (new FilesystemIterator($this->location) as $entry) {
            $name = basename((string) $entry);

            $members[] = self::nodeAt($this->location . '/' . $name, $name);
        }

        return $members;
    }

    /**
     * @throws NotFound If there is no such member, or the name could reach
     *                  outside this directory
     */
    public function child(string $name): INode
    {
        if (!$this->hasChild($name)) {
            throw new NotFound(sprintf('There is no "%s" here.', $name));
        }

        return self::nodeAt($this->location . '/' . $name, $name);
    }

    /**
     * Whether a member of that name is there — and no, where the name is one
     * that could reach outside this directory at all.
     */
    public function hasChild(string $name): bool
    {
        return self::staysInside($name) && file_exists($this->location . '/' . $name);
    }

    /**
     * Writes a new file, and says what it is tagged as, so that the `PUT`
     * which made it need not ask again.
     *
     * @param resource|string|null $content
     *
     * @throws Forbidden If the name would not survive everywhere
     * @throws Conflict If something of that name is there already
     */
    public function createFile(string $name, mixed $content = null): ?string
    {
        $file = new File($this->free($name), $name);

        $file->put($content ?? '');

        return $file->etag();
    }

    /**
     * Makes a directory for a new collection.
     *
     * @throws Forbidden If the name would not survive everywhere
     * @throws Conflict If something of that name is there already
     */
    public function createCollection(string $name): void
    {
        $made = $this->free($name);

        // @codeCoverageIgnoreStart
        // A disc that is full, a directory that is no longer writable. No test
        // can bring a filesystem into that state on every platform this runs
        // on, and a collection that was never made must not be reported as one.
        if (!mkdir($made)) {
            throw new RuntimeException(sprintf('"%s" could not be made.', $made));
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * R-TREE-06: a rename, where the server's own way is to copy every node
     * and then delete every node.
     */
    public function moveInto(string $name, string $sourcePath, INode $source): bool
    {
        $from = self::locationOf($source);

        if ($from === null || !self::isPortable($name)) {
            return false;
        }

        return rename($from, $this->location . '/' . $name);
    }

    /**
     * A file is copied here; a collection is handed back.
     *
     * Copying a tree means asking every node on the way whether it may be
     * copied at all, and the server does that with every listener in place. A
     * backend that quietly copied a whole subtree would be deciding that for
     * all of them.
     */
    public function copyInto(string $name, string $sourcePath, INode $source): bool
    {
        $from = $source instanceof File ? $source->location() : null;

        if ($from === null || !self::isPortable($name)) {
            return false;
        }

        return copy($from, $this->location . '/' . $name);
    }

    /**
     * Where a member of that name would go, once it is known that nothing is
     * there and that the name can be stored.
     *
     * @throws Forbidden If the name would not survive everywhere
     * @throws Conflict If something of that name is there already
     */
    private function free(string $name): string
    {
        if (!self::isPortable($name)) {
            throw new Forbidden(sprintf('"%s" is not a name this server stores.', $name));
        }

        $location = $this->location . '/' . $name;

        if (file_exists($location)) {
            throw new Conflict(sprintf('"%s" is already there.', $name));
        }

        return $location;
    }

    /**
     * Whether a name could reach outside this directory.
     */
    private static function staysInside(string $name): bool
    {
        if ($name === '' || $name === '.' || $name === '..') {
            return false;
        }

        return strpbrk($name, "/\\\0") === false;
    }

    /**
     * Whether a name is one every filesystem this may run on would take.
     */
    private static function isPortable(string $name): bool
    {
        if (!self::staysInside($name) || strpbrk($name, self::AWKWARD) !== false) {
            return false;
        }

        // Windows drops a trailing dot or space without a word, which turns
        // one name into another behind the client's back.
        if (str_ends_with($name, '.') || str_ends_with($name, ' ')) {
            return false;
        }

        return preg_match(self::RESERVED, pathinfo($name, PATHINFO_FILENAME)) !== 1;
    }

    private static function nodeAt(string $location, string $name): INode
    {
        return is_dir($location) ? new self($location, $name) : new File($location, $name);
    }

    /**
     * Where a node of this backend is on the disc, or null for one that is
     * not this backend's at all.
     */
    private static function locationOf(INode $node): ?string
    {
        if ($node instanceof File) {
            return $node->location();
        }

        return $node instanceof self ? $node->location() : null;
    }

    private static function removeEverythingIn(string $directory): void
    {
        foreach (new FilesystemIterator($directory) as $entry) {
            $location = (string) $entry;

            is_dir($location) ? self::removeEverythingIn($location) : unlink($location);
        }

        if (!rmdir($directory)) {
            throw new RuntimeException(sprintf('"%s" could not be removed.', $directory)); // @codeCoverageIgnore
        }
    }
}
