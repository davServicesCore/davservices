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

namespace DavServices\Tests\Unit\Plugin;

use DavServices\CalDav\Calendar;
use DavServices\CalDav\Schedule\Inbox;
use DavServices\CalDav\Schedule\Outbox;
use DavServices\CalDav\Schedule\Privileges;
use DavServices\Dav\Event\CurrentPrincipalRequested;
use DavServices\Dav\Method\PropFind;
use DavServices\Dav\Method\Report;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Plugin\Acl;
use DavServices\Tests\Unit\Acl\ArrayPrivilegeResolver;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\TypedCollection;
use DavServices\Xml\Writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for RFC 6638 §6 as a client reads it. R-CAL-42, P8-06.
 *
 * §6's MUST is about a property, not about an object:
 *
 * > All the scheduling privileges MUST be non-abstract and MUST appear in the
 * > DAV:supported-privilege-set property of scheduling Outbox and Inbox
 * > collections on which they are defined.
 *
 * So it is asked of what a `PROPFIND` actually returns, on all three kinds of
 * collection the sentence distinguishes: the Inbox, the Outbox, and a calendar
 * that is neither. {@see \DavServices\Tests\Unit\CalDav\Schedule\PrivilegesTest}
 * has the two branches themselves, and
 * {@see \DavServices\Tests\Unit\Plugin\AclTest} has the seam this reaches them
 * through.
 *
 * **Nothing here registers the scheduling plugin**, and that is the point.
 * §6's privileges are defined on the collections, so they are reported
 * wherever those collections are mounted — a deployment that answers
 * `DAV:supported-privilege-set` and has an Inbox owes the eight whether or not
 * it has switched implicit scheduling on.
 */
#[CoversClass(Acl::class)]
final class SchedulingPrivilegesTest extends TestCase
{
    private const ALICE = 'principals/alice';

    private const INBOX = '/calendars/inbox';

    private const OUTBOX = '/calendars/outbox';

    private const CALENDAR = '/calendars/work';

    /**
     * **§6.3's second line, as served.** The Inbox reports the aggregate and
     * the three it aggregates, nested inside it — a flat list would tell a
     * client that granting `CALDAV:schedule-deliver` leaves a reply out.
     */
    public function testAnInboxReportsTheThreeDeliveryPrivilegesInsideTheOneThatAggregatesThem(): void
    {
        $body = $this->propFind(self::INBOX);
        $deliver = strpos($body, '<C:schedule-deliver/>');

        self::assertIsInt($deliver);

        foreach ([Privileges::DELIVER_INVITE, Privileges::DELIVER_REPLY, Privileges::QUERY_FREEBUSY] as $name) {
            $child = strpos($body, self::elementFor($name));

            self::assertIsInt($child, $name . ' is reported');
            self::assertLessThan($child, $deliver, $name . ' is written inside what aggregates it');
        }
    }

    /**
     * **And §6.3's third line, on the Outbox.**
     */
    public function testAnOutboxReportsTheThreeSendingPrivilegesInsideTheOneThatAggregatesThem(): void
    {
        $body = $this->propFind(self::OUTBOX);
        $send = strpos($body, '<C:schedule-send/>');

        self::assertIsInt($send);

        foreach ([Privileges::SEND_INVITE, Privileges::SEND_REPLY, Privileges::SEND_FREEBUSY] as $name) {
            $child = strpos($body, self::elementFor($name));

            self::assertIsInt($child, $name . ' is reported');
            self::assertLessThan($child, $send, $name . ' is written inside what aggregates it');
        }
    }

    /**
     * **Each kind reports its own four and nothing of the other's.** §6.1 and
     * §6.2 each close with "The privileges defined in this section are ignored
     * if applied to a resource other than" the kind they are for, and
     * RFC 3744 §5.3 is the property a client offers its user to grant from —
     * a right listed there and then disregarded is worse than one not listed.
     */
    #[DataProvider('eachKindAndWhatItMayNotReport')]
    public function testACollectionReportsNoPrivilegeThatWouldBeIgnoredOnIt(string $target, string $privilege): void
    {
        self::assertStringNotContainsString(self::elementFor($privilege), $this->propFind($target));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function eachKindAndWhatItMayNotReport(): array
    {
        $cases = [];

        foreach (self::THE_FOUR_OF_EACH as $kind => $four) {
            foreach ($four as $privilege) {
                // The other box, and the calendar that is neither: a
                // calendar collection is no scheduling collection, so all
                // eight are ignored on it.
                $elsewhere = $kind === self::INBOX ? self::OUTBOX : self::INBOX;

                $cases[$elsewhere . ' and ' . $privilege] = [$elsewhere, $privilege];
                $cases[self::CALENDAR . ' and ' . $privilege] = [self::CALENDAR, $privilege];
            }
        }

        return $cases;
    }

    /**
     * **None of the eight is served abstract** (§6). RFC 3744 §5.3: "An
     * abstract privilege MUST NOT be used in an ACE for that resource.
     * Servers MUST fail an attempt to set an abstract privilege." A stray
     * `DAV:abstract` here would make a deployment's own grant unsettable, and
     * §6 marks none of them — the two aggregates included, which is where
     * §5.3's own example would have put one.
     */
    #[DataProvider('eachKindAndItsOwn')]
    public function testNoneOfTheEightIsServedAsOneThatMayNotBeGranted(string $target, string $privilege): void
    {
        $body = $this->propFind($target);

        self::assertStringContainsString(self::elementFor($privilege), $body);
        self::assertStringNotContainsString(
            self::elementFor($privilege) . '</d:privilege><d:abstract/>',
            $body,
        );
    }

    /**
     * **And each is served with the sentence and the language §5.3's DTD
     * requires.** `<!ELEMENT supported-privilege (privilege, abstract?,
     * description, supported-privilege*)>` — the description is not optional,
     * and "Servers MUST indicate the human language of the description using
     * the xml:lang attribute".
     */
    #[DataProvider('eachKindAndItsOwn')]
    public function testEachOfTheEightIsServedWithADescriptionInANamedLanguage(
        string $target,
        string $privilege,
    ): void {
        self::assertMatchesRegularExpression(
            '~' . preg_quote(self::elementFor($privilege), '~') . '</d:privilege><d:description xml:lang="[a-z]~',
            $this->propFind($target),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function eachKindAndItsOwn(): array
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
     * **What a collection reports is still everything else it grants.** The
     * branch is hung into the tree of RFC 3744 §3 rather than replacing it: an
     * Inbox that reported only its own four would be telling a client it
     * cannot be read.
     */
    #[DataProvider('theThreeKinds')]
    public function testEveryCollectionStillReportsWhatTheServerGrantsEverywhere(string $target): void
    {
        $body = $this->propFind($target);

        self::assertStringContainsString('<d:privilege><d:read/></d:privilege>', $body);
        self::assertStringContainsString('<d:privilege><d:bind/></d:privilege>', $body);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function theThreeKinds(): array
    {
        return [
            'the Inbox' => [self::INBOX],
            'the Outbox' => [self::OUTBOX],
            'a calendar' => [self::CALENDAR],
        ];
    }

    /**
     * The four of §6.1 and the four of §6.2, by the collection they are
     * defined on.
     */
    private const THE_FOUR_OF_EACH = [
        self::INBOX => [
            Privileges::DELIVER,
            Privileges::DELIVER_INVITE,
            Privileges::DELIVER_REPLY,
            Privileges::QUERY_FREEBUSY,
        ],
        self::OUTBOX => [
            Privileges::SEND,
            Privileges::SEND_INVITE,
            Privileges::SEND_REPLY,
            Privileges::SEND_FREEBUSY,
        ],
    ];

    /**
     * The element a privilege is written as, with the prefix this test's
     * writer was given for the CalDAV namespace.
     */
    private static function elementFor(string $privilege): string
    {
        return '<C:' . substr($privilege, strlen('{urn:ietf:params:xml:ns:caldav}')) . '/>';
    }

    private function propFind(string $target): string
    {
        $calendars = new MemoryCollection('calendars');

        $calendars->add(Inbox::of(new MemoryCollection('inbox')));
        $calendars->add(Outbox::of(new MemoryCollection('outbox')));
        $calendars->add(new TypedCollection('work', Calendar::CALENDAR));

        $server = new Server(
            new Tree((new MemoryCollection(''))->add($calendars)),
            writer: new Writer(['DAV:' => 'd', 'urn:ietf:params:xml:ns:caldav' => 'C']),
        );

        $resolver = ArrayPrivilegeResolver::asTheContractExpects([
            'calendars/inbox',
            'calendars/outbox',
        ]);

        (new Acl($server, $resolver))->register(new Report($server));

        $server->events()->on(
            CurrentPrincipalRequested::class,
            static fn (CurrentPrincipalRequested $event) => $event->answerWith(self::ALICE),
        );

        $propFind = new PropFind($server);

        $server->onMethod('PROPFIND', $propFind(...));

        return (string) $server->handle(new Request(
            'PROPFIND',
            $target,
            headers: new Headers(['Depth' => '0']),
            body: new Body(
                '<D:propfind xmlns:D="DAV:"><D:prop><D:supported-privilege-set/></D:prop></D:propfind>',
            ),
        ))->body();
    }
}
