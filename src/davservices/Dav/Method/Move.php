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

use DavServices\Dav\Event\AfterMove;
use DavServices\Dav\Event\BeforeMove;
use DavServices\Event\IEvent;
use DavServices\Exception\BadRequest;
use DavServices\Http\Request;

/**
 * Answers `MOVE`: the resource ends up somewhere else and is no longer where
 * it was (R-DAV-07).
 *
 * Everything about the destination is settled in {@see Transfer}, which `COPY`
 * settles the same way. Two things are this method's own.
 *
 * **A `MOVE` is always the whole thing** (RFC 4918 §9.9.2). There is no moving
 * a collection and leaving its members: they would be left with no address to
 * reach them by, which is the same reason a `DELETE` keeps the ancestors of
 * what it could not remove.
 *
 * **Nothing is removed until the copy has been made.** Where the backend
 * cannot move a node itself, the server copies and then removes — in that
 * order, because a removal before a copy that failed would have destroyed the
 * very thing the client was moving.
 *
 * Registered like any other method:
 *
 *     $move = new Move($server);
 *     $server->onMethod('MOVE', $move(...));
 */
final class Move extends Transfer
{
    /**
     * RFC 4918 §9.9.2: a `MOVE` acts as `Depth: infinity` and a client may
     * send nothing else.
     *
     * @throws BadRequest If it asked for any other depth
     */
    protected function refuseADepthThisMethodDoesNotTake(Request $request): void
    {
        if (self::depthOf($request) !== 'infinity') {
            throw new BadRequest('A MOVE takes what is below it with it.');
        }
    }

    protected function before(string $from, string $to): IEvent
    {
        return new BeforeMove($from, $to);
    }

    protected function after(string $from, string $to): IEvent
    {
        return new AfterMove($from, $to);
    }

    protected function carryOut(Request $request, string $from, string $to): array
    {
        return $this->server->tree()->move($from, $to);
    }
}
