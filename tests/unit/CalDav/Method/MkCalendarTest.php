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

namespace DavServices\Tests\Unit\CalDav\Method;

use DavServices\CalDav\Method\MkCalendar;
use DavServices\Dav\Event\AfterBind;
use DavServices\Dav\Event\AfterCreateCollection;
use DavServices\Dav\Event\BeforeBind;
use DavServices\Dav\Event\BeforeCreateCollection;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\Method\PropFind;
use DavServices\Dav\Property\LiveProperties;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Tests\Unit\CalDav\MemoryCalendarHome;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for `MKCALENDAR`. R-CAL-01, P6-01.
 *
 * RFC 4791 §5.3.1: "An HTTP request using the MKCALENDAR method creates a new
 * calendar collection resource. A server MAY restrict calendar collection
 * creation to particular collections."
 *
 *     <!ELEMENT mkcalendar (DAV:set)>
 *
 * **Support is only RECOMMENDED, not REQUIRED**, and the memo says why: "some
 * calendar stores only support one calendar per user (or principal), and those
 * are typically pre-created for each account." A server that has no store able
 * to make one simply does not register the method — which is why this is a
 * method handed to the server rather than something the plugin switches on by
 * itself.
 *
 * ## The preconditions, and the postcondition
 *
 * §5.3.1 names four preconditions — `DAV:resource-must-be-null`,
 * `CALDAV:calendar-collection-location-ok`, `CALDAV:valid-calendar-data` and
 * `DAV:needs-privilege` — and one postcondition,
 * `CALDAV:initialize-calendar-collection`: "A new calendar collection exists at
 * the Request-URI. The DAV:resourcetype of the calendar collection MUST contain
 * both DAV:collection and CALDAV:calendar XML elements."
 *
 * `DAV:needs-privilege` is `Plugin\Acl`'s, which guards `DAV:bind` on every
 * method that binds; the other three are here.
 */
#[CoversClass(MkCalendar::class)]
final class MkCalendarTest extends TestCase
{
    private const CALDAV = 'xmlns:C="urn:ietf:params:xml:ns:caldav"';

    /** RFC 4791 §5.2.2's example, shortened to the one observance it needs. */
    private const US_EASTERN = "BEGIN:VCALENDAR\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\nVERSION:2.0\r\n"
        . "BEGIN:VTIMEZONE\r\nTZID:US-Eastern\r\nBEGIN:STANDARD\r\nDTSTART:19671029T020000\r\n"
        . "TZOFFSETFROM:-0400\r\nTZOFFSETTO:-0500\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\nEND:VCALENDAR\r\n";

    /**
     * **Every event this method raises is something another plugin is waiting
     * for**, and a mutation run on 15.10.2026 showed that all five could be
     * deleted with nothing failing. They are not decoration:
     *
     * - `BeforeBind` is where `Plugin\Acl` asks for the `DAV:bind` privilege
     *   §5.3.1 names as a precondition, and where a lock on the parent is
     *   checked;
     * - `BeforeCreateCollection` carries the kind, and is where
     *   `Plugin\CalDav` refuses §4.2(b)'s nesting;
     * - `AfterCreateCollection` and `AfterBind` are where a store that keeps
     *   an index of its own hears about the new member.
     */
    public function testItRaisesTheEventsOtherPluginsWaitFor(): void
    {
        $server = $this->server();
        $raised = [];

        foreach (
            [
                BeforeBind::class,
                BeforeCreateCollection::class,
                AfterCreateCollection::class,
                AfterBind::class,
            ] as $event
        ) {
            $server->events()->on($event, static function (object $raisedEvent) use (&$raised): void {
                $raised[] = $raisedEvent::class;
            });
        }

        $this->making($server, '/calendars/soccer');

        self::assertSame(
            [BeforeBind::class, BeforeCreateCollection::class, AfterCreateCollection::class, AfterBind::class],
            $raised,
        );
    }

    /**
     * And the kind travels with the creation event, because that is what
     * `Plugin\CalDav` reads to tell a calendar from a plain collection.
     */
    public function testTheCreationEventSaysWhatKindIsBeingMade(): void
    {
        $server = $this->server();
        $kinds = [];

        $server->events()->on(
            BeforeCreateCollection::class,
            static function (BeforeCreateCollection $event) use (&$kinds): void {
                $kinds = $event->resourceTypes();
            },
        );

        $this->making($server, '/calendars/soccer');

        self::assertSame(['{DAV:}collection', '{urn:ietf:params:xml:ns:caldav}calendar'], $kinds);
    }

    /**
     * **The tree has to forget the collection the calendar was made in.**
     * R-TREE-05: a listing made before the creation would otherwise be handed
     * out without the new member in it, so a client that made a calendar and
     * then listed its home would not see it.
     *
     * The proof is that the backend is asked again, which is the same proof
     * `MKCOL` is held to — nothing a response body shows would distinguish a
     * fresh listing from a remembered one.
     */
    public function testTheTreeForgetsTheCollectionItWasCreatedIn(): void
    {
        $home = new MemoryCalendarHome('calendars');
        $root = (new MemoryCollection(''))->add($home);
        $tree = new Tree($root);
        $server = new Server($tree);

        $server->onMethod('MKCALENDAR', (new MkCalendar($server))(...));

        $tree->node('calendars');
        $server->handle(new Request('MKCALENDAR', '/calendars/soccer'));
        $tree->node('calendars');

        self::assertSame(2, $root->lookups, 'The collection was handed out from the cache after a member was added.');
    }
    /**
     * **A request with no body is still a request.** §5.3.1 makes the body
     * conditional — "If a request body is included, it MUST be a
     * CALDAV:mkcalendar XML element" — so a bare `MKCALENDAR` asks for a
     * calendar and nothing more.
     */
    public function testABareRequestMakesACalendar(): void
    {
        $server = $this->server();
        $response = $this->making($server, '/calendars/soccer');

        self::assertSame(201, $response->status());
        self::assertTrue($server->tree()->exists('calendars/soccer'));
    }

    /**
     * **§5.3.1: "The response MUST include a Cache-Control:no-cache
     * header."** One sentence, easy to miss, and a client behind a cache that
     * never sees it asks for a calendar it has just made and is told there is
     * nothing there.
     */
    public function testTheResponseSaysItMustNotBeCached(): void
    {
        self::assertSame('no-cache', $this->making($this->server(), '/calendars/soccer')->headers()->first('Cache-Control'));
    }

    /**
     * The postcondition `CALDAV:initialize-calendar-collection`: "The
     * DAV:resourcetype of the calendar collection MUST contain both
     * DAV:collection and CALDAV:calendar XML elements."
     */
    public function testWhatWasMadeSaysItIsACalendar(): void
    {
        $server = $this->server();

        $this->making($server, '/calendars/soccer');

        $body = '<D:propfind xmlns:D="DAV:"><D:prop><D:resourcetype/></D:prop></D:propfind>';
        $answer = (string) $server->handle(new Request(
            'PROPFIND',
            '/calendars/soccer',
            headers: new Headers(['Depth' => '0']),
            body: new Body($body),
        ))->body();

        self::assertStringContainsString('<d:collection/>', $answer);
        self::assertStringContainsString('calendar/>', $answer);
    }

    /**
     * §5.3.1: "Clients can either specify the value of the DAV:displayname
     * property in the request body of the MKCALENDAR request, or alternatively
     * issue a PROPPATCH request […] immediately after". The first of those is
     * what the `DAV:set` is for.
     */
    public function testTheInitialSetIsApplied(): void
    {
        $server = $this->server();

        $this->making($server, '/calendars/soccer', $this->setting(
            '<D:displayname>Soccer</D:displayname>'
            . '<C:calendar-description>The kids\' matches</C:calendar-description>',
        ));

        $body = '<D:propfind xmlns:D="DAV:" ' . self::CALDAV . '><D:prop>'
            . '<D:displayname/><C:calendar-description/></D:prop></D:propfind>';
        $answer = (string) $server->handle(new Request(
            'PROPFIND',
            '/calendars/soccer',
            headers: new Headers(['Depth' => '0']),
            body: new Body($body),
        ))->body();

        self::assertStringContainsString('Soccer', $answer);
        self::assertStringContainsString('matches', $answer);
    }

    /**
     * **An element that is no instruction is passed over rather than
     * refused**, and that is a decision at the wording: §5.3.1 says
     * "Instruction processing MUST occur in the order instructions are
     * received", and a child that is not an instruction is not one of them.
     * Nothing is lost by walking past it — it asks for nothing — whereas
     * refusing would reject a client whose request is otherwise exactly what
     * the DTD describes.
     *
     * The same reading as `MKCOL`'s, which filters the body to its `DAV:set`
     * blocks and their `DAV:prop` blocks for the same reason.
     */
    public function testSomethingThatIsNoInstructionIsPassedOver(): void
    {
        $server = $this->server();
        $body = sprintf(
            '<C:mkcalendar xmlns:D="DAV:" %s><C:stray/><D:set><C:stray/><D:prop>'
            . '<D:displayname>Soccer</D:displayname></D:prop></D:set></C:mkcalendar>',
            self::CALDAV,
        );

        self::assertSame(201, $this->making($server, '/calendars/soccer', $body)->status());

        // **And the instruction after it was read.** Walking past a stray
        // child has to mean carrying on, not stopping: a mutation run turned
        // each `continue` into a `break`, and nothing noticed until this
        // looked at the property.
        $answer = (string) $server->handle(new Request(
            'PROPFIND',
            '/calendars/soccer',
            headers: new Headers(['Depth' => '0']),
            body: new Body('<D:propfind xmlns:D="DAV:"><D:prop><D:displayname/></D:prop></D:propfind>'),
        ))->body();

        self::assertStringContainsString('Soccer', $answer);
    }
    /**
     * **A body that sets nothing asks for what no body asks for.** §5.3.1
     * makes the body itself conditional, so an empty `CALDAV:mkcalendar` is the
     * same request as none — nothing is passed over, because nothing was
     * asked for.
     */
    public function testABodyThatSetsNothingIsStillARequest(): void
    {
        $server = $this->server();
        $body = sprintf('<C:mkcalendar %s/>', self::CALDAV);

        self::assertSame(201, $this->making($server, '/calendars/soccer', $body)->status());
    }

    /**
     * §5.3.1: "If a request body is included, it MUST be a CALDAV:mkcalendar
     * XML element." Another root element is an entity of a type this server
     * does not take, which RFC 4918 §9.3 answers with `415` for the same
     * reason `MKCOL` does.
     */
    public function testRefusesABodyThatIsNoMkcalendar(): void
    {
        $response = $this->making($this->server(), '/calendars/soccer', '<D:mkcol xmlns:D="DAV:"/>');

        self::assertSame(415, $response->status());
    }

    public function testRefusesABodyItCannotRead(): void
    {
        self::assertSame(415, $this->making($this->server(), '/calendars/soccer', '<C:mkcalendar')->status());
    }

    /**
     * The precondition `DAV:resource-must-be-null`: "A resource MUST NOT exist
     * at the Request-URI." RFC 4918 §9.3.1 answers a `MKCOL` on a mapped URL
     * with `405`, and this is the same question.
     */
    public function testRefusesAPathThatIsTakenByName(): void
    {
        $response = $this->making($this->server(), '/calendars/work');

        self::assertSame(405, $response->status());
        self::assertStringContainsString('resource-must-be-null', (string) $response->body());
    }

    /**
     * §5.3.1.1: "409 (Conflict) - A collection cannot be made at the
     * Request-URI until one or more intermediate collections have been
     * created."
     */
    public function testRefusesAPathWhoseParentIsNotThere(): void
    {
        self::assertSame(409, $this->making($this->server(), '/nowhere/soccer')->status());
    }

    /**
     * **The precondition `CALDAV:calendar-collection-location-ok`: "The
     * Request-URI MUST identify a location where a calendar collection can be
     * created."** A store that does not make calendars is such a location, and
     * §5.3.1.1 gives the status: "403 (Forbidden) - This indicates at least one
     * of two conditions: 1) the server does not allow the creation of calendar
     * collections at the given location in its namespace, or 2) the parent
     * collection of the Request-URI exists but cannot accept members".
     */
    public function testRefusesAParentThatDoesNotMakeCalendarsByName(): void
    {
        $response = $this->making($this->server(), '/plain/soccer');

        self::assertSame(403, $response->status());
        self::assertStringContainsString('calendar-collection-location-ok', (string) $response->body());
    }

    /**
     * **The precondition `CALDAV:valid-calendar-data`: "The time zone specified
     * in the CALDAV:calendar-timezone property MUST be a valid iCalendar object
     * containing a single valid VTIMEZONE component."** What counts as valid is
     * settled in `CalDav\TimeZone`; what matters here is that the request is
     * refused by name rather than stored and tripped over later.
     */
    public function testRefusesATimeZoneThatIsNoTimeZoneByName(): void
    {
        $server = $this->server();
        $body = $this->setting('<C:calendar-timezone>Europe/Berlin</C:calendar-timezone>');
        $response = $this->making($server, '/calendars/soccer', $body);

        self::assertSame(403, $response->status());
        self::assertStringContainsString('valid-calendar-data', (string) $response->body());
    }

    public function testATimeZoneThatIsOneIsStored(): void
    {
        $server = $this->server();
        $body = $this->setting(sprintf('<C:calendar-timezone>%s</C:calendar-timezone>', self::US_EASTERN));

        self::assertSame(201, $this->making($server, '/calendars/soccer', $body)->status());

    }

    /**
     * **§5.3.1: "If a MKCALENDAR request fails, the server state preceding the
     * request MUST be restored."** Nothing here is created before the
     * preconditions are answered, so there is nothing to undo — and this is
     * what says so.
     */
    public function testARefusedRequestLeavesNothingBehind(): void
    {
        $server = $this->server();

        $this->making($server, '/calendars/soccer', $this->setting('<C:calendar-timezone>nonsense</C:calendar-timezone>'));

        self::assertFalse($server->tree()->exists('calendars/soccer'));
    }

    /**
     * A `CALDAV:mkcalendar` body wrapping one `DAV:set`.
     */
    private function setting(string $properties): string
    {
        return sprintf(
            '<C:mkcalendar xmlns:D="DAV:" %s><D:set><D:prop>%s</D:prop></D:set></C:mkcalendar>',
            self::CALDAV,
            $properties,
        );
    }

    private function making(Server $server, string $path, ?string $body = null): Response
    {
        return $server->handle(new Request(
            'MKCALENDAR',
            $path,
            body: $body === null ? new Body() : new Body($body),
        ));
    }

    /**
     * A tree with a store that makes calendars, one that does not, and a
     * calendar that is already there.
     */
    private function server(): Server
    {
        $calendars = new MemoryCalendarHome('calendars');

        $calendars->createExtendedCollection('work', ['{DAV:}collection', '{urn:ietf:params:xml:ns:caldav}calendar'], []);

        $root = (new MemoryCollection(''))
            ->add($calendars)
            ->add(new MemoryCollection('plain'));

        $events = new EventEmitter();

        $events->on(PropertiesRequested::class, (new LiveProperties())(...));

        $server = new Server(new Tree($root), $events);

        $server->onMethod('MKCALENDAR', (new MkCalendar($server))(...));
        $server->onMethod('PROPFIND', (new PropFind($server))(...));

        return $server;
    }
}
