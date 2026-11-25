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
use DavServices\CalDav\Schedule\Inbox;
use DavServices\Dav\Acl\PrincipalInfo;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\Method\PropPatch;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Http\Body;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Plugin\DeadProperties;
use DavServices\Plugin\Scheduling;
use DavServices\Tests\Unit\Backend\MemoryCalendarUserBackend;
use DavServices\Tests\Unit\Backend\MemoryPropertyStorage;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Uri\Path;
use DavServices\Xml\Element;
use DavServices\Xml\Writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for RFC 6638 §4.3's MUST on a changed default calendar, and
 * §4.3.1.2, the precondition that had nothing to raise it. R-CAL-43,
 * R-CODE-12.
 *
 * > Servers **MAY** allow clients to change the default calendar collection
 * > by changing the value of the CALDAV:schedule-default-calendar-URL WebDAV
 * > property on the scheduling Inbox collection. However, the server **MUST**
 * > ensure that any new value for that property refers to a valid calendar
 * > collection belonging to the owner of the scheduling Inbox collection.
 *
 * And §4.3.1.2 is the name to refuse with:
 *
 * > **Apply to:** PROPPATCH
 * >
 * > **Use with:** 403 Forbidden
 * >
 * > **Purpose:** (precondition) -- The client attempted to set the
 * > CALDAV:schedule-default-calendar-URL property to a DAV:href element that
 * > doesn't reference a valid calendar collection.
 *
 * ## Whom it is for, which the note says
 *
 * > Note: Servers that do not allow clients to change the
 * > CALDAV:schedule-default-calendar-URL property would simply return the
 * > DAV:cannot-modify-protected-property precondition defined in Section 16
 * > of WebDAV [RFC4918].
 *
 * **So this is for a deployment that does let a client choose**, and the
 * other case is already answered: §9.2 has the property "MAY be protected in
 * the case where a server does not support changing the default calendar",
 * and {@see DeadProperties} takes the names a client may not change and
 * refuses them with exactly that precondition. Two sentences, two
 * deployments, and this is the half that was missing.
 *
 * ## One rule, because the MUST is one sentence
 *
 * The value is written where it names **one of the owner's calendars**, and
 * refused otherwise. That single question answers every way the memo's
 * sentence can be broken — nothing there, no calendar there, somebody else's
 * calendar, no href at all, another server's URL — and it is the same
 * question {@see Scheduling} already asks when it delivers into a default
 * calendar, so a value this guard accepts is one the delivery will honour.
 *
 * **Whose calendars those are is decided by the calendar home set**, and that
 * is a decision at the wording rather than a convenience. RFC 4791 §6.2.1
 * defines the property as naming "the URL of any WebDAV collections that
 * contain calendar collections **owned by** the associated principal
 * resource", so a deployment that answers a home set has already said where
 * the owner's calendars are. A calendar that `DAV:owner` calls theirs while
 * no home set of theirs contains it is a deployment contradicting itself, and
 * the delivery path passes such a default over — accepting it here would
 * "ensure" a value the server then ignores, which is the one thing §4.3's
 * MUST is against.
 *
 * **Where the owner cannot be established, nothing is valid.** A server that
 * cannot tell whose Inbox this is cannot ensure what §4.3 tells it to ensure,
 * and a `403` naming the precondition says so, where writing the value would
 * promise a client something that can never take effect.
 */
#[CoversClass(Scheduling::class)]
final class SchedulingDefaultCalendarTest extends TestCase
{
    private const NS = 'urn:ietf:params:xml:ns:caldav';

    private const PRECONDITION = 'valid-schedule-default-calendar-URL';

    private const INBOX = '/calendars/bernard/inbox';

    private const HOME_SET = '{urn:ietf:params:xml:ns:caldav}calendar-home-set';

    private const OWNER = '{DAV:}owner';

    private const HREF = '{DAV:}href';

    /**
     * **One of the owner's calendars is what the sentence asks for**, and one
     * is written.
     */
    public function testADefaultAmongTheOwnersCalendarsIsWritten(): void
    {
        $answered = $this->setting('/calendars/bernard/work/');

        self::assertStringContainsString('HTTP/1.1 200 OK', (string) $answered->body());
        self::assertStringNotContainsString(self::PRECONDITION, (string) $answered->body());
    }

    /**
     * **Something that is no calendar collection is refused**, which is the
     * precondition's own words: "a DAV:href element that doesn't reference a
     * valid calendar collection".
     */
    public function testADefaultThatIsNoCalendarCollectionIsRefused(): void
    {
        $this->assertRefuses($this->setting('/calendars/bernard/plain/'));
    }

    /**
     * **And a resource is no collection at all.**
     */
    public function testADefaultThatIsAResourceIsRefused(): void
    {
        $this->assertRefuses($this->setting('/calendars/bernard/work/meeting.ics'));
    }

    /**
     * **An href that leads nowhere is refused**, which is the case a client
     * reaches by sending a path it remembers from before.
     */
    public function testADefaultThatLeadsNowhereIsRefused(): void
    {
        $this->assertRefuses($this->setting('/calendars/bernard/gone/'));
    }

    /**
     * **And one outside what this server serves.** A URL on another host
     * references no collection of this server's, and no amount of walking the
     * tree will find it.
     */
    public function testADefaultOutsideThisServerIsRefused(): void
    {
        $this->assertRefuses($this->setting('https://elsewhere.example.net/calendars/work/'));
    }

    /**
     * **An element carrying no `DAV:href` references nothing**, and §9.2's
     * definition is `(DAV:href?)` — so a client may send none, and what it
     * then names is not a calendar collection either.
     */
    public function testADefaultWithNoHrefIsRefused(): void
    {
        $this->assertRefuses($this->patching(sprintf(
            '<D:set><D:prop><C:schedule-default-calendar-URL xmlns:C="%s"/></D:prop></D:set>',
            self::NS,
        )));
    }

    /**
     * **Somebody else's calendar is refused**, and this is the half of §4.3's
     * MUST that the precondition's own Purpose does not spell out: "a valid
     * calendar collection **belonging to the owner of the scheduling Inbox
     * collection**". Eric's calendar is as valid a calendar collection as
     * Bernard's, and naming it would file every invitation to Bernard where
     * Bernard cannot be the one to answer it.
     */
    public function testADefaultBelongingToSomebodyElseIsRefused(): void
    {
        $this->assertRefuses($this->setting('/calendars/eric/work/'));
    }

    /**
     * **A second home of theirs counts**, because RFC 4791 §6.2.1 says there
     * can be more than one: "There can be multiple calendar collection home
     * sets per user." A rule that read only the first would refuse a calendar
     * the deployment itself calls theirs.
     */
    public function testADefaultInASecondHomeOfTheirsIsWritten(): void
    {
        $answered = $this->setting('/archive/bernard/old/');

        self::assertStringContainsString('HTTP/1.1 200 OK', (string) $answered->body());
        self::assertStringNotContainsString(self::PRECONDITION, (string) $answered->body());
    }

    /**
     * **And a calendar in no home of theirs is refused, though `DAV:owner`
     * calls it theirs.** The home set is what RFC 4791 §6.2.1 defines as
     * holding the collections "owned by the associated principal", so a
     * deployment answering both ways is contradicting itself — and the
     * delivery path passes such a default over, which would leave this guard
     * writing a value the server ignores.
     */
    public function testADefaultOwnedByThemButInNoHomeIsRefused(): void
    {
        $this->assertRefuses($this->setting('/elsewhere/bernard/kept/'));
    }

    /**
     * **Where no owner can be established, nothing is valid.** A server that
     * cannot tell whose Inbox this is cannot ensure what §4.3 asks of the
     * value, and saying so with the precondition is better than writing a
     * default that can never take effect.
     */
    public function testWithNoOwnerAnsweredEvenTheirOwnCalendarIsRefused(): void
    {
        $this->assertRefuses($this->setting('/calendars/bernard/work/', owned: false));
    }

    /**
     * **Removing it passes this guard untouched.** §4.3.1.1 is the
     * precondition for taking the default away, and it asks a different
     * question — whether the server allows it at all.
     */
    public function testRemovingItIsNotSettingItToNonsense(): void
    {
        $answered = $this->patching(sprintf(
            '<D:remove><D:prop><C:schedule-default-calendar-URL xmlns:C="%s"/></D:prop></D:remove>',
            self::NS,
        ));

        self::assertStringNotContainsString(self::PRECONDITION, (string) $answered->body());
    }

    /**
     * **Another property is none of this guard's business**, which is what
     * says the refusal is about the value of this one.
     */
    public function testAnotherPropertyIsUntouched(): void
    {
        $answered = $this->patching('<D:set><D:prop><D:displayname>Inbox</D:displayname></D:prop></D:set>');

        self::assertStringContainsString('HTTP/1.1 200 OK', (string) $answered->body());
        self::assertStringNotContainsString(self::PRECONDITION, (string) $answered->body());
    }

    /**
     * **And a refused value takes the rest of the request with it**, which is
     * RFC 4918 §9.2: "If one instruction fails, no instructions are
     * executed." The other name is answered `424 Failed Dependency` so that a
     * client can tell a value this server refused from one it never looked
     * at.
     */
    public function testARefusedDefaultTakesTheRestOfThePatchWithIt(): void
    {
        $answered = $this->patching(sprintf(
            '<D:set><D:prop><C:schedule-default-calendar-URL xmlns:C="%s">'
            . '<D:href>/calendars/bernard/plain/</D:href></C:schedule-default-calendar-URL>'
            . '<D:displayname>Inbox</D:displayname></D:prop></D:set>',
            self::NS,
        ));
        $body = (string) $answered->body();

        self::assertStringContainsString(self::PRECONDITION, $body);
        self::assertStringContainsString('424 Failed Dependency', $body);
        self::assertStringNotContainsString('HTTP/1.1 200 OK', $body);
    }

    /**
     * That the answer refuses the property by name, with §4.3.1.2's
     * precondition and its `403`.
     */
    private function assertRefuses(Response $answered): void
    {
        $body = (string) $answered->body();

        self::assertStringContainsString(self::PRECONDITION, $body);
        self::assertStringContainsString('HTTP/1.1 403 Forbidden', $body);
    }

    /**
     * A `PROPPATCH` setting the default calendar to that href.
     */
    private function setting(string $href, bool $owned = true): Response
    {
        return $this->patching(sprintf(
            '<D:set><D:prop><C:schedule-default-calendar-URL xmlns:C="%s">'
            . '<D:href>%s</D:href></C:schedule-default-calendar-URL></D:prop></D:set>',
            self::NS,
            $href,
        ), $owned);
    }

    private function patching(string $instructions, bool $owned = true): Response
    {
        return $this->server($owned)->handle(new Request(
            'PROPPATCH',
            self::INBOX,
            body: new Body(sprintf('<D:propertyupdate xmlns:D="DAV:">%s</D:propertyupdate>', $instructions)),
        ));
    }

    /**
     * Bernard's world: an Inbox to write the property on, two homes with a
     * calendar each, one object inside one of them, a plain collection that is
     * no calendar collection, a calendar of Eric's, and a calendar that
     * `DAV:owner` calls Bernard's while no home of theirs holds it.
     *
     * **A store for dead properties is wired**, because without one a
     * `PROPPATCH` of this property would be refused for a different reason and
     * no case here could tell the two refusals apart.
     *
     * @param bool $owned Whether the deployment answers `DAV:owner` at all,
     *                    which is the one thing §4.3's MUST cannot be checked
     *                    without
     */
    private function server(bool $owned = true): Server
    {
        $work = new MemoryCollection('work');

        $work->add(new MemoryFile('meeting.ics', "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n"));

        $events = new EventEmitter();

        $events->on(PropertiesRequested::class, static function (PropertiesRequested $event) use ($owned): void {
            self::answer($event, $owned);
        });

        $server = new Server(
            new Tree(
                (new MemoryCollection(''))
                    ->add((new MemoryCollection('calendars'))
                        ->add((new MemoryCollection('bernard'))
                            ->add(Inbox::of(new MemoryCollection('inbox')))
                            ->add(Calendar::of($work))
                            ->add(new MemoryCollection('plain')))
                        ->add((new MemoryCollection('eric'))
                            ->add(Calendar::of(new MemoryCollection('work')))))
                    ->add((new MemoryCollection('archive'))
                        ->add((new MemoryCollection('bernard'))
                            ->add(Calendar::of(new MemoryCollection('old')))))
                    ->add((new MemoryCollection('elsewhere'))
                        ->add((new MemoryCollection('bernard'))
                            ->add(Calendar::of(new MemoryCollection('kept')))))
                    ->add((new MemoryCollection('principals'))
                        ->add(new Principal(new PrincipalInfo('bernard', 'Bernard')))
                        ->add(new Principal(new PrincipalInfo('eric', 'Eric')))),
            ),
            $events,
            new Writer(['DAV:' => 'd', self::NS => 'C']),
        );

        $server->onMethod('PROPPATCH', (new PropPatch($server))(...));

        (new DeadProperties(new MemoryPropertyStorage()))->registerOn($server->events());
        (new Scheduling($server, new MemoryCalendarUserBackend([], '/principals/')))->register();

        return $server;
    }

    /**
     * What a deployment answers: where each principal's calendars are, and
     * whose every resource three segments deep is.
     *
     * Bernard has two homes, because RFC 4791 §6.2.1 allows it and one of the
     * cases turns on it. `/elsewhere/bernard/kept` is in neither, and is still
     * answered as Bernard's, which is the contradiction one case is about.
     */
    private static function answer(PropertiesRequested $event, bool $owned): void
    {
        $result = $event->result();
        $node = $event->node();

        if ($node instanceof Principal && $result->wants(self::HOME_SET)) {
            $result->set(self::HOME_SET, self::naming(self::HOME_SET, $node->name() === 'bernard'
                ? ['/calendars/bernard/', '/archive/bernard/']
                : ['/calendars/' . $node->name() . '/']));
        }

        $segments = Path::segments($result->path());

        if (!$owned || !$result->wants(self::OWNER) || count($segments) !== 3) {
            return;
        }

        $result->set(self::OWNER, self::naming(self::OWNER, ['/principals/' . $segments[1] . '/']));
    }

    /**
     * A property holding a `DAV:href` for each of those.
     *
     * @param list<string> $hrefs
     */
    private static function naming(string $property, array $hrefs): Element
    {
        $element = new Element($property);

        foreach ($hrefs as $href) {
            $child = new Element(self::HREF);

            $child->appendText($href);
            $element->append($child);
        }

        return $element;
    }
}
