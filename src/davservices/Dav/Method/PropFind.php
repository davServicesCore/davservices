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

namespace DavServices\Dav\Method;

use DavServices\Dav\ICollection;
use DavServices\Dav\INode;
use DavServices\Dav\Property\Answers;
use DavServices\Dav\PropFindForm;
use DavServices\Dav\Server;
use DavServices\Dav\VisibleMembers;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use DavServices\Exception\NotFound;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Uri\Path;
use DavServices\Xml\Element;
use DavServices\Xml\MultiStatus;

/**
 * Answers `PROPFIND`: what a client is told about the resources under a path.
 *
 * This is how a client learns what is on a server at all, and how nearly every
 * synchronisation begins. Two of its decisions are worth more attention than
 * the rest.
 *
 * **`Depth: infinity` is refused unless it was switched on** (R-DAV-02). It
 * walks the whole tree below the path, and on the root that is every file of
 * every account — the cheapest way there is to bring a server down, one
 * request long. RFC 4918 §9.1 has a server treat a missing `Depth` as
 * `infinity`, so a client that sends none meets the same refusal, and
 * `DAV:propfind-finite-depth` tells it exactly what to send instead. An
 * application that knows its tree is small switches it on:
 *
 *     $propFind = new PropFind($server, answersInfiniteDepth: true);
 *
 * **A listing can be cut short** (R-ACL-08). A collection of a hundred
 * thousand people is a document nobody can use and a server that stops
 * answering anything else while it builds one, so a deployment may say how
 * many members it will report. What is left out is not left silent: RFC 5323
 * §2.3.1 has the answer carry a `507` for the collection itself inside the
 * `207`, with the partial results beside it, and that is what this writes.
 *
 *     $propFind = new PropFind($server, atMostMembers: 500);
 *
 * **A property that was asked for is always accounted for** (R-DAV-04). One
 * nobody has gets a `404` of its own, one that may not be read gets a `403`,
 * and they go in blocks of their own: a client handed three answers to five
 * questions has nothing to tell it which two went missing.
 *
 * Where the answers come from is {@see Answers} — the listeners first, the
 * node after them, and the first answer for a property stands (R-PROP-05).
 *
 * Registered like any other method:
 *
 *     $propFind = new PropFind($server);
 *     $server->onMethod('PROPFIND', $propFind(...));
 */
final class PropFind
{
    /** What `Depth: infinity` becomes: deeper than any tree a backend holds. */
    private const ENDLESS = PHP_INT_MAX;

    /**
     * @param bool $answersInfiniteDepth Whether `Depth: infinity` is answered
     *                                   rather than refused (R-DAV-02)
     * @param int|null $atMostMembers How many members of one collection are
     *                                reported before the rest are left out and
     *                                the client is told (R-ACL-08). Null is no
     *                                limit, because R-ACL-08 asks for a server
     *                                to be *limitable* and one that began
     *                                truncating on its own would surprise a
     *                                deployment that never asked (R-ARC-02)
     */
    public function __construct(
        private readonly Server $server,
        private readonly bool $answersInfiniteDepth = false,
        private readonly ?int $atMostMembers = null,
    ) {
    }

    /**
     * Reports on the resource, and on what lies below it as far as the depth
     * allows.
     *
     * @throws NotFound If there is nothing at the path
     * @throws BadRequest If the depth or the body is not one this protocol has
     * @throws Forbidden If an infinite depth was asked for and is not answered
     */
    public function __invoke(Request $request): Response
    {
        $path = $this->server->path($request);
        $node = $this->server->tree()->node($path);

        [$form, $names] = $this->whatWasAskedFor($request);

        $report = new MultiStatus();

        $this->report($report, $path, $node, $form, $names, $this->depth($request));

        return (new Response(207, body: $this->server->writer()->write($report->toElement())))
            ->withHeader('Content-Type', 'application/xml; charset=utf-8');
    }

    /**
     * How far down the report goes.
     *
     * @throws BadRequest If the depth is not one RFC 4918 §9.1 has
     * @throws Forbidden If it is infinite and this server does not answer that
     */
    private function depth(Request $request): int
    {
        // RFC 4918 §9.1: a request without the header is treated as infinite,
        // which is to say it meets the same refusal.
        $depth = strtolower($request->headers()->first('Depth') ?? 'infinity');

        if ($depth === '0' || $depth === '1') {
            return (int) $depth;
        }

        if ($depth !== 'infinity') {
            throw new BadRequest('A PROPFIND goes 0, 1 or infinity deep.');
        }

        if (!$this->answersInfiniteDepth) {
            throw new Forbidden(
                'This server does not walk a whole tree for one request.',
                '{DAV:}propfind-finite-depth',
            );
        }

        return self::ENDLESS;
    }

    /**
     * What the body asks for.
     *
     * @throws BadRequest If the body is not a `DAV:propfind`, or asks for
     *                    nothing at all
     *
     * @return array{PropFindForm, list<string>}
     */
    private function whatWasAskedFor(Request $request): array
    {
        $body = $request->body();

        // RFC 4918 §9.1: a PROPFIND with no body asks for everything.
        if ($body->isEmpty()) {
            return [PropFindForm::Everything, []];
        }

        $document = $this->server->reader()->parse($body->contents());

        if ($document->name() !== '{DAV:}propfind') {
            throw new BadRequest('The body of a PROPFIND is a DAV:propfind.');
        }

        $named = $document->child('{DAV:}prop');

        if ($named !== null) {
            return [PropFindForm::Named, $named->childNames()];
        }

        if ($document->child('{DAV:}propname') !== null) {
            return [PropFindForm::NamesOnly, []];
        }

        if ($document->child('{DAV:}allprop') === null) {
            throw new BadRequest('This PROPFIND asks for nothing.');
        }

        $include = $document->child('{DAV:}include');

        return [PropFindForm::Everything, $include === null ? [] : $include->childNames()];
    }

    /**
     * Adds this resource to the report, and what lies below it.
     *
     * @param list<string> $names
     */
    private function report(
        MultiStatus $report,
        string $path,
        INode $node,
        PropFindForm $form,
        array $names,
        int $depth,
    ): void {
        $report->addProperties($this->server->hrefOf($path, $node), $this->answersFor($path, $node, $form, $names));

        if ($depth === 0 || !$node instanceof ICollection) {
            return;
        }

        $members = VisibleMembers::of($this->server->events(), $path, $node);
        $reported = $this->atMostMembers === null
            ? $members
            : array_slice($members, 0, $this->atMostMembers, true);

        foreach ($reported as $member => $child) {
            $this->report($report, $member, $child, $form, $names, $depth - 1);
        }

        if (count($reported) < count($members)) {
            $this->sayThereWasMore($report, $path, $node, count($reported));
        }
    }

    /**
     * **RFC 5323 §2.3.1: a result set too large is a `507` inside the `207`.**
     *
     * > the reply MUST use status code 207, return a DAV:multistatus response
     * > body, and indicate a status of 507 (Insufficient Storage) for the
     * > search arbiter URI. It SHOULD include the partial results.
     *
     * §2.3.4 writes the document out: the partial results, then one more
     * `DAV:response` for the resource the listing was about, carrying the
     * status and a `DAV:responsedescription`. Here the collection stands
     * where the search arbiter stands there — it is what the client asked
     * about.
     *
     * RFC 4918 §11.5 alone would have made `507` look wrong, since it
     * describes being unable to *store* something and calls the condition
     * temporary. RFC 5323 is the one that settles it for a listing that was
     * cut short, and it is a better answer than any of the alternatives: the
     * request did not fail, the client has something usable, and it knows
     * that it is not everything.
     */
    private function sayThereWasMore(MultiStatus $report, string $path, INode $node, int $reported): void
    {
        $report->addStatus(
            $this->server->hrefOf($path, $node),
            507,
            null,
            sprintf('Only the first %d members of this collection were reported.', $reported),
        );
    }

    /**
     * Everyone who has something to say about this resource, in order.
     *
     * @param list<string> $names
     *
     * @return array<int, array<string, Element|string|null>>
     */
    private function answersFor(string $path, INode $node, PropFindForm $form, array $names): array
    {
        return Answers::about($this->server->events(), $path, $node, $form, $names)->byStatus();
    }
}
