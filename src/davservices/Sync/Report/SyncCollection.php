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
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Sync\IRevisions;
use DavServices\Sync\Token;
use DavServices\Xml\Element;
use DavServices\Xml\MultiStatus;
use Generator;

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
 * falls back to.
 *
 * **A token is refused**, with §3.2's `DAV:valid-sync-token` precondition. The
 * first reason that section lists for invalidating one is this chunk's
 * situation exactly: "Servers might be unable to maintain all of the change
 * data for a collection due to storage or performance reasons." R-SYNC-03 and
 * `Sync\Journal` (P7-02) are what make a token answerable; until then a client
 * synchronises in full each time, which is slower and not wrong.
 *
 * **A `DAV:limit` is refused**, with §3.7's postcondition, which is §3.12's own
 * example down to the status: "If a server is unable to truncate the result at
 * or below the requested number, then it MUST fail the request with a
 * DAV:number-of-matches-within-limits postcondition error." Honouring a limit
 * means truncating, and truncation is §3.6 — R-SYNC-04, P7-03.
 *
 * **Every limit is refused, including one this answer would fit inside.** §3.7
 * asks whether the server can truncate, not whether a particular answer is
 * short enough; a server that accepted the easy ones would answer the same
 * request differently depending on how much happened to be in the collection,
 * and a client could not tell which it had been given.
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

        $this->mustNotAskForALimit($asked);

        $revision = $this->revisions->revisionOf($path);

        if ($revision === null) {
            throw new Forbidden(
                'This server keeps no synchronisation state for that collection, so it answers no DAV:sync-collection report on it (RFC 3253 §3.6).',
                self::SUPPORTED_REPORT,
            );
        }

        $this->mustBeAStateItCanAnswer($asked, $path);

        $report = new MultiStatus();

        foreach ($this->membersUnder($path, $node, $depth) as $member => $child) {
            $report->addProperties(
                $this->server->hrefOf($member, $child),
                Answers::about($this->server->events(), $member, $child, PropFindForm::Named, $names)->byStatus(),
            );
        }

        $report->addSyncToken((string) Token::for($path, $revision));

        return (new Response(207, body: $this->server->writer()->write($report->toElement())))
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
     * §3.7's postcondition.
     *
     * @throws InsufficientStorage If the body asks for a limit
     */
    private function mustNotAskForALimit(Element $asked): void
    {
        if ($asked->child(self::LIMIT) === null) {
            return;
        }

        throw new InsufficientStorage(
            'This server cannot truncate a synchronisation answer, so it cannot hold one to a DAV:limit: the DAV:number-of-matches-within-limits postcondition of RFC 6578 §3.7.',
            self::WITHIN_LIMITS,
        );
    }

    /**
     * §3.2's precondition, for the token the client sent.
     *
     * **An empty element is no token and no violation**, §3.1 making it the
     * request for a full listing. Anything else is a state this server cannot
     * answer from, there being no journal yet (R-SYNC-03, P7-02).
     *
     * @throws BadRequest If the body carries no `DAV:sync-token` at all
     * @throws Forbidden If it carries one this server cannot answer
     */
    private function mustBeAStateItCanAnswer(Element $asked, string $path): void
    {
        $element = $asked->child(self::TOKEN) ?? throw new BadRequest(
            'A DAV:sync-collection carries a DAV:sync-token, empty for a full synchronisation: RFC 6578 §6.1 and §3.1.',
        );
        $raw = $element->text();

        if ($raw === '') {
            return;
        }

        throw new Forbidden(
            sprintf(
                'This server keeps no change history yet, so "%s" is no state it can report from: the DAV:valid-sync-token precondition of RFC 6578 §3.2.',
                $raw,
            ),
            self::VALID_SYNC_TOKEN,
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
     * It is also the shape that does not crash the measuring device. A
     * generator that both yields and delegates with `yield from` in one frame
     * made Xdebug's branch coverage segfault somewhere inside this report's
     * own tests, over and over and at a different test each time — see
     * bin/lib/TestShards.php for the whole of that hunt. The library is not
     * contorted for it: a worklist is ordinary code, and the recursion bought
     * nothing but a stack.
     *
     * @return Generator<string, INode>
     */
    private function membersUnder(string $path, ICollection $node, int $depth): Generator
    {
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
                yield $member => $child;

                // §3.3: "all appropriate member URLs […] are reported, provided
                // child collections themselves also support the
                // DAV:sync-collection report." Which child collections do not
                // is §3.3's own `403` case, and it arrives with the journal
                // that can tell them apart (P7-02); until then every
                // collection this server holds is walked.
                if ($child instanceof ICollection) {
                    $pending[] = [$member, $child, $left - 1];
                }
            }
        }
    }
}
