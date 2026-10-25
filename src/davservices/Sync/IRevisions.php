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

namespace DavServices\Sync;

/**
 * Which change a collection is at (RFC 6578 §3.2, R-SYNC-02).
 *
 * **A synchronisation token has to say which state it stands for**, and
 * nothing a node offers says it. `INode::lastModified()` is null for every
 * store that keeps no such thing, and a collection's own entity tag is no
 * answer either — §3.5.1 says so outright: "There is no guarantee that changes
 * to members of a collection will result in a change in any entity tag of that
 * collection."
 *
 * So it is asked across a seam, and this is the seam. §3.2 offers the shape it
 * answers in: "a simple implementation of such a token could be a numeric
 * counter that counts each change as it occurs".
 *
 * **A seam here is an interface and not a callable**, which is how every other
 * seam in this library is cut — {@see \DavServices\Backend\ICalendarBackend},
 * {@see \DavServices\Acl\IPrivilegeResolver},
 * {@see \DavServices\Backend\ILockBackend}. A closure would have been shorter
 * to write and worse to live with: an implementation that needs state of its
 * own, or that wants to be swapped under test, has somewhere to be.
 *
 * `Sync\Journal` (R-SYNC-03, R-SYNC-05) is what will answer this from a
 * recorded history; until then an application answers it from whatever its
 * store already counts.
 */
interface IRevisions
{
    /**
     * Which change this collection is at, or null where none is tracked.
     *
     * **Null is not an error and not zero.** A collection this server keeps no
     * synchronisation state for is one it cannot hand a token for at all: it
     * gets no `DAV:sync-token` property, names no state token for an `If`
     * header, and the report refuses it with RFC 3253 §3.6's own
     * `DAV:supported-report`. Answering zero instead would promise a state
     * that never moves, and §5's conditional writes would then pass for ever.
     *
     * **The number only has to grow.** R-SYNC-02 asks for tokens that are
     * monotonically increasing, and {@see Token} compares the number it is
     * given; what the steps are, and whether any are skipped, is the
     * implementation's business.
     *
     * @param string $collection The collection's path inside the tree
     */
    public function revisionOf(string $collection): ?int;
}
