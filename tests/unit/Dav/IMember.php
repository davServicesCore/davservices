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

use DavServices\Dav\INode;

/**
 * A node of these tests that knows the collection it hangs in.
 *
 * The real thing knows nothing of the sort, and that is deliberate: a node
 * that does not know where it hangs can hang in two places at once. The
 * doubles need the way back all the same, because `delete()` has to make the
 * node disappear from its collection, and only the collection can do that.
 */
interface IMember extends INode
{
    /**
     * Tells the node which collection holds it.
     */
    public function attachTo(MemoryCollection $parent): void;
}
