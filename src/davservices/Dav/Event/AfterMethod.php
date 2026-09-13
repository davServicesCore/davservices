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
use DavServices\Http\Request;
use DavServices\Http\Response;

/**
 * Raised once the method has answered, with the answer.
 *
 * A listener may hand back another one, which is how a plugin adds a header to
 * everything a server sends. Unlike the event before the method, answering
 * here does not end the chain: several plugins each adding something is the
 * ordinary case, and each of them sees what the one before it did.
 */
final class AfterMethod extends Event
{
    public function __construct(
        private readonly Request $request,
        private Response $response,
    ) {
    }

    /**
     * The request as it arrived.
     */
    public function request(): Request
    {
        return $this->request;
    }

    /**
     * Replaces the answer with another.
     */
    public function answerWith(Response $response): void
    {
        $this->response = $response;
    }

    /**
     * The answer as it stands.
     */
    public function response(): Response
    {
        return $this->response;
    }
}
