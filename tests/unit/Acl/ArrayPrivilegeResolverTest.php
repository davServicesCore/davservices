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

namespace DavServices\Tests\Unit\Acl;

use DavServices\Acl\IPrivilegeResolver;

/**
 * The contract, run against the resolver the tests keep their rules in.
 *
 * It proves the contract can be satisfied, which is worth having on its own:
 * a contract nobody passes is a contract nobody has read carefully. And it is
 * what {@see MemoizingPrivilegeResolverTest} wraps, so a failure here is a
 * failure there as well.
 */
final class ArrayPrivilegeResolverTest extends PrivilegeResolverContract
{
    private ?ArrayPrivilegeResolver $resolver = null;

    protected function setUp(): void
    {
        $members = [];

        for ($member = 0; $member < 10; $member++) {
            $members[] = sprintf('calendars/member-%d', $member);
        }

        $this->resolver = ArrayPrivilegeResolver::asTheContractExpects($members);
    }

    protected function resolver(): IPrivilegeResolver
    {
        return $this->resolver ?? self::fail('The test has no resolver.');
    }

    /**
     * @param callable(IPrivilegeResolver): mixed $work
     */
    protected function askings(callable $work): int
    {
        $resolver = $this->resolver ?? self::fail('The test has no resolver.');
        $before = $resolver->lookups;

        $work($resolver);

        return $resolver->lookups - $before;
    }
}
