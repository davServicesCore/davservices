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
 * A node that is a notification message and knows what kind it is.
 *
 * **It is here rather than read out of the document**, and §4.2.1 of
 * `caldav-sharing` gives the reason in its own purpose:
 *
 * > This property allows a client, via a PROPFIND Depth:1 request, to
 * > **quickly** find notification messages that the client can handle in a
 * > notification collection.
 *
 * A server that answered by parsing every document in the collection would
 * make that property the slow way of doing what reading the bodies already
 * does, and the whole point of it is to save the client that. A store knows
 * the kind without looking inside — it was the server that wrote it.
 *
 * ## Why a capability and not a backend
 *
 * The same division the rest of the library keeps: what a *node* can tell
 * about itself is an interface here ({@see \DavServices\Dav\IResourceType},
 * `IQuota`, `IFilter`), and what a *store* does is a `Backend\I…Backend`.
 * Which kind a notification is, is something only the node knows — the same
 * sentence `IResourceType` opens with.
 *
 * ## It hands back the element as it stands
 *
 * Attributes, children and all. §4.2.1 asks for the property's child to be
 * emptied and its attributes kept, which is one rule in one sentence, so it
 * lives in one place — {@see \DavServices\Plugin\Notifications} — rather than
 * in every store that keeps notifications.
 *
 * ## One sentence of §4.2.1 is the store's to keep
 *
 * > COPY/MOVE behavior: This property value MUST be preserved in COPY and MOVE
 * > operations.
 *
 * Because the kind comes from the node, a copy answers its own and the
 * property cannot be lost on the way — as far as this library reaches. What it
 * cannot do is make a store carry the kind over when it copies a notification:
 * a store that dropped it would hand back a resource that is no longer a
 * notification of anything. **That is this interface's half of the bargain**,
 * said here because there is nowhere else to say it.
 */
interface INotification
{
    /**
     * The notification's type element, as it stands in the document.
     *
     * That is the child of the document's `CS:notification` root which comes
     * after `CS:dtstamp`, and §6.24 admits exactly one: "Any notification type
     * element can appear after CS:dtstamp, this specification defines only the
     * two listed above" — `CS:invite-notification` and `CS:invite-reply`.
     */
    public function notificationType(): Element;
}
