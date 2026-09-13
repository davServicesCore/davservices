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

use DavServices\Dav\Event\AfterBind;
use DavServices\Dav\Event\AfterCreateCollection;
use DavServices\Dav\Event\BeforeBind;
use DavServices\Dav\Event\BeforeCreateCollection;
use DavServices\Dav\ICollection;
use DavServices\Dav\IExtendedCollection;
use DavServices\Dav\Server;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Conflict;
use DavServices\Exception\MethodNotAllowed;
use DavServices\Exception\UnsupportedMediaType;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Uri\Path;
use DavServices\Xml\Element;
use DavServices\Xml\MkColResponse;

/**
 * Answers `MKCOL`: a collection is created, plain or of a kind the client asks
 * for (R-DAV-08).
 *
 * The plain form of RFC 4918 §9.3 is the whole of it for most clients: no
 * body, `201`, done. Two refusals are what keep a tree from filling up with
 * things nobody asked for. **A path that is taken is a `405`**, never a
 * replacement of what is there. **A missing parent collection is a `409`**,
 * never a quiet creation of the ancestors: that is how one typo becomes a tree
 * of empty collections.
 *
 * The extended form of RFC 5689 is what makes calendars and address books
 * possible: in one request the client says what kind of collection it wants
 * and what properties it is to carry, and the request either succeeds whole or
 * changes nothing. A property that cannot be set is **not** quietly skipped —
 * nothing is created, and the answer names the property, because a client told
 * `201` would go on believing its calendar has the name and the colour it
 * asked for.
 *
 * What a backend can make is the backend's business: a collection that
 * implements {@see IExtendedCollection} is handed the request whole, and one
 * that does not can make plain collections and says so with
 * `DAV:valid-resourcetype` for anything else.
 *
 * Registered like any other method:
 *
 *     $mkCol = new MkCol($server);
 *     $server->onMethod('MKCOL', $mkCol(...));
 */
final class MkCol
{
    /** What `MKCOL` makes where the client asks for nothing in particular. */
    private const PLAIN = ['{DAV:}collection'];

    public function __construct(private readonly Server $server)
    {
    }

    /**
     * Creates the collection the request names.
     *
     * @throws MethodNotAllowed If something is at that path already
     * @throws Conflict If the collection it would go in is not there
     * @throws UnsupportedMediaType If the body is not an extended `MKCOL`
     * @throws BadRequest If the body is an extended `MKCOL` that sets nothing
     */
    public function __invoke(Request $request): Response
    {
        $path = $this->server->path($request);

        if ($this->server->tree()->exists($path)) {
            throw new MethodNotAllowed('There is something at that path already.');
        }

        [$parentPath, $name] = Path::split($path);
        $parent = $this->server->tree()->collectionAt($parentPath);

        [$resourceTypes, $properties] = $this->whatWasAskedFor($request);

        $this->server->events()->emit(new BeforeBind($path));
        $this->server->events()->emit(new BeforeCreateCollection($path, $resourceTypes));

        $refusal = $this->create($parent, $name, $resourceTypes, $properties);

        if ($refusal !== null) {
            return $refusal;
        }

        $this->server->tree()->forget($parentPath);
        $this->server->events()->emit(new AfterCreateCollection($path));
        $this->server->events()->emit(new AfterBind($path));

        return new Response(201);
    }

    /**
     * The kind of collection and the properties the request asks for.
     *
     * @throws UnsupportedMediaType If the body is not an extended `MKCOL`
     * @throws BadRequest If the body sets nothing, or is not well formed
     *
     * @return array{list<string>, array<string, Element>}
     */
    private function whatWasAskedFor(Request $request): array
    {
        $body = $request->body();

        if ($body->isEmpty()) {
            return [self::PLAIN, []];
        }

        $document = $this->server->reader()->parse($body->contents());

        // RFC 4918 §9.3: a body the server does not understand is a `415`, and
        // RFC 5689 §5.1 reserves every other root element for later use. A
        // server that went ahead and made a plain collection would be
        // answering a request it never read.
        if ($document->name() !== '{DAV:}mkcol') {
            throw new UnsupportedMediaType('This body is not an extended MKCOL.');
        }

        $properties = self::propertiesOf($document);

        if ($properties === []) {
            throw new BadRequest('An extended MKCOL that sets nothing is not a request.');
        }

        $resourceType = $properties['{DAV:}resourcetype'] ?? null;

        unset($properties['{DAV:}resourcetype']);

        return [$resourceType === null ? self::PLAIN : self::namesOf($resourceType), $properties];
    }

    /**
     * Everything the `DAV:set` blocks of the document ask to be set.
     *
     * @return array<string, Element>
     */
    private static function propertiesOf(Element $document): array
    {
        $properties = [];

        foreach (self::childrenNamed($document, '{DAV:}set') as $set) {
            foreach (self::childrenNamed($set, '{DAV:}prop') as $prop) {
                foreach ($prop->children() as $property) {
                    // A property named twice is set once: the later value wins,
                    // which is what a single pass over the document gives.
                    $properties[$property->name()] = $property;
                }
            }
        }

        return $properties;
    }

    /**
     * @return list<Element>
     */
    private static function childrenNamed(Element $element, string $name): array
    {
        $found = [];

        foreach ($element->children() as $child) {
            if ($child->name() === $name) {
                $found[] = $child;
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private static function namesOf(Element $element): array
    {
        return array_map(static fn (Element $child): string => $child->name(), $element->children());
    }

    /**
     * @param list<string> $resourceTypes
     * @param array<string, Element> $properties
     *
     * @throws Conflict If a member of that name appeared in the meantime
     *
     * @return Response|null The refusal, where this collection cannot make
     *                       what was asked for; null where it was made
     */
    private function create(ICollection $parent, string $name, array $resourceTypes, array $properties): ?Response
    {
        if ($resourceTypes === self::PLAIN && $properties === []) {
            $parent->createCollection($name);

            return null;
        }

        // What a backend can make is the backend's business. It is handed the
        // request whole — the kind and the properties in one call — because
        // RFC 5689 asks for one operation that either succeeds or changes
        // nothing, and two calls could not give that.
        if ($parent instanceof IExtendedCollection) {
            $parent->createExtendedCollection($name, $resourceTypes, $properties);

            return null;
        }

        return $this->refuse($resourceTypes, $properties);
    }

    /**
     * Says which property this collection would not have, and creates nothing.
     *
     * @param list<string> $resourceTypes
     * @param array<string, Element> $properties
     */
    private function refuse(array $resourceTypes, array $properties): Response
    {
        $report = new MkColResponse();
        $theKindWasRefused = $resourceTypes !== self::PLAIN;

        if ($theKindWasRefused) {
            $report->add(403, ['{DAV:}resourcetype'], '{DAV:}valid-resourcetype');
        }

        if ($properties !== []) {
            // RFC 4918 §9.2, which RFC 5689 borrows its atomicity from: what
            // was not itself refused failed for another's sake, and `424` says
            // so. Reporting these as `403` would send a client hunting for a
            // second problem it does not have.
            $report->add($theKindWasRefused ? 424 : 403, array_keys($properties));
        }

        return (new Response(403, body: $this->server->writer()->write($report->toElement())))
            ->withHeader('Content-Type', 'application/xml; charset=utf-8');
    }
}
