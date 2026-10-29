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

use DavServices\Xml\Element;

/**
 * The message a sharee is sent when their standing on a calendar changes
 * (R-CAL-20).
 *
 * §6.15 of `caldav-sharing` gives its shape, and §5.3.2 says when it is owed:
 * "In each case the server MUST send a notification message to any sharees
 * whose status is changed (added, modified or removed) […] This is
 * accomplished by sending a CS:invite-notification (Section 6.15)
 * notification to each sharee."
 *
 *     <!ELEMENT invite-notification (
 *                 uid, DAV:href,
 *                 (invite-noresponse | invite-deleted |
 *                 invite-accepted | invite-declined),
 *                 access, hosturl, organizer,
 *                 summary?,
 *                 CALDAV:supported-calendar-component-set?>
 *
 * ## The state is not the sharee's state
 *
 * Three of the four elements are the ones §6.5 uses for a sharee's own entry,
 * and they mean something else here — §6.8, of `CS:invite-accepted`: "When
 * used in a CS:invite-notification element, this element is used to indicate
 * to the sharee that the sharing invite is **an update for one they
 * previously accepted**."
 *
 * The fourth is `CS:invite-deleted`, which §6.7 defines for this element
 * alone: "a shared calendar has been unshared by the sharer". There is no
 * such state for a sharee to be *in* — §5.4.2 has a refusal change a status,
 * not remove an entry — so it exists only as something to be told.
 *
 * **And `CS:invite-invalid` is missing from the four**, which settles what to
 * do about a sharee §6.10 calls "not a valid calendar user known to the
 * server": nothing. There is nobody to tell, and {@see aboutAChange()} hands
 * back null rather than writing a document the grammar does not admit.
 *
 * ## The uid identifies the invitation, not the message
 *
 * §6.16: "A unique identifier for an invitation to a shared calendar." An
 * invitation is one calendar offered to one sharee, so it is derived from
 * exactly those two and needs nothing remembered — the same way
 * {@see \DavServices\Sync\Token} derives a collection's key. A second message
 * about the same invitation therefore carries the same identifier, which is
 * what lets §6.23's `CS:in-reply-to` mean anything.
 *
 * ## One optional element is left out, and it costs something
 *
 * `CALDAV:supported-calendar-component-set` is optional, and §6.15 says what
 * it is for: "to allow clients to know what restrictions might apply to the
 * shared calendar **before accepting it**". Leaving it out therefore costs a
 * sharee exactly that, since they cannot read the calendar until they have
 * accepted. It is named here rather than quietly dropped: it wants a property
 * read from another node in the middle of a `POST`, and the place to add it
 * is beside the sharee's own copy (§5.4.1).
 */
final class InviteNotification
{
    /**
     * §6.15's root, which is this notification's type (§4.2.1).
     */
    public const INVITE_NOTIFICATION = '{http://calendarserver.org/ns/}invite-notification';

    /**
     * §6.7, which only ever appears in a notification.
     */
    private const DELETED = '{http://calendarserver.org/ns/}invite-deleted';

    private const UID = '{http://calendarserver.org/ns/}uid';

    private const ACCESS = '{http://calendarserver.org/ns/}access';

    private const HOST_URL = '{http://calendarserver.org/ns/}hosturl';

    private const ORGANIZER = '{http://calendarserver.org/ns/}organizer';

    private const COMMON_NAME = '{http://calendarserver.org/ns/}common-name';

    private const SUMMARY = '{http://calendarserver.org/ns/}summary';

    private const HREF = '{DAV:}href';

    /**
     * How many hexadecimal digits of the digest make the identifier.
     *
     * The same length {@see \DavServices\Sync\Token} uses for the same kind
     * of thing, and for the same reason: long enough that two invitations of
     * one deployment will not collide, short enough to read in a log.
     */
    private const DIGITS = 32;

    private function __construct(
        private readonly string $state,
        private readonly Sharee $sharee,
        private readonly string $calendar,
        private readonly string $sharer,
        private readonly ?string $sharerIsCalled,
    ) {
    }

    /**
     * A sharee has been added, or what they had has changed.
     *
     * Null for a sharee the store calls {@see InviteStatus::Invalid}: §6.15's
     * grammar has no element for that state, and §6.10 says why there is
     * none — the address is nobody the server knows, so there is nowhere for
     * a message to go.
     *
     * @param string $calendar The sharer's calendar, as a URL
     *                         (§6.17's `CS:hosturl`)
     * @param string $sharer The sharer, as a URL (§6.18)
     * @param string|null $sharerIsCalled Their name, where anything knows it
     */
    public static function aboutAChange(
        Sharee $sharee,
        string $calendar,
        string $sharer,
        ?string $sharerIsCalled,
    ): ?self {
        if ($sharee->status() === InviteStatus::Invalid) {
            return null;
        }

        return new self($sharee->status()->value, $sharee, $calendar, $sharer, $sharerIsCalled);
    }

    /**
     * A sharee has been taken off the list (§6.7).
     *
     * **Built from what they had**, because §6.15 makes `access` a required
     * child of this message as much as of any other: a server that had
     * already forgotten what it was taking away could not write a conforming
     * document.
     */
    public static function aboutARemoval(
        Sharee $was,
        string $calendar,
        string $sharer,
        ?string $sharerIsCalled,
    ): self {
        return new self(self::DELETED, $was, $calendar, $sharer, $sharerIsCalled);
    }

    /**
     * What §6.16 identifies the invitation by.
     *
     * Derived from the calendar and the sharee, so that the same invitation
     * keeps the same identifier however many times it is spoken about.
     */
    public function uid(): string
    {
        return substr(hash('sha256', $this->calendar . "\0" . $this->sharee->href()), 0, self::DIGITS);
    }

    /**
     * The message, in the order §6.15 lays down.
     */
    public function toElement(): Element
    {
        $notification = new Element(self::INVITE_NOTIFICATION);

        $notification->append(self::saying(self::UID, $this->uid()));
        $notification->append(self::saying(self::HREF, $this->sharee->href()));
        $notification->append(new Element($this->state));
        $notification->append(self::holding(self::ACCESS, new Element($this->sharee->access()->value)));
        $notification->append(self::holding(self::HOST_URL, self::saying(self::HREF, $this->calendar)));
        $notification->append($this->organizer());

        $summary = $this->sharee->summary();

        if ($summary !== null) {
            $notification->append(self::saying(self::SUMMARY, $summary));
        }

        return $notification;
    }

    /**
     * §6.18's `CS:organizer`: who the sharer is.
     *
     *     <!ELEMENT organizer (DAV:href, CS:common-name?,
     *                          (CS:first-name, CS:last-name)?)>
     *
     * The name is left out where nothing knows it rather than sent empty.
     * §6.18 allows for that in as many words: "In some cases servers might
     * have directory information that includes only the common name, or only
     * the first or last name, and it is better to expose those directly to
     * the client as-is". The first and last name are not sent at all, this
     * library holding no directory that tells them apart.
     */
    private function organizer(): Element
    {
        $organizer = self::holding(self::ORGANIZER, self::saying(self::HREF, $this->sharer));

        if ($this->sharerIsCalled !== null) {
            $organizer->append(self::saying(self::COMMON_NAME, $this->sharerIsCalled));
        }

        return $organizer;
    }

    /**
     * An element of this name with this text in it.
     */
    private static function saying(string $name, string $text): Element
    {
        $element = new Element($name);

        $element->appendText($text);

        return $element;
    }

    /**
     * An element of this name with that element inside it.
     */
    private static function holding(string $name, Element $child): Element
    {
        $element = new Element($name);

        $element->append($child);

        return $element;
    }
}
