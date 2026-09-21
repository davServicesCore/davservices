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

use DavServices\Acl\SearchableProperty;
use DavServices\Dav\ICollection;
use DavServices\Dav\INode;
use DavServices\Dav\Property\Answers;
use DavServices\Dav\PropFindForm;
use DavServices\Dav\PropFindResult;
use DavServices\Dav\ReportDepth;
use DavServices\Dav\Server;
use DavServices\Dav\VisibleMembers;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Xml\Element;
use DavServices\Xml\MultiStatus;

/**
 * Answers `DAV:principal-property-search`: who is this (RFC 3744 §9.4).
 *
 * **This is how a client finds a person.** It is what happens when somebody
 * types three letters into the invitation field of a calendar client — §9.4
 * says so itself: "one expected use of this report is to discover the URL of
 * a principal associated with a given person or group by searching for them
 * by name".
 *
 * Given to {@see \DavServices\Dav\Method\Report} like any other:
 *
 *     $report->on(
 *         '{DAV:}principal-property-search',
 *         (new PrincipalPropertySearch($server, SearchableProperty::standard()))(...),
 *     );
 *
 * ## The matching
 *
 * **What counts as a match is the server's to choose** (§9.4), because the
 * people usually live in somebody else's directory and an LDAP attribute
 * already has its own answer. Where nothing constrains it, §9.4 names the
 * preferred default and that is what this does: **caseless substring**, over
 * each contiguous piece of text in the value (§9.4.1) — so a string that runs
 * from the end of one `DAV:href` into the start of the next matches nothing,
 * because it was never in either of them.
 *
 * **The logic, though, is fixed: everything is AND.** Several
 * `DAV:property-search` elements, and several properties inside one
 * `DAV:prop`, all have to match. RFC 3744 has no `test` attribute — that
 * belongs to a CalDAV extension, and honouring one here would answer a
 * question the client did not ask.
 *
 * ## Why the values are not fetched from the backend
 *
 * They are assembled the way a `PROPFIND` assembles them ({@see Answers}),
 * and that is the whole reason `IPrincipalBackend` has no `search()`. **Not
 * every searchable property belongs to the backend:** the one CalDAV clients
 * really search by comes from a plugin, and dead properties come from the
 * property storage. A search that asked the backend could only answer for the
 * backend's own, would need this path for the rest anyway, and would then
 * have two places deciding what "matches" means.
 *
 * It also settles what a client may not see. A member that was concealed is
 * not searched, and a property answered with `403` does not match — an href
 * in the answer is a statement that the resource exists, with or without
 * properties beside it.
 *
 * ## Three refusals
 *
 * A search **without a `DAV:match`**, or naming **no property**, is refused
 * rather than read as an empty search: the empty string is a substring of
 * every value, so the lenient reading would hand the whole directory to a
 * client that sent a malformed body.
 *
 * A property **this server does not search** matches nobody (§9.4), so the
 * list `DAV:principal-search-property-set` publishes is the list this report
 * honours. Otherwise the first report would be a promise the second breaks.
 *
 * And **too many matches is refused** with
 * `DAV:number-of-matches-within-limits`, the postcondition §9.4 names — so
 * the limit arrives under a name clients already understand rather than an
 * invented one.
 */
final class PrincipalPropertySearch
{
    private const PROPERTY_SEARCH = '{DAV:}property-search';

    private const PROP = '{DAV:}prop';

    private const MATCH = '{DAV:}match';

    private const APPLY_TO_COLLECTION_SET = '{DAV:}apply-to-principal-collection-set';

    private const COLLECTION_SET = '{DAV:}principal-collection-set';

    /**
     * @param list<SearchableProperty> $searchable What may be searched at all
     *                                             — the same list
     *                                             {@see PrincipalSearchPropertySet}
     *                                             publishes, because a client
     *                                             chooses from it
     * @param int $atMost How many matches will be reported before the search
     *                    is refused (§9.4,
     *                    `DAV:number-of-matches-within-limits`). A directory
     *                    of a hundred thousand people answers a search for
     *                    "a" with all of them unless somebody says otherwise
     */
    public function __construct(
        private readonly Server $server,
        private readonly array $searchable,
        private readonly int $atMost = 250,
    ) {
    }

    /**
     * Finds the principals whose properties hold what was asked for.
     *
     * @throws BadRequest If the depth or the body is not one this report has
     * @throws Forbidden If more principals matched than will be reported
     */
    public function __invoke(Request $request, Element $asked): Response
    {
        ReportDepth::mustBeZero($request);

        $searches = self::searchesIn($asked);
        $wanted = $asked->child(self::PROP)?->childNames() ?? [];
        $report = new MultiStatus();

        foreach ($this->matchesFor($request, $asked, $searches) as $path => $node) {
            $report->addProperties(
                $this->server->hrefOf($path, $node),
                $this->propertiesOf($path, $node, $wanted)->byStatus(),
            );
        }

        return (new Response(207, body: $this->server->writer()->write($report->toElement())))
            ->withHeader('Content-Type', 'application/xml; charset=utf-8');
    }

    /**
     * Everyone who matched, keyed by path.
     *
     * @param list<array{names: list<string>, text: string}> $searches
     *
     * @throws Forbidden If more matched than will be reported
     *
     * @return array<string, INode>
     */
    private function matchesFor(Request $request, Element $asked, array $searches): array
    {
        if (!$this->searchesOnlyWhatItSearches($searches)) {
            // §9.4: a property that is not searchable matches nobody, and
            // every search has to match — so there is nothing to look for.
            return [];
        }

        $matches = [];

        foreach ($this->whereToSearch($request, $asked) as $path) {
            $matches += $this->matchesUnder($path, $searches);
        }

        if (count($matches) > $this->atMost) {
            throw new Forbidden(
                sprintf('This search matched more principals than the %d this server reports at once.', $this->atMost),
                '{DAV:}number-of-matches-within-limits',
            );
        }

        return $matches;
    }

    /**
     * The collections the search runs over.
     *
     * By default the one at the request target. With
     * `DAV:apply-to-principal-collection-set` it is instead each collection
     * that target's `DAV:principal-collection-set` names (§9.4) — which is
     * how a client searches everybody without knowing where the principals
     * are mounted.
     *
     * @return list<string>
     */
    private function whereToSearch(Request $request, Element $asked): array
    {
        $path = $this->server->path($request);

        if ($asked->child(self::APPLY_TO_COLLECTION_SET) === null) {
            return [$path];
        }

        $node = $this->server->tree()->node($path);
        $set = $this->propertiesOf($path, $node, [self::COLLECTION_SET])->byStatus()[200][self::COLLECTION_SET] ?? null;

        if (!$set instanceof Element) {
            return [];
        }

        $paths = [];

        foreach ($set->children() as $href) {
            $paths[] = $this->server->pathOfUrl($href->text(), $request);
        }

        return $paths;
    }

    /**
     * Everyone under this path who matched, at any depth (§9.4).
     *
     * A path that holds nothing, or holds something that is not a collection,
     * has no members to search. That is an empty answer rather than a
     * refusal: §9.4 has the Request-URI identify a collection, and a client
     * that named something else has made a mistake this report cannot
     * correct — while an error would say something about what is there.
     *
     * @param list<array{names: list<string>, text: string}> $searches
     *
     * @return array<string, INode>
     */
    private function matchesUnder(string $path, array $searches): array
    {
        $tree = $this->server->tree();

        if (!$tree->exists($path)) {
            return [];
        }

        $node = $tree->node($path);

        return $node instanceof ICollection ? $this->matchesIn($path, $node, $searches) : [];
    }

    /**
     * @param list<array{names: list<string>, text: string}> $searches
     *
     * @return array<string, INode>
     */
    private function matchesIn(string $path, ICollection $collection, array $searches): array
    {
        $matches = [];

        foreach (VisibleMembers::of($this->server->events(), $path, $collection) as $member => $child) {
            if ($this->holds($member, $child, $searches)) {
                $matches[$member] = $child;
            }

            if ($child instanceof ICollection) {
                $matches += $this->matchesIn($member, $child, $searches);
            }
        }

        return $matches;
    }

    /**
     * Whether this resource satisfies every search, which is what AND means.
     *
     * @param list<array{names: list<string>, text: string}> $searches
     */
    private function holds(string $path, INode $node, array $searches): bool
    {
        $values = $this->propertiesOf($path, $node, self::namesSearched($searches))->byStatus()[200] ?? [];

        foreach ($searches as $search) {
            foreach ($search['names'] as $name) {
                if (!self::found($search['text'], $values[$name] ?? null)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * The properties of one resource, as this server answers them.
     *
     * @param list<string> $names
     */
    private function propertiesOf(string $path, INode $node, array $names): PropFindResult
    {
        return Answers::about($this->server->events(), $path, $node, PropFindForm::Named, $names);
    }

    /**
     * §9.4: caseless substring, and §9.4.1: over each piece of text on its
     * own, so that a string spanning two of them is not a match.
     */
    private static function found(string $text, Element|string|null $value): bool
    {
        foreach (self::textsOf($value) as $piece) {
            // The encoding is said rather than left to `mb_internal_encoding()`:
            // both sides came out of the same XML document, and an application
            // that had set that global to something else would change what
            // this server finds without touching it.
            if (mb_stripos($piece, $text, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every contiguous piece of character data in a value (§9.4.1).
     *
     * @return list<string>
     */
    private static function textsOf(Element|string|null $value): array
    {
        if ($value === null) {
            return [];
        }

        if (!$value instanceof Element) {
            return [$value];
        }

        $texts = [$value->text()];

        foreach ($value->children() as $child) {
            $texts = [...$texts, ...self::textsOf($child)];
        }

        return $texts;
    }

    /**
     * What the body asks to be searched.
     *
     * @throws BadRequest If it asks for nothing, or for something malformed
     *
     * @return list<array{names: list<string>, text: string}>
     */
    private static function searchesIn(Element $asked): array
    {
        $searches = [];

        foreach ($asked->children() as $child) {
            if ($child->name() === self::PROPERTY_SEARCH) {
                $searches[] = self::searchIn($child);
            }
        }

        if ($searches === []) {
            throw new BadRequest('A principal-property-search holds at least one property-search.');
        }

        return $searches;
    }

    /**
     * One `DAV:property-search`: what to look in, and what to look for.
     *
     * @throws BadRequest If either half is missing
     *
     * @return array{names: list<string>, text: string}
     */
    private static function searchIn(Element $search): array
    {
        $names = $search->child(self::PROP)?->childNames() ?? [];
        $match = $search->child(self::MATCH);

        if ($names === [] || $match === null) {
            // Not pedantry: read leniently, a search with nothing to look
            // for would match every value there is, and the answer to a
            // malformed body would be the whole directory.
            throw new BadRequest('A property-search names a property to look in and a string to look for.');
        }

        return ['names' => $names, 'text' => $match->text()];
    }

    /**
     * §9.4: a property this server does not search matches nobody — and every
     * search has to match, so one such property empties the whole result.
     *
     * @param list<array{names: list<string>, text: string}> $searches
     */
    private function searchesOnlyWhatItSearches(array $searches): bool
    {
        $searchable = array_map(
            static fn (SearchableProperty $property): string => $property->name(),
            $this->searchable,
        );

        foreach (self::namesSearched($searches) as $name) {
            if (!in_array($name, $searchable, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every property any of the searches looks in, named once.
     *
     * @param list<array{names: list<string>, text: string}> $searches
     *
     * @return list<string>
     */
    private static function namesSearched(array $searches): array
    {
        $names = [];

        foreach ($searches as $search) {
            foreach ($search['names'] as $name) {
                if (!in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }
}
