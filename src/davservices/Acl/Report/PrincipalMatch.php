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

namespace DavServices\Acl\Report;

use DavServices\Acl\GroupResolver;
use DavServices\Dav\Event\CurrentPrincipalRequested;
use DavServices\Dav\ICollection;
use DavServices\Dav\INode;
use DavServices\Dav\Property\Answers;
use DavServices\Dav\PropFindForm;
use DavServices\Dav\ReportDepth;
use DavServices\Dav\Server;
use DavServices\Dav\VisibleMembers;
use DavServices\Exception\BadRequest;
use DavServices\Exception\IHttpFailure;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Xml\Element;
use DavServices\Xml\MultiStatus;

/**
 * Answers `DAV:principal-match`: which of these is mine (RFC 3744 §9.3).
 *
 * **Support for this report is REQUIRED**, and §7.2 binds any server
 * announcing `access-control` to it: that value "MUST indicate that the
 * server supports all MUST level requirements and REQUIRED features specified
 * in this document".
 *
 * Given to {@see \DavServices\Dav\Method\Report} like any other:
 *
 *     $report->on('{DAV:}principal-match', (new PrincipalMatch($server, $groups))(...));
 *
 * ## The two questions it answers
 *
 * **`DAV:principal-property`** — "this report can return all of the resources
 * in a collection hierarchy that are owned by the current user" (§9.3). The
 * body names a property, `DAV:owner` above all, and every member whose
 * property points at the asker is reported. That is how a client draws "my
 * files" in one request rather than one per resource.
 *
 * **`DAV:self`** — "if the collection contains principals, the report can be
 * used to identify all members of the collection that match the current
 * user". That is how a client finds its own principal, and the groups it is
 * in, knowing only where the principals are kept.
 *
 * ## What "matches the current user" means
 *
 * **Not "is the current user".** RFC 3744 §2: "If a person or computational
 * agent matches a principal resource that is a member of a group, they also
 * match the group. Membership in a group is recursive." So the asker matches
 * themselves and every group they are in, however far out — which is what
 * {@see GroupResolver} works out, and the same list access control itself
 * decides by.
 *
 * §9.3 says it a second time for the other half: "When the DAV:self element
 * is used in a DAV:principal-match report issued against a group, it matches
 * the group if a member identifies the same principal as the current user."
 *
 * Nobody signed in matches nothing at all. §5.5.1 makes matching a principal
 * conditional on being "authenticated as being (or being a member of)" it, so
 * an unauthenticated request gets the empty multistatus §9.3 provides for.
 *
 * ## Three decisions the wording settles
 *
 * **There is no limit here.** §9.2 and §9.4 both carry the postcondition
 * `DAV:number-of-matches-within-limits`, and the index at the back of the RFC
 * lists it on exactly those two pages. §9.3 carries none — so unlike its two
 * siblings this report has no `$atMost`, because a refusal invented here
 * would refuse a request the specification says succeeds. It is also the one
 * of the three whose answer is bounded by something other than the client:
 * what somebody owns, rather than what they searched for.
 *
 * **The href is the property's own.** §9.3 matches on "the URI found in the
 * DAV:href element of the property", so an href further down inside the value
 * belongs to whatever holds it and not to the property — the same distinction
 * `DAV:acl-principal-prop-set` has to make for `DAV:inherited` (§5.5.2).
 *
 * **Without `DAV:prop` a response is a bare `200`**, which is the shape
 * §9.3.1's own example answers with.
 */
final class PrincipalMatch
{
    private const PRINCIPAL_PROPERTY = '{DAV:}principal-property';

    private const ITSELF = '{DAV:}self';

    private const PROP = '{DAV:}prop';

    private const HREF = '{DAV:}href';

    /**
     * @param GroupResolver|null $groups What the asker counts as besides
     *                                   themselves (RFC 3744 §2). Null where
     *                                   a server keeps no principals — there
     *                                   are then no groups to be in, and the
     *                                   recursion is empty rather than
     *                                   missing
     */
    public function __construct(
        private readonly Server $server,
        private readonly ?GroupResolver $groups = null,
    ) {
    }

    /**
     * Reports the members of this collection that match whoever is asking.
     *
     * @throws BadRequest If the request asks for a depth this report has no
     *                    meaning at, or does not say what to match on
     */
    public function __invoke(Request $request, Element $asked): Response
    {
        ReportDepth::mustBeZero($request);

        $property = self::propertyMatchedOn($asked);
        $wanted = $asked->child(self::PROP)?->childNames() ?? [];
        $identities = $this->identitiesAsking($request);
        $report = new MultiStatus();

        foreach ($this->membersUnder($this->server->path($request)) as $path => $node) {
            if ($this->matches($request, $path, $node, $property, $identities)) {
                $this->describe($report, $path, $node, $wanted);
            }
        }

        return (new Response(207, body: $this->server->writer()->write($report->toElement())))
            ->withHeader('Content-Type', 'application/xml; charset=utf-8');
    }

    /**
     * One member that matched, as a `DAV:response`.
     *
     * §9.3: "If DAV:prop is specified in the request body, the properties
     * specified in the DAV:prop element MUST be reported in the DAV:response
     * elements." Where none are, §9.3.1's example gives the shape: the href
     * and `HTTP/1.1 200 OK`, which is also the only valid response there is
     * to give — RFC 4918 §14.24 has a `DAV:response` hold either propstats or
     * a status, and a body naming no properties leaves no propstat to hold.
     *
     * @param list<string> $wanted
     */
    private function describe(MultiStatus $report, string $path, INode $node, array $wanted): void
    {
        $href = $this->server->hrefOf($path, $node);

        if ($wanted === []) {
            $report->addStatus($href, 200);

            return;
        }

        $report->addProperties($href, $this->propertiesOf($path, $node, $wanted));
    }

    /**
     * Whether this member is one of the asker's.
     *
     * @param string|null $property What to look in, or null for `DAV:self`
     * @param list<string> $identities
     */
    private function matches(
        Request $request,
        string $path,
        INode $node,
        ?string $property,
        array $identities,
    ): bool {
        if ($property === null) {
            // §9.3, `DAV:self`: the member is itself a principal the asker
            // matches. Nothing else can be — the identities are principal
            // paths, so a page is never among them however it is named.
            return in_array($path, $identities, true);
        }

        $value = $this->propertiesOf($path, $node, [$property])[200][$property] ?? null;

        foreach ($this->principalsNamedIn($request, $value) as $principal) {
            if (in_array($principal, $identities, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The principals a property value names, as paths in this tree.
     *
     * **Only its own `DAV:href` children** (§9.3: "the DAV:href element of
     * the property"). A value holding an href one element further down is
     * holding somebody else's href — `DAV:acl` is full of them, and each
     * means something different.
     *
     * A property may name more than one, and then any of them matching is a
     * match: §9.3 expects "an href element" because `DAV:owner` holds one,
     * but a report reading only the first would answer differently depending
     * on the order somebody wrote them in.
     *
     * @return list<string>
     */
    private function principalsNamedIn(Request $request, Element|string|null $value): array
    {
        if (!$value instanceof Element) {
            return [];
        }

        $paths = [];

        foreach ($value->children() as $child) {
            if ($child->name() !== self::HREF) {
                continue;
            }

            $path = $this->pathOf($request, trim($child->text()));

            if ($path !== null) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * Everything the asker counts as, as paths (RFC 3744 §2).
     *
     * Nobody signed in counts as nobody — an empty list rather than a list
     * holding null, because there is no principal an unauthenticated request
     * matches (§5.5.1) and this report has nothing to say about `DAV:all`.
     *
     * @return list<string>
     */
    private function identitiesAsking(Request $request): array
    {
        $path = $this->server->events()->emit(new CurrentPrincipalRequested($request))->principal();

        if ($path === null) {
            return [];
        }

        return $this->groups === null ? [$path] : $this->groups->identitiesOf($path);
    }

    /**
     * Every member of this collection, at any depth (§9.3), keyed by path.
     *
     * The collection itself is not among them: §9.3 reports "all members ...
     * of the collection identified by the Request-URI", and §9.3.1 asks about
     * `/doc/` and answers with two resources inside it.
     *
     * A Request-URI that is not a collection has no members. That is an empty
     * answer rather than a refusal — §9.3 has it identify a collection, and a
     * client that named something else has made a mistake this report cannot
     * correct, while an error would say something about what is there.
     *
     * @return array<string, INode>
     */
    private function membersUnder(string $path): array
    {
        $node = $this->server->tree()->node($path);

        return $node instanceof ICollection ? $this->membersIn($path, $node) : [];
    }

    /**
     * @return array<string, INode>
     */
    private function membersIn(string $path, ICollection $collection): array
    {
        $members = [];

        foreach (VisibleMembers::of($this->server->events(), $path, $collection) as $member => $child) {
            $members[$member] = $child;

            if ($child instanceof ICollection) {
                $members += $this->membersIn($member, $child);
            }
        }

        return $members;
    }

    /**
     * The properties of one resource, as this server answers them — so that
     * a property a plugin answers, or refuses, is the one this report reads
     * (R-PROP-05).
     *
     * @param list<string> $names
     *
     * @return array<int, array<string, Element|string|null>>
     */
    private function propertiesOf(string $path, INode $node, array $names): array
    {
        return Answers::about($this->server->events(), $path, $node, PropFindForm::Named, $names)->byStatus();
    }

    /**
     * What the body says to match on, or null where it says `DAV:self`.
     *
     * `<!ELEMENT principal-match ((principal-property | self), prop?)>` — one
     * of the two, and a body with neither or with both is refused rather than
     * guessed at. The two say different things about the same resources, and
     * choosing between them would be answering a question the client did not
     * ask.
     *
     * @throws BadRequest If it says neither, both, or no property to look in
     */
    private static function propertyMatchedOn(Element $asked): ?string
    {
        $property = $asked->child(self::PRINCIPAL_PROPERTY);

        if (($property === null) === ($asked->child(self::ITSELF) === null)) {
            throw new BadRequest('A principal-match matches on either a principal-property or self.');
        }

        if ($property === null) {
            return null;
        }

        $names = $property->childNames();

        // §9.3 calls it "**the** property identified by the
        // DAV:principal-property element" throughout, and whether two of them
        // would mean both or either is not something it says.
        if (count($names) !== 1) {
            throw new BadRequest('A principal-property names the one property to look in.');
        }

        return $names[0];
    }

    /**
     * The path a principal URL names here, or null where it names none.
     *
     * A property may point at a principal on another server, and whether that
     * one is the person asking is not something this server can tell.
     */
    private function pathOf(Request $request, string $url): ?string
    {
        try {
            return $this->server->pathOfUrl($url, $request);
        } catch (IHttpFailure) {
            return null;
        }
    }
}
