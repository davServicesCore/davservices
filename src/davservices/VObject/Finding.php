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

namespace DavServices\VObject;

/**
 * One thing a {@see Validator} found, and where (R-VOBJ-04).
 *
 * **The place is not decoration.** A calendar runs to ten thousand lines, and
 * a message without a path leaves somebody to read the whole file to find the
 * one line it is about. The path is written the way the object is nested —
 * `VCALENDAR/VEVENT/DTSTART`, and a parameter after a semicolon.
 */
final class Finding
{
    public function __construct(
        private readonly Severity $severity,
        private readonly string $where,
        private readonly string $message,
    ) {
    }

    /**
     * How bad it is.
     */
    public function severity(): Severity
    {
        return $this->severity;
    }

    /**
     * Where it is, as a path through the object.
     */
    public function where(): string
    {
        return $this->where;
    }

    /**
     * What is wrong, in words somebody repairing the file can act on.
     */
    public function message(): string
    {
        return $this->message;
    }
}
