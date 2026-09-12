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

use DateTimeInterface;

/**
 * The conditions a request carries, evaluated in the order RFC 9110 §13.2.2
 * lays down:
 *
 * 1. `If-Match` — does not hold: `412`
 * 2. otherwise `If-Unmodified-Since` — does not hold: `412`
 * 3. `If-None-Match` — holds: `304` for `GET` and `HEAD`, `412` for the rest
 * 4. otherwise, and only for `GET` and `HEAD`, `If-Modified-Since` — `304`
 *
 * The order is normative, and the "otherwise" in steps 2 and 4 is the part
 * that gets lost: a request carrying both an entity tag and a date is decided
 * by the tag alone. A server that consulted the date as well would refuse
 * updates that are perfectly in order, because a modification time has a
 * resolution of one second and an entity tag has none.
 */
final class ConditionalRequest
{
    /** The methods that may be answered with `304` (RFC 9110 §13.1.3). */
    private const READ_METHODS = ['GET', 'HEAD'];

    public function __construct(private readonly Request $request)
    {
    }

    /**
     * What the conditions come to for the resource as it stands.
     *
     * @param string|null $etag Its current entity tag, if it has one
     * @param DateTimeInterface|null $modified When it last changed, if that is known
     * @param bool $exists Whether anything is bound to the path
     *                     at all — which is the whole of what
     *                     the wildcard `*` asks about
     */
    public function evaluate(?string $etag, ?DateTimeInterface $modified = null, bool $exists = true): Precondition
    {
        $current = $etag === null ? null : ETag::parse($etag);

        $failed = $this->entityTagFails($current, $exists) ?? $this->modificationDateFails($modified);

        if ($failed === true) {
            return Precondition::Failed;
        }

        return $this->alreadyHeld($current, $exists) ?? $this->unchangedSince($modified);
    }

    /**
     * Step 1: `If-Match`, compared strongly. Null where the field is absent
     * and step 2 is to be consulted instead.
     */
    private function entityTagFails(?ETag $current, bool $exists): ?bool
    {
        $given = $this->header('If-Match');

        if ($given === null) {
            return null;
        }

        if ($given === '*') {
            return !$exists;
        }

        return !self::anyMatches(ETag::parseList($given), $current, strongly: true);
    }

    /**
     * Step 2: `If-Unmodified-Since`, reached only when `If-Match` was absent.
     */
    private function modificationDateFails(?DateTimeInterface $modified): bool
    {
        $given = self::timestampOf($this->header('If-Unmodified-Since'));

        if ($given === null || $modified === null) {
            return false;
        }

        return $modified->getTimestamp() > $given;
    }

    /**
     * Step 3: `If-None-Match`, compared weakly. A match means the client
     * already holds this version — `304` where it was only reading, `412`
     * where it was about to change something.
     */
    private function alreadyHeld(?ETag $current, bool $exists): ?Precondition
    {
        $given = $this->header('If-None-Match');

        if ($given === null) {
            return null;
        }

        $matches = $given === '*'
            ? $exists
            : self::anyMatches(ETag::parseList($given), $current, strongly: false);

        if (!$matches) {
            return Precondition::Met;
        }

        return $this->isRead() ? Precondition::NotModified : Precondition::Failed;
    }

    /**
     * Step 4: `If-Modified-Since`, reached only when `If-None-Match` was
     * absent, and only for a method that could be answered with `304`.
     */
    private function unchangedSince(?DateTimeInterface $modified): Precondition
    {
        $given = self::timestampOf($this->header('If-Modified-Since'));

        if ($given === null || $modified === null || !$this->isRead()) {
            return Precondition::Met;
        }

        return $modified->getTimestamp() <= $given ? Precondition::NotModified : Precondition::Met;
    }

    /**
     * @param list<ETag> $given
     */
    private static function anyMatches(array $given, ?ETag $current, bool $strongly): bool
    {
        if ($current === null) {
            return false;
        }

        foreach ($given as $tag) {
            if ($strongly ? $tag->matchesStrongly($current) : $tag->matchesWeakly($current)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A date in any of the three formats of RFC 9110 §5.6.7, or null where the
     * field is absent or cannot be read — §13.1.3 says such a field is to be
     * ignored rather than refused.
     */
    private static function timestampOf(?string $date): ?int
    {
        if ($date === null) {
            return null;
        }

        $timestamp = strtotime($date);

        return $timestamp === false ? null : $timestamp;
    }

    private function header(string $name): ?string
    {
        return $this->request->headers()->first($name);
    }

    private function isRead(): bool
    {
        return in_array($this->request->method(), self::READ_METHODS, true);
    }
}
