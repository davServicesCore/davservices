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
 * A write lock stands in the way.
 *
 * Either someone else holds it, or the caller holds it but did not submit its
 * token in the `If` header (RFC 4918 §9.10.6).
 */
final class Locked extends DavException
{
    protected const STATUS = 423;
}
