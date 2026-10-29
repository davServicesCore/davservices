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
use DavServices\CalDav\InviteStatus;
use DavServices\CalDav\IShareable;
use DavServices\CalDav\Sharee;
use DavServices\CalDav\SharingAccess;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\INode;
use DavServices\Dav\Method\Options;
use DavServices\Dav\Method\PropFind;
use DavServices\Dav\Method\PropPatch;
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
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryPropertyCollection;
use DavServices\Xml\Writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for the sharing plugin. R-CAL-20, P7-05.
 *
 * **No RFC defines any of this.** The source is `caldav-sharing`, "Shared and
 * Published Calendars in CalDAV", C. Daboo and E. York, Apple, 19 September
 * 2012, which `docs/referenzen/download-extensions.sh` fetches. It is the same
 * document P7-06 built §4 of, and this is the first half of its §5.
 *
 * ## `CS:invite`, §5.2.2
 *
 * > Purpose: Used to show to whom a calendar has been shared.
 * >
 * > Protected: This property MUST be protected.
 * >
 * > PROPFIND behavior: This property SHOULD NOT be returned by a PROPFIND
 * > allprop request.
 * >
 * > Description: This WebDAV property is present on a calendar collection
 * > resource that has been shared by the owner […] It provides a list of users
 * > to whom the calendar has been shared, along with the "status" of the
 * > sharing invites sent to each user.
 * >
 * > <!ELEMENT invite (organizer?, user*)>
 *
 * **`CS:organizer` is not answered here** and the same sentence says why: the
 * element belongs "on calendar collection resources **of the sharees**", which
 * are the copies §5.4.1 creates when an invitation is accepted. Nothing
 * creates one yet.
 *
 * ## `CS:allowed-sharing-modes`, §5.2.3
 *
 * > Protected: This property MUST be protected.
 * >
 * > PROPFIND behavior: This property SHOULD NOT be returned by a PROPFIND
 * > allprop request.
 * >
 * > Description: This WebDAV property is present on a calendar collection
 * > resource that can been shared or published.
 * >
 * > <!ELEMENT allowed-sharing-modes (can-be-shared?, can-be-published?)>
 *
 * And §5.5.2 gives its absence a meaning: "When not present on a calendar
 * collection, sharing or publishing of that calendar is not allowed."
 *
 * ## Why the prefix is named in the fixture
 *
 * The server is given a {@see Writer} that writes this namespace as `cs`, so
 * that a case can say which element it expects and in which order rather than
 * matching a local name that another element also contains — `read` inside
 * `read-write` being the trap. What prefix an unnamed namespace is given is
 * {@see \DavServices\Tests\Unit\Xml\WriterTest}'s question, not this one's.
 */
#[CoversClass(Sharing::class)]
#[CoversClass(Sharee::class)]
final class SharingTest extends TestCase
{
    private const NS = 'http://calendarserver.org/ns/';

    private const PROTECTED_PROPERTY = '<d:status>HTTP/1.1 403 Forbidden</d:status>';

    private const CANNOT_MODIFY = 'cannot-modify-protected-property';

    /**
     * **§5.2.3: a calendar that may be shared says so**, which is what lets a
     * client offer sharing on this calendar and not on the next one — §5.5.2:
     * "clients need a way to determine which calendar could be shared so they
     * can enable or disable sharing options on a per-calendar basis".
     */
    public function testACalendarThatMayBeSharedSaysSo(): void
    {
        $answer = $this->asking('<T:allowed-sharing-modes/>', $this->calendar(modes: [IShareable::CAN_BE_SHARED]));

        self::assertStringContainsString(
            '<cs:allowed-sharing-modes><cs:can-be-shared/></cs:allowed-sharing-modes>',
            $answer,
        );
    }

    /**
     * **And one that may be both says both, in the grammar's order.**
     * `<!ELEMENT allowed-sharing-modes (can-be-shared?, can-be-published?)>`
     * is a sequence rather than a choice, so a store that names them the other
     * way round must not be able to make this server write a document that
     * does not match the definition it cites.
     */
    public function testTheModesComeInTheGrammarsOrder(): void
    {
        $answer = $this->asking('<T:allowed-sharing-modes/>', $this->calendar(
            modes: [IShareable::CAN_BE_PUBLISHED, IShareable::CAN_BE_SHARED],
        ));

        self::assertStringContainsString(
            '<cs:allowed-sharing-modes><cs:can-be-shared/><cs:can-be-published/></cs:allowed-sharing-modes>',
            $answer,
        );
    }

    /**
     * **A mode the grammar does not admit is not written** — and the two it
     * does are still there, which is the half that keeps this from passing for
     * a plugin that writes nothing at all.
     *
     * §5.2.3's definition names two elements and no `x-` escape hatch, so
     * anything else would be a document a strict client is right to refuse.
     */
    public function testAModeTheGrammarDoesNotAdmitIsNotWritten(): void
    {
        $answer = $this->asking('<T:allowed-sharing-modes/>', $this->calendar(modes: [
            IShareable::CAN_BE_SHARED,
            '{https://dav.services/test}can-be-whistled-at',
            IShareable::CAN_BE_PUBLISHED,
        ]));

        self::assertStringContainsString(
            '<cs:allowed-sharing-modes><cs:can-be-shared/><cs:can-be-published/></cs:allowed-sharing-modes>',
            $answer,
        );
        self::assertStringNotContainsString('whistled', $answer);
    }

    /**
     * **A calendar that allows neither mode carries no property**, §5.2.3
     * saying when it is there at all: "This WebDAV property is present on a
     * calendar collection resource that can been shared or published." One
     * that can be neither is not such a resource, and §5.5.2 makes the two
     * ways of saying so mean one thing: "When not present on a calendar
     * collection, sharing or publishing of that calendar is not allowed."
     *
     * **And the sharee list is still answered**, which is §5.5.2's own reason
     * for keeping the two apart: a server "could treat some calendars as
     * always private (cannot be shared) or always public (always shared)". A
     * calendar that is shared and is not the client's to re-share is the
     * second of those, and the list is owed either way.
     */
    public function testACalendarThatAllowsNeitherModeCarriesNoProperty(): void
    {
        $answer = $this->asking('<T:allowed-sharing-modes/><T:invite/>', $this->calendar([$this->eric()]));

        self::assertStringContainsString('404 Not Found', $answer);
        self::assertStringNotContainsString('<cs:can-be-shared', $answer);
        self::assertStringNotContainsString('<cs:can-be-published', $answer);
        self::assertStringContainsString('<cs:invite>', $answer);
    }

    /**
     * **And a calendar that cannot be shared carries no property at all**,
     * §5.5.2 having made that the way to say so: "When not present on a
     * calendar collection, sharing or publishing of that calendar is not
     * allowed."
     *
     * So a store that knows nothing of sharing gets the right answer by
     * knowing nothing of it, and a client offers sharing nowhere rather than
     * everywhere.
     */
    public function testACalendarThatCannotBeSharedCarriesNoModes(): void
    {
        $answer = $this->asking('<T:allowed-sharing-modes/>', Calendar::of(new MemoryCollection('work')));

        self::assertStringContainsString('404 Not Found', $answer);
        self::assertStringNotContainsString('<cs:can-be-shared', $answer);
    }

    /**
     * And neither property is answered for something that is no calendar, §5.2
     * being headed "Additional Properties for **Calendars**" and §5.2.2 having
     * its property "present on a calendar **collection** resource".
     *
     * A store that knows about sharing but was never said to be a calendar is
     * therefore asked nothing, which is also what keeps this plugin from
     * answering on a principal or a file.
     */
    public function testSomethingThatIsNoCalendarCarriesNeitherProperty(): void
    {
        $answer = $this->asking(
            '<T:invite/><T:allowed-sharing-modes/>',
            new MemoryShareable('work', [$this->eric()], [IShareable::CAN_BE_SHARED]),
        );

        self::assertStringContainsString('404 Not Found', $answer);
        self::assertStringNotContainsString('<cs:invite>', $answer);
        self::assertStringNotContainsString('<cs:can-be-shared', $answer);
        // Nothing at all was answered, which a bare count of `404` would not
        // have shown: a document carrying one of the two would carry both a
        // `200` block and a `404` one.
        self::assertStringNotContainsString('200 OK', $answer);
    }

    /**
     * **§5.2.2: who the calendar is shared with, and how it stands with them.**
     * One entry per sharee, in the order §6.5 lays down:
     *
     *     <!ELEMENT user (DAV:href, common-name?, (invite-noresponse |
     *                     invite-accepted | invite-declined | invite-invalid),
     *                     access, summary?)>
     *
     * Asserted as one string rather than five, because the order is the thing
     * being asserted: five assertions would pass on a document that carried
     * the right elements in the wrong sequence.
     */
    public function testASharedCalendarNamesItsShareeAndHowItStands(): void
    {
        $answer = $this->asking('<T:invite/>', $this->calendar([$this->eric()]));

        self::assertStringContainsString(
            '<cs:invite><cs:user>'
            . '<d:href>mailto:eric@example.com</d:href>'
            . '<cs:common-name>Eric York</cs:common-name>'
            . '<cs:invite-noresponse/>'
            . '<cs:access><cs:read-write/></cs:access>'
            . '<cs:summary>Shared workspace</cs:summary>'
            . '</cs:user></cs:invite>',
            $answer,
        );
    }

    /**
     * **Every sharee gets an entry**, `user*` being a list. Counted rather
     * than looked for by name: an assertion that finds both names would also
     * pass on a document that carried the second one inside the first.
     */
    public function testEveryShareeGetsAnEntryOfItsOwn(): void
    {
        $answer = $this->asking('<T:invite/>', $this->calendar([
            $this->eric(),
            new Sharee('mailto:wilfredo@example.com', SharingAccess::Read, InviteStatus::Accepted),
        ]));

        self::assertSame(2, substr_count($answer, '<cs:user>'));
        self::assertStringContainsString('mailto:wilfredo@example.com', $answer);
    }

    /**
     * **What nobody said is not made up.** §6.5 makes `common-name` and
     * `summary` optional, and §5.3.2 leaves the first to something that knows
     * its users: the server "SHOULD add a CS:common-name when it is able to
     * match the sharee with a known user, and a common name for that user can
     * be determined" — which a library over an unknown store cannot do.
     *
     * The three that are not optional are still there, so this cannot pass on
     * a plugin that writes an empty entry.
     */
    public function testWhatNobodySaidIsNotMadeUp(): void
    {
        $answer = $this->asking('<T:invite/>', $this->calendar([
            new Sharee('/principals/eric/', SharingAccess::Read, InviteStatus::Declined),
        ]));

        self::assertStringContainsString(
            '<cs:user><d:href>/principals/eric/</d:href><cs:invite-declined/>'
            . '<cs:access><cs:read/></cs:access></cs:user>',
            $answer,
        );
        self::assertStringNotContainsString('common-name', $answer);
        self::assertStringNotContainsString('summary', $answer);
    }

    /**
     * **An address is handed on as it stands**, in whichever of §5.3.2's three
     * forms it arrived: "either a principal-URL for a sharee hosted on the same
     * server, a calendar user address or email address". A server that made a
     * path of `mailto:eric@example.com` would name a resource that is not
     * there, and one that prefixed a principal-URL with its base twice would
     * name the wrong one.
     */
    #[DataProvider('addresses')]
    public function testAnAddressIsHandedOnAsItStands(string $address): void
    {
        $answer = $this->asking('<T:invite/>', $this->calendar([
            new Sharee($address, SharingAccess::Read, InviteStatus::NoResponse),
        ]));

        self::assertStringContainsString('<d:href>' . $address . '</d:href>', $answer);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function addresses(): iterable
    {
        yield 'a principal-URL' => ['/principals/eric/'];
        yield 'a calendar user address' => ['urn:uuid:4c4c4c4c-eric'];
        yield 'an email address' => ['mailto:eric@example.com'];
    }

    /**
     * **Each of §6.5's four states has its own element**, and a sharee is in
     * exactly one of them. §6.10's is the one that is no answer at all —
     * "the corresponding sharee is not a valid calendar user known to the
     * server" — and it is told apart from the rest here so that a store can
     * report an address nobody is behind.
     */
    #[DataProvider('states')]
    public function testEachStateHasItsOwnElement(InviteStatus $status, string $element): void
    {
        $answer = $this->asking('<T:invite/>', $this->calendar([
            new Sharee('/principals/eric/', SharingAccess::Read, $status),
        ]));

        self::assertStringContainsString($element, $answer);
    }

    /**
     * @return iterable<string, array{InviteStatus, string}>
     */
    public static function states(): iterable
    {
        yield 'never replied' => [InviteStatus::NoResponse, '<cs:invite-noresponse/>'];
        yield 'said yes' => [InviteStatus::Accepted, '<cs:invite-accepted/>'];
        yield 'said no' => [InviteStatus::Declined, '<cs:invite-declined/>'];
        yield 'nobody the server knows' => [InviteStatus::Invalid, '<cs:invite-invalid/>'];
    }

    /**
     * **And each of §5.5.1's two access levels has its own**: "Two levels of
     * access ca be granted by a sharer to any sharee", and §6.11's grammar
     * `<!ELEMENT access (read | read-write)>` admits one of them and no more.
     */
    #[DataProvider('levels')]
    public function testEachAccessLevelHasItsOwnElement(SharingAccess $access, string $element): void
    {
        $answer = $this->asking('<T:invite/>', $this->calendar([
            new Sharee('/principals/eric/', $access, InviteStatus::Accepted),
        ]));

        self::assertStringContainsString('<cs:access>' . $element . '</cs:access>', $answer);
    }

    /**
     * @return iterable<string, array{SharingAccess, string}>
     */
    public static function levels(): iterable
    {
        yield 'read' => [SharingAccess::Read, '<cs:read/>'];
        yield 'read and write' => [SharingAccess::ReadWrite, '<cs:read-write/>'];
    }

    /**
     * **A calendar shared with nobody carries no invitation list**, §5.2.2
     * having the property "present on a calendar collection resource that
     * **has been shared** by the owner". A calendar that may be shared and is
     * not is not one that has been.
     *
     * **And §5.2.3's property is still there**, which is the half that matters:
     * the two say different things, and a plugin that answered neither for an
     * unshared calendar would tell a client it cannot be shared at all.
     */
    public function testACalendarSharedWithNobodyCarriesNoInvitationList(): void
    {
        $answer = $this->asking(
            '<T:invite/><T:allowed-sharing-modes/>',
            $this->calendar(modes: [IShareable::CAN_BE_SHARED]),
        );

        self::assertStringContainsString('404 Not Found', $answer);
        self::assertStringNotContainsString('<cs:invite>', $answer);
        self::assertStringContainsString('<cs:can-be-shared/>', $answer);
    }

    /**
     * **Neither is returned for `allprop`**, both sections saying so in the
     * same words: "This property SHOULD NOT be returned by a PROPFIND allprop
     * request (as defined in Section 14.2 of [RFC4918])."
     *
     * A sharee list is as long as the number of people a calendar is shared
     * with, and repeating it on every member of a `Depth: 1` listing is what
     * that sentence exists to prevent.
     */
    public function testNeitherIsReturnedForAllprop(): void
    {
        $answer = $this->askingWhole(
            '<D:allprop/>',
            $this->calendar([$this->eric()], [IShareable::CAN_BE_SHARED]),
        );

        self::assertStringNotContainsString('<cs:invite>', $answer);
        self::assertStringNotContainsString('allowed-sharing-modes', $answer);
        // And the request was an allprop request at all, which is what a
        // prefix left off would quietly cost: an element in no namespace is
        // not `DAV:allprop`, and the case would pass for nothing.
        self::assertStringContainsString('<d:resourcetype>', $answer);
    }

    /**
     * **But a client that includes them by name gets them**, RFC 4918 §9.1
     * giving it `DAV:include` "to define the properties to be returned in
     * addition to those defined by DAV:allprop" — which is what keeps the
     * sentence above from costing a client a second round trip.
     */
    public function testBothAreReturnedForAnAllpropThatIncludesThem(): void
    {
        $answer = $this->askingWhole(
            '<D:allprop/><D:include><T:invite/><T:allowed-sharing-modes/></D:include>',
            $this->calendar([$this->eric()], [IShareable::CAN_BE_SHARED]),
        );

        self::assertStringContainsString('<cs:invite>', $answer);
        self::assertStringContainsString('<cs:can-be-shared/>', $answer);
    }

    /**
     * **Both are protected**, §5.2.2 and §5.2.3 each saying "This property
     * MUST be protected" — and the refusal has to say *that* as well as which
     * condition it failed, because a client told only that it may not write
     * one property is left to guess whether it was refused at all.
     */
    #[DataProvider('bothProperties')]
    public function testBothPropertiesAreProtected(string $property): void
    {
        $answer = (string) $this->propPatch($property, $this->calendar([$this->eric()]))->body();

        self::assertStringContainsString(self::PROTECTED_PROPERTY, $answer);
        self::assertStringContainsString(self::CANNOT_MODIFY, $answer);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bothProperties(): iterable
    {
        yield 'the sharee list' => ['<T:invite/>'];
        yield 'the allowed modes' => ['<T:allowed-sharing-modes/>'];
    }

    /**
     * **And nothing else is protected here.** Without this the plugin could
     * refuse every `PROPPATCH` and still pass both cases above — the lesson
     * P7-04's mutation round taught, where a guard over two names negated into
     * one that was true for every name.
     *
     * `DAV:displayname` is the property to try it with: §5.5.3 makes it one a
     * server "MUST treat as per-user" on a shared calendar, so of all the
     * properties this is the one a sharee is certain to write.
     */
    public function testNoOtherPropertyIsProtectedHere(): void
    {
        $answer = (string) $this->propPatch(
            '<D:displayname>Our week</D:displayname>',
            Calendar::of(new MemoryPropertyCollection('work')),
        )->body();

        self::assertStringNotContainsString(self::CANNOT_MODIFY, $answer);
        self::assertStringContainsString('200 OK', $answer);
    }

    /**
     * **It announces no sharing support yet, and that is deliberate.** §5.1:
     *
     * > A server that supports the features described in this document MUST
     * > include "calendarserver-sharing" as a field in the DAV response header
     * > from an OPTIONS request on any resource that supports these features.
     *
     * The features described in that document include §5.3.2's `POST`, which
     * is the only way a sharee list is ever changed. A server announcing the
     * token over these two properties alone would have a client offer sharing
     * and then be told the method is not allowed — worse than silence, because
     * §5.1 is how a client decides whether to offer it at all.
     *
     * The same standard {@see \DavServices\Plugin\CalDav} holds itself to with
     * `calendar-access`, whose §5.1 makes the token "a promise that the server
     * supports all MUST level requirements specified in this document" — and
     * which that plugin does not announce either, for one still open.
     */
    public function testItAnnouncesNoSharingSupportYet(): void
    {
        $server = $this->server($this->calendar([$this->eric()], [IShareable::CAN_BE_SHARED]));

        $server->onMethod('OPTIONS', (new Options($server))(...));

        $answer = $server->handle(new Request('OPTIONS', '/calendars/work'));

        self::assertStringNotContainsString('calendarserver-sharing', $answer->headers()->first('DAV') ?? '');
        // The whole header, so that the case cannot pass on a request that
        // answered nothing at all: class `1` is what a WebDAV server is by
        // being one (RFC 4918 §18.1), and this plugin claims nothing beside
        // it.
        self::assertSame('1', $answer->headers()->first('DAV'));
    }

    /**
     * The sharee of §5.3.2.1's own example, with everything §6.5 allows.
     */
    private function eric(): Sharee
    {
        return new Sharee(
            'mailto:eric@example.com',
            SharingAccess::ReadWrite,
            InviteStatus::NoResponse,
            'Eric York',
            'Shared workspace',
        );
    }

    /**
     * A calendar over a store that knows about sharing.
     *
     * @param list<Sharee> $sharees
     * @param list<string> $modes
     */
    private function calendar(array $sharees = [], array $modes = []): Calendar
    {
        return Calendar::of(new MemoryShareable('work', $sharees, $modes));
    }

    /**
     * What a `PROPFIND` naming these properties on this node answers.
     */
    private function asking(string $properties, INode $node): string
    {
        return $this->askingWhole('<D:prop>' . $properties . '</D:prop>', $node);
    }

    private function askingWhole(string $body, INode $node): string
    {
        return (string) $this->propFind($body, $node)->body();
    }

    private function propFind(string $body, INode $node): Response
    {
        return $this->server($node)->handle(new Request(
            'PROPFIND',
            '/calendars/work',
            headers: new Headers(['Depth' => '0']),
            body: new Body(sprintf(
                '<D:propfind xmlns:D="DAV:" xmlns:T="%s">%s</D:propfind>',
                self::NS,
                $body,
            )),
        ));
    }

    private function propPatch(string $set, INode $node): Response
    {
        return $this->server($node)->handle(new Request(
            'PROPPATCH',
            '/calendars/work',
            headers: new Headers(['Content-Type' => 'application/xml']),
            body: new Body(sprintf(
                '<D:propertyupdate xmlns:D="DAV:" xmlns:T="%s"><D:set><D:prop>%s</D:prop></D:set>'
                . '</D:propertyupdate>',
                self::NS,
                $set,
            )),
        ));
    }

    /**
     * A tree holding this one node, under a collection of calendars.
     */
    private function server(INode $node): Server
    {
        $events = new EventEmitter();

        $events->on(PropertiesRequested::class, (new LiveProperties())(...));

        $server = new Server(
            new Tree((new MemoryCollection(''))->add((new MemoryCollection('calendars'))->add($node))),
            $events,
            new Writer(['DAV:' => 'd', self::NS => 'cs']),
        );

        $server->onMethod('PROPFIND', (new PropFind($server))(...));
        $server->onMethod('PROPPATCH', (new PropPatch($server))(...));

        (new Sharing($server))->register();

        return $server;
    }
}
