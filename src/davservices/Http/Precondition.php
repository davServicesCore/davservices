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

/**
 * What the conditions attached to a request come to.
 *
 * Three outcomes, one answer each: carry on, `304 Not Modified`, or
 * `412 Precondition Failed`. They are a value rather than an exception because
 * two of the three are the ordinary course of a caching client's day.
 */
enum Precondition
{
    /** Nothing stands in the way; answer the request as it was meant. */
    case Met;

    /** The client already holds this version; answer `304` without a body. */
    case NotModified;

    /** A condition the client attached does not hold; answer `412`. */
    case Failed;
}
