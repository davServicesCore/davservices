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

use DavServices\Dav\IExtendedCollection;
use DavServices\Exception\Forbidden;
use DavServices\Exception\IHttpFailure;

/**
 * A collection that can make members of a kind other than its own.
 *
 * The backend half of the extended `MKCOL` of RFC 5689: it keeps what it was
 * asked for, so that the tests can show the request reached it whole rather
 * than being flattened into a plain collection on the way.
 */
final class MemoryExtendedCollection extends MemoryCollection implements IExtendedCollection
{
    /**
     * The resource types of the last request that got this far.
     *
     * @var list<string>
     */
    public array $resourceTypesAsked = [];

    /**
     * The properties of the last request that got this far.
     *
     * @var array<string, mixed>
     */
    public array $propertiesAsked = [];

    /** Set where making one is to be refused. */
    private ?IHttpFailure $creationRefusal = null;

    public function createExtendedCollection(string $name, array $resourceTypes, array $properties): void
    {
        if ($this->creationRefusal !== null) {
            throw $this->creationRefusal;
        }

        $this->resourceTypesAsked = $resourceTypes;
        $this->propertiesAsked = $properties;

        $this->add(new MemoryCollection($name));
    }

    /**
     * Refuses to make one, as a backend does that will not have that kind.
     */
    public function refuseCreation(?IHttpFailure $refusal = null): self
    {
        $this->creationRefusal = $refusal ?? new Forbidden('That kind is not made here.');

        return $this;
    }
}
