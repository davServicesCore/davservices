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

namespace DavServices\Dav\Event;

use DavServices\Event\Event;

/**
 * Raised before a member appears at a path, whatever put it there — the
 * `beforeBind` extension point of R-ARC-04.
 *
 * „Bind“ is the protocol's word for adding a member to a collection (RFC 3744
 * §3.9), and it is the one question that covers every way of doing it: a `PUT`
 * that creates, a `MKCOL`, the destination of a `COPY` or a `MOVE`. A plugin
 * that guards what may exist where — a quota, a naming policy, access control
 * — asks it once here instead of four times in four methods.
 *
 * The more specific events are raised as well where they apply, so a listener
 * that cares only about new files can go on listening for
 * {@see BeforeCreateFile}.
 */
final class BeforeBind extends Event
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * The path a member is about to appear at.
     */
    public function path(): string
    {
        return $this->path;
    }
}
