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
 * A collection that can do a report's filtering itself.
 *
 * R-CAL-16: a `time-range` is to be narrowed by the backend, never by loading
 * every object of a calendar and looking at each. A collection that cannot do
 * this simply does not implement it, and the server filters in memory.
 */
interface IFilter
{
    /**
     * The names of the members that match.
     *
     * Names rather than nodes, so that a backend may answer from an index it
     * keeps for the purpose; the caller fetches what it needs afterwards,
     * through `IMultiGet` where the collection has it.
     *
     * @return list<string>
     */
    public function filteredChildNames(IQueryFilter $filter): array;
}
