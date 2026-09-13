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

use DavServices\Dav\Event\AfterCopy;
use DavServices\Dav\Event\BeforeCopy;
use DavServices\Event\IEvent;
use DavServices\Exception\BadRequest;
use DavServices\Http\Request;

/**
 * Answers `COPY`: the resource is made a second time somewhere else, and the
 * first one stays where it is (R-DAV-07).
 *
 * Everything about the destination is settled in {@see Transfer}, which `MOVE`
 * settles the same way. What is this method's own is the depth: RFC 4918
 * §9.8.3 allows `0` and `infinity` and nothing else. A copy one level deep is
 * not an operation this protocol has, and answering a `Depth: 1` as though it
 * had said `infinity` would quietly do something the client did not ask for.
 *
 * Registered like any other method:
 *
 *     $copy = new Copy($server);
 *     $server->onMethod('COPY', $copy(...));
 */
final class Copy extends Transfer
{
    /**
     * RFC 4918 §9.8.3: `0` and `infinity`, and nothing else.
     *
     * @throws BadRequest If it asked for anything else
     */
    protected function refuseADepthThisMethodDoesNotTake(Request $request): void
    {
        $depth = self::depthOf($request);

        if ($depth !== '0' && $depth !== 'infinity') {
            throw new BadRequest('A COPY goes 0 or infinity deep.');
        }
    }

    protected function before(string $from, string $to): IEvent
    {
        return new BeforeCopy($from, $to);
    }

    protected function after(string $from, string $to): IEvent
    {
        return new AfterCopy($from, $to);
    }

    protected function carryOut(Request $request, string $from, string $to): array
    {
        return $this->server->tree()->copy($from, $to, self::depthOf($request) !== '0');
    }
}
