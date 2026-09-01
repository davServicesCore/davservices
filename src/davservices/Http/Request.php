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
 * Placeholder to exercise the quality gates on a bare scaffold.
 */
final class Request
{
    public function __construct(public readonly string $method)
    {
    }
}
