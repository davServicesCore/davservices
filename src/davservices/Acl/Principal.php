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

namespace DavServices\Acl;

use DateTimeImmutable;
use DavServices\Dav\Acl\PrincipalInfo;
use DavServices\Dav\INode;
use DavServices\Dav\IProperties;
use DavServices\Dav\IResourceType;
use DavServices\Exception\Forbidden;
use DavServices\Xml\Element;

/**
 * One principal, as a resource in the tree (RFC 3744 §4).
 *
 * **That a principal is addressable at all is the idea of RFC 3744.** Rather
 * than a private notion of users, the people a server knows have URLs, and an
 * access control entry points at one of them. A `PROPFIND` on this answers
 * what the person is called and how else to reach them.
 *
 * Two properties are answered here and one deliberately is not.
 * `DAV:displayname` and `DAV:alternate-URI-set` came from the backend with
 * the principal, so the node has them. **`DAV:principal-URL` it cannot
 * know**: a node knows its name and nothing about where it hangs
 * ({@see INode}), and a node that guessed at its own URL would send clients
 * to a place that does not answer. {@see \DavServices\Plugin\Principals}
 * answers that one, where the path is known.
 *
 * **Nothing here is written through WebDAV.** Who exists is the application's
 * business — a directory, an identity provider, a table somebody else owns —
 * and a `PROPPATCH` that appeared to rename somebody while the directory kept
 * the old name would be worse than a refusal.
 */
final class Principal implements INode, IProperties, IResourceType
{
    private const DISPLAY_NAME = '{DAV:}displayname';

    private const ALTERNATE_URIS = '{DAV:}alternate-URI-set';

    public function __construct(private readonly PrincipalInfo $principal)
    {
    }

    /**
     * The member name inside the principal collection.
     */
    public function name(): string
    {
        return $this->principal->name();
    }

    /**
     * The groups this principal is **directly** in (RFC 3744 §4.4), by their
     * member names.
     *
     * **The node hands over names, not URLs**, for the same reason it cannot
     * answer `DAV:principal-URL`: a node knows its name and nothing about
     * where it hangs. {@see \DavServices\Plugin\Principals} turns these into
     * hrefs, where the path is known.
     *
     * @return list<string>
     */
    public function memberOf(): array
    {
        return $this->principal->memberOf();
    }

    /**
     * The principals **directly** in this group (§4.3), or null where this
     * server does not say — which §4.3 allows, being the one property of §4
     * that need not be supported at all.
     *
     * @return list<string>|null
     */
    public function members(): ?array
    {
        return $this->principal->members();
    }

    /**
     * Nothing: a backend that cannot say when a principal last changed says
     * so rather than guessing, because an invented time is handed out as
     * `DAV:getlastmodified` and cached by clients.
     */
    public function lastModified(): ?DateTimeImmutable
    {
        return null;
    }

    /**
     * Refuses. Who exists is the application's business, and a principal that
     * vanished from WebDAV while the directory kept it would be a person the
     * server denies and the directory still knows.
     *
     * @throws Forbidden Always
     */
    public function delete(): void
    {
        throw new Forbidden('A principal is not removed through WebDAV.');
    }

    /**
     * RFC 3744 §4: a principal is of `DAV:principal` resource type, which is
     * how a client tells one from any other resource.
     *
     * @return list<string>
     */
    public function resourceTypes(): array
    {
        return ['{DAV:}principal'];
    }

    /**
     * What this principal has, which is what it was given.
     *
     * A principal nobody named offers no `DAV:displayname`: the list is what
     * there is rather than what there could be.
     *
     * @return list<string>
     */
    public function propertyNames(): array
    {
        return $this->principal->displayName() === null
            ? [self::ALTERNATE_URIS]
            : [self::DISPLAY_NAME, self::ALTERNATE_URIS];
    }

    /**
     * What it was given, for the names that were asked about.
     *
     * A principal nobody named is **left out** rather than answered with
     * null: null is a value a property may hold, and "nothing here" is a
     * different answer from "this is empty".
     *
     * @param list<string> $names
     *
     * @return array<string, Element|string|null>
     */
    public function properties(array $names): array
    {
        $answers = [];
        $displayName = $this->principal->displayName();

        if ($displayName !== null && in_array(self::DISPLAY_NAME, $names, true)) {
            $answers[self::DISPLAY_NAME] = $displayName;
        }

        if (in_array(self::ALTERNATE_URIS, $names, true)) {
            $answers[self::ALTERNATE_URIS] = $this->alternateUris();
        }

        return $answers;
    }

    /**
     * Refuses every change, and says so for each property rather than
     * throwing: `PROPPATCH` reports per property, and a client is owed the
     * name of what it may not change.
     *
     * @param array<string, Element|string|null> $mutations
     *
     * @return array<string, int>
     */
    public function patchProperties(array $mutations): array
    {
        return array_map(static fn (): int => 403, $mutations);
    }

    /**
     * RFC 3744 §4.1, as hrefs. Empty where there is no other address — which
     * says "none", while a missing property would say "this server does not
     * know the question".
     */
    private function alternateUris(): Element
    {
        $set = new Element(self::ALTERNATE_URIS);

        foreach ($this->principal->alternateUris() as $uri) {
            $href = new Element('{DAV:}href');

            $href->appendText($uri);
            $set->append($href);
        }

        return $set;
    }
}
