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
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\INode;
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
use DavServices\Tests\Unit\CalDav\MemorySharedWithMe;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Xml\Writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for what a sharee reads on their own copy. R-CAL-20, P7-05c.
 *
 * ## `CS:shared-url`, §5.2.4
 *
 * > Purpose: Indicates the URL of the owner's copy of a shared calendar.
 * >
 * > Protected: This property MUST be protected.
 * >
 * > PROPFIND behavior: This property SHOULD NOT be returned by a PROPFIND
 * > allprop request.
 * >
 * > Description: This WebDAV property is present on a shared calendar
 * > collection resource that appears in a sharee's calendar home collection.
 * > Its content is a single DAV:href element whose value is the URL of the
 * > sharer's calendar being shared.
 * >
 * > <!ELEMENT shared-url (DAV:href)>
 *
 * ## `CS:organizer` inside `CS:invite`, §5.2.2
 *
 * > In addition, servers SHOULD include a CS:organizer XML element on calendar
 * > collection resources of the sharees to provide clients with a fast way to
 * > determine who the sharer is.
 *
 * **And the sharer is already named on the copy.** §5.5.5 puts `DAV:owner` on
 * a shared calendar as "a reference to a principal-URL of the sharer (owner)",
 * and says what a client does with it there: "Clients can use this value to
 * determine what the allowed ORGANIZER iCalendar property values are" — a
 * client working in the sharee's copy. So the organizer is read from the copy
 * itself and nothing is stored twice.
 */
#[CoversClass(Sharing::class)]
final class SharingShareeTest extends TestCase
{
    private const NS = 'http://calendarserver.org/ns/';

    private const PROTECTED_PROPERTY = '<d:status>HTTP/1.1 403 Forbidden</d:status>';

    private const CANNOT_MODIFY = 'cannot-modify-protected-property';

    /**
     * **§5.2.4: the copy says where the original is**, as the store gave it.
     * Nothing is rewritten: §5.3.2 shares between principals of one server, so
     * the URL is one this server made, and a server that rebuilt it would be
     * guessing at a path the store already knows.
     */
    public function testACopyNamesWhereTheOriginalIs(): void
    {
        $answer = $this->asking('<T:shared-url/>', Calendar::of(new MemorySharedWithMe('work')));

        self::assertStringContainsString(
            '<cs:shared-url><d:href>/calendars/alice/work/</d:href></cs:shared-url>',
            $answer,
        );
    }

    /**
     * And a calendar that is nobody's copy says nothing of the sort: §5.2.4
     * has the property "present on a shared calendar collection resource that
     * appears in a sharee's calendar home collection", and a calendar of one's
     * own is not one of those.
     */
    public function testACalendarThatIsNobodysCopyNamesNoOriginal(): void
    {
        $answer = $this->asking(
            '<T:shared-url/>',
            Calendar::of(new MemoryShareable('work', [], [IShareable::CAN_BE_SHARED])),
        );

        self::assertStringContainsString('404 Not Found', $answer);
        self::assertStringNotContainsString('<cs:shared-url>', $answer);
    }

    /**
     * Nor does something that is no calendar at all, §5.2 being headed
     * "Additional Properties for **Calendars**".
     */
    public function testSomethingThatIsNoCalendarNamesNoOriginal(): void
    {
        $answer = $this->asking('<T:shared-url/>', new MemorySharedWithMe('work'));

        self::assertStringContainsString('404 Not Found', $answer);
        self::assertStringNotContainsString('<cs:shared-url>', $answer);
    }

    /**
     * **§5.2.4 withholds it from `allprop`** in the same words as the other
     * two, and `DAV:include` still gets it (RFC 4918 §9.1).
     */
    public function testTheSharedUrlIsWithheldFromAllpropAndComesWithInclude(): void
    {
        $withheld = $this->askingWhole('<D:allprop/>', Calendar::of(new MemorySharedWithMe('work')));
        $included = $this->askingWhole(
            '<D:allprop/><D:include><T:shared-url/></D:include>',
            Calendar::of(new MemorySharedWithMe('work')),
        );

        self::assertStringNotContainsString('<cs:shared-url>', $withheld);
        // And it was an allprop request at all, which a prefix left off would
        // quietly cost.
        self::assertStringContainsString('<d:resourcetype>', $withheld);
        self::assertStringContainsString('<cs:shared-url>', $included);
    }

    /**
     * **§5.2.4: "This property MUST be protected."** Where the original is, is
     * what the acceptance of an invitation decided; a client that could write
     * it would point its own copy at somebody else's calendar.
     */
    public function testTheSharedUrlIsProtected(): void
    {
        $answer = (string) $this->propPatch(
            '<T:shared-url><D:href>/calendars/bob/secrets/</D:href></T:shared-url>',
            Calendar::of(new MemorySharedWithMe('work')),
        )->body();

        self::assertStringContainsString(self::PROTECTED_PROPERTY, $answer);
        self::assertStringContainsString(self::CANNOT_MODIFY, $answer);
    }

    /**
     * **§5.2.2: the copy says who shared it**, which that section asks for "to
     * provide clients with a fast way to determine who the sharer is" — and
     * §6.18's grammar is `(DAV:href, CS:common-name?, …)`, so the name comes
     * with it where the principal has one.
     */
    public function testACopyNamesWhoSharedIt(): void
    {
        $answer = $this->asking('<T:invite/>', Calendar::of(new MemorySharedWithMe('work')));

        self::assertStringContainsString(
            '<cs:invite><cs:organizer><d:href>/principals/alice/</d:href>'
            . '<cs:common-name>Alice</cs:common-name></cs:organizer></cs:invite>',
            $answer,
        );
    }

    /**
     * **And the organizer comes before the sharees**, §5.2.2's grammar being
     * a sequence: `<!ELEMENT invite (organizer?, user*)>`.
     *
     * What a sharee may see of the list is the store's to decide, and §5.2.2
     * says so: "A server's local privacy policy may prevent sharees from
     * knowing about other sharees on a shared calendar. If that is so server
     * will not include CS:user XML elements for other sharees." A library
     * cannot know a deployment's policy, so it carries what the store hands
     * over.
     */
    public function testTheOrganiserComesBeforeTheSharees(): void
    {
        $answer = $this->asking('<T:invite/>', Calendar::of(new MemorySharedWithMe('work', sharees: [
            new Sharee('/principals/eric/', SharingAccess::Read, InviteStatus::Accepted),
        ])));

        self::assertStringContainsString(
            '<cs:invite><cs:organizer><d:href>/principals/alice/</d:href>'
            . '<cs:common-name>Alice</cs:common-name></cs:organizer>'
            . '<cs:user><d:href>/principals/eric/</d:href><cs:invite-accepted/>'
            . '<cs:access><cs:read/></cs:access></cs:user></cs:invite>',
            $answer,
        );
    }

    /**
     * **A sharer's own calendar carries no organizer**, which is the half that
     * keeps this from being answered everywhere: §5.2.2 asks for the element
     * "on calendar collection resources **of the sharees**", and on the
     * sharer's own copy the sharer is whoever is reading it.
     */
    public function testASharersOwnCalendarCarriesNoOrganiser(): void
    {
        $answer = $this->asking('<T:invite/>', Calendar::of(new MemoryShareable('work', [
            new Sharee('/principals/eric/', SharingAccess::Read, InviteStatus::Accepted),
        ])));

        self::assertStringContainsString('<cs:invite><cs:user>', $answer);
        self::assertStringNotContainsString('organizer', $answer);
    }

    /**
     * **And a copy that names no sharer carries none either.** §6.18 makes the
     * `DAV:href` the one required child, so there is nothing to write without
     * one — and §5.2.2's organizer is a SHOULD, which a server that cannot
     * tell who the sharer is may not keep.
     */
    #[DataProvider('copiesWithNoSharer')]
    public function testACopyThatNamesNoSharerCarriesNoOrganiser(?string $owner): void
    {
        $answer = $this->asking('<T:invite/>', Calendar::of(new MemorySharedWithMe(
            'work',
            sharees: [new Sharee('/principals/eric/', SharingAccess::Read, InviteStatus::Accepted)],
            owner: $owner,
        )));

        self::assertStringContainsString('<cs:invite><cs:user>', $answer);
        self::assertStringNotContainsString('organizer', $answer);
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function copiesWithNoSharer(): iterable
    {
        yield 'no property at all' => [null];
        yield 'a property that names nobody' => [''];
        yield 'an address that is blank' => [' '];
    }

    /**
     * **A sharer this server does not host is named by address alone.**
     * §6.18's `CS:common-name` is optional and that section allows for
     * exactly this: "In some cases servers might have directory information
     * that includes only the common name" — or, here, none at all.
     */
    public function testASharerThisServerDoesNotHostIsNamedByAddressAlone(): void
    {
        $answer = $this->asking(
            '<T:invite/>',
            Calendar::of(new MemorySharedWithMe('work', owner: '/principals/ghost/')),
        );

        self::assertStringContainsString(
            '<cs:organizer><d:href>/principals/ghost/</d:href></cs:organizer>',
            $answer,
        );
        self::assertStringNotContainsString('common-name', $answer);
    }

    /**
     * And neither is one whose address climbs out of the tree: `Uri\Path`
     * refuses it, and a name that cannot be looked up is a name this server
     * does not have.
     */
    public function testASharerWhoseAddressClimbsOutOfTheTreeIsNamedByAddressAlone(): void
    {
        $answer = $this->asking(
            '<T:invite/>',
            Calendar::of(new MemorySharedWithMe('work', owner: '/../principals/alice/')),
        );

        self::assertStringContainsString('<cs:organizer><d:href>', $answer);
        self::assertStringNotContainsString('common-name', $answer);
    }

    /**
     * **A copy with no sharee list still says who shared it**, §5.2.2's
     * `(organizer?, user*)` admitting a document with the organizer and no
     * users at all — and the organizer being the half a sharee is certain to
     * want.
     */
    public function testACopyWithNoShareeListStillSaysWhoSharedIt(): void
    {
        $answer = $this->asking('<T:invite/>', Calendar::of(new MemorySharedWithMe('work')));

        self::assertStringNotContainsString('<cs:user>', $answer);
        self::assertStringNotContainsString('404 Not Found', $answer);
    }

    /**
     * A `PROPFIND` naming properties in both namespaces.
     */
    private function asking(string $properties, INode $calendar): string
    {
        return $this->askingWhole('<D:prop>' . $properties . '</D:prop>', $calendar);
    }

    private function askingWhole(string $body, INode $calendar): string
    {
        return (string) $this->server($calendar)->handle(new Request(
            'PROPFIND',
            '/calendars/work',
            headers: new Headers(['Depth' => '0']),
            body: new Body(sprintf(
                '<D:propfind xmlns:D="DAV:" xmlns:T="%s">%s</D:propfind>',
                self::NS,
                $body,
            )),
        ))->body();
    }

    private function propPatch(string $set, INode $calendar): Response
    {
        return $this->server($calendar)->handle(new Request(
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
     * The calendar under test at `/calendars/work`, and the sharer's
     * principal where their name can be read.
     */
    private function server(INode $calendar): Server
    {
        $events = new EventEmitter();

        $events->on(PropertiesRequested::class, (new LiveProperties())(...));

        $server = new Server(
            new Tree(
                (new MemoryCollection(''))
                    ->add((new MemoryCollection('calendars'))->add($calendar))
                    ->add((new MemoryCollection('principals'))
                        ->add(new Principal(new PrincipalInfo('alice', 'Alice')))),
            ),
            $events,
            new Writer(['DAV:' => 'd', self::NS => 'cs']),
        );

        $server->onMethod('PROPFIND', (new PropFind($server))(...));
        $server->onMethod('PROPPATCH', (new PropPatch($server))(...));

        (new Sharing($server))->register();

        return $server;
    }
}
