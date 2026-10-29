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

use DavServices\CalDav\Calendar;
use DavServices\CalDav\IShareable;
use DavServices\CalDav\Sharee;
use DavServices\Dav\Event\PropertiesChanging;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\PropFindResult;
use DavServices\Dav\Server;
use DavServices\Xml\Element;

/**
 * What a client may read about a shared calendar (R-CAL-20).
 *
 *     $sharing = new Sharing($server);
 *
 *     $sharing->register();
 *
 * ## There is no RFC, and the source was fetched rather than remembered
 *
 * `caldav-sharing`, "Shared and Published Calendars in CalDAV", C. Daboo and
 * E. York, Apple, 19 September 2012 — a Calendar Server extension that never
 * became an IETF document. `docs/referenzen/download-extensions.sh` fetches
 * it, and it is the same document {@see Notifications} built §4 of.
 *
 * ## This is the first half of §5, and the cut is along the document's own seam
 *
 * §5.2 is headed "Additional Properties for Calendars", §5.3 "Sharer
 * **Actions**" and §5.4 "Sharee **Actions**". The properties are here; the
 * actions — §5.3.2's `POST` that changes a sharee list, §5.4.1's reply and
 * §5.4.2's refusal — are not, and neither is the sharee's own copy of a
 * shared calendar, which §5.4.1 says is "created by the invite acceptance"
 * and so cannot exist before there is an acceptance to create it.
 *
 * **The seam is not between the sharer and the sharee**, which is where the
 * plan had it: `CS:invite` is one property of both — §5.2.2 has it "on a
 * calendar collection resource that has been shared by the owner, **or** on
 * the calendar collection resources of the sharees" — and cutting there would
 * have split one property in two. §5.3.1 is no separate mechanism either: it
 * says the sharer "simply adds one or more sharees to the calendar collection
 * **as per Section 5.3.2**", so its MUST belongs with that `POST`.
 *
 * ## What it answers
 *
 * - **`CS:invite`** (§5.2.2), the list of people a calendar has been shared
 *   with and how it stands with each.
 * - **`CS:allowed-sharing-modes`** (§5.2.3), which of the two modes a calendar
 *   allows at all.
 *
 * Both are withheld from `allprop` — each section says so in the same words —
 * and both are protected, each with a MUST. §5.2.1's `CS:shared-owner` is
 * answered by {@see Calendar} instead, because `DAV:resourcetype` is a node's
 * to say.
 *
 * ## It asks the store behind the calendar
 *
 * §5.2 puts these properties on calendar collections, so a node that is no
 * {@see Calendar} is left alone. What a calendar is shared with lives in the
 * collection underneath it, which {@see Calendar::collection()} hands over —
 * "Read by a plugin that was handed a node and needs the store behind it, so
 * that it does not have to unwrap one by hand".
 *
 * ## What is not here, and why
 *
 * **No `calendarserver-sharing` token.** §5.1 asks it of "a server that
 * supports the features described in this document", and the feature a client
 * would act on first is §5.3.2's `POST`. A server announcing the token over
 * two readable properties would have a client offer sharing and then be told
 * the method is not allowed — worse than silence, because §5.1 is how a client
 * decides whether to offer it at all. {@see CalDav} withholds
 * `calendar-access` on the same ground and for a smaller gap.
 *
 * **No `CS:organizer`** inside `CS:invite`. §5.2.2 asks for it "on calendar
 * collection resources **of the sharees**", and those copies arrive with
 * §5.4.1.
 *
 * **Nothing per-user.** §5.5.3 makes a server "MUST support 'per-user' WebDAV
 * properties on shared calendar collections" and §5.5.4 says the same of
 * `TRANSP` and `VALARM` in the calendar data. Both are requirements on
 * storage: a per-user property is one "whose value can be set and retrieved
 * independently by each user", which only something that knows who is asking
 * and keeps a value per asker can do. A library that holds no data cannot keep
 * them, and saying so is the honest half of why the §5.1 token is withheld.
 */
final class Sharing
{
    /**
     * §5.2.2's property: whom the calendar has been shared with.
     */
    public const INVITE = '{http://calendarserver.org/ns/}invite';

    /**
     * §5.2.3's property: which modes of sharing this calendar allows.
     */
    public const ALLOWED_SHARING_MODES = '{http://calendarserver.org/ns/}allowed-sharing-modes';

    /**
     * The order §5.2.3's grammar puts the modes in.
     *
     *     <!ELEMENT allowed-sharing-modes (can-be-shared?, can-be-published?)>
     *
     * A sequence rather than a choice, so the document's order is kept here
     * rather than taken from a store — which also settles what to do with a
     * name that is in neither place: §5.2.3 admits these two elements and no
     * others, so nothing else is written.
     */
    private const MODES = [IShareable::CAN_BE_SHARED, IShareable::CAN_BE_PUBLISHED];

    private const USER = '{http://calendarserver.org/ns/}user';

    private const COMMON_NAME = '{http://calendarserver.org/ns/}common-name';

    private const ACCESS = '{http://calendarserver.org/ns/}access';

    private const SUMMARY = '{http://calendarserver.org/ns/}summary';

    private const HREF = '{DAV:}href';

    private const CANNOT_MODIFY_PROTECTED = '{DAV:}cannot-modify-protected-property';

    public function __construct(private readonly Server $server)
    {
    }

    /**
     * Switches the two properties on.
     *
     * **No method and no report**, which is what makes this half of §5 worth
     * having on its own: everything here is read with the `PROPFIND` a client
     * already sends.
     */
    public function register(): void
    {
        $events = $this->server->events();

        $events->on(PropertiesRequested::class, $this->describe(...));
        $events->on(PropertiesChanging::class, $this->guardChangingProperties(...));
    }

    /**
     * Answers whichever of the two was asked for by name, where the node is a
     * calendar whose store knows about sharing.
     */
    private function describe(PropertiesRequested $event): void
    {
        $node = $event->node();

        if (!$node instanceof Calendar) {
            return;
        }

        $store = $node->collection();

        if (!$store instanceof IShareable) {
            return;
        }

        self::nameTheModes($event->result(), $store);
        self::nameTheSharees($event->result(), $store);
    }

    /**
     * §5.2.3, which of the two modes this calendar allows.
     *
     * **A calendar that allows neither carries no property**, which is the
     * same sentence read twice: §5.2.3 has it "present on a calendar
     * collection resource that can been shared or published", and §5.5.2 has
     * the absence mean "sharing or publishing of that calendar is not
     * allowed". So the two agree, and a store that names nothing — or names
     * only something the grammar does not admit — says the same thing as a
     * store that never heard of sharing.
     *
     * Asked for by name only: "This property SHOULD NOT be returned by a
     * PROPFIND allprop request."
     */
    private static function nameTheModes(PropFindResult $result, IShareable $calendar): void
    {
        if (!$result->wasNamed(self::ALLOWED_SHARING_MODES)) {
            return;
        }

        $allowed = $calendar->allowedSharingModes();
        $property = new Element(self::ALLOWED_SHARING_MODES);

        foreach (self::MODES as $mode) {
            if (in_array($mode, $allowed, true)) {
                $property->append(new Element($mode));
            }
        }

        if ($property->children() === []) {
            return;
        }

        $result->set(self::ALLOWED_SHARING_MODES, $property);
    }

    /**
     * §5.2.2, the people the calendar has been shared with.
     *
     * **A calendar shared with nobody carries no property**, that section
     * having it "present on a calendar collection resource that has been
     * shared by the owner": one that may be shared and has not been is not one
     * that has been, and the property that says sharing is allowed is the
     * other one.
     *
     * Asked for by name only, for the reason §5.2.3 gives in the same words.
     */
    private static function nameTheSharees(PropFindResult $result, IShareable $calendar): void
    {
        if (!$result->wasNamed(self::INVITE)) {
            return;
        }

        $sharees = $calendar->sharees();

        if ($sharees === []) {
            return;
        }

        $property = new Element(self::INVITE);

        foreach ($sharees as $sharee) {
            $property->append(self::entryFor($sharee));
        }

        $result->set(self::INVITE, $property);
    }

    /**
     * One sharee, in the order §6.5 lays down.
     *
     *     <!ELEMENT user (DAV:href, common-name?, (invite-noresponse |
     *                     invite-accepted | invite-declined | invite-invalid),
     *                     access, summary?)>
     *
     * The two optional elements are left out where nothing said: §5.3.2 leaves
     * the common name to a server that "is able to match the sharee with a
     * known user", which is a judgement about who somebody is, and a library
     * over a store it knows nothing about has no way to make it.
     */
    private static function entryFor(Sharee $sharee): Element
    {
        $entry = new Element(self::USER);

        $entry->append(self::saying(self::HREF, $sharee->href()));

        $commonName = $sharee->commonName();

        if ($commonName !== null) {
            $entry->append(self::saying(self::COMMON_NAME, $commonName));
        }

        $entry->append(new Element($sharee->status()->value));

        $access = new Element(self::ACCESS);

        $access->append(new Element($sharee->access()->value));
        $entry->append($access);

        $summary = $sharee->summary();

        if ($summary !== null) {
            $entry->append(self::saying(self::SUMMARY, $summary));
        }

        return $entry;
    }

    /**
     * An element of this name with this text in it.
     */
    private static function saying(string $name, string $text): Element
    {
        $element = new Element($name);

        $element->appendText($text);

        return $element;
    }

    /**
     * §5.2.2's and §5.2.3's "This property MUST be protected".
     *
     * **Both for the same reason: the server works them out.** Who a calendar
     * is shared with changes through §5.3.2's `POST` and nowhere else, and
     * whether it may be shared at all is the store's to decide (§5.5.2). A
     * client that could write either would grant itself access by claiming to
     * have it.
     *
     * **Nothing else is touched.** §5.5.3 has a shared calendar carry
     * `DAV:displayname` and three more properties that every user with access
     * may write, so a plugin that refused a `PROPPATCH` because two names are
     * protected would refuse exactly what that section requires.
     */
    private function guardChangingProperties(PropertiesChanging $event): void
    {
        $result = $event->result();

        foreach (array_keys($result->mutations()) as $name) {
            if ($name === self::INVITE || $name === self::ALLOWED_SHARING_MODES) {
                $result->set($name, 403, self::CANNOT_MODIFY_PROTECTED);
            }
        }
    }
}
