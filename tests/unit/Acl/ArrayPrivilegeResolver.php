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
use DavServices\Acl\Privilege;
use DavServices\Acl\PrivilegeSet;

/**
 * A resolver that keeps its rules in an array, for the tests.
 *
 * It stands in for the thing an application would write against a directory
 * or a database, and it **counts how often it was consulted** — which is what
 * lets the contract catch a `forPaths()` that asks one path at a time
 * (R-PRIV-01).
 *
 * The counting is of *lookups into the rules*, not of method calls: that is
 * the shape the count has in a real implementation, where one query fetches
 * many paths and a loop fetches one each.
 */
final class ArrayPrivilegeResolver implements IPrivilegeResolver
{
    public int $lookups = 0;

    private readonly Privilege $tree;

    /**
     * @param array<string, array<string, list<string>>> $rules Keyed by
     *                                                          principal URI,
     *                                                          then by path
     */
    public function __construct(private readonly array $rules)
    {
        $this->tree = Privilege::standard();
    }

    /**
     * What this test's rules say Alice and Bob may do, which is what the
     * contract expects to find.
     *
     * @param list<string> $members Extra member paths Alice may write to, so
     *                              that a batch can be asked about many
     */
    public static function asTheContractExpects(array $members = []): self
    {
        $alice = ['calendars/work' => ['{DAV:}read', '{DAV:}write']];

        foreach ($members as $member) {
            $alice[$member] = ['{DAV:}read', '{DAV:}write'];
        }

        return new self([
            '/principals/alice' => $alice,
            '/principals/bob' => ['calendars/work' => ['{DAV:}read']],
        ]);
    }

    public function forPath(?string $principalUri, string $path): PrivilegeSet
    {
        $this->lookups++;

        return $this->granted($principalUri, $path);
    }

    /**
     * @param list<string> $paths
     *
     * @return array<string, PrivilegeSet>
     */
    public function forPaths(?string $principalUri, array $paths): array
    {
        // One lookup for all of them, which is what a real implementation
        // does with one query — and what R-PRIV-01 is about.
        $this->lookups++;

        $answers = [];

        foreach ($paths as $path) {
            $answers[$path] = $this->granted($principalUri, $path);
        }

        return $answers;
    }

    /**
     * @return array<string, PrivilegeSet>
     */
    public function principalsForPath(string $path): array
    {
        $this->lookups++;

        $holders = [];

        foreach ($this->rules as $principal => $paths) {
            if (($paths[$path] ?? []) !== []) {
                $holders[$principal] = PrivilegeSet::of($this->tree, ...$paths[$path]);
            }
        }

        return $holders;
    }

    private function granted(?string $principalUri, string $path): PrivilegeSet
    {
        if ($principalUri === null) {
            return PrivilegeSet::nothing();
        }

        $names = $this->rules[$principalUri][$path] ?? [];

        return $names === [] ? PrivilegeSet::nothing() : PrivilegeSet::of($this->tree, ...$names);
    }
}
