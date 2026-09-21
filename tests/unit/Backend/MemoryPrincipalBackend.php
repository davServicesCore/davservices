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

/**
 * Principals in an array, for the tests.
 *
 * Unlike a lock, a principal may live in memory without lying: it is read
 * far more often than written, and an application that has its people
 * somewhere else entirely — a directory, an identity provider — hands them
 * over exactly like this.
 */
final class MemoryPrincipalBackend implements IPrincipalBackend
{
    /** @var array<string, PrincipalInfo> */
    private array $principals = [];

    private bool $refusesListing = false;

    public function __construct(PrincipalInfo ...$principals)
    {
        foreach ($principals as $principal) {
            $this->principals[$principal->name()] = $principal;
        }
    }

    /**
     * A deployment with a hundred thousand users would not be listed, and a
     * test has to be able to stand in for one.
     */
    public function refuseListing(): void
    {
        $this->refusesListing = true;
    }

    public function principal(string $name): ?PrincipalInfo
    {
        return $this->principals[$name] ?? null;
    }

    /**
     * @return list<PrincipalInfo>
     */
    public function principals(): array
    {
        if ($this->refusesListing) {
            throw new Forbidden('This storage will not be listed.');
        }

        return array_values($this->principals);
    }
}
