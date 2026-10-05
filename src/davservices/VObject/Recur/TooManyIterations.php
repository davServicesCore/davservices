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

use RuntimeException;

/**
 * Expanding a recurrence rule reached its hard iteration limit (R-RRULE-04).
 *
 * **Not a parse error, and not an HTTP failure.** The rule was read without
 * difficulty and says something perfectly well formed — "every day, for
 * ever". What ran out is the patience of the expansion, and R-CAL-09 says
 * what that has to look like: „Wird eine Grenze überschritten, MUSS mit der
 * zugehörigen Precondition abgelehnt werden, **niemals mit Abbruch**." A
 * refusal the layer above can answer with, rather than a hang or a memory
 * error.
 *
 * ## It is not `CALDAV:max-instances`
 *
 * The requirements name two numbers and they are deliberately different: the
 * hard iteration limit is ten thousand, and `CALDAV:max-instances` is five
 * thousand. The second counts the instances a resource generates and belongs
 * to the protocol edge, where RFC 4791 §5.2.8 gives it a precondition of its
 * own: "Any attempt to store a calendar object resource with a recurrence
 * pattern that generates more instances than this value MUST result in an
 * error, with the CALDAV:max-instances precondition […] being violated."
 *
 * **This one counts the iterations rather than the instances**, which is the
 * difference that matters for a rule that throws most of its candidates away.
 * A monthly rule on the thirty-first looks at twelve months to find seven
 * days, and a yearly one on the twenty-ninth of February looks at four years
 * to find one. The edge will usually meet its own limit first; this one is
 * the floor under the work, so that no rule can run away.
 *
 * The limit it reached is in the message and nowhere else. A reader of a log
 * wants to know which number was hit; the layer above configured that number
 * itself, so it is already holding it, and an accessor nobody calls is a
 * guess about the future rather than a service to the present.
 */
final class TooManyIterations extends RuntimeException
{
    public function __construct(int $iterations)
    {
        parent::__construct(sprintf(
            'Expanding this recurrence rule passed its limit of %d iterations (R-RRULE-04).',
            $iterations,
        ));
    }
}
