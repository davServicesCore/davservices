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

use DateTimeInterface;

/**
 * The one date format HTTP has (RFC 9110 §5.6.7).
 *
 * Always in GMT, whatever time zone the server keeps, and always with the
 * English day and month names of the specification, whatever locale it runs
 * under. A client compares what it is handed byte for byte with what it
 * stored: a date in the server's own zone is a cache that never hits again,
 * and one in the server's own language is worse, because it changes when
 * somebody changes an unrelated setting.
 *
 * `Last-Modified` and the `DAV:getlastmodified` property are the same format,
 * which is why it is here rather than in the method that first needed it.
 */
final class HttpDate
{
    /**
     * The moment as a header or property value.
     */
    public static function format(DateTimeInterface $moment): string
    {
        return gmdate('D, d M Y H:i:s \G\M\T', $moment->getTimestamp());
    }
}
