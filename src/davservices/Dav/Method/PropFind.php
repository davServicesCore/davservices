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
     */
    public function __construct(
        private readonly Server $server,
        private readonly bool $answersInfiniteDepth = false,
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

        $named = self::childNamed($document, '{DAV:}prop');

        if ($named !== null) {
            return [PropFindForm::Named, self::namesOf($named)];
        }

        if (self::childNamed($document, '{DAV:}propname') !== null) {
            return [PropFindForm::NamesOnly, []];
        }

        if (self::childNamed($document, '{DAV:}allprop') === null) {
            throw new BadRequest('This PROPFIND asks for nothing.');
        }

        $include = self::childNamed($document, '{DAV:}include');

        return [PropFindForm::Everything, $include === null ? [] : self::namesOf($include)];
    }

    private static function childNamed(Element $element, string $name): ?Element
    {
        foreach ($element->children() as $child) {
            if ($child->name() === $name) {
                return $child;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function namesOf(Element $element): array
    {
        return array_map(static fn (Element $child): string => $child->name(), $element->children());
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

        foreach (VisibleMembers::of($this->server->events(), $path, $node) as $member => $child) {
            $this->report($report, $member, $child, $form, $names, $depth - 1);
        }
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
