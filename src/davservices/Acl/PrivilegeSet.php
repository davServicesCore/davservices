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

namespace DavServices\Acl;

use InvalidArgumentException;

/**
 * What a principal holds on one resource (RFC 3744 §5.4, R-PRIV-06).
 *
 * Everything that decides whether a request may go through asks this, and it
 * answers without the asker having to know the shape of the privilege tree.
 *
 * **Aggregation happens once, when the set is made.** Granting `DAV:write`
 * grants its four there and then, so `has()` is afterwards a lookup rather
 * than a walk. That is more than tidiness: a `PROPFIND` over two hundred
 * members asks this two hundred times.
 *
 * **What was granted and what it reaches are both kept**, because two
 * properties ask two different questions of the same set. `DAV:acl` reports
 * the entries as somebody wrote them; `DAV:current-user-privilege-set`
 * reports what they come to.
 *
 * **An empty set is "no access", and it is a proper answer** (R-PRIV-03): a
 * request from nobody in particular gets one, and every check reads it as a
 * refusal rather than as a missing answer.
 */
final class PrivilegeSet
{
    /**
     * @param list<string> $granted
     * @param list<string> $flattened
     */
    private function __construct(
        private readonly array $granted,
        private readonly array $flattened,
    ) {
    }

    /**
     * No access at all — what a request from nobody in particular holds
     * (R-PRIV-03), and what a resolver answers for a path it knows nothing
     * about (R-PRIV-04).
     */
    public static function nothing(): self
    {
        return new self([], []);
    }

    /**
     * The privileges these names come to, worked out against a tree.
     *
     * @throws InvalidArgumentException If a name is in no tree: a set holding
     *                                  a privilege this server does not grant
     *                                  would answer `has()` for it, and a
     *                                  request would go through on a
     *                                  permission nobody could have revoked —
     *                                  it being in no list, no report and no
     *                                  tree
     */
    public static function of(Privilege $tree, string ...$names): self
    {
        $granted = [];
        $flattened = [];

        foreach ($names as $name) {
            $privilege = $tree->find($name);

            if ($privilege === null) {
                throw new InvalidArgumentException(sprintf('No privilege "%s" is granted by this server.', $name));
            }

            self::addOnce($granted, $name);

            foreach ($privilege->flattened() as $reached) {
                self::addOnce($flattened, $reached);
            }
        }

        return new self($granted, $flattened);
    }

    /**
     * Is there no access at all?
     */
    public function isEmpty(): bool
    {
        return $this->flattened === [];
    }

    /**
     * Does this hold that privilege, whether it was granted by name or
     * reached through an aggregate?
     *
     * A name this server does not know is simply not held: a client may ask
     * whatever it likes, and the answer is no rather than an error.
     */
    public function has(string $name): bool
    {
        return in_array($name, $this->flattened, true);
    }

    /**
     * What was granted, as it was written — which is what `DAV:acl` reports.
     *
     * @return list<string>
     */
    public function granted(): array
    {
        return $this->granted;
    }

    /**
     * Everything that comes to, aggregates and all — which is what
     * `DAV:current-user-privilege-set` reports.
     *
     * @return list<string>
     */
    public function flattened(): array
    {
        return $this->flattened;
    }

    /**
     * Both sets together, each privilege named once.
     *
     * A principal may be in two groups that grant overlapping privileges, and
     * a set that named `DAV:read` twice would have
     * `DAV:current-user-privilege-set` say it twice.
     */
    public function merged(self $other): self
    {
        $granted = $this->granted;
        $flattened = $this->flattened;

        foreach ($other->granted as $name) {
            self::addOnce($granted, $name);
        }

        foreach ($other->flattened as $name) {
            self::addOnce($flattened, $name);
        }

        return new self($granted, $flattened);
    }

    /**
     * @param list<string> $names
     */
    private static function addOnce(array &$names, string $name): void
    {
        if (!in_array($name, $names, true)) {
            $names[] = $name;
        }
    }
}
