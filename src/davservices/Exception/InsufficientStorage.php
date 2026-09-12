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
 * What the request asks for does not fit.
 *
 * A quota is exhausted (RFC 4331), or a report would return more than the
 * configured number of results and is truncated instead.
 */
final class InsufficientStorage extends DavException
{
    protected const STATUS = 507;
}
