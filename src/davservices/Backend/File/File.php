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
use DavServices\Dav\IFile;
use RuntimeException;

/**
 * One file on a disc.
 *
 * The reference backend of R-BE-05: it is here to be read, copied and measured
 * against, **not to be run in production**. It knows nothing of locking
 * between processes, of quotas, or of what happens when two requests write to
 * one path at the same moment.
 *
 * Two things about it are worth more than they look, because every backend
 * written after this one will copy them.
 *
 * **Nothing is read into a string.** A file goes out as the handle it is
 * (R-TREE-02) and a write goes in the same way (R-TREE-03). A backend that
 * read a recording into memory to hand it over would be deciding, on behalf of
 * every server built on it, how large a file may be.
 *
 * **The entity tag is made without reading the file.** Size and time of change
 * are what a filesystem answers at no cost; hashing the content would mean
 * reading every byte of every file in a collection to answer one `PROPFIND`.
 * The tag is weak because that is the truth about it: two changes within the
 * same second that leave the length alone look the same from outside.
 */
final class File implements IFile
{
    /**
     * What a name says about what is in the file.
     *
     * A small table rather than `finfo`, which is an extension: a library that
     * needs one has decided something for everybody who uses it. What is not
     * in here is `application/octet-stream`, which is the honest answer to
     * "this server does not know".
     */
    private const TYPES = [
        'ics' => 'text/calendar',
        'ifb' => 'text/calendar',
        'vcf' => 'text/vcard',
        'txt' => 'text/plain',
        'html' => 'text/html',
        'css' => 'text/css',
        'js' => 'text/javascript',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'svg' => 'image/svg+xml',
        'zip' => 'application/zip',
    ];

    /**
     * @param string $location Where the file is on the disc
     * @param string $name What it is called inside its collection
     */
    public function __construct(
        private readonly string $location,
        private readonly string $name,
    ) {
    }

    /**
     * The name it has inside its collection, which is not the same thing as
     * where it lies on the disc: here the two agree, and a backend that stored
     * files under an identifier would keep them apart.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Where this file is on the disc, for a collection that can copy or move
     * it in one operation rather than through the server.
     */
    public function location(): string
    {
        return $this->location;
    }

    /**
     * The time the filesystem keeps, or nothing where the file has gone away
     * under the request.
     */
    public function lastModified(): ?DateTimeImmutable
    {
        $stat = $this->stat();

        return $stat === null ? null : new DateTimeImmutable('@' . $stat['mtime']);
    }

    /**
     * Removes the file. One that is already gone is no error: something else
     * has done what the client asked for.
     */
    public function delete(): void
    {
        if (is_file($this->location) && !unlink($this->location)) {
            throw new RuntimeException(sprintf('"%s" could not be removed.', $this->location)); // @codeCoverageIgnore
        }
    }

    /**
     * The open file, for the server to send from (R-TREE-02).
     *
     * A file that has gone away under the request is refused rather than
     * answered with nothing: an empty answer is what the client stores as the
     * truth about it.
     *
     * @throws RuntimeException If the file cannot be opened
     *
     * @return resource
     */
    public function get(): mixed
    {
        $stream = @fopen($this->location, 'rb');

        // Not an empty answer: a file that has gone away under the request is
        // a `500` the application can look into, where an empty one is stored
        // by the client as the truth.
        if ($stream === false) {
            throw new RuntimeException(sprintf('"%s" could not be opened.', $this->location));
        }

        return $stream;
    }

    /**
     * Writes what the client sent — a handle at a time where it sent a handle
     * (R-TREE-03), so that a file larger than this process's memory still
     * arrives whole.
     *
     * @param resource|string $content
     *
     * @throws RuntimeException If the file cannot be written
     */
    public function put(mixed $content): ?string
    {
        // @codeCoverageIgnoreStart
        // A disc that is full, a file somebody made read-only underneath. No
        // test can bring a filesystem into that state on every platform this
        // runs on, and a write that quietly failed would lose what a client
        // sent.
        $target = @fopen($this->location, 'wb');

        if ($target === false) {
            throw new RuntimeException(sprintf('"%s" could not be written.', $this->location));
        }
        // @codeCoverageIgnoreEnd

        if (is_string($content)) {
            fwrite($target, $content);
        } else {
            // Copied from one handle to the other, so that a file larger than
            // the memory this process has still arrives whole.
            stream_copy_to_stream($content, $target);
        }

        fclose($target);

        return $this->etag();
    }

    /**
     * What the name says is in the file. A filesystem keeps no type of its
     * own, so there is nothing else to go on — and there is always an answer,
     * because `application/octet-stream` is what "this server does not know"
     * is called.
     */
    public function contentType(): string
    {
        $extension = strtolower(pathinfo($this->name, PATHINFO_EXTENSION));

        return self::TYPES[$extension] ?? 'application/octet-stream';
    }

    /**
     * How long the file is, asked of the filesystem each time: another request
     * may have written to it since.
     */
    public function contentLength(): ?int
    {
        $stat = $this->stat();

        return $stat === null ? null : $stat['size'];
    }

    /**
     * Size and time of change, and nothing read.
     *
     * Weak, and marked as such (RFC 9110 §8.8.3): two changes within the same
     * second that leave the length alone look the same from here, and a client
     * that was told it was strong would use it to piece together a byte range
     * from two different files.
     */
    public function etag(): ?string
    {
        $stat = $this->stat();

        return $stat === null ? null : sprintf('W/"%x-%x"', $stat['mtime'], $stat['size']);
    }

    /**
     * What the filesystem says about the file now, or nothing where it is gone.
     *
     * Asked afresh every time, because **PHP keeps what it was told about a
     * path and a write through this very object does not change its mind**: a
     * file written a moment ago is reported with the length it had before,
     * which a client then stores as the truth. One call answers both questions
     * at once, so the two halves of an entity tag can never disagree either.
     *
     * @return array{mtime: int, size: int}|null
     */
    private function stat(): ?array
    {
        clearstatcache(true, $this->location);

        $stat = @stat($this->location);

        return $stat === false ? null : ['mtime' => $stat['mtime'], 'size' => $stat['size']];
    }
}
