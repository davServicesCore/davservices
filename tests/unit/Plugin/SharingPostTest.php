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

use DavServices\Acl\Principal;
use DavServices\CalDav\Calendar;
use DavServices\CalDav\InviteStatus;
use DavServices\CalDav\IShareable;
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
use DavServices\Plugin\Sharing;
use DavServices\Tests\Unit\CalDav\MemoryShareable;
use DavServices\Tests\Unit\CalDav\MemoryShareTarget;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Xml\Writer;
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
final class SharingPostTest extends TestCase
{
    private const NS = 'http://calendarserver.org/ns/';

    private const ERIC = '<D:href>mailto:eric@example.com</D:href>';

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
        return $this->server($calendar, $asking)->handle(new Request(
            'POST',
            '/calendars/work',
            headers: new Headers(['Content-Type' => 'application/xml', 'Host' => 'calendar.example.com']),
            body: new Body($body),
        ));
    }

    /**
     * A calendar at `/calendars/work`, two principals, and whoever is asking.
     */
    private function server(INode $calendar, ?string $asking): Server
    {
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
                        ->add(new Principal(new PrincipalInfo('alice', 'Alice')))
                        ->add(new Principal(new PrincipalInfo('eric', 'Eric York')))),
            ),
            $events,
            new Writer(['DAV:' => 'd', self::NS => 'cs']),
        );

        $post = new Post($server);

        (new Sharing($server))->register($post);

        $server->onMethod('POST', $post(...));

        return $server;
    }
}
