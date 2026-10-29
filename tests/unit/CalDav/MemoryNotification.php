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

namespace DavServices\Tests\Unit\CalDav;

use DateTimeImmutable;
use DavServices\CalDav\INotification;
use DavServices\Dav\IFile;
use DavServices\Tests\Unit\Dav\IMember;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Xml\Element;

/**
 * A notification message that lives in an array.
 *
 * **It says its own type rather than having it read out of its body**, which
 * is the point of {@see INotification}: §4.2.1 of `caldav-sharing` wants a
 * client to be able to "quickly find notification messages that the client can
 * handle" through a `PROPFIND Depth:1`, and a store that parsed every document
 * to answer that would make the property the slow way to do what reading the
 * bodies already does.
 *
 * **The reference implementations are not for production** (R-BE-05).
 */
final class MemoryNotification implements IFile, IMember, INotification
{
    private ?MemoryCollection $parent = null;

    /**
     * @param string $name The member name inside the notification collection
     * @param Element $type The notification's type element, as it stands in
     *                      the document — attributes and children and all,
     *                      because what the property does with them is
     *                      {@see \DavServices\Plugin\Notifications}'s to
     *                      decide
     * @param string $body The document itself
     */
    public function __construct(
        private readonly string $name,
        private readonly Element $type,
        private readonly string $body = '<notification/>',
    ) {
    }

    public function attachTo(MemoryCollection $parent): void
    {
        $this->parent = $parent;
    }

    public function notificationType(): Element
    {
        return $this->type;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function lastModified(): ?DateTimeImmutable
    {
        return null;
    }

    public function delete(): void
    {
        $this->parent?->remove($this->name);
    }

    public function get(): string
    {
        return $this->body;
    }

    public function put(mixed $content): ?string
    {
        return null;
    }

    public function contentType(): string
    {
        return 'application/xml; charset=utf-8';
    }

    public function contentLength(): int
    {
        return strlen($this->body);
    }

    public function etag(): string
    {
        return '"' . md5($this->body) . '"';
    }
}
