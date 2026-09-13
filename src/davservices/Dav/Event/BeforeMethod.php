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
 * Raised before the method of a request is run.
 *
 * This is where a plugin takes a method over: it answers, and the server's own
 * handler is never asked. Authentication answers `401` here, and the sharing
 * protocol answers a `POST` it recognises.
 *
 * Answering and stopping are two different things. Answering says what the
 * client gets; stopping only keeps the listeners behind this one from running.
 * A plugin that merely wants to be last stops without answering, and the
 * method runs as it would have.
 */
final class BeforeMethod extends Event
{
    private ?Response $response = null;

    public function __construct(private readonly Request $request)
    {
    }

    /**
     * The request as it arrived.
     */
    public function request(): Request
    {
        return $this->request;
    }

    /**
     * Answers the request here, instead of the method.
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
