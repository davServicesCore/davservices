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
 * A collection that can copy a node in from somewhere else itself.
 *
 * R-TREE-06, and the same bargain as `IMoveTarget`: a backend that can copy a
 * whole subtree in one operation is asked first, and the server falls back to
 * walking it node by node.
 */
interface ICopyTarget
{
    /**
     * Copies the node into this collection.
     *
     * @param string $name The member name the copy is to have here
     * @param string $sourcePath The path it is coming from
     * @param INode $source The node itself
     *
     * @return bool True where the copy was made; false to let the server walk it
     */
    public function copyInto(string $name, string $sourcePath, INode $source): bool;
}
