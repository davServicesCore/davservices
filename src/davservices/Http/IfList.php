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

namespace DavServices\Http;

/**
 * One parenthesised list out of a WebDAV `If` header.
 *
 * Every condition in it has to hold — a list is an *and*. The header holds if
 * any one of its lists does, so the lists are an *or*.
 *
 * A list may name the resource it is about. Where it does not, it is about the
 * request target. The name is kept exactly as the client wrote it, since
 * resolving it needs the server's own idea of where it is mounted.
 */
final class IfList
{
    /**
     * @param list<IfCondition> $conditions
     *
     * @internal Built by the parser
     */
    public function __construct(
        private readonly ?string $resource,
        private readonly array $conditions,
    ) {
    }

    /**
     * The resource this list is about, or null for the request target.
     */
    public function resource(): ?string
    {
        return $this->resource;
    }

    /**
     * The conditions, every one of which has to hold.
     *
     * @return list<IfCondition>
     */
    public function conditions(): array
    {
        return $this->conditions;
    }
}
