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
 * The `Range` header asks for bytes the resource does not have.
 *
 * RFC 9110 §15.5.17. The answer carries a `Content-Range` naming the size that
 * does exist, so that the client can ask again.
 */
final class RangeNotSatisfiable extends DavException
{
    protected const STATUS = 416;
}
