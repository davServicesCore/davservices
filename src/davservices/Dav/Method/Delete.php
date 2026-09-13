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

use DavServices\Dav\Event\AfterUnbind;
use DavServices\Dav\Event\BeforeUnbind;
use DavServices\Dav\ICollection;
use DavServices\Dav\INode;
use DavServices\Dav\Server;
use DavServices\Exception\BadRequest;
use DavServices\Exception\IHttpFailure;
use DavServices\Exception\NotFound;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Uri\Path;
use DavServices\Xml\MultiStatus;

/**
 * Answers `DELETE`: a node goes, and a collection goes with everything below
 * it (R-DAV-06).
 *
 * One rule shapes the whole method, and it is RFC 4918 §9.6.1: **a member that
 * cannot be deleted keeps all of its ancestors.** A server that removed the
 * collection anyway would leave that member in the backend with no address to
 * reach it by — data that is still stored, still charged for, and can never be
 * got at again. So the removal works from the deepest member upwards, and a
 * collection is removed only once everything in it has gone.
 *
 * That rule is also why the walk happens here rather than in the backend. A
 * backend asked to delete a whole collection can say *that* something went
 * wrong, but never *which* member it was — and the answer has to name it. What
 * stayed is reported as a `207` with one `DAV:response` per path; what went is
 * deliberately left out of it, because a removal that worked needs no
 * explanation and listing every one would make the answer as long as the tree.
 *
 * A refusal of the request target itself is a plain status instead: a `207`
 * exists to say something about a *second* resource, and where the client
 * asked about one resource and was refused, the status alone says it.
 *
 * Registered like any other method:
 *
 *     $delete = new Delete($server);
 *     $server->onMethod('DELETE', $delete(...));
 */
final class Delete
{
    public function __construct(private readonly Server $server)
    {
    }

    /**
     * Removes what the request names, and reports what would not go.
     *
     * @throws NotFound If there is nothing at the path
     * @throws BadRequest If a collection is asked for at any depth but infinity
     * @throws IHttpFailure Whatever the target itself was refused with
     */
    public function __invoke(Request $request): Response
    {
        $path = $this->server->path($request);
        $node = $this->server->tree()->node($path);

        $this->refuseAnyDepthButInfinity($request, $node);

        $failures = $this->remove($path, $node);

        // Forgetting the collection this hung in drops the node and everything
        // that was below it as well, because a path is forgotten with its
        // subtree. The root has no collection above it: forgetting it is
        // forgetting all.
        $this->server->tree()->forget($path === '' ? '' : Path::split($path)[0]);

        return $this->answer($path, $failures);
    }

    /**
     * RFC 4918 §9.6.1: `DELETE` on a collection acts as though `Depth:
     * infinity` had been sent, and a client may not ask for anything else. One
     * that sends `0` believes it is removing the collection on its own, and
     * has to be told that no such operation exists rather than have its
     * members removed behind its back.
     *
     * A file has nothing below it, so `0` and `infinity` say the same thing
     * about one. Refusing a header that cannot mean anything else would break
     * clients for nothing.
     *
     * @throws BadRequest If a collection is asked for at any other depth
     */
    private function refuseAnyDepthButInfinity(Request $request, INode $node): void
    {
        $depth = $request->headers()->first('Depth');

        if (!$node instanceof ICollection || $depth === null) {
            return;
        }

        // RFC 5234 §2.3: what a grammar spells out in letters is matched
        // without regard to case.
        if (strtolower($depth) !== 'infinity') {
            throw new BadRequest('A collection is only ever deleted whole.');
        }
    }

    /**
     * Removes the node and everything below it, deepest first.
     *
     * @return array<string, IHttpFailure> What would not go, by path
     */
    private function remove(string $path, INode $node): array
    {
        try {
            $failures = $this->removeMembersOf($path, $node);

            if ($failures !== []) {
                // RFC 4918 §9.6.1: what stays keeps its ancestors, or it is
                // left in the tree with nothing addressing it any more.
                return $failures;
            }

            $this->removeOne($path, $node);

            return [];
        } catch (IHttpFailure $refusal) {
            // Either the collection would not say what it holds, or the node
            // itself would not go. Both leave this path where it was.
            return [$path => $refusal];
        }
    }

    /**
     * @throws IHttpFailure If the collection will not say what it holds
     *
     * @return array<string, IHttpFailure> What would not go, by path
     */
    private function removeMembersOf(string $path, INode $node): array
    {
        if (!$node instanceof ICollection) {
            return [];
        }

        $failures = [];

        foreach ($node->children() as $child) {
            $failures += $this->remove(Path::join($path, $child->name()), $child);
        }

        return $failures;
    }

    /**
     * @throws IHttpFailure If a listener or the backend will not have it
     */
    private function removeOne(string $path, INode $node): void
    {
        $this->server->events()->emit(new BeforeUnbind($path));

        $node->delete();

        $this->server->events()->emit(new AfterUnbind($path));
    }

    /**
     * @param array<string, IHttpFailure> $failures
     *
     * @throws IHttpFailure Where the request target itself was refused
     */
    private function answer(string $path, array $failures): Response
    {
        if ($failures === []) {
            return new Response(204);
        }

        // The target is in this list only where it was refused itself, and
        // then it is the only thing in it: a member that stayed keeps its
        // ancestors, so the target is never even attempted.
        $refusalOfTheTarget = $failures[$path] ?? null;

        if ($refusalOfTheTarget !== null) {
            throw $refusalOfTheTarget;
        }

        $report = new MultiStatus();

        foreach ($failures as $failed => $refusal) {
            $report->addStatus(
                $this->server->href($failed),
                $refusal->status(),
                $refusal->errorElement(),
                self::reason($refusal),
            );
        }

        return (new Response(207, body: $this->server->writer()->write($report->toElement())))
            ->withHeader('Content-Type', 'application/xml; charset=utf-8');
    }

    /**
     * What a person reads where the status alone does not explain itself.
     *
     * A refusal that came without a message says nothing rather than saying
     * nothing at length: an empty `DAV:responsedescription` is noise in an
     * answer a client is meant to act on.
     */
    private static function reason(IHttpFailure $refusal): ?string
    {
        $message = $refusal->getMessage();

        return $message === '' ? null : $message;
    }
}
