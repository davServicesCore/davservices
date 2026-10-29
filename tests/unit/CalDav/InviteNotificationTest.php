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

namespace DavServices\Tests\Unit\CalDav;

use DavServices\CalDav\InviteNotification;
use DavServices\CalDav\InviteStatus;
use DavServices\CalDav\Sharee;
use DavServices\CalDav\SharingAccess;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for §6.16's identifier. R-CAL-20, P7-05b.
 *
 * > CS:uid — Purpose: Unique identifier. Description: A unique identifier for
 * > an invitation to a shared calendar.
 *
 * **Two words carry the whole of it**: "unique", and "an invitation". An
 * invitation is one calendar offered to one sharee, so the identifier is
 * derived from exactly those two and nothing is remembered — and the cases
 * here are what "unique" means when it is spelt out: a different calendar or
 * a different sharee is a different invitation, and the same pair is the same
 * invitation however often it is spoken about.
 *
 * That last one is not decoration. §6.23 has a sharee's reply carry
 * `CS:in-reply-to`, "the unique identifier of the invite to which it is a
 * reply", and {@see \DavServices\Plugin\Notifications::deposit()} finds the
 * message to replace by it. An identifier that moved would break both.
 *
 * The whole of §6.15's document is asserted in
 * {@see \DavServices\Tests\Unit\Plugin\SharingPostTest}; this is about the one
 * element nothing else can check.
 */
#[CoversClass(InviteNotification::class)]
final class InviteNotificationTest extends TestCase
{
    public function testTwoShareesOfOneCalendarAreTwoInvitations(): void
    {
        self::assertNotSame(
            self::identifierOf('/calendars/alice/work/', '/principals/eric/'),
            self::identifierOf('/calendars/alice/work/', '/principals/wilfredo/'),
        );
    }

    public function testOneShareeOfTwoCalendarsIsTwoInvitationsAsWell(): void
    {
        self::assertNotSame(
            self::identifierOf('/calendars/alice/work/', '/principals/eric/'),
            self::identifierOf('/calendars/alice/home/', '/principals/eric/'),
        );
    }

    /**
     * **And an address that runs into the calendar's own path is still an
     * invitation of its own**, which is what the separator in the derivation
     * is for: `/a` shared to `b/c` and `/ab` shared to `/c` would otherwise
     * come to the same string, and §6.16 asks for a unique identifier.
     *
     * Far-fetched as a pair of real addresses, and not far-fetched as a
     * mistake: a derivation that joins two values without a separator is one
     * that says two things are the same invitation when they are not.
     */
    public function testAnAddressRunningIntoThePathIsStillAnInvitationOfItsOwn(): void
    {
        self::assertNotSame(
            self::identifierOf('/a', 'b/c'),
            self::identifierOf('/ab', '/c'),
        );
    }

    /**
     * **The same invitation keeps its identifier**, whatever is being said
     * about it: an update and a removal are two messages about one invitation,
     * and §6.23's reply quotes the identifier rather than the message.
     */
    public function testTheSameInvitationKeepsItsIdentifier(): void
    {
        $sharee = new Sharee('/principals/eric/', SharingAccess::Read, InviteStatus::Accepted);
        $change = InviteNotification::aboutAChange($sharee, '/calendars/alice/work/', '/principals/alice', null);
        $removal = InviteNotification::aboutARemoval($sharee, '/calendars/alice/work/', '/principals/alice', null);

        self::assertNotNull($change);
        self::assertSame($change->uid(), $removal->uid());
    }

    /**
     * **And a sharee the store calls invalid has no message at all**, §6.15's
     * grammar admitting four states and `CS:invite-invalid` being none of
     * them. §6.10 says why there is none: the address is "not a valid
     * calendar user known to the server", so there is nobody to send one to.
     */
    public function testAShareeWhoIsNoValidUserHasNoMessage(): void
    {
        self::assertNull(InviteNotification::aboutAChange(
            new Sharee('/principals/nobody/', SharingAccess::Read, InviteStatus::Invalid),
            '/calendars/alice/work/',
            '/principals/alice',
            null,
        ));
    }

    /**
     * The identifier of the invitation of this calendar to this sharee.
     */
    private static function identifierOf(string $calendar, string $sharee): string
    {
        $notice = InviteNotification::aboutAChange(
            new Sharee($sharee, SharingAccess::Read, InviteStatus::NoResponse),
            $calendar,
            '/principals/alice',
            null,
        );

        self::assertNotNull($notice);

        return $notice->uid();
    }
}
