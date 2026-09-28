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
 * How bad a finding is (R-VOBJ-04).
 *
 * The requirement names the three words and does not say where the lines
 * between them run. They are drawn here, and **the line that matters is
 * whether there is exactly one right answer**: that is what separates
 * something a repairer may put right from something only the author knows.
 *
 * The values are the words themselves, so that a log line or a protocol
 * answer can print one without a second table to look it up in.
 */
enum Severity: string
{
    /**
     * A MUST is broken **and** the specification leaves exactly one way to
     * put it right — so a repairer can do it without guessing what the author
     * meant.
     */
    case Repair = 'REPAIR';

    /**
     * Nothing is broken, but something is likely a mistake or belongs to an
     * older specification.
     */
    case Warning = 'WARNING';

    /**
     * A MUST is broken and nothing but the author knows what was meant.
     */
    case Error = 'ERROR';
}
