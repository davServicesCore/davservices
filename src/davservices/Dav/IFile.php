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

use DavServices\Exception\Forbidden;

/**
 * A node that holds content.
 *
 * Content moves through this interface as a stream wherever it can. A file of
 * any size then costs the same, which is what R-TREE-02 and R-TREE-03 are
 * after: reading a recording into a string to hand it over would spend as much
 * memory as the recording is long.
 */
interface IFile extends INode
{
    /**
     * The content, as a stream wherever the backend can manage one.
     *
     * A string is allowed for what is small and already in memory. The caller
     * must cope with both, and the server streams whichever it is given.
     *
     *
     * @throws Forbidden If the content may not be read
     *
     * @return resource|string
     */
    public function get(): mixed;

    /**
     * Replaces the content.
     *
     * @param resource|string $content A stream is not to be read into memory
     *
     * @throws Forbidden If the content may not be written
     *
     * @return string|null The new entity tag, quoted as it will go on the
     *                     wire, or null where the backend cannot say — the
     *                     server then asks for it again
     */
    public function put(mixed $content): ?string;

    /**
     * The media type, or null where the backend does not know one.
     */
    public function contentType(): ?string;

    /**
     * The length in bytes, or null where it cannot be given without reading
     * the whole of it.
     */
    public function contentLength(): ?int;

    /**
     * The entity tag, quoted as it goes on the wire (`"abc"` or `W/"abc"`), or
     * null where the backend has none.
     *
     * R-HTTP-08 asks for strong tags: a weak one has to say so with `W/`.
     */
    public function etag(): ?string;
}
