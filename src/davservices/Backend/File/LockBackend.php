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
use DavServices\Backend\ILockBackend;
use DavServices\Dav\Locks\LockInfo;
use DavServices\Dav\Locks\LockScope;
use DavServices\Uri\Path;
use DavServices\Xml\Element;
use DavServices\Xml\Reader;
use DavServices\Xml\Writer;
use FilesystemIterator;
use RuntimeException;

/**
 * Write locks in a directory, one file per lock (R-LOCK-05).
 *
 * The simple storage, and the one a small deployment can run: nothing to set
 * up but a directory the web server may write to. It is the reference backend
 * of R-BE-05 and not built for a busy server — but unlike an in-memory one it
 * is a lock store at all, because **a lock has to outlive the process that
 * took it.** Two requests to one server are two processes as often as not.
 *
 * The file is named after a hash of the token and holds the path inside, as
 * the property storage beside it does and for the same reasons: a name every
 * filesystem takes, at the price of reading the directory to answer a question
 * about a path.
 *
 * **A store that cannot be read is not an empty store.** Where the properties
 * skip a line somebody edited wrongly, this refuses the request: a property
 * that goes missing is an inconvenience, and a lock that goes missing lets a
 * write through that somebody was told could not happen.
 */
final class LockBackend implements ILockBackend
{
    /** The namespace of the file, which is this library's own business. */
    private const LOCK = '{https://dav.services/locks}';

    private readonly Writer $writer;

    private readonly Reader $reader;

    /**
     * @param string $directory Where the files go; it has to exist, because
     *                          creating directories on a guess is how a typo
     *                          ends with a lock store in a web root
     *
     * @throws RuntimeException If the directory is not there
     */
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory)) {
            throw new RuntimeException(sprintf('"%s" is no directory.', $directory));
        }

        $this->writer = new Writer([trim(self::LOCK, '{}') => 'l']);
        $this->reader = new Reader();
    }

    /**
     * Reads the directory: a file named after a hash says nothing about the
     * path it holds, so every one of them is asked.
     *
     * @return list<LockInfo>
     */
    public function locksOn(string $path, DateTimeImmutable $now): array
    {
        return $this->matching(static fn (LockInfo $lock): bool => $lock->covers($path), $now);
    }

    /**
     * The same walk, asking a different question: which locks were taken
     * **inside** this path. A collection cannot be locked whole while somebody
     * holds a piece of it.
     *
     * @return list<LockInfo>
     */
    public function locksBelow(string $path, DateTimeImmutable $now): array
    {
        return $this->matching(
            static fn (LockInfo $lock): bool => $lock->root() !== $path && Path::isBelow($lock->root(), $path),
            $now,
        );
    }

    /**
     * One file per lock, written beside its place and moved in, so that a
     * write cut short leaves the lock as it was rather than half of it.
     *
     * Half a file would be worse here than in the property storage beside it:
     * a lock file that cannot be read makes **every** later request throw, by
     * the rule of this class. The temporary name is built from the final one
     * so that both lie in the same directory — a rename is only atomic within
     * one filesystem, and a temporary file elsewhere would be a copy.
     */
    public function set(LockInfo $lock): void
    {
        $file = $this->fileFor($lock->token());
        $temporary = $file . '.writing';

        // @codeCoverageIgnoreStart
        // A disc that is full, a directory that is no longer writable. No test
        // can bring a filesystem into that state on every platform this runs
        // on, and a lock that was never written must not be reported as taken.
        if (file_put_contents($temporary, $this->writer->write(self::document($lock))) === false
            || !rename($temporary, $file)) {
            throw new RuntimeException(sprintf('"%s" could not be written.', $file));
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Removes the one file that lock is in. One that is not there is no error:
     * something else has done what the client asked for.
     */
    public function remove(LockInfo $lock): void
    {
        $this->forget($this->fileFor($lock->token()));
    }

    /**
     * What matches and has not run out — and the ones that have are removed
     * while we are here, as R-LOCK-03 asks.
     *
     * @param callable(LockInfo): bool $matches
     *
     * @return list<LockInfo>
     */
    private function matching(callable $matches, DateTimeImmutable $now): array
    {
        $found = [];

        foreach (new FilesystemIterator($this->directory) as $entry) {
            $file = (string) $entry;

            // Whatever else is in the directory belongs to somebody else.
            if (str_ends_with($file, '.xml')) {
                $lock = $this->read($file);

                if ($lock->hasExpired($now)) {
                    $this->forget($file);
                } elseif ($matches($lock)) {
                    $found[] = $lock;
                }
            }
        }

        return $found;
    }

    /**
     * @throws RuntimeException If the file cannot be read as a lock
     */
    private function read(string $file): LockInfo
    {
        $document = file_get_contents($file);

        // @codeCoverageIgnoreStart
        // The file was listed a moment ago, so a false here is a disc that has
        // gone away under the request.
        if ($document === false) {
            throw new RuntimeException(sprintf('"%s" could not be read.', $file));
        }
        // @codeCoverageIgnoreEnd

        $lock = $this->reader->parse($document);
        $root = $lock->attribute('root');
        $token = $lock->attribute('token');

        if ($root === null || $token === null) {
            throw new RuntimeException(sprintf('"%s" is no lock this server wrote.', $file));
        }

        $until = $lock->attribute('expires');

        return new LockInfo(
            $root,
            $token,
            $lock->attribute('scope') === 'shared' ? LockScope::Shared : LockScope::Exclusive,
            $lock->attribute('deep') === 'yes',
            self::ownerIn($lock),
            $until === null ? null : new DateTimeImmutable($until),
        );
    }

    /**
     * What a lock looks like on a disc: one element a person can read, with
     * the owner as the XML the client sent.
     */
    private static function document(LockInfo $lock): Element
    {
        $expires = $lock->expiresAt();
        $attributes = [
            'root' => $lock->root(),
            'token' => $lock->token(),
            'scope' => $lock->scope() === LockScope::Shared ? 'shared' : 'exclusive',
            'deep' => $lock->isDeep() ? 'yes' : 'no',
        ];

        if ($expires !== null) {
            $attributes['expires'] = $expires->format(DateTimeImmutable::ATOM);
        }

        $document = new Element(self::LOCK . 'lock', $attributes);
        $owner = $lock->owner();

        if ($owner !== null) {
            $held = new Element(self::LOCK . 'owner');

            $owner instanceof Element ? $held->append($owner) : $held->appendText($owner);
            $document->append($held);
        }

        return $document;
    }

    /**
     * The owner as it was written: the XML a client sent, or its text, or
     * nothing where it sent nothing.
     *
     * The owner is the only child this ever writes, so the first child is it.
     */
    private static function ownerIn(Element $lock): Element|string|null
    {
        $held = $lock->children()[0] ?? null;

        return $held === null ? null : ($held->children()[0] ?? $held->text());
    }

    /**
     * A name every filesystem takes, whatever a token is made of.
     */
    private function fileFor(string $token): string
    {
        return sprintf('%s/%s.xml', $this->directory, hash('sha256', $token));
    }

    private function forget(string $file): void
    {
        if (!is_file($file)) {
            return;
        }

        // @codeCoverageIgnoreStart
        // Worse to ignore than a failed write: an `UNLOCK` that reported
        // success while the lock stayed would hold the path for ever.
        if (!unlink($file)) {
            throw new RuntimeException(sprintf('"%s" could not be removed.', $file));
        }
        // @codeCoverageIgnoreEnd
    }
}
