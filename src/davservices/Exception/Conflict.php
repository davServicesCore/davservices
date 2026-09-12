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
 * The request would leave the tree inconsistent.
 *
 * RFC 4918 §9.7.1: a `PUT` whose parent collection does not exist. Creating the
 * ancestors silently is expressly not what a DAV server does.
 */
final class Conflict extends DavException
{
    protected const STATUS = 409;
}
