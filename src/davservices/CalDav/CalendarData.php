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

namespace DavServices\CalDav;

use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use DavServices\Exception\NotImplemented;
use DavServices\VObject\Component;
use DavServices\VObject\Serializer;
use DavServices\Xml\Element;

/**
 * The calendar data a report asks for, and the data it answers with
 * (RFC 4791 §9.6).
 *
 * The element has a shape per direction:
 *
 *     <!ELEMENT calendar-data (comp?,
 *                              (expand | limit-recurrence-set)?,
 *                              limit-freebusy-set?)>   in a request
 *     <!ELEMENT calendar-data (#PCDATA)>               in a response
 *     <!ATTLIST calendar-data content-type CDATA "text/calendar"
 *                             version CDATA "2.0">
 *
 * and a third, `EMPTY`, inside `CALDAV:supported-calendar-data`, where it
 * names a media type rather than data. That one is written by
 * {@see \DavServices\Plugin\CalDav} and read by nobody.
 *
 * **It is not a WebDAV property**, which §9.6 says in as many words: it "is
 * specified in requests and responses inside the DAV:prop XML element as if it
 * were a WebDAV property. However, the CALDAV:calendar-data XML element is not
 * a WebDAV property and, as such, is not returned in PROPFIND responses, nor
 * used in PROPPATCH requests." So a report answers it itself rather than
 * letting the property machinery try — a client that asked for it and got a
 * `404` would have been told the one thing it came for does not exist — and a
 * `DAV:allprop` never brings it along.
 *
 * ## What the attributes are held to
 *
 * §7.8 makes them a precondition of the report: "(CALDAV:supported-calendar-
 * data): The attributes 'content-type' and 'version' of the
 * CALDAV:calendar-data XML element (see Section 9.6) specify a media type
 * supported by the server for calendar object resources."
 *
 * What this server supports is §5.2.4's answer in the absence of a property
 * saying otherwise — "the server MUST only accept data with the media type
 * 'text/calendar' and iCalendar version 2.0" — and it is read from
 * {@see Limits} rather than written out again, so the property that publishes
 * the list and the report held to it cannot come apart.
 *
 * ## Narrowing is refused rather than ignored
 *
 * The three elements §9.6's request shape allows — `CALDAV:comp` with its
 * `prop` and `allcomp` (§9.6.1 to §9.6.4), `CALDAV:expand` (§9.6.5) and the
 * two `limit-…` elements (§9.6.6, §9.6.7) — are R-CAL-13 and R-CAL-14, and
 * they arrive with the chunk that is about them.
 *
 * **Until then the answer is `501` rather than the whole object.** §9.6
 * returns an object "in their entirety" for a request that asked for nothing
 * narrower, so sending everything to a client that asked for `VERSION` alone
 * would answer a question it did not ask and look like an answer. RFC 9110
 * §15.6.2 has the status for the other way round: "the server does not support
 * the functionality required to fulfill the request".
 */
final class CalendarData
{
    public const NAME = '{urn:ietf:params:xml:ns:caldav}calendar-data';

    /** §9.6.1, and what narrowing an answer begins with. */
    private const COMP = '{urn:ietf:params:xml:ns:caldav}comp';

    /** §9.6.5: recurrences written out as instances. */
    private const EXPAND = '{urn:ietf:params:xml:ns:caldav}expand';

    /** §9.6.6: the instances inside a range, and the overrides outside it. */
    private const LIMIT_RECURRENCE = '{urn:ietf:params:xml:ns:caldav}limit-recurrence-set';

    /** §9.6.7: the free/busy periods inside a range. */
    private const LIMIT_FREEBUSY = '{urn:ietf:params:xml:ns:caldav}limit-freebusy-set';

    /**
     * The four a client may narrow with, in the order §9.6's DTD writes them.
     *
     * @var list<string>
     */
    private const NARROWING = [self::COMP, self::EXPAND, self::LIMIT_RECURRENCE, self::LIMIT_FREEBUSY];

    /**
     * Holds the element a client sent to what this server can answer with.
     *
     * Asked before any object is read, because a precondition is a reason not
     * to run the report at all.
     *
     * @throws Forbidden If the attributes name calendar data this server does
     *                   not keep (§7.8's `CALDAV:supported-calendar-data`)
     * @throws NotImplemented If it asks for parts of an object (R-CAL-13)
     * @throws BadRequest If it carries a child §9.6's DTD does not name
     */
    public static function mustBeOneItAnswers(Element $element): void
    {
        self::mustBeTheDataItKeeps($element);

        foreach ($element->children() as $child) {
            if (in_array($child->name(), self::NARROWING, true)) {
                throw new NotImplemented(sprintf(
                    'This server answers a calendar object whole, so %s of RFC 4791 §9.6 is not one it can narrow by yet.',
                    $child->name(),
                ));
            }

            throw new BadRequest(sprintf(
                '%s has no place in a CALDAV:calendar-data of RFC 4791 §9.6.',
                $child->name(),
            ));
        }
    }

    /**
     * The element as a response carries it, which is §9.6's second shape:
     * `<!ELEMENT calendar-data (#PCDATA)>`.
     *
     * The writer escapes the text, which is what §9.6 asks for — "The
     * iCalendar data embedded within the CALDAV:calendar-data XML element MUST
     * follow the standard XML character data encoding rules, including use of
     * &lt;, &gt;, &amp; etc. entity encoding or the use of a <![CDATA[ ... ]]>
     * construct." Entity encoding is the half chosen here, so the warning that
     * follows it — that a CDATA section cannot carry the sequence that ends one
     * — does not apply.
     *
     * **The object is written out rather than handed on as it arrived.** §9.6
     * licenses the difference that makes: "Given that XML parsers normalize the
     * two-character sequence CRLF […] to a single LF character […], the CR
     * character […] MAY be omitted in calendar object resources specified in
     * the CALDAV:calendar-data XML element." What a client gets back is
     * therefore the object as this library writes one, and the chunk that
     * narrows it (R-CAL-13) has a component to narrow rather than text to cut.
     */
    public static function elementFor(Component $object): Element
    {
        $element = new Element(self::NAME);

        $element->appendText((new Serializer())->write($object));

        return $element;
    }

    /**
     * @throws Forbidden If this is not the media type and version §5.2.4 names
     */
    private static function mustBeTheDataItKeeps(Element $element): void
    {
        // RFC 9110 §8.3.1 makes a media type's "type, subtype, and parameter
        // name tokens" case-insensitive. A version is a string the memo prints
        // as it is, and is compared that way.
        $contentType = strtolower($element->attribute('content-type') ?? Limits::MEDIA_TYPE);
        $version = $element->attribute('version') ?? Limits::VERSION;

        if ($contentType === Limits::MEDIA_TYPE && $version === Limits::VERSION) {
            return;
        }

        throw new Forbidden(
            sprintf(
                'This server keeps calendar data as "%s" version %s, not as "%s" version %s: the CALDAV:supported-calendar-data precondition of RFC 4791 §7.8.',
                Limits::MEDIA_TYPE,
                Limits::VERSION,
                $contentType,
                $version,
            ),
            Limits::SUPPORTED_DATA,
        );
    }
}
