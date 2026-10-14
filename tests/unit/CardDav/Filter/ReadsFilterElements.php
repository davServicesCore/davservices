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

namespace DavServices\Tests\Unit\CardDav\Filter;

use DavServices\Xml\Element;
use DavServices\Xml\Reader;

/**
 * Turns a fragment of a request body into the element a filter is built from.
 *
 * The filter classes are tested through the reader rather than through
 * hand-built elements, so that every case below reads as the piece of a REPORT
 * body a client would actually send — and so that a mistake in how an
 * attribute is named would show here rather than only once a real request
 * arrived.
 */
trait ReadsFilterElements
{
    protected static function element(string $fragment): Element
    {
        return (new Reader())->parse($fragment);
    }
}
