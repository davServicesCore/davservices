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

namespace DavServices\Acl;

use DateTimeImmutable;
use DavServices\Backend\IPrincipalBackend;
use DavServices\Dav\Acl\PrincipalInfo;
use DavServices\Dav\ICollection;
use DavServices\Dav\INode;
use DavServices\Exception\Forbidden;
use DavServices\Exception\NotFound;

/**
 * Where the principals hang (RFC 3744 §5.8).
 *
 * A collection like any other as far as the protocol is concerned, which is
 * the point: a client walks it with `PROPFIND` and has to learn nothing new
 * to do so. Mounted wherever an application likes —
 *
 *     $root->add(new PrincipalCollection('principals', $backend));
 *
 * — because `DAV:principal-collection-set` tells clients where to look, and
 * nothing in this library assumes a path.
 *
 * **Nothing is created or removed through it.** Who exists is the
 * application's business: a directory, an identity provider, a table somebody
 * else owns. A `PUT` that appeared to make a person would make nothing at
 * all, and a client told `201` would go on believing in somebody who is not
 * there.
 *
 * **A listing may be refused**, and a deployment with a hundred thousand
 * users will refuse it. That decision belongs to the backend — the only thing
 * that knows how many there are — and is passed on rather than softened: an
 * empty listing where the storage said no would tell a client there are no
 * users at all.
 */
final class PrincipalCollection implements ICollection
{
    public function __construct(
        private readonly string $name,
        private readonly IPrincipalBackend $backend,
    ) {
    }

    /**
     * The name this collection is mounted under.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Nothing: a collection of people changes when the people do, and no
     * backend is asked to keep a time for that.
     */
    public function lastModified(): ?DateTimeImmutable
    {
        return null;
    }

    /**
     * Refuses. Removing the collection would remove everybody in it, and who
     * exists is not WebDAV's to decide.
     *
     * @throws Forbidden Always
     */
    public function delete(): void
    {
        throw new Forbidden('The principal collection is not removed through WebDAV.');
    }

    /**
     * Every principal the backend will name.
     *
     * @throws Forbidden If the backend will not be listed
     *
     * @return list<INode>
     */
    public function children(): array
    {
        return array_map(
            static fn (PrincipalInfo $principal): Principal => new Principal($principal),
            $this->backend->principals(),
        );
    }

    /**
     * One principal by name.
     *
     * @throws NotFound If no principal of that name is known
     */
    public function child(string $name): INode
    {
        $principal = $this->backend->principal($name);

        if ($principal === null) {
            throw new NotFound(sprintf('No principal "%s" is known here.', $name));
        }

        return new Principal($principal);
    }

    /**
     * Whether there is a principal of that name.
     */
    public function hasChild(string $name): bool
    {
        return $this->backend->principal($name) !== null;
    }

    /**
     * Refuses: a principal is not made by writing a file. A client told `201`
     * would go on believing in somebody who is not there.
     *
     * @throws Forbidden Always
     */
    public function createFile(string $name, mixed $content = null): ?string
    {
        throw new Forbidden('A principal is not made by writing a file.');
    }

    /**
     * Refuses, for the same reason: whoever exists comes from the backend.
     *
     * @throws Forbidden Always
     */
    public function createCollection(string $name): void
    {
        throw new Forbidden('A principal is not made by making a collection.');
    }
}
