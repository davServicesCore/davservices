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

use DavServices\CalDav\Calendar;
use DavServices\CalDav\IShareeHome;
use DavServices\Dav\ICollection;
use DavServices\Dav\INode;
use DavServices\Tests\Unit\Dav\MemoryCollection;

/**
 * A calendar home that can take a shared calendar into itself.
 *
 * §5.4.1 of `caldav-sharing`: the sharee replies "by issuing a POST request to
 * the sharee's calendar home collection resource", and an acceptance leaves
 * behind "the URI of the shared calendar in the sharee's calendar home created
 * by the invite acceptance".
 *
 * What it makes is a {@see MemorySharedWithMe}, because that is what the copy
 * is: a calendar that knows where the original is.
 */
final class MemoryShareeHome extends MemoryCollection implements IShareeHome
{
    public function acceptShareOf(string $hostUrl): string
    {
        $name = 'shared-' . (string) (count($this->children()) + 1);

        $this->add(new MemorySharedWithMe($name, $hostUrl));

        return $name;
    }

    /**
     * **A calendar home hands out calendars**, which is what makes the copy
     * one: §5.2's properties are a calendar collection's, and so is §5.4.2's
     * removal. The store underneath is what is kept, so that deleting the
     * copy reaches the member of this collection.
     */
    public function child(string $name): INode
    {
        $child = parent::child($name);

        return $child instanceof ICollection ? Calendar::of($child) : $child;
    }

    /**
     * @return list<INode>
     */
    public function children(): array
    {
        return array_map(
            static fn (INode $child): INode => $child instanceof ICollection ? Calendar::of($child) : $child,
            parent::children(),
        );
    }
}
