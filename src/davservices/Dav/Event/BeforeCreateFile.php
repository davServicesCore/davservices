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

namespace DavServices\Dav\Event;

use DavServices\Event\Event;

/**
 * Raised before a file that is not there yet is created.
 *
 * This is the seam a protocol extension checks through: CalDAV refuses an
 * object it cannot parse here, and a plugin that normalises what it stores
 * changes it here rather than afterwards. A listener that will not have the
 * write throws, and the refusal becomes the answer.
 *
 * The content may be a stream. A listener that means to look at it has to put
 * back something the write can use — which is what `writeInstead()` is for,
 * and why reading the stream without replacing it is a way to store an empty
 * file.
 */
final class BeforeCreateFile extends Event
{
    /**
     * @param resource|string $content
     */
    public function __construct(
        private readonly string $path,
        private mixed $content,
    ) {
    }

    /**
     * The path the content is being written to.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * What is about to be written.
     *
     * @return resource|string
     */
    public function content(): mixed
    {
        return $this->content;
    }

    /**
     * Writes this instead of what the client sent.
     *
     * There is no way to say "nothing" here: a body is always at least an
     * empty stream, and a listener that means to store nothing stores that.
     *
     * @param resource|string $content
     */
    public function writeInstead(mixed $content): void
    {
        $this->content = $content;
    }
}
