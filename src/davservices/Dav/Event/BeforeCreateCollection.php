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

namespace DavServices\Dav\Event;

use DavServices\Event\Event;

/**
 * Raised before a collection is created, whatever kind was asked for.
 *
 * A listener refuses by throwing, and the refusal becomes the answer: a naming
 * policy, a quota on how many collections an account may have, a plugin that
 * will not have address books made anywhere but in one place.
 *
 * It carries the resource types because that is what such a decision turns on.
 * A plugin told only that *a* collection is being made could do nothing but
 * refuse every one of them alike.
 */
final class BeforeCreateCollection extends Event
{
    /**
     * @param list<string> $resourceTypes The `DAV:resourcetype` children as
     *                                    `{namespace}localname`; a plain
     *                                    collection is `{DAV:}collection`
     *                                    alone
     */
    public function __construct(
        private readonly string $path,
        private readonly array $resourceTypes,
    ) {
    }

    /**
     * The path the collection is to be created at.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * The kind of collection that was asked for.
     *
     * @return list<string>
     */
    public function resourceTypes(): array
    {
        return $this->resourceTypes;
    }
}
