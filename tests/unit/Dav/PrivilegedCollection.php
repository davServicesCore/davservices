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

namespace DavServices\Tests\Unit\Dav;

use DavServices\Acl\IPrivileges;
use DavServices\Acl\Privilege;

/**
 * A collection that defines a privilege of its own, so that RFC 3744 §5.3's
 * "the privileges defined **for the resource**" can be told from the tree the
 * server grants everywhere.
 *
 * A double rather than a scheduling collection on purpose: the `Acl` plugin
 * must answer this for **any** node that says it has something of its own,
 * and a test that used an Inbox would be measuring whether CalDAV happens to
 * be loaded.
 */
final class PrivilegedCollection extends MemoryCollection implements IPrivileges
{
    public function privilegesDefinedHere(Privilege $everywhere): Privilege
    {
        return $everywhere->with(
            '{DAV:}all',
            new Privilege('{https://dav.services/test}own-one', 'what only this collection grants'),
        );
    }
}
