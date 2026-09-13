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
use DavServices\Dav\PropFindResult;
use DavServices\Event\Event;

/**
 * Raised for every resource a `PROPFIND` reports on, before the node is asked.
 *
 * This is where live properties come from. The server computes none of its own
 * yet; `DAV:resourcetype`, `DAV:getcontentlength` and the rest arrive as
 * listeners on this event, and so do the privileges of the access-control
 * layer and the quota of a backend that keeps one.
 *
 * **Before the node**, and the first answer for a property stands (R-PROP-05).
 * That order is the whole point: access control has to be able to refuse a
 * property with `403` that the node would gladly hand over, and it can only do
 * that by answering first.
 *
 * A listener asks {@see PropFindResult::wants()} before it works anything out.
 * Under `DAV:propname` the values are dropped, so there is nothing to gain by
 * computing them — {@see PropFindResult::form()} says so.
 */
final class PropertiesRequested extends Event
{
    public function __construct(
        private readonly PropFindResult $result,
        private readonly INode $node,
    ) {
    }

    /**
     * What has been answered so far, and what is being asked for.
     */
    public function result(): PropFindResult
    {
        return $this->result;
    }

    /**
     * The node the properties are being asked about.
     */
    public function node(): INode
    {
        return $this->node;
    }
}
