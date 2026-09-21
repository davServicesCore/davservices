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

/**
 * Remembers, for the length of one request, what another resolver said
 * (R-PRIV-02).
 *
 * Wrapped round whatever an application wrote:
 *
 *     $resolver = new MemoizingPrivilegeResolver($yourResolver);
 *
 * Rather than ask every implementation to keep its own cache and get it
 * right, this does it once. A `PROPFIND` asks about a collection and then
 * about each of its members, and the collection comes up again in the second
 * half; a `COPY` asks about both ends and then about the members of the
 * source. The repetition is in the protocol, not in any one caller.
 *
 * **An answer belongs to a principal and a path together.** A cache keyed by
 * path alone would hand one person's privileges to the next, and every check
 * above it would let the writes through without a word. That is the whole
 * reason this is a class rather than an array somewhere.
 *
 * **A batch fills the cache for everything it asked about**, and asks only
 * about what is not in it yet. Otherwise the single questions that follow
 * repeat the work the batch just did, and the N+1 that `forPaths()` exists to
 * prevent arrives one step later.
 *
 * It is a cache for **one request**. Nothing here expires, because nothing
 * here outlives the answer being sent: a resolver that remembered across
 * requests would go on granting what an administrator had just taken away.
 */
final class MemoizingPrivilegeResolver implements IPrivilegeResolver
{
    /** @var array<string, PrivilegeSet> */
    private array $remembered = [];

    public function __construct(private readonly IPrivilegeResolver $resolver)
    {
    }

    /**
     * What the one behind this said, asked once.
     */
    public function forPath(?string $principalUri, string $path): PrivilegeSet
    {
        $key = self::keyFor($principalUri, $path);

        return $this->remembered[$key] ??= $this->resolver->forPath($principalUri, $path);
    }

    /**
     * The same for several paths: one question about whatever is not known
     * yet, and nothing at all where everything is.
     *
     * @param list<string> $paths
     *
     * @return array<string, PrivilegeSet>
     */
    public function forPaths(?string $principalUri, array $paths): array
    {
        $wanted = [];

        foreach ($paths as $path) {
            if (!isset($this->remembered[self::keyFor($principalUri, $path)])) {
                $wanted[] = $path;
            }
        }

        if ($wanted !== []) {
            foreach ($this->resolver->forPaths($principalUri, $wanted) as $path => $set) {
                $this->remembered[self::keyFor($principalUri, $path)] = $set;
            }
        }

        $answers = [];

        foreach ($paths as $path) {
            // Every path asked about appears, even one the resolver left out
            // of its answer: a caller may not have to tell "no access" from
            // "no answer" (R-PRIV-04).
            $answers[$path] = $this->remembered[self::keyFor($principalUri, $path)] ?? PrivilegeSet::nothing();
        }

        return $answers;
    }

    /**
     * Passed straight through. `DAV:acl` is asked once in a request, so
     * remembering it would mean keeping a second cache for a question nobody
     * repeats.
     *
     * @return array<string, PrivilegeSet>
     */
    public function principalsForPath(string $path): array
    {
        return $this->resolver->principalsForPath($path);
    }

    /**
     * A principal and a path together, and told apart from each other: a
     * principal whose URI ends in a slash and a path that begins with one
     * must not come to the same key as some other pair.
     */
    private static function keyFor(?string $principalUri, string $path): string
    {
        return sprintf('%s%s%s', $principalUri ?? '', "\0", $path);
    }
}
