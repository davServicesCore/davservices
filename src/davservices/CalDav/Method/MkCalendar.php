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

namespace DavServices\CalDav\Method;

use DavServices\CalDav\Calendar;
use DavServices\CalDav\TimeZone;
use DavServices\Dav\Event\AfterBind;
use DavServices\Dav\Event\AfterCreateCollection;
use DavServices\Dav\Event\BeforeBind;
use DavServices\Dav\Event\BeforeCreateCollection;
use DavServices\Dav\ICollection;
use DavServices\Dav\IExtendedCollection;
use DavServices\Dav\Server;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use DavServices\Exception\MethodNotAllowed;
use DavServices\Exception\UnsupportedMediaType;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Uri\Path;
use DavServices\Xml\Element;

/**
 * Answers `MKCALENDAR`: make me a calendar (RFC 4791 §5.3.1).
 *
 *     <!ELEMENT mkcalendar (DAV:set)>
 *
 * **Support is only RECOMMENDED**, and the memo says why: "some calendar
 * stores only support one calendar per user (or principal), and those are
 * typically pre-created for each account." So this is a method an application
 * hands to the server rather than something a plugin switches on by itself:
 *
 *     $server->onMethod('MKCALENDAR', (new MkCalendar($server))(...));
 *
 * A store that cannot make calendars needs no method, and one that can says so
 * by implementing {@see IExtendedCollection}.
 *
 * ## Why the request goes to the store in one call
 *
 * §5.3.1 asks for more than a creation: "Instruction processing MUST occur in
 * the order instructions are received […] Instructions MUST either all be
 * executed or none executed. Thus, if any error occurs during processing, all
 * executed instructions MUST be undone and a proper error result returned."
 * And: "If a MKCALENDAR request fails, the server state preceding the request
 * MUST be restored."
 *
 * A server that created the collection and then set the properties could not
 * promise that. So the kind and the properties travel together, through the
 * same seam RFC 5689's extended `MKCOL` uses — a store either makes the whole
 * thing or makes nothing. Nothing here is created before every precondition
 * has been answered, which is why there is nothing to undo.
 *
 * ## The preconditions
 *
 * §5.3.1 names four. Three are answered here:
 *
 * - **`DAV:resource-must-be-null`** — "A resource MUST NOT exist at the
 *   Request-URI". RFC 4918 §9.3.1 answers the same question for `MKCOL` with
 *   `405`, and this is that question.
 * - **`CALDAV:calendar-collection-location-ok`** — "The Request-URI MUST
 *   identify a location where a calendar collection can be created". Two
 *   things can make it the wrong place: a store that does not make calendars,
 *   which is this method's `403`; and §4.2(b)'s ban on nesting, which is a
 *   question about where a path lies and belongs to
 *   {@see \DavServices\Plugin\CalDav} — it refuses on the event raised below.
 * - **`CALDAV:valid-calendar-data`** — "The time zone specified in the
 *   CALDAV:calendar-timezone property MUST be a valid iCalendar object
 *   containing a single valid VTIMEZONE component". What counts as valid is
 *   {@see TimeZone}'s answer.
 *
 * The fourth, **`DAV:needs-privilege`** for `DAV:bind` on the parent, is
 * {@see \DavServices\Plugin\Acl}'s: it guards every method that binds, through
 * the same `BeforeBind` event this one raises.
 *
 * ## And the one sentence that is easiest to miss
 *
 * §5.3.1: "The response MUST include a Cache-Control:no-cache header." A
 * client behind a cache that never sees it asks for the calendar it has just
 * made and is told there is nothing there.
 */
final class MkCalendar
{
    /** What §4.2 has a calendar report, and what the store is asked for. */
    private const KINDS = ['{DAV:}collection', Calendar::CALENDAR];

    private const MKCALENDAR = '{urn:ietf:params:xml:ns:caldav}mkcalendar';

    private const TIMEZONE = '{urn:ietf:params:xml:ns:caldav}calendar-timezone';

    private const MUST_BE_NULL = '{DAV:}resource-must-be-null';

    private const LOCATION_OK = '{urn:ietf:params:xml:ns:caldav}calendar-collection-location-ok';

    private const VALID_CALENDAR_DATA = '{urn:ietf:params:xml:ns:caldav}valid-calendar-data';

    public function __construct(private readonly Server $server)
    {
    }

    /**
     * Makes the calendar, or says why it did not.
     *
     * @throws MethodNotAllowed If something is at the path already
     * @throws UnsupportedMediaType If the body is no `CALDAV:mkcalendar`
     * @throws Forbidden If the time zone is none, or the store will not make a
     *                   calendar there
     */
    public function __invoke(Request $request): Response
    {
        $path = $this->server->path($request);

        if ($this->server->tree()->exists($path)) {
            throw new MethodNotAllowed('There is something at that path already.', self::MUST_BE_NULL);
        }

        [$parentPath, $name] = Path::split($path);

        // A parent that is not there is RFC 4791 §5.3.1.1's `409`: "A
        // collection cannot be made at the Request-URI until one or more
        // intermediate collections have been created." The tree says so.
        $parent = $this->server->tree()->collectionAt($parentPath);

        $properties = $this->propertiesIn($request);

        self::mustBeAValidTimeZone($properties);

        $this->server->events()->emit(new BeforeBind($path));
        $this->server->events()->emit(new BeforeCreateCollection($path, self::KINDS));

        $this->make($parent, $name, $properties);

        $this->server->tree()->forget($parentPath);
        $this->server->events()->emit(new AfterCreateCollection($path));
        $this->server->events()->emit(new AfterBind($path));

        return (new Response(201))->withHeader('Cache-Control', 'no-cache');
    }

    /**
     * Hands the whole request to the store.
     *
     * @param array<string, Element> $properties
     *
     * @throws Forbidden If this is no place a calendar can be made
     */
    private function make(ICollection $parent, string $name, array $properties): void
    {
        if (!$parent instanceof IExtendedCollection) {
            throw new Forbidden(
                'No calendar can be made here: RFC 4791 §5.3.1 lets a server "restrict calendar collection creation to particular collections".',
                self::LOCATION_OK,
            );
        }

        $parent->createExtendedCollection($name, self::KINDS, $properties);
    }

    /**
     * What the `DAV:set` of the body asks to be set.
     *
     * **A body that sets nothing asks for what no body asks for.** §5.3.1
     * makes the body itself conditional — "If a request body is included, it
     * MUST be a CALDAV:mkcalendar XML element" — so an empty one is the same
     * request as none. Nothing is passed over, because nothing was asked for.
     *
     *
     * @throws UnsupportedMediaType If the body is not a `CALDAV:mkcalendar`,
     *                              or cannot be read at all
     *
     * @return array<string, Element>
     */
    private function propertiesIn(Request $request): array
    {
        $body = $request->body();

        if ($body->isEmpty()) {
            return [];
        }

        // RFC 4918 §9.3: a body the server does not understand is a `415`, and
        // that covers one it cannot read. A document that is not well formed is
        // a `400` from the reader; for this method it is an entity of a type
        // this server does not take, which is the same reading `MKCOL` makes.
        try {
            $document = $this->server->reader()->parse($body->contents());
        } catch (BadRequest $unreadable) {
            throw new UnsupportedMediaType('This body is not a CALDAV:mkcalendar.', null, $unreadable);
        }

        if ($document->name() !== self::MKCALENDAR) {
            throw new UnsupportedMediaType('This body is not a CALDAV:mkcalendar.');
        }

        return self::propertiesOf($document);
    }

    /**
     * Everything the `DAV:set` blocks ask to be set, in the order they arrive.
     *
     * §5.3.1: "Instruction processing MUST occur in the order instructions are
     * received (i.e., from top to bottom)", which one pass over the document
     * gives — and a property named twice is set once, the later value winning.
     *
     * @return array<string, Element>
     */
    private static function propertiesOf(Element $document): array
    {
        $properties = [];

        foreach ($document->children() as $set) {
            if ($set->name() !== '{DAV:}set') {
                continue;
            }

            foreach ($set->children() as $prop) {
                if ($prop->name() !== '{DAV:}prop') {
                    continue;
                }

                foreach ($prop->children() as $property) {
                    $properties[$property->name()] = $property;
                }
            }
        }

        return $properties;
    }

    /**
     * §5.3.1's `CALDAV:valid-calendar-data`, asked before anything is made.
     *
     * @param array<string, Element> $properties
     *
     * @throws Forbidden If a time zone was given and is not one
     */
    private static function mustBeAValidTimeZone(array $properties): void
    {
        $zone = $properties[self::TIMEZONE] ?? null;

        if ($zone === null) {
            // §5.2.2: "In the absence of this property, the server MAY rely on
            // the time zone of their choice." So not saying is not an error.
            return;
        }

        if (TimeZone::in($zone->text()) === null) {
            throw new Forbidden(
                'A CALDAV:calendar-timezone is an iCalendar object with exactly one valid VTIMEZONE component (RFC 4791 §5.2.2, §5.3.1).',
                self::VALID_CALENDAR_DATA,
            );
        }
    }
}
