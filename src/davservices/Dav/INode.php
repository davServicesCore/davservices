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

use DateTimeImmutable;
use DavServices\Exception\Forbidden;

/**
 * Anything that can be addressed by a path.
 *
 * The tree of a DAV server is made of these. A node knows its own name and
 * nothing about where it hangs: the path is the tree's business, so that the
 * same node can appear in more than one place without knowing it.
 */
interface INode
{
    /**
     * The name this node is known by inside its parent collection.
     *
     * Decoded, without slashes — the member name, not the path.
     */
    public function name(): string;

    /**
     * When the node last changed, or null where that is not known.
     *
     * A backend that cannot say returns null rather than guessing: an invented
     * time would be handed out as `DAV:getlastmodified` and cached by clients.
     */
    public function lastModified(): ?DateTimeImmutable;

    /**
     * Removes the node.
     *
     * A collection removes everything below it.
     *
     * @throws Forbidden If the backend will not have it removed
     */
    public function delete(): void;
}
