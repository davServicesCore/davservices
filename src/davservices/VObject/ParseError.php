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

use RuntimeException;

/**
 * Something in an iCalendar or vCard object cannot be read at all.
 *
 * **Not an HTTP failure.** The classes in `DavServices\Exception` each stand
 * for a status a request is answered with, and a calendar file is read in
 * places that are answering no request: a migration, a backup, a test. What
 * the protocol edge makes of this is its own decision — a `PUT` of a broken
 * object is a `403` with `CALDAV:valid-calendar-data`, and that mapping
 * belongs where the `PUT` is (P5-06, P6).
 *
 * It says what could not be read, and it stops there. **Reading on and
 * guessing would be worse than refusing**: an object whose syntax is broken
 * says nothing reliable about the appointment it was meant to carry, and an
 * appointment at the wrong time is worse than an appointment that failed to
 * arrive.
 *
 * R-VOBJ-03 has a lenient mode that repairs common client mistakes. It is
 * built in P4-06, and it will work by deciding what to do with cases this
 * refuses — not by making the refusals quieter.
 */
final class ParseError extends RuntimeException
{
}
