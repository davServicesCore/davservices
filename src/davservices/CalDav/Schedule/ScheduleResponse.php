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

namespace DavServices\CalDav\Schedule;

use DavServices\Xml\Element;

/**
 * The answer to a busy time request (RFC 6638 §5, elements in §10).
 *
 * > A response to a busy time request that indicates status for one or more
 * > calendar users MUST be an XML document with a CALDAV:schedule-response
 * > XML element as its root element. This element MUST contain one
 * > CALDAV:response element for each calendar user, with each such element in
 * > turn containing elements that indicate which calendar user they
 * > correspond to, the scheduling status for that calendar user, any error
 * > codes, and an optional description.
 *
 * §10.2 gives the order inside one response, and the order is the grammar:
 *
 *     <!ELEMENT response (recipient,
 *                         request-status,
 *                         calendar-data?,
 *                         DAV:error?,
 *                         DAV:responsedescription?)>
 *
 * ## One for each calendar user is a count, not a set
 *
 * RFC 5546 §3.3.2 gives `ATTENDEE | 1+` and says nothing about uniqueness, so
 * a request may name an address twice. Then it is answered twice: a client
 * lines the answers up against the questions it sent, and a server that
 * folded two into one would hand back a document that does not match.
 *
 * ## The status is iTIP's value, not a bare code
 *
 * §10.4 has `request-status` be "The iTIP 'REQUEST-STATUS' property value for
 * this response", and RFC 5546 §3.6 makes that a `statcode` followed by a
 * short description. Appendix B.5 writes `2.0;Success` and
 * `3.7;Invalid calendar user`, so both halves are carried rather than leaving
 * a client to look the number up.
 *
 * ## What is not written here
 *
 * `DAV:error` and `DAV:responsedescription` are both optional in §10.2, and
 * nothing this server reports needs either: the two statuses it uses are
 * iTIP's own and say what happened in their own words. An empty `DAV:error`
 * would name a precondition that was not violated, which is worse than
 * saying nothing.
 */
final class ScheduleResponse
{
    /**
     * §10.1's root element.
     */
    public const NAME = '{urn:ietf:params:xml:ns:caldav}schedule-response';

    /**
     * RFC 5546 §3.6: "2.0;Success".
     */
    public const SUCCESS = '2.0;Success';

    /**
     * RFC 5546 §3.6: "3.7;Invalid calendar user" — nobody is reachable at
     * that address, which Appendix B.5 reports for Mike.
     */
    public const NO_SUCH_USER = '3.7;Invalid calendar user';

    /**
     * RFC 5546 §3.6.21: "No authority" — the code for an operation that
     * "failed because an Attendee does not have suitable privileges".
     *
     * **It is the calendar user asked about who did not grant them**, which
     * is RFC 6638 §6.1.4: `CALDAV:schedule-query-freebusy` "controls freebusy
     * requests targeted at the owner of the scheduling Inbox collection". So
     * this is reported **per recipient** and the others are still answered —
     * one calendar user's rules do not decide what is said about anybody
     * else.
     *
     * Not to be confused with §6.2.4, which is the asker's own right to use
     * the method at all: that one refuses the request outright with §5.1's
     * `403`.
     */
    public const NO_AUTHORITY = '3.8;No authority';

    private const NS = 'urn:ietf:params:xml:ns:caldav';

    /** @var list<array{string, string, string|null}> */
    private array $told = [];

    /**
     * Reports one calendar user.
     *
     * @param string $recipient The calendar user address this answer is for,
     *                          as §10.3's `recipient` carries it
     * @param string $status The iTIP `REQUEST-STATUS` value (§10.4)
     * @param string|null $calendarData The `VFREEBUSY` found, where there is
     *                                  one. §5 has `calendar-data` "For a
     *                                  successful busy time request", so a
     *                                  failure passes null rather than an
     *                                  empty document — which would say the
     *                                  user is free rather than that nobody
     *                                  looked
     */
    public function tell(string $recipient, string $status, ?string $calendarData = null): void
    {
        $this->told[] = [$recipient, $status, $calendarData];
    }

    /**
     * The document, as §10.1 and §10.2 shape it.
     */
    public function asElement(): Element
    {
        $document = new Element(self::NAME);

        foreach ($this->told as [$recipient, $status, $calendarData]) {
            $document->append(self::oneResponse($recipient, $status, $calendarData));
        }

        return $document;
    }

    /**
     * One `CALDAV:response`, its children in the order §10.2 gives.
     */
    private static function oneResponse(string $recipient, string $status, ?string $calendarData): Element
    {
        $response = new Element('{' . self::NS . '}response');
        $named = new Element('{' . self::NS . '}recipient');

        $named->append(self::saying('{DAV:}href', $recipient));
        $response->append($named);
        $response->append(self::saying('{' . self::NS . '}request-status', $status));

        if ($calendarData !== null) {
            $response->append(self::saying('{' . self::NS . '}calendar-data', $calendarData));
        }

        return $response;
    }

    /**
     * An element holding text and nothing else.
     *
     * §10.2 notes of `calendar-data` that it is "the definition with content
     * (#PCDATA) only" here, and §10.3's and §10.4's are #PCDATA as well — so
     * every leaf of this document is written the one way.
     */
    private static function saying(string $name, string $text): Element
    {
        $element = new Element($name);

        $element->appendText($text);

        return $element;
    }
}
