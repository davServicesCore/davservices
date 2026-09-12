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
 * The method is implemented, but it does not apply to this resource.
 *
 * A `MKCOL` on a path that is already bound is the usual case. The server adds
 * the `Allow` header, because only it knows what the resource does accept.
 */
final class MethodNotAllowed extends DavException
{
    protected const STATUS = 405;
}
