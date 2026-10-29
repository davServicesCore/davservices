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

use DateTimeImmutable;
use DavServices\Acl\Principal;
use DavServices\CalDav\Calendar;
use DavServices\CalDav\InviteNotification;
use DavServices\CalDav\InviteStatus;
use DavServices\CalDav\IShareable;
use DavServices\CalDav\NotificationCollection;
use DavServices\CalDav\Sharee;
use DavServices\CalDav\SharingAccess;
use DavServices\Dav\Acl\PrincipalInfo;
use DavServices\Dav\Event\CurrentPrincipalRequested;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\INode;
use DavServices\Dav\Method\Post;
use DavServices\Dav\Property\LiveProperties;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Plugin\Notifications;
use DavServices\Plugin\Sharing;
use DavServices\Tests\Unit\CalDav\MemoryShareable;
use DavServices\Tests\Unit\CalDav\MemoryShareTarget;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Xml\Writer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for the `POST` that changes a sharee list. R-CAL-20, P7-05b.
 *
 * §5.3.2 of `caldav-sharing`, which `docs/referenzen/download-extensions.sh`
 * fetches:
 *
 * > The sharer of a shared calendar is able to manipulate the sharee list by
 * > issuing a POST request targeted at the calendar collection resource. The
 * > POST request MUST contain an XML document as its body with the root
 * > element being CS:share.
 * >
 * > The CS:share element in the POST requests MUST contain one or more CS:set
 * > or CS:remove elements. For each CS:set element, the server MUST add the
 * > specified sharee access to the calendar. For each CS:remove element the
 * > server MUST remove the specified sharee access from the shared calendar.
 *
 * with the grammars of §6.26 to §6.28:
 *
 *     <!ELEMENT share (set | remove)*>
 *     <!ELEMENT set (DAV:href, common-name?, summary?, (read | read-write)>
 *     <!ELEMENT remove (DAV:href)>
 *
 * ## Who may do it, and how this server knows
 *
 * "**The sharer** of a shared calendar is able to" — and §5.5.5 is the only
 * place that says who that is:
 *
 * > The DAV:owner WebDAV property MUST be present on a shared calendar and
 * > MUST provide a reference to a principal-URL of the sharer (owner) of the
 * > shared calendar.
 *
 * So the owner is asked for, and a calendar that names none cannot be shared
 * through this server at all. That turns a MUST nothing enforced into one
 * this method requires, which matters because a `POST` passes through none of
 * the write seams {@see Acl} guards: without this, anybody who could reach a
 * calendar could give themselves access to it.
 *
 * ## A refused request changes nothing
 *
 * §5.3.2 says nothing about what happens to a document whose third
 * instruction is bad, and the answer that cannot surprise a sharer is
 * "nothing": the whole document is read before any of it is carried out. A
 * server that applied the first two would leave a calendar shared with
 * somebody the sharer never saw confirmed.
 */
#[CoversClass(Sharing::class)]
#[CoversClass(InviteNotification::class)]
#[CoversClass(Notifications::class)]
final class SharingPostTest extends TestCase
{
    private const NS = 'http://calendarserver.org/ns/';

    /**
     * §5.3.2's second form of address: "a calendar user address or email
     * address", which may be somebody this server has never heard of.
     */
    private const ERIC = '<D:href>mailto:eric@example.com</D:href>';

    /**
     * And its first: "a principal-URL for a sharee hosted on the same
     * server", which is the only one this server can deliver a notification
     * to.
     */
    private const ERIC_HERE = '<D:href>/principals/eric/</D:href>';

    /**
     * **§5.3.2: a `CS:set` adds the sharee**, with everything §6.27 lets the
     * client say, and §5.3.2.1's example is the request.
     *
     * The state is `CS:invite-noresponse` because §6.27's grammar carries no
     * state at all and §6.6 says what the absence means: "the sharee has
     * never replied to the corresponding sharing invite". It is the server's
     * to set, not the client's to claim.
     */
    public function testASetAddsAShareeWhoHasNotRepliedYet(): void
    {
        $calendar = new MemoryShareTarget('work');

        $response = $this->share(
            '<CS:set>' . self::ERIC . '<CS:common-name>Eric York</CS:common-name>'
            . '<CS:summary>Shared workspace</CS:summary><CS:read-write/></CS:set>',
            $calendar,
        );

        self::assertSame(200, $response->status());
        self::assertSame('mailto:eric@example.com', self::only($calendar)->href());
        self::assertSame(SharingAccess::ReadWrite, self::only($calendar)->access());
        self::assertSame(InviteStatus::NoResponse, self::only($calendar)->status());
        self::assertSame('Eric York', self::only($calendar)->commonName());
        self::assertSame('Shared workspace', self::only($calendar)->summary());
    }

    /**
     * **And a `CS:set` for somebody already there keeps how they answered.**
     * §6.8 says what such a message is: "the sharing invite is an update for
     * one they previously accepted" — so the reply still stands, and a server
     * that reset it to `invite-noresponse` would ask a sharee to accept again
     * something they are already using.
     */
    public function testASetForSomebodyAlreadyThereKeepsHowTheyAnswered(): void
    {
        $calendar = new MemoryShareTarget('work', sharees: [
            new Sharee('mailto:eric@example.com', SharingAccess::ReadWrite, InviteStatus::Accepted),
        ]);

        $this->share('<CS:set>' . self::ERIC . '<CS:read/></CS:set>', $calendar);

        self::assertCount(1, $calendar->sharees());
        self::assertSame(InviteStatus::Accepted, self::only($calendar)->status());
        self::assertSame(SharingAccess::Read, self::only($calendar)->access());
    }

    /**
     * **§6.28: a `CS:remove` takes all of it away.** "All access to the shared
     * calendar is removed for the sharee."
     */
    public function testARemoveTakesTheShareeAway(): void
    {
        $calendar = new MemoryShareTarget('work', sharees: [
            new Sharee('mailto:eric@example.com', SharingAccess::Read, InviteStatus::Accepted),
        ]);

        self::assertSame(200, $this->share('<CS:remove>' . self::ERIC . '</CS:remove>', $calendar)->status());
        self::assertSame([], $calendar->sharees());
    }

    /**
     * **§5.3.2.2's example: several in one request**, one sharee's access
     * lowered while another is taken off the list entirely.
     */
    public function testSeveralInstructionsAreAllCarriedOut(): void
    {
        $calendar = new MemoryShareTarget('work', sharees: [
            new Sharee('mailto:wilfredo@example.com', SharingAccess::ReadWrite, InviteStatus::Accepted),
        ]);

        $this->share(
            '<CS:set>' . self::ERIC . '<CS:summary>Shared workspace</CS:summary><CS:read-write/></CS:set>'
            . '<CS:remove><D:href>mailto:wilfredo@example.com</D:href></CS:remove>',
            $calendar,
        );

        self::assertCount(1, $calendar->sharees());
        self::assertSame('mailto:eric@example.com', self::only($calendar)->href());
    }

    /**
     * **And in the order they arrive.** §6.26's `(set | remove)*` is a
     * sequence of instructions, and two about one sharee in one document can
     * only mean what their order says — a server that grouped them would
     * decide by an order the client cannot see.
     */
    #[DataProvider('bothOrders')]
    public function testTheInstructionsAreCarriedOutInTheOrderTheyArrive(string $instructions, int $left): void
    {
        $calendar = new MemoryShareTarget('work');

        $this->share($instructions, $calendar);

        self::assertCount($left, $calendar->sharees());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function bothOrders(): iterable
    {
        yield 'added and then taken off again' => [
            '<CS:set>' . self::ERIC . '<CS:read/></CS:set><CS:remove>' . self::ERIC . '</CS:remove>',
            0,
        ];

        yield 'taken off and then added' => [
            '<CS:remove>' . self::ERIC . '</CS:remove><CS:set>' . self::ERIC . '<CS:read/></CS:set>',
            1,
        ];
    }

    /**
     * **The answer is the one §5.3.2.1 and §5.3.2.2 both show**: `200 OK`
     * with `Cache-Control: no-cache` and nothing in the body. There is
     * nothing to say that the sharee list does not say, and a client that
     * wants it reads `CS:invite`.
     */
    public function testTheAnswerIsTheOneTheExamplesShow(): void
    {
        $response = $this->share('<CS:set>' . self::ERIC . '<CS:read/></CS:set>', new MemoryShareTarget('work'));

        self::assertSame(200, $response->status());
        self::assertSame('no-cache', $response->headers()->first('Cache-Control'));
        self::assertSame('', (string) $response->body());
    }

    /**
     * §6.27 makes `common-name` and `summary` optional, and nothing is
     * invented for a sharee who arrives without them.
     */
    public function testASetWithoutANameOrASummaryIsStillASharee(): void
    {
        $calendar = new MemoryShareTarget('work');

        $this->share('<CS:set>' . self::ERIC . '<CS:read/></CS:set>', $calendar);

        self::assertNull(self::only($calendar)->commonName());
        self::assertNull(self::only($calendar)->summary());
    }

    /**
     * **Each of §5.5.1's two levels is granted by its own element**, §6.27's
     * `(read | read-write)` admitting one of them.
     */
    #[DataProvider('levels')]
    public function testEachAccessElementGrantsItsLevel(string $element, SharingAccess $granted): void
    {
        $calendar = new MemoryShareTarget('work');

        $this->share('<CS:set>' . self::ERIC . $element . '</CS:set>', $calendar);

        self::assertSame($granted, self::only($calendar)->access());
    }

    /**
     * @return iterable<string, array{string, SharingAccess}>
     */
    public static function levels(): iterable
    {
        yield 'read' => ['<CS:read/>', SharingAccess::Read];
        yield 'read and write' => ['<CS:read-write/>', SharingAccess::ReadWrite];
    }

    /**
     * §5.3.2 puts the request "targeted at the calendar collection resource",
     * so a share aimed at anything else is refused.
     */
    public function testAShareAimedAtSomethingThatIsNoCalendarIsRefused(): void
    {
        $response = $this->share(
            '<CS:set>' . self::ERIC . '<CS:read/></CS:set>',
            new MemoryCollection('work'),
        );

        self::assertSame(403, $response->status());
    }

    /**
     * **And a calendar whose store will not have its list changed is refused
     * too**, which is §5.5.2's "always public (always shared)" calendar: its
     * sharee list is readable and is nobody's to change by request.
     *
     * The store says so by not being an `IShareTarget`. A store that named
     * `CS:can-be-shared` without being one would be advertising what it
     * cannot do, and this is the answer its clients would get.
     */
    public function testAShareOfACalendarThatWillNotHaveItsListChangedIsRefused(): void
    {
        $response = $this->share(
            '<CS:set>' . self::ERIC . '<CS:read/></CS:set>',
            Calendar::of(new MemoryShareable('work', [], [IShareable::CAN_BE_SHARED])),
        );

        self::assertSame(403, $response->status());
    }

    /**
     * **Nobody may share anonymously.** §6.15's grammar makes `CS:organizer`
     * a required child of the notification every change owes, and a server
     * that does not know who is asking cannot fill it — quite apart from the
     * sharer being the only one allowed to ask at all.
     */
    public function testAShareByNobodyIsRefused(): void
    {
        $response = $this->share(
            '<CS:set>' . self::ERIC . '<CS:read/></CS:set>',
            new MemoryShareTarget('work'),
            asking: null,
        );

        self::assertSame(403, $response->status());
    }

    /**
     * **A calendar that says nowhere who owns it cannot be shared**, and
     * §5.5.5's MUST is why: "The DAV:owner WebDAV property MUST be present on
     * a shared calendar". Without it this server cannot tell the sharer from
     * anybody else who can reach the calendar.
     */
    public function testAShareOfACalendarWithNoOwnerIsRefused(): void
    {
        $response = $this->share(
            '<CS:set>' . self::ERIC . '<CS:read/></CS:set>',
            new MemoryShareTarget('work', owner: null),
        );

        self::assertSame(403, $response->status());
    }

    /**
     * **And a calendar whose `DAV:owner` names nobody cannot be shared
     * either.** RFC 3744 §5.1 makes the address optional —
     * `<!ELEMENT owner (href?)>` — so the property can be there and say
     * nothing, and a property that says nothing identifies nobody. §5.5.5
     * asks for more than the property: it has to "provide a reference to a
     * principal-URL of the sharer".
     */
    public function testACalendarWhoseOwnerNamesNobodyCannotBeShared(): void
    {
        $response = $this->share(
            '<CS:set>' . self::ERIC . '<CS:read/></CS:set>',
            new MemoryShareTarget('work', owner: ''),
        );

        self::assertSame(403, $response->status());
    }

    /**
     * **Nor one whose owner is nothing but blank space.** The property is
     * there, it has a `DAV:href`, and the address in it is no address — which
     * §5.5.5 does not meet either: it wants "a reference to a principal-URL
     * of the sharer".
     */
    public function testACalendarWhoseOwnerIsBlankCannotBeShared(): void
    {
        $response = $this->share(
            '<CS:set>' . self::ERIC . '<CS:read/></CS:set>',
            new MemoryShareTarget('work', owner: ' '),
        );

        self::assertSame(403, $response->status());
    }

    /**
     * **But an owner written across several lines is still the owner.** A
     * `DAV:owner` whose `DAV:href` arrives pretty-printed carries the
     * newlines and the indentation with it, and RFC 4918's own examples are
     * printed exactly that way — so a server that compared the text as it
     * stood would refuse the sharer of every store that formats its
     * properties.
     */
    public function testAnOwnerWrittenAcrossSeveralLinesIsStillTheOwner(): void
    {
        $calendar = new MemoryShareTarget('work', owner: "\n            /principals/alice/\n        ");

        $response = $this->share('<CS:set>' . self::ERIC . '<CS:read/></CS:set>', $calendar);

        self::assertSame(200, $response->status());
        self::assertCount(1, $calendar->sharees());
    }

    /**
     * **And nobody may share a calendar that nobody owns.** This is the case
     * the two guards exist for together: with no owner the sharer is nobody,
     * with nobody asking the asker is nobody, and a server that compared them
     * without first insisting that somebody is asking would find them equal
     * and let an unauthenticated client give itself access.
     *
     * A mutation round on 29.10.2026 is what found it: taking the `throw` out
     * of the first guard changed nothing any case could see, because every
     * calendar in them had an owner.
     */
    public function testNobodyMayShareACalendarThatNobodyOwns(): void
    {
        $calendar = new MemoryShareTarget('work', owner: null);

        $response = $this->share(
            '<CS:set>' . self::ERIC . '<CS:read/></CS:set>',
            $calendar,
            asking: null,
        );

        self::assertSame(403, $response->status());
        self::assertSame([], $calendar->sharees());
    }

    /**
     * **Nor one owned on another server.** This server cannot establish that
     * whoever is asking is a principal it does not host, and §5.5.5 wants "a
     * principal-URL of the sharer" — so an address that
     * {@see \DavServices\Dav\Server::pathOfUrl()} refuses as another
     * server's names nobody here.
     */
    public function testACalendarOwnedOnAnotherServerCannotBeShared(): void
    {
        $response = $this->share(
            '<CS:set>' . self::ERIC . '<CS:read/></CS:set>',
            new MemoryShareTarget('work', owner: 'http://elsewhere.example.com/principals/alice/'),
        );

        self::assertSame(403, $response->status());
    }

    /**
     * **And somebody who is not the owner is refused**, which is the half
     * that makes the guard worth having: §5.3.2 gives the sharee list to "the
     * sharer of a shared calendar", and a `POST` reaches no seam that
     * {@see Acl} guards.
     */
    public function testAShareBySomebodyWhoIsNotTheOwnerIsRefused(): void
    {
        $calendar = new MemoryShareTarget('work');

        $response = $this->share(
            '<CS:set>' . self::ERIC . '<CS:read/></CS:set>',
            $calendar,
            asking: 'principals/eric',
        );

        self::assertSame(403, $response->status());
        self::assertSame([], $calendar->sharees());
    }

    /**
     * **§5.3.2: "The CS:share element … MUST contain one or more CS:set or
     * CS:remove elements."** A document that asks for nothing is refused
     * rather than answered `200`, because `200` would tell a sharer that
     * whatever they meant had been done.
     */
    #[DataProvider('documentsThatAskForNothing')]
    public function testAShareThatAsksForNothingIsRefused(string $instructions): void
    {
        self::assertSame(400, $this->share($instructions, new MemoryShareTarget('work'))->status());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function documentsThatAskForNothing(): iterable
    {
        yield 'empty' => [''];
        yield 'nothing the grammar admits' => ['<CS:summary>Shared workspace</CS:summary>'];
    }

    /**
     * §6.27 and §6.28 both begin their grammar with `DAV:href`, and an
     * instruction about nobody cannot be carried out.
     */
    #[DataProvider('instructionsAboutNobody')]
    public function testAnInstructionWithoutAnAddressIsRefused(string $instructions): void
    {
        self::assertSame(400, $this->share($instructions, new MemoryShareTarget('work'))->status());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function instructionsAboutNobody(): iterable
    {
        yield 'a set' => ['<CS:set><CS:read/></CS:set>'];
        yield 'a remove' => ['<CS:remove/>'];
        yield 'a set whose address is blank' => ['<CS:set><D:href> </D:href><CS:read/></CS:set>'];
    }

    /**
     * **§6.27's `(read | read-write)` is a choice, and a choice of one.**
     * Neither is an instruction that grants nothing; both is a request this
     * server will not guess at, and guessing the stronger of two access
     * levels is exactly the guess not to make.
     */
    #[DataProvider('setsWithTheWrongNumberOfLevels')]
    public function testASetThatDoesNotNameOneAccessLevelIsRefused(string $instructions): void
    {
        $calendar = new MemoryShareTarget('work');

        self::assertSame(400, $this->share($instructions, $calendar)->status());
        self::assertSame([], $calendar->sharees());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function setsWithTheWrongNumberOfLevels(): iterable
    {
        yield 'neither' => ['<CS:set>' . self::ERIC . '</CS:set>'];
        yield 'both' => ['<CS:set>' . self::ERIC . '<CS:read/><CS:read-write/></CS:set>'];
    }

    /**
     * **A refused request changes nothing at all.** The whole document is
     * read before any of it is carried out, so a good instruction in front of
     * a bad one is not a change the sharer never confirmed.
     */
    public function testARefusedRequestChangesNothing(): void
    {
        $calendar = new MemoryShareTarget('work', sharees: [
            new Sharee('mailto:wilfredo@example.com', SharingAccess::Read, InviteStatus::Accepted),
        ]);

        $response = $this->share(
            '<CS:set>' . self::ERIC . '<CS:read/></CS:set>'
            . '<CS:remove><D:href>mailto:wilfredo@example.com</D:href></CS:remove>'
            . '<CS:set><CS:read/></CS:set>',
            $calendar,
        );

        self::assertSame(400, $response->status());
        self::assertCount(1, $calendar->sharees());
        self::assertSame('mailto:wilfredo@example.com', self::only($calendar)->href());
    }

    /**
     * A `POST` whose body is no `CS:share` is not this plugin's at all, and
     * the method says so: {@see \DavServices\Dav\Method\Post} answers `415`
     * for a root element nobody registered.
     */
    public function testAPostThatIsNoShareIsNotAnsweredHere(): void
    {
        $response = $this->post(
            '<CS:other xmlns:CS="' . self::NS . '"/>',
            new MemoryShareTarget('work'),
            'principals/alice',
        );

        self::assertSame(415, $response->status());
    }

    /**
     * **§5.3.2's MUST: whoever is affected is told.**
     *
     * > In each case the server MUST send a notification message to any
     * > sharees whose status is changed (added, modified or removed),
     * > indicating to them a change in status for the shared calendar. […]
     * > This is accomplished by sending a CS:invite-notification
     * > (Section 6.15) notification to each sharee.
     *
     * Asserted as one string from the uid onwards, because §6.15's grammar is
     * a sequence and the order is half of what is being asserted:
     *
     *     <!ELEMENT invite-notification (
     *                 uid, DAV:href,
     *                 (invite-noresponse | invite-deleted |
     *                 invite-accepted | invite-declined),
     *                 access, hosturl, organizer,
     *                 summary?,
     *                 CALDAV:supported-calendar-component-set?>
     *
     * The uid itself is a digest, so it is matched for its shape; what it is
     * derived from is {@see \DavServices\CalDav\InviteNotification}'s business.
     *
     * And it arrives wrapped as §6.24 and §6.25 require — a `CS:notification`
     * whose first child is a `CS:dtstamp`, which is why the clock is a fixed
     * one here.
     */
    public function testAddingAShareeLeavesThemTheWholeOfTheMemosNotification(): void
    {
        $eric = new MemoryCollection('eric');

        $this->shareWith(
            $eric,
            '<CS:set>' . self::ERIC_HERE . '<CS:summary>Shared workspace</CS:summary><CS:read-write/></CS:set>',
            new MemoryShareTarget('work'),
        );

        $document = self::theOneNotificationIn($eric);

        // §6.24: `<!ELEMENT notification (CS:dtstamp, (invite-notification |
        // invite-reply)>`. The root carries the namespace declarations, which
        // is {@see Writer}'s business and not this case's, so the sequence is
        // what is asserted rather than the whole opening tag.
        self::assertStringContainsString('<cs:notification ', $document);
        self::assertStringContainsString(
            '<cs:dtstamp>2026-10-29T19:30:00Z</cs:dtstamp><cs:invite-notification>',
            $document,
        );
        self::assertMatchesRegularExpression('~<cs:uid>[0-9a-f]{64}</cs:uid>~', $document);
        self::assertStringContainsString(
            '</cs:uid><d:href>/principals/eric/</d:href>'
            . '<cs:invite-noresponse/>'
            . '<cs:access><cs:read-write/></cs:access>'
            . '<cs:hosturl><d:href>/calendars/work/</d:href></cs:hosturl>'
            . '<cs:organizer><d:href>/principals/alice</d:href>'
            . '<cs:common-name>Alice</cs:common-name></cs:organizer>'
            . '<cs:summary>Shared workspace</cs:summary>'
            . '</cs:invite-notification></cs:notification>',
            $document,
        );
    }

    /**
     * **And whoever is taken off is told that it is gone**, with §6.7's
     * element: "this element is used to indicate to the sharee that a shared
     * calendar has been unshared by the sharer."
     *
     * **With the access they had**, because §6.15's grammar makes `access`
     * required even in this message — so the old entry is what the notice is
     * built from, and a server that had already forgotten it could not write
     * a conforming document.
     */
    public function testTakingAShareeOffTellsThemTheCalendarIsGone(): void
    {
        $eric = new MemoryCollection('eric');

        $this->shareWith(
            $eric,
            '<CS:remove>' . self::ERIC_HERE . '</CS:remove>',
            new MemoryShareTarget('work', sharees: [
                new Sharee('/principals/eric/', SharingAccess::Read, InviteStatus::Accepted),
            ]),
        );

        self::assertStringContainsString(
            '<cs:invite-deleted/><cs:access><cs:read/></cs:access>',
            self::theOneNotificationIn($eric),
        );
    }

    /**
     * **The resource is named after the invitation**, so that a second
     * message about one invitation finds the first — and so that two messages
     * about two different invitations in one collection do not land on each
     * other. §4 says nothing about what a notification is called; the `.xml`
     * is this library's own and the identifier is §6.16's.
     */
    public function testTheNotificationIsNamedAfterTheInvitation(): void
    {
        $eric = new MemoryCollection('eric');

        $this->shareWith($eric, '<CS:set>' . self::ERIC_HERE . '<CS:read/></CS:set>', new MemoryShareTarget('work'));

        $children = $eric->children();
        $notification = $children[0] ?? self::fail('nothing was deposited');

        self::assertMatchesRegularExpression('~^[0-9a-f]{64}\.xml$~', $notification->name());
    }

    /**
     * **A sharee who needs no message does not stop the next one getting
     * theirs.** §5.3.2 owes a notification to "any sharees whose status is
     * changed", and the list is walked rather than cut short at the first
     * sharee there is nothing to say to.
     */
    public function testAShareeWhoNeedsNoMessageDoesNotStopTheNext(): void
    {
        $eric = new MemoryCollection('eric');
        $unchanged = new Sharee('mailto:wilfredo@example.com', SharingAccess::Read, InviteStatus::Accepted);

        $this->shareWith(
            $eric,
            '<CS:set><D:href>mailto:wilfredo@example.com</D:href><CS:read/></CS:set>'
            . '<CS:set>' . self::ERIC_HERE . '<CS:read-write/></CS:set>',
            new MemoryShareTarget('work', sharees: [
                $unchanged,
                new Sharee('/principals/eric/', SharingAccess::Read, InviteStatus::Accepted),
            ]),
        );

        self::assertStringContainsString('<cs:read-write/>', self::theOneNotificationIn($eric));
    }

    /**
     * And the same where somebody is taken off the list behind a sharee who
     * stays on it: the removals are walked too.
     */
    public function testAShareeWhoStaysDoesNotStopTheNextBeingToldTheyAreGone(): void
    {
        $eric = new MemoryCollection('eric');

        $this->shareWith(
            $eric,
            '<CS:remove>' . self::ERIC_HERE . '</CS:remove>',
            new MemoryShareTarget('work', sharees: [
                new Sharee('mailto:wilfredo@example.com', SharingAccess::Read, InviteStatus::Accepted),
                new Sharee('/principals/eric/', SharingAccess::Read, InviteStatus::Accepted),
            ]),
        );

        self::assertStringContainsString('<cs:invite-deleted/>', self::theOneNotificationIn($eric));
    }

    /**
     * **An email address is not a path, however much it looks like one.**
     * §5.3.2's three forms are different address spaces, and
     * `mailto:principals/eric` is an address in the second — a server that
     * stripped the scheme would write into the notification collection of
     * whoever happened to live at that path, on the strength of an email
     * address that only resembled it.
     */
    public function testAnEmailAddressIsNotReadAsAPath(): void
    {
        $eric = new MemoryCollection('eric');
        $calendar = new MemoryShareTarget('work');

        $response = $this->shareWith(
            $eric,
            '<CS:set><D:href>mailto:principals/eric</D:href><CS:read/></CS:set>',
            $calendar,
        );

        self::assertSame(200, $response->status());
        self::assertSame([], $eric->children());
        self::assertCount(1, $calendar->sharees());
    }

    /**
     * **§5.3.2: "The server SHOULD NOT send notification messages to sharees
     * whose status is unchanged."** A `CS:set` that asks for what is already
     * so is no change, and a sharee told about it again would be asked to
     * look at something that had not happened.
     */
    public function testAShareeWhoseStatusDidNotChangeIsNotToldAgain(): void
    {
        $eric = new MemoryCollection('eric');

        $this->shareWith(
            $eric,
            '<CS:set>' . self::ERIC_HERE . '<CS:summary>Shared workspace</CS:summary><CS:read/></CS:set>',
            new MemoryShareTarget('work', sharees: [
                new Sharee('/principals/eric/', SharingAccess::Read, InviteStatus::Accepted, 'Eric York', 'Shared workspace'),
            ]),
        );

        self::assertSame([], $eric->children());
    }

    /**
     * **And a real change is told about**, which is the half that keeps the
     * case above from passing on a plugin that notifies nobody ever.
     *
     * What counts as a change is **what a notification carries**: §6.15 holds
     * the state, the access and the summary, so a difference in any of those
     * is a difference the sharee is being told about.
     */
    #[DataProvider('changesWorthTelling')]
    public function testAChangeWorthTellingIsTold(string $instruction): void
    {
        $eric = new MemoryCollection('eric');

        $this->shareWith(
            $eric,
            $instruction,
            new MemoryShareTarget('work', sharees: [
                new Sharee('/principals/eric/', SharingAccess::Read, InviteStatus::Accepted, 'Eric York', 'Shared workspace'),
            ]),
        );

        self::assertStringContainsString('<cs:invite-accepted/>', self::theOneNotificationIn($eric));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function changesWorthTelling(): iterable
    {
        yield 'the access' => [
            '<CS:set>' . self::ERIC_HERE . '<CS:summary>Shared workspace</CS:summary><CS:read-write/></CS:set>',
        ];

        yield 'the summary' => [
            '<CS:set>' . self::ERIC_HERE . '<CS:summary>Our week</CS:summary><CS:read/></CS:set>',
        ];
    }

    /**
     * **But a new common name is nothing to tell them about**, and §6.15's
     * grammar is the reason: the notification carries no `CS:common-name` for
     * the sharee at all, so there is nothing in the message that would
     * differ. It is a change to what the *sharer* sees in `CS:invite`.
     */
    public function testANewCommonNameIsNothingToTellTheShareeAbout(): void
    {
        $eric = new MemoryCollection('eric');

        $this->shareWith(
            $eric,
            '<CS:set>' . self::ERIC_HERE . '<CS:common-name>E. York</CS:common-name>'
            . '<CS:summary>Shared workspace</CS:summary><CS:read/></CS:set>',
            $calendar = new MemoryShareTarget('work', sharees: [
                new Sharee('/principals/eric/', SharingAccess::Read, InviteStatus::Accepted, 'Eric York', 'Shared workspace'),
            ]),
        );

        self::assertSame([], $eric->children());
        self::assertSame('E. York', self::only($calendar)->commonName());
    }

    /**
     * **A sharee who is nobody on this server is written to nowhere**, and
     * §5.3.2 says as much: "the sharee might not be a user on the same server
     * - though in that case how invitations are sent or access enabled is out
     * of scope for this specification."
     *
     * The change still happens and the answer is still `200`: out of scope is
     * not a failure.
     */
    #[DataProvider('addressesOfTheirOwn')]
    public function testAShareeWhoIsNobodyHereIsWrittenToNowhere(string $address): void
    {
        $eric = new MemoryCollection('eric');
        $calendar = new MemoryShareTarget('work');

        $response = $this->shareWith(
            $eric,
            '<CS:set><D:href>' . $address . '</D:href><CS:read/></CS:set>',
            $calendar,
        );

        self::assertSame(200, $response->status());
        self::assertSame([], $eric->children());
        self::assertCount(1, $calendar->sharees());
    }

    /**
     * §5.3.2's other two forms, each with a scheme of its own.
     *
     * @return iterable<string, array{string}>
     */
    public static function addressesOfTheirOwn(): iterable
    {
        yield 'an email address' => ['mailto:eric@example.com'];
        yield 'a calendar user address' => ['urn:uuid:4c4c4c4c-eric'];
    }

    /**
     * **But an absolute principal-URL on this server is one of ours.** §5.3.2
     * calls the first form "a principal-URL for a sharee hosted on the same
     * server", and nothing says it has to arrive as a path — so `http` and
     * `https` are let through and {@see \DavServices\Dav\Server::pathOfUrl()}
     * decides whose server it names, by the same rule a `Destination` header
     * is read with.
     */
    public function testAnAbsolutePrincipalUrlOnThisServerIsOneOfOurs(): void
    {
        $eric = new MemoryCollection('eric');

        $this->shareWith(
            $eric,
            '<CS:set><D:href>http://calendar.example.com/principals/eric/</D:href><CS:read/></CS:set>',
            new MemoryShareTarget('work'),
        );

        self::assertStringContainsString('<cs:invite-noresponse/>', self::theOneNotificationIn($eric));
    }

    /**
     * **A sharee on another server is written to nowhere**, and the change
     * still happens: §5.3.2's "the sharee might not be a user on the same
     * server" covers an address this server simply does not host, however it
     * is spelt. {@see \DavServices\Dav\Server::pathOfUrl()} is what decides,
     * by the same rule a `Destination` header is read with.
     */
    public function testAShareeOnAnotherServerIsWrittenToNowhere(): void
    {
        $eric = new MemoryCollection('eric');
        $calendar = new MemoryShareTarget('work');

        $response = $this->shareWith(
            $eric,
            '<CS:set><D:href>http://elsewhere.example.com/principals/eric/</D:href><CS:read/></CS:set>',
            $calendar,
        );

        self::assertSame(200, $response->status());
        self::assertSame([], $eric->children());
        self::assertCount(1, $calendar->sharees());
    }

    /**
     * **And neither is one whose principal is not there at all.** §6.10 has
     * an element for a sharee who "is not a valid calendar user known to the
     * server", and it is the *store* that decides to use it: this server
     * carries out what it was asked and finds nobody to write to.
     */
    public function testAShareeWhosePrincipalIsNotThereIsWrittenToNowhere(): void
    {
        $eric = new MemoryCollection('eric');
        $calendar = new MemoryShareTarget('work');

        $response = $this->shareWith(
            $eric,
            '<CS:set><D:href>/principals/nobody/</D:href><CS:read/></CS:set>',
            $calendar,
        );

        self::assertSame(200, $response->status());
        self::assertSame([], $eric->children());
        self::assertCount(1, $calendar->sharees());
    }

    /**
     * **And neither is an address that names something which is no principal
     * at all.** §5.3.2 has the first form be "a principal-URL for a sharee",
     * so a path that leads to a calendar leads to nobody — and a server that
     * deposited a notification wherever a path happened to resolve would
     * write into resources that are not notification collections.
     */
    public function testAnAddressThatNamesNoPrincipalIsWrittenToNowhere(): void
    {
        $eric = new MemoryCollection('eric');
        $calendar = new MemoryShareTarget('work');

        $response = $this->shareWith(
            $eric,
            '<CS:set><D:href>/calendars/work/</D:href><CS:read/></CS:set>',
            $calendar,
        );

        self::assertSame(200, $response->status());
        self::assertSame([], $eric->children());
        self::assertCount(1, $calendar->sharees());
    }

    /**
     * **An address that climbs out of the tree is nobody either, and does not
     * take the request down with it.** The address comes from a client, and
     * `Uri\Path` refuses one that "climbs above the root of the server" —
     * which has to be an answer here rather than an exception nobody catches,
     * because §5.3.2's change has already been made by then.
     */
    public function testAnAddressThatClimbsOutOfTheTreeIsWrittenToNowhere(): void
    {
        $eric = new MemoryCollection('eric');
        $calendar = new MemoryShareTarget('work');

        $response = $this->shareWith(
            $eric,
            '<CS:set><D:href>/../principals/eric/</D:href><CS:read/></CS:set>',
            $calendar,
        );

        self::assertSame(200, $response->status());
        self::assertSame([], $eric->children());
        self::assertCount(1, $calendar->sharees());
    }

    /**
     * And the same of a `DAV:owner` that climbs out of the tree: it names
     * nobody, so nobody is the sharer, so nothing is shared. **Before**
     * anything changes, which is the difference from the case above — the
     * owner is read first.
     */
    public function testACalendarWhoseOwnerClimbsOutOfTheTreeCannotBeShared(): void
    {
        $calendar = new MemoryShareTarget('work', owner: '/../principals/alice/');

        $response = $this->share('<CS:set>' . self::ERIC . '<CS:read/></CS:set>', $calendar);

        self::assertSame(403, $response->status());
        self::assertSame([], $calendar->sharees());
    }

    /**
     * And a sharer whose own principal is not in the tree is named by address
     * alone: this server has just taken somebody's word for who is asking,
     * and a dangling `DAV:owner` is a deployment's problem rather than a
     * reason to undo a change that has already been made.
     */
    public function testASharerWhoseOwnPrincipalIsMissingIsNamedByAddressAlone(): void
    {
        $eric = new MemoryCollection('eric');

        $this->shareOn(
            $this->server(
                new MemoryShareTarget('work', owner: '/principals/ghost/'),
                asking: 'principals/ghost',
                ericsNotifications: NotificationCollection::of($eric),
            ),
            '<CS:set>' . self::ERIC_HERE . '<CS:read/></CS:set>',
        );

        self::assertStringContainsString(
            '<cs:organizer><d:href>/principals/ghost</d:href></cs:organizer>',
            self::theOneNotificationIn($eric),
        );
    }

    /**
     * **And neither is one with no notification collection**, which §4.1.1
     * calls a calendar user "not enabled for notification messages on the
     * server". There is nowhere to put it, and §5.3.2's MUST cannot reach
     * further than the mechanism §4 provides.
     */
    public function testAShareeWithNoNotificationCollectionIsWrittenToNowhere(): void
    {
        $eric = new MemoryCollection('eric');
        $calendar = new MemoryShareTarget('work');

        $response = $this->shareWith(
            $eric,
            '<CS:set><D:href>/principals/wilfredo/</D:href><CS:read/></CS:set>',
            $calendar,
        );

        self::assertSame(200, $response->status());
        self::assertSame([], $eric->children());
        self::assertCount(1, $calendar->sharees());
    }

    /**
     * **Nor somewhere that is no collection at all.** A deployment that put
     * something else where a notification collection belongs gets no
     * notification rather than a failed share: §5.3.2's change is what the
     * sharer asked for, and the message is what this server owes on top of
     * it.
     */
    public function testSomewhereThatIsNoCollectionIsNoPlaceForANotification(): void
    {
        $calendar = new MemoryShareTarget('work');

        $response = $this->shareOn(
            $this->server($calendar, ericsNotifications: new MemoryFile('eric', 'not a collection')),
            '<CS:set>' . self::ERIC_HERE . '<CS:read/></CS:set>',
        );

        self::assertSame(200, $response->status());
        self::assertCount(1, $calendar->sharees());
    }

    /**
     * **A sharee the store calls invalid is written to nowhere either**, and
     * §6.15's grammar is why: its state is one of four elements and
     * `CS:invite-invalid` is not among them. §6.10 says what the state means
     * — "the corresponding sharee is not a valid calendar user known to the
     * server" — so there is nobody to send anything to, and the entry stands
     * on the calendar to tell the sharer so.
     */
    public function testAShareeTheStoreCallsInvalidIsWrittenToNowhere(): void
    {
        $eric = new MemoryCollection('eric');
        $calendar = new MemoryShareTarget(
            'work',
            whenShared: static fn (Sharee $sharee): Sharee => new Sharee(
                $sharee->href(),
                $sharee->access(),
                InviteStatus::Invalid,
            ),
        );

        $this->shareWith($eric, '<CS:set>' . self::ERIC_HERE . '<CS:read/></CS:set>', $calendar);

        self::assertSame([], $eric->children());
        self::assertSame(InviteStatus::Invalid, self::only($calendar)->status());
    }

    /**
     * **A change the store declined tells nobody**, because what is notified
     * about is worked out from the list before and the list after rather than
     * from what was asked for. A store that quietly keeps its list as it was
     * cannot cause a message about something that is not so.
     */
    public function testAChangeTheStoreDeclinedTellsNobody(): void
    {
        $eric = new MemoryCollection('eric');
        $calendar = new MemoryShareTarget('work', whenShared: static fn (): ?Sharee => null);

        $response = $this->shareWith($eric, '<CS:set>' . self::ERIC_HERE . '<CS:read/></CS:set>', $calendar);

        self::assertSame(200, $response->status());
        self::assertSame([], $eric->children());
        self::assertSame([], $calendar->sharees());
    }

    /**
     * **The second notice about one invitation replaces the first.** §4 makes
     * deleting a notification "no longer relevant or valid" a server's MAY,
     * and P7-06 declined it as a judgement about somebody's data — but this
     * is the one case where there is no judgement to make: the superseded
     * message says the access is one thing while this server has just made it
     * another, and a client acting on the stale one acts wrongly.
     *
     * The invitation is what the uid identifies (§6.16), so the resource is
     * the same one both times.
     */
    public function testTheSecondNoticeAboutOneInvitationReplacesTheFirst(): void
    {
        $eric = new MemoryCollection('eric');
        $calendar = new MemoryShareTarget('work');

        $this->shareWith($eric, '<CS:set>' . self::ERIC_HERE . '<CS:read/></CS:set>', $calendar);
        $this->shareWith($eric, '<CS:set>' . self::ERIC_HERE . '<CS:read-write/></CS:set>', $calendar);

        self::assertStringContainsString(
            '<cs:access><cs:read-write/></cs:access>',
            self::theOneNotificationIn($eric),
        );
    }

    /**
     * §6.18 makes the sharer's `CS:common-name` optional — "In some cases
     * servers might have directory information that includes only the common
     * name" — so a principal nobody named is named by address alone rather
     * than with an empty element.
     */
    public function testASharerNobodyNamedIsNamedByAddressAlone(): void
    {
        $calendar = new MemoryShareTarget('work');
        $eric = new MemoryCollection('eric');

        $this->shareOn(
            $this->server($calendar, sharerIsCalled: null, ericsNotifications: NotificationCollection::of($eric)),
            '<CS:set>' . self::ERIC_HERE . '<CS:read/></CS:set>',
        );

        $document = self::theOneNotificationIn($eric);

        self::assertStringContainsString(
            '<cs:organizer><d:href>/principals/alice</d:href></cs:organizer>',
            $document,
        );
        self::assertStringNotContainsString('common-name', $document);
    }

    /**
     * And a refused request tells nobody, for the same reason it changes
     * nothing: the instructions are carried out after the whole document has
     * been read, and notifications after that.
     */
    public function testARefusedRequestTellsNobody(): void
    {
        $eric = new MemoryCollection('eric');

        $response = $this->shareWith(
            $eric,
            '<CS:set>' . self::ERIC_HERE . '<CS:read/></CS:set><CS:remove/>',
            new MemoryShareTarget('work'),
        );

        self::assertSame(400, $response->status());
        self::assertSame([], $eric->children());
    }

    /**
     * **And a `POST` cannot be wired without somewhere for its notifications
     * to go.** §5.3.2's MUST is part of the change, not an extra: a server
     * that moved a sharee list and told nobody would leave §3's whole flow —
     * "If the request succeeds, a notification is sent to the sharee" —
     * broken, and the sharee would never learn they had been invited.
     *
     * Said out loud at wiring time rather than at request time, the way
     * {@see \DavServices\Dav\Event\CurrentPrincipalRequested} says two
     * listeners disagreeing about identity out loud: of all the things to
     * discover from a missing notification, this would be the worst.
     */
    public function testAPostNeedsSomewhereForItsNotificationsToGo(): void
    {
        $server = new Server(new Tree(new MemoryCollection('')));

        $this->expectException(InvalidArgumentException::class);

        (new Sharing($server))->register(new Post($server));
    }

    /**
     * The one notification deposited in a store, as it was written.
     */
    private static function theOneNotificationIn(MemoryCollection $store): string
    {
        $children = $store->children();

        self::assertCount(1, $children);

        $notification = $children[0] ?? self::fail('nothing was deposited');

        self::assertInstanceOf(MemoryFile::class, $notification);

        return $notification->get();
    }

    /**
     * What a share does, with Eric's notifications kept where they can be
     * read back.
     */
    private function shareWith(
        MemoryCollection $ericsNotifications,
        string $instructions,
        MemoryShareTarget $calendar,
    ): Response {
        return $this->shareOn(
            $this->server($calendar, ericsNotifications: NotificationCollection::of($ericsNotifications)),
            $instructions,
        );
    }

    private function shareOn(Server $server, string $instructions): Response
    {
        return $server->handle(self::requestOf(
            sprintf('<CS:share xmlns:D="DAV:" xmlns:CS="%s">%s</CS:share>', self::NS, $instructions),
        ));
    }

    /**
     * The one sharee on the list, said out loud rather than indexed: a case
     * that silently read nothing would pass on a store that was never told
     * anything at all.
     */
    private static function only(MemoryShareTarget $calendar): Sharee
    {
        $sharees = $calendar->sharees();

        self::assertCount(1, $sharees);

        return $sharees[0] ?? self::fail('the list is empty');
    }

    /**
     * What a `CS:share` carrying these instructions does.
     */
    private function share(string $instructions, INode $calendar, ?string $asking = 'principals/alice'): Response
    {
        return $this->post(
            sprintf('<CS:share xmlns:D="DAV:" xmlns:CS="%s">%s</CS:share>', self::NS, $instructions),
            $calendar,
            $asking,
        );
    }

    private function post(string $body, INode $calendar, ?string $asking): Response
    {
        return $this->server($calendar, $asking)->handle(self::requestOf($body));
    }

    private static function requestOf(string $body): Request
    {
        return new Request(
            'POST',
            '/calendars/work',
            headers: new Headers(['Content-Type' => 'application/xml', 'Host' => 'calendar.example.com']),
            body: new Body($body),
        );
    }

    /**
     * A calendar at `/calendars/work`, three principals, a notification
     * collection for one of them, and whoever is asking.
     *
     * **Wilfredo has no notification collection**, which is §4.1.1's calendar
     * user "not enabled for notification messages on the server", and the
     * clock is a fixed one so that §6.25's `CS:dtstamp` can be asserted.
     */
    private function server(
        INode $calendar,
        ?string $asking = 'principals/alice',
        ?string $sharerIsCalled = 'Alice',
        ?INode $ericsNotifications = null,
    ): Server {
        $events = new EventEmitter();

        $events->on(PropertiesRequested::class, (new LiveProperties())(...));

        if ($asking !== null) {
            $events->on(
                CurrentPrincipalRequested::class,
                static function (CurrentPrincipalRequested $event) use ($asking): void {
                    $event->answerWith($asking);
                },
            );
        }

        $server = new Server(
            new Tree(
                (new MemoryCollection(''))
                    ->add((new MemoryCollection('calendars'))->add(
                        $calendar instanceof MemoryShareTarget ? Calendar::of($calendar) : $calendar,
                    ))
                    ->add((new MemoryCollection('principals'))
                        ->add(new Principal(new PrincipalInfo('alice', $sharerIsCalled)))
                        ->add(new Principal(new PrincipalInfo('eric', 'Eric York')))
                        ->add(new Principal(new PrincipalInfo('wilfredo', 'Wilfredo'))))
                    ->add((new MemoryCollection('notifications'))->add(
                        $ericsNotifications ?? NotificationCollection::of(new MemoryCollection('eric')),
                    )),
            ),
            $events,
            new Writer(['DAV:' => 'd', self::NS => 'cs']),
        );

        $post = new Post($server);
        $notifications = new Notifications(
            $server,
            now: static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-29T19:30:00+00:00'),
        );

        (new Sharing($server, $notifications))->register($post);

        $server->onMethod('POST', $post(...));

        return $server;
    }
}
