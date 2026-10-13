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

namespace DavServices\Backend\File;

use DateTimeImmutable;
use DavServices\Backend\ICardBackend;
use DavServices\Dav\Cards\AddressBookInfo;
use DavServices\Dav\Cards\CardInfo;
use DavServices\Exception\Forbidden;
use DavServices\Exception\NotFound;
use FilesystemIterator;
use RuntimeException;

/**
 * Address books in a directory, one directory per address book and one file
 * per card (R-BE-05).
 *
 *     <root>/<principal>/<address book>/<member name>
 *
 * The simple store: nothing to set up but a directory the web server may
 * write to, and every card is a file a person can read with `cat`. An address
 * book is made by making a directory, which is what §6.3.1 means by "created
 * through provisioning (e.g., automatically created when a user's account is
 * provisioned)".
 *
 * **The file is named exactly what the client called the member**, suffix and
 * all, because the member name is the client's to choose and RFC 6352 asks
 * nothing of it. Every file in an address book's directory is therefore taken
 * to be a card, which §5.2 licenses: an address book "MUST only contain
 * address object resources and collections that are not address book
 * collections".
 *
 *     $cards = new CardBackend('/var/lib/davservices/cards');
 *
 * ## A `PROPFIND Depth: 1` reads no card
 *
 * R-BE-02's first case is the listing, and this answers it from the directory
 * entry alone: the size is `filesize()`, the time is `filemtime()`, and the
 * entity tag is made of those two. **No card is opened.** That is why the tag
 * is not a hash of the contents, which would be the obvious choice and would
 * make a listing of two hundred cards read two hundred files.
 *
 * The price is named rather than hidden: two writes inside one second that
 * leave the length unchanged are one entity tag. A store that matters uses a
 * revision its database already keeps; see the warning below.
 *
 * ## The `UID` is answered as unknown, honestly
 *
 * {@see CardInfo::uid()} is null here, because the only way this store could
 * answer it is by reading the card — the very thing the listing avoids. A
 * server built on this therefore reads the cards to answer
 * `CARDDAV:no-uid-conflict` (R-CARD-07), and that is a cost of this backend
 * rather than of the protocol: a store with a column for the `UID` answers
 * both questions at once.
 *
 * **This is a reference backend (R-BE-05) and is not to be run in
 * production.** It knows nothing of two requests writing to one card at the
 * same moment, nothing of a filesystem that reorders a rename, and its entity
 * tag is as exact as the clock. It is here so that the library can be tried in
 * one line and so that the contract has a second implementation to be held
 * against.
 */
final class CardBackend implements ICardBackend
{
    /**
     * @param string $directory Where the address books go; it has to exist,
     *                          because creating directories on a guess is how
     *                          a typo ends up with a card store in a web root
     *
     * @throws RuntimeException If it is no directory
     */
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory)) {
            throw new RuntimeException(sprintf('"%s" is no directory.', $directory));
        }
    }

    /**
     * The address books of a principal, which are the directories under its
     * own.
     *
     * @return list<AddressBookInfo>
     */
    public function addressBooks(string $principal): array
    {
        if (!self::isASegment($principal) || !is_dir($this->directory . '/' . $principal)) {
            return [];
        }

        $books = [];

        foreach (self::entriesIn($this->directory . '/' . $principal) as $entry) {
            if (is_dir($entry)) {
                $books[] = new AddressBookInfo(basename($entry));
            }
        }

        return $books;
    }

    /**
     * The metadata of every card, from the directory entries alone.
     *
     * @throws NotFound If there is no such address book
     *
     * @return list<CardInfo>
     */
    public function cards(string $principal, string $addressBook): array
    {
        $cards = [];

        foreach (self::entriesIn($this->where($principal, $addressBook)) as $entry) {
            // **Every file is taken to be a card**, rather than only those
            // with a suffix. A client chooses the member name, and RFC 6352
            // asks nothing of it; §5.2 says an address book "MUST only contain
            // address object resources and collections that are not address
            // book collections", so a file in here is meant to be a card. A
            // store that filtered by `.vcf` would hide one a client called
            // `simon`.
            if (is_file($entry)) {
                $cards[] = self::describe($entry);
            }
        }

        return $cards;
    }

    /**
     * The metadata of one card, or null where there is none of that name.
     *
     * @throws NotFound If there is no such address book
     */
    public function card(string $principal, string $addressBook, string $name): ?CardInfo
    {
        $location = $this->fileFor($principal, $addressBook, $name);

        return $location !== null && is_file($location) ? self::describe($location) : null;
    }

    /**
     * The cards themselves, in one pass over the names asked for.
     *
     * @param list<string> $names
     *
     * @throws NotFound If there is no such address book
     *
     * @return array<string, string>
     */
    public function bodies(string $principal, string $addressBook, array $names): array
    {
        $this->where($principal, $addressBook);
        $bodies = [];

        foreach ($names as $name) {
            $card = $this->body($principal, $addressBook, $name);

            if ($card !== null) {
                $bodies[$name] = $card;
            }
        }

        return $bodies;
    }

    /**
     * One card as it was stored, or null where there is none of that name.
     *
     * @throws NotFound If there is no such address book
     */
    public function body(string $principal, string $addressBook, string $name): ?string
    {
        $location = $this->fileFor($principal, $addressBook, $name);

        if ($location === null || !is_file($location)) {
            return null;
        }

        $card = @file_get_contents($location);

        // @codeCoverageIgnoreStart
        // `is_file()` said yes a moment ago, so a false here is a disc that
        // has gone away under the request. The difference between "no such
        // card" and "this store is broken" is one a client is owed, which is
        // why it is not folded into the null above.
        if ($card === false) {
            throw new RuntimeException(sprintf('"%s" cannot be read.', $location));
        }
        // @codeCoverageIgnoreEnd

        return $card;
    }

    /**
     * Writes a card, replacing one of that name if it is there.
     *
     * **The file is written beside its place and moved in**, so that a write
     * cut in half leaves the old card rather than half of the new one. A
     * client that read the card in between gets one of the two, not a
     * fragment.
     *
     * @throws NotFound If there is no such address book
     * @throws Forbidden If the name is not one this store will take
     *
     * **Narrower than the interface on purpose**: {@see ICardBackend::put()}
     * allows null, for a store that normalises the card and therefore may not
     * answer a strong entity tag (§6.3.2.3). This one writes the bytes it was
     * given, byte for byte, so it always has a tag to give.
     *
     * @return string The strong entity tag the card now has
     */
    public function put(string $principal, string $addressBook, string $name, string $card): string
    {
        $inside = $this->where($principal, $addressBook);

        if (!self::isASegment($name)) {
            throw new Forbidden(sprintf('"%s" is not a name this store takes.', $name));
        }

        $location = $inside . '/' . $name;
        $beside = $location . '.' . bin2hex(random_bytes(8)) . '.part';

        // Both are asked quietly, because both answers are handled right here
        // and a warning beside the exception would say the same thing twice —
        // the same reason `@fopen` and `@stat` are written that way in this
        // directory.
        $written = @file_put_contents($beside, $card);

        // @codeCoverageIgnoreStart
        // A fresh random name inside a directory the store has just confirmed
        // is a directory: a false here is the filesystem failing under the
        // request, not a case a test can arrange. It is kept apart from the
        // move below so that the half which *can* be tried stays measured.
        if ($written === false) {
            @unlink($beside);

            throw new RuntimeException(sprintf('"%s" cannot be written.', $location));
        }
        // @codeCoverageIgnoreEnd

        // The commonest failure of the two, and one a client produces by
        // `PUT`ting a card over the name of a collection: `rename` refuses
        // that rather than emptying it.
        if (!@rename($beside, $location)) {
            @unlink($beside);

            throw new RuntimeException(sprintf('"%s" cannot be written.', $location));
        }

        // No `clearstatcache()` here, and that was measured rather than
        // assumed: `rename()` invalidates PHP's stat cache for the path it
        // moves onto, so a call would be a line no test could tell from its
        // absence — and a mutation run said exactly that.
        return self::describe($location)->etag();
    }

    /**
     * Takes a card out.
     *
     * @throws NotFound If there is no such address book, or no such card
     */
    public function remove(string $principal, string $addressBook, string $name): void
    {
        $location = $this->fileFor($principal, $addressBook, $name);

        if ($location === null || !is_file($location)) {
            throw new NotFound(sprintf('There is no "%s" in "%s".', $name, $addressBook));
        }

        // @codeCoverageIgnoreStart
        // The file was there a moment ago and the directory is the store's
        // own, so a false here is the filesystem refusing something it had
        // just allowed.
        if (!@unlink($location)) {
            throw new RuntimeException(sprintf('"%s" cannot be removed.', $location));
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Where an address book that is there keeps its cards.
     *
     * @throws NotFound If it is not there
     */
    private function where(string $principal, string $addressBook): string
    {
        if (self::isASegment($principal) && self::isASegment($addressBook)) {
            $inside = $this->directory . '/' . $principal . '/' . $addressBook;

            if (is_dir($inside)) {
                return $inside;
            }
        }

        throw new NotFound(sprintf('There is no address book "%s" of "%s".', $addressBook, $principal));
    }

    /**
     * The file a card of that name would be in, or null where the name is one
     * this store would not have written.
     *
     * A name that could reach outside is answered as "no such card" rather
     * than refused: on the way *out* there is nothing to protect but the
     * answer, and a reader asking for `../../etc/passwd` is asking for
     * something that is not a card in this address book.
     *
     * @throws NotFound If there is no such address book
     */
    private function fileFor(string $principal, string $addressBook, string $name): ?string
    {
        $inside = $this->where($principal, $addressBook);

        return self::isASegment($name) ? $inside . '/' . $name : null;
    }

    /**
     * What is known about a card without opening it.
     *
     * The entity tag is the length and the time it changed, which is what a
     * directory entry already holds — see the note on the class about what
     * that buys and what it costs.
     */
    private static function describe(string $location): CardInfo
    {
        $size = @filesize($location);
        $changed = @filemtime($location);

        // @codeCoverageIgnoreStart
        // The entry came out of the directory listing, so a false here is a
        // card that was deleted between the listing and this line — a race
        // with another request rather than anything a test can arrange.
        if ($size === false || $changed === false) {
            throw new RuntimeException(sprintf('"%s" cannot be looked at.', $location));
        }
        // @codeCoverageIgnoreEnd

        return new CardInfo(
            basename($location),
            sprintf('"%x-%x"', $changed, $size),
            $size,
            // Reading the card is the one thing the listing is here to avoid,
            // so this store says it does not know rather than guessing.
            null,
            (new DateTimeImmutable())->setTimestamp($changed),
        );
    }

    /**
     * Whether a name is one segment and could not reach outside its
     * directory.
     */
    private static function isASegment(string $name): bool
    {
        if ($name === '' || $name === '.' || $name === '..') {
            return false;
        }

        return strpbrk($name, "/\\\0") === false;
    }

    /**
     * The entries of a directory, in whatever order it hands them over.
     *
     * **It used to sort, and a mutation run showed the sort was unobservable:**
     * {@see ICardBackend} promises no order, nothing here relies on one, and
     * every test that cares sorts for itself. A line no test can distinguish
     * from its absence is a line that cannot be maintained, so it went.
     *
     * @return list<string>
     */
    private static function entriesIn(string $directory): array
    {
        $entries = [];

        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
            $entries[] = (string) $entry;
        }

        return $entries;
    }
}
