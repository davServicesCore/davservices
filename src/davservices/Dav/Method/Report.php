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

namespace DavServices\Dav\Method;

use Closure;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\Server;
use DavServices\Event\EventEmitter;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use DavServices\Exception\NotFound;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Xml\Element;

/**
 * Answers `REPORT`: whatever the body asks for, by name (RFC 3253 §3.6).
 *
 * **This is the method everything else in the protocol family is built on,
 * and it is remarkably little on its own.** A body naming what is wanted, and
 * a server that knows how to answer that name. Everything that makes a report
 * interesting — searching principals, querying calendars, telling a client
 * what has changed — is the report rather than the method.
 *
 * So it does three things and no more:
 *
 *     $report = new Report($server);
 *     $report->on('{DAV:}principal-search-property-set', $searchable(...));
 *     $report->register();
 *
 * It **reads the body once** and hands the report the element it found, so
 * that no report parses the request twice: two readings are two chances to
 * disagree about what the client asked for.
 *
 * It **refuses a name it does not know** with `403` and `DAV:supported-report`
 * (§3.6). The precondition is what tells a client to stop asking — a bare
 * `403` reads as "not for you", and a client would go on trying with other
 * credentials.
 *
 * And it **answers `DAV:supported-report-set`** (§3.1.5), because the thing
 * that knows the reports is the only thing that can. That is why this method
 * has a `register()` where the others are simply handed to the server: one
 * that brought a property with it and left the wiring to the application
 * would sooner or later be wired half way, and a `supported-report-set` that
 * said "none" while reports were being answered is a client that never asks
 * for them.
 *
 * **`Depth` is not read here.** RFC 3253 lets each report define what depth
 * means for it, and they do not agree — so the request is handed over whole
 * and the report takes what it needs.
 *
 * Every report is offered on every resource. A server whose reports differ by
 * resource — a calendar query belongs on a calendar and nowhere else — would
 * need this list to be asked for per path rather than kept here; that is a
 * seam to add when there is a report that wants it, not before.
 */
final class Report
{
    private const SUPPORTED_REPORTS = '{DAV:}supported-report-set';

    /**
     * Early enough to answer `supported-report-set` before the live
     * properties say there are none. The order is set here rather than by
     * whoever wires the application together, because it decides an answer
     * rather than a convenience.
     */
    private const BEFORE_THE_LIVE_PROPERTIES = EventEmitter::DEFAULT_PRIORITY - 1;

    /** @var array<string, Closure(Request, Element): Response> */
    private array $reports = [];

    public function __construct(private readonly Server $server)
    {
    }

    /**
     * Takes a report, by the name a client asks for it under.
     *
     * @param string $name As `{namespace}localname`
     * @param callable(Request, Element): Response $report What answers it,
     *                                                     given the request
     *                                                     and the element the
     *                                                     client sent
     */
    public function on(string $name, callable $report): void
    {
        $this->reports[$name] = $report(...);
    }

    /**
     * Switches `REPORT` on, and the property that says what it answers.
     */
    public function register(): void
    {
        $this->server->onMethod('REPORT', $this(...));
        $this->server->events()->on(
            PropertiesRequested::class,
            $this->describe(...),
            self::BEFORE_THE_LIVE_PROPERTIES,
        );
    }

    /**
     * Hands the request to whichever report it names.
     *
     * @throws NotFound If there is nothing at the path
     * @throws BadRequest If the body names nothing, or cannot be read
     * @throws Forbidden If this server does not answer that report
     */
    public function __invoke(Request $request): Response
    {
        // **RFC 3253 §3.6: the Request-URI identifies the resource the report
        // is about.** There being none is looked for here rather than in each
        // report, because every report of this family is about that resource
        // — and one that forgot to look would answer as though it were merely
        // empty, which is a different thing and a worse answer.
        //
        // It is looked for **before** the body is read: a client that asked
        // about something that is not there has that wrong with its request
        // whatever the body says, and being told the body was malformed would
        // send it looking in the wrong place.
        $this->server->tree()->node($this->server->path($request));

        // **There is no default report**, unlike a `PROPFIND` with no body,
        // which asks for everything. Choosing one would be answering a
        // question nobody asked — so a body that names nothing is refused,
        // and the reader is what refuses it: it says the body is empty, with
        // the same `400`, and a second guard for the same thing would be a
        // second place to keep right.
        $asked = $this->server->reader()->parse($request->body()->contents());
        $report = $this->reports[$asked->name()] ?? null;

        if ($report === null) {
            throw new Forbidden(
                sprintf('This server does not answer the report "%s".', $asked->name()),
                '{DAV:}supported-report',
            );
        }

        return $report($request, $asked);
    }

    /**
     * RFC 3253 §3.1.5: what may be asked for at all.
     *
     * A report that is not named here does not exist as far as a client is
     * concerned, so the list comes from the same place the answers do.
     */
    public function describe(PropertiesRequested $event): void
    {
        $result = $event->result();

        if (!$result->wants(self::SUPPORTED_REPORTS)) {
            return;
        }

        $set = new Element(self::SUPPORTED_REPORTS);

        foreach (array_keys($this->reports) as $name) {
            $supported = new Element('{DAV:}supported-report');
            $report = new Element('{DAV:}report');

            $report->append(new Element($name));
            $supported->append($report);
            $set->append($supported);
        }

        $result->set(self::SUPPORTED_REPORTS, $set);
    }
}
