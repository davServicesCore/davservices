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
 * How hard a reader is on what it is given (R-VOBJ-03).
 *
 *     new Reader($body);                  // strict, and says so by saying nothing
 *     new Reader($body, Mode::Lenient);   // asked for, in so many words
 *
 * **Strict is the default because leniency cannot be taken back.** A strict
 * reader can be made lenient the day somebody needs it; a lenient one can
 * never be made strict again, because by then nobody knows which files came
 * to depend on the leniency. That order is why P4-01 to P4-05 refused
 * everything they could not read, and why this arrives afterwards rather than
 * alongside.
 *
 * ## What leniency is, and what it is not
 *
 * **It repairs what has exactly one right answer, and refuses the rest.** It
 * is the line {@see Severity} draws, applied while reading rather than
 * afterwards: there is only one line that closes a `VEVENT`, so a file that
 * never wrote it can be read as though it had — but a property standing
 * before any `BEGIN` belongs to no component, and putting it in one would be
 * putting somebody's data where they never wrote it.
 *
 * RFC 5545 §3.6 says why the repairs are worth making: applications "SHOULD
 * NOT silently drop any components as that can lead to user data loss." The
 * events in a truncated file are real events.
 *
 * **And nothing is repaired silently.** Every repair is a {@see Finding} on
 * {@see Reader::repairs()}, because of what comes next.
 *
 * ## The protocol consequence, which R-VOBJ-03 promises and does not name
 *
 * R-VOBJ-03 says the choice of mode „hat unmittelbare Protokollfolgen, siehe
 * Abschnitt 22" — and the requirements document has no section 22. **The
 * consequence is in RFC 9110 §9.3.4**, and it is exact:
 *
 * > An origin server MUST NOT send a validator field […] such as an ETag or
 * > Last-Modified field, in a successful response to PUT unless the request's
 * > representation data was saved without any transformation applied to the
 * > content (i.e., the resource's new representation data is identical to the
 * > content received in the PUT request) […]
 *
 * A repaired object is transformed content. So a `PUT` that was read
 * leniently and repaired **must not** be answered with an `ETag`, and the
 * client must fetch the resource again rather than trust what it still holds
 * in memory.
 *
 * **And there is a second consequence, which comes first.** RFC 4791 §5.3.2
 * and RFC 6352 §6.3.2 give `PUT` a precondition apiece, and both are about
 * what the client sent rather than what the server made of it:
 *
 * > (CALDAV:valid-calendar-data): The resource **submitted in the PUT
 * > request** […] MUST be valid data for the media type being specified
 * > (i.e., MUST contain valid iCalendar data)
 *
 * A truncated calendar is not valid iCalendar data — it does not match
 * `icalobject` — however well it can be repaired afterwards. **So the strict
 * mode is the conformant one on the write path of a CalDAV or CardDAV
 * server**, and leniency belongs where this library reads data it was not
 * handed as a resource to store: an import, a backup, a migration from
 * another server. An operator who chooses leniency on `PUT` anyway is
 * choosing it over that precondition, and then owes RFC 9110 §9.3.4 as well.
 *
 * Both belong to P5-06 and P6, where `PUT` is written; they are named here so
 * that the reader hands over what those layers will need.
 */
enum Mode
{
    /**
     * The default: what cannot be read as written is refused.
     */
    case Strict;

    /**
     * Repairs what has one right answer, and says what it repaired.
     */
    case Lenient;
}
