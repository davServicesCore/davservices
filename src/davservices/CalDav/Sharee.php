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

/**
 * One person a calendar is shared with, and how it stands with them
 * (R-CAL-20).
 *
 * This is one entry of the `CS:invite` property, whose grammar §6.5 of
 * `caldav-sharing` gives as:
 *
 *     <!ELEMENT user (DAV:href, common-name?, (invite-noresponse |
 *                     invite-accepted | invite-declined | invite-invalid),
 *                     access, summary?)>
 *
 * Three of those are owed and two are not, which is why three are arguments
 * without a default: an entry with no address is nobody, and §6.5 admits
 * exactly one status and exactly one access level.
 *
 * ## The address is handed on as it stands
 *
 * §5.3.2 lets it be three different things:
 *
 * > Sharee's are identified via a DAV:href element whose value is either a
 * > principal-URL for a sharee hosted on the same server, a calendar user
 * > address or email address.
 *
 * So nothing here touches it. A `mailto:` address that a server tried to make
 * into a path would name a resource that is not there, and the one case where
 * a change is allowed is the store's: "A server MAY change the sharee's
 * 'address' to any suitable alternative that it might prefer when returning
 * the list of sharees" — which is a judgement about who a person is, and only
 * something that knows its users can make it.
 */
final class Sharee
{
    /**
     * @param string $href A principal-URL, a calendar user
     *                     address or an email address (§5.3.2)
     * @param SharingAccess $access What they may do with the calendar
     * @param InviteStatus $status How they answered, if they have
     * @param string|null $commonName Their name as it is to be shown. §5.3.2
     *                                has the server "SHOULD add a
     *                                CS:common-name when it is able to match
     *                                the sharee with a known user", which
     *                                only a store that knows its users can
     *                                do
     * @param string|null $summary What the calendar is, in a line (§6.14)
     */
    public function __construct(
        private readonly string $href,
        private readonly SharingAccess $access,
        private readonly InviteStatus $status,
        private readonly ?string $commonName = null,
        private readonly ?string $summary = null,
    ) {
    }

    /**
     * Where to find them, in whichever of §5.3.2's three forms.
     */
    public function href(): string
    {
        return $this->href;
    }

    /**
     * What they may do with the calendar (§5.5.1).
     */
    public function access(): SharingAccess
    {
        return $this->access;
    }

    /**
     * How it stands with their invitation (§6.5).
     */
    public function status(): InviteStatus
    {
        return $this->status;
    }

    /**
     * Their name as it is to be shown, where anything knows it (§6.19).
     */
    public function commonName(): ?string
    {
        return $this->commonName;
    }

    /**
     * What the shared calendar is, in a line, where anybody said (§6.14).
     */
    public function summary(): ?string
    {
        return $this->summary;
    }
}
