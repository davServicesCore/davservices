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

namespace DavServices\Tests\Unit\CalDav;

use DavServices\CalDav\ISharedWithMe;
use DavServices\CalDav\Sharee;

/**
 * A sharee's copy of somebody else's calendar.
 *
 * §5.2.4 of `caldav-sharing` describes the thing it stands for: "a shared
 * calendar collection resource that appears in a sharee's calendar home
 * collection", whose `CS:shared-url` holds "the URL of the sharer's calendar
 * being shared".
 *
 * **It keeps a `DAV:owner` like any shared calendar**, inherited from
 * {@see MemoryShareable}, because §5.5.5 wants one on the sharee's copy as
 * well — that is where a client reads "what the allowed ORGANIZER iCalendar
 * property values are".
 *
 * And it can carry a sharee list, because §5.2.2 has `CS:invite` on "the
 * calendar collection resources of the sharees" too. What a sharee is allowed
 * to see of it is the store's to decide: "A server's local privacy policy may
 * prevent sharees from knowing about other sharees on a shared calendar."
 */
final class MemorySharedWithMe extends MemoryShareable implements ISharedWithMe
{
    /**
     * @param list<Sharee> $sharees What this sharee may see of the list
     */
    public function __construct(
        string $name,
        private readonly string $sharedUrl = '/calendars/alice/work/',
        array $sharees = [],
        ?string $owner = '/principals/alice/',
    ) {
        parent::__construct($name, $sharees, [], $owner);
    }

    public function sharedUrl(): string
    {
        return $this->sharedUrl;
    }
}
