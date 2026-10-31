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
 * What a sharee answers an invitation with, and what the sharer is then told
 * (R-CAL-20).
 *
 * **One element for both directions**, which is §6.22's own doing: the section
 * describes it as "a notification used as a reply to a shared calendar invite"
 * and §5.4.1 has the sharee send the same thing — "The POST request MUST
 * contain an XML document as its body with the root element being
 * CS:invite-reply". So the document a sharee posts and the message the sharer
 * receives are the same shape, and this class is how the second is written.
 *
 *     <!ELEMENT invite-reply (DAV:href,
 *                             (invite-accepted | invite-declined),
 *                             hosturl, in-reply-to, summary?>
 *
 * ## Two of the four states, and the reader is where that is enforced
 *
 * §6.22 admits `CS:invite-accepted` and `CS:invite-declined` and neither of
 * the other two {@see InviteStatus} carries. The choice is read out of a
 * client's document, so it is read there — {@see \DavServices\Plugin\Sharing}
 * refuses a reply that names neither or both — and what arrives here is
 * whichever of the two the document said.
 *
 * ## The identifier is quoted rather than derived
 *
 * §6.23's `CS:in-reply-to` is "the unique identifier of the inviate message
 * that this notification message is a reply to", so it is the sharee's claim
 * about which invitation they are answering. The plugin checks that claim
 * against {@see InviteNotification::identifierOf()} before anything is
 * recorded; what is carried on to the sharer is the identifier of the
 * invitation that was really found, so that the two messages about one
 * invitation agree.
 */
final class InviteReply
{
    /**
     * §6.22's root, which is both the request body and the notification type.
     */
    public const INVITE_REPLY = '{http://calendarserver.org/ns/}invite-reply';

    private const HOST_URL = '{http://calendarserver.org/ns/}hosturl';

    private const IN_REPLY_TO = '{http://calendarserver.org/ns/}in-reply-to';

    private const SUMMARY = '{http://calendarserver.org/ns/}summary';

    private const HREF = '{DAV:}href';

    /**
     * @param string $sharee Their calendar user address, as §6.22 has
     *                       it: "the calendar user address of the
     *                       sharee to whom the original invite
     *                       message was sent"
     * @param InviteStatus $answer Whichever of §6.22's two the document said
     * @param string $calendar The sharer's calendar (§6.17)
     * @param string $inReplyTo The invitation this answers (§6.23)
     * @param string|null $summary What the sharee calls it (§6.14)
     */
    public function __construct(
        private readonly string $sharee,
        private readonly InviteStatus $answer,
        private readonly string $calendar,
        private readonly string $inReplyTo,
        private readonly ?string $summary = null,
    ) {
    }

    /**
     * The invitation this answers (§6.23).
     *
     * Read by {@see \DavServices\Plugin\Sharing} to name the resource the
     * message is deposited as, so that two replies about one invitation are
     * one message and the later one stands.
     */
    public function inReplyTo(): string
    {
        return $this->inReplyTo;
    }

    /**
     * The message, in the order §6.22 lays down.
     */
    public function toElement(): Element
    {
        $reply = new Element(self::INVITE_REPLY);

        $reply->append(self::saying(self::HREF, $this->sharee));
        $reply->append(new Element($this->answer->value));
        $reply->append(self::holding(self::HOST_URL, self::saying(self::HREF, $this->calendar)));
        $reply->append(self::saying(self::IN_REPLY_TO, $this->inReplyTo));

        if ($this->summary !== null) {
            $reply->append(self::saying(self::SUMMARY, $this->summary));
        }

        return $reply;
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
