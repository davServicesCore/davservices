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
 * Raised while an `If` header is being evaluated: which state tokens is this
 * resource in the state of? (RFC 4918 §10.4.)
 *
 * **The seam that lets the core hold a client to its own conditions without
 * knowing what a lock is.** A state token is whatever a plugin says a
 * resource is in the state of; today only the lock plugin answers, and
 * anything else that hands tokens out may answer tomorrow.
 *
 * That is why this is an event rather than a question to a backend. A server
 * built without the lock plugin still owes a client the `412` its `If` header
 * asked for — an entity tag needs no locking at all — and it has to manage
 * that without a line about locking in it (R-ARC-02).
 */
final class StateTokensRequested extends Event
{
    /** @var list<string> */
    private array $tokens = [];

    public function __construct(private readonly string $path)
    {
    }

    /**
     * The path being asked about, inside the tree.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Names one or more tokens this resource is in the state of.
     *
     * A token named twice is one token: two listeners may know of the same
     * hold, and a condition is satisfied by a token being there rather than
     * by how often it was mentioned.
     */
    public function add(string ...$tokens): void
    {
        foreach ($tokens as $token) {
            if (!in_array($token, $this->tokens, true)) {
                $this->tokens[] = $token;
            }
        }
    }

    /**
     * The tokens, in the order they were named.
     *
     * @return list<string>
     */
    public function tokens(): array
    {
        return $this->tokens;
    }
}
