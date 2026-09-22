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

    /** @var list<string> */
    private readonly array $memberOf;

    /** @var list<string>|null */
    private readonly ?array $members;

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
     * @param list<string> $memberOf The groups this principal is **directly**
     *                               in (RFC 3744 §4.4), by their member names.
     *                               Directly, because §4.4 says so and tells a
     *                               client to query those groups in turn for
     *                               the rest — a backend answering the whole
     *                               chain here would be lying to a client
     *                               doing what it was told
     * @param list<string>|null $members The principals **directly** in this
     *                                   group (§4.3), or null where this
     *                                   server does not say. Null and the
     *                                   empty list are different answers: §4.3
     *                                   is the one property of §4 that does
     *                                   not have to be supported at all, and a
     *                                   directory that will not hand out
     *                                   rosters should not thereby claim every
     *                                   group is empty
     */
    public function __construct(
        private readonly string $name,
        private readonly ?string $displayName = null,
        array $alternateUris = [],
        array $memberOf = [],
        ?array $members = null,
    ) {
        $this->alternateUris = $alternateUris;
        $this->memberOf = $memberOf;
        $this->members = $members;
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

    /**
     * The groups this principal is **directly** in (RFC 3744 §4.4).
     *
     * Directly, and that is the specification's word: "identifies the groups
     * in which the principal is **directly** a member … the
     * DAV:group-membership of those other groups would need to be queried in
     * order to determine the groups in which the principal is indirectly a
     * member".
     *
     * **Matching, though, is recursive** (§2): somebody in a group that is in
     * another group is in both as far as access control is concerned. That
     * happens where privileges are worked out, not here — this is what the
     * storage holds, and it is one edge of a graph.
     *
     * @return list<string>
     */
    public function memberOf(): array
    {
        return $this->memberOf;
    }

    /**
     * The principals **directly** in this group (§4.3), or null where this
     * server does not say.
     *
     * **Null is not the empty list.** §4.3 is the only property of RFC 3744
     * §4 without the sentence "Support for this property is REQUIRED", so a
     * directory that will not hand out group rosters is within its rights —
     * and saying `[]` instead would tell every client that every group it
     * declines to describe is empty.
     *
     * @return list<string>|null
     */
    public function members(): ?array
    {
        return $this->members;
    }
}
