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

use DavServices\Backend\IAuthBackend;

/**
 * Credentials kept in an array, for the tests of the layers above.
 *
 * **It does the same work either way**, which is what the contract asks of a
 * real one: every user-id is looked up, every password compared with
 * `hash_equals()`, and an unknown user-id is compared against a placeholder
 * so that it costs what a known one costs. A double that returned early
 * would be a double that could not demonstrate the rule it exists to keep.
 */
final class MemoryAuthBackend implements IAuthBackend
{
    /** How often it was asked, so a test can show the answer was remembered. */
    public int $asked = 0;

    /**
     * @param array<string, array{string, string}> $people Keyed by user-id,
     *                                                     each the password
     *                                                     and the principal
     *                                                     name
     */
    public function __construct(private readonly array $people = [])
    {
    }

    public function principalFor(string $userId, string $password): ?string
    {
        ++$this->asked;

        [$kept, $principal] = $this->people[$userId] ?? ['', null];

        // Compared even where there is nobody, so that an unknown user-id
        // costs what a known one costs.
        $matches = hash_equals($kept, $password);

        return $matches && $principal !== null ? $principal : null;
    }
}
