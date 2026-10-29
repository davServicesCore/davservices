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

namespace DavServices\Sync\Report;

use DavServices\Dav\Event\ListingMembers;
use DavServices\Dav\ICollection;
use DavServices\Dav\INode;
use DavServices\Dav\Property\Answers;
use DavServices\Dav\PropFindForm;
use DavServices\Dav\ReportDepth;
use DavServices\Dav\Server;
use DavServices\Dav\VisibleMembers;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use DavServices\Exception\InsufficientStorage;
use DavServices\Exception\NotFound;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Sync\Change;
use DavServices\Sync\IRevisions;
use DavServices\Sync\Token;
use DavServices\Xml\Element;
use DavServices\Xml\MultiStatus;

/**
 * Answers `DAV:sync-collection`: what has changed here (RFC 6578 §3.2).
 *
 * **This is how a client stays in step without asking about everything.** It
 * holds a token from last time, hands it back, and is told only what moved.
 * §3.1:
 *
 * > In order to synchronize the contents of a collection between a server and
 * > client, the server provides the client with a synchronization token each
 * > time the synchronization report is executed. That token represents the
 * > state of the data being synchronized at that point in time. The client can
 * > then present that same token back to the server at some later time, and the
 * > server will return only those items that are new, have changed, or were
 * > deleted since that token was generated.
 *
 * Given to {@see \DavServices\Dav\Method\Report} like any other, which
 * {@see \DavServices\Plugin\Sync} does for every server it is registered on.
 *
 * The body is §6.1's:
 *
 *     <!ELEMENT sync-collection (sync-token, sync-level, limit?, prop)>
 *
 * **An empty `DAV:sync-token` is not a missing one**, and that is the
 * distinction in that definition which is easiest to get backwards. §3.1: "That
 * is done by the client sending an empty token value to the server. This
 * indicates to the server that a full listing is required." So the element is
 * required and its emptiness is a request.
 *
 * ## The scope is `DAV:sync-level`, never the `Depth` header
 *
 * §3.2: "This report is only defined when the Depth header has value '0'; other
 * values result in a 400 (Bad Request) error response." And §3.3: "Servers MUST
 * support only Depth:0 behavior with the DAV:sync-collection report."
 *
 * The header is therefore a guard and not a scope — the opposite of §7.8's
 * `calendar-query`, where it *is* the scope. What scopes this report is
 * `DAV:sync-level`, with §6.3's two values: `1` for the immediate children and
 * `infinite` for every depth. **`infinity` is not one of them**: that is the
 * `Depth` header's spelling (RFC 4918 §10.2), and a client that sent it asked
 * for something §6.3 does not define.
 *
 * ## What this answers, and what it refuses
 *
 * **The initial synchronisation**, which §3.4 defines: "the server MUST return
 * all member URLs of the collection […] and it MUST NOT return any removed
 * member URLs. All types of member (collection or non-collection) MUST be
 * reported." That is the half a server can answer while keeping no history at
 * all, and §3.1 names it as both the first thing a client does and the thing it
 * falls back to. **It is the tree and never the history**: a removal reported
 * there would tell a client that something it has never heard of has gone.
 *
 * **A subsequent synchronisation**, which §3.5 defines: the members that
 * changed, with their properties, and the members that went, with a `404`.
 * Which is which comes from {@see IRevisions::changesSince()} — and a server
 * that keeps no history answers nothing there, which is §3.2's own first
 * reason for refusing a token: "Servers might be unable to maintain all of the
 * change data for a collection due to storage or performance reasons." Such a
 * server synchronises its clients in full each time, which is slower and not
 * wrong.
 *
 * **A token this server never made for this collection is refused**, with
 * §3.2's `DAV:valid-sync-token` precondition read literally: "The
 * DAV:sync-token element value MUST be a valid token previously returned by
 * the server for the collection targeted by the request-URI."
 *
 * **An answer may be cut short**, which is §3.6 and R-SYNC-04: the page, a
 * token naming the state that page covers, and one more `DAV:response` saying
 * `507` about the collection itself. Both of §3.6's causes are served — the
 * client's `DAV:limit` and this server's own number — and the smaller of the
 * two decides, since an answer at or below both satisfies each.
 *
 * **A first synchronisation is the one answer that cannot be cut**, and §3.6's
 * own rule about the token is why: no `DAV:sync-token` names part of a
 * listing, so there would be nothing for the client to come back with. A
 * `DAV:limit` on one is therefore refused with §3.7's postcondition —
 * "If a server is unable to truncate the result at or below the requested
 * number, then it MUST fail the request with a
 * DAV:number-of-matches-within-limits postcondition error" — which is the very
 * case that sentence is for. {@see mustNotCutAFirstListing()} has the whole
 * argument, including what a cursor would cost.
 *
 * ## Which collections can be synchronised
 *
 * A token has to say which state it stands for, so a collection this server
 * tracks no revision of cannot be given one. §3.2 has the report listed "on any
 * collection that supports synchronization", and a collection that does not is
 * refused with RFC 3253 §3.6's own `DAV:supported-report` — the same
 * precondition {@see \DavServices\Dav\Method\Report} raises for a report this
 * server does not answer at all.
 *
 * **Where the revision comes from is a seam and not a guess.** Nothing a node
 * offers says it: `INode::lastModified()` is null for every store that keeps no
 * such thing, and a server that read a collection's own entity tag would be
 * reading something §3.5.1 warns about in as many words — "There is no
 * guarantee that changes to members of a collection will result in a change in
 * any entity tag of that collection".
 *
 * ## Why the properties are assembled rather than fetched
 *
 * §3.2 has the response "follow the same behavior as PROPFIND with respect to
 * the DAV:multistatus response elements used to return specific property
 * results", so they come from {@see Answers} — which is also what makes a
 * property nobody has come back as a `404` **inside** the `propstat`. That
 * matters more here than elsewhere, because §3.2 reserves a status on the
 * *response* for a member that was removed: a `404` in the wrong place would
 * tell a client a resource had been deleted.
 *
 * **The members are walked through {@see VisibleMembers}**, so a member
 * concealed from a client is not in the answer. §3.5.2 provides for it — "A
 * member MAY be reported as removed if the user issuing the request no longer
 * has access to this member" — and on an initial synchronisation §3.4 forbids
 * reporting removals, so it is simply absent.
 */
final class SyncCollection
{
    private const TOKEN = '{DAV:}sync-token';

    private const LEVEL = '{DAV:}sync-level';

    private const LIMIT = '{DAV:}limit';

    private const NRESULTS = '{DAV:}nresults';

    private const PROP = '{DAV:}prop';

    private const VALID_SYNC_TOKEN = '{DAV:}valid-sync-token';

    private const WITHIN_LIMITS = '{DAV:}number-of-matches-within-limits';

    private const SUPPORTED_REPORT = '{DAV:}supported-report';

    /** §6.3: `<!-- Text MUST be either "1" or "infinite" -->`. */
    private const ONE = '1';

    private const INFINITE = 'infinite';

    /** What `infinite` becomes: deeper than any tree a backend holds. */
    private const ENDLESS = PHP_INT_MAX;

    /**
     * @param IRevisions $revisions Which change a collection is at, or null
     *                              where this server tracks no revision of it
     *                              — a collection that cannot be
     *                              synchronised. **Required rather than
     *                              defaulted**: a report whose default
     *                              answered something would hand out tokens it
     *                              could not keep honest, and §5's conditional
     *                              writes rest on a token that moves when the
     *                              collection does
     */
    public function __construct(
        private readonly Server $server,
        private readonly IRevisions $revisions,
        private readonly int $atMost = IRevisions::EVERYTHING,
    ) {
    }

    /**
     * Reports what the client does not have yet.
     *
     * @throws BadRequest If the `Depth` header is not `0`, the Request-URI is
     *                    no collection, or the body is not one §6.1 admits
     * @throws Forbidden If the token is one this server cannot answer (§3.2),
     *                   or the collection cannot be synchronised
     * @throws InsufficientStorage If a `DAV:limit` is asked for (§3.7)
     */
    public function __invoke(Request $request, Element $asked): Response
    {
        ReportDepth::mustBeZero($request);

        $path = $this->server->path($request);
        $node = $this->server->tree()->node($path);

        if (!$node instanceof ICollection) {
            throw new BadRequest(
                'A DAV:sync-collection is about a collection: RFC 6578 §3.2 has the request-URI identify one.',
            );
        }

        $depth = self::levelIn($asked);
        $names = self::namesIn($asked);

        $revision = $this->revisions->revisionOf($path);

        if ($revision === null) {
            throw new Forbidden(
                'This server keeps no synchronisation state for that collection, so it answers no DAV:sync-collection report on it (RFC 3253 §3.6).',
                self::SUPPORTED_REPORT,
            );
        }

        // **The token is asked about before the limit is**, because §3.2's
        // `DAV:valid-sync-token` is a *precondition* and §3.7's
        // `DAV:number-of-matches-within-limits` a *postcondition*: one is
        // about whether the request can be acted on at all, the other about
        // what came of acting on it. A client holding a token this server
        // cannot answer is owed that answer whatever else its body asked for.
        $held = $this->stateHeldBy($asked, $path);
        $report = new MultiStatus();

        if ($held === null) {
            $this->mustNotCutAFirstListing($asked);
            $this->describeEveryMember($report, $path, $node, $depth, $names);
            $report->addSyncToken((string) Token::for($path, $revision));

            return self::sent($report, $this->server);
        }

        $changed = $this->revisions->changesSince(
            $held,
            $path,
            $depth,
            $this->limitIn($asked),
        ) ?? throw new Forbidden(
            'This server keeps no change history, so no token it ever returned is a state it can report from: the DAV:valid-sync-token precondition of RFC 6578 §3.2.',
            self::VALID_SYNC_TOKEN,
        );

        $this->describeWhatChanged($report, $path, $changed->members(), $names);

        // §3.6: "the DAV:sync-token value returned in the response MUST
        // represent the correct state for the partial set of changes
        // returned." Which state that is, is the one place that knows both
        // the changes and where they stop — see {@see Changes}.
        $report->addSyncToken((string) Token::for($path, $changed->upTo()));

        if ($changed->wasCutShort()) {
            $this->sayThereWasMore($report, $path, $node);
        }

        return self::sent($report, $this->server);
    }

    /**
     * The document, as §3.2 asks for it: a `207` carrying a `DAV:multistatus`.
     */
    private static function sent(MultiStatus $report, Server $server): Response
    {
        return (new Response(207, body: $server->writer()->write($report->toElement())))
            ->withHeader('Content-Type', 'application/xml; charset=utf-8');
    }

    /**
     * §3.3's scope, as a depth to count down through.
     *
     * @throws BadRequest If the element is missing, or names a third value
     */
    private static function levelIn(Element $asked): int
    {
        $level = $asked->child(self::LEVEL)?->text();

        if ($level === self::ONE) {
            return 1;
        }

        if ($level === self::INFINITE) {
            return self::ENDLESS;
        }

        throw new BadRequest(sprintf(
            'A DAV:sync-level is 1 or infinite (RFC 6578 §6.3), not "%s".',
            $level ?? '',
        ));
    }

    /**
     * What the `DAV:prop` named.
     *
     * §6.1 puts it outside any optional group, so a body without one is no
     * `sync-collection` — and this report has no default property set to fall
     * back on: unlike a `PROPFIND` with no body, which §14.2 of RFC 4918 says
     * asks for everything, nothing here says what "everything" would mean.
     *
     * @throws BadRequest If there is none
     *
     * @return list<string>
     */
    private static function namesIn(Element $asked): array
    {
        $prop = $asked->child(self::PROP) ?? throw new BadRequest(
            'A DAV:sync-collection names the properties it wants: RFC 6578 §6.1 has a DAV:prop outside the optional group.',
        );

        $names = [];

        foreach ($prop->children() as $child) {
            $names[] = $child->name();
        }

        return $names;
    }

    /**
     * §3.7's postcondition, for the one answer that cannot be cut.
     *
     * **A first listing is the tree and not the history**, and §3.6's rule
     * about the token is what makes it uncuttable: "the DAV:sync-token value
     * returned in the response MUST represent the correct state for the
     * partial set of changes returned." For a page of *changes* a revision
     * says that exactly. For the first ten of sixty members there is no such
     * revision — a token naming the state would bring back only what has
     * changed since, and the other fifty would never be mentioned again. The
     * client would be left believing it had the collection.
     *
     * A cursor in the token would answer it, §3.2 letting a server put what it
     * likes in one, but a cursor into a listing needs the listing to come back
     * in the same order next time, and {@see ICollection::children()} promises
     * no order at all. **A paging that rests on a promise nobody made is worse
     * than a refusal**, because it loses members quietly.
     *
     * So this server is "unable to truncate the result at or below the
     * requested number", which is the very case §3.7 names, and it fails the
     * request the way that sentence says to. Its own limit it simply does not
     * apply here: §3.6's limiting is a MAY, and §3.4's "the server MUST return
     * all member URLs of the collection" is then answered whole.
     *
     * @throws InsufficientStorage If a first listing is asked to be cut short
     */
    private function mustNotCutAFirstListing(Element $asked): void
    {
        if ($asked->child(self::LIMIT) === null) {
            return;
        }

        throw new InsufficientStorage(
            'A first synchronisation answers the collection rather than its history, and no DAV:sync-token names part of a listing, so this server cannot hold one to a DAV:limit: the DAV:number-of-matches-within-limits postcondition of RFC 6578 §3.7.',
            self::WITHIN_LIMITS,
        );
    }

    /**
     * How many members an answer may name: the smaller of the two limits.
     *
     * **Both are real, and §3.6 names both causes** — a server limits "to
     * limit the amount of work expended in processing a request, or as the
     * result of an explicit limit set by the client". The client's binds
     * because §3.7 has the server fail what it cannot hold "at or below the
     * requested number"; this server's binds because that limiting is its own
     * MAY. An answer at or below both satisfies each.
     *
     * **`DAV:limit` is read as the grammar that defines it.** RFC 5323 §5.17:
     * `<!ELEMENT limit (nresults) >` and
     * `<!ELEMENT nresults (#PCDATA)> <!-- only digits -->`, with "The value of
     * this element is an unsigned integer". A body that says something else is
     * one this server will not guess a number out of.
     *
     * **And §5.17's "The server MAY disregard this limit" does not reach
     * here.** RFC 6578 §3.7 takes that offer back for this report: a server
     * either holds the answer to the number or fails the request by name.
     *
     * @throws BadRequest If the `DAV:limit` is not one §5.17 defines
     */
    private function limitIn(Element $asked): int
    {
        $limit = $asked->child(self::LIMIT);

        if ($limit === null) {
            return $this->atMost;
        }

        $wanted = $limit->child(self::NRESULTS)?->text() ?? throw new BadRequest(
            'A DAV:limit carries a DAV:nresults: RFC 5323 §5.17 has it as "limit (nresults)".',
        );

        if (preg_match('~^[0-9]+$~', $wanted) !== 1) {
            throw new BadRequest(sprintf(
                'A DAV:nresults is an unsigned integer (RFC 5323 §5.17), not "%s".',
                $wanted,
            ));
        }

        return min($this->atMost, (int) $wanted);
    }

    /**
     * §3.6's word for the collection when its answer was cut short.
     *
     * > If the result set is truncated, the response MUST use status code 207
     * > (Multi-Status), return a DAV:multistatus response body, and indicate a
     * > status of 507 (Insufficient Storage) for the request-URI. That
     * > DAV:response element SHOULD include a DAV:error element with the
     * > DAV:number-of-matches-within-limits precondition.
     *
     * The collection stands where RFC 5323 §2.3.1 — the section §3.6 is built
     * on — puts the search arbiter: it is what the client asked about. It is
     * named with its trailing slash, RFC 4918 §5.2 asking for one wherever a
     * server produces the URL of a collection.
     *
     * **This response is one more and not one of the limited ones**, which
     * §3.6's example settles: the server returns "only 10 DAV:response
     * elements […] together with an additional DAV:response element for the
     * request-URI with a status code of 507". Counting it would quietly make
     * every page one change shorter than the client asked for.
     */
    private function sayThereWasMore(MultiStatus $report, string $path, ICollection $node): void
    {
        $report->addStatus(
            $this->server->hrefOf($path, $node),
            507,
            self::WITHIN_LIMITS,
            'There is more than this answer carries: ask again with the DAV:sync-token it gave you.',
        );
    }

    /**
     * Which state the client says it holds, or null where it holds none.
     *
     * **An empty element is no token and no violation**, §3.1 making it the
     * request for a full listing: "That is done by the client sending an empty
     * token value to the server. This indicates to the server that a full
     * listing is required."
     *
     * Anything else has to be a token this server made **for this
     * collection**, which is §3.2's precondition read literally: "a valid
     * token previously returned by the server for the collection targeted by
     * the request-URI". {@see Token} carries both halves, so a token from
     * another server, from another collection, or of no shape at all is
     * refused here — and whether the state it names is one the history still
     * reaches is {@see IRevisions::changesSince()}'s to say, that being where
     * what has been forgotten is known.
     *
     * @throws BadRequest If the body carries no `DAV:sync-token` at all
     * @throws Forbidden If it carries one this server never made for this
     *                   collection
     */
    private function stateHeldBy(Element $asked, string $path): ?int
    {
        $element = $asked->child(self::TOKEN) ?? throw new BadRequest(
            'A DAV:sync-collection carries a DAV:sync-token, empty for a full synchronisation: RFC 6578 §6.1 and §3.1.',
        );
        $raw = $element->text();

        if ($raw === '') {
            return null;
        }

        $token = Token::of($raw);

        if ($token === null || !$token->isFor($path)) {
            throw new Forbidden(
                sprintf(
                    'No token this server returned for that collection reads "%s": the DAV:valid-sync-token precondition of RFC 6578 §3.2.',
                    $raw,
                ),
                self::VALID_SYNC_TOKEN,
            );
        }

        return $token->change();
    }

    /**
     * §3.4's initial synchronisation: every member, and no removals.
     *
     * > The server MUST return all member URLs of the collection (taking
     * > account of the DAV:sync-level XML element value as per Section 3.3, and
     * > optional truncation of the result set as per Section 3.6) and it MUST
     * > NOT return any removed member URLs. All types of member (collection or
     * > non-collection) MUST be reported.
     *
     * **It is the tree and never the history**, however much of one this
     * server keeps: a removal reported here would tell a client that something
     * it has never heard of has gone.
     *
     * @param list<string> $names What the `DAV:prop` asked for
     */
    private function describeEveryMember(
        MultiStatus $report,
        string $path,
        ICollection $node,
        int $depth,
        array $names,
    ): void {
        foreach ($this->membersUnder($path, $node, $depth) as $member => $child) {
            $this->describe($report, $member, $child, $names);
        }
    }

    /**
     * §3.5's subsequent synchronisation: what has become of each member.
     *
     * **The history says which members are owed an answer, and the tree says
     * what to say about each.** A removal needs nothing from the tree — §3.2
     * gives it "one DAV:status with a value set to '404 Not Found'" and
     * nothing else — so a client that deleted five hundred events is answered
     * without five hundred lookups of what is no longer there.
     *
     * **Where the two disagree, the tree is right.** A member the history
     * calls changed but the tree no longer holds is answered as removed: a
     * `propstat` would send the client to fetch what is not there, and
     * §3.5.2's first sentence is a MUST about the mapping rather than about
     * the record of it.
     *
     * **Only the members that are still there are asked about** (R-ACL-06).
     * Concealment keeps a client from being told that something exists, so it
     * is asked of the things that do — §3.5.1's "A member URL MAY be reported
     * as changed if the user issuing the request was granted access to this
     * member URL" is the same question from the other side. A removal names a
     * path and says only that nothing is there, which is what a `GET` would
     * answer any client; §3.5.2 licenses it in as many words — "A member MAY
     * be reported as removed if the user issuing the request no longer has
     * access to this member" — and dropping one because its resource has gone
     * would leave a client holding it for ever.
     *
     * @param array<string, Change> $changes What became of each member, as
     *                                       much of it as this answer carries
     * @param list<string> $names What the `DAV:prop` asked for
     */
    private function describeWhatChanged(
        MultiStatus $report,
        string $path,
        array $changes,
        array $names,
    ): void {
        $present = $this->whatIsStillThere($changes);
        $listing = $this->server->events()->emit(new ListingMembers($path, array_keys($present)));
        $visible = array_flip($listing->visible());

        foreach ($changes as $member => $change) {
            $child = $present[$member] ?? null;

            if ($child === null) {
                $report->addStatus($this->server->href($member), 404);

                continue;
            }

            if (isset($visible[$member])) {
                $this->describe($report, $member, $child, $names);
            }
        }
    }

    /**
     * Which of the changed members the tree still holds, with their nodes.
     *
     * **Looked up before the concealment question rather than after**, because
     * concealment is a question about what exists: a path with nothing behind
     * it is one no resolver can answer about, and a member that has gone is
     * answered by §3.2's `404` whatever the answer would have been. Asking
     * first and looking up afterwards would have had the two rules decided by
     * which `if` came first.
     *
     * A removal needs no lookup at all — §3.2 gives it a status and nothing
     * else — so a client that deleted five hundred events costs five hundred
     * fewer questions than it looks.
     *
     * @param array<string, Change> $changes
     *
     * @return array<string, INode>
     */
    private function whatIsStillThere(array $changes): array
    {
        $present = [];

        foreach ($changes as $member => $change) {
            if ($change !== Change::Changed) {
                continue;
            }

            $child = $this->nodeAt($member);

            if ($child !== null) {
                $present[$member] = $child;
            }
        }

        return $present;
    }

    /**
     * The node at that path, or null where nothing is there any more.
     */
    private function nodeAt(string $member): ?INode
    {
        try {
            return $this->server->tree()->node($member);
        } catch (NotFound) {
            return null;
        }
    }

    /**
     * One member that is there, with the properties the body named.
     *
     * §3.2: the response "MUST contain at least one DAV:propstat element and
     * MUST NOT contain any DAV:status element". The properties come from
     * {@see Answers}, which is also what puts a property nobody has at a
     * `404` **inside** the `propstat` — and that distinction matters more here
     * than anywhere else, §3.2 reserving a status on the *response* for a
     * member that has gone.
     *
     * @param list<string> $names What the `DAV:prop` asked for
     */
    private function describe(MultiStatus $report, string $member, INode $child, array $names): void
    {
        $report->addProperties(
            $this->server->hrefOf($member, $child),
            Answers::about($this->server->events(), $member, $child, PropFindForm::Named, $names)->byStatus(),
        );
    }

    /**
     * The members below this path, by path, down to the level asked for.
     *
     * **A worklist rather than recursion**, and the order that falls out of it
     * is the order a client gets: every member of one collection before any
     * member of the collections inside it. Nothing asks for an order — §3.2
     * wants "one DAV:response element for each member URL" and says nothing
     * about where — so the walk is free to be the shape that is easiest to
     * read, and a list of what is still to do is that shape.
     *
     * **And it hands back what it found rather than yielding it**, because
     * nothing here stops early. The truncation that *does* stop early never
     * reaches this walk: a first listing is the one answer that cannot be cut
     * ({@see mustNotCutAFirstListing()}), and a page of changes is cut where
     * the changes are. So the laziness this shape gave up is still laziness
     * nobody wants, and a generator whose laziness nobody uses is an array
     * that is harder to read.
     *
     * Both shapes also happen to be the ones that do not crash the measuring
     * device: Xdebug's branch coverage segfaulted on this walk while it was a
     * generator, first delegating with `yield from` and then not. See
     * bin/lib/TestShards.php for that hunt. The library is not contorted for
     * a tool — a worklist and an array are ordinary code, and the recursion
     * and the laziness each bought nothing.
     *
     * @return array<string, INode>
     */
    private function membersUnder(string $path, ICollection $node, int $depth): array
    {
        $found = [];

        /** @var list<array{string, ICollection, int}> $pending */
        $pending = [[$path, $node, $depth]];

        while ($pending !== []) {
            [$where, $collection, $left] = array_shift($pending);

            // At the end of the depth there is nothing here. §6.3 admits no
            // level that comes to zero, so no request starts here — but a walk
            // whose end condition lived anywhere else would be one where the
            // depth it was given did not decide what came back.
            if ($left === 0) {
                continue;
            }

            foreach (VisibleMembers::of($this->server->events(), $where, $collection) as $member => $child) {
                $found[$member] = $child;

                // §3.3: "all appropriate member URLs […] are reported, provided
                // child collections themselves also support the
                // DAV:sync-collection report." A child collection that does
                // not is §3.3's own `403` case, which is still to come: it is
                // unreachable for a server with a journal, a journal knowing
                // the state of every collection it is asked about, and for one
                // whose `IRevisions` answers for some collections and not
                // others it needs this walk to ask about each collection on
                // the way down — and §3.3's "The 403 response MUST be sent
                // once, when the collection is first reported to the client"
                // decides when. Until then every collection here is walked.
                if ($child instanceof ICollection) {
                    $pending[] = [$member, $child, $left - 1];
                }
            }
        }

        return $found;
    }
}
