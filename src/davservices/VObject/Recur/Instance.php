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

namespace DavServices\VObject\Recur;

use DavServices\VObject\Component;
use DavServices\VObject\Value\Date;
use DavServices\VObject\Value\DateTime;

/**
 * One instance of a recurrence set (RFC 5545 §3.8.4.4).
 *
 * **An instance has two dates, and the memo is emphatic about the
 * difference.** §3.8.4.4 of `RECURRENCE-ID`: "The property value is the
 * **original** value of the 'DTSTART' property of the recurrence instance",
 * and again, in case that was not plain enough: "The DATE-TIME value is set
 * to the time when the original recurrence instance would occur; meaning that
 * **if the intent is to change a Friday meeting to Thursday, the DATE-TIME is
 * still set to the original Friday meeting**."
 *
 * So {@see self::recurrenceId()} is where the series put the instance and
 * {@see self::start()} is where it is. For an instance nobody overrode they
 * are the same value; for an overridden one they are not, and **the
 * identifier is what names it** — "Subsequent instances are determined by
 * their 'RECURRENCE-ID' value and not their current scheduled start time."
 *
 * And {@see self::component()} is what describes it: the master for an
 * ordinary instance, the overriding component for an overridden one. An
 * override may change anything the series set, a summary as readily as a
 * start, so the component matters as much as the dates.
 */
final class Instance
{
    /**
     * @param Date|DateTime $recurrenceId Where the series put this instance,
     *                                    which is what identifies it
     * @param Date|DateTime $start Where the instance actually begins
     * @param Component $component What describes it: the master, or the
     *                             component that overrides it
     */
    public function __construct(
        private readonly Date|DateTime $recurrenceId,
        private readonly Date|DateTime $start,
        private readonly Component $component,
    ) {
    }

    /**
     * Where the series put this instance — "the original value of the
     * 'DTSTART' property of the recurrence instance".
     */
    public function recurrenceId(): Date|DateTime
    {
        return $this->recurrenceId;
    }

    /**
     * Where the instance begins, which an override or a range may have moved.
     */
    public function start(): Date|DateTime
    {
        return $this->start;
    }

    /**
     * The component that describes this instance.
     */
    public function component(): Component
    {
        return $this->component;
    }
}
