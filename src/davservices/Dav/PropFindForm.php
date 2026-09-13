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

namespace DavServices\Dav;

/**
 * The three ways of asking, from RFC 4918 §9.1 (R-DAV-03).
 *
 * They differ in what a client is owed, not merely in how much of it: a
 * `propname` answer that carried values would turn the cheap question into the
 * expensive one, and clients ask it precisely to avoid that.
 */
enum PropFindForm
{
    /** `DAV:prop`: these properties, and a `404` for any of them nobody has. */
    case Named;

    /** `DAV:allprop`: whatever there is, plus the extras of `DAV:include`. */
    case Everything;

    /** `DAV:propname`: which properties there are, without their values. */
    case NamesOnly;
}
