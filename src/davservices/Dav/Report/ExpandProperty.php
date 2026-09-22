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

namespace DavServices\Dav\Report;

use DavServices\Dav\Event\ListingMembers;
use DavServices\Dav\INode;
use DavServices\Dav\Property\Answers;
use DavServices\Dav\PropFindForm;
use DavServices\Dav\ReportDepth;
use DavServices\Dav\Server;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use DavServices\Exception\IHttpFailure;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Xml\Element;
use DavServices\Xml\MultiStatus;

/**
 * Answers `DAV:expand-property`: one request instead of a chain of them
 * (RFC 3253 §3.8).
 *
 * **Half the properties in this protocol family hold `DAV:href` elements**,
 * and a client that wants to show anything about what they point at has to
 * fetch each one: a principal's `DAV:group-membership`, then a `PROPFIND` per
 * group for its name. This report does it in one — it "not only decreases the
 * number of requests required, but also allows the server to minimize the
 * number of separate read transactions".
 *
 * Given to {@see \DavServices\Dav\Method\Report} like any other:
 *
 *     $report->on('{DAV:}expand-property', (new ExpandProperty($server))(...));
 *
 * **The body does not look like a `DAV:prop`.** It is `DAV:property`
 * elements carrying attributes — `name` required, `namespace` defaulting to
 * `DAV:` — and nesting them is what asks for the expansion. The nesting can
 * go on: "multiple levels of DAV:href expansion can be requested".
 *
 * **Replacement means replacement.** Every `DAV:href` in the value goes and a
 * whole `DAV:response` takes its place, inside the property. §3.8 puts it
 * plainly: the report "effectively modifies the DTD of every property by
 * replacing every occurrence of `href` in the DTD with `href | response`".
 *
 * ## Three things it will not do
 *
 * **An href it cannot resolve here stays an href.** A `mailto:` in a
 * `DAV:alternate-URI-set` is an href and is not a resource on this server; so
 * is a link to another host, and so is a reference to something that has
 * since gone. Leaving such an href alone loses nothing — the client sees what
 * it would have seen without the report — while a `404` response in its place
 * would be an answer about a resource this server was never asked about.
 *
 * **What the asker may not read is not expanded.** An expanded href reaches a
 * resource that neither the request guard nor a collection listing covers, so
 * without a check here this report would hand over the properties of
 * resources a `PROPFIND` would have refused (R-ACL-06). The question goes out
 * as {@see ListingMembers} — the same question the same listener already
 * answers for a collection — and it goes out for **all** the hrefs of a value
 * at once (R-PRIV-01).
 *
 * **It will not expand without limit.** At *n* hrefs a level and *d* levels
 * of nesting there are *n^d* responses, and the client chooses *d* by how
 * deep it nests. The budget is spent **before** each expansion rather than
 * counted afterwards, so it bounds the work and not merely the answer.
 *
 * **`Depth` other than `0` is refused, and that is this server's limit rather
 * than the specification's.** RFC 3253 §3.6 gives a report with a `Depth`
 * header a meaning — it is applied to the collection and to its members —
 * which multiplies exactly the fan-out this report already has to hold down.
 * A seam to open when there is a client that wants it; a missing header means
 * `0`, which is what every client sends.
 */
final class ExpandProperty
{
    private const PROPERTY = '{DAV:}property';

    private const HREF = '{DAV:}href';

    /**
     * @param int $atMost How many resources will be expanded into one answer
     *                    before the report is refused. A group of a hundred
     *                    members nested three deep is a million responses
     *                    unless somebody says otherwise
     */
    public function __construct(
        private readonly Server $server,
        private readonly int $atMost = 250,
    ) {
    }

    /**
     * Reports the properties that were named, with their hrefs expanded.
     *
     * @throws BadRequest If the depth or the body is not one this report has
     * @throws Forbidden If more would be expanded than will be answered
     */
    public function __invoke(Request $request, Element $asked): Response
    {
        ReportDepth::mustBeZero($request);
        self::refuseWhatNamesNothing($asked);

        $path = $this->server->path($request);
        $node = $this->server->tree()->node($path);
        $budget = $this->atMost;

        $report = new MultiStatus();

        $report->addProperties(
            $this->server->hrefOf($path, $node),
            $this->answersFor($request, $path, $node, self::propertiesIn($asked), $budget),
        );

        return (new Response(207, body: $this->server->writer()->write($report->toElement())))
            ->withHeader('Content-Type', 'application/xml; charset=utf-8');
    }

    /**
     * What this server says about one resource, with the nested asks honoured.
     *
     * @param list<Element> $wanted
     * @param int $budget How many more resources may be expanded
     *
     * @return array<int, array<string, Element|string|null>>
     */
    private function answersFor(Request $request, string $path, INode $node, array $wanted, int &$budget): array
    {
        $names = array_map(self::nameOf(...), $wanted);
        $byStatus = Answers::about($this->server->events(), $path, $node, PropFindForm::Named, $names)->byStatus();

        foreach ($wanted as $property) {
            $nested = self::propertiesIn($property);
            $value = $byStatus[200][self::nameOf($property)] ?? null;

            // Only a property that was asked to be expanded, and that holds
            // XML to expand: text has no hrefs in it.
            if ($nested !== [] && $value instanceof Element) {
                $byStatus[200][self::nameOf($property)] = $this->expanded($request, $path, $value, $nested, $budget);
            }
        }

        return $byStatus;
    }

    /**
     * The value again, with every href that may be expanded replaced.
     *
     * The hrefs are gathered from the whole value first and asked about in
     * one go, so that a property holding two hundred of them is one question
     * rather than two hundred.
     *
     * @param list<Element> $nested
     *
     */
    private function expanded(Request $request, string $path, Element $value, array $nested, int &$budget): Element
    {
        return $this->rebuilt(
            $request,
            $value,
            $this->whatMayBeExpanded($request, $path, self::hrefsIn($value)),
            $nested,
            $budget,
        );
    }

    /**
     * @param array<string, string> $paths The path behind each href that is
     *                                     to be expanded, by the URL it was
     *                                     written as
     * @param list<Element> $nested
     */
    private function rebuilt(
        Request $request,
        Element $value,
        array $paths,
        array $nested,
        int &$budget,
    ): Element {
        $copy = new Element($value->name(), $value->attributes());

        $copy->appendText($value->text());

        foreach ($value->children() as $child) {
            if ($child->name() !== self::HREF) {
                $copy->append($this->rebuilt($request, $child, $paths, $nested, $budget));

                continue;
            }

            $path = $paths[trim($child->text())] ?? null;

            $copy->append($path === null ? $child : $this->responseFor($request, $path, $nested, $budget));
        }

        return $copy;
    }

    /**
     * Which of these hrefs name a resource here that the asker may see.
     *
     * @param list<string> $urls
     *
     * @return array<string, string>
     */
    private function whatMayBeExpanded(Request $request, string $path, array $urls): array
    {
        $paths = [];

        foreach ($urls as $url) {
            $here = $this->pathOf($request, $url);

            if ($here !== null) {
                $paths[$url] = $here;
            }
        }

        if ($paths === []) {
            return [];
        }

        $listing = $this->server->events()->emit(new ListingMembers($path, array_values($paths)));
        $visible = array_flip($listing->visible());

        return array_filter($paths, static fn (string $here): bool => isset($visible[$here]));
    }

    /**
     * The path an href names here, or null where it names nothing of ours.
     *
     * A `mailto:`, another host, a reference that has gone stale — all of
     * them are hrefs this server cannot report on, and all of them are left
     * as they are.
     */
    private function pathOf(Request $request, string $url): ?string
    {
        try {
            $path = $this->server->pathOfUrl($url, $request);
        } catch (IHttpFailure) {
            return null;
        }

        return $this->server->tree()->exists($path) ? $path : null;
    }

    /**
     * One expansion: the response that takes an href's place.
     *
     * @param list<Element> $nested
     *
     * @throws Forbidden If the answer has grown as large as it may
     */
    private function responseFor(Request $request, string $path, array $nested, int &$budget): Element
    {
        if ($budget < 1) {
            throw new Forbidden(
                sprintf('This report expands at most %d resources into one answer.', $this->atMost),
                // RFC 3253 §3.8 names no condition for this. The one borrowed
                // here is RFC 3744 §9.4's, which says exactly this and which
                // clients already read; inventing a name would be worse.
                '{DAV:}number-of-matches-within-limits',
            );
        }

        --$budget;

        $node = $this->server->tree()->node($path);

        return MultiStatus::responseWith(
            $this->server->hrefOf($path, $node),
            $this->answersFor($request, $path, $node, $nested, $budget),
        );
    }

    /**
     * Every href anywhere in a value, in the order they stand.
     *
     * @return list<string>
     */
    private static function hrefsIn(Element $value): array
    {
        $urls = [];

        foreach ($value->children() as $child) {
            if ($child->name() === self::HREF) {
                $urls[] = trim($child->text());

                continue;
            }

            $urls = [...$urls, ...self::hrefsIn($child)];
        }

        return $urls;
    }

    /**
     * The `DAV:property` elements directly inside this one.
     *
     * @return list<Element>
     */
    private static function propertiesIn(Element $element): array
    {
        $properties = [];

        foreach ($element->children() as $child) {
            if ($child->name() === self::PROPERTY) {
                $properties[] = $child;
            }
        }

        return $properties;
    }

    /**
     * §3.8 has `name` as `#REQUIRED`, and the whole body is looked over
     * before any of it is answered: a nameless property deeper down would
     * otherwise be complained about only if the expansion happened to reach
     * it, so the same body would be refused or answered depending on what the
     * resources held.
     *
     * @throws BadRequest If some property names nothing
     */
    private static function refuseWhatNamesNothing(Element $element): void
    {
        foreach (self::propertiesIn($element) as $property) {
            self::nameOf($property);
            self::refuseWhatNamesNothing($property);
        }
    }

    /**
     * §3.8: the name is an attribute, and the namespace is another that
     * defaults to `DAV:`.
     *
     * @throws BadRequest If the property names nothing
     */
    private static function nameOf(Element $property): string
    {
        $name = $property->attribute('name');

        if ($name === null) {
            throw new BadRequest('A DAV:property in an expand-property says which property it means.');
        }

        return sprintf('{%s}%s', $property->attribute('namespace') ?? 'DAV:', $name);
    }
}
