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

namespace DavServices\Dav\Locks;

use DavServices\Http\ETag;
use DavServices\Http\IfCondition;

/**
 * What a client could have known about one resource (RFC 4918 §10.4).
 *
 * The two things an `If` header asks about: which lock tokens are held on it,
 * and what its entity tag is. One condition is held against this, and the
 * answer is the whole of what the condition is worth.
 *
 * **A tag is compared weakly, and that is not a shortcut.** RFC 9110 §8.8.3.3
 * names a comparison for each HTTP header; WebDAV's `If` is in no such table,
 * and the question it asks decides it. `If` asks "is this resource still in
 * the state I saw", and a weak tag answers that question at exactly the
 * granularity the server has. A byte range asks something a weak tag cannot
 * answer — whether two responses may be spliced — which is why the file
 * backend marks its own tags weak, and why comparing them strongly here would
 * mean **no** conditional write could ever succeed against this server.
 *
 * **A resource with no entity tag satisfies no condition about one.** Not
 * every backend has a tag for every node, and a comparison against nothing is
 * one that failed rather than one that passed.
 */
final class ResourceState
{
    /**
     * @param list<string> $tokens The lock tokens held on the resource, of
     *                             which there may be several: a shared lock
     *                             is one several clients hold at once
     * @param ETag|null $etag Its entity tag, where the backend has one
     */
    public function __construct(
        private readonly array $tokens,
        private readonly ?ETag $etag,
    ) {
    }

    /**
     * Does this condition hold of the resource?
     *
     * `Not` is read here rather than by the caller, because it belongs to the
     * answer and not to the walk: `Not <token>` on a resource that does not
     * hold that token is **true**, and a client sends it to say "only if
     * nobody else has taken this".
     */
    public function satisfies(IfCondition $condition): bool
    {
        $etag = $condition->etag();
        $matches = $etag === null
            ? in_array($condition->stateToken(), $this->tokens, true)
            : $this->isTagged($etag);

        return $condition->isNegated() !== $matches;
    }

    private function isTagged(ETag $etag): bool
    {
        return $this->etag !== null && $this->etag->matchesWeakly($etag);
    }
}
