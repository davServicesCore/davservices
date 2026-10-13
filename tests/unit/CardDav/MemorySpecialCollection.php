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

namespace DavServices\Tests\Unit\CardDav;

use DavServices\Dav\IResourceType;
use DavServices\Tests\Unit\Dav\MemoryCollection;

/**
 * A collection that is already a kind of its own.
 *
 * `DAV:resourcetype` is a list rather than a label, so a collection can be an
 * address book **and** something an application invented. This double exists
 * to show that wrapping one does not take the other half away.
 *
 * **It reports two kinds, not one**, and that is the point of it: with a
 * single kind, a wrapper that kept only the first of the list it was given
 * would look right. A mutation run said so.
 */
final class MemorySpecialCollection extends MemoryCollection implements IResourceType
{
    public function resourceTypes(): array
    {
        return ['{http://example.com/ns}shared', '{http://example.com/ns}curated'];
    }
}
