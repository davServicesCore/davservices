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

namespace DavServices\Dav;

use Closure;
use DavServices\Xml\Element;

/**
 * The changes of one `PROPPATCH`, while it is being decided who makes them.
 *
 * The rule that makes a `PROPPATCH` atomic lives here: **one failure fails
 * them all** (R-DAV-05, RFC 4918 §9.2). The property that was refused keeps
 * its own status, and every other one gets `424 Failed Dependency` — which
 * tells a client the thing it needs to know, that there is nothing wrong with
 * *those* and no point in retrying them on their own.
 *
 * Nobody writes while this is being filled in. A listener says what it *will*
 * write, or refuses outright, and the method decides afterwards whether
 * anything is written at all. That order is what keeps the second half of
 * R-DAV-05 — that a failed request stores nothing — rather than hoping every
 * storage can be wound back.
 *
 * The values never come back to the client (RFC 4918 §9.2). It sent them; an
 * answer that echoed them would be twice the size and say no more.
 */
final class PropPatchResult
{
    /** @var array<string, Closure(Element|string|null): void> */
    private array $writers = [];

    /** @var array<string, int> */
    private array $statuses = [];

    /**
     * @param array<string, Element|string|null> $mutations What is to become
     *                                                      of each property,
     *                                                      in the order the
     *                                                      request named them;
     *                                                      null removes one
     */
    public function __construct(
        private readonly string $path,
        private readonly array $mutations,
    ) {
    }

    /**
     * The path this is about.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Everything the request asks for.
     *
     * @return array<string, Element|string|null>
     */
    public function mutations(): array
    {
        return $this->mutations;
    }

    /**
     * What nobody has taken on or settled yet.
     *
     * This is what the node itself is handed, in one call, so that the storage
     * that holds most of them makes its changes together or not at all.
     *
     * @return array<string, Element|string|null>
     */
    public function open(): array
    {
        $open = [];

        foreach ($this->mutations as $name => $value) {
            if (!isset($this->writers[$name]) && !isset($this->statuses[$name])) {
                $open[$name] = $value;
            }
        }

        return $open;
    }

    /**
     * Takes a property on: this is who will write it, if anything is written.
     *
     * The first to say so owns it, as in a `PROPFIND`, so that the order of
     * the listeners decides and not the order they were loaded in.
     *
     * @param Closure(Element|string|null): void $writer
     */
    public function willWrite(string $name, Closure $writer): void
    {
        if (isset($this->writers[$name]) || isset($this->statuses[$name])) {
            return;
        }

        $this->writers[$name] = $writer;
    }

    /**
     * Who is to write what, in the order they took it on.
     *
     * @return array<string, Closure(Element|string|null): void>
     */
    public function writers(): array
    {
        return $this->writers;
    }

    /**
     * Settles one property: what became of it, or what is to become of it.
     */
    public function set(string $name, int $status): void
    {
        if (isset($this->statuses[$name])) {
            return;
        }

        $this->statuses[$name] = $status;
    }

    /**
     * Has anything been refused?
     *
     * While this is false, nothing has been written and nothing needs to be.
     */
    public function hasFailure(): bool
    {
        foreach ($this->statuses as $status) {
            if (!self::wentWell($status)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The answers grouped into the `propstat` blocks of the response.
     *
     * Every property of the request appears exactly once. Where anything
     * failed, everything that did not fail on its own account is `424`,
     * whether it was settled, taken on, or never got that far.
     *
     * @return array<int, array<string, null>>
     */
    public function byStatus(): array
    {
        $failed = $this->hasFailure();
        $byStatus = [];

        foreach (array_keys($this->mutations) as $name) {
            $status = $this->statuses[$name] ?? 424;

            if ($failed && self::wentWell($status)) {
                $status = 424;
            }

            $byStatus[$status][$name] = null;
        }

        return $byStatus;
    }

    /**
     * A `2xx` and nothing else means the property was dealt with. Saying that
     * in one place keeps the two readings of it from ever disagreeing.
     */
    private static function wentWell(int $status): bool
    {
        return intdiv($status, 100) === 2;
    }
}
