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
 * A collection that can fetch several of its members in one go.
 *
 * The reports of CalDAV and CardDAV ask for a hundred objects at a time, and a
 * backend that answered them one by one would make a hundred round trips of
 * what is one query (R-BE-02).
 */
interface IMultiGet
{
    /**
     * The members of these names that exist.
     *
     * A name that is not there is left out rather than reported: the caller is
     * a multiget, and RFC 4918 §9.6 has it answer `404` per href, which the
     * server does from what is missing here.
     *
     * @param list<string> $names
     *
     * @return list<INode>
     */
    public function multipleChildren(array $names): array;
}
