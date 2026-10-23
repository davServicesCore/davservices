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

namespace DavServices\Sync;

use Stringable;

/**
 * The state of one collection at one moment (RFC 6578 §3.2, R-SYNC-02).
 *
 * > The token defines the state of the data being synchronized at a particular
 * > point in time. It can then be used to determine what has changed between
 * > one point in time and another.
 *
 * A server hands one out with every `DAV:sync-collection` report and with the
 * `DAV:sync-token` property; a client hands it back to ask what has changed
 * since, and may put it in an `If` header to make a write conditional on
 * nothing having changed (§5).
 *
 * ## The three promises R-SYNC-02 makes, and where each lives
 *
 * **Monotonically increasing** — as the states they stand for. §3.2 offers the
 * shape this follows: "a simple implementation of such a token could be a
 * numeric counter that counts each change as it occurs and relates that change
 * to the specific object that changed. The numeric value could be appended to a
 * 'base' URI to form the valid sync-token." A counter written in decimal does
 * **not** sort as a string — `9` comes after `10` to `strcmp()` and before it
 * to anybody who means the numbers — so {@see isAfter()} exists and the
 * comparison is never left to a caller to guess at.
 *
 * **Opaque** — "The synchronization token itself MUST be treated as an 'opaque'
 * string by the client, i.e., the actual string data has no specific meaning or
 * syntax." That is an obligation on the **client**, which no server can
 * enforce. What a server can do is give it nothing worth reading, so the
 * collection goes in as a hash rather than as its path.
 *
 * **Unique per collection** — and §3.2's precondition is what makes this
 * load-bearing: the value "MUST be a valid token previously returned by the
 * server **for the collection targeted by the request-URI**". A server that
 * could not tell one collection's token from another's could not check that
 * sentence at all, which is why {@see isFor()} is part of the type.
 *
 * ## Why the collection is hashed
 *
 * Two reasons, and the second is the one that forces it. A path may hold
 * anything a URI path segment may not, so writing it in would mean escaping it
 * and reading it back out again. And a token is meant to be opaque, so a
 * collection's name in plain sight is an invitation to read it.
 *
 * **The hash is not keyed, and that is deliberate.** A forged token grants
 * nothing: it says which changes to replay, and those are changes the client
 * may read anyway — a client that forged a small change number would be asking
 * for a longer answer to a question it is allowed to ask. Keying it would add a
 * secret to keep and a server-restart problem to solve, for no access it
 * withholds.
 *
 * ## Why an `https:` URI and not a `tag:` one
 *
 * §4 asks only for "any valid URI", and a tag URI (RFC 4151) would be the more
 * truthful one: §2.1 of that memo has them "not meant to be dereferenced",
 * which is what §3.2 asks of the client, said in the identifier itself rather
 * than in a sentence the client has to have read first.
 *
 * It was **not** chosen, and the reason is interoperability rather than
 * correctness: the shape clients are known to round-trip is the one §3.2's own
 * example uses and the one the widely deployed implementations hand out. A
 * token is worth nothing if a client mangles it on the way back.
 */
final readonly class Token implements Stringable
{
    /**
     * What §3.2 calls the "base" URI, under a domain this project owns.
     *
     * It is not meant to be fetched, and §3.2 says so of every token: the
     * client treats it as opaque.
     */
    private const BASE = 'https://dav.services/sync/';

    /**
     * How many hexadecimal digits of the hash name the collection.
     *
     * **A hundred and twenty-eight bits, because R-SYNC-02's "unique per
     * collection" is absolute.** Sixteen digits would be sixty-four bits,
     * which is not a number two collections collide at in practice — but "in
     * practice" is not what the requirement says, and the cost of the other
     * sixteen digits is sixteen characters in a string nobody reads.
     */
    private const DIGITS = 32;

    private function __construct(
        private string $collection,
        private int $change,
    ) {
    }

    /**
     * The token for this collection at this change.
     *
     * @param string $path The collection's path inside the tree
     * @param int $change Which change of that collection this is the state
     *                    after. Not negative: §3.2's counter "counts each
     *                    change as it occurs", and there is no state before
     *                    the first
     */
    public static function for(string $path, int $change): self
    {
        return new self(self::keyOf($path), $change);
    }

    /**
     * The token a client sent, or null where this server did not make it.
     *
     * **Null is §3.2's precondition and not an error here.** The value is then
     * not "a valid token previously returned by the server", and the report is
     * the place that says so by name — a value object that threw would make
     * every caller catch in order to answer one precondition.
     *
     * The whole value is read, not the tail of it: a token of this shape under
     * another scheme or another host is another server's.
     */
    public static function of(string $raw): ?self
    {
        if (!str_starts_with($raw, self::BASE)) {
            return null;
        }

        $found = [];

        // The change is written without a leading zero, because two spellings
        // of one number would be two tokens for one state.
        if (preg_match('~^([0-9a-f]+)/(0|[1-9][0-9]*)$~', substr($raw, strlen(self::BASE)), $found) !== 1) {
            return null;
        }

        if (strlen($found[1]) !== self::DIGITS) {
            return null;
        }

        return new self($found[1], (int) $found[2]);
    }

    /**
     * Whether this is a token for that collection (§3.2's precondition).
     */
    public function isFor(string $path): bool
    {
        return hash_equals($this->collection, self::keyOf($path));
    }

    /**
     * Which change of its collection this is the state after.
     *
     * What a journal is asked for the changes since (P7-02).
     */
    public function change(): int
    {
        return $this->change;
    }

    /**
     * Whether this token stands for a later state than that one.
     *
     * **Only meaningful for two tokens of one collection**, the counters being
     * per collection; comparing across collections compares two numbers that
     * were never about the same thing. The caller has already established
     * which collection it is asking about, through {@see isFor()}.
     */
    public function isAfter(self $other): bool
    {
        return $this->change > $other->change;
    }

    /**
     * The token as a client sees it: §3.2's base with the change after it.
     */
    public function __toString(): string
    {
        return self::BASE . $this->collection . '/' . $this->change;
    }

    /**
     * The collection, as the token names it.
     */
    private static function keyOf(string $path): string
    {
        return substr(hash('sha256', $path), 0, self::DIGITS);
    }
}
