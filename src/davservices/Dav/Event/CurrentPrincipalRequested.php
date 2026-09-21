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
use RuntimeException;

/**
 * Raised when something needs to know **who is asking** (RFC 5397,
 * RFC 3744 §5.4).
 *
 * More than one part of this library needs the answer: `current-user-principal`
 * says who you are, `current-user-privilege-set` says what you may do, and
 * the access checks decide whether you may do it. **Asking each of them to
 * keep its own idea of who is signed in would be asking them to disagree** —
 * and a server whose two answers disagree about identity is one that shows
 * one person's calendar under another person's name.
 *
 * So there is one question and one place to answer it. An application wires
 * its own answer; a plugin that authenticates answers it instead.
 *
 * **Nobody answering is a proper answer.** A request from somebody who has
 * not signed in is answered `DAV:unauthenticated` and holds nothing
 * (R-PRIV-03), which is what every check reads as a refusal.
 *
 * **Two listeners naming different principals is a mistake in the wiring**,
 * and it is said out loud rather than settled by whichever happened to be
 * registered first: of all the things to decide by accident of order, who
 * somebody is would be the worst.
 */
final class CurrentPrincipalRequested extends Event
{
    private ?string $principal = null;

    public function __construct(private readonly Request $request)
    {
    }

    /**
     * The request being answered, which is what a session cookie or an
     * `Authorization` header arrives in.
     */
    public function request(): Request
    {
        return $this->request;
    }

    /**
     * Names whoever is asking, as the path of their principal inside the
     * tree.
     *
     * @throws RuntimeException If somebody has already named a different
     *                          principal
     */
    public function answerWith(string $principalPath): void
    {
        if ($this->principal !== null && $this->principal !== $principalPath) {
            throw new RuntimeException(sprintf(
                'Two listeners disagree about who is asking: "%s" and "%s".',
                $this->principal,
                $principalPath,
            ));
        }

        $this->principal = $principalPath;
    }

    /**
     * Whoever is asking, or null where nobody has said — which means nobody
     * is signed in.
     */
    public function principal(): ?string
    {
        return $this->principal;
    }
}
