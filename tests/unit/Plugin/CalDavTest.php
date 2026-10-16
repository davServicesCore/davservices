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
use DavServices\CalDav\Limits;
use DavServices\CalDav\Method\MkCalendar;
use DavServices\Dav\Acl\PrincipalInfo;
use DavServices\Dav\Event\BeforeCopy;
use DavServices\Dav\Event\BeforeCreateCollection;
use DavServices\Dav\Event\BeforeCreateFile;
use DavServices\Dav\Event\BeforeMove;
use DavServices\Dav\Event\BeforeWriteContent;
use DavServices\Dav\Event\PropertiesChanging;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\INode;
use DavServices\Dav\Method\Copy;
use DavServices\Dav\Method\Move;
use DavServices\Dav\Method\Options;
use DavServices\Dav\Method\PropFind;
use DavServices\Dav\Method\PropPatch;
use DavServices\Dav\Method\Put;
use DavServices\Dav\PropFindForm;
use DavServices\Dav\PropFindResult;
use DavServices\Dav\PropPatchResult;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Exception\Forbidden;
use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Plugin\CalDav;
use DavServices\Plugin\DeadProperties;
use DavServices\Tests\Unit\Backend\MemoryPropertyStorage;
use DavServices\Tests\Unit\CalDav\MemoryCalendarHome;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Tests\Unit\Dav\MemoryPropertyCollection;
use DavServices\Tests\Unit\Dav\StreamFile;
use DavServices\Xml\Element;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for the CalDAV plugin. R-CAL-06, P6-01.
 *
 * **What this plugin answers is the question no node can:** §4.2(b) forbids a
 * calendar collection below a calendar collection "at any depth", and that is a
 * question about *where* a path lies. A node knows its name and nothing about
 * where it hangs — so `CalDav\Calendar` does the half it can see, by not being
 * an extended collection, and the other half is here.
 *
 * ## And one thing it deliberately does not do yet
 *
 * **It does not announce `calendar-access`.** §5.1 makes that value a promise
 * with teeth: "A value of 'calendar-access' in the DAV response header MUST
 * indicate that the server supports all MUST level requirements specified in
 * this document" — and §7.8 makes `CALDAV:calendar-query` one of them. A server
 * announcing the class before the reports exist would send every client
 * straight into a `403`, which is worse than a server a client does not try.
 * The same reasoning kept `CARDDAV:supported-collation-set` out of P5-01 until
 * the collations existed.
 */
#[CoversClass(CalDav::class)]
final class CalDavTest extends TestCase
{
    private const LOCATION_OK = 'calendar-collection-location-ok';

    /** RFC 4791 §5.2.2's example, shortened to the one observance it needs. */
    private const US_EASTERN = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//x//y//EN\r\n"
        . "BEGIN:VTIMEZONE\r\nTZID:US-Eastern\r\nBEGIN:STANDARD\r\nDTSTART:19671029T020000\r\n"
        . "TZOFFSETFROM:-0400\r\nTZOFFSETTO:-0500\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\nEND:VCALENDAR\r\n";

    /**
     * §4.2(b): "Collections contained in calendar collections MUST NOT contain
     * calendar collections at any depth."
     */
    public function testACalendarMayNotBeMadeInsideACalendar(): void
    {
        $server = $this->server();
        $response = $server->handle(new Request('MKCALENDAR', '/calendars/work/nested'));

        self::assertSame(403, $response->status());
        self::assertStringContainsString(self::LOCATION_OK, (string) $response->body());
    }

    /**
     * **And not at any depth below one**, which is the half of §4.2(b) that is
     * easy to get wrong: §4.2(a) allows "collections that are not calendar
     * collections" inside a calendar, so there is a legal plain folder in
     * there — and a calendar may not go into it either.
     */
    public function testACalendarMayNotBeMadeInAFolderInsideACalendar(): void
    {
        $server = $this->server();
        $response = $server->handle(new Request('MKCALENDAR', '/calendars/work/attachments/nested'));

        self::assertSame(403, $response->status());
        self::assertStringContainsString(self::LOCATION_OK, (string) $response->body());
    }

    /**
     * A calendar somewhere ordinary is the whole point of the method, so the
     * guard has to let it through.
     */
    public function testACalendarSomewhereOrdinaryIsMade(): void
    {
        self::assertSame(201, $this->server()->handle(new Request('MKCALENDAR', '/calendars/soccer'))->status());
    }

    /**
     * §4.2: "Multiple calendar collections MAY be children of the same
     * collection." So a sibling is no reason to refuse.
     */
    public function testASecondCalendarBesideTheFirstIsMade(): void
    {
        $server = $this->server();

        self::assertSame(201, $server->handle(new Request('MKCALENDAR', '/calendars/soccer'))->status());
        self::assertSame(201, $server->handle(new Request('MKCALENDAR', '/calendars/hockey'))->status());
    }

    /**
     * **A plain collection inside a calendar is allowed** — §4.2(a) says so in
     * as many words — so the guard looks at what is being made, not only at
     * where.
     */
    public function testAPlainCollectionInsideACalendarIsNotRefused(): void
    {
        $plugin = new CalDav($this->server());

        $plugin->refuseNesting(new BeforeCreateCollection(
            'calendars/work/attachments',
            ['{DAV:}collection'],
        ));

        $this->expectNotToPerformAssertions();
    }

    /**
     * **§4.2(b) would be a formality without this half:** a client would make
     * the calendar beside and move it in. The same holds for a `COPY`.
     */
    public function testACalendarMayNotBeMovedIntoACalendar(): void
    {
        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage('§4.2');

        $this->transfer(new BeforeMove('calendars/holiday', 'calendars/work/holiday'));
    }

    public function testACalendarMayNotBeCopiedIntoACalendar(): void
    {
        $this->expectException(Forbidden::class);

        $this->transfer(new BeforeCopy('calendars/holiday', 'calendars/work/holiday'));
    }

    /**
     * **And not by moving something that merely holds one**, which is what "at
     * any depth" means: a plain folder with a calendar inside it may not come
     * to rest in a calendar either.
     */
    public function testAFolderHoldingACalendarMayNotBeMovedIntoACalendar(): void
    {
        $this->expectException(Forbidden::class);

        $this->transfer(new BeforeMove('spare', 'calendars/work/spare'));
    }

    /**
     * **And a folder that holds no calendar may be moved in**, which is the
     * other half of "at any depth": the walk has to come back empty-handed as
     * readily as it finds something, or §4.2(a)'s own permission — "collections
     * that are not calendar collections" — would be worth nothing.
     *
     * The folder holds one of each, a collection and a file, because the walk
     * has to say no to both: a file is not a calendar and carries nothing at
     * any depth.
     */
    public function testAFolderHoldingNoCalendarMayBeMovedIntoOne(): void
    {
        $this->transfer(new BeforeMove('drafts', 'calendars/work/drafts'));

        $this->expectNotToPerformAssertions();
    }
    /**
     * **And a file carries nothing at any depth**, so §4.2 has nothing to say
     * about one. What §5.3.2.1 has to say about it is checked further down,
     * which is why this case uses an object that is one.
     */
    public function testSomethingThatIsNoCalendarMayBeMovedIntoOne(): void
    {
        $this->transfer(new BeforeMove('spare/good.ics', 'calendars/work/good.ics'));

        $this->expectNotToPerformAssertions();
    }

    /**
     * And a calendar may be moved somewhere ordinary, or the rule would stop a
     * client tidying its own account.
     */
    public function testACalendarMayBeMovedSomewhereOrdinary(): void
    {
        $this->transfer(new BeforeMove('calendars/holiday', 'spare/holiday'));

        $this->expectNotToPerformAssertions();
    }

    /**
     * A source that is not there is a `404` the method raises; a plugin that
     * fell over looking for it would turn that into a `500`.
     */
    public function testASourceThatIsNotThereIsNotThisPluginsBusiness(): void
    {
        $this->transfer(new BeforeMove('calendars/nothing', 'calendars/work/nothing'));

        $this->expectNotToPerformAssertions();
    }

    /**
     * **The `DAV` header says nothing about CalDAV yet**, and that is the
     * decision this test records: §5.1 makes `calendar-access` a promise that
     * every MUST-level requirement of RFC 4791 is met, and §7.8's
     * `calendar-query` is one of them.
     */
    public function testItDoesNotYetAnnounceCalendarAccess(): void
    {
        $server = $this->server();
        $server->onMethod('OPTIONS', (new Options($server))(...));

        (new CalDav($server))->register();

        $response = $server->handle(new Request('OPTIONS', '/calendars'));

        self::assertStringNotContainsString('calendar-access', $response->headers()->first('DAV') ?? '');
    }

    /**
     * **§5.2.4: "In the absence of this property, the server MUST only accept
     * data with the media type 'text/calendar' and iCalendar version 2.0."**
     * So saying it says what silence already meant — and saying it is what
     * lets a client know without guessing.
     */
    public function testItSaysWhichCalendarDataItKeeps(): void
    {
        $answer = $this->answerFor('{urn:ietf:params:xml:ns:caldav}supported-calendar-data');

        self::assertInstanceOf(Element::class, $answer);
        self::assertSame(['{urn:ietf:params:xml:ns:caldav}calendar-data'], $answer->childNames());

        $data = $answer->children()[0] ?? self::fail('the property holds no calendar-data element');

        self::assertSame(['content-type' => 'text/calendar', 'version' => '2.0'], $data->attributes());
    }

    /**
     * §5.2.3's own example shape: one `comp` element per kind, with the kind
     * in a `name` attribute.
     */
    public function testItSaysWhichComponentsItTakes(): void
    {
        $answer = $this->answerFor('{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set');

        self::assertInstanceOf(Element::class, $answer);
        self::assertSame(
            ['VEVENT', 'VTODO', 'VJOURNAL', 'VFREEBUSY'],
            array_map(static fn (Element $comp): ?string => $comp->attribute('name'), $answer->children()),
        );
    }

    /**
     * **A calendar may take fewer kinds than the server does**, because §5.2.3
     * says so: "clients can initialize the value of this property when
     * creating a new calendar collection with MKCALENDAR". So where the
     * collection keeps a value, that value is the answer — a plugin that
     * answered its own would make the initialisation pointless.
     */
    public function testACalendarThatNarrowsTheComponentsAnswersForItself(): void
    {
        $narrowed = new Element('{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set');

        $narrowed->append(new Element('{urn:ietf:params:xml:ns:caldav}comp', ['name' => 'VTODO']));

        $store = new MemoryPropertyCollection('todo');

        $store->patchProperties(['{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => $narrowed]);

        $answer = $this->answerFor(
            '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set',
            Calendar::of($store),
        );

        self::assertSame($narrowed, $answer);
    }

    /**
     * The four limits the requirements fix, each published as §5.2.5 to §5.2.9
     * define it — R-CAL-08 asks for exactly that: "Sie MÜSSEN über die
     * entsprechenden Properties gemeldet werden."
     *
     * @param string $property The property the case is about
     * @param string $expected What this server publishes for it by default
     */
    #[DataProvider('limits')]
    public function testItPublishesEveryLimitItKeeps(string $property, string $expected): void
    {
        self::assertSame($expected, $this->answerFor('{urn:ietf:params:xml:ns:caldav}' . $property));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function limits(): iterable
    {
        yield 'max-resource-size' => ['max-resource-size', '10485760'];
        yield 'min-date-time' => ['min-date-time', '19000101T000000Z'];
        yield 'max-date-time' => ['max-date-time', '20991231T235959Z'];
        yield 'max-instances' => ['max-instances', '5000'];
        yield 'max-attendees-per-instance' => ['max-attendees-per-instance', '250'];
    }

    /**
     * R-CAL-08: "Die Werte MÜSSEN konfigurierbar sein, die genannten sind
     * Vorgaben." A deployment that sets its own publishes its own.
     */
    public function testTheLimitsItPublishesAreTheOnesItWasGiven(): void
    {
        $plugin = new CalDav($this->server(), limits: new Limits(maxResourceSize: 1024, maxInstances: 7));

        self::assertSame('1024', $this->answerFor('{urn:ietf:params:xml:ns:caldav}max-resource-size', null, $plugin));
        self::assertSame('7', $this->answerFor('{urn:ietf:params:xml:ns:caldav}max-instances', null, $plugin));
    }

    /**
     * **Two properties this deliberately does not answer.** §5.2.1 has
     * `calendar-description` carry "a description of the calendar collection
     * that is suitable for presentation to a user", and §5.2.2 has a client
     * set `calendar-timezone` through `MKCALENDAR` or a `PROPPATCH` — both are
     * values somebody writes, so a plugin answering them would make them
     * unwritable.
     *
     * @param string $property The property that is stored rather than computed
     */
    #[DataProvider('stored')]
    public function testItLeavesTheStoredPropertiesToTheStore(string $property): void
    {
        self::assertNull($this->answerFor('{urn:ietf:params:xml:ns:caldav}' . $property));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stored(): iterable
    {
        yield 'calendar-description' => ['calendar-description'];
        yield 'calendar-timezone' => ['calendar-timezone'];
    }

    /**
     * **And one it does not answer yet.** §7.5.1 makes
     * `CALDAV:supported-collation-set` owed "on any resource that supports a
     * report that does text matching" — and this server supports no such
     * report yet. Announcing the collations would be the `calendar-access`
     * mistake in miniature: §7.5.2 then obliges the server to honour any of
     * them a client names.
     */
    public function testItDoesNotYetAnnounceTheCollations(): void
    {
        self::assertNull($this->answerFor('{urn:ietf:params:xml:ns:caldav}supported-collation-set'));
    }

    /**
     * §6.2.1: "Identifies the URL of any WebDAV collections that contain
     * calendar collections owned by the associated principal resource", and
     * RFC 4918 §5.2 has a collection's URL end in a slash.
     */
    public function testItSaysWhereAPrincipalsCalendarsAre(): void
    {
        $answer = $this->answerFor(
            '{urn:ietf:params:xml:ns:caldav}calendar-home-set',
            $this->principal('bernard'),
        );

        self::assertInstanceOf(Element::class, $answer);
        self::assertSame(['/calendars/bernard/'], $this->hrefsIn($answer));
    }

    /**
     * **RFC 6638 §2.4.1's property is answered only where an application has
     * addresses to give**, and the memo's own last sentence is why: "If not
     * present, then the associated calendar user is not enabled for scheduling
     * on the server." This server does no scheduling, so a deployment that
     * says nothing is described exactly right — and one that does supply
     * addresses is saying something true about itself.
     */
    public function testItSaysAPrincipalsCalendarAddressesWhereItHasThem(): void
    {
        self::assertNull($this->answerFor(
            '{urn:ietf:params:xml:ns:caldav}calendar-user-address-set',
            $this->principal('bernard'),
        ));

        $plugin = new CalDav(
            $this->server(),
            calendarUserAddresses: static fn (string $principal): array => ['mailto:' . $principal . '@example.com'],
        );
        $answer = $this->answerFor(
            '{urn:ietf:params:xml:ns:caldav}calendar-user-address-set',
            $this->principal('bernard'),
            $plugin,
        );

        self::assertInstanceOf(Element::class, $answer);
        self::assertSame(['mailto:bernard@example.com'], $this->hrefsIn($answer));
    }

    /**
     * **Not one of the nine turns up in an `allprop` response.** Every memo
     * that defines one says the same sentence about it — §5.2.3 to §5.2.9 and
     * §6.2.1 of RFC 4791, and §2.4.1 of RFC 6638: it "SHOULD NOT be returned
     * by a PROPFIND DAV:allprop request". Nine properties nobody asked for,
     * on every calendar and every principal in the response, is what that
     * sentence exists to prevent.
     *
     * **And `DAV:include` is how a client asks for one anyway**, which is why
     * the same case checks both: RFC 4918 §9.1 gives it "to define the
     * properties to be returned in addition to those defined by
     * DAV:allprop", so a server that simply withheld them would leave the
     * client no way to get them in one request.
     *
     * @param string $property The property name the case is about
     * @param bool $principal Whether it hangs on a principal rather than a
     *                        calendar
     */
    #[DataProvider('everyPropertyItAnswers')]
    public function testNothingItAnswersTurnsUpUnderAllprop(string $property, bool $principal): void
    {
        $node = $principal ? $this->principal('bernard') : null;
        $plugin = new CalDav(
            $this->server(),
            calendarUserAddresses: static fn (string $name): array => ['mailto:' . $name . '@example.com'],
        );

        self::assertNull(
            $this->answerFor($property, $node, $plugin, PropFindForm::Everything, named: false),
            'An allprop request was answered.',
        );
        self::assertNotNull(
            $this->answerFor($property, $node, $plugin, PropFindForm::Everything),
            'A DAV:include was not answered.',
        );
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function everyPropertyItAnswers(): iterable
    {
        $namespace = '{urn:ietf:params:xml:ns:caldav}';

        foreach ([
            'supported-calendar-component-set',
            'supported-calendar-data',
            'max-resource-size',
            'min-date-time',
            'max-date-time',
            'max-instances',
            'max-attendees-per-instance',
        ] as $property) {
            yield $property => [$namespace . $property, false];
        }

        yield 'calendar-home-set' => [$namespace . 'calendar-home-set', true];
        yield 'calendar-user-address-set' => [$namespace . 'calendar-user-address-set', true];
    }

    /**
     * None of these belongs on anything but a calendar: a plugin that answered
     * them for every collection would tell a client a plain folder keeps
     * calendar objects.
     */
    public function testItSaysNothingAboutAnOrdinaryCollection(): void
    {
        $plain = new MemoryCollection('spare');

        foreach (
            [
                'supported-calendar-data',
                'supported-calendar-component-set',
                'max-resource-size',
                'min-date-time',
                'max-date-time',
                'max-instances',
                'max-attendees-per-instance',
            ] as $property
        ) {
            self::assertNull($this->answerFor('{urn:ietf:params:xml:ns:caldav}' . $property, $plain), $property);
        }
    }

    /**
     * **§5.2.3 to §5.2.9 each say "If defined, it MUST be protected"**, and a
     * protected property is one a `PROPPATCH` cannot change. The plugin
     * answers them, so it is the plugin that has to refuse the change — RFC
     * 4918 §15 makes `403` the answer.
     *
     * @param string $property The protected property the case is about
     */
    #[DataProvider('protectedProperties')]
    public function testAProtectedPropertyCannotBeChanged(string $property): void
    {
        $name = '{urn:ietf:params:xml:ns:caldav}' . $property;

        self::assertSame(403, $this->changing([$name => 'whatever'])[$name] ?? null);
    }

    /**
     * **And the refusal names the condition RFC 4918 gives it.** §9.2 of that
     * memo: "403 (Forbidden): The client has attempted to set a protected
     * property, such as DAV:getetag. If returning this error, the server
     * SHOULD use the precondition code 'cannot-modify-protected-property'
     * inside the response body." §16 then makes the placing a MUST, inside
     * the `propstat` of the property it is about.
     */
    public function testTheRefusalOfAProtectedPropertyNamesItsCondition(): void
    {
        $name = '{urn:ietf:params:xml:ns:caldav}max-resource-size';

        self::assertSame('{DAV:}cannot-modify-protected-property', $this->conditionOf([$name => '1024'])[$name] ?? null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function protectedProperties(): iterable
    {
        yield 'supported-calendar-data' => ['supported-calendar-data'];
        yield 'supported-calendar-component-set' => ['supported-calendar-component-set'];
        yield 'max-resource-size' => ['max-resource-size'];
        yield 'min-date-time' => ['min-date-time'];
        yield 'max-date-time' => ['max-date-time'];
        yield 'max-instances' => ['max-instances'];
        yield 'max-attendees-per-instance' => ['max-attendees-per-instance'];
    }

    /**
     * And `calendar-description` is not protected — §5.2.1 has a user write
     * it — so the plugin says nothing and the store answers.
     */
    public function testADescriptionMayBeChanged(): void
    {
        $name = '{urn:ietf:params:xml:ns:caldav}calendar-description';

        self::assertArrayNotHasKey($name, $this->changing([$name => 'Team events']));
    }

    /**
     * **The three properties Apple's clients expect are stored, not
     * answered** (R-CAL-03).
     *
     * `calendar-color` and `calendar-order` appear in no RFC — they come from
     * CalendarServer and from iCal, and what they hold is a colour somebody
     * picked and a position somebody dragged a calendar to. A server has
     * nothing of its own to say about either, so "supported" can only mean
     * stored and handed back: they are dead properties, and
     * {@see \DavServices\Plugin\DeadProperties} or a backend keeping its
     * own is what keeps them.
     *
     * What this plugin owes them is to stay out of the way — neither claiming
     * them on a `PROPFIND` nor refusing them on a `PROPPATCH`. The names are
     * written out in full because the namespace is the whole of the
     * convention: `http://calendarserver.org/ns/` for the first two and
     * `http://apple.com/ns/ical/` for the third, which is the one iCal reads.
     *
     * @param string $property The property name the case is about
     */
    #[DataProvider('whatApplesClientsExpect')]
    public function testAPropertyNoMemoDefinesIsLeftToTheStore(string $property): void
    {
        self::assertNull($this->answerFor($property));
        self::assertArrayNotHasKey($property, $this->changing([$property => '#711A76FF']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function whatApplesClientsExpect(): iterable
    {
        yield 'calendar-color' => ['{http://calendarserver.org/ns/}calendar-color'];
        yield 'calendar-order' => ['{http://calendarserver.org/ns/}calendar-order'];
        yield 'apple:calendar-order' => ['{http://apple.com/ns/ical/}calendar-order'];
    }

    /**
     * **A time zone a `PROPPATCH` brings is held to the same rule a
     * `MKCALENDAR` is.** §5.2.2 lets a client set the property either way, and
     * the value it may hold is the same either way — a server that checked
     * only the first would let a client store, at the second attempt, exactly
     * what it refused at the first.
     */
    public function testATimeZoneAPropPatchBringsIsChecked(): void
    {
        $name = '{urn:ietf:params:xml:ns:caldav}calendar-timezone';

        self::assertSame(409, $this->changing([$name => 'Europe/Berlin'])[$name] ?? null);
        self::assertArrayNotHasKey($name, $this->changing([$name => self::US_EASTERN]));
    }

    /**
     * **And what a `PROPPATCH` actually carries is an element.** RFC 4918 §9.2
     * wraps each value in `DAV:set`/`DAV:prop`, so {@see \DavServices\Dav\Method\PropPatch}
     * hands a listener the property element itself and the time zone is its
     * text. A guard that could read only a bare string would wave through
     * every real request; the bare string is what another listener or a store
     * may hand on, which is why both are read.
     */
    public function testATimeZoneArrivingAsAnElementIsCheckedToo(): void
    {
        $name = '{urn:ietf:params:xml:ns:caldav}calendar-timezone';

        $zone = new Element($name);
        $nonsense = new Element($name);

        $zone->appendText(self::US_EASTERN);
        $nonsense->appendText('Europe/Berlin');

        self::assertArrayNotHasKey($name, $this->changing([$name => $zone]));
        self::assertSame(409, $this->changing([$name => $nonsense])[$name] ?? null);
    }
    /**
     * **A time zone that is no time zone is a `409`, not a `403`**, and both
     * are in RFC 4918 §9.2's own list: "403 (Forbidden): The client has
     * attempted to set a protected property" against "409 (Conflict) - The
     * client has provided a value whose semantics are not appropriate for the
     * property." A `calendar-timezone` is a property a client may write
     * (§5.2.2), so what is wrong is the value — and §16 says which status
     * goes with which situation: "403 (Forbidden) if the request should not be
     * repeated because it will always fail, and 409 (Conflict) if it is
     * expected that the user might be able to resolve the conflict and
     * resubmit the request." A client that sends a good time zone next time
     * succeeds, which is the definition of the second.
     *
     * The condition is §5.2.10's, which exists for exactly this: "(CALDAV:
     * valid-calendar-data): The time zone specified in CALDAV:calendar-timezone
     * property MUST be a valid iCalendar object containing a single valid
     * VTIMEZONE component."
     */
    public function testARefusedTimeZoneNamesTheConditionOfParagraph5210(): void
    {
        $name = '{urn:ietf:params:xml:ns:caldav}calendar-timezone';
        $condition = '{urn:ietf:params:xml:ns:caldav}valid-calendar-data';

        self::assertSame($condition, $this->conditionOf([$name => 'Europe/Berlin'])[$name] ?? null);
    }

    /**
     * Removing it is not setting it to nonsense: §5.2.2 says "In the absence of
     * this property, the server MAY rely on the time zone of their choice", so
     * taking it away is a thing a client may do.
     */
    public function testATimeZoneMayBeRemoved(): void
    {
        $name = '{urn:ietf:params:xml:ns:caldav}calendar-timezone';

        self::assertArrayNotHasKey($name, $this->changing([$name => null]));
    }

    /**
     * **§5.3.2.1 applies its preconditions to three methods**, and scopes them
     * itself: "when a PUT operation of a calendar object resource into a
     * calendar collection occurs, or when a COPY or MOVE operation of a
     * calendar object resource into a calendar collection occurs". An object
     * every limit admits is stored.
     */
    public function testAnObjectEveryLimitAdmitsIsStored(): void
    {
        self::assertSame(201, $this->putting('/calendars/work/new.ics', self::meeting())->status());
    }

    /**
     * **A file outside a calendar is nobody's business here.** The sentence
     * above scopes the preconditions to a calendar collection — a plain WebDAV
     * collection takes whatever a client puts in it, and a CalDAV plugin that
     * validated everything would turn a WebDAV server into a calendar store.
     */
    public function testAFileOutsideACalendarIsNotChecked(): void
    {
        self::assertSame(201, $this->putting('/spare/anything.txt', 'This is a note.')->status());
    }

    /**
     * §5.3.2.1: "(CALDAV:valid-calendar-data): The resource submitted in the
     * PUT request […] MUST be valid data for the media type being specified
     * (i.e., MUST contain valid iCalendar data)."
     */
    public function testRefusesSomethingThatIsNoCalendar(): void
    {
        $response = $this->putting('/calendars/work/new.ics', 'This is a note, not a calendar.');

        self::assertSame(403, $response->status());
        self::assertStringContainsString('valid-calendar-data', (string) $response->body());
    }

    /**
     * Each limit, refused by its own name through a real request — because a
     * guard that was never wired would let every one of them through.
     *
     * @param string $precondition The precondition the case expects
     * @param int $status The status chosen for it
     * @param string $object The calendar object that breaks it
     */
    #[DataProvider('brokenLimits')]
    public function testEachLimitIsRefusedByName(string $precondition, int $status, string $object): void
    {
        $response = $this->putting('/calendars/work/new.ics', $object);

        self::assertSame($status, $response->status());
        self::assertStringContainsString($precondition, (string) $response->body());
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function brokenLimits(): iterable
    {
        yield 'supported-calendar-data' => [
            'supported-calendar-data',
            415,
            str_replace("VERSION:2.0\r\n", "VERSION:1.0\r\n", self::meeting()),
        ];

        yield 'supported-calendar-component' => [
            'supported-calendar-component',
            403,
            str_replace('VEVENT', 'VPOLL', self::meeting()),
        ];

        yield 'min-date-time' => [
            'min-date-time',
            403,
            str_replace('20260105T120000Z', '18991231T120000Z', self::meeting()),
        ];

        yield 'max-date-time' => [
            'max-date-time',
            403,
            str_replace('20260105T120000Z', '21000101T120000Z', self::meeting()),
        ];

        yield 'max-instances' => [
            'max-instances',
            403,
            str_replace("END:VEVENT\r\n", "RRULE:FREQ=DAILY\r\nEND:VEVENT\r\n", self::meeting()),
        ];
    }

    /**
     * The two limits a deployment is most likely to lower, lowered — so that
     * the figure the guard uses is the figure the property published.
     */
    public function testTheLimitsTheGuardKeepsAreTheOnesItWasGiven(): void
    {
        $tiny = $this->putting('/calendars/work/new.ics', self::meeting(), new Limits(maxResourceSize: 10));

        self::assertSame(413, $tiny->status());
        self::assertStringContainsString('max-resource-size', (string) $tiny->body());

        $crowded = str_replace(
            "END:VEVENT\r\n",
            "ATTENDEE:mailto:a@example.com\r\nATTENDEE:mailto:b@example.com\r\nEND:VEVENT\r\n",
            self::meeting(),
        );
        $refused = $this->putting('/calendars/work/new.ics', $crowded, new Limits(maxAttendees: 1));

        self::assertSame(403, $refused->status());
        self::assertStringContainsString('max-attendees-per-instance', (string) $refused->body());
    }

    /**
     * **A calendar that narrows its kinds narrows what it takes**, which is
     * the other half of §5.2.3's permission: the property a client set at
     * `MKCALENDAR` is the one the guard holds an object to.
     */
    public function testACalendarThatTakesOnlyToDosRefusesAnEvent(): void
    {
        $response = $this->putting('/calendars/todo/new.ics', self::meeting());

        self::assertSame(403, $response->status());
        self::assertStringContainsString('supported-calendar-component', (string) $response->body());
    }

    /**
     * §6.3.2 of RFC 6352 and §5.3.2 of this one agree: a `PUT` at a mapped URI
     * updates what is there, and that raises a different event than a creation
     * does. A guard wired to only one of the two would let a client replace a
     * good object with anything at all.
     */
    public function testReplacingAnObjectIsCheckedToo(): void
    {
        $response = $this->putting('/calendars/work/meeting.ics', 'This is a note, not a calendar.');

        self::assertSame(403, $response->status());
        self::assertStringContainsString('valid-calendar-data', (string) $response->body());
    }

    /**
     * §5.3.2.1 names `COPY` and `MOVE` in the same breath as `PUT`, so an
     * object cannot be reached into a calendar sideways.
     */
    public function testACopyIntoACalendarIsCheckedToo(): void
    {
        $response = $this->transferring('COPY', '/spare/note.txt', '/calendars/work/note.txt');

        self::assertSame(403, $response->status());
        self::assertStringContainsString('valid-calendar-data', (string) $response->body());
    }

    public function testAMoveIntoACalendarIsCheckedToo(): void
    {
        $response = $this->transferring('MOVE', '/spare/note.txt', '/calendars/work/note.txt');

        self::assertSame(403, $response->status());
        self::assertStringContainsString('valid-calendar-data', (string) $response->body());
    }

    /**
     * And an object that is fine goes through all three ways.
     */
    public function testAGoodObjectMayBeMovedIn(): void
    {
        self::assertSame(201, $this->transferring('MOVE', '/spare/good.ics', '/calendars/work/good.ics')->status());
    }

    /**
     * A collection being transferred is §4.2's business rather than §5.3.2.1's:
     * that section applies to "a calendar object resource", and a folder is not
     * one.
     */
    public function testAFolderMovedIntoACalendarIsNotCheckedAsAnObject(): void
    {
        self::assertSame(201, $this->transferring('MOVE', '/spare/folder', '/calendars/work/folder')->status());
    }

    /**
     * **A calendar that narrows its kinds takes the kinds it named** — all of
     * them, as it spelled them. §5.2.3 lets a client "initialize the value of
     * this property when creating a new calendar collection with MKCALENDAR",
     * and §3.1 of RFC 5545 makes the names case-insensitive, so a plugin that
     * read only the first `comp`, or only a shouted one, would refuse a
     * calendar its own kind.
     */
    public function testACalendarTakesEveryKindItNamedHoweverItSpelledIt(): void
    {
        $journal = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\n"
            . "BEGIN:VJOURNAL\r\nUID:3@example.com\r\nDTSTAMP:20260101T000000Z\r\nEND:VJOURNAL\r\n"
            . "END:VCALENDAR\r\n";

        self::assertSame(201, $this->putting('/calendars/jobs/note.ics', $journal)->status());
    }

    /**
     * And it still refuses a kind it did not name, which is the other half of
     * the same sentence.
     */
    public function testACalendarRefusesAKindItDidNotName(): void
    {
        $response = $this->putting('/calendars/jobs/meeting.ics', self::meeting());

        self::assertSame(403, $response->status());
        self::assertStringContainsString('supported-calendar-component', (string) $response->body());
    }

    /**
     * **A store that hands out a stream is read as readily as one that hands
     * out a string.** `IFile::get()` says "The content, as a stream wherever
     * the backend has one" — and on the `COPY`/`MOVE` path there is no event
     * to put a stream back into, which is the case this covers.
     */
    public function testAStreamedObjectIsReadOnTheWayIn(): void
    {
        self::assertSame(
            201,
            $this->transferring('MOVE', '/spare/streamed.ics', '/calendars/work/streamed.ics')->status(),
        );
    }

    /**
     * **The guard itself, on both events it listens to.** Each rule above is
     * driven through a real request, which proves the listener is wired; this
     * holds the listener to the two answers it has — nothing to say about a
     * file outside a calendar, and a named refusal for one inside.
     */
    public function testTheGuardHasNothingToSayAboutAFileOutsideACalendar(): void
    {
        (new CalDav($this->server()))->validateTheObject(
            new BeforeCreateFile('spare/anything.txt', 'This is a note, not a calendar.'),
        );

        $this->expectNotToPerformAssertions();
    }

    public function testTheGuardRefusesANonObjectInsideACalendar(): void
    {
        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage('valid-calendar-data');

        (new CalDav($this->server()))->validateTheObject(
            new BeforeWriteContent('calendars/work/meeting.ics', 'This is a note, not a calendar.'),
        );
    }

    /**
     * **Every mutation of a `PROPPATCH` is judged, not just the first.**
     * RFC 4918 §9.2 has the instructions carried out in the order they were
     * sent and every property accounted for, so a guard that stopped at the
     * first thing it recognised would wave the rest through.
     *
     * @param array<string, string> $mutations What the request asks for, in
     *                                         the order it asks
     */
    #[DataProvider('mutationsInTheWay')]
    public function testEveryMutationIsJudgedAndNotJustTheFirst(array $mutations): void
    {
        $timezone = '{urn:ietf:params:xml:ns:caldav}calendar-timezone';

        self::assertSame(409, $this->changing($mutations)[$timezone] ?? null);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function mutationsInTheWay(): iterable
    {
        $timezone = '{urn:ietf:params:xml:ns:caldav}calendar-timezone';

        yield 'behind a protected one' => [[
            '{urn:ietf:params:xml:ns:caldav}max-resource-size' => '1024',
            $timezone => 'Europe/Berlin',
        ]];

        yield 'behind one nobody here claims' => [[
            '{urn:ietf:params:xml:ns:caldav}calendar-description' => 'Team events',
            $timezone => 'Europe/Berlin',
        ]];
    }

    /**
     * **And a time zone written in lower case is a time zone.** §5.2.10 asks
     * for "a valid iCalendar object containing a single valid VTIMEZONE
     * component", and RFC 5234 §2.3 makes the grammar's own spelling
     * case-insensitive — refusing one would refuse what the memo allows.
     */
    public function testATimeZoneWrittenInLowerCaseIsAccepted(): void
    {
        $name = '{urn:ietf:params:xml:ns:caldav}calendar-timezone';
        $quiet = "begin:vcalendar\r\nversion:2.0\r\nprodid:-//x//y//EN\r\n"
            . "begin:vtimezone\r\ntzid:US-Eastern\r\nbegin:standard\r\ndtstart:19671029T020000\r\n"
            . "tzoffsetfrom:-0400\r\ntzoffsetto:-0500\r\nend:standard\r\nend:vtimezone\r\nend:vcalendar\r\n";

        self::assertArrayNotHasKey($name, $this->changing([$name => $quiet]));
    }

    /**
     * **A description keeps the language it was written in**, which §5.2.1
     * makes a MUST in as many words: "An xml:lang attribute indicating the
     * human language of the description SHOULD be set for this property by
     * clients or through server provisioning. **Servers MUST return any
     * xml:lang attribute if set for the property.**"
     *
     * The property is a dead one here — §5.2.1 says it "MAY be protected", and
     * a description nobody can write is no use to anybody — so keeping the
     * attribute is the storage's and the writer's doing rather than this
     * plugin's. It is asserted through a whole round trip because that is the
     * only place the MUST can be seen: a value flattened to its text on the
     * way in would come back out looking right and saying less.
     */
    public function testADescriptionKeepsTheLanguageItWasWrittenIn(): void
    {
        $server = new Server(new Tree($this->tree()));

        (new CalDav($server))->register();
        (new DeadProperties(new MemoryPropertyStorage()))->registerOn($server->events());

        $server->onMethod('PROPPATCH', (new PropPatch($server))(...));
        $server->onMethod('PROPFIND', (new PropFind($server))(...));

        $patched = $server->handle(new Request('PROPPATCH', '/calendars/work', body: new Body(
            '<D:propertyupdate xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav"><D:set><D:prop>'
            . '<C:calendar-description xml:lang="fr-CA">Calendrier de Mathilde</C:calendar-description>'
            . '</D:prop></D:set></D:propertyupdate>',
        )));

        self::assertSame(207, $patched->status());
        self::assertStringContainsString('200 OK', (string) $patched->body());

        $described = (string) $server->handle(new Request(
            'PROPFIND',
            '/calendars/work',
            new Headers(['Depth' => '0']),
            new Body(
                '<D:propfind xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">'
                . '<D:prop><C:calendar-description/></D:prop></D:propfind>',
            ),
        ))->body();

        self::assertStringContainsString('Calendrier de Mathilde', $described);
        self::assertStringContainsString('fr-CA', $described);
    }

    /**
     * **`register()` wires the guards, and this is what proves it.** Each rule
     * above is driven through the listener or a real request; without the
     * wiring, every one of them would pass and the server would keep none of
     * them.
     */
    public function testRegisterWiresTheGuards(): void
    {
        $server = new Server(new Tree($this->tree()));

        $server->onMethod('MKCALENDAR', (new MkCalendar($server))(...));
        $server->onMethod('MOVE', (new Move($server))(...));

        // **The request is aimed where a store would gladly make a calendar**,
        // which is the only shape that tells the plugin apart from the node
        // half: aimed straight at a calendar it is refused either way, because
        // a calendar is deliberately no extended collection.
        self::assertSame(
            201,
            $server->handle(new Request('MKCALENDAR', '/calendars/work/attachments/nested'))->status(),
            'unwired, the store makes it',
        );

        $guarded = new Server(new Tree($this->tree()));

        (new CalDav($guarded))->register();
        $guarded->onMethod('MKCALENDAR', (new MkCalendar($guarded))(...));
        $guarded->onMethod('MOVE', (new Move($guarded))(...));

        self::assertSame(
            403,
            $guarded->handle(new Request('MKCALENDAR', '/calendars/work/attachments/nested'))->status(),
        );
        self::assertSame(
            403,
            $guarded->handle(new Request(
                'MOVE',
                '/calendars/holiday',
                headers: new Headers(['Destination' => '/calendars/work/holiday']),
            ))->status(),
        );

        // All three seams, because each is its own `on()` call and each was
        // separately deletable until this looked.
        $guarded->onMethod('COPY', (new Copy($guarded))(...));

        self::assertSame(
            403,
            $guarded->handle(new Request(
                'COPY',
                '/calendars/holiday',
                headers: new Headers(['Destination' => '/calendars/work/copy']),
            ))->status(),
        );

        // And the two property seams, for the same reason: each `on()` call
        // was separately deletable with every property test still green,
        // because those call the listener rather than the server.
        $guarded->onMethod('PROPFIND', (new PropFind($guarded))(...));
        $guarded->onMethod('PROPPATCH', (new PropPatch($guarded))(...));

        $answered = (string) $guarded->handle(new Request(
            'PROPFIND',
            '/calendars/work',
            headers: new Headers(['Depth' => '0']),
            body: new Body(
                '<?xml version="1.0"?><D:propfind xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">'
                . '<D:prop><C:max-resource-size/></D:prop></D:propfind>',
            ),
        ))->body();

        self::assertStringContainsString('10485760', $answered, 'unwired, nobody answers the property');

        $refused = (string) $guarded->handle(new Request(
            'PROPPATCH',
            '/calendars/work',
            body: new Body(
                '<?xml version="1.0"?><D:propertyupdate xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">'
                . '<D:set><D:prop><C:max-resource-size>1024</C:max-resource-size></D:prop></D:set>'
                . '</D:propertyupdate>',
            ),
        ))->body();

        self::assertStringContainsString('cannot-modify-protected-property', $refused);
    }

    /**
     * One `VEVENT` within every limit, in a calendar object a client would
     * send.
     */
    private static function meeting(): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example Corp.//CalDAV Client//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:1@example.com\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260105T120000Z\r\n"
            . "SUMMARY:One-off Meeting\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    /**
     * What the plugin answers for one property on one node, or null where it
     * says nothing.
     */
    private function answerFor(
        string $property,
        ?INode $node = null,
        ?CalDav $plugin = null,
        PropFindForm $form = PropFindForm::Named,
        bool $named = true,
    ): Element|string|null {
        $node ??= Calendar::of(new MemoryCollection('work'));
        $plugin ??= new CalDav($this->server());
        $result = new PropFindResult('calendars/work', $form, $named ? [$property] : []);

        $plugin->describe(new PropertiesRequested($result, $node));

        return ($result->byStatus()[200] ?? [])[$property] ?? null;
    }

    /**
     * What the plugin itself decided about a `PROPPATCH`, as statuses by
     * property name.
     *
     * **What is still open is left out**, and that is the whole point of the
     * helper: `byStatus()` answers for every property of the request, `424` for
     * the ones nobody has settled, so a property this plugin says nothing about
     * would otherwise look refused. An absent key here means the store gets to
     * answer.
     *
     * @param array<string, Element|string|null> $mutations
     *
     * @return array<string, int>
     */
    private function changing(array $mutations): array
    {
        $result = new PropPatchResult('calendars/work', $mutations);

        (new CalDav($this->server()))->guardChangingProperties(new PropertiesChanging(
            $result,
            Calendar::of(new MemoryCollection('work')),
        ));

        $open = $result->open();
        $statuses = [];

        foreach ($result->byStatus() as $status => $properties) {
            foreach (array_keys($properties) as $name) {
                if (!array_key_exists($name, $open)) {
                    $statuses[$name] = $status;
                }
            }
        }

        return $statuses;
    }

    /**
     * The condition the plugin names for each property it refused.
     *
     * @param array<string, Element|string|null> $mutations
     *
     * @return array<string, string>
     */
    private function conditionOf(array $mutations): array
    {
        $result = new PropPatchResult('calendars/work', $mutations);

        (new CalDav($this->server()))->guardChangingProperties(new PropertiesChanging(
            $result,
            Calendar::of(new MemoryCollection('work')),
        ));

        return array_map(
            static fn (Element|string $condition): string => $condition instanceof Element
                ? $condition->name()
                : $condition,
            $result->errors(),
        );
    }

    /**
     * @return list<string>
     */
    private function hrefsIn(Element $property): array
    {
        return array_map(static fn (Element $href): string => $href->text(), $property->children());
    }

    private function principal(string $name): Principal
    {
        return new Principal(new PrincipalInfo($name, $name));
    }

    private function putting(string $path, string $body, ?Limits $limits = null): Response
    {
        $server = $this->serverTaking($limits);

        return $server->handle(new Request('PUT', $path, body: new Body($body)));
    }

    private function transferring(string $method, string $from, string $to): Response
    {
        $server = $this->serverTaking();

        return $server->handle(new Request($method, $from, headers: new Headers(['Destination' => $to])));
    }

    /**
     * A server with the plugin wired and the methods §5.3.2.1 names.
     */
    private function serverTaking(?Limits $limits = null): Server
    {
        $server = new Server(new Tree($this->tree()));

        (new CalDav($server, limits: $limits ?? new Limits()))->register();

        $server->onMethod('PUT', (new Put($server))(...));
        $server->onMethod('COPY', (new Copy($server))(...));
        $server->onMethod('MOVE', (new Move($server))(...));

        return $server;
    }

    private function transfer(BeforeCopy|BeforeMove $event): void
    {
        (new CalDav($this->server()))->refuseABadDestination($event);
    }

    private function server(): Server
    {
        $server = new Server(new Tree($this->tree()));

        (new CalDav($server))->register();

        $server->onMethod('MKCALENDAR', (new MkCalendar($server))(...));
        $server->onMethod('COPY', (new Copy($server))(...));
        $server->onMethod('MOVE', (new Move($server))(...));

        return $server;
    }

    /**
     * A home with one calendar that holds a plain folder, a calendar beside
     * it, something that is no calendar, and a folder with a calendar in it.
     */
    private function tree(): MemoryCollection
    {
        // §4.2(a) allows "collections that are not calendar collections"
        // inside a calendar, and a store able to make calendars is one of
        // those — which makes it the only place where this plugin is the
        // *only* thing standing between a client and §4.2(b). Aimed straight
        // at a calendar, a request is refused whether the plugin is there or
        // not, because a calendar is deliberately no extended collection.
        $work = (new MemoryCollection('work'))
            ->add(new MemoryCalendarHome('attachments'))
            ->add(new MemoryFile('meeting.ics', self::meeting()));

        // A calendar that narrowed its kinds at MKCALENDAR, which §5.2.3
        // licenses — the one shape that shows the guard reading the
        // collection's own property rather than the server's default.
        $narrowed = new Element('{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set');

        $narrowed->append(new Element('{urn:ietf:params:xml:ns:caldav}comp', ['name' => 'VTODO']));

        $todo = new MemoryPropertyCollection('todo');

        $todo->patchProperties(['{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => $narrowed]);

        $calendars = new MemoryCalendarHome('calendars');

        // Two kinds, written quietly: §5.2.3 lets a client name them and
        // RFC 5545 §3.1 lets it name them in any case it likes.
        $quiet = new Element('{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set');

        $quiet->append(new Element('{urn:ietf:params:xml:ns:caldav}comp', ['name' => 'vtodo']));
        $quiet->append(new Element('{urn:ietf:params:xml:ns:caldav}comp', ['name' => 'vjournal']));

        $jobs = new MemoryPropertyCollection('jobs');

        $jobs->patchProperties(['{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => $quiet]);

        $calendars->add(Calendar::of($work));
        $calendars->add(Calendar::of($todo));
        $calendars->add(Calendar::of($jobs));
        $calendars->add(Calendar::of(new MemoryCollection('holiday')));
        $calendars->add(new MemoryFile('loose.ics', 'BEGIN:VCALENDAR'));

        $spare = (new MemoryCollection('spare'))
            ->add(Calendar::of(new MemoryCollection('buried')))
            ->add(new MemoryFile('note.txt', 'This is a note.'))
            ->add(new MemoryFile('good.ics', self::meeting()))
            ->add(new StreamFile('streamed.ics', self::meeting()))
            ->add(new MemoryCollection('folder'));

        return (new MemoryCollection(''))
            ->add($calendars)
            ->add($spare)
            ->add((new MemoryCollection('drafts'))
                ->add(new MemoryCollection('notes'))
                ->add(new MemoryFile('sketch.ics', self::meeting())));
    }
}
