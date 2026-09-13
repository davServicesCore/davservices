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
use DavServices\Exception\NotFound;

/**
 * A node that holds other nodes.
 *
 * Only the members of the collection itself, never what lies below them: the
 * tree walks down one name at a time, so that a backend never has to build
 * more of it than a request asks for.
 */
interface ICollection extends INode
{
    /**
     * Every member of this collection.
     *
     *
     * @throws Forbidden If the collection may not be listed
     *
     * @return list<INode>
     */
    public function children(): array;

    /**
     * One member by name.
     *
     * @throws NotFound If nothing of that name is in this collection
     */
    public function child(string $name): INode;

    /**
     * Is there a member of that name?
     *
     * Asked far more often than `child()` — a `PUT` needs to know whether it
     * is creating or replacing — so a backend may answer it more cheaply.
     */
    public function hasChild(string $name): bool;

    /**
     * Creates a file in this collection.
     *
     * @param resource|string|null $content Null creates an empty file
     *
     * @throws Forbidden If the collection will not take a new file
     * @throws Conflict If a member of that name is already there
     *
     * @return string|null The entity tag of what was created, or null
     */
    public function createFile(string $name, mixed $content = null): ?string;

    /**
     * Creates a plain collection inside this one.
     *
     * @throws Forbidden If the collection will not take another
     * @throws Conflict If a member of that name is already there
     */
    public function createCollection(string $name): void;
}
