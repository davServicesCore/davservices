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

/**
 * Raised while an `OPTIONS` request is being answered.
 *
 * This is where a plugin says what it makes the server capable of. The lock
 * plugin names class `2`, access control names `3` and `access-control`,
 * CalDAV names `calendar-access` — and a server without them names none of it
 * (R-HTTP-11).
 *
 * That matters more than it looks. A client reads this answer as a promise: no
 * client offers to lock a resource on a server whose `DAV` header does not say
 * `2`, and one that finds `2` there and gets a `501` for its `LOCK` is a client
 * with a problem nobody can debug from the outside. A compliance class claimed
 * by a plugin that is registered is a promise the server can keep.
 */
final class OptionsRequested extends Event
{
    /** @var list<string> */
    private array $compliance = [];

    /** @var list<string> */
    private array $methods = [];

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
     * Names one or more compliance classes this server keeps to.
     *
     * A class two plugins both name is listed once: several of them may rest
     * on the same one, and a header that said so twice would be a header no
     * client had seen before.
     */
    public function addCompliance(string ...$classes): void
    {
        foreach ($classes as $class) {
            if (!in_array($class, $this->compliance, true)) {
                $this->compliance[] = $class;
            }
        }
    }

    /**
     * Names one or more methods the server answers.
     *
     * For a plugin that answers a method on the event before it rather than
     * with a handler of its own — which is how the sharing protocol takes over
     * a `POST`, and how it would otherwise be left out of a list clients read
     * as the truth.
     */
    public function allowMethod(string ...$methods): void
    {
        foreach ($methods as $method) {
            if (!in_array($method, $this->methods, true)) {
                $this->methods[] = $method;
            }
        }
    }

    /**
     * The compliance classes, in the order they were named.
     *
     * @return list<string>
     */
    public function compliance(): array
    {
        return $this->compliance;
    }

    /**
     * The methods, in the order they were named.
     *
     * @return list<string>
     */
    public function methods(): array
    {
        return $this->methods;
    }
}
