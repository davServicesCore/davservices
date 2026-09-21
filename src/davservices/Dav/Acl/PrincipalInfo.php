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

namespace DavServices\Dav\Acl;

/**
 * One principal, as a backend hands it over (RFC 3744 §2).
 *
 * A principal is "a distinct human or computational actor that initiates
 * access to network resources" — a person, a group, a service. Everything
 * access control says is said about one of these, so this is the smallest
 * thing the rest of it can be built on.
 *
 * **It has no URL of its own here, only a name.** Where a principal hangs in
 * the tree is the server's business, the same way a node knows its name and
 * nothing about its path: the same backend must be mountable at
 * `/principals/` or `/dav/users/` without knowing it. `DAV:principal-URL` is
 * worked out where the path is known.
 *
 * Like {@see \DavServices\Dav\Locks\LockInfo} this lives below the backends
 * rather than beside the nodes, because a backend interface may not reach up
 * into the layer that uses it.
 */
final class PrincipalInfo
{
    /** @var list<string> */
    private readonly array $alternateUris;

    /**
     * @param string $name The member name inside the principal collection,
     *                     which is what a path is built from
     * @param string|null $displayName What a person is called, where the
     *                                 backend knows; null rather than the
     *                                 name itself, because a name invented
     *                                 from a login is shown to people as
     *                                 though somebody had chosen it
     * @param list<string> $alternateUris Other ways to reach the same actor —
     *                                    `mailto:` above all (RFC 3744 §4.1)
     */
    public function __construct(
        private readonly string $name,
        private readonly ?string $displayName = null,
        array $alternateUris = [],
    ) {
        $this->alternateUris = $alternateUris;
    }

    /**
     * The member name inside the principal collection.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * What a person is called, or null where nobody has said.
     */
    public function displayName(): ?string
    {
        return $this->displayName;
    }

    /**
     * The other URIs this actor answers to (RFC 3744 §4.1).
     *
     * @return list<string>
     */
    public function alternateUris(): array
    {
        return $this->alternateUris;
    }
}
