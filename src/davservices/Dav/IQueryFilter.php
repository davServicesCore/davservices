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
 * What a report asks a collection to filter by.
 *
 * The filters themselves belong to the protocols that define them — the
 * `calendar-query` of CalDAV, the `addressbook-query` of CardDAV — and those
 * live above this layer. This is the shape they take when they are handed
 * down to a backend, so that a collection can be asked to do the filtering
 * without the tree having to know what a calendar is.
 */
interface IQueryFilter
{
}
