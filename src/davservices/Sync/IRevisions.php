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

use DavServices\Event\EventEmitter;
use DavServices\Exception\Forbidden;

/**
 * Which change a collection is at, and what has happened since (RFC 6578 §3.2,
 * R-SYNC-02, R-SYNC-03).
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
 * {@see Journal} answers all three from a recorded history (R-SYNC-03,
 * R-SYNC-05), and an application whose own store already counts its changes
 * answers them from that instead.
 *
 * ## Why it is one seam and not three
 *
 * §4, of the `DAV:sync-token` property: "It contains the value of the
 * synchronization token as it would be returned by a DAV:sync-collection
 * report on that resource at the same point in time."
 *
 * **So the state a token stands for and the history behind it are one
 * record.** Two collaborators — one asked where a collection is, another asked
 * what happened since — could be wired to disagree, and a server whose
 * property and whose report answered two different states would be handing out
 * tokens that mean nothing. One interface makes that impossible rather than
 * unlikely.
 *
 * {@see keepTrack()} belongs here for the same reason read the other way: a
 * history nothing writes to is a server that hands out tokens and reports no
 * changes, which is the one failure a client cannot detect. The seam that
 * answers what happened is the seam that hears it happen.
 *
 * ## And a server that keeps no history is still conformant
 *
 * §3.2 says so itself, among its reasons for invalidating a token: "Servers
 * might be unable to maintain all of the change data for a collection due to
 * storage or performance reasons." Such a server answers `null` from
 * {@see changesSince()}, hears nothing in {@see keepTrack()}, and refuses
 * every token with `DAV:valid-sync-token` — and §3.1 then has the client
 * synchronise the whole collection each time, which is slower and not wrong.
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

    /**
     * What has become of the members of that collection since that revision,
     * or null where no history is kept.
     *
     * **Null is "ask me nothing" and not "nothing happened".** An empty array
     * is a collection in step, which is the commonest answer there is; null is
     * the server §3.2 describes as "unable to maintain all of the change data
     * for a collection", and the report then refuses the token with
     * `DAV:valid-sync-token` rather than claiming the client is up to date.
     *
     * **The last thing that happened to a member is what is answered**, which
     * is §3.5's rule from both of its sides: §3.5.1 has a member that was
     * removed and mapped again reported "as changed and MUST NOT be reported
     * as removed", §3.5.2 has one that was added and then removed reported as
     * removed. Keeping only the latest entry per member satisfies both, and
     * §3.2's "A given member URL MUST appear only once in the response" with
     * them.
     *
     * **And a removed collection silences what is below it**, §3.5.2: "where a
     * collection is removed, the server MUST NOT report the removal of any
     * members of the removed collection."
     *
     * @param int $revision The state the client holds
     * @param string $collection The collection's path inside the tree
     * @param int $depth §3.3's level: 1 for the immediate children,
     *                   {@see Journal::EVERY_DEPTH} for every depth. The
     *                   collection itself is never one of its own members
     *
     * @throws Forbidden If the revision is one this server can no longer
     *                   report from, or never handed out: §3.2's
     *                   `DAV:valid-sync-token`
     *
     * @return array<string, Change>|null What became of each member, by path,
     *                                    in the order it happened
     */
    public function changesSince(int $revision, string $collection, int $depth): ?array;

    /**
     * Hears what the server does, so that there is a history to answer from.
     *
     * §3.2: "To implement the behavior for this report, a server needs to keep
     * track of changes to any member URLs and their mapped resources in a
     * collection […] This includes noting the addition of new member URLs, the
     * changes to the mapped resources of existing member URLs, and the removal
     * of member URLs."
     *
     * **{@see \DavServices\Plugin\Sync} calls this and a deployment does not**,
     * which is the point of its being here: a journal wired in but never
     * listening would answer every token with "nothing has changed", and a
     * client would believe it.
     *
     * An implementation whose own store already records its changes — the
     * ordinary case for an application with a database — hears nothing here
     * and answers {@see changesSince()} from what it has.
     *
     * @param EventEmitter $events The server's events, where
     *                             {@see \DavServices\Dav\Event\AfterBind} and
     *                             its fellows say what has happened
     */
    public function keepTrack(EventEmitter $events): void;
}
