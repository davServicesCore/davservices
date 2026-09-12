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

namespace DavServices\Http;

/**
 * One entity tag, and the two ways HTTP compares them.
 *
 * A tag is opaque: it says nothing but "this is version X of that resource".
 * A weak one is marked `W/` and promises only that the representation is
 * equivalent, not that it is byte for byte the same (RFC 9110 §8.8.3).
 *
 * That distinction is the reason for two comparison functions rather than one.
 * `If-Match` compares strongly, because a client about to overwrite a resource
 * has to know it holds the exact bytes it thinks it holds; `If-None-Match`
 * compares weakly, because "you already have a good enough copy" is all that
 * question needs (§8.8.3.2).
 */
final class ETag
{
    private function __construct(
        private readonly string $opaque,
        private readonly bool $weak,
    ) {
    }

    /**
     * Reads one entity tag, or null where the value is no tag at all.
     *
     * The wildcard `*` is deliberately not a tag: it asks about the existence
     * of the resource rather than about a version of it, and the conditional
     * request handles it before it gets here.
     */
    public static function parse(string $value): ?self
    {
        $weak = str_starts_with($value, 'W/');
        $quoted = $weak ? substr($value, 2) : $value;

        if (preg_match('/^"[^"]*"$/', $quoted) !== 1) {
            return null;
        }

        return new self(substr($quoted, 1, -1), $weak);
    }

    /**
     * Reads a comma-separated list, dropping what cannot be read.
     *
     * An entry nobody can read is one the resource cannot match, and dropping
     * it leaves the condition to the entries that can be: a list of nothing
     * but rubbish matches nothing, which is the safe direction.
     *
     * @return list<self>
     */
    public static function parseList(string $value): array
    {
        $tags = [];

        foreach (explode(',', $value) as $entry) {
            $tag = self::parse(trim($entry, " \t"));

            if ($tag !== null) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    /**
     * Is this tag a weak one?
     */
    public function isWeak(): bool
    {
        return $this->weak;
    }

    /**
     * Both tags are strong and say the same thing (RFC 9110 §8.8.3.2).
     */
    public function matchesStrongly(self $other): bool
    {
        return !$this->weak && !$other->weak && $this->opaque === $other->opaque;
    }

    /**
     * Both tags say the same thing, whether or not either is weak.
     */
    public function matchesWeakly(self $other): bool
    {
        return $this->opaque === $other->opaque;
    }

    /**
     * The tag as it is written in a header field.
     */
    public function toString(): string
    {
        return ($this->weak ? 'W/' : '') . '"' . $this->opaque . '"';
    }
}
