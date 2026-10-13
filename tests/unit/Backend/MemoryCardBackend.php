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

use DateTimeImmutable;
use DavServices\Backend\ICardBackend;
use DavServices\Dav\Cards\AddressBookInfo;
use DavServices\Dav\Cards\CardInfo;
use DavServices\Exception\Forbidden;
use DavServices\Exception\NotFound;

/**
 * A card store that lives in an array, for the tests of the layers above.
 *
 * It exists for two reasons. The CardDAV reports and the `PUT` validation
 * still to come need a store they can set up in a line, without a directory
 * and without cleaning one up. And a contract suite with one implementation
 * proves nothing: two make the difference between "this is what a card store
 * does" and "this is what that one class happens to do".
 *
 * **It counts how often a body was read**, which is how a test can show that
 * {@see cards()} answers a `PROPFIND Depth: 1` without touching a single card
 * — the promise R-BE-02 is made of.
 */
final class MemoryCardBackend implements ICardBackend
{
    /** How often a card's bytes have been handed out. */
    public int $bodyReads = 0;

    /** @var array<string, array<string, AddressBookInfo>> */
    private array $books = [];

    /** @var array<string, array<string, array<string, string>>> */
    private array $cards = [];

    /**
     * Puts an address book there, as provisioning would.
     */
    public function provision(string $principal, AddressBookInfo $book): self
    {
        $this->books[$principal][$book->name()] = $book;
        $this->cards[$principal][$book->name()] ??= [];

        return $this;
    }

    public function addressBooks(string $principal): array
    {
        return array_values($this->books[$principal] ?? []);
    }

    public function cards(string $principal, string $addressBook): array
    {
        $cards = [];

        foreach ($this->inside($principal, $addressBook) as $name => $card) {
            $cards[] = $this->describe($name, $card);
        }

        return $cards;
    }

    public function card(string $principal, string $addressBook, string $name): ?CardInfo
    {
        $card = $this->inside($principal, $addressBook)[$name] ?? null;

        return $card === null ? null : $this->describe($name, $card);
    }

    public function bodies(string $principal, string $addressBook, array $names): array
    {
        $inside = $this->inside($principal, $addressBook);
        $bodies = [];

        foreach ($names as $name) {
            if (isset($inside[$name])) {
                ++$this->bodyReads;
                $bodies[$name] = $inside[$name];
            }
        }

        return $bodies;
    }

    public function body(string $principal, string $addressBook, string $name): ?string
    {
        $card = $this->inside($principal, $addressBook)[$name] ?? null;

        if ($card !== null) {
            ++$this->bodyReads;
        }

        return $card;
    }

    public function put(string $principal, string $addressBook, string $name, string $card): string
    {
        $this->inside($principal, $addressBook);

        if ($name === '' || $name === '.' || $name === '..' || strpbrk($name, "/\\\0") !== false) {
            throw new Forbidden(sprintf('"%s" is not a name this store takes.', $name));
        }

        $this->cards[$principal][$addressBook][$name] = $card;

        return self::etagOf($card);
    }

    public function remove(string $principal, string $addressBook, string $name): void
    {
        if (!isset($this->inside($principal, $addressBook)[$name])) {
            throw new NotFound(sprintf('There is no "%s" in "%s".', $name, $addressBook));
        }

        unset($this->cards[$principal][$addressBook][$name]);
    }

    /**
     * The cards of an address book that is there.
     *
     * @throws NotFound If it is not
     *
     * @return array<string, string>
     */
    private function inside(string $principal, string $addressBook): array
    {
        if (!isset($this->cards[$principal][$addressBook])) {
            throw new NotFound(sprintf('There is no address book "%s" of "%s".', $addressBook, $principal));
        }

        return $this->cards[$principal][$addressBook];
    }

    /**
     * What is known about a card without handing its bytes out — so the
     * counter stays where it is.
     */
    private function describe(string $name, string $card): CardInfo
    {
        return new CardInfo(
            $name,
            self::etagOf($card),
            strlen($card),
            self::uidIn($card),
            new DateTimeImmutable('2026-01-01 00:00:00'),
        );
    }

    private static function etagOf(string $card): string
    {
        return '"' . substr(hash('sha256', $card), 0, 32) . '"';
    }

    /**
     * The `UID` a store keeps beside the card, so that R-CARD-07 can be
     * answered for a whole address book without reading any of them.
     */
    private static function uidIn(string $card): ?string
    {
        foreach (explode("\r\n", $card) as $line) {
            if (str_starts_with($line, 'UID:')) {
                return substr($line, strlen('UID:'));
            }
        }

        return null;
    }
}
