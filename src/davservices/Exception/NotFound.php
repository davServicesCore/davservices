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

namespace DavServices\Exception;

/**
 * Nothing is bound to that path — or the caller may not learn that something is.
 *
 * A deployment may prefer this over a `403` so that a collection cannot be
 * probed for the names it holds.
 */
final class NotFound extends DavException
{
    protected const STATUS = 404;
}
