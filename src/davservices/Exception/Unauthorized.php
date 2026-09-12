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
 * No credentials were sent, or they did not hold up.
 *
 * The authentication plugin adds the `WWW-Authenticate` header that tells the
 * client how to try again (RFC 7617 §2).
 */
final class Unauthorized extends DavException
{
    protected const STATUS = 401;
}
