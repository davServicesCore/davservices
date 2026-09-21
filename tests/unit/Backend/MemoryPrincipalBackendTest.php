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

namespace DavServices\Tests\Unit\Backend;

use DavServices\Backend\IPrincipalBackend;
use DavServices\Dav\Acl\PrincipalInfo;
use DavServices\Exception\Forbidden;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The contract, run against the principals kept in an array.
 *
 * This is the implementation the rest of P3 is tested against, and the
 * contract is what keeps a real one — a directory, a database — honest when
 * it arrives.
 */
#[CoversClass(PrincipalInfo::class)]
final class MemoryPrincipalBackendTest extends PrincipalBackendContract
{
    /**
     * A storage may refuse to be listed, and a deployment with a hundred
     * thousand users will. The refusal is the backend's to make, because only
     * it knows how many there are.
     */
    public function testAStorageMayRefuseToBeListed(): void
    {
        $backend = new MemoryPrincipalBackend();

        $backend->refuseListing();

        $this->expectException(Forbidden::class);

        $backend->principals();
    }

    protected function backend(): IPrincipalBackend
    {
        return new MemoryPrincipalBackend(
            new PrincipalInfo('alice', 'Alice Ashton', ['mailto:alice@example.test']),
            new PrincipalInfo('plain'),
        );
    }
}
