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

namespace DavServices\Dav\Collation;

use RuntimeException;

/**
 * A client asked for a collation this server has not got.
 *
 * **Not an HTTP failure, and deliberately not one.** RFC 6352 §8.3 says what
 * the answer has to be — "If the client chooses a collation not supported by
 * the server, the server MUST respond with a CARDDAV:supported-collation
 * precondition error response" — and that element belongs to CardDAV, a layer
 * above this one. A `Dav` class may not reach up into it.
 *
 * So this carries the identifier that was asked for, and the report that
 * caught it writes the precondition. The same arrangement as
 * {@see \DavServices\VObject\Recur\TooManyIterations}: the layer that knows the
 * protocol word phrases the refusal, and the layer that found the problem says
 * only what the problem was.
 *
 * **The identifier is in the message and in the accessor both.** A log reader
 * wants to see what a client sent; the layer above wants it as a value, to put
 * in an answer.
 */
final class UnknownCollation extends RuntimeException
{
    public function __construct(private readonly string $identifier)
    {
        parent::__construct(sprintf('This server has no collation called "%s" (RFC 6352 §8.3).', $identifier));
    }

    /**
     * What the client asked for.
     */
    public function identifier(): string
    {
        return $this->identifier;
    }
}
