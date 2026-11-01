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

use DavServices\Acl\Principal;
use DavServices\CalDav\Schedule\Inbox;
use DavServices\CalDav\Schedule\Outbox;
use DavServices\Dav\Event\BeforeUnbind;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\ICollection;
use DavServices\Dav\INode;
use DavServices\Dav\IResourceType;
use DavServices\Dav\Property\Answers;
use DavServices\Dav\PropFindForm;
use DavServices\Dav\PropFindResult;
use DavServices\Dav\Server;
use DavServices\Exception\Forbidden;
use DavServices\Uri\MalformedPath;
use DavServices\Uri\Path;
use DavServices\Xml\Element;

/**
 * Where a calendar user's scheduling collections are (R-CAL-30, R-CAL-31).
 *
 *     $scheduling = new Scheduling($server);
 *
 *     $scheduling->register();
 *
 * RFC 6638, "CalDAV Scheduling", June 2012 — and after three chunks on a
 * vendor document with no RFC, this one has conformance clauses, named
 * preconditions and an IANA registration again.
 *
 * ## It answers two properties and guards two collections
 *
 * - **`CALDAV:schedule-inbox-URL`** (§2.2.1) and
 *   **`CALDAV:schedule-outbox-URL`** (§2.1.1) on a principal, each holding a
 *   `DAV:href` to the collection of that name.
 * - and §2's "clients have no way to create or delete them", which is a
 *   `DELETE` refused.
 *
 * Both properties are withheld from `allprop`, both sections asking for that
 * in the same words.
 *
 * ## The collections are looked for, not named
 *
 * §2.1.1's description ends: "If not present, then the associated calendar
 * user is not enabled for the sending of scheduling messages on the server."
 * The same sentence `caldav-sharing` §4.1.1 has about a notification
 * collection, and it settles the same question: the property is answered only
 * where the collection really is, because one naming a collection that is not
 * there would send a client to a `404` for ever.
 *
 * **And RFC 6638 says nowhere what they are called.** §2 says only that
 * creating them "is the server's responsibility", so there is no name to
 * assume — this plugin reads the principal's `CALDAV:calendar-home-set`, which
 * is where this server itself says that user's calendars are, and looks there
 * for a child whose `DAV:resourcetype` carries the one it wants. No
 * configuration, and nothing to keep in step with a deployment's layout.
 *
 * A deployment that keeps them somewhere else answers the property from its
 * own store — §2.1.1 makes it "MAY be protected", so a stored value is
 * conforming — and this plugin finding nothing leaves that answer standing.
 *
 * **The cost is a listing of the home** for each of the two properties, and
 * only when a client names one: `wasNamed()` is what keeps that off every
 * `PROPFIND`.
 *
 * ## Four things this deliberately does not do
 *
 * **No `calendar-auto-schedule` token.** §2 puts it on "any resource that
 * supports any scheduling operations, properties, privileges, or methods", and
 * on those words these two properties would be enough. It is withheld anyway,
 * because of what the token is *read for*: a client that sees it stops sending
 * invitations itself and leaves that to the server, and §3.2.1.1's "the server
 * MUST send scheduling messages" is not here yet. Nothing would be delivered
 * and nobody would be told — worse than silence by a wider margin than any
 * other promise in this library, because what goes missing is somebody's
 * invitation rather than a feature. The same judgement {@see CalDav} makes
 * about `calendar-access`.
 *
 * **No `CALDAV:calendar-user-address-set`**, although §2.4.1 is this memo's
 * too. It is answered by {@see CalDav}, and it stays there: it is the map from
 * a calendar user address to a principal, which §5.4.1 of `caldav-sharing`
 * needs as much as scheduling does. It belongs to the plugin every CalDAV
 * deployment has rather than to the one only a scheduling deployment has.
 *
 * **No `CALDAV:calendar-user-type`** (§2.4.2). It is a MAY, its value is "the
 * same as the iCalendar CUTYPE property parameter", and §2.4 says of both of
 * its properties that a server "MAY allow them to be written by appropriate
 * users" — so it is a value somebody sets, and a plugin that answered it would
 * make it unwritable. The same reading {@see CalDav} makes of
 * `CALDAV:calendar-description`.
 *
 * **No guard for §2.1's and §2.2's "MUST NOT be a child (at any depth) of a
 * calendar collection resource".** There is no request to guard: a client
 * cannot make one of these collections at all, because nothing registers
 * their resource types with {@see \DavServices\Dav\Method\MkCol}, which
 * refuses an unknown one by name with `DAV:valid-resourcetype`. Where an
 * application puts them is the application's to get right, and that sentence
 * is what it has to go on.
 */
final class Scheduling
{
    /**
     * §2.2.1's property, which says where a principal's Inbox is.
     */
    public const INBOX_URL = '{urn:ietf:params:xml:ns:caldav}schedule-inbox-URL';

    /**
     * §2.1.1's property, which says where their Outbox is.
     */
    public const OUTBOX_URL = '{urn:ietf:params:xml:ns:caldav}schedule-outbox-URL';

    /**
     * RFC 4791 §6.2.1's property, which is where this server says a
     * principal's calendars are — and therefore where its scheduling
     * collections are looked for.
     */
    private const CALENDAR_HOME_SET = '{urn:ietf:params:xml:ns:caldav}calendar-home-set';

    private const HREF = '{DAV:}href';

    public function __construct(private readonly Server $server)
    {
    }

    /**
     * Switches the two properties on, and the guard on the two collections.
     *
     * **No method and no report.** §2.3 extends two reports and §5 adds a
     * `POST`, and neither is here: both are about what is *in* an Inbox or
     * what an Outbox is asked to do, and nothing delivers into one yet.
     */
    public function register(): void
    {
        $events = $this->server->events();

        $events->on(PropertiesRequested::class, $this->describe(...));
        $events->on(BeforeUnbind::class, $this->guardTheCollections(...));
    }

    /**
     * Answers whichever of the two was asked for by name.
     */
    private function describe(PropertiesRequested $event): void
    {
        $node = $event->node();

        if (!$node instanceof Principal) {
            return;
        }

        $result = $event->result();

        $this->nameTheCollection($result, $node, self::INBOX_URL, Inbox::INBOX);
        $this->nameTheCollection($result, $node, self::OUTBOX_URL, Outbox::OUTBOX);
    }

    /**
     * §2.1.1 and §2.2.1, where the principal has such a collection.
     *
     * **Asked for by name only**, both sections' PROPFIND behaviour being
     * "This property SHOULD NOT be returned by a PROPFIND DAV:allprop
     * request" — the same sentence {@see PropFindResult::wasNamed()} exists
     * for, and the thing that keeps the home from being listed on every
     * request.
     *
     * The collection is named with its trailing slash, which RFC 4918 §5.2
     * asks for wherever a server produces the URL of a collection.
     */
    private function nameTheCollection(
        PropFindResult $result,
        Principal $node,
        string $property,
        string $kind,
    ): void {
        if (!$result->wasNamed($property)) {
            return;
        }

        $where = $this->find($kind, $result->path(), $node);

        if ($where === null) {
            return;
        }

        $url = new Element($property);
        $href = new Element(self::HREF);

        $href->appendText($this->server->href($where) . '/');
        $url->append($href);

        $result->set($property, $url);
    }

    /**
     * The path of the collection of this kind in the principal's calendar
     * home, or null where there is none.
     *
     * Each home is asked in the order the property names them, and the first
     * such collection is the answer: RFC 4791 §6.2.1 allows more than one home
     * — "There can be multiple" — while §2.1.1 and §2.2.1 each hold a single
     * `DAV:href`, so the question is which one, and the first is the only
     * answer this server can give without a rule the memo does not state.
     */
    private function find(string $kind, string $principal, Principal $node): ?string
    {
        $homes = Answers::about(
            $this->server->events(),
            $principal,
            $node,
            PropFindForm::Named,
            [self::CALENDAR_HOME_SET],
        )->byStatus()[200][self::CALENDAR_HOME_SET] ?? null;

        if (!$homes instanceof Element) {
            return null;
        }

        foreach ($homes->children() as $href) {
            // Whatever answers that property is another plugin, so what is in
            // it is read rather than assumed.
            if ($href->name() !== self::HREF) {
                continue;
            }

            $found = $this->findIn($kind, trim($href->text()));

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * The first child of this home whose resource type carries that kind.
     */
    private function findIn(string $kind, string $home): ?string
    {
        try {
            $path = $this->server->pathOf($home);
        } catch (MalformedPath) {
            return null;
        }

        if (!$this->server->tree()->exists($path)) {
            return null;
        }

        $node = $this->server->tree()->node($path);

        if (!$node instanceof ICollection) {
            return null;
        }

        foreach ($node->children() as $child) {
            if (self::isOfKind($child, $kind)) {
                return Path::join($path, $child->name());
            }
        }

        return null;
    }

    /**
     * §2: a client may not delete either collection.
     *
     * > It is the server's responsibility to create these collection
     * > resources, and clients have no way to create or delete them.
     *
     * **A user who may empty their own calendar home would otherwise be able
     * to take their own Inbox away** and stop every invitation ever sent to
     * them, with one request and no way back. Creating one needs no guard:
     * nothing registers these resource types with
     * {@see \DavServices\Dav\Method\MkCol}, which refuses an unknown one by
     * name.
     *
     * This is a client's `DELETE` and not an application's `delete()`: a
     * deployment that takes a user away takes their collections with them, and
     * {@see Inbox::delete()} passes that straight to the store.
     *
     * @throws Forbidden If the path holds an Inbox or an Outbox
     */
    private function guardTheCollections(BeforeUnbind $event): void
    {
        $node = $this->server->tree()->node($event->path());

        foreach ([Inbox::INBOX, Outbox::OUTBOX] as $kind) {
            if (self::isOfKind($node, $kind)) {
                throw new Forbidden(
                    'RFC 6638 §2: a scheduling collection is the server to create and nobody to delete.',
                );
            }
        }
    }

    /**
     * Whether this node says it is of that kind.
     */
    private static function isOfKind(INode $node, string $kind): bool
    {
        return $node instanceof IResourceType && in_array($kind, $node->resourceTypes(), true);
    }
}
