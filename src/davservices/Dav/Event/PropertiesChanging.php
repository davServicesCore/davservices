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

namespace DavServices\Dav\Event;

use DavServices\Dav\INode;
use DavServices\Dav\PropPatchResult;
use DavServices\Event\Event;

/**
 * Raised for a `PROPPATCH` before anything at all is written — the `propPatch`
 * extension point of R-ARC-04.
 *
 * A listener does one of two things here. It **refuses** a property it will
 * not have changed, with {@see PropPatchResult::set()}; or it **takes one on**
 * with {@see PropPatchResult::willWrite()}, saying that it, and not the node,
 * is the one that keeps it — which is how a storage for dead properties holds
 * what a node has nowhere to put.
 *
 * Nobody writes while this is being answered, and that is the point. A
 * `PROPPATCH` is all or nothing (R-DAV-05), and the only way to keep that
 * promise across storages that cannot share a transaction is to settle every
 * refusal before the first change is made.
 */
final class PropertiesChanging extends Event
{
    public function __construct(
        private readonly PropPatchResult $result,
        private readonly INode $node,
    ) {
    }

    /**
     * What is to be changed, and what has been said about it so far.
     */
    public function result(): PropPatchResult
    {
        return $this->result;
    }

    /**
     * The node whose properties are being changed.
     */
    public function node(): INode
    {
        return $this->node;
    }
}
