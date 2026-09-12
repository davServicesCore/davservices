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

namespace DavServices\Uri;

use InvalidArgumentException;

/**
 * A request target that cannot be resolved to a safe internal path.
 *
 * It extends the SPL type so that a caller who has never heard of davServices
 * still catches it. The HTTP layer turns it into `400 Bad Request`: the target
 * is syntactically unusable, so no amount of retrying the same request helps.
 */
final class MalformedPath extends InvalidArgumentException
{
}
