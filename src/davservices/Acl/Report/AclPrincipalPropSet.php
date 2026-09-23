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
 * Answers `DAV:acl-principal-prop-set`: who is in this list, by name
 * (RFC 3744 §9.2).
 *
 * **Support for this report is REQUIRED**, and §7.2 makes that binding on any
 * server announcing `access-control`: that value "MUST indicate that the
 * server supports all MUST level requirements and REQUIRED features specified
 * in this document".
 *
 * §9.2 says what it is for: "One expected use of this report is to retrieve
 * the human readable name (found in the DAV:displayname property) of each
 * principal found in an ACL. This is useful for constructing user interfaces
 * that show each ACE in a human readable form." Without it a client reading
 * `DAV:acl` holds a list of URLs and cannot put a name beside an entry
 * without one request per principal.
 *
 * Given to {@see \DavServices\Dav\Method\Report} like any other:
 *
 *     $report->on('{DAV:}acl-principal-prop-set', (new AclPrincipalPropSet($server))(...));
 *
 * ## Where the principals come from
 *
 * **From the `DAV:acl` property, not from the resolver.** This asks the
 * server the same question a client would, so there is one source of truth
 * about what the list says — and whatever access control decides about
 * reading that list decides it here too.
 *
 * The hrefs are taken from `ace → principal` in particular rather than from
 * wherever they appear. A `DAV:inherited` element holds an href as well
 * (§5.5.2), and that one names the collection an entry came from; a report
 * gathering every href in the value would report a collection as though
 * somebody had been granted something.
 *
 * ## Two rules the wording settles
 *
 * **A principal named twice is reported once**: "In the case where a
 * principal URL appears multiple times, the DAV:acl-principal-prop-set report
 * MUST return the properties for that principal only once." A list granting
 * read in one entry and write in another names the same person twice, and a
 * client drawing a row per response would draw the person twice.
 *
 * **Only `DAV:href` principals are reported.** §9.2's normative sentence
 * speaks of "each principal identified by an http(s) URL listed in a
 * DAV:principal XML element"; `DAV:all`, `DAV:authenticated` and the rest
 * (§5.5.1) name no principal resource, so there are no properties of theirs
 * to fetch.
 */
final class AclPrincipalPropSet
{
    private const ACL = '{DAV:}acl';

    private const ACE = '{DAV:}ace';

    private const PRINCIPAL = '{DAV:}principal';

    private const HREF = '{DAV:}href';

    /**
     * @param int $atMost How many principals will be reported before the
     *                    report is refused (§9.2,
     *                    `DAV:number-of-matches-within-limits`). An access
     *                    control list on a busy collection can name a great
     *                    many people
     */
    public function __construct(
        private readonly Server $server,
        private readonly int $atMost = 250,
    ) {
    }

    /**
     * Reports the named properties of everybody the list names.
     *
     * The body is `<!ELEMENT acl-principal-prop-set ANY>` with "at most one
     * DAV:prop element", so a body naming no properties asks who is in the
     * list and nothing about them — which is an answer worth having.
     *
     * @throws BadRequest If the request asks for a depth this report has no
     *                    meaning at
     * @throws Forbidden If the list names more principals than will be
     *                   reported
     */
    public function __invoke(Request $request, Element $asked): Response
    {
        ReportDepth::mustBeZero($request);

        $wanted = $asked->child('{DAV:}prop')?->childNames() ?? [];
        $report = new MultiStatus();

        foreach ($this->principalsListedAt($this->server->path($request), $request) as $path) {
            $this->describe($report, $path, $wanted);
        }

        return (new Response(207, body: $this->server->writer()->write($report->toElement())))
            ->withHeader('Content-Type', 'application/xml; charset=utf-8');
    }

    /**
     * One principal, as a `DAV:response`.
     *
     * A principal the list names and this server no longer has is reported
     * as missing rather than left out: the report exists to describe a list
     * to a person, and "this entry points at somebody who is gone" is exactly
     * what an administrator needs to see.
     *
     * @param list<string> $wanted
     */
    private function describe(MultiStatus $report, string $path, array $wanted): void
    {
        $tree = $this->server->tree();

        if (!$tree->exists($path)) {
            $report->addStatus($this->server->href($path), 404);

            return;
        }

        $node = $tree->node($path);

        $report->addProperties(
            $this->server->hrefOf($path, $node),
            Answers::about($this->server->events(), $path, $node, PropFindForm::Named, $wanted)->byStatus(),
        );
    }

    /**
     * The principals the `DAV:acl` of a path names, each once and in order.
     *
     * @throws Forbidden If there are more of them than will be reported
     *
     * @return list<string>
     */
    private function principalsListedAt(string $path, Request $request): array
    {
        $tree = $this->server->tree();
        $node = $tree->node($path);
        $acl = Answers::about($this->server->events(), $path, $node, PropFindForm::Named, [self::ACL])
            ->byStatus()[200][self::ACL] ?? null;

        if (!$acl instanceof Element) {
            return [];
        }

        $principals = [];

        foreach (self::principalHrefsIn($acl) as $url) {
            $principal = $this->pathOf($request, $url);

            // §9.2: "MUST return the properties for that principal only
            // once", however many entries name them.
            if ($principal !== null && !in_array($principal, $principals, true)) {
                $principals[] = $principal;
            }
        }

        if (count($principals) > $this->atMost) {
            throw new Forbidden(
                sprintf('This list names more principals than the %d this server reports at once.', $this->atMost),
                '{DAV:}number-of-matches-within-limits',
            );
        }

        return $principals;
    }

    /**
     * Every `ace → principal → href`, in the order the list gives them.
     *
     * **Not every href in the value**: §5.5.2's `DAV:inherited` holds one
     * too, and it names a collection rather than a person.
     *
     * @return list<string>
     */
    private static function principalHrefsIn(Element $acl): array
    {
        $urls = [];

        foreach ($acl->children() as $ace) {
            if ($ace->name() !== self::ACE) {
                continue;
            }

            $href = $ace->child(self::PRINCIPAL)?->child(self::HREF);

            if ($href !== null) {
                $urls[] = trim($href->text());
            }
        }

        return $urls;
    }

    /**
     * The path a principal URL names here, or null where it names none.
     *
     * A list may point at a principal on another server, and there are no
     * properties of that one this server could report.
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
