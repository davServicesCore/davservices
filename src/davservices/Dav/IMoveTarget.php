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

/**
 * A collection that can take a node over from somewhere else itself.
 *
 * R-TREE-06. A `MOVE` inside one backend is usually a single operation there —
 * a rename, an `UPDATE` — while the server's own way is to copy every node and
 * then delete every node. Where a backend can do it, it should be asked.
 */
interface IMoveTarget
{
    /**
     * Moves the node into this collection.
     *
     * @param string $name The member name it is to have here
     * @param string $sourcePath The path it is coming from, so that a backend
     *                           can tell whether it is one of its own
     * @param INode $source The node itself
     *
     * @return bool True where the move was made. False is not a failure: it
     *              says the backend would rather the server did it node by
     *              node, and the server then does
     */
    public function moveInto(string $name, string $sourcePath, INode $source): bool;
}
