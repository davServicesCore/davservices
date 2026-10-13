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

namespace DavServices\Dav\Cards;

use DateTimeImmutable;

/**
 * What is known about one address object resource without reading it
 * (RFC 6352 §5.1).
 *
 * **The body is deliberately not here, and that absence is the design.**
 * R-BE-02 asks that the cuts make N+1 queries avoidable, and the case it names
 * first is `PROPFIND Depth: 1`: a client listing two hundred cards wants their
 * entity tags and their sizes, and it wants them in one question. A metadata
 * object carrying the body would make the cheap question as expensive as the
 * dear one, and no amount of care further up could undo that.
 *
 * So this is everything `PROPFIND` can answer from, and
 * {@see \DavServices\Backend\ICardBackend::bodies()} is the separate, batched
 * way to the cards themselves.
 *
 * **The `UID` is in here although it is inside the card**, which is the one
 * thing a store has to read a card to learn. It is here because R-CARD-07 asks
 * for uniqueness within an address book — `CARDDAV:no-uid-conflict` — and a
 * server that had to fetch every body to answer that would make a single `PUT`
 * cost the whole collection. A store keeps it beside the card for the same
 * reason it keeps the entity tag.
 *
 * It lives in `Dav\Cards` rather than beside the interface that hands it out,
 * because a backend interface may not reach up into the layer that uses it —
 * the same reason {@see \DavServices\Dav\Locks\LockInfo} and
 * {@see \DavServices\Dav\Acl\PrincipalInfo} live where they do.
 */
final class CardInfo
{
    /**
     * @param string $name The member name inside the address book, which is
     *                     the last segment of the card's URL
     * @param string $etag The entity tag, quoted as RFC 9110 §8.8.3 writes
     *                     one. RFC 6352 §6.3.2.3 asks that a server give
     *                     address object resources one, so that a client can
     *                     avoid a lost update (§9.2)
     * @param int $size The card in octets, which `DAV:getcontentlength`
     *                  answers with and `CARDDAV:max-resource-size` is
     *                  measured against
     * @param string|null $uid The vCard `UID`, or null where the store does
     *                         not keep it. **Null is not "no UID"**: every
     *                         card has one, and a store answering null is
     *                         saying it cannot tell without reading, which is
     *                         a limit of the store rather than of the card
     * @param DateTimeImmutable|null $lastModified When the store last saw it
     *                                             change, or null where it
     *                                             does not keep that
     */
    public function __construct(
        private readonly string $name,
        private readonly string $etag,
        private readonly int $size,
        private readonly ?string $uid = null,
        private readonly ?DateTimeImmutable $lastModified = null,
    ) {
    }

    /**
     * The member name inside the address book.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * The entity tag, as it goes into an `ETag` header.
     */
    public function etag(): string
    {
        return $this->etag;
    }

    /**
     * The card's length in octets.
     */
    public function size(): int
    {
        return $this->size;
    }

    /**
     * The vCard `UID`, or null where the store cannot tell without reading
     * the card.
     */
    public function uid(): ?string
    {
        return $this->uid;
    }

    /**
     * When the store last saw it change, or null where it does not keep that.
     */
    public function lastModified(): ?DateTimeImmutable
    {
        return $this->lastModified;
    }
}
