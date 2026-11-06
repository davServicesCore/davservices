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

namespace DavServices\Tests\Unit\Backend;

use DavServices\Backend\ICalendarUserBackend;

/**
 * A directory of calendar users held in an array.
 *
 * **Both directions from one table**, which is the point of the interface: a
 * double that kept two would be able to disagree with itself, and so would a
 * deployment that wired two callables.
 */
final class MemoryCalendarUserBackend implements ICalendarUserBackend
{
    /**
     * @param array<string, list<string>> $users The addresses of each
     *                                           principal, by name
     * @param string $principalsAt Where this double pretends the principals
     *                             are mounted, so that it can answer with a
     *                             URL as the interface asks
     */
    public function __construct(
        private readonly array $users,
        private readonly string $principalsAt = '/principals/',
    ) {
    }

    /**
     * @return list<string>
     */
    public function addressesOf(string $principal): array
    {
        return $this->users[$principal] ?? [];
    }

    public function principalFor(string $address): ?string
    {
        foreach ($this->users as $principal => $addresses) {
            if (in_array($address, $addresses, true)) {
                return $this->principalsAt . $principal . '/';
            }
        }

        return null;
    }
}
