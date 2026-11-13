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

namespace DavServices\Tests\Unit\CalDav\Schedule;

use DavServices\Acl\Privilege;
use DavServices\CalDav\Schedule\Privileges;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for RFC 6638 §6, the eight scheduling privileges. R-CAL-42,
 * P8-06.
 *
 * §6 carries three MUSTs and this class answers all three of them:
 *
 * > All the scheduling privileges MUST be non-abstract and MUST appear in the
 * > DAV:supported-privilege-set property of scheduling Outbox and Inbox
 * > collections on which they are defined.
 *
 * and
 *
 * > Server implementations MUST aggregate the scheduling privileges as
 * > follows:
 * >
 * >    DAV:all contains CALDAV:schedule-deliver and CALDAV:schedule-send;
 * >
 * >    CALDAV:schedule-deliver contains CALDAV:schedule-deliver-invite,
 * >    CALDAV:schedule-deliver-reply, and CALDAV:schedule-query-freebusy;
 * >
 * >    CALDAV:schedule-send contains CALDAV:schedule-send-invite, CALDAV:
 * >    schedule-send-reply, and CALDAV:schedule-send-freebusy.
 *
 * **The aggregation is prescribed, so it is asserted rather than described.**
 * `DAV:all` reaching a privilege through the wrong parent would grant a
 * calendar user the wrong half of scheduling, and §6.3 is the only sentence
 * in either RFC that fixes where these eight hang.
 *
 * ## Two branches, and each on one kind of collection
 *
 * §6.1 defines four privileges for a scheduling Inbox and §6.2 four for a
 * scheduling Outbox, and each section ends with the same sentence about the
 * other kind:
 *
 * > The privileges defined in this section are ignored if applied to a
 * > resource other than a scheduling Inbox collection.
 *
 * So the branches are separate, and which one a collection is given is the
 * collection's answer rather than this class's — see
 * {@see \DavServices\Tests\Unit\Plugin\SchedulingPrivilegesTest} for what a
 * client reads off an Inbox, an Outbox and a calendar.
 *
 * ## Non-abstract, which is a decision against RFC 3744's example
 *
 * RFC 3744 §5.3 marks `DAV:all` abstract in its example tree and explains
 * what that means: "An abstract privilege MUST NOT be used in an ACE for that
 * resource. Servers MUST fail an attempt to set an abstract privilege." §6
 * says the opposite of all eight, the two aggregates included — so a
 * deployment granting `CALDAV:schedule-deliver` in one entry has to be
 * obeyed, and this class may not mark it.
 */
#[CoversClass(Privileges::class)]
final class PrivilegesTest extends TestCase
{
    /**
     * **§6.3's first two lines, read as a tree.** `CALDAV:schedule-deliver`
     * hangs directly under `DAV:all` and reaches exactly its own three.
     */
    public function testAnInboxDefinesTheThreeDeliveryPrivilegesUnderTheOneThatAggregatesThem(): void
    {
        $deliver = Privileges::anInboxDefines(Privilege::standard())->find(Privileges::DELIVER);

        self::assertInstanceOf(Privilege::class, $deliver);
        self::assertSame(
            [
                Privileges::DELIVER,
                Privileges::DELIVER_INVITE,
                Privileges::DELIVER_REPLY,
                Privileges::QUERY_FREEBUSY,
            ],
            $deliver->flattened(),
        );
    }

    /**
     * **And §6.3's third line, the same way.**
     */
    public function testAnOutboxDefinesTheThreeSendingPrivilegesUnderTheOneThatAggregatesThem(): void
    {
        $send = Privileges::anOutboxDefines(Privilege::standard())->find(Privileges::SEND);

        self::assertInstanceOf(Privilege::class, $send);
        self::assertSame(
            [
                Privileges::SEND,
                Privileges::SEND_INVITE,
                Privileges::SEND_REPLY,
                Privileges::SEND_FREEBUSY,
            ],
            $send->flattened(),
        );
    }

    /**
     * **`DAV:all` contains them, and contains them itself.** §6.3's diagram
     * has each aggregate one level under `DAV:all`, not somewhere below
     * `DAV:write` — a server that hung `CALDAV:schedule-send` under the
     * privilege to change a resource would be saying that whoever may edit an
     * event may invite the world to it.
     */
    #[DataProvider('theTwoAggregates')]
    public function testAnAggregateHangsDirectlyUnderTheOneThatHoldsEverything(
        string $branch,
        string $aggregate,
    ): void {
        $tree = self::theTreeOf($branch);
        $directly = [];

        foreach ($tree->aggregates() as $child) {
            $directly[] = $child->name();
        }

        self::assertSame('{DAV:}all', $tree->name());
        self::assertContains($aggregate, $directly);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function theTwoAggregates(): array
    {
        return [
            'an Inbox' => ['inbox', Privileges::DELIVER],
            'an Outbox' => ['outbox', Privileges::SEND],
        ];
    }

    /**
     * **Neither kind of collection defines the other's four.** §6.1 and §6.2
     * each close with "ignored if applied to a resource other than" the kind
     * they are for, and RFC 3744 §5.3 has the property identify "the
     * privileges defined **for the resource**" — so a right that would be
     * disregarded here is not reported here.
     */
    #[DataProvider('whatTheOtherKindDefines')]
    public function testOneKindOfCollectionDoesNotDefineWhatTheOtherDoes(string $branch, string $privilege): void
    {
        self::assertFalse(self::theTreeOf($branch)->contains($privilege));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function whatTheOtherKindDefines(): array
    {
        $cases = [];

        foreach (self::THE_FOUR_OF_EACH as $kind => $four) {
            $other = $kind === 'inbox' ? 'outbox' : 'inbox';

            foreach ($four as $privilege) {
                $cases[$other . ' and ' . $privilege] = [$other, $privilege];
            }
        }

        return $cases;
    }

    /**
     * **None of the eight is abstract** (§6), the two aggregates included —
     * which is the opposite of what RFC 3744 §5.3's example does with
     * `DAV:all`. An abstract one "MUST NOT be used in an ACE for that
     * resource", so marking any of these would make a deployment's own
     * grant unsettable.
     */
    #[DataProvider('allEight')]
    public function testNoneOfTheEightMayBeMarkedAbstract(string $branch, string $privilege): void
    {
        $found = self::theTreeOf($branch)->find($privilege);

        self::assertInstanceOf(Privilege::class, $found);
        self::assertFalse($found->isAbstract());
    }

    /**
     * **And each says what it controls, in a language it names.** RFC 3744
     * §5.3's DTD has `description` required and the `xml:lang` attribute with
     * it — a privilege brought by an extension brings the sentence that
     * explains it, or it brings a hole in a required element.
     */
    #[DataProvider('allEight')]
    public function testEachOfTheEightSaysWhatItControlsAndInWhatLanguage(string $branch, string $privilege): void
    {
        $found = self::theTreeOf($branch)->find($privilege);

        self::assertInstanceOf(Privilege::class, $found);
        self::assertNotSame('', $found->description());
        self::assertNotSame('', $found->language());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function allEight(): array
    {
        $cases = [];

        foreach (self::THE_FOUR_OF_EACH as $kind => $four) {
            foreach ($four as $privilege) {
                $cases[$privilege] = [$kind, $privilege];
            }
        }

        return $cases;
    }

    /**
     * **What the collection was given back is still the whole tree.** The
     * branch is hung into what this server grants everywhere rather than
     * replacing it: a node that answered only its own four would take
     * `DAV:read` off its own report, and RFC 3744 §5.3 is the one property a
     * client reads to know what it may ask for at all.
     */
    #[DataProvider('theTwoAggregates')]
    public function testTheTreeItWasGivenIsStillWholeUnderneath(string $branch): void
    {
        $tree = self::theTreeOf($branch);

        foreach (Privilege::standard()->flattened() as $name) {
            self::assertTrue($tree->contains($name), $name . ' is still granted');
        }
    }

    /**
     * The four §6.1 defines and the four §6.2 defines, by the kind of
     * collection they belong to.
     */
    private const THE_FOUR_OF_EACH = [
        'inbox' => [
            Privileges::DELIVER,
            Privileges::DELIVER_INVITE,
            Privileges::DELIVER_REPLY,
            Privileges::QUERY_FREEBUSY,
        ],
        'outbox' => [
            Privileges::SEND,
            Privileges::SEND_INVITE,
            Privileges::SEND_REPLY,
            Privileges::SEND_FREEBUSY,
        ],
    ];

    private static function theTreeOf(string $branch): Privilege
    {
        return $branch === 'inbox'
            ? Privileges::anInboxDefines(Privilege::standard())
            : Privileges::anOutboxDefines(Privilege::standard());
    }
}
