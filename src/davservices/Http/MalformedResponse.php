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
 * A response that could not be put on the wire as it stands.
 *
 * Unlike its counterparts for the request, this one is never the client's
 * doing: a status that is not three digits is a mistake in the library or in a
 * plugin, and it is better found where it was made than in a client's log.
 */
final class MalformedResponse extends InvalidArgumentException
{
}
