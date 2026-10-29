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

namespace DavServices\CalDav;

/**
 * What a sharee may do with a calendar shared to them (R-CAL-20).
 *
 * **Two levels and no third**, which is the source's own count — §5.5.1 of
 * `caldav-sharing`: "Two levels of access ca be granted by a sharer to any
 * sharee." The grammar of §6.11 says it again and leaves no room:
 *
 *     <!ELEMENT access (read | read-write)>
 *
 * The cases carry the element names rather than the plugin writing them out,
 * so that what a store grants and what the document says cannot come apart.
 */
enum SharingAccess: string
{
    /**
     * Read, and nothing else (§6.12).
     *
     * > Indicates that the access level granted only allows sharees to read
     * > data in the shared calendar (though they can write per-user data
     * > (Section 5.5.4)).
     */
    case Read = '{http://calendarserver.org/ns/}read';

    /**
     * Read and write (§6.13).
     *
     * > Indicates that the access level granted allows sharees to read and
     * > write all data in the shared calendar, with the exception of
     * > components that would trigger scheduling.
     */
    case ReadWrite = '{http://calendarserver.org/ns/}read-write';
}
