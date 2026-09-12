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
 * The body exceeds the limit configured for its kind.
 *
 * XML bodies and resource uploads carry separate limits, because an XML body is
 * parsed into memory while an upload is streamed past it.
 */
final class PayloadTooLarge extends DavException
{
    protected const STATUS = 413;
}
