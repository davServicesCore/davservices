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
 * One condition out of a WebDAV `If` header (RFC 4918 §10.4).
 *
 * Either a state token — in practice a lock token the client is submitting —
 * or an entity tag, and either of them may be negated by `Not`.
 *
 * Whether the condition holds is not decided here: that needs the lock backend
 * and the resource, and it belongs to the lock plugin.
 */
final class IfCondition
{
    private function __construct(
        private readonly bool $negated,
        private readonly ?string $stateToken,
        private readonly ?ETag $etag,
    ) {
    }

    /**
     * A condition on a state token, such as a lock token.
     *
     * @internal Built by the parser
     */
    public static function onStateToken(string $token, bool $negated): self
    {
        return new self($negated, $token, null);
    }

    /**
     * A condition on an entity tag.
     *
     * @internal Built by the parser
     */
    public static function onETag(ETag $etag, bool $negated): self
    {
        return new self($negated, null, $etag);
    }

    /**
     * Does the condition have to be false rather than true?
     */
    public function isNegated(): bool
    {
        return $this->negated;
    }

    /**
     * The state token, or null where the condition is about an entity tag.
     */
    public function stateToken(): ?string
    {
        return $this->stateToken;
    }

    /**
     * The entity tag, or null where the condition is about a state token.
     */
    public function etag(): ?ETag
    {
        return $this->etag;
    }
}
