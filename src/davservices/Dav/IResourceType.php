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

namespace DavServices\Dav;

/**
 * A node that knows what kind of resource it is.
 *
 * `DAV:resourcetype` is the property a client reads to tell a calendar from a
 * folder and a principal from a file, and **most of it cannot be worked out
 * from the outside**. The server can see that a node is a collection; that a
 * collection is a calendar (RFC 4791) or that a resource is a principal
 * (RFC 3744 §4) is something only the node knows.
 *
 * It is said here rather than through the node's own properties because of
 * the order `PROPFIND` keeps: listeners answer before the node is asked, and
 * `DAV:resourcetype` is answered by one of them (R-PROP-01). A node that
 * tried to answer it itself would never be reached.
 *
 * What is named here is **added** to what the server could tell by itself, so
 * a collection that is also a calendar says only `{urn:ietf:params:xml:ns:caldav}calendar`
 * and still comes back as both.
 */
interface IResourceType
{
    /**
     * The kinds this resource is, beyond being a collection or not.
     *
     * @return list<string> As `{namespace}localname`, in the order they are
     *                      to appear
     */
    public function resourceTypes(): array;
}
