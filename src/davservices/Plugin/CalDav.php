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
use DavServices\Dav\Event\BeforeCopy;
use DavServices\Dav\Event\BeforeCreateCollection;
use DavServices\Dav\Event\BeforeMove;
use DavServices\Dav\ICollection;
use DavServices\Dav\INode;
use DavServices\Dav\Server;
use DavServices\Exception\Forbidden;
use DavServices\Exception\NotFound;
use DavServices\Uri\Path;

/**
 * The one rule RFC 4791 §4.2 cannot leave to a node.
 *
 * Switched on in one line, like everything else here:
 *
 *     (new CalDav($server))->register();
 *
 * **§4.2 puts two restrictions on what a calendar may contain, and the second
 * is the one with teeth:**
 *
 * - (a) "Calendar collections MUST only contain calendar object resources and
 *   collections that are **not** calendar collections";
 * - (b) "Collections contained in calendar collections MUST NOT contain
 *   calendar collections **at any depth**."
 *
 * So a plain collection inside a calendar is allowed — the memo says so in as
 * many words — and what is forbidden is a calendar, anywhere below one.
 *
 * **That is a question about where a path lies**, and a node knows its name
 * and nothing about where it hangs. {@see Calendar} does the half it can see,
 * by not being an {@see \DavServices\Dav\IExtendedCollection}; this answers the
 * other half, on the two seams a calendar can arrive through:
 *
 * - **being made** — {@see \DavServices\CalDav\Method\MkCalendar} and an
 *   extended `MKCOL` both raise `BeforeCreateCollection` with the kind, and a
 *   calendar below a calendar is refused with §5.3.1's own
 *   `CALDAV:calendar-collection-location-ok`;
 * - **being moved or copied in** — without this half the rule would be a
 *   formality: a client would make the calendar beside and move it in. And
 *   "at any depth" means the question is not only whether the source *is* a
 *   calendar but whether it *holds* one.
 *
 * ## What this deliberately does not do yet
 *
 * **It does not announce `calendar-access` in the `DAV` header.** §5.1 makes
 * that value a promise with teeth: "A value of 'calendar-access' in the DAV
 * response header MUST indicate that the server supports all MUST level
 * requirements specified in this document" — and §7.8 makes
 * `CALDAV:calendar-query` one of those: "Support for the CALDAV:calendar-query
 * REPORT is REQUIRED." A server announcing the class before the reports exist
 * would send every client that reads it straight into a `403`, which is worse
 * than a server a client does not try at all.
 *
 * The class therefore arrives with the chunk that completes §5.1's promise,
 * which is the same reasoning that kept `CARDDAV:supported-collation-set` out
 * of {@see CardDav} until the collations existed.
 *
 * `MKCALENDAR` is not switched on here either, and for a reason of the memo's
 * own: §5.3.1 makes support for it only RECOMMENDED, because "some calendar
 * stores only support one calendar per user (or principal), and those are
 * typically pre-created for each account". An application whose store can make
 * calendars hands the method to the server; one whose store cannot needs no
 * method, and this plugin would be wrong to decide that for it.
 */
final class CalDav
{
    /**
     * The precondition §5.3.1 defines for a calendar that would go where one
     * may not.
     */
    private const LOCATION_OK = '{urn:ietf:params:xml:ns:caldav}calendar-collection-location-ok';

    public function __construct(private readonly Server $server)
    {
    }

    /**
     * Switches the one rule on, at both seams a calendar can arrive through.
     */
    public function register(): void
    {
        $events = $this->server->events();

        $events->on(BeforeCreateCollection::class, $this->refuseNesting(...));
        $events->on(BeforeCopy::class, $this->refuseABadDestination(...));
        $events->on(BeforeMove::class, $this->refuseABadDestination(...));
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
     * §4.2(b), for a calendar being moved or copied in.
     *
     * **"At any depth" is why this asks what the source *holds*** rather than
     * only what it is: a plain folder with a calendar inside it may not come to
     * rest in a calendar either.
     *
     * @throws Forbidden If what is being transferred would put a calendar
     *                   below a calendar
     */
    public function refuseABadDestination(BeforeCopy|BeforeMove $event): void
    {
        $moving = $this->nodeAt($event->from());

        if ($moving === null) {
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
            sprintf(
                'A calendar may not come to rest inside the calendar above "%s" (RFC 4791 §4.2).',
                $event->to(),
            ),
            self::LOCATION_OK,
        );
    }

    /**
     * Whether a path has a calendar anywhere above it.
     *
     * §4.2(b) forbids nesting "at any depth", so the walk goes all the way up
     * rather than looking at the parent alone.
     */
    private function isBelowACalendar(string $path): bool
    {
        [$parent] = Path::split($path);

        while ($parent !== '') {
            if ($this->nodeAt($parent) instanceof Calendar) {
                return true;
            }

            [$parent] = Path::split($parent);
        }

        return false;
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
}
