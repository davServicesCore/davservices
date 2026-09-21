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

namespace DavServices\Dav\Precondition;

use DavServices\Dav\Event\StateTokensRequested;
use DavServices\Dav\IFile;
use DavServices\Dav\Server;
use DavServices\Exception\BadRequest;
use DavServices\Exception\DavException;
use DavServices\Exception\PreconditionFailed;
use DavServices\Http\ETag;
use DavServices\Http\IfHeader;
use DavServices\Http\Request;
use DavServices\Uri\MalformedPath;

/**
 * Holds a request to the conditions it set itself (RFC 4918 §10.4,
 * R-HTTP-07).
 *
 * **This is core WebDAV and not part of locking**, which is why it is here
 * rather than in the lock plugin. An `If` header may name entity tags alone,
 * and those need no lock storage to check; a server assembled without the
 * lock plugin that ignored such a header would be dropping a guard a client
 * took pains to set. {@see \DavServices\Http\IfHeader} answers `400` to a
 * header it cannot read for exactly that reason, and silently ignoring one it
 * can read would be the same failure by another route.
 *
 * The difference from a method is worth stating, because it is what decides
 * where this lives. A method nobody registers is one the server refuses
 * honestly with `501`. A condition nobody evaluates is one the server
 * silently pretends to have honoured. The first may be a plugin; the second
 * may not.
 *
 * State tokens arrive through {@see StateTokensRequested}, so nothing here
 * knows what a lock is (R-ARC-02). With no listener there are no tokens, and
 * a condition naming one is false — which is the right answer rather than a
 * lenient one: a client that submitted a lock token to a server holding no
 * locks has claimed something untrue.
 *
 * **Fail closed throughout.** A resource tag naming another server, or a path
 * that cannot be resolved, yields a state that satisfies nothing: a condition
 * this server cannot check is not one it may call true.
 */
final class RequestConditions
{
    public function __construct(private readonly Server $server)
    {
    }

    /**
     * Refuses a request whose `If` header does not hold.
     *
     * @throws PreconditionFailed If no list of the header holds
     * @throws BadRequest If the header cannot be read
     */
    public function refuseWhatDoesNotHold(Request $request): void
    {
        $header = $request->headers()->first('If');

        if ($header === null) {
            return;
        }

        $stateOf = fn (?string $resource): ResourceState => $this->stateOf($resource, $request);

        if (!IfEvaluator::holds(IfHeader::parse($header), $stateOf)) {
            throw new PreconditionFailed('The If header of this request names a state this server is not in.');
        }
    }

    /**
     * What a client could have known about one resource: the state tokens
     * anything has named for it, and its entity tag.
     */
    private function stateOf(?string $resource, Request $request): ResourceState
    {
        try {
            $path = $resource === null
                ? $this->server->path($request)
                : $this->server->pathOfUrl($resource, $request);
        } catch (DavException | MalformedPath $elsewhere) {
            return new ResourceState([], null);
        }

        return new ResourceState($this->tokensOn($path), $this->etagOf($path));
    }

    /**
     * @return list<string>
     */
    private function tokensOn(string $path): array
    {
        return $this->server->events()->emit(new StateTokensRequested($path))->tokens();
    }

    /**
     * The entity tag of what is at the path, where there is something there
     * and it has one.
     */
    private function etagOf(string $path): ?ETag
    {
        try {
            $node = $this->server->tree()->node($path);
        } catch (DavException $nothingThere) {
            // The path came through `pathOf()` already, so it cannot be a
            // malformed one here: what is caught is "there is nothing there".
            return null;
        }

        $etag = $node instanceof IFile ? $node->etag() : null;

        return $etag === null ? null : ETag::parse($etag);
    }
}
