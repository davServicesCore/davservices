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

use Throwable;

/**
 * An exception that knows how it is to be reported over HTTP.
 *
 * The server catches this one type and needs nothing further to build the
 * response. Implementations outside this namespace are expected and welcome:
 * a request target is refused long before the DAV layer sees it, and still
 * has to come back as a `400`.
 */
interface IHttpFailure extends Throwable
{
    /**
     * The HTTP status the failure is reported with.
     */
    public function status(): int;

    /**
     * The precondition to name in a `DAV:error` body, as `{namespace}localname`.
     *
     * Null where the status alone says everything there is to say. RFC 4918
     * §16 defines the body; the element is chosen per throw site rather than
     * per status, because one status serves several preconditions.
     */
    public function errorElement(): ?string;
}
