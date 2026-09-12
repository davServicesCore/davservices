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
 * A request the library cannot make sense of.
 *
 * Malformed XML, a header it cannot parse, or a value outside the range the
 * specification allows. Sending the same request again cannot help.
 */
final class BadRequest extends DavException
{
    protected const STATUS = 400;
}
