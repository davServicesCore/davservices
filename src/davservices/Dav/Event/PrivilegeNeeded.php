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
use DavServices\Exception\IHttpFailure;

/**
 * Raised when something above the access control layer has to know whether
 * **whoever is asking holds a privilege somewhere else** (RFC 3744 §7.1.1).
 *
 * The seams of {@see \DavServices\Plugin\Acl} guard the path a request
 * touches, and that covers nearly everything: a write is asked about the
 * resource it writes, a removal about the collection it empties. **This is
 * for the privilege that is somewhere else entirely.** RFC 6638 §6.2.2 is
 * the case it was built for:
 *
 * > Users granted the DAV:bind privilege on a calendar collection … will also
 * > need the CALDAV:schedule-send-invite privilege granted on the scheduling
 * > Outbox collection of the owner of the calendar collection … in order to
 * > be allowed to create, modify, or delete scheduling object resources in a
 * > way that will trigger the CalDAV server to deliver scheduling messages to
 * > "Attendees".
 *
 * A client writes to a calendar collection and the privilege is wanted on an
 * Outbox it never named. Only the scheduling layer knows that the two are
 * connected, and only the access control layer knows what is granted — so
 * neither of them can answer the question on its own.
 *
 * ## The answer is the refusal, not a no
 *
 * §7.1.1 prescribes the **shape** a missing privilege is reported in:
 *
 * > the response body to the "403 Forbidden" error MUST contain the
 * > `<DAV:error>` element, which in turn contains the
 * > `<DAV:need-privileges>` element, which contains one or more
 * > `<DAV:resource>` elements indicating which resource had insufficient
 * > privileges, and what the lacking privileges were
 *
 * Building that takes the href the server gives a path, which is not the
 * asker's business — so what comes back here is the refusal itself, ready to
 * be thrown. **An asker that got a bare "no" would have to build §7.1.1's
 * shape a second time**, and two places answering one question are two places
 * that can answer it differently; the detail that would go first is the href,
 * which is the one part a client acts on.
 *
 * **It is handed over rather than thrown**, because not every asker refuses.
 * RFC 6638 §3.2.9 reports a delivery that insufficient privileges stopped on
 * the resource instead — its `3.8` — and carries on with the others, so the
 * same question has to be answerable without the answer ending the request.
 * Whoever asked decides; a refusal nobody throws is simply read.
 *
 * ## Nobody answering means yes
 *
 * This is the opposite of {@see CurrentPrincipalRequested}, where silence is
 * `DAV:unauthenticated` and every check reads that as a refusal — and the
 * difference is what the silence means. There it means nobody said who is
 * asking, which is an answer about the asker. Here it means **nothing manages
 * access at all**: no plugin, no resolver, nowhere a grant could have been
 * made. R-ARC-02 has every plugin switchable, and a layer that refused
 * everything without one would not be switchable but required. "Insufficient
 * privileges" describes nothing where there are no privileges.
 */
final class PrivilegeNeeded extends Event
{
    private ?IHttpFailure $refusal = null;

    /**
     * @param string $path The resource the privilege is wanted **on**, inside
     *                     the tree — not the one the request named
     * @param string $privilege Its name, as a privilege appears in
     *                          `DAV:supported-privilege-set`
     * @param string|null $principal Who is asked about, as the path of their
     *                               principal inside the tree; null asks
     *                               about whoever is making the request, and
     *                               is the ordinary case
     */
    public function __construct(
        private readonly string $path,
        private readonly string $privilege,
        private readonly ?string $principal = null,
    ) {
    }

    /**
     * The resource the privilege is wanted on.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * The privilege that is wanted there.
     */
    public function privilege(): string
    {
        return $this->privilege;
    }

    /**
     * Whose holding of it is in question, or null for whoever is asking.
     *
     * **Somebody other than the asker is the second thing this seam is for.**
     * RFC 6638 §6.1 grants on a recipient's scheduling Inbox collection, and
     * says whose grant it is looking at: the privileges "determine whether
     * delivery of scheduling messages **from a calendar user** is allowed by
     * the calendar user who 'owns' the scheduling Inbox collection. This
     * allows calendar users to choose which other calendar users can schedule
     * with them."
     *
     * The calendar user sending need not be the one signed in — a delegate
     * writes an event that goes out in the organizer's name — so a seam that
     * could only ask about the asker would answer a different question, and
     * would both let anybody schedule with anybody who could write somewhere
     * and refuse a delegate acting for somebody the recipient *had* granted.
     *
     * **Null is not "nobody".** It means the question is about this request's
     * own asker, which is what every other check on this server is about;
     * nobody signed in is a case of that and is answered the way it is
     * everywhere ({@see CurrentPrincipalRequested}).
     */
    public function principal(): ?string
    {
        return $this->principal;
    }

    /**
     * Answers that it is not held, with the refusal §7.1.1 asks for.
     *
     * **A second answer replaces the first**, and nothing is made of that:
     * both say the privilege is not held, so there is no disagreement to
     * report — unlike {@see CurrentPrincipalRequested}, where two listeners
     * naming different principals disagree about who somebody is. What a
     * second answer cannot do is take the first one back: silence is not an
     * answer here, so no listener can say yes over another's no.
     */
    public function refuseWith(IHttpFailure $refusal): void
    {
        $this->refusal = $refusal;
    }

    /**
     * Why the privilege is not held, or null where nobody said it was not.
     */
    public function refusal(): ?IHttpFailure
    {
        return $this->refusal;
    }
}
