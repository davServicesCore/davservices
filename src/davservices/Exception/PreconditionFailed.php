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
 * A condition the client attached to the request did not hold.
 *
 * An `If-Match`, an `If-None-Match`, or a state token in the WebDAV `If` header
 * (RFC 4918 §10.4.6). The resource is untouched.
 */
final class PreconditionFailed extends DavException
{
    protected const STATUS = 412;
}
