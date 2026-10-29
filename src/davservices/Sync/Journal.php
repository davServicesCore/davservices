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

use Closure;
use DateInterval;
use DateTimeImmutable;
use DavServices\Dav\Event\AfterBind;
use DavServices\Dav\Event\AfterMove;
use DavServices\Dav\Event\AfterUnbind;
use DavServices\Dav\Event\AfterWriteContent;
use DavServices\Event\EventEmitter;
use DavServices\Exception\Forbidden;
use DavServices\Uri\Path;

/**
 * What has happened in a collection, and what a client is owed because of it
 * (RFC 6578 §3.5, R-SYNC-03, R-SYNC-05).
 *
 * **This is what makes a token worth handing back.** Without it a server
 * answers the full listing and refuses every token, which §3.2 permits for one
 * that "might be unable to maintain all of the change data for a collection"
 * — and §3.1 then has the client synchronise the whole collection every time.
 *
 *     $journal = new Journal($yourStore, new DateInterval('P3W'));
 *
 *     $sync = new Sync($server, $journal);
 *
 * It answers {@see IRevisions} as well, because the state a token stands for
 * and the history behind it are one record: a server with a journal has no
 * second place to ask how far a collection has got.
 *
 * ## The rule §3.5 states twice: the last thing wins
 *
 * §3.5.1, on a member that came back:
 *
 * > In the case where a mapping between a member URL and the target collection
 * > was removed, then a new mapping with the same URI was created, the member
 * > URL MUST be reported as changed and MUST NOT be reported as removed.
 *
 * §3.5.2, on a member that left again:
 *
 * > If a member was added (and its mapped resource possibly modified), then
 * > removed between two synchronization report requests, it MUST be reported
 * > as removed. This ensures that a client that adds a member is informed of
 * > the removal of the member, if the removal occurs before the client has had
 * > a chance to execute a synchronization report.
 *
 * **Those are one rule seen from two sides**, and keeping only the latest entry
 * per member satisfies both. It also satisfies §3.2's "A given member URL MUST
 * appear only once in the response", which a journal that answered every entry
 * would break.
 *
 * ## And the one rule that is not about a member
 *
 * > §3.5.2: For a report with the DAV:sync-level XML element value set to
 * > "infinite", where a collection is removed, the server MUST NOT report the
 * > removal of any members of the removed collection. Clients MUST assume that
 * > if a collection is reported as being removed, then all members of that
 * > collection have also been removed.
 *
 * So a removed collection silences what is below it — **and not only the
 * removals**. A member that changed before its collection went is gone too, and
 * reporting it as changed would send a client to fetch what is not there. A
 * collection made again stops silencing, because then the last thing that
 * happened to it is a change like any other.
 *
 * ## Why it forgets
 *
 * R-SYNC-05 asks for a configurable period and a cleanup, and §3.2 says what a
 * server gains: it "might only be able to maintain up to 3 weeks worth of
 * changes to a collection, or only up to 10,000 total changes". What is
 * forgotten cannot be answered from, and §3.2's `DAV:valid-sync-token` is what
 * says so (R-SYNC-03) — the same section asking that servers "MUST limit
 * themselves to invalidating tokens only when absolutely necessary", which is
 * why the period is the deployment's to choose rather than a number here.
 *
 * **The state it has just handed out is always answerable**, however far the
 * cleanup has gone: a client that hands back the token it was just given is
 * owed an empty answer and not a refusal.
 */
final class Journal implements IRevisions
{
    /**
     * What `DAV:sync-level: infinite` comes to: deeper than any tree.
     *
     * The same figure {@see \DavServices\Dav\ReportDepth::ENDLESS} hands out,
     * named here as well so that a caller of this class need not reach into a
     * report's vocabulary to ask for everything.
     */
    public const EVERY_DEPTH = PHP_INT_MAX;

    private const VALID_SYNC_TOKEN = '{DAV:}valid-sync-token';

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $now;

    /**
     * @param IJournalBackend $entries Where the history is kept
     * @param DateInterval $keepFor How long an entry is worth keeping
     *                              (R-SYNC-05). §3.2 offers three weeks as an
     *                              example and nothing more, so a deployment
     *                              that keeps a day and one that keeps a year
     *                              are both conformant — and the longer it
     *                              keeps, the rarer a client's fall back to a
     *                              full synchronisation
     * @param (callable(): DateTimeImmutable)|null $now The clock, handed in so
     *                                                  that a test can say what
     *                                                  time it is and so that
     *                                                  one request reads it
     *                                                  once (R-ARC-05)
     */
    public function __construct(
        private readonly IJournalBackend $entries,
        private readonly DateInterval $keepFor = new DateInterval('P3W'),
        ?callable $now = null,
    ) {
        $this->now = $now === null
            ? static fn (): DateTimeImmutable => new DateTimeImmutable()
            : $now(...);
    }

    /**
     * Listens for everything §3.2 asks a server to keep track of.
     *
     * > To implement the behavior for this report, a server needs to keep
     * > track of changes to any member URLs and their mapped resources in a
     * > collection (as defined in Section 3 of [RFC4918]). This includes
     * > noting the addition of new member URLs, the changes to the mapped
     * > resources of existing member URLs, and the removal of member URLs.
     *
     * **Four listeners answer that sentence, and none of them names a
     * method.** A member arrives however it arrives — a `PUT`, a `MKCOL`, a
     * `MKCALENDAR`, the destination of a `COPY` or a `MOVE`, the lock-null
     * resource of a `LOCK` — and all of them come through
     * {@see AfterBind}. §3.5.1 asks for exactly that breadth: "this includes
     * member URLs that have been newly mapped as the result of a COPY, MOVE,
     * BIND [RFC5842], or REBIND [RFC5842] request."
     *
     * - {@see AfterBind}: newly mapped, which §3.5.1 reports as changed.
     * - {@see AfterWriteContent}: "A member URL MUST be reported as changed if
     *   its mapped resource's entity tag value […] has changed."
     * - {@see AfterUnbind}: unmapped, which §3.5.2 reports as removed. A
     *   `DELETE` of a collection raises one for every member as well, deepest
     *   first, and {@see changesSince()} is what silences those below it.
     * - {@see AfterMove}: the path it came *from* is unmapped. §3.5.2: "this
     *   includes members that have been unmapped as the result of a MOVE".
     *   Where it went is {@see AfterBind}'s to record, so a move is one
     *   removal and one change and needs no listener that knows both halves.
     *
     * **An overwriting `MOVE` or `COPY` is a change and not a removal**, and
     * that falls out rather than being arranged: what was at the destination
     * is removed through the node itself, which raises nothing, and then
     * {@see AfterBind} records the new mapping. §3.5.1 asks for precisely that
     * answer — "In the case where a mapping between a member URL and the
     * target collection was removed, then a new mapping with the same URI was
     * created, the member URL MUST be reported as changed and MUST NOT be
     * reported as removed."
     */
    public function keepTrack(EventEmitter $events): void
    {
        $events->on(AfterBind::class, function (AfterBind $event): void {
            $this->recordAChange($event->path());
        });

        $events->on(AfterWriteContent::class, function (AfterWriteContent $event): void {
            $this->recordAChange($event->path());
        });

        $events->on(AfterUnbind::class, function (AfterUnbind $event): void {
            $this->recordARemoval($event->path());
        });

        $events->on(AfterMove::class, function (AfterMove $event): void {
            $this->recordARemoval($event->from());
        });
    }

    /**
     * Writes down that a member is new, or that its entity tag moved (§3.5.1).
     *
     * @param string $member The member's path inside the tree
     */
    public function recordAChange(string $member): void
    {
        $this->entries->append($member, Change::Changed, ($this->now)());
    }

    /**
     * Writes down that a member's mapping is gone (§3.5.2).
     *
     * @param string $member The member's path inside the tree
     */
    public function recordARemoval(string $member): void
    {
        $this->entries->append($member, Change::Removed, ($this->now)());
    }

    /**
     * Which change the collection is at.
     *
     * **The highest revision of anything below it**, at any depth, because
     * that is what §4 asks the property to carry: "the value of the
     * synchronization token as it would be returned by a DAV:sync-collection
     * report on that resource at the same point in time". A collection nothing
     * has happened in keeps the state it had, which is what makes §5's
     * conditional writes worth anything — a token that moved when another
     * collection changed would refuse a write for no reason.
     *
     * **Never below what has been forgotten.** The floor is the earliest state
     * this journal can say anything after, so a collection whose own last
     * change has been cleaned away is at the floor and not at zero — a
     * revision below it would be a token the journal would refuse the moment
     * it was handed back, which is the one answer §4 cannot mean.
     *
     * Zero where nothing has ever happened and nothing has been forgotten,
     * and **never null, which the signature says rather than the prose**: a
     * journal tracks every collection it is asked about, and `null` in
     * {@see IRevisions} is for a server that tracks none.
     */
    public function revisionOf(string $collection): int
    {
        $at = $this->entries->cleanedUpTo();

        foreach ($this->entries->since($at, $collection) as [$revision, $member]) {
            if ($revision > $at && self::liesUnder($member, $collection)) {
                $at = $revision;
            }
        }

        return $at;
    }

    /**
     * What has become of the members of that collection since that revision.
     *
     * @param int $revision The state the client holds
     * @param string $collection The request-URI's path
     * @param int $depth How far the `DAV:sync-level` reaches: 1 for the
     *                   immediate children, {@see self::EVERY_DEPTH} for all
     *
     * @throws Forbidden If the revision is older than what is still kept
     *                   (§3.2's `DAV:valid-sync-token`, R-SYNC-03)
     *
     * @return array<string, Change> What became of each member, by path, in the
     *                               order the changes happened
     */
    public function changesSince(int $revision, string $collection, int $depth): array
    {
        $this->mustStillKnowWhatFollowed($revision);

        $found = [];

        foreach ($this->entries->since($revision, $collection) as [, $member, $change]) {
            if (!self::liesWithin($member, $collection, $depth)) {
                continue;
            }

            // **The last thing that happened to a member is what is
            // reported**, which is §3.5's rule from both its sides at once.
            // Unsetting first is what keeps the order the order of the
            // changes: a member that moved again belongs where it moved last.
            unset($found[$member]);

            $found[$member] = $change;
        }

        return self::withoutWhatARemovedCollectionTookWithIt($found);
    }

    /**
     * Forgets what is older than the period (R-SYNC-05).
     *
     * Running it twice at one moment takes nothing more: it is a statement
     * about time rather than a counter.
     */
    public function clean(): void
    {
        $this->entries->forget(($this->now)()->sub($this->keepFor));
    }

    /**
     * §3.2's precondition: "The DAV:sync-token element value MUST be a valid
     * token previously returned by the server for the collection targeted by
     * the request-URI."
     *
     * **Two sentences rather than one**, because a token can fail that in two
     * directions and a client is owed the difference. One from before what is
     * kept was handed out and has gone out of date, which is §3.2's own first
     * reason — a server that "might be unable to maintain all of the change
     * data for a collection". One from after the newest entry was never handed
     * out at all, and answering it would mean taking a client's word for a
     * state this server has never been in.
     *
     * **The newest state is always answerable**, even where the cleanup has
     * passed it: a client handing back the token it was just given asked about
     * nothing, and the honest answer to nothing is an empty one. That holds
     * because the floor is a revision this journal handed out, so it is never
     * above the latest.
     *
     * @throws Forbidden If the revision is one this journal cannot report from
     */
    private function mustStillKnowWhatFollowed(int $revision): void
    {
        if ($revision > $this->entries->latest()) {
            throw new Forbidden(
                sprintf(
                    'This server has never been at change %d, so no token it handed out stands for it: the DAV:valid-sync-token precondition of RFC 6578 §3.2.',
                    $revision,
                ),
                self::VALID_SYNC_TOKEN,
            );
        }

        if ($revision < $this->entries->cleanedUpTo()) {
            throw new Forbidden(
                sprintf(
                    'This server no longer keeps what happened after change %d, so it cannot report from it: the DAV:valid-sync-token precondition of RFC 6578 §3.2.',
                    $revision,
                ),
                self::VALID_SYNC_TOKEN,
            );
        }
    }

    /**
     * §3.5.2's last rule: a removed collection silences what is below it.
     *
     * Applied to the answer rather than to the history, because the history is
     * where both halves are still visible — a member that changed and then had
     * its collection removed has two entries, and only the collection's
     * removal is the client's business.
     *
     * @param array<string, Change> $found
     *
     * @return array<string, Change>
     */
    private static function withoutWhatARemovedCollectionTookWithIt(array $found): array
    {
        $gone = [];

        foreach ($found as $member => $change) {
            if ($change === Change::Removed) {
                $gone[] = $member;
            }
        }

        if ($gone === []) {
            return $found;
        }

        $kept = [];

        foreach ($found as $member => $change) {
            foreach ($gone as $collection) {
                if ($member !== $collection && self::liesUnder($member, $collection)) {
                    continue 2;
                }
            }

            $kept[$member] = $change;
        }

        return $kept;
    }

    /**
     * Whether this member is one the collection owes an answer for, at that
     * depth.
     *
     * **The collection is not one of its own members** (§3.2 asks for "one
     * DAV:response element for each member URL"), so it is never in its own
     * answer — a client told its own collection had changed under it would
     * have nowhere to put that.
     */
    private static function liesWithin(string $member, string $collection, int $depth): bool
    {
        if ($member === $collection || !self::liesUnder($member, $collection)) {
            return false;
        }

        if ($depth === self::EVERY_DEPTH) {
            return true;
        }

        $inside = substr($member, strlen($collection) + 1);

        return !str_contains($inside, '/');
    }

    /**
     * Whether this path lies at or below that collection.
     *
     * **A path that merely begins the same way is a different path**, which is
     * why this is a question about segments and not about prefixes:
     * `calendars/work2` does not lie inside `calendars/work`, and a journal
     * that thought otherwise would hand one client another's changes.
     */
    private static function liesUnder(string $path, string $collection): bool
    {
        return Path::isBelow($path, $collection);
    }
}
