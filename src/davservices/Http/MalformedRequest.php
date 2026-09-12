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

namespace DavServices\Http;

use InvalidArgumentException;

/**
 * A request line that cannot be made sense of.
 *
 * A method that is no token, or a target that is empty. It extends the SPL
 * type so that a caller who has never heard of davServices still catches it;
 * what it becomes on the wire is for the server to decide.
 */
final class MalformedRequest extends InvalidArgumentException
{
}
