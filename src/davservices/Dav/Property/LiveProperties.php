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

namespace DavServices\Dav\Property;

use DateTimeInterface;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\ICollection;
use DavServices\Dav\IFile;
use DavServices\Dav\INode;
use DavServices\Dav\IQuota;
use DavServices\Dav\PropFindResult;
use DavServices\Http\HttpDate;
use DavServices\Xml\Element;

/**
 * The properties the server works out for itself (R-PROP-01).
 *
 * No backend stores these: what kind of thing a node is, how long a file is,
 * when it last changed, how much of a quota is left. They arrive as a listener
 * on {@see PropertiesRequested}, so that `PROPFIND` knows nothing about them
 * and an application that wants none of them registers none:
 *
 *     $live = new LiveProperties();
 *     $server->events()->on(PropertiesRequested::class, $live(...));
 *
 * **What a backend cannot say is not invented.** A node that does not know its
 * own entity tag gets no `DAV:getetag`, and the `404` a client is then handed
 * is the truth. A made-up tag would be believed, and the file would go on
 * changing underneath a client that had been told it had not.
 *
 * **Nothing is worked out that nobody asked for.** On a `Depth: 1` listing of
 * two hundred members that would be two hundred pieces of work thrown away,
 * and an entity tag can mean reading the whole file to arrive at.
 *
 * Two properties of R-PROP-01 are deliberately absent. `DAV:creationdate` and
 * `DAV:displayname` cannot be worked out from a node at all — only the backend
 * knows them, and {@see \DavServices\Dav\IProperties} is how it says so.
 * Guessing a display name from a file name is the kind of helpfulness that
 * ends with `holiday.ics` shown to somebody who named the calendar something
 * else.
 */
final class LiveProperties
{
    private const RESOURCE_TYPE = '{DAV:}resourcetype';

    private const LAST_MODIFIED = '{DAV:}getlastmodified';

    private const CONTENT_LENGTH = '{DAV:}getcontentlength';

    private const CONTENT_TYPE = '{DAV:}getcontenttype';

    private const ENTITY_TAG = '{DAV:}getetag';

    private const QUOTA_USED = '{DAV:}quota-used-bytes';

    private const QUOTA_AVAILABLE = '{DAV:}quota-available-bytes';

    private const SUPPORTED_REPORTS = '{DAV:}supported-report-set';

    /**
     * Answers what this server can work out about the node.
     */
    public function __invoke(PropertiesRequested $event): void
    {
        $result = $event->result();
        $node = $event->node();

        $this->aboutEveryNode($result, $node);

        if ($node instanceof IFile) {
            $this->aboutAFile($result, $node);
        }

        if ($node instanceof IQuota) {
            $this->aboutAQuota($result, $node);
        }
    }

    private function aboutEveryNode(PropFindResult $result, INode $node): void
    {
        if ($result->wants(self::RESOURCE_TYPE)) {
            $result->set(self::RESOURCE_TYPE, self::resourceType($node));
        }

        if ($result->wants(self::LAST_MODIFIED)) {
            self::answer($result, self::LAST_MODIFIED, self::httpDate($node->lastModified()));
        }

        if ($result->wants(self::SUPPORTED_REPORTS)) {
            // A server with no reports supports none, and saying so is worth
            // more than leaving the property out: a client handed a `404`
            // cannot tell "none" from "this server does not know the
            // question". The reports arrive with `REPORT` and add themselves.
            $result->set(self::SUPPORTED_REPORTS, new Element(self::SUPPORTED_REPORTS));
        }
    }

    private function aboutAFile(PropFindResult $result, IFile $file): void
    {
        if ($result->wants(self::CONTENT_LENGTH)) {
            self::answer($result, self::CONTENT_LENGTH, self::number($file->contentLength()));
        }

        if ($result->wants(self::CONTENT_TYPE)) {
            self::answer($result, self::CONTENT_TYPE, $file->contentType());
        }

        if ($result->wants(self::ENTITY_TAG)) {
            self::answer($result, self::ENTITY_TAG, $file->etag());
        }
    }

    /**
     * RFC 4331: what is used, and what may still be used. A backend with no
     * limit set says how much is used and nothing about how much is left —
     * there is no number that means "as much as you like".
     */
    private function aboutAQuota(PropFindResult $result, IQuota $node): void
    {
        if ($result->wants(self::QUOTA_USED)) {
            $result->set(self::QUOTA_USED, (string) $node->quotaUsedBytes());
        }

        if ($result->wants(self::QUOTA_AVAILABLE)) {
            self::answer($result, self::QUOTA_AVAILABLE, self::number($node->quotaAvailableBytes()));
        }
    }

    /**
     * RFC 4918 §15.9: a resource that is no collection has an *empty*
     * `resourcetype` rather than none. The property is how a client tells the
     * two apart, and leaving it out leaves it guessing.
     */
    private static function resourceType(INode $node): Element
    {
        $type = new Element(self::RESOURCE_TYPE);

        if ($node instanceof ICollection) {
            $type->append(new Element('{DAV:}collection'));
        }

        return $type;
    }

    /**
     * Leaves the property out where the backend cannot say. Reporting it as
     * missing is the truth; inventing a value is a lie a client will believe.
     */
    private static function answer(PropFindResult $result, string $name, ?string $value): void
    {
        if ($value !== null) {
            $result->set($name, $value);
        }
    }

    private static function httpDate(?DateTimeInterface $moment): ?string
    {
        return $moment === null ? null : HttpDate::format($moment);
    }

    private static function number(?int $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
