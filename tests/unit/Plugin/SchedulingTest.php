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
use DavServices\CalDav\Schedule\Outbox;
use DavServices\Dav\Acl\PrincipalInfo;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\Method\Delete;
use DavServices\Dav\Method\Options;
use DavServices\Dav\Method\PropFind;
use DavServices\Dav\Property\LiveProperties;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Plugin\Scheduling;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Tests\Unit\Dav\TypedCollection;
use DavServices\Xml\Element;
use DavServices\Xml\Writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for the scheduling plugin. R-CAL-30, R-CAL-31, P8-01.
 *
 * RFC 6638 §2.1.1 and §2.2.1 define one property each, in the same words:
 *
 * > Purpose: Identify the URL of the scheduling Outbox collection owned by the
 * > associated principal resource.
 * >
 * > Protected: This property MAY be protected.
 * >
 * > PROPFIND behavior: This property SHOULD NOT be returned by a PROPFIND
 * > DAV:allprop request.
 * >
 * > Description: This property is needed for a client to determine where the
 * > scheduling Outbox collection of the current user is located so that
 * > sending of scheduling messages can occur. **If not present, then the
 * > associated calendar user is not enabled for the sending of scheduling
 * > messages on the server.**
 *
 * ## That last sentence decides where the answer comes from
 *
 * The same sentence `caldav-sharing` §4.1.1 has about a notification
 * collection, and it settles the question the same way: the property is
 * answered only where the collection really is, because a property naming one
 * that is not there would send a client to a `404` for ever.
 *
 * **And the collections are looked for rather than named.** RFC 6638 says
 * nowhere what they are called — §2 says only that creating them "is the
 * server's responsibility" — so this plugin reads the principal's
 * `CALDAV:calendar-home-set`, which is where this server itself says a user's
 * calendars are, and looks there for a child whose `DAV:resourcetype` carries
 * `CALDAV:schedule-inbox` or `CALDAV:schedule-outbox`. A deployment that keeps
 * them somewhere else answers the property from its own store, and this plugin
 * finding nothing leaves that answer standing.
 */
#[CoversClass(Scheduling::class)]
final class SchedulingTest extends TestCase
{
    private const NS = 'urn:ietf:params:xml:ns:caldav';

    private const HOME_SET = '{' . self::NS . '}calendar-home-set';

    /**
     * **§2.2.1: a principal whose home holds an Inbox carries its URL.** With
     * the trailing slash RFC 4918 §5.2 asks for "wherever a server produces a
     * URL referring to a collection".
     */
    public function testAPrincipalWhoseHomeHoldsAnInboxCarriesItsUrl(): void
    {
        $answer = $this->asking('<C:schedule-inbox-URL/>', '/principals/bernard');

        self::assertStringContainsString(
            '<C:schedule-inbox-URL><d:href>/calendars/bernard/inbox/</d:href></C:schedule-inbox-URL>',
            $answer,
        );
    }

    /**
     * **§2.1.1: and the Outbox the same way.** The two sections are one
     * sentence apart and differ only in which collection they name.
     */
    public function testAPrincipalWhoseHomeHoldsAnOutboxCarriesItsUrl(): void
    {
        $answer = $this->asking('<C:schedule-outbox-URL/>', '/principals/bernard');

        self::assertStringContainsString(
            '<C:schedule-outbox-URL><d:href>/calendars/bernard/outbox/</d:href></C:schedule-outbox-URL>',
            $answer,
        );
    }

    /**
     * **A principal with neither carries neither**, which is §2.1.1's and
     * §2.2.1's own sentence: "If not present, then the associated calendar
     * user is not enabled for the sending of scheduling messages on the
     * server."
     *
     * Wilfredo has a calendar home and nothing scheduling-shaped in it, which
     * is the case that tells this apart from a principal with no home at all.
     */
    #[DataProvider('bothProperties')]
    public function testAPrincipalWithNeitherCollectionCarriesNoUrl(string $property): void
    {
        $answer = $this->asking('<C:' . $property . '/>', '/principals/wilfredo');

        self::assertStringContainsString('404 Not Found', $answer);
        // The name comes back empty in the `404` block, which is what "no
        // such property here" looks like. The response carries an href of its
        // own, so looking for the absence of one would pass for nothing.
        self::assertStringContainsString('<C:' . $property . '/>', $answer);
    }

    /**
     * **And neither does one this server publishes no calendar home for.**
     * There is nowhere to look, and §2 gives the collections no place of their
     * own: "It is the server's responsibility to create these collection
     * resources."
     */
    #[DataProvider('bothProperties')]
    public function testAPrincipalWithNoHomeAtAllCarriesNoUrl(string $property): void
    {
        $answer = $this->asking('<C:' . $property . '/>', '/principals/nobody');

        self::assertStringContainsString('404 Not Found', $answer);
        self::assertStringContainsString('<C:' . $property . '/>', $answer);
    }

    /**
     * Nor does something that is no principal: §2.4's properties are "new
     * properties for WebDAV principal resources", and §2.1.1 has this one
     * "owned by the associated principal resource".
     */
    #[DataProvider('bothProperties')]
    public function testSomethingThatIsNoPrincipalCarriesNoUrl(string $property): void
    {
        $answer = $this->asking('<C:' . $property . '/>', '/calendars/bernard/inbox');

        self::assertStringContainsString('404 Not Found', $answer);
    }

    /**
     * **Both are withheld from `allprop`**, each section saying so in the same
     * words — and `DAV:include` still gets them (RFC 4918 §9.1).
     */
    #[DataProvider('bothProperties')]
    public function testBothAreWithheldFromAllpropAndComeWithInclude(string $property): void
    {
        $withheld = $this->askingWhole('<D:allprop/>', '/principals/bernard');
        $included = $this->askingWhole(
            '<D:allprop/><D:include><C:' . $property . '/></D:include>',
            '/principals/bernard',
        );

        self::assertStringNotContainsString($property, $withheld);
        // And it was an allprop request at all, which a prefix left off would
        // quietly cost.
        self::assertStringContainsString('<d:resourcetype>', $withheld);
        self::assertStringContainsString($property, $included);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bothProperties(): iterable
    {
        yield 'the Inbox' => ['schedule-inbox-URL'];
        yield 'the Outbox' => ['schedule-outbox-URL'];
    }

    /**
     * **§2: a client may not delete either collection.**
     *
     * > It is the server's responsibility to create these collection
     * > resources, and clients have no way to create or delete them.
     *
     * A user who may empty their own calendar home would otherwise be able to
     * take their own Inbox away and stop every invitation ever sent to them,
     * with one request and no way back.
     */
    #[DataProvider('bothCollections')]
    public function testAClientMayNotDeleteEitherCollection(string $path, string $name): void
    {
        $server = $this->server();

        $server->onMethod('DELETE', (new Delete($server))(...));

        self::assertSame(403, $server->handle(new Request('DELETE', $path))->status());
        self::assertTrue(
            $server->tree()->exists('calendars/bernard/' . $name),
            'and it is still there',
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bothCollections(): iterable
    {
        yield 'the Inbox' => ['/calendars/bernard/inbox', 'inbox'];
        yield 'the Outbox' => ['/calendars/bernard/outbox', 'outbox'];
    }

    /**
     * **And an ordinary calendar still goes**, which is the half that keeps
     * the guard off every other `DELETE` this server answers.
     */
    public function testAnOrdinaryCalendarStillGoes(): void
    {
        $server = $this->server();

        $server->onMethod('DELETE', (new Delete($server))(...));

        self::assertSame(204, $server->handle(new Request('DELETE', '/calendars/bernard/work'))->status());
        self::assertFalse($server->tree()->exists('calendars/bernard/work'));
    }

    /**
     * **It announces no `calendar-auto-schedule`, and that is deliberate.**
     * §2 puts the token on "any resource that supports any scheduling
     * operations, properties, privileges, or methods" — and on its own words
     * these two properties would be enough.
     *
     * **It is withheld anyway**, because of what the token is read for: a
     * client that sees it stops sending invitations itself and leaves that to
     * the server, and §3.2.1.1's "the server MUST send scheduling messages" is
     * not here yet. Nothing would be delivered, and nobody would be told —
     * which is worse than silence by a wider margin than any other promise in
     * this library, because what goes missing is somebody's invitation rather
     * than a feature.
     *
     * The same judgement {@see \DavServices\Plugin\CalDav} makes about
     * `calendar-access` and {@see \DavServices\Plugin\Sharing} made twice
     * about `calendarserver-sharing`. The token comes with the phase.
     */
    public function testItAnnouncesNoAutoSchedulingYet(): void
    {
        $server = $this->server();

        $server->onMethod('OPTIONS', (new Options($server))(...));

        $answer = $server->handle(new Request('OPTIONS', '/calendars/bernard/outbox'));

        self::assertStringNotContainsString('calendar-auto-schedule', $answer->headers()->first('DAV') ?? '');
        self::assertSame('1', $answer->headers()->first('DAV'));
    }

    /**
     * **Whatever else is in that property is read rather than assumed.**
     * Another plugin answers `CALDAV:calendar-home-set`, so a child that is no
     * `DAV:href` is passed over and the address beside it still counts — here
     * the stray one comes first.
     */
    public function testAStrayElementInTheHomeSetIsPassedOver(): void
    {
        $set = new Element(self::HOME_SET);

        $set->append(new Element('{https://dav.services/test}something-else'));
        $set->append(self::hrefSaying('/calendars/bernard/'));

        $answer = $this->asking('<C:schedule-inbox-URL/>', '/principals/bernard', $set);

        self::assertStringContainsString('<d:href>/calendars/bernard/inbox/</d:href>', $answer);
    }

    /**
     * **And a home this server cannot resolve is no home — and not the end of
     * the list.** RFC 4791 §6.2.1 allows more than one: "There can be
     * multiple", so one address that leads nowhere is one entry passed over.
     *
     * Three ways it can lead nowhere, and all three come from whatever answers
     * that property rather than from a client — which is why none of them may
     * take the request down with it.
     */
    #[DataProvider('homesThatLeadNowhere')]
    public function testAHomeThatLeadsNowhereIsPassedOver(string $href): void
    {
        $set = new Element(self::HOME_SET);

        $set->append(self::hrefSaying($href));
        $set->append(self::hrefSaying('/calendars/bernard/'));

        $answer = $this->asking('<C:schedule-inbox-URL/>', '/principals/bernard', $set);

        self::assertStringContainsString('<d:href>/calendars/bernard/inbox/</d:href>', $answer);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function homesThatLeadNowhere(): iterable
    {
        yield 'it climbs out of the tree' => ['/../calendars/bernard/'];
        yield 'there is nothing there' => ['/calendars/nobody/'];
        yield 'it is no collection' => ['/calendars/bernard/notes.txt'];
    }

    /**
     * And where every address leads nowhere, the property is not answered at
     * all — which is §2.2.1's "not enabled" again.
     */
    public function testAHomeSetThatLeadsNowhereAnswersNothing(): void
    {
        $set = new Element(self::HOME_SET);

        $set->append(self::hrefSaying('/calendars/nobody/'));

        $answer = $this->asking('<C:schedule-inbox-URL/>', '/principals/bernard', $set);

        self::assertStringContainsString('404 Not Found', $answer);
    }

    private static function hrefSaying(string $href): Element
    {
        $names = new Element('{DAV:}href');

        $names->appendText($href);

        return $names;
    }

    private function asking(string $properties, string $path, ?Element $homeSet = null): string
    {
        return $this->askingWhole('<D:prop>' . $properties . '</D:prop>', $path, $homeSet);
    }

    private function askingWhole(string $body, string $path, ?Element $homeSet = null): string
    {
        return (string) $this->server($homeSet)->handle(new Request(
            'PROPFIND',
            $path,
            headers: new Headers(['Depth' => '0']),
            body: new Body(sprintf(
                '<D:propfind xmlns:D="DAV:" xmlns:C="%s">%s</D:propfind>',
                self::NS,
                $body,
            )),
        ))->body();
    }

    /**
     * Bernard's home with an Inbox, an Outbox and a calendar; Wilfredo's with
     * nothing but a calendar; and a principal this server publishes no home
     * for at all.
     *
     * **`CALDAV:calendar-home-set` is answered by a listener here** rather
     * than by {@see \DavServices\Plugin\CalDav}, because what this plugin
     * reads is the property and not that plugin: whatever this server
     * publishes as a principal's calendar home is where the collections are
     * looked for.
     */
    private function server(?Element $homeSet = null): Server
    {
        $events = new EventEmitter();

        $events->on(PropertiesRequested::class, (new LiveProperties())(...));
        $events->on(PropertiesRequested::class, static function (PropertiesRequested $event) use ($homeSet): void {
            $node = $event->node();
            $result = $event->result();

            if (!$node instanceof Principal || !$result->wants(self::HOME_SET) || $node->name() === 'nobody') {
                return;
            }

            if ($homeSet !== null) {
                $result->set(self::HOME_SET, $homeSet);

                return;
            }

            $set = new Element(self::HOME_SET);
            $href = new Element('{DAV:}href');

            $href->appendText('/calendars/' . $node->name() . '/');
            $set->append($href);
            $result->set(self::HOME_SET, $set);
        });

        $server = new Server(
            new Tree(
                (new MemoryCollection(''))
                    ->add((new MemoryCollection('calendars'))
                        ->add((new MemoryCollection('bernard'))
                            ->add(Inbox::of(new MemoryCollection('inbox')))
                            ->add(Outbox::of(new MemoryCollection('outbox')))
                            ->add(new TypedCollection('work', Calendar::CALENDAR))
                            ->add(new MemoryFile('notes.txt', 'nothing to do with scheduling')))
                        ->add((new MemoryCollection('wilfredo'))
                            ->add(new TypedCollection('work', Calendar::CALENDAR))))
                    ->add((new MemoryCollection('principals'))
                        ->add(new Principal(new PrincipalInfo('bernard', 'Bernard')))
                        ->add(new Principal(new PrincipalInfo('wilfredo', 'Wilfredo')))
                        ->add(new Principal(new PrincipalInfo('nobody', 'Nobody')))),
            ),
            $events,
            new Writer(['DAV:' => 'd', self::NS => 'C']),
        );

        $server->onMethod('PROPFIND', (new PropFind($server))(...));

        (new Scheduling($server))->register();

        return $server;
    }
}
