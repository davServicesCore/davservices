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

use DavServices\Xml\Element;
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
     * The condition to put in a `DAV:error` body (RFC 4918 §16).
     *
     * Null where the status alone says everything there is to say. The
     * condition is chosen per throw site rather than per status, because one
     * status serves several of them.
     *
     * **A name where the element is empty, the element itself where it is
     * not.** Most conditions mean only themselves —
     * `DAV:propfind-finite-depth` has nothing inside it — but some carry
     * their own detail: RFC 3744 §7.1.1's `DAV:need-privileges` names the
     * resource that lacked a privilege and the privilege it lacked, and a
     * refusal that could only give a name would leave a client knowing it may
     * not and nothing about what to change.
     */
    public function errorElement(): Element|string|null;
}
