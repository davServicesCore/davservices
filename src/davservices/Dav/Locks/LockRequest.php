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

namespace DavServices\Dav\Locks;

use DavServices\Exception\BadRequest;
use DavServices\Http\Headers;
use DavServices\Xml\Element;

/**
 * What a client asked a `LOCK` for (RFC 4918 §9.10), taken apart.
 *
 * Three things arrive separately and mean one thing together: the
 * `DAV:lockinfo` body says what kind of hold it wants and for whom, `Depth`
 * says how far it reaches, and `Timeout` says how long it would like it. They
 * are read here so that the part which decides what to do about it does not
 * have to know three grammars.
 *
 * **The two headers are not read alike, and the difference is the point.**
 * `Depth` decides what the lock holds, so a value this does not understand is
 * refused: a lock that reaches less far than the client believes is worse than
 * no lock at all. `Timeout` is a wish — §10.7 lets a server ignore it
 * altogether — so one that cannot be read costs the client its preference and
 * nothing more. The client wants the lock, not the number.
 *
 * A request with no `Depth` at all means `infinity` (§9.10.3). That is the
 * RFC's choice rather than an obvious one, which is why it is said here.
 */
final class LockRequest
{
    private function __construct(
        private readonly LockScope $scope,
        private readonly bool $deep,
        private readonly Element|string|null $owner,
        private readonly ?int $seconds,
    ) {
    }

    /**
     * Reads one `LOCK` request.
     *
     * @throws BadRequest If the body is no `DAV:lockinfo`, asks for a lock
     *                    this server has no notion of, or names a depth no
     *                    lock can have
     */
    public static function read(Element $lockinfo, Headers $headers): self
    {
        if ($lockinfo->name() !== '{DAV:}lockinfo') {
            throw new BadRequest(sprintf('A LOCK carries DAV:lockinfo, not "%s".', $lockinfo->name()));
        }

        return new self(
            self::scopeIn($lockinfo),
            self::reachesBelow($headers),
            self::ownerIn($lockinfo),
            self::secondsAsked($headers),
        );
    }

    /**
     * How long the client would like to hold it, or null where it named no
     * limit of its own and the server's maximum decides.
     *
     * A refresh carries no body at all (§9.10.2), so this is public: the
     * timeout is read from the same place and by the same rules either way,
     * and a grammar written twice is a grammar that will be read two ways.
     */
    public static function secondsAsked(Headers $headers): ?int
    {
        // RFC 4918 §10.7: a list of preferences, best first. `Infinite` is a
        // preference this server cannot honour, so it falls through to the
        // maximum like a header nobody sent.
        foreach (explode(',', $headers->first('Timeout') ?? '') as $preference) {
            $seconds = self::secondsIn(trim($preference));

            if ($seconds !== null) {
                return $seconds;
            }
        }

        return null;
    }

    /**
     * Exclusive or shared (RFC 4918 §6.1). They are different promises, and
     * neither is handed out on a guess.
     */
    public function scope(): LockScope
    {
        return $this->scope;
    }

    /**
     * Whether the lock is to hold everything below its root as well.
     */
    public function isDeep(): bool
    {
        return $this->deep;
    }

    /**
     * Whoever the client said holds it, as it wrote it (RFC 4918 §14.17).
     */
    public function owner(): Element|string|null
    {
        return $this->owner;
    }

    /**
     * The seconds the client asked for, or null where it asked for nothing in
     * particular.
     */
    public function seconds(): ?int
    {
        return $this->seconds;
    }

    /**
     * @throws BadRequest
     */
    private static function scopeIn(Element $lockinfo): LockScope
    {
        $scope = self::childOf($lockinfo, '{DAV:}lockscope');
        $type = self::childOf($lockinfo, '{DAV:}locktype');

        // RFC 4918 §7: write is the only kind of lock there is. Answering
        // another with a write lock would hand out something else than what
        // was asked for.
        if ($type === null || self::firstNameIn($type) !== '{DAV:}write') {
            throw new BadRequest('This server locks against writes and nothing else.');
        }

        return match ($scope === null ? null : self::firstNameIn($scope)) {
            '{DAV:}exclusive' => LockScope::Exclusive,
            '{DAV:}shared' => LockScope::Shared,
            default => throw new BadRequest('A lock is exclusive or shared, and this one is neither.'),
        };
    }

    private static function ownerIn(Element $lockinfo): Element|string|null
    {
        $owner = self::childOf($lockinfo, '{DAV:}owner');

        if ($owner === null) {
            return null;
        }

        return $owner->children()[0] ?? $owner->text();
    }

    /**
     * RFC 4918 §9.10.3: `0` or `infinity`, and nothing at all means infinity.
     *
     * @throws BadRequest If the header names a depth no lock can have
     */
    private static function reachesBelow(Headers $headers): bool
    {
        $depth = $headers->first('Depth');

        return match ($depth === null ? 'infinity' : strtolower($depth)) {
            '0' => false,
            'infinity' => true,
            default => throw new BadRequest(sprintf('A lock is taken at depth 0 or infinity, not "%s".', $depth)),
        };
    }

    /**
     * One preference of the `Timeout` header, or null where this cannot read
     * it or will not honour it.
     */
    private static function secondsIn(string $preference): ?int
    {
        $seconds = substr($preference, strlen('Second-'));

        if (stripos($preference, 'Second-') !== 0 || !ctype_digit($seconds)) {
            return null;
        }

        return (int) $seconds;
    }

    private static function childOf(Element $element, string $name): ?Element
    {
        foreach ($element->children() as $child) {
            if ($child->name() === $name) {
                return $child;
            }
        }

        return null;
    }

    /**
     * The name of what an element holds — `DAV:exclusive` inside a
     * `DAV:lockscope` — or nothing where it holds no element at all.
     */
    private static function firstNameIn(Element $element): ?string
    {
        return ($element->children()[0] ?? null)?->name();
    }
}
