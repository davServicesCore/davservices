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
use DavServices\CalDav\InviteNotification;
use DavServices\CalDav\InviteReply;
use DavServices\CalDav\InviteStatus;
use DavServices\CalDav\IShareable;
use DavServices\CalDav\ISharedWithMe;
use DavServices\CalDav\IShareeHome;
use DavServices\CalDav\IShareTarget;
use DavServices\CalDav\Sharee;
use DavServices\CalDav\SharingAccess;
use DavServices\Dav\Event\BeforeMethod;
use DavServices\Dav\Event\BeforeUnbind;
use DavServices\Dav\Event\CurrentPrincipalRequested;
use DavServices\Dav\Event\OptionsRequested;
use DavServices\Dav\Event\PropertiesChanging;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\ICollection;
use DavServices\Dav\INode;
use DavServices\Dav\Method\Post;
use DavServices\Dav\Property\Answers;
use DavServices\Dav\PropFindForm;
use DavServices\Dav\PropFindResult;
use DavServices\Dav\Server;
use DavServices\Exception\BadRequest;
use DavServices\Exception\DavException;
use DavServices\Exception\Forbidden;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Uri\MalformedPath;
use DavServices\Uri\Path;
use DavServices\Xml\Element;
use InvalidArgumentException;

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
     * §5.2.4's property: where the sharer's own copy of this calendar is.
     */
    public const SHARED_URL = '{http://calendarserver.org/ns/}shared-url';

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

    /**
     * §6.26's root element, which is what a share `POST` carries.
     */
    private const SHARE = '{http://calendarserver.org/ns/}share';

    /**
     * §5.1's token, which this server says once it can do all of §5.
     *
     * > A server that supports the features described in this document MUST
     * > include "calendarserver-sharing" as a field in the DAV response header
     * > from an OPTIONS request on any resource that supports these features.
     *
     * **Said only where the `POST` is wired**, because that is what makes the
     * features reachable: §5.2's properties on their own are something to read
     * and nothing to do, and a client that read the token and could not share
     * would have been told something untrue. The same standard
     * {@see CalDav} holds `calendar-access` to.
     */
    public const COMPLIANCE = 'calendarserver-sharing';

    /**
     * §6.29's root, which §5.4.1 makes the answer to an acceptance.
     */
    private const SHARED_AS = '{http://calendarserver.org/ns/}shared-as';

    private const HOST_URL = '{http://calendarserver.org/ns/}hosturl';

    private const IN_REPLY_TO = '{http://calendarserver.org/ns/}in-reply-to';

    /**
     * RFC 4791 §6.2.1's property, which is how this server says where a
     * principal's calendars are — and therefore which home §5.4.1 lets them
     * reply on.
     */
    private const CALENDAR_HOME_SET = '{urn:ietf:params:xml:ns:caldav}calendar-home-set';

    private const SET = '{http://calendarserver.org/ns/}set';

    private const REMOVE = '{http://calendarserver.org/ns/}remove';

    /**
     * RFC 3744 §5.1's property, which §5.5.5 makes the one thing that says
     * who the sharer is.
     */
    private const OWNER = '{DAV:}owner';

    /**
     * What §6.18's `CS:common-name` is filled from, where the sharer's
     * principal has one.
     */
    private const DISPLAY_NAME = '{DAV:}displayname';

    private const USER = '{http://calendarserver.org/ns/}user';

    private const ORGANIZER = '{http://calendarserver.org/ns/}organizer';

    /**
     * The three §5.2 says a client may not write.
     *
     * §5.2.2, §5.2.3 and §5.2.4 each say "This property MUST be protected",
     * and they are one list here rather than three names in a condition
     * because they are refused for one reason: the server works all three out.
     */
    private const PROTECTED = [self::INVITE, self::ALLOWED_SHARING_MODES, self::SHARED_URL];

    private const COMMON_NAME = '{http://calendarserver.org/ns/}common-name';

    private const ACCESS = '{http://calendarserver.org/ns/}access';

    private const SUMMARY = '{http://calendarserver.org/ns/}summary';

    private const HREF = '{DAV:}href';

    private const CANNOT_MODIFY_PROTECTED = '{DAV:}cannot-modify-protected-property';

    /**
     * What is being answered, where this plugin has seen a method begin.
     *
     * §5.4.2's correction hangs off {@see BeforeUnbind}, which carries a path
     * and nothing else, and the correction has to know who is asking.
     */
    private ?Request $request = null;

    /**
     * @param Notifications|null $notifications Where §5.3.2's notifications
     *                                          are deposited. Needed only for
     *                                          the `POST`, and then it is
     *                                          needed: see {@see register()}
     */
    public function __construct(
        private readonly Server $server,
        private readonly ?Notifications $notifications = null,
    ) {
    }

    /**
     * Switches the two properties on, and §5.3.2's `POST` where there is a
     * method for it to hang on.
     *
     * **The properties need no method at all**, which is what made them worth
     * having on their own: they are read with the `PROPFIND` a client already
     * sends. The `POST` is a different matter — RFC 4918 §9.5 defines no
     * behaviour for it, so a server owes none, and a deployment that wants
     * the properties without the change simply does not hand one over.
     *
     * **And a `POST` needs somewhere for its notifications to go.** §5.3.2
     * makes a notification part of every sharing change rather than an extra
     * — §3 gives it as the whole flow, "If the request succeeds, a
     * notification is sent to the sharee" — so a server that moved a sharee
     * list and told nobody would leave the sharee never knowing they had
     * been invited. That is said out loud here, at wiring time, rather than
     * discovered later from a message that never arrived.
     *
     * @param Post|null $post The `POST` method to hang §5.3.2's `CS:share`
     *                        on, where the application has one
     *
     * @throws InvalidArgumentException If a `POST` is to be answered and this
     *                                  plugin was given no notifications to
     *                                  deposit into
     */
    public function register(?Post $post = null): void
    {
        $events = $this->server->events();

        $events->on(PropertiesRequested::class, $this->describe(...));
        $events->on(PropertiesChanging::class, $this->guardChangingProperties(...));

        if ($post === null) {
            return;
        }

        $notifications = $this->notifications ?? throw new InvalidArgumentException(
            'A share POST owes a notification for every change it makes (§5.3.2), so it needs a notification plugin to deposit one with.',
        );

        $post->on(self::SHARE, fn (Request $request, Element $document): Response => $this->share(
            $request,
            $document,
            $notifications,
        ));

        $post->on(
            InviteReply::INVITE_REPLY,
            fn (Request $request, Element $document): Response => $this->reply($request, $document, $notifications),
        );

        // **The request is remembered**, because {@see BeforeUnbind} carries
        // only a path and §5.4.2's correction needs to know who is asking.
        // The same way {@see Acl} keeps the request it is guarding.
        $events->on(BeforeMethod::class, function (BeforeMethod $event): void {
            $this->request = $event->request();
        });

        $events->on(
            BeforeUnbind::class,
            function (BeforeUnbind $event) use ($notifications): void {
                $this->refuseByRemoving($event, $notifications);
            },
        );

        // §5.1's token comes with the `POST` and not before it: a server that
        // announced support over the properties alone would have a client
        // offer sharing and then be told the method is not allowed.
        $events->on(OptionsRequested::class, static function (OptionsRequested $event): void {
            $event->addCompliance(self::COMPLIANCE);
        });
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
        $result = $event->result();

        if ($store instanceof ISharedWithMe) {
            self::nameTheOriginal($result, $store);
        }

        if ($store instanceof IShareable) {
            self::nameTheModes($result, $store);
        }

        $this->nameTheSharees($result, $node, $store);
    }

    /**
     * §5.2.4, where the sharer's own copy of this calendar is.
     *
     * > This WebDAV property is present on a shared calendar collection
     * > resource that appears in a sharee's calendar home collection. Its
     * > content is a single DAV:href element whose value is the URL of the
     * > sharer's calendar being shared.
     *
     * Handed on as the store gave it. §5.3.2 shares between principals of one
     * server, so the URL is one this server made, and rebuilding it would be
     * guessing at a path the store already knows.
     *
     * Asked for by name only, §5.2.4 saying so in the same words as the other
     * two.
     */
    private static function nameTheOriginal(PropFindResult $result, ISharedWithMe $copy): void
    {
        if (!$result->wasNamed(self::SHARED_URL)) {
            return;
        }

        $result->set(self::SHARED_URL, self::holding(self::SHARED_URL, self::saying(self::HREF, $copy->sharedUrl())));
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
    private function nameTheSharees(PropFindResult $result, Calendar $node, ICollection $store): void
    {
        if (!$result->wasNamed(self::INVITE)) {
            return;
        }

        // §5.2.2's grammar is a sequence — `<!ELEMENT invite (organizer?,
        // user*)>` — so the order here is the document's and not a choice.
        $property = new Element(self::INVITE);

        if ($store instanceof ISharedWithMe) {
            $organizer = $this->organizerOf($result->path(), $node);

            if ($organizer !== null) {
                $property->append($organizer);
            }
        }

        if ($store instanceof IShareable) {
            foreach ($store->sharees() as $sharee) {
                $property->append(self::entryFor($sharee));
            }
        }

        if ($property->children() === []) {
            return;
        }

        $result->set(self::INVITE, $property);
    }

    /**
     * §5.2.2's `CS:organizer`, which a sharee's copy carries and the sharer's
     * own does not.
     *
     * > In addition, servers SHOULD include a CS:organizer XML element on
     * > calendar collection resources of the sharees to provide clients with a
     * > fast way to determine who the sharer is.
     *
     * **And the sharer is already named on the copy**, so nothing is stored
     * twice: §5.5.5 puts `DAV:owner` on a shared calendar as "a reference to a
     * principal-URL of the sharer (owner)" and says what a client does with it
     * there — "Clients can use this value to determine what the allowed
     * ORGANIZER iCalendar property values are", which is a client working in
     * the sharee's copy.
     *
     * Null where that names nobody. §6.18 makes the `DAV:href` the one
     * required child, so there is nothing to write without one, and §5.2.2's
     * organizer is a SHOULD that a server which cannot tell who the sharer is
     * may not keep.
     *
     * **Asking the node for its owner raises `PropertiesRequested` again**,
     * and this is not the recursion it looks like: the inner question names
     * `DAV:owner` and nothing else, so {@see self::nameTheSharees()} finds
     * `CS:invite` unasked for and does nothing. The round trip is what makes
     * a dead property store and access control answer, which reading the node
     * directly would step over.
     */
    private function organizerOf(string $path, Calendar $node): ?Element
    {
        $href = $this->ownerOf($path, $node);

        if ($href === null) {
            return null;
        }

        $organizer = self::holding(self::ORGANIZER, self::saying(self::HREF, $href));
        $name = $this->nameOf($href);

        if ($name !== null) {
            $organizer->append(self::saying(self::COMMON_NAME, $name));
        }

        return $organizer;
    }

    /**
     * What the principal at this address is called, where this server has one
     * and it is named.
     */
    private function nameOf(string $href): ?string
    {
        try {
            $path = $this->server->pathOf($href);
        } catch (MalformedPath) {
            return null;
        }

        if (!$this->server->tree()->exists($path)) {
            return null;
        }

        $node = $this->server->tree()->node($path);
        $name = Answers::about($this->server->events(), $path, $node, PropFindForm::Named, [self::DISPLAY_NAME])
            ->byStatus()[200][self::DISPLAY_NAME] ?? null;

        return is_string($name) ? $name : null;
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
     * An element of this name with that element inside it.
     */
    private static function holding(string $name, Element $child): Element
    {
        $element = new Element($name);

        $element->append($child);

        return $element;
    }

    /**
     * §5.3.2's `POST`: the sharer changes who the calendar is shared with.
     *
     * > The sharer of a shared calendar is able to manipulate the sharee list
     * > by issuing a POST request targeted at the calendar collection
     * > resource.
     *
     * Four things are asked before anything changes, and the order is the
     * order of the sentence: is this a calendar, is its list one a client may
     * change, who is asking, and are they the sharer.
     *
     * **The whole document is read before any of it is carried out.** §5.3.2
     * says nothing about a document whose third instruction is bad, and the
     * only answer that cannot surprise a sharer is "nothing happened": a
     * server that applied the first two would leave a calendar shared with
     * somebody the sharer never saw confirmed.
     *
     * @throws Forbidden If this is no calendar, if its list is not a client's
     *                   to change, or if whoever is asking is not the sharer
     * @throws BadRequest If the document asks for nothing, or for something
     *                    §6.27 and §6.28 do not allow
     */
    private function share(Request $request, Element $document, Notifications $notifications): Response
    {
        $path = $this->server->path($request);
        $node = $this->server->tree()->node($path);

        if (!$node instanceof Calendar) {
            throw new Forbidden('A CS:share belongs on a calendar collection (§5.3.2), and this is none.');
        }

        $store = $node->collection();

        if (!$store instanceof IShareTarget) {
            throw new Forbidden('This calendar does not let a client change its sharee list (§5.5.2).');
        }

        // §6.15 makes `CS:organizer` a required child of the notification
        // every change owes, so a server that does not know who is asking
        // could not write one — quite apart from §5.3.2 giving the list to
        // the sharer alone.
        $asking = $this->server->events()->emit(new CurrentPrincipalRequested($request))->principal();

        if ($asking === null) {
            throw new Forbidden('§5.3.2 gives the sharee list to the sharer, and nobody here has said who they are.');
        }

        // **A calendar that names no owner fails this comparison as well**,
        // which is §5.5.5's "The DAV:owner WebDAV property MUST be present on
        // a shared calendar" met by arithmetic rather than by a second guard:
        // no owner is nobody to be.
        if ($this->sharerOf($path, $node, $request) !== $asking) {
            throw new Forbidden('Only the principal that DAV:owner names may change the sharee list (§5.5.5).');
        }

        $before = $store->sharees();

        foreach (self::instructionsIn($document, $before) as $instruction) {
            $instruction($store);
        }

        $this->tellWhoeverItConcerns(
            $notifications,
            $request,
            $this->server->hrefOf($path, $node),
            $asking,
            $before,
            $store->sharees(),
        );

        // §5.3.2.1 and §5.3.2.2 both answer `200 OK` with `Cache-Control:
        // no-cache` and no body. There is nothing to say that the sharee list
        // does not say, and a client that wants it reads `CS:invite`.
        return (new Response(200))->withHeader('Cache-Control', 'no-cache');
    }

    /**
     * §5.4.1's `POST`: the sharee accepts or declines an invitation.
     *
     * > When a sharee is invited to a shared calendar they can accept or
     * > decline the invite by issuing a POST request to the sharee's calendar
     * > home collection resource.
     *
     * **Everything in the document comes from a client** — an address, a
     * calendar and an identifier — so each of the three is checked against
     * something this server knows before anything happens:
     *
     * - the target is the asker's own calendar home, as this server's own
     *   `CALDAV:calendar-home-set` names it;
     * - the `DAV:href` names the asker;
     * - the `CS:hosturl` names a calendar here whose sharee list can be
     *   corrected, and that list holds an invitation for the asker;
     * - and §6.23's `CS:in-reply-to` matches the identifier that invitation
     *   was sent under.
     *
     * The last one is free, §6.16's identifier being derived from the calendar
     * and the sharee rather than remembered. Without the others, anybody could
     * accept any calendar into any home.
     *
     * @throws Forbidden If the reply is not the asker's to make, or names
     *                   something this server cannot answer for
     * @throws BadRequest If the document is missing anything §6.22 requires
     */
    private function reply(Request $request, Element $document, Notifications $notifications): Response
    {
        $path = $this->server->path($request);
        $home = $this->server->tree()->node($path);

        $asking = $this->server->events()->emit(new CurrentPrincipalRequested($request))->principal();

        if ($asking === null) {
            throw new Forbidden('§5.4.1 is a sharee replying, and nobody here has said who they are.');
        }

        if (!$this->isTheCalendarHomeOf($asking, $path)) {
            throw new Forbidden('§5.4.1 puts a reply on the sharee own calendar home, and this is not theirs.');
        }

        $sharee = self::addressIn($document, '§6.22 begins with a DAV:href, and this reply names nobody.');
        $answer = self::answerIn($document);
        $wanted = self::addressIn(
            $document->child(self::HOST_URL) ?? throw new BadRequest('§6.22 wants a CS:hosturl, and this reply has none.'),
            '§6.17 holds a DAV:href, and this CS:hosturl has none.',
        );
        $inReplyTo = self::identifierIn($document);
        $summary = self::textIn($document, self::SUMMARY);

        $who = $this->principalAt($sharee, $request);

        if ($who === null || $who[0] !== $asking) {
            throw new Forbidden('§5.4.1 has the sharee reply for themselves, and this reply names somebody else.');
        }

        $shared = $this->calendarAt($wanted, $request);

        if ($shared === null) {
            throw new Forbidden('A reply names a calendar of this server whose sharee list can be corrected (§5.4.2).');
        }

        [$sharedPath, $sharedNode, $store] = $shared;

        $where = $this->server->hrefOf($sharedPath, $sharedNode);
        $invitation = $this->invitationFor($asking, $store->sharees(), $request);

        if ($invitation === null) {
            throw new Forbidden('There is no invitation on that calendar for whoever is asking.');
        }

        if (!hash_equals(InviteNotification::identifierOf($where, $invitation->href()), $inReplyTo)) {
            throw new Forbidden('§6.23 names the invitation being answered, and this is not one this server sent.');
        }

        // **Before the answer is recorded**, because a home that cannot take a
        // shared calendar must leave the invitation as it was rather than
        // half-answered.
        $copy = $answer === InviteStatus::Accepted ? $this->copyInto($home, $path, $where) : null;

        $store->shareWith(new Sharee(
            $invitation->href(),
            $invitation->access(),
            $answer,
            $invitation->commonName(),
            $summary ?? $invitation->summary(),
        ));

        $this->tellTheSharer($notifications, $request, $sharedPath, $sharedNode, new InviteReply(
            $invitation->href(),
            $answer,
            $where,
            $inReplyTo,
            $summary,
        ));

        return $copy === null
            ? (new Response(200))->withHeader('Cache-Control', 'no-cache')
            : (new Response(200, body: $this->server->writer()->write(
                self::holding(self::SHARED_AS, self::saying(self::HREF, $copy)),
            )))
                ->withHeader('Content-Type', 'application/xml; charset=utf-8')
                ->withHeader('Cache-Control', 'no-cache');
    }

    /**
     * Makes the sharee's copy and says where it is, as §5.4.1's answer needs
     * it.
     *
     * > That root element contains a single DAV:href element whose content is
     * > the URI of the shared calendar in the sharee's calendar home created
     * > by the invite acceptance.
     *
     * @throws Forbidden If this home takes no shared calendars
     */
    private function copyInto(INode $home, string $path, string $hostUrl): string
    {
        if (!$home instanceof IShareeHome) {
            throw new Forbidden('This calendar home does not take shared calendars into itself (§5.4.1).');
        }

        $copy = Path::join($path, $home->acceptShareOf($hostUrl));

        return $this->server->hrefOf($copy, $this->server->tree()->node($copy));
    }

    /**
     * Whether this path is a calendar home that principal may reply on.
     *
     * **Asked of the server's own answer** rather than worked out from a
     * layout: RFC 4791 §6.2.1's `CALDAV:calendar-home-set` is what this
     * server tells a client its calendars are under, so it is also what says
     * which home is theirs. A deployment that puts homes somewhere unusual is
     * right either way, and a server with no such property answers nobody's
     * reply — which is the truth about a server that has no calendar homes.
     */
    private function isTheCalendarHomeOf(string $principal, string $path): bool
    {
        if (!$this->server->tree()->exists($principal)) {
            return false;
        }

        $homes = Answers::about(
            $this->server->events(),
            $principal,
            $this->server->tree()->node($principal),
            PropFindForm::Named,
            [self::CALENDAR_HOME_SET],
        )->byStatus()[200][self::CALENDAR_HOME_SET] ?? null;

        if (!$homes instanceof Element) {
            return false;
        }

        foreach ($homes->children() as $href) {
            // Whatever answers that property is another plugin, so what is in
            // it is read rather than assumed.
            if ($href->name() !== self::HREF) {
                continue;
            }

            try {
                if ($this->server->pathOf(trim($href->text())) === $path) {
                    return true;
                }
            } catch (MalformedPath) {
                continue;
            }
        }

        return false;
    }

    /**
     * The calendar this address names, where it is one of this server's and
     * its sharee list is one that can be corrected.
     *
     * @return array{string, Calendar, IShareTarget}|null The path, the node
     *                                                    and the store
     */
    private function calendarAt(string $href, Request $request): ?array
    {
        try {
            $path = $this->server->pathOfUrl($href, $request);
        } catch (DavException | MalformedPath) {
            return null;
        }

        if (!$this->server->tree()->exists($path)) {
            return null;
        }

        $node = $this->server->tree()->node($path);

        if (!$node instanceof Calendar) {
            return null;
        }

        $store = $node->collection();

        return $store instanceof IShareTarget ? [$path, $node, $store] : null;
    }

    /**
     * The invitation on this list that was sent to this principal, if any.
     *
     * Matched by resolving each address rather than by comparing text,
     * because §5.3.2 lets a sharer name a sharee three different ways. An
     * entry naming somebody this server does not host resolves to nobody and
     * matches nobody, which is §5.3.2's own position on such a sharee: "how
     * invitations are sent or access enabled is out of scope".
     *
     * @param list<Sharee> $sharees
     */
    private function invitationFor(string $principal, array $sharees, Request $request): ?Sharee
    {
        foreach ($sharees as $sharee) {
            $who = $this->principalAt($sharee->href(), $request);

            if ($who !== null && $who[0] === $principal) {
                return $sharee;
            }
        }

        return null;
    }

    /**
     * §5.4.2: a sharee takes their copy away, and the sharer's list says so.
     *
     * > When such a request is received the server MUST remove the shared
     * > calendar from the sharee's calendar home and automatically update the
     * > sharee's status in the sharer's calendar's CS:invite property.
     *
     * **Declined**, §6.9's being the only state that fits somebody who no
     * longer has it — and §5.4.2 being the other way a sharee refuses.
     *
     * Done **before** the unbind, because the copy is what says where the
     * sharer's calendar is, and after the unbind there is nothing to ask.
     *
     * **The sharee is taken to be whoever is asking**, and that is a limit
     * rather than a choice: nothing on the copy says whose it is. §5.4.2
     * describes the sharee removing their own copy, which is what this
     * answers; an administrator clearing somebody else's leaves the sharer's
     * list to be put right by hand.
     *
     * The sharer is told in the same words a decline is told in. §5.4.2 does
     * not ask for that and §5.4.1 asks for it of "the change in the sharee
     * state" — which is this change, reached the other way.
     */
    private function refuseByRemoving(BeforeUnbind $event, Notifications $notifications): void
    {
        $path = $event->path();
        $node = $this->server->tree()->node($path);

        if (!$node instanceof Calendar || !$node->collection() instanceof ISharedWithMe) {
            return;
        }

        // Nothing to correct where no request is behind the removal: an
        // application taking a node away itself is nobody refusing anything,
        // and §5.4.2 is about a sharee's `DELETE`.
        $request = $this->request;

        if ($request === null) {
            return;
        }

        $asking = $this->server->events()->emit(new CurrentPrincipalRequested($request))->principal();

        if ($asking === null) {
            return;
        }

        $shared = $this->calendarAt($node->collection()->sharedUrl(), $request);

        if ($shared === null) {
            return;
        }

        [$sharedPath, $sharedNode, $store] = $shared;

        $invitation = $this->invitationFor($asking, $store->sharees(), $request);

        if ($invitation === null) {
            return;
        }

        $where = $this->server->hrefOf($sharedPath, $sharedNode);

        $store->shareWith(new Sharee(
            $invitation->href(),
            $invitation->access(),
            InviteStatus::Declined,
            $invitation->commonName(),
            $invitation->summary(),
        ));

        $this->tellTheSharer($notifications, $request, $sharedPath, $sharedNode, new InviteReply(
            $invitation->href(),
            InviteStatus::Declined,
            $where,
            InviteNotification::identifierOf($where, $invitation->href()),
            $invitation->summary(),
        ));
    }

    /**
     * §5.4.1's SHOULD: the sharer is told how the sharee answered.
     *
     * > When the sharee replies to an invite, the server SHOULD send a
     * > notification to the sharer to update them on the change in the sharee
     * > state.
     *
     * The sharer is whoever §5.5.5's `DAV:owner` names on the calendar, and
     * nothing is sent where that is nobody this server hosts — the same reach
     * §5.3.2's notifications have.
     */
    private function tellTheSharer(
        Notifications $notifications,
        Request $request,
        string $path,
        Calendar $calendar,
        InviteReply $reply,
    ): void {
        $owner = $this->ownerOf($path, $calendar);

        if ($owner === null) {
            return;
        }

        $sharer = $this->principalAt($owner, $request);

        if ($sharer === null) {
            return;
        }

        [, $principal] = $sharer;

        $notifications->deposit($principal, $reply->inReplyTo(), $reply->toElement());
    }

    /**
     * Which of §6.22's two the document says.
     *
     * @throws BadRequest If it names neither, or both
     */
    private static function answerIn(Element $document): InviteStatus
    {
        $accepted = $document->child(InviteStatus::Accepted->value) !== null;
        $declined = $document->child(InviteStatus::Declined->value) !== null;

        // One condition for both ways of failing a choice of one, as §6.27's
        // access level is read.
        if ($accepted === $declined) {
            throw new BadRequest(
                '§6.22 lets a reply name one of CS:invite-accepted and CS:invite-declined, not neither and not both.',
            );
        }

        return $accepted ? InviteStatus::Accepted : InviteStatus::Declined;
    }

    /**
     * The invitation a reply answers (§6.23).
     *
     * @throws BadRequest If it names none
     */
    private static function identifierIn(Element $document): string
    {
        $identifier = trim($document->child(self::IN_REPLY_TO)?->text() ?? '');

        if ($identifier === '') {
            throw new BadRequest('§6.22 wants a CS:in-reply-to, and this reply names no invitation.');
        }

        return $identifier;
    }

    /**
     * §5.3.2's MUST: everyone whose standing changed is told, and nobody
     * else.
     *
     * > In each case the server MUST send a notification message to any
     * > sharees whose status is changed (added, modified or removed) […] The
     * > server SHOULD NOT send notification messages to sharees whose status
     * > is unchanged.
     *
     * **Worked out from the list before and the list after**, rather than
     * from what the request asked for. A store that declined a change quietly
     * therefore causes no message about something that is not so, and one
     * that made a different change from the one asked for sends a message
     * about what it really did.
     *
     * @param string $where The sharer's calendar, as §6.17 wants it
     * @param string $asking The sharer, as a path inside this tree
     * @param list<Sharee> $before
     * @param list<Sharee> $after
     */
    private function tellWhoeverItConcerns(
        Notifications $notifications,
        Request $request,
        string $where,
        string $asking,
        array $before,
        array $after,
    ): void {
        [$sharer, $sharerIsCalled] = $this->sharerIn($asking);

        foreach ($after as $sharee) {
            $was = self::onTheList($sharee->href(), $before);

            if ($was !== null && self::nothingAMessageWouldSayDiffers($was, $sharee)) {
                continue;
            }

            $this->tell(
                $notifications,
                $request,
                $sharee->href(),
                InviteNotification::aboutAChange($sharee, $where, $sharer, $sharerIsCalled),
            );
        }

        foreach ($before as $sharee) {
            if (self::onTheList($sharee->href(), $after) !== null) {
                continue;
            }

            $this->tell(
                $notifications,
                $request,
                $sharee->href(),
                InviteNotification::aboutARemoval($sharee, $where, $sharer, $sharerIsCalled),
            );
        }
    }

    /**
     * Leaves one message where its sharee will find it, where there is a
     * message to leave and somebody on this server to leave it for.
     */
    private function tell(
        Notifications $notifications,
        Request $request,
        string $href,
        ?InviteNotification $notice,
    ): void {
        if ($notice === null) {
            return;
        }

        $sharee = $this->principalAt($href, $request);

        if ($sharee === null) {
            return;
        }

        [, $principal] = $sharee;

        $notifications->deposit($principal, $notice->uid(), $notice->toElement());
    }

    /**
     * **Whether a message about this change would say anything new.**
     *
     * What counts as a change is what §6.15 carries: the state, the access
     * and the summary. A new common name is therefore not one — the
     * notification has no `CS:common-name` for the sharee at all, so a
     * message about it would be the same message again, and §5.3.2's "SHOULD
     * NOT send notification messages to sharees whose status is unchanged"
     * covers it.
     */
    private static function nothingAMessageWouldSayDiffers(Sharee $was, Sharee $is): bool
    {
        return $was->status() === $is->status()
            && $was->access() === $is->access()
            && $was->summary() === $is->summary();
    }

    /**
     * The entry for this address in a list, or null where it is on none.
     *
     * @param list<Sharee> $sharees
     */
    private static function onTheList(string $href, array $sharees): ?Sharee
    {
        foreach ($sharees as $sharee) {
            if ($sharee->href() === $href) {
                return $sharee;
            }
        }

        return null;
    }

    /**
     * The sharer as §6.18 wants them: an address, and a name where anything
     * knows one.
     *
     * A principal that is not in the tree is still named by address — this
     * server has just taken its word for who is asking, and a dangling
     * `DAV:owner` is a deployment's problem rather than a reason to refuse a
     * change that has already been made.
     *
     * @return array{string, string|null}
     */
    private function sharerIn(string $asking): array
    {
        if (!$this->server->tree()->exists($asking)) {
            return [$this->server->href($asking), null];
        }

        $node = $this->server->tree()->node($asking);
        $name = Answers::about($this->server->events(), $asking, $node, PropFindForm::Named, [self::DISPLAY_NAME])
            ->byStatus()[200][self::DISPLAY_NAME] ?? null;

        return [$this->server->hrefOf($asking, $node), is_string($name) ? $name : null];
    }

    /**
     * The principal an address names on this server and the path it is at,
     * or null where it names none.
     *
     * **Both, from one resolution.** A caller that had the node and wanted
     * the path would have to resolve the address again, and two resolutions
     * of one address are two chances to disagree about whose it is.
     *
     * @return array{string, Principal}|null
     *
     * §5.3.2 lets the address be one of three things, and only the first can
     * be delivered to: "either a principal-URL for a sharee hosted on the
     * same server, a calendar user address or email address. In the case of
     * the later two, the sharee might not be a user on the same server -
     * though in that case **how invitations are sent or access enabled is out
     * of scope** for this specification."
     *
     * A scheme of its own is what tells the second and third from the first.
     * `http` and `https` are let through because a principal-URL may be
     * absolute, and {@see Server::pathOfUrl()} is what then decides whether
     * it is this server's.
     */
    private function principalAt(string $href, Request $request): ?array
    {
        $scheme = parse_url($href, PHP_URL_SCHEME);

        if ($scheme !== null && !in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        try {
            $path = $this->server->pathOfUrl($href, $request);
        } catch (DavException | MalformedPath) {
            return null;
        }

        if (!$this->server->tree()->exists($path)) {
            return null;
        }

        $node = $this->server->tree()->node($path);

        return $node instanceof Principal ? [$path, $node] : null;
    }

    /**
     * Who §5.5.5 says the sharer is, as a path inside this tree.
     *
     * Read through {@see Answers} rather than from the node, because that is
     * the one way this server finds out what it says about a property: it is
     * where a dead property store is reached, and where access control gets
     * the first word. A plugin that asked the node directly would read a
     * value this server would not hand over.
     *
     * Null wherever that leads nowhere — no property, no `DAV:href` in it, or
     * an address on another server. Each of those is a calendar whose sharer
     * this server cannot name, and the caller treats them alike.
     */
    private function sharerOf(string $path, INode $node, Request $request): ?string
    {
        $href = $this->ownerOf($path, $node);

        if ($href === null) {
            return null;
        }

        try {
            return $this->server->pathOfUrl($href, $request);
        } catch (DavException | MalformedPath) {
            return null;
        }
    }

    /**
     * The address `DAV:owner` names on this node, or null where it names
     * nobody.
     *
     * Read through {@see Answers} for the reason {@see self::sharerOf()}
     * gives, and trimmed because a `DAV:href` that arrives pretty-printed
     * carries its indentation with it.
     */
    private function ownerOf(string $path, INode $node): ?string
    {
        $owner = Answers::about($this->server->events(), $path, $node, PropFindForm::Named, [self::OWNER])
            ->byStatus()[200][self::OWNER] ?? null;

        if (!$owner instanceof Element) {
            return null;
        }

        $href = trim($owner->child(self::HREF)?->text() ?? '');

        return $href === '' ? null : $href;
    }

    /**
     * What the document asks for, as a list of things to do.
     *
     * **A list rather than a tally of changes already made**, so that the
     * whole of it is understood before any of it happens — and in the order
     * the instructions arrive, because §6.26's `(set | remove)*` is a
     * sequence and two instructions about one sharee can only mean what their
     * order says.
     *
     * An element that is neither is passed over: reading tolerates the
     * unknown here as it does everywhere else in this library, and a document
     * that asks for nothing this server understands is caught by the count
     * rather than by guessing what was meant.
     *
     * @param list<Sharee> $already Who the calendar is shared with now, which
     *                              is what says whether a `CS:set` is an
     *                              addition or an update
     *
     * @throws BadRequest If nothing in it is an instruction
     *
     * @return list<Closure(IShareTarget): void>
     */
    private static function instructionsIn(Element $document, array $already): array
    {
        $instructions = [];

        foreach ($document->children() as $element) {
            if ($element->name() === self::SET) {
                $instructions[] = self::toShare($element, $already);
            }

            if ($element->name() === self::REMOVE) {
                $instructions[] = self::toStopSharing($element);
            }
        }

        if ($instructions === []) {
            throw new BadRequest(
                '§5.3.2: a CS:share has to contain one or more CS:set or CS:remove elements.',
            );
        }

        return $instructions;
    }

    /**
     * One `CS:set` (§6.27), read and ready to be carried out.
     *
     * The state is not the client's to say — §6.27's grammar carries none —
     * so a sharee who is already on the list arrives with the state they are
     * already in (§6.8: "the sharing invite is an update for one they
     * previously accepted"), and a new one with §6.6's "has never replied".
     *
     * @param list<Sharee> $already
     *
     * @return Closure(IShareTarget): void
     */
    private static function toShare(Element $set, array $already): Closure
    {
        $href = self::addressIn($set, '§6.27 begins with a DAV:href, and this CS:set names nobody.');

        $sharee = new Sharee(
            $href,
            self::accessIn($set),
            self::howTheyAnswered($href, $already),
            self::textIn($set, self::COMMON_NAME),
            self::textIn($set, self::SUMMARY),
        );

        return static function (IShareTarget $target) use ($sharee): void {
            $target->shareWith($sharee);
        };
    }

    /**
     * One `CS:remove` (§6.28), which carries an address and nothing else.
     *
     * @return Closure(IShareTarget): void
     */
    private static function toStopSharing(Element $remove): Closure
    {
        $href = self::addressIn($remove, '§6.28 begins with a DAV:href, and this CS:remove names nobody.');

        return static function (IShareTarget $target) use ($href): void {
            $target->stopSharingWith($href);
        };
    }

    /**
     * The address an element is about, in whichever of §5.3.2's three forms
     * it arrived.
     *
     * @param string $why What to say where there is none, every grammar that
     *                    carries an address having its own section to cite
     *
     * @throws BadRequest If there is none
     */
    private static function addressIn(Element $instruction, string $why): string
    {
        $href = trim($instruction->child(self::HREF)?->text() ?? '');

        if ($href === '') {
            throw new BadRequest($why);
        }

        return $href;
    }

    /**
     * Which of §5.5.1's two levels a `CS:set` grants.
     *
     * @throws BadRequest If it names neither, or both
     */
    private static function accessIn(Element $set): SharingAccess
    {
        $read = $set->child(SharingAccess::Read->value) !== null;
        $readWrite = $set->child(SharingAccess::ReadWrite->value) !== null;

        // One condition for both ways of failing a choice of one: neither
        // names a level, and both name two. **Guessing is the thing not to
        // do here** — of the two, one grants more than may have been meant.
        if ($read === $readWrite) {
            throw new BadRequest('§6.27 lets a CS:set name one of CS:read and CS:read-write, not neither and not both.');
        }

        return $read ? SharingAccess::Read : SharingAccess::ReadWrite;
    }

    /**
     * How this sharee answered already, or §6.6's "never replied" where they
     * are nobody the calendar knows yet.
     *
     * @param list<Sharee> $already
     */
    private static function howTheyAnswered(string $href, array $already): InviteStatus
    {
        foreach ($already as $sharee) {
            if ($sharee->href() === $href) {
                return $sharee->status();
            }
        }

        return InviteStatus::NoResponse;
    }

    /**
     * What an optional child of a `CS:set` says, or null where it is absent.
     */
    private static function textIn(Element $set, string $name): ?string
    {
        return $set->child($name)?->text();
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
            if (in_array($name, self::PROTECTED, true)) {
                $result->set($name, 403, self::CANNOT_MODIFY_PROTECTED);
            }
        }
    }
}
