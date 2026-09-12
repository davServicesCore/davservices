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
 * An upstream server did not play along.
 *
 * RFC 4918 §9.9.4: the destination of a `COPY` or a `MOVE` lies on a server
 * this one cannot write to.
 */
final class BadGateway extends DavException
{
    protected const STATUS = 502;
}
