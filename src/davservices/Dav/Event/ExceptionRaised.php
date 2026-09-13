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
use Throwable;

/**
 * Raised when something went wrong while a request was being answered.
 *
 * Two reasons it exists. Something has to be able to write the failure down,
 * and the library holds no opinion about logging — that belongs to whatever
 * embeds it. And a plugin may know better than the server what a failure of
 * its own backend means: a connection that timed out is a `502`, a quota that
 * ran out is a `507`, and neither is anything the server could work out.
 *
 * A listener that answers is believed. One that fails while answering is not
 * allowed to take the server with it: the client is still owed an answer to
 * the failure that came first.
 */
final class ExceptionRaised extends Event
{
    private ?Response $response = null;

    public function __construct(
        private readonly Request $request,
        private readonly Throwable $failure,
    ) {
    }

    /**
     * The request that was being answered.
     */
    public function request(): Request
    {
        return $this->request;
    }

    /**
     * What went wrong.
     */
    public function failure(): Throwable
    {
        return $this->failure;
    }

    /**
     * Answers the failure here, instead of the server.
     */
    public function answerWith(Response $response): void
    {
        $this->response = $response;
    }

    /**
     * What a listener answered with, or null where none has.
     */
    public function response(): ?Response
    {
        return $this->response;
    }
}
