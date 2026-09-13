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

use DavServices\Dav\Event\PropertiesChanging;
use DavServices\Dav\INode;
use DavServices\Dav\IProperties;
use DavServices\Dav\PropPatchResult;
use DavServices\Dav\Server;
use DavServices\Exception\BadRequest;
use DavServices\Exception\NotFound;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Xml\Element;
use DavServices\Xml\MultiStatus;

/**
 * Answers `PROPPATCH`: the properties of one resource are changed, all of them
 * or none (R-DAV-05).
 *
 * The way that promise is kept is by **deciding before writing**. The
 * listeners of {@see PropertiesChanging} and then the node itself say what
 * they will and will not have; only once nothing has been refused does
 * anything get written. A server that wrote as it went and tried to wind back
 * afterwards would be relying on every storage having something to wind back
 * with, and most have nothing of the kind.
 *
 * The node is asked first of those that write, because it holds most of what
 * there is: a refusal from it stops the listeners' writers before they run.
 * Two storages cannot share a transaction, and the order is what decides which
 * way the remaining crack falls.
 *
 * A property that was refused keeps its own status; every other one in the
 * request gets `424 Failed Dependency`, which tells a client the thing it
 * needs — that there is nothing wrong with *those*, and no point in retrying
 * them on their own.
 *
 * Registered like any other method:
 *
 *     $propPatch = new PropPatch($server);
 *     $server->onMethod('PROPPATCH', $propPatch(...));
 */
final class PropPatch
{
    public function __construct(private readonly Server $server)
    {
    }

    /**
     * Changes what the request asks for, or nothing at all.
     *
     * @throws NotFound If there is nothing at the path
     * @throws BadRequest If the body is not a `DAV:propertyupdate`, or changes
     *                    nothing
     */
    public function __invoke(Request $request): Response
    {
        $path = $this->server->path($request);
        $node = $this->server->tree()->node($path);

        $result = new PropPatchResult($path, $this->whatWasAskedFor($request));

        $this->server->events()->emit(new PropertiesChanging($result, $node));

        $this->settleWhatIsLeft($result, $node);

        if (!$result->hasFailure()) {
            $this->write($result);
            $this->server->tree()->forget($path);
        }

        $report = new MultiStatus();
        $report->addProperties($this->server->href($path), $result->byStatus());

        return (new Response(207, body: $this->server->writer()->write($report->toElement())))
            ->withHeader('Content-Type', 'application/xml; charset=utf-8');
    }

    /**
     * What the body asks to be changed, in the order it asks for it.
     *
     * RFC 4918 §9.2 has the instructions carried out in the order they were
     * sent: a client that removes a property and then sets it means to end up
     * with the value, and one that does it the other way round means to end up
     * with nothing.
     *
     * @throws BadRequest If the body is not a `DAV:propertyupdate`, or asks
     *                    for no change at all
     *
     * @return array<string, Element|string|null> Null removes the property
     */
    private function whatWasAskedFor(Request $request): array
    {
        $body = $request->body();

        if ($body->isEmpty()) {
            throw new BadRequest('A PROPPATCH that changes nothing is not a request.');
        }

        $document = $this->server->reader()->parse($body->contents());

        if ($document->name() !== '{DAV:}propertyupdate') {
            throw new BadRequest('The body of a PROPPATCH is a DAV:propertyupdate.');
        }

        $mutations = [];

        foreach ($document->children() as $instruction) {
            $removing = $instruction->name() === '{DAV:}remove';

            if (!$removing && $instruction->name() !== '{DAV:}set') {
                continue;
            }

            foreach ($instruction->children() as $prop) {
                if ($prop->name() !== '{DAV:}prop') {
                    continue;
                }

                foreach ($prop->children() as $property) {
                    $mutations[$property->name()] = $removing ? null : $property;
                }
            }
        }

        if ($mutations === []) {
            throw new BadRequest('This PROPPATCH asks for no change at all.');
        }

        return $mutations;
    }

    /**
     * Asks the node about everything nobody else has taken on.
     *
     * In one call, so that the storage holding most of the properties makes
     * its changes together or not at all. A node that keeps no properties can
     * take none, and says so for each rather than letting the request look as
     * though it had worked.
     */
    private function settleWhatIsLeft(PropPatchResult $result, INode $node): void
    {
        $open = $result->open();

        if ($open === []) {
            return;
        }

        if (!$node instanceof IProperties) {
            foreach (array_keys($open) as $name) {
                $result->set($name, 403);
            }

            return;
        }

        foreach ($node->patchProperties($open) as $name => $status) {
            $result->set($name, $status);
        }

        // A backend that says nothing about a property has not changed it.
        // Reporting `200` for a change nobody confirmed is the one answer that
        // cannot be taken back.
        foreach (array_keys($open) as $name) {
            $result->set($name, 403);
        }
    }

    /**
     * Runs the writers of the listeners that took a property on.
     *
     * Only once nothing has been refused, so that a listener never writes into
     * a request that is going to fail.
     */
    private function write(PropPatchResult $result): void
    {
        $mutations = $result->mutations();

        foreach ($result->writers() as $name => $writer) {
            $writer($mutations[$name] ?? null);
            $result->set($name, 200);
        }
    }
}
