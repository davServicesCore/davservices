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

namespace DavServices\Plugin;

use Closure;
use DavServices\Acl\Principal;
use DavServices\CalDav\Calendar;
use DavServices\CalDav\CalendarObject;
use DavServices\CalDav\Limits;
use DavServices\CalDav\TimeZone;
use DavServices\Dav\Event\BeforeCopy;
use DavServices\Dav\Event\BeforeCreateCollection;
use DavServices\Dav\Event\BeforeCreateFile;
use DavServices\Dav\Event\BeforeMove;
use DavServices\Dav\Event\BeforeWriteContent;
use DavServices\Dav\Event\PropertiesChanging;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\ICollection;
use DavServices\Dav\IFile;
use DavServices\Dav\INode;
use DavServices\Dav\IProperties;
use DavServices\Dav\PropFindResult;
use DavServices\Dav\Server;
use DavServices\Exception\Forbidden;
use DavServices\Exception\NotFound;
use DavServices\Exception\PayloadTooLarge;
use DavServices\Exception\UnsupportedMediaType;
use DavServices\Uri\Path;
use DavServices\Xml\Element;

/**
 * What a client is told about calendars, and the rules §4.2 and §5.3.2.1 cannot
 * leave to a node (RFC 4791).
 *
 * Switched on in one line, like everything else here:
 *
 *     (new CalDav($server, 'calendars'))->register();
 *
 * ## The one rule about where a calendar may be
 *
 * §4.2 puts two restrictions on what a calendar may contain, and the second is
 * the one with teeth:
 *
 * - (a) "Calendar collections MUST only contain calendar object resources and
 *   collections that are **not** calendar collections";
 * - (b) "Collections contained in calendar collections MUST NOT contain
 *   calendar collections **at any depth**."
 *
 * So a plain collection inside a calendar is allowed — the memo says so in as
 * many words — and what is forbidden is a calendar, anywhere below one. That
 * is a question about where a path lies, and a node knows its name and nothing
 * about where it hangs: {@see Calendar} does the half it can see by not being
 * an {@see \DavServices\Dav\IExtendedCollection}, and this answers the other
 * half on both seams a calendar can arrive through — being made, and being
 * moved or copied in. Without the second the rule would be a formality: a
 * client would make the calendar beside and move it in.
 *
 * ## The properties, and why a plugin answers them
 *
 * **Seven are limits the server sets** rather than data a store holds —
 * §5.2.3 to §5.2.9 — and each says of itself "If defined, it MUST be
 * protected", which is why they are answered here and refused on a
 * `PROPPATCH`. R-CAL-08 makes every value configurable through
 * {@see Limits}, and R-CAL-09 has each one refuse with its own precondition
 * rather than fail: §5.2.5 to §5.2.9 each say "MUST result in an error, with
 * the CALDAV:… precondition (Section 5.3.2.1) being violated".
 *
 * **`CALDAV:supported-calendar-component-set` is the one a calendar may answer
 * for itself.** §5.2.3: "clients can initialize the value of this property
 * when creating a new calendar collection with MKCALENDAR" — so where the
 * collection keeps a value, that value is the answer, and the server's default
 * only fills in for a calendar that never narrowed it. A plugin that answered
 * its own regardless would make the initialisation pointless.
 *
 * **Two are about where something is.** `CALDAV:calendar-home-set` (§6.2.1)
 * and `CALDAV:calendar-user-address-set` (RFC 6638 §2.4.1) hang on a
 * principal, and a node knows its name and nothing about where it hangs — the
 * same reason {@see Principals} exists.
 *
 * ## Three properties this deliberately does not answer
 *
 * **`CALDAV:calendar-description`** (§5.2.1) carries "a description of the
 * calendar collection that is suitable for presentation to a user", and
 * **`CALDAV:calendar-timezone`** (§5.2.2) is set by a client through
 * `MKCALENDAR` or a `PROPPATCH`. Both are values somebody writes, so a plugin
 * that answered them would make them unwritable. They are stored — by the
 * backend through `IProperties`, or by {@see DeadProperties}.
 *
 * **`calendar-color` and `calendar-order`** (R-CAL-03) are the same kind of
 * thing from outside the RFCs: CalendarServer's
 * `{http://calendarserver.org/ns/}calendar-color` and `…}calendar-order`, and
 * iCal's `{http://apple.com/ns/ical/}calendar-order`, hold a colour somebody
 * picked and a position somebody dragged a calendar to. No memo defines them
 * and a server has nothing of its own to say about either, so supporting them
 * is storing them and handing them back — which is what this plugin leaving
 * them alone achieves.
 *
 * **`CALDAV:supported-collation-set`** (§7.5.1) is owed "on any resource that
 * supports a report that does text matching", and this server supports no such
 * report yet. Announcing three collations would be a promise §7.5.2 then
 * obliges it to keep for any of them a client names — the same reasoning that
 * kept the property out of {@see CardDav} until the collations existed.
 *
 * ## And the compliance class, still not announced
 *
 * §5.1 makes `calendar-access` a promise that "the server supports all MUST
 * level requirements specified in this document", and §7.8 makes
 * `CALDAV:calendar-query` one of them. A server announcing the class before
 * the reports exist would send every client that reads it straight into a
 * `403`, which is worse than a server a client does not try. The class arrives
 * with the chunk that completes the promise.
 */
final class CalDav
{
    private const NS = 'urn:ietf:params:xml:ns:caldav';

    /**
     * The precondition §5.3.1 defines for a calendar that would go where one
     * may not.
     */
    private const LOCATION_OK = '{' . self::NS . '}calendar-collection-location-ok';

    /**
     * §5.3.2.1: the body is valid data for its media type. §5.2.10 gives the
     * same condition a second job — the time zone a `PROPPATCH` sets "MUST be
     * a valid iCalendar object containing a single valid VTIMEZONE component".
     */
    private const VALID_CALENDAR_DATA = '{' . self::NS . '}valid-calendar-data';

    /**
     * RFC 4918 §9.2, for a `PROPPATCH` that goes for one of the seven: "If
     * returning this error, the server SHOULD use the precondition code
     * 'cannot-modify-protected-property' inside the response body."
     */
    private const CANNOT_MODIFY_PROTECTED = '{DAV:}cannot-modify-protected-property';

    private const COMPONENT_SET = '{' . self::NS . '}supported-calendar-component-set';

    private const COMP = '{' . self::NS . '}comp';

    private const CALENDAR_DATA = '{' . self::NS . '}calendar-data';

    private const TIMEZONE = '{' . self::NS . '}calendar-timezone';

    private const HOME_SET = '{' . self::NS . '}calendar-home-set';

    private const USER_ADDRESSES = '{' . self::NS . '}calendar-user-address-set';

    /**
     * The properties §5.2.3 to §5.2.9 each call protected, in the order the
     * memo defines them.
     *
     * @var list<string>
     */
    private const PROTECTED = [
        self::COMPONENT_SET,
        Limits::SUPPORTED_DATA,
        Limits::MAX_RESOURCE_SIZE,
        Limits::MIN_DATE_TIME,
        Limits::MAX_DATE_TIME,
        Limits::MAX_INSTANCES,
        Limits::MAX_ATTENDEES,
    ];

    private readonly Limits $limits;

    /** @var Closure(string): list<string> */
    private readonly Closure $calendarUserAddresses;

    /**
     * @param string $calendarsAt Where the calendar homes are mounted, as a
     *                            path inside the tree; nothing in this library
     *                            assumes one, which is why it is said here. A
     *                            principal's home set is that path with the
     *                            principal's name under it
     * @param Limits|null $limits What this server will keep (§5.2.3 to §5.2.9).
     *                            R-CAL-08 makes every value configurable and
     *                            the ones {@see Limits} names the defaults
     * @param (callable(string): list<string>)|null $calendarUserAddresses The
     *                                                                     calendar addresses of a principal, by name
     *                                                                     (RFC 6638 §2.4.1). Asked for only where
     *                                                                     somebody asked for the property, because an
     *                                                                     application's callable may well go looking in
     *                                                                     a directory. **Nothing is the right answer
     *                                                                     where there is nothing**: §2.4.1 reads an
     *                                                                     absent property as "the associated calendar
     *                                                                     user is not enabled for scheduling on the
     *                                                                     server", which is true of a server that does
     *                                                                     no scheduling
     */
    public function __construct(
        private readonly Server $server,
        private readonly string $calendarsAt = 'calendars',
        ?Limits $limits = null,
        ?callable $calendarUserAddresses = null,
    ) {
        $this->limits = $limits ?? new Limits();
        $this->calendarUserAddresses = $calendarUserAddresses === null
            ? static fn (string $principal): array => []
            : $calendarUserAddresses(...);
    }

    /**
     * Switches the rules on, at every seam they are about.
     */
    public function register(): void
    {
        $events = $this->server->events();

        $events->on(PropertiesRequested::class, $this->describe(...));
        $events->on(PropertiesChanging::class, $this->guardChangingProperties(...));
        $events->on(BeforeCreateCollection::class, $this->refuseNesting(...));
        $events->on(BeforeCopy::class, $this->refuseABadDestination(...));
        $events->on(BeforeMove::class, $this->refuseABadDestination(...));
        $events->on(BeforeCreateFile::class, $this->validateTheObject(...));
        $events->on(BeforeWriteContent::class, $this->validateTheObject(...));
    }

    /**
     * Answers whichever of the nine properties was asked for.
     */
    public function describe(PropertiesRequested $event): void
    {
        $node = $event->node();
        $result = $event->result();

        if ($node instanceof Calendar) {
            $this->describeTheCalendar($result, $node);
        }

        if ($node instanceof Principal) {
            $this->describeThePrincipal($result, $node);
        }
    }

    /**
     * §5.2.3 to §5.2.9: "If defined, it MUST be protected."
     *
     * A protected property is one a `PROPPATCH` cannot change, and since this
     * plugin is what answers them it is this plugin that has to refuse —
     * RFC 4918 §15 makes `403` the answer.
     *
     * **And a time zone a `PROPPATCH` brings is held to the rule a
     * `MKCALENDAR` is held to**, because §5.2.2 lets a client set it either
     * way and the value it may hold is the same either way. A server that
     * checked only the first would let a client store at the second attempt
     * exactly what it refused at the first.
     */
    public function guardChangingProperties(PropertiesChanging $event): void
    {
        $result = $event->result();

        foreach ($result->mutations() as $name => $value) {
            if (in_array($name, self::PROTECTED, true)) {
                $result->set($name, 403, self::CANNOT_MODIFY_PROTECTED);

                continue;
            }

            // Removing it is not setting it to nonsense: §5.2.2 has it that
            // "in the absence of this property, the server MAY rely on the
            // time zone of their choice", so taking it away is a thing a
            // client may do.
            if ($name !== self::TIMEZONE || $value === null) {
                continue;
            }

            if (TimeZone::in($value instanceof Element ? $value->text() : $value) === null) {
                $result->set($name, 409, self::VALID_CALENDAR_DATA);
            }
        }
    }

    /**
     * §4.2(b), for a calendar being made.
     *
     * **It looks at what is being made as well as at where**, because §4.2(a)
     * allows a plain collection inside a calendar: refusing every creation
     * below one would be stricter than the specification this cites.
     *
     * @throws Forbidden If a calendar would end up below a calendar
     */
    public function refuseNesting(BeforeCreateCollection $event): void
    {
        if (!in_array(Calendar::CALENDAR, $event->resourceTypes(), true)) {
            return;
        }

        if (!$this->isBelowACalendar($event->path())) {
            return;
        }

        throw new Forbidden(
            sprintf('No calendar may be made below the calendar above "%s" (RFC 4791 §4.2).', $event->path()),
            self::LOCATION_OK,
        );
    }

    /**
     * §4.2(b) and §5.3.2.1, for whatever is being moved or copied in.
     *
     * Two rules meet here, and they are about different things: a
     * **collection** may not carry a calendar into a calendar (§4.2(b)), and a
     * **calendar object** going into a calendar is held to every limit
     * (§5.3.2.1, which names `COPY` and `MOVE` beside `PUT`).
     *
     * **"At any depth" is why the first asks what the source *holds*** rather
     * than only what it is: a plain folder with a calendar inside it may not
     * come to rest in a calendar either.
     *
     * @throws Forbidden If a calendar would end up below a calendar, or an
     *                   object breaks a limit
     * @throws UnsupportedMediaType If an object is not the iCalendar version
     *                              this server keeps
     * @throws PayloadTooLarge If an object is larger than the limit
     */
    public function refuseABadDestination(BeforeCopy|BeforeMove $event): void
    {
        $moving = $this->nodeAt($event->from());

        if ($moving === null) {
            return;
        }

        if ($moving instanceof IFile) {
            $this->checkTheObjectGoingTo($event->to(), self::bytesIn($moving->get()));

            return;
        }

        // The walk is paid for only where it can matter: a destination that is
        // not below a calendar takes nothing with it that §4.2 forbids,
        // whatever the source holds.
        if (!$this->isBelowACalendar($event->to())) {
            return;
        }

        if (!$this->holdsACalendar($moving)) {
            return;
        }

        throw new Forbidden(
            sprintf('A calendar may not come to rest inside the calendar above "%s" (RFC 4791 §4.2).', $event->to()),
            self::LOCATION_OK,
        );
    }

    /**
     * §5.3.2.1's preconditions, for an object on its way into a calendar.
     *
     * **A file outside a calendar is nobody's business here.** The section
     * scopes itself to "a PUT operation of a calendar object resource into a
     * calendar collection" — a plain WebDAV collection takes whatever a client
     * puts in it, and a CalDAV plugin that validated everything would turn a
     * WebDAV server into a calendar store.
     *
     * @throws Forbidden If the body is no iCalendar, or breaks a limit
     * @throws UnsupportedMediaType If it is not the version this server keeps
     * @throws PayloadTooLarge If it is larger than the limit
     */
    public function validateTheObject(BeforeCreateFile|BeforeWriteContent $event): void
    {
        $this->checkTheObjectGoingTo($event->path(), self::bytesIn($event->content(), $event));
    }

    /**
     * Holds the bytes to every limit of the calendar they are going into.
     *
     * @throws Forbidden If the body is no iCalendar, or breaks a limit
     * @throws UnsupportedMediaType If it is not the version this server keeps
     * @throws PayloadTooLarge If it is larger than the limit
     */
    private function checkTheObjectGoingTo(string $path, string $bytes): void
    {
        $calendar = $this->calendarOver($path);

        if ($calendar === null) {
            return;
        }

        $object = CalendarObject::in($bytes) ?? throw new Forbidden(
            'A calendar object holds one iCalendar object: the CALDAV:valid-calendar-data precondition of RFC 4791 §5.3.2.1.',
            self::VALID_CALENDAR_DATA,
        );

        $this->limits->mustTake($bytes, $object, $this->kindsOf($calendar));
    }

    /**
     * The seven properties a calendar owes a client.
     */
    private function describeTheCalendar(PropFindResult $result, Calendar $node): void
    {
        if ($result->wasNamed(self::COMPONENT_SET)) {
            $result->set(self::COMPONENT_SET, self::keptBy($node, self::COMPONENT_SET) ?? $this->theComponents());
        }

        if ($result->wasNamed(Limits::SUPPORTED_DATA)) {
            $result->set(Limits::SUPPORTED_DATA, $this->theCalendarDataItKeeps());
        }

        foreach ($this->theLimitsItSets() as $name => $value) {
            if ($result->wasNamed($name)) {
                $result->set($name, $value);
            }
        }
    }

    /**
     * The four numbers and two dates §5.2.5 to §5.2.9 publish.
     *
     * @return array<string, string>
     */
    private function theLimitsItSets(): array
    {
        return [
            Limits::MAX_RESOURCE_SIZE => (string) $this->limits->maxResourceSize(),
            Limits::MIN_DATE_TIME => $this->limits->earliest(),
            Limits::MAX_DATE_TIME => $this->limits->latest(),
            Limits::MAX_INSTANCES => (string) $this->limits->maxInstances(),
            Limits::MAX_ATTENDEES => (string) $this->limits->maxAttendees(),
        ];
    }

    /**
     * §5.2.3's element: one `comp` per kind, with the kind in a `name`
     * attribute.
     */
    private function theComponents(): Element
    {
        $property = new Element(self::COMPONENT_SET);

        foreach ($this->limits->kinds() as $kind) {
            $property->append(new Element(self::COMP, ['name' => $kind]));
        }

        return $property;
    }

    /**
     * §5.2.4's element, which says what silence already meant: "In the absence
     * of this property, the server MUST only accept data with the media type
     * 'text/calendar' and iCalendar version 2.0."
     */
    private function theCalendarDataItKeeps(): Element
    {
        $property = new Element(Limits::SUPPORTED_DATA);

        $property->append(new Element(
            self::CALENDAR_DATA,
            ['content-type' => Limits::MEDIA_TYPE, 'version' => Limits::VERSION],
        ));

        return $property;
    }

    /**
     * The two properties a principal owes a client.
     */
    private function describeThePrincipal(PropFindResult $result, Principal $node): void
    {
        if ($result->wasNamed(self::HOME_SET)) {
            $result->set(self::HOME_SET, $this->holdingHrefs(
                self::HOME_SET,
                [$this->server->href(Path::join($this->calendarsAt, $node->name())) . '/'],
            ));
        }

        if (!$result->wasNamed(self::USER_ADDRESSES)) {
            return;
        }

        // Asked only where somebody asked for the answer: the application's
        // callable may well go looking in a directory, and a `PROPFIND` for
        // `DAV:displayname` has no business costing that.
        $addresses = ($this->calendarUserAddresses)($node->name());

        if ($addresses !== []) {
            $result->set(self::USER_ADDRESSES, $this->holdingHrefs(self::USER_ADDRESSES, $addresses));
        }
    }

    /**
     * One property holding an href per URL.
     *
     * **The URLs arrive ready**, unlike {@see CardDav}'s, and that is the
     * difference between the two properties: a home set is a path inside this
     * tree, which only the server can turn into a URL, while a calendar user
     * address is "mailto:bernard@example.com" or a principal's URL — something
     * the application knows and this server has no business rewriting (RFC
     * 6638 §2.4.1).
     *
     * **A collection is named with a trailing slash**, which RFC 4918 §5.2
     * asks for: "Wherever a server produces a URL referring to a collection,
     * the server SHOULD include the trailing slash."
     *
     * @param list<string> $urls
     */
    private function holdingHrefs(string $name, array $urls): Element
    {
        $property = new Element($name);

        foreach ($urls as $url) {
            $href = new Element('{DAV:}href');

            $href->appendText($url);
            $property->append($href);
        }

        return $property;
    }

    /**
     * The kinds of component this calendar takes.
     *
     * §5.2.3 lets a client narrow them per calendar at `MKCALENDAR`, so the
     * collection's own value wins and the server's default only fills in.
     *
     * @return list<string>
     */
    private function kindsOf(Calendar $calendar): array
    {
        $kept = self::keptBy($calendar, self::COMPONENT_SET);

        if (!$kept instanceof Element) {
            return $this->limits->kinds();
        }

        $kinds = [];

        foreach ($kept->children() as $comp) {
            $name = $comp->attribute('name');

            if ($name !== null) {
                $kinds[] = strtoupper($name);
            }
        }

        return $kinds;
    }

    /**
     * What a node keeps for a property of its own, or null where it keeps
     * none.
     */
    private static function keptBy(INode $node, string $property): Element|string|null
    {
        if (!$node instanceof IProperties) {
            return null;
        }

        return $node->properties([$property])[$property] ?? null;
    }

    /**
     * The calendar a path lies in, or null where there is none above it.
     *
     * §4.2(b) forbids a calendar inside a calendar, so the first one upwards
     * is the only one there can be.
     */
    private function calendarOver(string $path): ?Calendar
    {
        [$parent] = Path::split($path);

        while ($parent !== '') {
            $node = $this->nodeAt($parent);

            if ($node instanceof Calendar) {
                return $node;
            }

            [$parent] = Path::split($parent);
        }

        return null;
    }

    /**
     * Whether a path has a calendar anywhere above it.
     */
    private function isBelowACalendar(string $path): bool
    {
        return $this->calendarOver($path) !== null;
    }

    /**
     * Whether this node is a calendar or has one somewhere inside it.
     *
     * **The "somewhere inside" half is what makes §4.2(b) more than a
     * formality.** Without it a client would make a plain folder, make a
     * calendar in it, and move the folder into a calendar — and every rule
     * about nesting would have been kept at each step.
     */
    private function holdsACalendar(INode $node): bool
    {
        if ($node instanceof Calendar) {
            return true;
        }

        if (!$node instanceof ICollection) {
            return false;
        }

        foreach ($node->children() as $child) {
            if ($this->holdsACalendar($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The node at a path, or null where nothing is there.
     *
     * **A path that is not in the tree is no business of this plugin.** A
     * `MOVE` from a source that is not there is a `404` the method raises, and
     * a plugin that fell over looking for it would turn that into a `500`.
     */
    private function nodeAt(string $path): ?INode
    {
        try {
            return $this->server->tree()->node($path);
        } catch (NotFound) {
            return null;
        }
    }

    /**
     * Content as bytes, whichever of the two shapes it arrived in.
     *
     * **A stream that is read has to be put back.** `BeforeCreateFile` says
     * what a listener owes: "A listener that means to look at it has to put
     * back something the write can use […] reading the stream without
     * replacing it is a way to store an empty file." So where an event is
     * handed in, what was read is written back to it.
     *
     * @param resource|string $content
     */
    private static function bytesIn(mixed $content, BeforeCreateFile|BeforeWriteContent|null $event = null): string
    {
        if (is_string($content)) {
            return $content;
        }

        $bytes = (string) stream_get_contents($content);

        $event?->writeInstead($bytes);

        return $bytes;
    }
}
