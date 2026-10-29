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

use DavServices\CalDav\Calendar;
use DavServices\Dav\Event\PropertiesChanging;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\Event\StateTokensRequested;
use DavServices\Dav\ICollection;
use DavServices\Dav\IResourceType;
use DavServices\Dav\Method\Report;
use DavServices\Dav\Server;
use DavServices\Sync\IRevisions;
use DavServices\Sync\Report\SyncCollection;
use DavServices\Sync\Token;

/**
 * Collection synchronisation (RFC 6578, R-SYNC-01, R-SYNC-02, R-SYNC-06).
 *
 * **This is how a client with a thousand events asks what changed.** Without
 * it, keeping in step means listing the whole collection and comparing entity
 * tags every time; with it, the client hands back a token and is told only
 * what moved.
 *
 *     $sync = new Sync($server, $yourRevisions);
 *     $sync = new Sync($server, $yourRevisions, atMost: 500);   // §3.6
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
 * And a fourth thing that memo does not ask for: **`CS:getctag` on a calendar
 * collection** (R-SYNC-06), which is the question §3.2's report answers, under
 * the name clients asked it by before the report existed. There is no RFC for
 * it — {@see nameTheTag()} says where its wording comes from, and why the one
 * number answers both names.
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
 * ## Where the history comes from
 *
 * §3.2's other duty is the one a client feels: "a server needs to keep track of
 * changes to any member URLs and their mapped resources in a collection". That
 * is the same seam's fourth answer — {@see IRevisions::keepTrack()} — and
 * {@see register()} calls it, rather than leaving it to a deployment to
 * remember. A history nothing writes to has no symptom: every token would be
 * answered with "nothing has changed", and a client would believe it.
 *
 * A server handed {@see \DavServices\Sync\Journal} therefore reports changes
 * and removals from the moment it is registered (R-SYNC-03, R-SYNC-05). One
 * handed an `IRevisions` that keeps no history refuses every token with §3.2's
 * `DAV:valid-sync-token`, which is that section's own first reason for
 * invalidating one — "Servers might be unable to maintain all of the change
 * data for a collection due to storage or performance reasons" — and §3.1 has
 * the client fall back to a full synchronisation, which it does correctly.
 *
 * ## What is not here yet
 *
 * **§3.3's `403` for a child collection that cannot be synchronised**, which
 * §3.2 gives a shape of its own: "one DAV:status with a value set to '403
 * Forbidden', a DAV:error containing DAV:supported-report or
 * DAV:sync-traversal-supported". It is unreachable for a server with a
 * journal — a journal knows the state of every collection it is asked about —
 * and needs a walk that asks about each collection on the way down for one
 * whose `IRevisions` answers for some collections and not others.
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
     * The tag of Apple's `caldav-ctag-03` §4.1 (R-SYNC-06).
     *
     * No RFC defines it; {@see nameTheTag()} says where it comes from.
     */
    public const GETCTAG = '{http://calendarserver.org/ns/}getctag';

    /**
     * @param IRevisions $revisions Which change a collection is at, or null
     *                              where this server keeps no synchronisation
     *                              state for it
     * @param int $atMost How many members one answer may name before it is cut
     *                    short with §3.6's `507` (R-SYNC-04), or
     *                    {@see IRevisions::EVERYTHING} for no limit. **Off
     *                    unless someone sets it**, because §3.6 has the
     *                    limiting as a MAY and §3.4's "the server MUST return
     *                    all member URLs of the collection" is what a client
     *                    is owed otherwise. A first synchronisation is never
     *                    cut: see {@see \DavServices\Sync\Report\SyncCollection}
     */
    public function __construct(
        private readonly Server $server,
        private readonly IRevisions $revisions,
        private readonly int $atMost = IRevisions::EVERYTHING,
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
        $events->on(PropertiesRequested::class, $this->nameTheTag(...));
        $events->on(PropertiesChanging::class, $this->guardChangingProperties(...));
        $events->on(StateTokensRequested::class, $this->nameTheState(...));

        // **And the fourth thing, which is §3.2's first sentence about what a
        // server has to do:** "a server needs to keep track of changes to any
        // member URLs and their mapped resources in a collection". Whatever
        // answers where a collection is also hears it move
        // ({@see IRevisions::keepTrack()}) — and wiring it here rather than
        // leaving it to a deployment is what makes a history that nothing
        // writes to impossible. That failure has no symptom: every token
        // would be answered with "nothing has changed", and a client would
        // believe it.
        $this->revisions->keepTrack($events);

        $report->on(
            self::SYNC_COLLECTION,
            (new SyncCollection($this->server, $this->revisions, $this->atMost))(...),
        );
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
     * Answers `CS:getctag` on a calendar collection (R-SYNC-06).
     *
     * **There is no RFC for this property, and that was checked rather than
     * assumed.** The namespace is Apple's, and so is the specification:
     * `caldav-ctag-03`, "Calendar Collection Entity Tag (CTag) in CalDAV",
     * C. Daboo, 27 May 2015, a Calendar Server extension that never became an
     * IETF document. Its §4.1 is the whole definition, and every decision here
     * is one of its sentences.
     *
     * **The value is §4's token, which the extension itself asks for.** Its
     * abstract deprecates it in favour of RFC 6578 and then says how to keep
     * it: "servers MAY continue to support this specification by simply using
     * the DAV:sync-token property value for the getctag property value, in
     * order to provide backwards compatibility with old clients." So one
     * number answers both names — and that is the honest arrangement rather
     * than the cheap one, because a server whose tag and token named different
     * states would be a server whose answers about one collection disagree.
     *
     * §4.1's other sentences, each in one line of code:
     *
     * - "This property MUST be defined on a calendar or scheduling Inbox or
     *   Outbox collection resource" — hence the question about the resource
     *   type. **Asked of the resource type and not of a class**, because that
     *   is what a calendar collection *is*: RFC 4791 §4.2 has one as a
     *   collection whose `DAV:resourcetype` carries `CALDAV:calendar`, so a
     *   deployment's own calendar is one too. *Scheduling Inbox and Outbox
     *   collections are not here because this library has none* — RFC 6638's
     *   scheduling is not implemented, and a property for a collection that
     *   cannot exist is not a gap.
     * - "It MUST be protected" — {@see guardChangingProperties()}.
     * - "SHOULD be returned by a PROPFIND DAV:allprop request" — hence
     *   {@see \DavServices\Dav\PropFindResult::wants()} here where the token
     *   uses `wasNamed()`. **Two SHOULDs pointing opposite ways over the same
     *   number**: RFC 6578 §4 has its token "SHOULD NOT be returned by a
     *   PROPFIND DAV:allprop request", and each is followed for the property
     *   it is written about. The extension cites §12.14.1 of RFC 2518 for that
     *   sentence, a memo RFC 4918 obsoletes and whose `allprop` meant *all*;
     *   §9.1 of RFC 4918 no longer asks for every live property there, so this
     *   is a SHOULD honoured rather than one compelled.
     * - "guaranteed to be unique over the lifetime of any calendar […] at a
     *   specific URI" (§3.1) and "each change MUST result in a value that is
     *   different from any other used with that collection URI" (§4.1) — a
     *   revision that only ever grows gives that, and {@see Token} carries the
     *   collection as well, so no two collections share a value either.
     *
     * **§3.1's MUST comes for free, and that is the point of using the
     * token:** "Whenever a calendar resource is added to, modified or deleted
     * from the calendar collection, the value of the CS:getctag property MUST
     * change." The journal hears those three through the same events it hears
     * for the report ({@see \DavServices\Sync\IRevisions::keepTrack()}), so
     * there is no second mechanism to keep in step with the first.
     */
    private function nameTheTag(PropertiesRequested $event): void
    {
        $result = $event->result();

        if (!$result->wants(self::GETCTAG)) {
            return;
        }

        $node = $event->node();

        if (!$node instanceof IResourceType || !in_array(Calendar::CALENDAR, $node->resourceTypes(), true)) {
            return;
        }

        $revision = $this->revisions->revisionOf($result->path());

        if ($revision === null) {
            return;
        }

        $result->set(self::GETCTAG, (string) Token::for($result->path(), $revision));
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
            if ($name === self::TOKEN || $name === self::GETCTAG) {
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
