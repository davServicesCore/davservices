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
use PHPUnit\Framework\TestCase;

/**
 * What every principal storage has to do, whatever it keeps its people in
 * (RFC 3744 §2, R-ACL-01).
 *
 * Written once and run against each implementation, because a backend that
 * is exchangeable is only exchangeable if they all behave the same — and
 * access control asks these questions about every request. A backend that
 * answered "no such principal" where another said yes would hand out
 * different permissions for the same account.
 *
 * **A name nobody has is `null`, not an error.** A path that names no
 * principal is a `404`, and that is the server's word to say; a backend that
 * threw would turn every mistyped URL into a `500`.
 */
abstract class PrincipalBackendContract extends TestCase
{
    public function testHandsBackAPrincipalByName(): void
    {
        $principal = $this->backend()->principal('alice');

        self::assertInstanceOf(PrincipalInfo::class, $principal);
        self::assertSame('alice', $principal->name());
    }

    public function testKnowsNothingOfANameNobodyHas(): void
    {
        self::assertNull($this->backend()->principal('nobody'));
    }

    /**
     * RFC 3744 §4.1: the display name is what a person is called, and it is
     * kept as the backend gave it — a server that invented one from a login
     * would show `a.mueller` to people as though somebody had chosen it.
     */
    public function testKeepsWhatAPersonIsCalled(): void
    {
        self::assertSame('Alice Ashton', $this->backend()->principal('alice')?->displayName());
    }

    public function testAPrincipalNeedNotBeCalledAnything(): void
    {
        self::assertNull($this->backend()->principal('plain')?->displayName());
    }

    /**
     * RFC 3744 §4.1: the alternate URIs are the other ways to reach the same
     * actor, and `mailto:` is the one every calendar client cares about —
     * scheduling finds a person by their address, not by their path.
     */
    public function testKeepsTheOtherWaysToReachThem(): void
    {
        self::assertSame(
            ['mailto:alice@example.test'],
            $this->backend()->principal('alice')?->alternateUris(),
        );
    }

    public function testAPrincipalNeedHaveNoOtherAddress(): void
    {
        self::assertSame([], $this->backend()->principal('plain')?->alternateUris());
    }

    public function testHandsBackEveryPrincipalItHas(): void
    {
        $names = array_map(
            static fn (PrincipalInfo $principal): string => $principal->name(),
            $this->backend()->principals(),
        );

        sort($names);

        self::assertSame(['alice', 'plain'], $names);
    }

    /**
     * The storage under test, holding Alice — who is called something and has
     * an address — and one plain principal that is nothing but a name.
     */
    abstract protected function backend(): IPrincipalBackend;
}
