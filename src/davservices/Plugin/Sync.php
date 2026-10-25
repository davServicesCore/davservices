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

namespace DavServices\Plugin;

use DavServices\Dav\Event\PropertiesChanging;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\Event\StateTokensRequested;
use DavServices\Dav\ICollection;
use DavServices\Dav\Method\Report;
use DavServices\Dav\Server;
use DavServices\Sync\IRevisions;
use DavServices\Sync\Report\SyncCollection;
use DavServices\Sync\Token;

/**
 * Collection synchronisation (RFC 6578, R-SYNC-01, R-SYNC-02).
 *
 * **This is how a client with a thousand events asks what changed.** Without
 * it, keeping in step means listing the whole collection and comparing entity
 * tags every time; with it, the client hands back a token and is told only
 * what moved.
 *
 *     $sync = new Sync($server, $yourRevisions);
 *
 *     $sync->register($report);
 *
 * It owes three things, and each is a MUST of that memo:
 *
 * - **the report** (§3.2), and the same section has it listed in
 *   `DAV:supported-report-set` "on any collection that supports
 *   synchronization" — which {@see Report} answers from what is registered, so
 *   registering it is what makes the claim;
 * - **`DAV:sync-token` as a property** (§4): "MUST be defined on all resources
 *   that support the DAV:sync-collection report. It contains the value of the
 *   synchronization token as it would be returned by a DAV:sync-collection
 *   report on that resource at the same point in time";
 * - **the token in an `If` header** (§5): "Servers MUST support use of
 *   DAV:sync-token values in If request headers."
 *
 * ## Why the third one matters most to a client
 *
 * §5 is how a client writes into a collection only if nothing has changed
 * since it last looked:
 *
 * > By using this, clients can ensure requests only complete when there have
 * > been no changes to the content of a collection, by virtue of an unchanged
 * > DAV:sync-token value.
 *
 * It costs this plugin one listener. {@see StateTokensRequested} is where
 * {@see \DavServices\Dav\Precondition\RequestConditions} asks what tokens a
 * path is in the state of, so a sync token joins the lock tokens there and
 * nothing in the core had to learn what synchronisation is (R-ARC-02).
 *
 * ## Where the revision comes from, and why it is not optional
 *
 * A token has to say which state it stands for, and nothing a node offers says
 * it. `INode::lastModified()` is null for every store that keeps no such
 * thing; a collection's own entity tag is no answer either, and §3.5.1 says why
 * in as many words — "There is no guarantee that changes to members of a
 * collection will result in a change in any entity tag of that collection".
 *
 * So the revision is asked across a seam, {@see IRevisions}, and the seam is
 * **required rather than defaulted**: a plugin whose default answered something
 * would look wired while handing out tokens it could not keep honest, and §5's
 * conditional writes rest on a token that moves when the collection does. A
 * collection it answers `null` for is one this server does not synchronise: it
 * gets no property, names no state token, and the report refuses it with
 * RFC 3253 §3.6's own `DAV:supported-report`.
 *
 * ## It announces no compliance class
 *
 * RFC 6578 defines none — there is no `DAV` header value anywhere in it. What
 * tells a client the report is there is `DAV:supported-report-set`, which §3.2
 * names in as many words, and announcing something of this library's own
 * invention would be telling a client about a word no memo gives a meaning.
 *
 * ## What is not here yet
 *
 * **Nothing is reported as changed or removed**, because nothing records
 * changes. Every token a client hands back is refused with §3.2's
 * `DAV:valid-sync-token`, which is that section's own first reason for
 * invalidating one — "Servers might be unable to maintain all of the change
 * data for a collection due to storage or performance reasons" — and §3.1 has
 * the client fall back to a full synchronisation, which it does correctly here.
 * `Sync\Journal` (R-SYNC-03, R-SYNC-05) is what makes a token answerable, and
 * truncation (R-SYNC-04) is what makes a `DAV:limit` honourable.
 */
final class Sync
{
    /**
     * §4's property, which §3.2's report answers the same value for.
     */
    public const TOKEN = '{DAV:}sync-token';

    private const SYNC_COLLECTION = '{DAV:}sync-collection';

    private const CANNOT_MODIFY_PROTECTED = '{DAV:}cannot-modify-protected-property';

    /**
     * @param IRevisions $revisions Which change a collection is at, or null
     *                              where this server keeps no synchronisation
     *                              state for it
     */
    public function __construct(
        private readonly Server $server,
        private readonly IRevisions $revisions,
    ) {
    }

    /**
     * Switches synchronisation on: the report, the property, and the token's
     * use in an `If` header.
     *
     * @param Report $report The `REPORT` method, which this plugin owes a
     *                       `DAV:sync-collection` (§3.2). It is asked for
     *                       rather than optional because `DAV:sync-token`
     *                       promises a report: §4 defines the property on the
     *                       resources that support one, so a server answering
     *                       the property without the report would be handing
     *                       out a token nothing would take back
     */
    public function register(Report $report): void
    {
        // **The three go together, and that is why they are private.** §4
        // defines `DAV:sync-token` on "all resources that support the
        // DAV:sync-collection report", so a server answering the property
        // without the report would hand out a token nothing would take back —
        // and §5's conditional writes rest on the same token. `register()`
        // asking for the `Report` is what makes wiring them apart impossible.

        $events = $this->server->events();

        $events->on(PropertiesRequested::class, $this->describe(...));
        $events->on(PropertiesChanging::class, $this->guardChangingProperties(...));
        $events->on(StateTokensRequested::class, $this->nameTheState(...));

        $report->on(self::SYNC_COLLECTION, (new SyncCollection($this->server, $this->revisions))(...));
    }

    /**
     * Answers `DAV:sync-token` where the resource has one (§4).
     *
     * **Only to a client that names it.** §4: "It SHOULD NOT be returned by a
     * PROPFIND DAV:allprop request" — a token is of no use to a client that
     * did not ask, and it would otherwise be in every listing of every
     * collection for ever. {@see \DavServices\Dav\PropFindResult::wasNamed()}
     * is where that sentence lives for all the properties that carry it.
     */
    private function describe(PropertiesRequested $event): void
    {
        $result = $event->result();

        if (!$result->wasNamed(self::TOKEN)) {
            return;
        }

        // §3.2 has the report about a collection and §4 defines the property
        // on the resources that support the report, so a file has none. A file
        // answering one would be a client told it could synchronise something
        // it cannot.
        if (!$event->node() instanceof ICollection) {
            return;
        }

        $revision = $this->revisions->revisionOf($result->path());

        if ($revision === null) {
            return;
        }

        $result->set(self::TOKEN, (string) Token::for($result->path(), $revision));
    }

    /**
     * §4: "Protected: MUST be protected because this value is created and
     * controlled by the server."
     *
     * A protected property is one a `PROPPATCH` cannot change, and RFC 4918
     * §16's `DAV:cannot-modify-protected-property` is what says so by name —
     * where a bare `403` would leave a client guessing whether it had asked
     * wrongly or been refused.
     */
    private function guardChangingProperties(PropertiesChanging $event): void
    {
        $result = $event->result();

        foreach (array_keys($result->mutations()) as $name) {
            if ($name === self::TOKEN) {
                $result->set($name, 403, self::CANNOT_MODIFY_PROTECTED);
            }
        }
    }

    /**
     * Names the collection's token among the states it is in (§5).
     *
     * **This is the whole of §5's MUST.** A client puts the token it last saw
     * in an `If` header, and a write goes through only while the collection is
     * still in that state — so the token has to be offered where every other
     * state token is, which is {@see StateTokensRequested}.
     *
     * A path this server keeps no synchronisation state for names nothing, and
     * a condition about it is then false. That is the fail-closed answer
     * {@see \DavServices\Dav\Precondition\RequestConditions} gives throughout:
     * a condition this server cannot check is not one it may call true.
     */
    private function nameTheState(StateTokensRequested $event): void
    {
        $revision = $this->revisions->revisionOf($event->path());

        if ($revision === null) {
            return;
        }

        $event->add((string) Token::for($event->path(), $revision));
    }
}
