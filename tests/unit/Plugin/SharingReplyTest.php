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
use DavServices\CalDav\InviteReply;
use DavServices\CalDav\InviteStatus;
use DavServices\CalDav\NotificationCollection;
use DavServices\CalDav\Sharee;
use DavServices\CalDav\SharingAccess;
use DavServices\Dav\Acl\PrincipalInfo;
use DavServices\Dav\Event\BeforeUnbind;
use DavServices\Dav\Event\CurrentPrincipalRequested;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\INode;
use DavServices\Dav\Method\Delete;
use DavServices\Dav\Method\Options;
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
use DavServices\Tests\Unit\CalDav\MemorySharedWithMe;
use DavServices\Tests\Unit\CalDav\MemoryShareeHome;
use DavServices\Tests\Unit\CalDav\MemoryShareTarget;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Xml\Element;
use DavServices\Xml\Writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for §5.4.1, the sharee's reply. R-CAL-20, P7-05c.
 *
 * > When a sharee is invited to a shared calendar they can accept or decline
 * > the invite by issuing a POST request to the sharee's calendar home
 * > collection resource. The POST request MUST contain an XML document as its
 * > body with the root element being CS:invite-reply.
 * >
 * > The CS:invite-reply element in the POST request specifies the sharee who
 * > is replying in the DAV:href element, the accept or decline action via the
 * > CS:invite-accepted or CS:invite-declined elements, the URL of the shared
 * > calendar in the CS:hosturl element, the unique identifier of the invite to
 * > which it is a reply in the CS:in-reply-to element, and an optional
 * > CS:summary element.
 * >
 * > The response to a POST request that accepts a shared calendar invite MUST
 * > be an XML document containing CS:shared-as (Section 6.29) as its root
 * > element. That root element contains a single DAV:href element whose
 * > content is the URI of the shared calendar in the sharee's calendar home
 * > created by the invite acceptance.
 * >
 * > When the sharee replies to an invite, the server SHOULD send a
 * > notification to the sharer to update them on the change in the sharee
 * > state.
 *
 * with §6.22's grammar:
 *
 *     <!ELEMENT invite-reply (DAV:href,
 *                             (invite-accepted | invite-declined),
 *                             hosturl, in-reply-to, summary?>
 *
 * ## Four things are checked before anything happens, and each of them has to
 *
 * A reply carries the address of a sharee, the URL of a calendar and the
 * identifier of an invitation — **all three from the client**. A server that
 * took them on trust would let anybody accept any calendar into any home.
 *
 * - the target is **the asker's own** calendar home, as the server's own
 *   `CALDAV:calendar-home-set` names it;
 * - the `DAV:href` names **the asker**;
 * - the `CS:hosturl` names a calendar on this server whose sharee list can be
 *   corrected;
 * - and that list holds an invitation **for the asker**, whose identifier the
 *   `CS:in-reply-to` matches.
 *
 * The last one is free: §6.16's identifier is derived from the calendar and
 * the sharee, so checking it costs nothing and proves the reply answers an
 * invitation this server really issued.
 */
#[CoversClass(Sharing::class)]
#[CoversClass(InviteReply::class)]
// **And the notification too**, because §6.23's check runs through
// `InviteNotification::identifierOf()`: a mutation round told what covers only
// the two above runs this file's cases against neither, and reported a change
// to that method as unnoticed when every case here notices it.
#[CoversClass(InviteNotification::class)]
final class SharingReplyTest extends TestCase
{
    private const NS = 'http://calendarserver.org/ns/';

    private const HOME_SET = '{urn:ietf:params:xml:ns:caldav}calendar-home-set';

    private const HOST = '<CS:hosturl><D:href>/calendars/alice/work/</D:href></CS:hosturl>';

    private ?MemoryShareeHome $elsewhere = null;

    /**
     * Somebody else's calendar home, which also takes shared calendars: a
     * reply aimed at it is refused by the home check and by nothing else.
     */
    private function elsewhere(): MemoryShareeHome
    {
        return $this->elsewhere ??= new MemoryShareeHome('wilfredo');
    }

    /**
     * **The whole of §5.4.1's answer**: `CS:shared-as` naming the copy the
     * acceptance created, and the copy really there.
     */
    public function testAcceptingMakesTheCopyAndSaysWhereItIs(): void
    {
        $home = new MemoryShareeHome('eric');

        $response = $this->reply($this->accepting(), home: $home);

        self::assertSame(200, $response->status());
        // The root carries the namespace declarations, which is the writer's
        // business and not this case's.
        self::assertStringContainsString('<cs:shared-as ', (string) $response->body());
        self::assertStringContainsString(
            '><d:href>/calendars/eric/shared-1/</d:href></cs:shared-as>',
            (string) $response->body(),
        );
        self::assertSame('application/xml; charset=utf-8', $response->headers()->first('Content-Type'));
        self::assertTrue($home->hasChild('shared-1'));
    }

    /**
     * **And the sharer's list says so**, which is what §5.4.2 calls updating
     * "the sharee's status in the sharer's calendar's CS:invite property" and
     * what §6.8 means by an invite "they previously accepted".
     */
    public function testAcceptingSetsTheShareeAsAcceptedOnTheSharersCalendar(): void
    {
        $work = $this->sharersCalendar();

        $this->reply($this->accepting(), work: $work);

        self::assertSame(InviteStatus::Accepted, self::only($work)->status());
        self::assertSame(SharingAccess::ReadWrite, self::only($work)->access(), 'the access is not the reply to change');
    }

    /**
     * **§6.22's notification goes to the sharer**, in the order that grammar
     * lays down — "the server SHOULD send a notification to the sharer to
     * update them on the change in the sharee state".
     *
     * Asserted as one string because the order is half of what is asserted.
     */
    public function testAcceptingTellsTheSharer(): void
    {
        $alice = new MemoryCollection('alice');

        $this->reply($this->accepting(), notifications: $alice);

        self::assertStringContainsString(
            '<cs:invite-reply><d:href>/principals/eric/</d:href><cs:invite-accepted/>'
            . '<cs:hosturl><d:href>/calendars/alice/work/</d:href></cs:hosturl>'
            . '<cs:in-reply-to>' . $this->identifier() . '</cs:in-reply-to>'
            . '</cs:invite-reply>',
            self::theOneNotificationIn($alice),
        );
    }

    /**
     * **A refusal creates nothing** and says nothing beyond `200`: §5.4.1
     * makes a body owed only to "a POST request that **accepts**", and there
     * is no URI to name where nothing was created.
     */
    public function testDecliningMakesNothingAndNamesNothing(): void
    {
        $home = new MemoryShareeHome('eric');
        $work = $this->sharersCalendar();

        $response = $this->reply($this->declining(), home: $home, work: $work);

        self::assertSame(200, $response->status());
        self::assertSame('', (string) $response->body());
        self::assertSame([], $home->children());
        self::assertSame(InviteStatus::Declined, self::only($work)->status());
    }

    /**
     * And the sharer is told of that as well, with §6.9's element: the point
     * of the message is "the change in the sharee state", whichever way it
     * changed.
     */
    public function testDecliningTellsTheSharerToo(): void
    {
        $alice = new MemoryCollection('alice');

        $this->reply($this->declining(), notifications: $alice);

        self::assertStringContainsString('<cs:invite-declined/>', self::theOneNotificationIn($alice));
    }

    /**
     * §6.22 makes `CS:summary` optional, and it is carried where the sharee
     * sent one — §6.14 having it "used by sharees to indicate back to the
     * sharer how each sharee is refering to the shared calendar".
     */
    public function testTheShareesOwnSummaryIsCarriedToTheSharer(): void
    {
        $alice = new MemoryCollection('alice');

        $this->reply(
            $this->accepting() . '<CS:summary>Alice and me</CS:summary>',
            notifications: $alice,
        );

        self::assertStringContainsString(
            '</cs:in-reply-to><cs:summary>Alice and me</cs:summary></cs:invite-reply>',
            self::theOneNotificationIn($alice),
        );
    }

    /**
     * **And it takes the place of the one the sharer wrote.** §6.14 gives the
     * element to both of them — "This can be used by sharers to communicate
     * the nature of a shared calendar to sharees, **as well as** used by
     * sharees to indicate back to the sharer how each sharee is refering to
     * the shared calendar" — and the entry is where the sharer reads what
     * their sharee calls it. A server keeping its own wording would never
     * show them the answer.
     */
    public function testTheShareesSummaryTakesThePlaceOfTheSharersOnTheList(): void
    {
        $work = $this->sharersCalendar();

        $this->reply($this->accepting() . '<CS:summary>Alice and me</CS:summary>', work: $work);

        self::assertSame('Alice and me', self::only($work)->summary());
    }

    /**
     * And the sharer's own wording stands where the sharee sent none.
     */
    public function testTheSharersSummaryStandsWhereTheShareeSentNone(): void
    {
        $work = $this->sharersCalendar();

        $this->reply($this->accepting(), work: $work);

        self::assertSame('Shared workspace', self::only($work)->summary());
    }

    /**
     * **An identifier written across several lines is still the identifier.**
     * §6.23 is `(#PCDATA)`, so a document that arrives pretty-printed carries
     * the indentation with it — and a server comparing the text as it stood
     * would refuse every client that formats its XML.
     */
    public function testAnIdentifierWrittenAcrossSeveralLinesStillMatches(): void
    {
        $response = $this->reply(
            '<D:href>/principals/eric/</D:href><CS:invite-accepted/>' . self::HOST
            . '<CS:in-reply-to>' . "\n            " . $this->identifier() . "\n        " . '</CS:in-reply-to>',
        );

        self::assertSame(200, $response->status());
    }

    /**
     * **A reply on somebody else's calendar home is refused.** §5.4.1 puts it
     * on "**the sharee's** calendar home collection resource", and the home is
     * where the copy would be made: without this, an invitation could be
     * accepted into a home its holder never asked about.
     */
    public function testAReplyOnSomebodyElsesHomeIsRefused(): void
    {
        $response = $this->reply($this->accepting(), at: '/calendars/wilfredo');

        self::assertSame(403, $response->status());
        // **Wilfredo's home takes shared calendars too**, so nothing but the
        // home check stands between this reply and a copy in it. A fixture
        // whose other home took none would have let a server that skipped
        // that check refuse for the wrong reason.
        self::assertSame([], $this->elsewhere()->children());
    }

    /**
     * **And one by nobody at all**, there being no home to call theirs and
     * nobody to be the sharee of an invitation.
     */
    public function testAReplyByNobodyIsRefused(): void
    {
        self::assertSame(403, $this->reply($this->accepting(), asking: null)->status());
    }

    /**
     * **And one by somebody whose principal is not in the tree**: no address
     * can resolve to a principal that is not there, so the `DAV:href` names
     * somebody other than whoever is asking whatever it says.
     *
     * That is also why the home question is asked **after** this one — it
     * then never has a missing principal to answer about, and needs no guard
     * of its own for a case it cannot meet.
     */
    public function testAReplyByAPrincipalThatIsNotThereIsRefused(): void
    {
        self::assertSame(403, $this->reply($this->accepting(), asking: 'principals/ghost')->status());
    }

    /**
     * **A server that publishes no calendar home answers nobody's reply**,
     * and that is the truth about it rather than an oversight: §5.4.1's
     * target is "the sharee's calendar home collection resource", and RFC
     * 4791 §6.2.1's property is how this server says where that is.
     */
    public function testAReplyIsRefusedWhereThisServerPublishesNoHome(): void
    {
        self::assertSame(403, $this->reply($this->accepting(), homeSet: false)->status());
    }

    /**
     * **And whatever else is in that property is read rather than assumed.**
     * Another plugin answers it, so a child that is no `DAV:href` is passed
     * over and the hrefs beside it still count — here the stray one comes
     * first.
     */
    public function testAStrayElementInTheHomeSetIsPassedOver(): void
    {
        $home = new MemoryShareeHome('eric');

        $response = $this->reply($this->accepting(), home: $home, homeSet: self::aHomeSetWithAStrayChild());

        self::assertSame(200, $response->status());
        self::assertTrue($home->hasChild('shared-1'));
    }

    /**
     * And a home named by an address that climbs out of the tree is no home:
     * `Uri\Path` refuses it.
     */
    public function testAHomeThatClimbsOutOfTheTreeIsNoHome(): void
    {
        $response = $this->reply($this->accepting(), homeSet: self::aHomeSetSaying('/../calendars/eric/'));

        self::assertSame(403, $response->status());
    }

    /**
     * **But the one named after it is still read.** RFC 4791 §6.2.1 lets the
     * property name more than one home — "There can be multiple" — so one
     * address this server cannot resolve is one entry passed over and not the
     * end of the list.
     */
    public function testAHomeNamedAfterAnUnusableOneIsStillRead(): void
    {
        $home = new MemoryShareeHome('eric');

        $response = $this->reply($this->accepting(), home: $home, homeSet: self::aHomeSetSaying(
            '/../calendars/eric/',
            '/calendars/eric/',
        ));

        self::assertSame(200, $response->status());
        self::assertTrue($home->hasChild('shared-1'));
    }

    /**
     * **And a home written across several lines is still the home.** The
     * property is another plugin's answer and may arrive pretty-printed, which
     * a server comparing the text as it stood would refuse.
     */
    public function testAHomeWrittenAcrossSeveralLinesIsStillTheHome(): void
    {
        $home = new MemoryShareeHome('eric');

        $response = $this->reply(
            $this->accepting(),
            home: $home,
            homeSet: self::aHomeSetSaying("\n            /calendars/eric/\n        "),
        );

        self::assertSame(200, $response->status());
        self::assertTrue($home->hasChild('shared-1'));
    }

    /**
     * **A reply that names somebody else as the sharee is refused**, which is
     * the guard that keeps the notification honest: without it a client could
     * have the sharer told that a third party had accepted.
     */
    public function testAReplyThatNamesSomebodyElseIsRefused(): void
    {
        $response = $this->reply(
            '<D:href>/principals/alice/</D:href><CS:invite-accepted/>' . self::HOST
            . '<CS:in-reply-to>' . $this->identifier() . '</CS:in-reply-to>',
        );

        self::assertSame(403, $response->status());
    }

    /**
     * **A reply about a calendar that is not there is refused**, and so is one
     * about a calendar whose sharee list is nobody's to correct: §5.4.2 makes
     * updating that list part of what a reply does, so a reply that could not
     * be recorded would be a reply that went nowhere.
     */
    #[DataProvider('calendarsNoReplyCanName')]
    public function testAReplyAboutACalendarThatCannotRecordItIsRefused(string $host): void
    {
        $response = $this->reply(
            '<D:href>/principals/eric/</D:href><CS:invite-accepted/>'
            . '<CS:hosturl><D:href>' . $host . '</D:href></CS:hosturl>'
            . '<CS:in-reply-to>' . $this->identifier() . '</CS:in-reply-to>',
        );

        self::assertSame(403, $response->status());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function calendarsNoReplyCanName(): iterable
    {
        yield 'nothing is there' => ['/calendars/alice/missing/'];
        yield 'it is no calendar' => ['/calendars/'];
        yield 'it is on another server' => ['http://elsewhere.example.com/calendars/alice/work/'];
        yield 'it climbs out of the tree' => ['/../calendars/alice/work/'];
        yield 'it is a calendar whose list is nobody to change' => ['/calendars/alice/private/'];
    }

    /**
     * **A reply to an invitation that was never sent is refused.** The asker
     * is on no sharee list of that calendar, so there is nothing to answer —
     * and this is what keeps a client from accepting a calendar it was never
     * offered.
     */
    public function testAReplyToAnInvitationNobodySentIsRefused(): void
    {
        $work = new MemoryShareTarget('work', sharees: [
            new Sharee('/principals/wilfredo/', SharingAccess::Read, InviteStatus::NoResponse),
        ]);

        $response = $this->reply($this->accepting(), work: $work);

        self::assertSame(403, $response->status());
        self::assertSame(InviteStatus::NoResponse, self::only($work)->status());
    }

    /**
     * **And one whose identifier does not match is refused.** §6.23 has
     * `CS:in-reply-to` carry "the unique identifier of the inviate message
     * that this notification message is a reply to", and §6.16's identifier is
     * derived from the calendar and the sharee — so the check costs nothing
     * and a reply that fails it is answering something this server never sent.
     */
    public function testAReplyWhoseIdentifierDoesNotMatchIsRefused(): void
    {
        $response = $this->reply(
            '<D:href>/principals/eric/</D:href><CS:invite-accepted/>' . self::HOST
            . '<CS:in-reply-to>not the one</CS:in-reply-to>',
        );

        self::assertSame(403, $response->status());
    }

    /**
     * **A home that takes no shared calendars cannot accept one**, and that is
     * a `403` rather than a crash: a calendar home is an ordinary collection
     * until an application says otherwise, and §5.4.1's copy is one only it
     * can make.
     */
    public function testAHomeThatTakesNoSharedCalendarsCannotAcceptOne(): void
    {
        $work = $this->sharersCalendar();

        $response = $this->reply($this->accepting(), home: new MemoryCollection('eric'), work: $work);

        self::assertSame(403, $response->status());
        // **And the invitation is left as it was**, which is why the copy is
        // made before the answer is recorded: a sharer whose list said
        // "accepted" over a sharee with no copy would be reading a state
        // nobody is in.
        self::assertSame(InviteStatus::NoResponse, self::only($work)->status());
    }

    /**
     * **But it can still decline**, because nothing is created by a refusal —
     * which is what makes the two worth telling apart.
     */
    public function testAHomeThatTakesNoSharedCalendarsCanStillDecline(): void
    {
        $work = $this->sharersCalendar();

        $response = $this->reply($this->declining(), home: new MemoryCollection('eric'), work: $work);

        self::assertSame(200, $response->status());
        self::assertSame(InviteStatus::Declined, self::only($work)->status());
    }

    /**
     * **Every required child of §6.22 is required**, and a document missing
     * one is refused before anything changes.
     */
    #[DataProvider('repliesThatSayTooLittle')]
    public function testAReplyThatSaysTooLittleIsRefused(string $body): void
    {
        $work = $this->sharersCalendar();

        self::assertSame(400, $this->reply($body, work: $work)->status());
        self::assertSame(InviteStatus::NoResponse, self::only($work)->status());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function repliesThatSayTooLittle(): iterable
    {
        $identifier = '<CS:in-reply-to>whatever</CS:in-reply-to>';

        yield 'no address' => ['<CS:invite-accepted/>' . self::HOST . $identifier];
        yield 'no calendar' => ['<D:href>/principals/eric/</D:href><CS:invite-accepted/>' . $identifier];
        yield 'no identifier' => ['<D:href>/principals/eric/</D:href><CS:invite-accepted/>' . self::HOST];
        yield 'neither yes nor no' => ['<D:href>/principals/eric/</D:href>' . self::HOST . $identifier];
        yield 'both yes and no' => [
            '<D:href>/principals/eric/</D:href><CS:invite-accepted/><CS:invite-declined/>'
            . self::HOST . $identifier,
        ];
    }

    /**
     * **§5.4.2: taking the copy away is a `DELETE`**, and it leaves the
     * sharer's list saying so.
     *
     * > To remove a shared calendar from a sharee's calendar home collection
     * > a DELETE request is targeted at the shared calendar URI. When such a
     * > request is received the server MUST remove the shared calendar from
     * > the sharee's calendar home and **automatically update the sharee's
     * > status** in the sharer's calendar's CS:invite property.
     *
     * Declined, because §6.9's is the only state that fits somebody who no
     * longer has it — and because §5.4.2 is the other way a sharee refuses.
     */
    public function testRemovingTheCopyLeavesTheShareeDeclined(): void
    {
        $work = $this->sharersCalendar();
        $home = $this->homeHoldingACopy();

        $response = $this->remove($home, $work);

        self::assertSame(204, $response->status());
        self::assertFalse($home->hasChild('shared-1'), 'and the copy is gone');
        self::assertSame(InviteStatus::Declined, self::only($work)->status());
    }

    /**
     * **And the sharer is told, in the same words a decline is told in.**
     * §5.4.2 does not ask for it and §5.4.1 does, of "the change in the
     * sharee state" — and this is that change, reached the other way. A
     * sharer left to notice it on their next `PROPFIND` would be the only one
     * not told about a list of their own.
     */
    public function testRemovingTheCopyTellsTheSharer(): void
    {
        $alice = new MemoryCollection('alice');

        $this->remove($this->homeHoldingACopy(), $this->sharersCalendar(), $alice);

        self::assertStringContainsString('<cs:invite-declined/>', self::theOneNotificationIn($alice));
    }

    /**
     * **Deleting a calendar that is nobody's copy corrects no list**, which is
     * the half that keeps this listener off every other `DELETE` this server
     * answers.
     */
    public function testDeletingACalendarThatIsNobodysCopyCorrectsNoList(): void
    {
        $work = $this->sharersCalendar();
        $home = new MemoryShareeHome('eric');

        $home->add(new MemoryCollection('mine'));

        $response = $this->remove($home, $work, at: '/calendars/eric/mine');

        self::assertSame(204, $response->status());
        self::assertSame(InviteStatus::NoResponse, self::only($work)->status());
    }

    /**
     * **And deleting something that is no calendar at all corrects nothing**,
     * which is the half that keeps this listener off every `DELETE` this
     * server answers — a file, a plain collection, anything.
     */
    public function testDeletingSomethingThatIsNoCalendarCorrectsNothing(): void
    {
        $work = $this->sharersCalendar();

        $response = $this->remove($this->homeHoldingACopy(), $work, at: '/calendars/alice/notes.txt');

        self::assertSame(204, $response->status());
        self::assertSame(InviteStatus::NoResponse, self::only($work)->status());
    }

    /**
     * **And neither does one whose original is not there any more.** The copy
     * still goes — §5.4.2's first half is the sharee's to have — and there is
     * no list left to correct.
     */
    public function testDeletingACopyWhoseOriginalIsGoneStillRemovesIt(): void
    {
        $home = $this->homeHoldingACopy('/calendars/alice/missing/');

        $response = $this->remove($home, $this->sharersCalendar());

        self::assertSame(204, $response->status());
        self::assertFalse($home->hasChild('shared-1'));
    }

    /**
     * **A removal by somebody who holds no invitation corrects nothing**, and
     * that is a limit worth seeing: nothing on the copy says whose it is, so
     * the sharee is taken to be whoever is asking. §5.4.2 describes the sharee
     * removing their own copy; an administrator clearing somebody else's leaves
     * the sharer's list to be corrected by hand.
     */
    public function testARemovalBySomebodyWithNoInvitationCorrectsNothing(): void
    {
        $work = $this->sharersCalendar();

        $response = $this->remove($this->homeHoldingACopy(), $work, asking: 'principals/alice');

        self::assertSame(204, $response->status());
        self::assertSame(InviteStatus::NoResponse, self::only($work)->status());
    }

    /**
     * **And one by nobody corrects nothing either.** A `DELETE` that got this
     * far without anybody having said who they are is one no sharee made, and
     * §5.4.2's correction is about a sharee's standing.
     */
    public function testARemovalByNobodyCorrectsNothing(): void
    {
        $work = $this->sharersCalendar();

        $response = $this->remove($this->homeHoldingACopy(), $work, asking: null);

        self::assertSame(204, $response->status());
        self::assertSame(InviteStatus::NoResponse, self::only($work)->status());
    }

    /**
     * **A sharer this server cannot name is not written to**, and the reply
     * still works: §5.4.1's SHOULD reaches no further than the notification
     * mechanism §4 provides, and the sharee's standing is recorded either way.
     */
    #[DataProvider('sharersNobodyCanWriteTo')]
    public function testASharerThisServerCannotNameIsNotWrittenTo(?string $owner): void
    {
        $alice = new MemoryCollection('alice');
        $work = new MemoryShareTarget('work', owner: $owner, sharees: [
            new Sharee('/principals/eric/', SharingAccess::ReadWrite, InviteStatus::NoResponse),
        ]);

        $response = $this->reply($this->accepting(), work: $work, notifications: $alice);

        self::assertSame(200, $response->status());
        self::assertSame([], $alice->children());
        self::assertSame(InviteStatus::Accepted, self::only($work)->status());
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function sharersNobodyCanWriteTo(): iterable
    {
        yield 'the calendar names no owner' => [null];
        yield 'it names somebody this server does not host' => ['mailto:alice@example.com'];
    }

    /**
     * **And a removal with no request behind it corrects nothing.** An
     * application taking a node away itself is nobody refusing anything, and
     * §5.4.2 is about a sharee's `DELETE` — so there is nobody whose standing
     * could have changed.
     */
    public function testARemovalWithNoRequestBehindItCorrectsNothing(): void
    {
        $work = $this->sharersCalendar();
        $server = $this->server(
            $this->homeHoldingACopy(),
            $work,
            new MemoryCollection('alice'),
            'principals/eric',
            true,
        );

        $server->events()->emit(new BeforeUnbind('calendars/eric/shared-1'));

        self::assertSame(InviteStatus::NoResponse, self::only($work)->status());
    }

    /**
     * **§5.1: and now the server says it does all this.**
     *
     * > A server that supports the features described in this document MUST
     * > include "calendarserver-sharing" as a field in the DAV response header
     * > from an OPTIONS request on any resource that supports these features.
     *
     * The token was withheld through P7-05a and P7-05b, twice, under this same
     * sentence: with §5.3.2's `POST` missing a client could not share, and
     * with §5.4 missing a sharee could not answer. Both are here, so the
     * promise can be made.
     */
    public function testItNowAnnouncesSharingSupport(): void
    {
        $server = $this->server(
            new MemoryShareeHome('eric'),
            $this->sharersCalendar(),
            new MemoryCollection('alice'),
            'principals/eric',
            true,
        );

        $server->onMethod('OPTIONS', (new Options($server))(...));

        $answer = $server->handle(new Request('OPTIONS', '/calendars/eric'));

        self::assertStringContainsString(
            'calendarserver-sharing',
            $answer->headers()->first('DAV') ?? '',
        );
    }

    /**
     * Eric's home with a copy of Alice's calendar already in it.
     */
    private function homeHoldingACopy(string $of = '/calendars/alice/work/'): MemoryShareeHome
    {
        $home = new MemoryShareeHome('eric');

        $home->add(new MemorySharedWithMe('shared-1', $of));

        return $home;
    }

    private function remove(
        MemoryShareeHome $home,
        MemoryShareTarget $work,
        ?MemoryCollection $notifications = null,
        ?string $asking = 'principals/eric',
        string $at = '/calendars/eric/shared-1',
    ): Response {
        return $this->server(
            $home,
            $work,
            $notifications ?? new MemoryCollection('alice'),
            $asking,
            true,
        )->handle(new Request('DELETE', $at, headers: new Headers(['Host' => 'calendar.example.com'])));
    }

    /**
     * The body of a reply that accepts, with everything §6.22 requires.
     */
    private function accepting(): string
    {
        return '<D:href>/principals/eric/</D:href><CS:invite-accepted/>' . self::HOST
            . '<CS:in-reply-to>' . $this->identifier() . '</CS:in-reply-to>';
    }

    private function declining(): string
    {
        return '<D:href>/principals/eric/</D:href><CS:invite-declined/>' . self::HOST
            . '<CS:in-reply-to>' . $this->identifier() . '</CS:in-reply-to>';
    }

    /**
     * §6.16's identifier of the invitation these cases answer, taken from the
     * thing that makes it rather than written out.
     */
    private function identifier(): string
    {
        $notice = InviteNotification::aboutAChange(
            new Sharee('/principals/eric/', SharingAccess::ReadWrite, InviteStatus::NoResponse),
            '/calendars/alice/work/',
            '/principals/alice/',
            null,
        );

        self::assertNotNull($notice);

        return $notice->uid();
    }

    /**
     * Alice's calendar, shared to Eric and waiting for his answer.
     */
    private function sharersCalendar(): MemoryShareTarget
    {
        return new MemoryShareTarget('work', sharees: [
            new Sharee('/principals/eric/', SharingAccess::ReadWrite, InviteStatus::NoResponse, 'Eric York', 'Shared workspace'),
        ]);
    }

    /**
     * The one sharee on a calendar's list.
     */
    private static function only(MemoryShareTarget $calendar): Sharee
    {
        $sharees = $calendar->sharees();

        self::assertCount(1, $sharees);

        return $sharees[0] ?? self::fail('the list is empty');
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
     * RFC 4791 §6.2.1's property, naming one home.
     */
    private static function aHomeSetSaying(string ...$hrefs): Element
    {
        $set = new Element(self::HOME_SET);

        foreach ($hrefs as $href) {
            $names = new Element('{DAV:}href');

            $names->appendText($href);
            $set->append($names);
        }

        return $set;
    }

    /**
     * And one with something in it that is no address at all, which only
     * whatever answers the property can put there.
     */
    private static function aHomeSetWithAStrayChild(): Element
    {
        $set = new Element(self::HOME_SET);
        $stray = new Element('{https://dav.services/test}something-else');
        $names = new Element('{DAV:}href');

        $names->appendText('/calendars/eric/');
        $set->append($stray);
        $set->append($names);

        return $set;
    }

    private function reply(
        string $body,
        ?string $asking = 'principals/eric',
        ?INode $home = null,
        ?MemoryShareTarget $work = null,
        ?MemoryCollection $notifications = null,
        string $at = '/calendars/eric',
        Element|bool $homeSet = true,
    ): Response {
        return $this->server(
            $home ?? new MemoryShareeHome('eric'),
            $work ?? $this->sharersCalendar(),
            $notifications ?? new MemoryCollection('alice'),
            $asking,
            $homeSet,
        )->handle(new Request(
            'POST',
            $at,
            headers: new Headers(['Content-Type' => 'application/xml', 'Host' => 'calendar.example.com']),
            body: new Body(sprintf(
                '<CS:invite-reply xmlns:D="DAV:" xmlns:CS="%s">%s</CS:invite-reply>',
                self::NS,
                $body,
            )),
        ));
    }

    /**
     * Alice's calendars, Eric's home, the two principals, and a notification
     * collection for Alice.
     *
     * **`CALDAV:calendar-home-set` is answered by a listener here** rather
     * than by {@see \DavServices\Plugin\CalDav}, because what the sharing
     * plugin reads is the property and not that plugin: whatever this server
     * publishes as a principal's calendar home is the home a reply may be
     * posted to.
     */
    private function server(
        INode $home,
        MemoryShareTarget $work,
        MemoryCollection $notifications,
        ?string $asking,
        Element|bool $homeSet,
    ): Server {
        $events = new EventEmitter();

        $events->on(PropertiesRequested::class, (new LiveProperties())(...));
        $events->on(PropertiesRequested::class, static function (PropertiesRequested $event) use ($homeSet): void {
            $result = $event->result();

            if ($homeSet === false || !$event->node() instanceof Principal || !$result->wants(self::HOME_SET)) {
                return;
            }

            $result->set(self::HOME_SET, $homeSet instanceof Element
                ? $homeSet
                : self::aHomeSetSaying('/calendars/' . $event->node()->name() . '/'));
        });

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
                    ->add((new MemoryCollection('calendars'))
                        ->add((new MemoryCollection('alice'))
                            ->add(Calendar::of($work))
                            ->add(Calendar::of(new MemoryCollection('private')))
                            ->add(new MemoryFile('notes.txt', 'nothing to do with sharing')))
                        ->add($home)
                        ->add($this->elsewhere()))
                    ->add((new MemoryCollection('principals'))
                        ->add(new Principal(new PrincipalInfo('alice', 'Alice')))
                        ->add(new Principal(new PrincipalInfo('eric', 'Eric York'))))
                    ->add((new MemoryCollection('notifications'))
                        ->add(NotificationCollection::of($notifications))),
            ),
            $events,
            new Writer(['DAV:' => 'd', self::NS => 'cs']),
        );

        $post = new Post($server);

        (new Sharing($server, new Notifications(
            $server,
            now: static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-30T09:00:00+00:00'),
        )))->register($post);

        $server->onMethod('POST', $post(...));
        $server->onMethod('DELETE', (new Delete($server))(...));

        return $server;
    }
}
