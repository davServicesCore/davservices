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
 * The request was understood and is refused.
 *
 * RFC 4918 §9.1 uses it for a `PROPFIND` with `Depth: infinity` where the
 * server does not offer one, RFC 3744 §7.1.1 for a missing privilege.
 */
final class Forbidden extends DavException
{
    protected const STATUS = 403;
}
