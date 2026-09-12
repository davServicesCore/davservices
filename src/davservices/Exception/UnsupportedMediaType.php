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
 * The resource cannot hold a body of that media type.
 *
 * A vCard sent to a calendar collection, for instance.
 */
final class UnsupportedMediaType extends DavException
{
    protected const STATUS = 415;
}
