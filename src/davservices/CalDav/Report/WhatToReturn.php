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

namespace DavServices\CalDav\Report;

use DavServices\CalDav\CalendarData;
use DavServices\CalDav\Moments;
use DavServices\Dav\INode;
use DavServices\Dav\Property\Answers;
use DavServices\Dav\PropFindForm;
use DavServices\Dav\Server;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use DavServices\Exception\NotImplemented;
use DavServices\VObject\Component;
use DavServices\Xml\Element;
use DavServices\Xml\MultiStatus;

/**
 * What a report is to return about one object, and the `DAV:response` it
 * becomes.
 *
 * **The reports of RFC 4791 answer the same question about an object.** §7.9
 * says so of the multiget — it "is similar to the CALDAV:calendar-query REPORT
 * (see Section 7.8), except that it takes a list of DAV:href elements, instead
 * of a CALDAV:filter element, to determine which calendar object resources to
 * return" — and both read the same optional property group, both may carry a
 * `CALDAV:calendar-data`, and both answer with the same `DAV:response`. So it
 * is read and written in one place rather than in two that could drift apart.
 *
 * ## The one thing that makes this more than a parser
 *
 * §9.6: the `CALDAV:calendar-data` element "is specified in requests and
 * responses inside the DAV:prop XML element **as if it were a WebDAV
 * property**. However, the CALDAV:calendar-data XML element is not a WebDAV
 * property and, as such, is not returned in PROPFIND responses, nor used in
 * PROPPATCH requests."
 *
 * So it arrives among the property names and has to be taken out before the
 * property machinery is asked — a server that passed it along would answer the
 * one thing the client came for with the `404` §7.8 demands for a property
 * nobody has. For the same reason a `DAV:allprop` brings no calendar data with
 * it: it asks for WebDAV properties, and this is not one.
 *
 * ## The response takes the shape the answer has
 *
 * RFC 4918 §14.24 gives a `DAV:response` two shapes — an href with propstats,
 * or an href with a status — and both cases here are one of them:
 *
 * - properties, calendar data, or both: one `DAV:propstat` per status;
 * - a client that named neither: the href and a `200`, because an empty
 *   propstat is not a shape the grammar admits.
 *
 * There is no third case of the kind {@see \DavServices\CardDav\AddressData}
 * has, where a card cannot be converted into what was asked for: §5.2.4 gives
 * this server one media type and one version, so an object it keeps is an
 * object it can answer with.
 */
final class WhatToReturn
{
    /**
     * @param list<string> $names The WebDAV properties named, with
     *                            `CALDAV:calendar-data` already taken out
     * @param CalendarData|null $data What of the object the client asked for,
     *                                or null where it asked for none of it
     */
    private function __construct(
        private readonly Server $server,
        private readonly ?PropFindForm $form,
        private readonly array $names,
        private readonly ?CalendarData $data,
    ) {
    }

    /**
     * Reads the property group of a report's body.
     *
     * @throws Forbidden If a `CALDAV:calendar-data` asks for a media type this
     *                   server never advertised (§7.8's
     *                   `CALDAV:supported-calendar-data`)
     * @throws NotImplemented If it asks for an expansion (R-CAL-14)
     * @throws BadRequest If it carries a child §9.6's DTD does not name
     */
    public static function of(Server $server, Element $asked): self
    {
        $named = $asked->child('{DAV:}prop');

        if ($named !== null) {
            return self::named($server, $named);
        }

        if ($asked->child('{DAV:}propname') !== null) {
            return new self($server, PropFindForm::NamesOnly, [], null);
        }

        if ($asked->child('{DAV:}allprop') !== null) {
            return new self($server, PropFindForm::Everything, [], null);
        }

        // §9.5 and §9.10 make the group optional, and §7.8 has it that "the
        // request can include XML elements to request WebDAV properties to be
        // returned" — so one that named none wanted none, and answering
        // `allprop` would send an object nobody asked for.
        return new self($server, null, [], null);
    }

    /**
     * Adds this object to the report.
     *
     * @param Moments $moments What moment each of the object's properties
     *                         names, which is what §9.6.6's and §9.6.7's
     *                         limits are measured with — the zone having been
     *                         chosen as §7.3 says, by whoever read the request
     */
    public function describe(
        MultiStatus $report,
        string $href,
        string $path,
        INode $node,
        Component $object,
        Moments $moments,
    ): void {
        $byStatus = $this->form === null
            ? []
            : Answers::about($this->server->events(), $path, $node, $this->form, $this->names)->byStatus();

        if ($this->data !== null) {
            $byStatus[200][CalendarData::NAME] = $this->data->elementFor($object, $moments);
        }

        if ($byStatus === []) {
            $report->addStatus($href, 200);

            return;
        }

        $report->addProperties($href, $byStatus);
    }

    /**
     * A `DAV:prop`, with the calendar data told apart from the properties.
     *
     * **A `DAV:prop` holding nothing but the data leaves nothing to ask the
     * property machinery**, and that is why the form goes back to null rather
     * than staying `Named` over an empty list: {@see Answers} would hand back
     * an empty `DAV:propstat`, which RFC 4918 §14.24 does not admit.
     *
     * @throws Forbidden If the data is not one this server keeps
     * @throws NotImplemented If it asks for an expansion (R-CAL-14)
     * @throws BadRequest If it carries a child §9.6's DTD does not name
     */
    private static function named(Server $server, Element $prop): self
    {
        $data = $prop->child(CalendarData::NAME);
        $names = [];

        foreach ($prop->childNames() as $name) {
            if ($name !== CalendarData::NAME) {
                $names[] = $name;
            }
        }

        return new self(
            $server,
            $names === [] ? null : PropFindForm::Named,
            $names,
            $data === null ? null : CalendarData::of($data),
        );
    }
}
